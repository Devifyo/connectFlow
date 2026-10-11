<?php

namespace App\Services;

use App\Models\FaceVideo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Post-processing for recorded punch / enrollment videos.
 *
 * MediaRecorder output has no duration marker and odd timestamps, and uploads from phones are
 * frequently cut short (locked screen, closed tab, admin navigating away). This service turns
 * whatever reached the server into a playable, seekable mp4 and drops recordings with no video
 * track, so the admin never sits on a spinner for a file that will never play.
 *
 * Phone recorders (WebKit) have also been seen to restart their WebM muxer mid-recording, which
 * leaves a second EBML header in the middle of the streamed file. A plain ffmpeg pass stops
 * reading there and silently discards the rest, so the file is split at every header, each
 * segment is converted on its own and the parts are joined back together.
 */
class FaceVideoFinalizer
{
    /** Uploads whose last chunk is older than this are considered abandoned and finalized as-is. */
    public const STALE_AFTER_SECONDS = 180;

    private const EBML_MAGIC = "\x1A\x45\xDF\xA3";

    /**
     * Re-encode to a clean faststart H.264/AAC mp4. Returns the (possibly new .mp4) relative path;
     * on any failure the original path is returned unchanged.
     */
    public function finalize(string $relativePath): string
    {
        $scratch = [];
        try {
            $full = Storage::disk('local')->path($relativePath);
            if (!is_file($full) || filesize($full) < 1024) {
                return $relativePath;
            }

            $dir = trim(dirname($relativePath), '.');
            $name = pathinfo($relativePath, PATHINFO_FILENAME);
            $prefix = ($dir !== '' ? $dir . '/' : '');
            $newRel = $prefix . $name . '.mp4';
            $newFull = Storage::disk('local')->path($newRel);
            $tmp = $newFull . '.tmp.mp4';

            $segments = $this->splitAtEbmlHeaders($full, $tmp);
            $scratch = array_filter($segments, fn ($s) => $s !== $full);

            $ok = false;
            if (count($segments) <= 1) {
                $ok = $this->encodeToMp4($full, $tmp, null, basename($relativePath));
            } else {
                Log::info(sprintf('[face-finalize] %s: %d webm segments (recorder restarted mid-stream)', basename($relativePath), count($segments)));
                $parts = [];
                $size = null;
                foreach ($segments as $i => $seg) {
                    $partOut = $tmp . ".part{$i}.mp4";
                    $scratch[] = $partOut;
                    if ($this->encodeToMp4($seg, $partOut, $size, basename($relativePath) . "#{$i}")) {
                        $parts[] = $partOut;
                        $size = $size ?? $this->videoSize($partOut);
                    }
                }
                if (count($parts) > 1) {
                    $ok = $this->concatMp4s($parts, $tmp);
                } elseif (count($parts) === 1) {
                    $ok = @rename($parts[0], $tmp);
                }
            }

            if ($ok && is_file($tmp) && filesize($tmp) > 1024) {
                @rename($tmp, $newFull);
                if ($newFull !== $full) {
                    if (config('attendance.keep_raw_face_videos')) {
                        // Keep the untouched recording for diagnostics/recovery (never served to anyone).
                        @rename($full, Storage::disk('local')->path($prefix . $name . '.raw.webm'));
                    } else {
                        @unlink($full);
                    }
                }
                return $newRel;
            }

            // Re-encode failed (e.g. audio-only clip). Drop the temp and at least remux for duration.
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === 'mp4' ? 'mp4' : 'webm';
            $remux = $full . '.fix.' . $ext;
            exec(sprintf('ffmpeg -y -loglevel error -i %s -c copy -f %s %s 2>/dev/null',
                escapeshellarg($full), $ext, escapeshellarg($remux)), $o2, $c2);
            if ($c2 === 0 && is_file($remux) && filesize($remux) > 1024) {
                @rename($remux, $full);
            } elseif (is_file($remux)) {
                @unlink($remux);
            }
            return $relativePath;
        } catch (\Throwable $e) {
            Log::warning('[face-finalize] ' . basename($relativePath) . ' failed: ' . $e->getMessage());
            return $relativePath;
        } finally {
            foreach ($scratch as $f) {
                if (is_string($f) && is_file($f)) @unlink($f);
            }
        }
    }

    /**
     * Byte offsets of every WebM/Matroska EBML header in the file. A valid recording has exactly one
     * (at offset 0); a recorder that restarted mid-stream leaves more. Returns one file path per
     * segment: the original path when there is nothing to split, otherwise temp files.
     */
    private function splitAtEbmlHeaders(string $full, string $tmpBase): array
    {
        $data = file_get_contents($full);
        if ($data === false) return [$full];

        $offsets = [];
        $pos = 0;
        while (($pos = strpos($data, self::EBML_MAGIC, $pos)) !== false) {
            // Guard against the 4 magic bytes occurring inside compressed video: a real header names
            // its DocType ("webm"/"matroska") within the next few dozen bytes.
            $window = substr($data, $pos + 4, 64);
            if (str_contains($window, "\x42\x82") && (str_contains($window, 'webm') || str_contains($window, 'matroska'))) {
                $offsets[] = $pos;
            }
            $pos += 4;
        }

        if (count($offsets) <= 1 && ($offsets[0] ?? 0) === 0) {
            return [$full];
        }

        $segments = [];
        if (empty($offsets) || $offsets[0] !== 0) {
            // Leading bytes without a header (chunk 0 lost) cannot be decoded; skip them.
            array_unshift($offsets, null);
        }
        for ($i = 0; $i < count($offsets); $i++) {
            if ($offsets[$i] === null) continue;
            $start = $offsets[$i];
            $end = $offsets[$i + 1] ?? strlen($data);
            if ($end - $start < 1024) continue;
            $path = $tmpBase . ".seg{$i}.webm";
            file_put_contents($path, substr($data, $start, $end - $start));
            $segments[] = $path;
        }
        return $segments ?: [$full];
    }

    /**
     * One ffmpeg pass to H.264/AAC mp4. $size forces the output frame size (so parts can be joined);
     * missing audio is replaced by silence for the same reason. Logs how much of the input survived
     * and retries with a timestamp-tolerant pass when a large part was lost.
     */
    private function encodeToMp4(string $in, string $out, ?array $size, string $label): bool
    {
        $span = $this->packetSpan($in);
        $hasAudio = isset($span['audio']);

        $scale = $size
            ? sprintf('scale=%1$d:%2$d:force_original_aspect_ratio=decrease,pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2,setsar=1', $size[0], $size[1])
            : 'scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1';

        // Phone recorders have been observed to freeze their clock mid-recording (every later frame
        // carries the same timestamp) while frames keep coming. A plain conversion keeps one frame per
        // timestamp and silently drops the rest of the recording. When a meaningful share of frames
        // share a timestamp, re-time: keep the original spacing where it increases, and give frozen
        // frames the recording's nominal frame duration so every frame survives.
        $frozen = ($span['video_packets'] ?? 0) > 0 && ($span['video_dups'] ?? 0) > 0.02 * $span['video_packets'];
        $vf = $scale;
        $af = '';
        if ($frozen) {
            $frameDur = $span['video_frame_dur'] ?? (1 / 30);
            $vf = sprintf('setpts=if(eq(N\,0)\,0\,PREV_OUTPTS+if(gt(PTS-PREV_INPTS\,0)*lt(PTS-PREV_INPTS\,1/TB)\,PTS-PREV_INPTS\,%.5f/TB)),%s', $frameDur, $scale);
            $af = ' -af asetpts=N/SR/TB';
        }

        $audioIn = $hasAudio ? '' : ' -f lavfi -i anullsrc=r=48000:cl=mono';
        $maps = $hasAudio ? '-map 0:v:0 -map 0:a:0' : '-map 0:v:0 -map 1:a:0 -shortest';
        $cmd = sprintf(
            'ffmpeg -y -loglevel warning -fflags +genpts -i %s%s %s -vf %s%s -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -ar 48000 -ac 1 -movflags +faststart %s 2>&1',
            escapeshellarg($in), $audioIn, $maps, escapeshellarg($vf), $af, escapeshellarg($out)
        );
        exec($cmd, $lines, $code);
        $dur = ($code === 0 && is_file($out)) ? $this->durationOf($out) : null;

        Log::info(sprintf('[face-finalize] %s input(v=%.1fs a=%.1fs vpk=%d dups=%d fps=%.1f)%s output=%s code=%d %s',
            $label, $span['video'] ?? -1, $span['audio'] ?? -1, $span['video_packets'] ?? 0, $span['video_dups'] ?? 0,
            isset($span['video_frame_dur']) ? 1 / $span['video_frame_dur'] : 0,
            $frozen ? ' FROZEN-CLOCK re-timed' : '',
            $dur === null ? 'fail' : sprintf('%.1fs', $dur), $code, $this->summarizeFfmpegOutput($lines)));

        return $code === 0 && is_file($out) && filesize($out) > 1024;
    }

    /** Join same-codec mp4 parts without re-encoding. */
    private function concatMp4s(array $parts, string $out): bool
    {
        $list = $out . '.list.txt';
        file_put_contents($list, implode("\n", array_map(fn ($p) => "file '" . str_replace("'", "'\\''", $p) . "'", $parts)) . "\n");
        exec(sprintf('ffmpeg -y -loglevel error -f concat -safe 0 -i %s -c copy -movflags +faststart %s 2>&1',
            escapeshellarg($list), escapeshellarg($out)), $o, $c);
        @unlink($list);
        Log::info(sprintf('[face-finalize] joined %d parts -> %s', count($parts), is_file($out) ? sprintf('%.1fs', $this->durationOf($out) ?? 0) : 'fail'));
        return $c === 0 && is_file($out);
    }

    private function videoSize(string $fullPath): ?array
    {
        exec(sprintf('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null', escapeshellarg($fullPath)), $o, $c);
        $parts = explode(',', trim(implode('', $o ?? [])));
        return ($c === 0 && count($parts) >= 2 && (int) $parts[0] > 0) ? [(int) $parts[0], (int) $parts[1]] : null;
    }

    /**
     * Per-stream packet statistics: last timestamp (seconds); for video also the packet count, how
     * many packets repeat the previous timestamp (frozen clock) and the typical frame duration
     * measured from the increasing part of the timeline. [] on failure.
     */
    private function packetSpan(string $fullPath): array
    {
        $span = [];
        foreach (['video' => 'v:0', 'audio' => 'a:0'] as $key => $sel) {
            $lines = [];
            exec(sprintf('ffprobe -v error -select_streams %s -show_entries packet=pts_time -of csv=p=0 %s 2>/dev/null', $sel, escapeshellarg($fullPath)), $lines, $code);
            $vals = array_values(array_filter(array_map('floatval', array_filter($lines, 'is_numeric')), fn ($v) => $v >= 0));
            if (!$vals) continue;
            $span[$key] = max($vals);
            if ($key !== 'video') continue;

            $span['video_packets'] = count($vals);
            $dups = 0;
            $deltas = [];
            for ($i = 1; $i < count($vals); $i++) {
                $d = $vals[$i] - $vals[$i - 1];
                if ($d <= 0) $dups++;
                elseif ($d < 1) $deltas[] = $d;
            }
            $span['video_dups'] = $dups;
            if (count($deltas) >= 10) {
                sort($deltas);
                // Mean of the middle half: robust to the alternating 21/42 ms pattern some encoders emit.
                $mid = array_slice($deltas, (int) (count($deltas) * 0.25), max(1, (int) (count($deltas) * 0.5)));
                $span['video_frame_dur'] = array_sum($mid) / count($mid);
            }
        }
        return $span;
    }

    private function durationOf(string $fullPath): ?float
    {
        exec(sprintf('ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null', escapeshellarg($fullPath)), $o, $c);
        $d = trim(implode('', $o ?? []));
        return ($c === 0 && is_numeric($d)) ? (float) $d : null;
    }

    private function summarizeFfmpegOutput(array $lines): string
    {
        $counts = [];
        foreach ($lines as $l) {
            $k = preg_replace('/[0-9.:-]+/', '#', trim($l));
            if ($k === '') continue;
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        arsort($counts);
        $parts = [];
        foreach (array_slice($counts, 0, 4, true) as $k => $n) $parts[] = $n . 'x ' . substr($k, 0, 90);
        return $parts ? 'warnings: ' . implode(' | ', $parts) : 'no warnings';
    }

    /** True when ffprobe finds a decodable video stream in the file. */
    public function hasVideoStream(string $relativePath): bool
    {
        $full = Storage::disk('local')->path($relativePath);
        if (!is_file($full)) return false;
        exec(sprintf('ffprobe -v error -select_streams v:0 -show_entries stream=codec_type -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($full)), $out, $code);
        return $code === 0 && trim(implode('', $out)) === 'video';
    }

    /**
     * Finalize an upload and persist the result on its row. Returns false (and removes file + row)
     * when the recording has no video track.
     */
    public function completeUpload(?FaceVideo $row, string $relativePath): bool
    {
        $newPath = $this->finalize($relativePath);

        if (!$this->hasVideoStream($newPath)) {
            Storage::disk('local')->delete($newPath);
            if ($newPath !== $relativePath) Storage::disk('local')->delete($relativePath);
            $row?->delete();
            return false;
        }

        if ($row && $row->file_path !== $newPath) {
            $row->update(['file_path' => $newPath]);
        }
        return true;
    }

    /** Relative paths of the two sequence markers an upload id may use (chunked uses a leading dot). */
    public function seqMarkers(int $userId, string $uploadId): array
    {
        return [
            "face-video-chunks/{$userId}/.{$uploadId}.seq",
            "face-video-chunks/{$userId}/{$uploadId}.seq",
        ];
    }

    public function isUploading(int $userId, string $uploadId): bool
    {
        foreach ($this->seqMarkers($userId, $uploadId) as $marker) {
            if (Storage::disk('local')->exists($marker)) return true;
        }
        return false;
    }

    /**
     * Finalize every abandoned upload for a user: anything whose last chunk arrived more than
     * STALE_AFTER_SECONDS ago. The partial file is re-encoded so it plays, or removed if it holds no
     * video. Returns the number of uploads handled.
     */
    public function finalizeStaleUploads(int $userId, int $tenantId, int $minAgeSeconds = self::STALE_AFTER_SECONDS): int
    {
        $disk = Storage::disk('local');
        $chunkDir = "face-video-chunks/{$userId}";
        if (!$disk->exists($chunkDir)) return 0;

        $handled = 0;
        $now = time();
        $videoDir = "face-videos/{$tenantId}/{$userId}";

        foreach ($disk->files($chunkDir) as $marker) {
            $base = basename($marker);
            if (!str_ends_with($base, '.seq')) continue;
            if ($now - $disk->lastModified($marker) < $minAgeSeconds) continue;

            $uploadId = ltrim(substr($base, 0, -4), '.');

            $row = FaceVideo::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('file_path', 'like', "{$videoDir}/{$uploadId}.%")
                ->first();

            $candidates = array_values(array_unique(array_filter([
                $row?->file_path,
                "{$videoDir}/{$uploadId}.webm",
                "{$videoDir}/{$uploadId}.mp4",
            ])));
            $path = null;
            foreach ($candidates as $c) {
                if ($disk->exists($c)) { $path = $c; break; }
            }

            if ($path === null) {
                $row?->delete();
            } else {
                $this->completeUpload($row, $path);
            }

            $disk->delete($marker);
            $handled++;
        }

        return $handled;
    }
}
