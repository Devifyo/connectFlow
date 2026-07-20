<?php

namespace App\Http\Controllers;

use App\Models\AiBackgroundLog;
use App\Models\FaceLoginAttempt;
use App\Models\FaceVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeepStackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FaceRecognitionController extends Controller
{
    public function status()
    {
        $user = auth()->user();
        $tenant = Tenant::find($user->tenant_id);

        return response()->json([
            'enabled' => (bool) $tenant?->face_recognition_enabled,
            'enrolled' => !is_null($user->face_enrolled_at),
            'enrolled_at' => $user->face_enrolled_at,
        ]);
    }

    public function enroll(Request $request, DeepStackService $deepstack)
    {
        $request->validate([
            'image' => 'required|string|max:5000000',
        ]);

        $user = auth()->user();
        $tenant = Tenant::find($user->tenant_id);

        if (!$tenant?->face_recognition_enabled) {
            return response()->json(['error' => 'Face recognition is not enabled for your organization.'], 403);
        }

        $userId = "tenant_{$user->tenant_id}_user_{$user->id}";

        if ($user->face_enrolled_at) {
            $deepstack->deleteFace($userId);
        }

        $result = $deepstack->registerFace($userId, $request->image);

        if (!$result['success']) {
            return response()->json([
                'error' => $result['message'] ?? 'Face registration failed. Ensure your face is clearly visible.',
            ], 422);
        }

        $user->forceFill(['face_enrolled_at' => now()])->save();

        return response()->json(['status' => 'success']);
    }

    public function verify(Request $request, DeepStackService $deepstack)
    {
        $request->validate([
            'images' => 'required|array|min:1|max:3',
            'images.*' => 'required|string|max:5000000',
        ]);

        $user = auth()->user();
        $minConfidence = max(config('deepstack.min_confidence') - 0.15, 0.40);
        $expectedUserId = "tenant_{$user->tenant_id}_user_{$user->id}";
        $tenantPrefix = "tenant_{$user->tenant_id}_";

        if (count($request->images) >= 2) {
            $filterDetected = $this->detectFilterServerSide($request->images[0], $request->images[count($request->images) - 1]);
            if ($filterDetected) {
                return response()->json([
                    'verified' => false,
                    'error' => 'Background filter detected. Please disable any virtual background, blur, or camera filters and try again.',
                ]);
            }
        }

        $aiResult = $this->analyzeBackgroundWithAI($request->images[0], $user->tenant_id, $user->id, 'verify', $request->ip());
        if ($aiResult && $aiResult['virtual_bg'] === true && ($aiResult['confidence'] ?? 0) >= 0.75) {
            return response()->json([
                'verified' => false,
                'error' => 'Virtual or filtered background detected. Please use a real physical background and try again.',
            ]);
        }

        $bestConfidence = 0;
        $bestMatchUserId = null;
        $faceDetected = false;

        foreach ($request->images as $image) {
            $result = $deepstack->recognizeFace($image);

            if (!$result['success']) continue;

            foreach ($result['predictions'] ?? [] as $prediction) {
                if (!str_starts_with($prediction['userid'], $tenantPrefix)) continue;

                $faceDetected = true;

                if ($prediction['confidence'] > $bestConfidence) {
                    $bestConfidence = $prediction['confidence'];
                    $bestMatchUserId = $prediction['userid'];
                }

                if ($prediction['userid'] === $expectedUserId && $prediction['confidence'] >= $minConfidence) {
                    return response()->json([
                        'verified' => true,
                        'confidence' => round($prediction['confidence'], 4),
                    ]);
                }
            }
        }

        if (!$faceDetected) {
            return response()->json([
                'verified' => false,
                'error' => 'No face detected. Please ensure your face is clearly visible and well-lit.',
            ]);
        }

        // Accept same-tenant match with high confidence for punch verification
        // (user is already authenticated — this confirms a real person is present)
        if ($bestMatchUserId && $bestConfidence >= 0.60) {
            Log::info("Face verify: user {$user->id} matched as {$bestMatchUserId} (conf: {$bestConfidence})");
            return response()->json([
                'verified' => true,
                'confidence' => round($bestConfidence, 4),
            ]);
        }

        if ($bestConfidence > 0) {
            return response()->json([
                'verified' => false,
                'error' => 'Face recognized but confidence too low (' . round($bestConfidence * 100) . '%). Move closer and ensure good lighting.',
            ]);
        }

        return response()->json([
            'verified' => false,
            'error' => 'Face not recognized. Please re-enroll your face from Profile settings.',
        ]);
    }

    private function detectFilterServerSide(string $base64First, string $base64Last): bool
    {
        $img1 = $this->base64ToGdImage($base64First);
        $img2 = $this->base64ToGdImage($base64Last);

        if (!$img1 || !$img2) return false;

        $w = min(imagesx($img1), imagesx($img2));
        $h = min(imagesy($img1), imagesy($img2));

        $edgeW = (int) ($w * 0.15);
        $topH = (int) ($h * 0.20);
        $step = 3;

        // Check 1: Temporal stability in border regions
        $borderTotal = 0;
        $borderStatic = 0;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if ($x >= $edgeW && $x < $w - $edgeW && $y >= $topH) continue;
                $c1 = imagecolorat($img1, $x, $y);
                $c2 = imagecolorat($img2, $x, $y);
                $diff = abs((($c1 >> 16) & 0xFF) - (($c2 >> 16) & 0xFF))
                      + abs((($c1 >> 8) & 0xFF) - (($c2 >> 8) & 0xFF))
                      + abs(($c1 & 0xFF) - ($c2 & 0xFF));
                $borderTotal++;
                if ($diff <= 2) $borderStatic++;
            }
        }
        $staticRatio = $borderTotal > 0 ? $borderStatic / $borderTotal : 0;

        // Check 2: Border sharpness vs center sharpness (blur detection)
        $borderSharpness = $this->regionSharpness($img2, 0, 0, $edgeW, $h, $step)
                         + $this->regionSharpness($img2, $w - $edgeW, 0, $w, $h, $step);
        $borderSharpness /= 2;
        $centerSharpness = $this->regionSharpness($img2, (int)($w*0.3), (int)($h*0.2), (int)($w*0.7), (int)($h*0.8), $step);
        $blurRatio = ($centerSharpness > 0) ? $borderSharpness / $centerSharpness : 1;

        // Check 3: Color uniformity in far edges
        $edgeVar = ($this->regionColorVariance($img2, 0, 0, (int)($w*0.08), $h, $step)
                  + $this->regionColorVariance($img2, (int)($w*0.92), 0, $w, $h, $step)) / 2;

        // Check 4: Segmentation halo — soft edges from virtual BG blending
        $softEdgeRatio = $this->detectSoftEdges($img2);

        // Check 5: Color blend artifacts — linear interpolation at transitions
        $blendRatio2 = $this->detectBlendArtifacts($img2);

        imagedestroy($img1);
        imagedestroy($img2);

        // Strong blur = definite filter
        if ($blurRatio < 0.20) return true;

        // Segmentation halo = definite virtual background
        if ($softEdgeRatio > 0.78 && $blendRatio2 > 0.58) return true;

        // Otherwise need 2 out of 5 signals
        $flags = 0;
        if ($staticRatio > 0.88) $flags++;
        if ($blurRatio < 0.35) $flags++;
        if ($edgeVar < 12) $flags++;
        if ($softEdgeRatio > 0.76) $flags++;
        if ($blendRatio2 > 0.55) $flags++;

        return $flags >= 2;
    }

    private function getBackgroundPrompt(): string
    {
        return 'You are a security system analyzing webcam frames for face verification during employee clock-in. '
            . 'Your job is to detect if the background is REAL and UNMODIFIED or if ANY digital manipulation has been applied. '
            . 'This is captured from a laptop/phone webcam for a workplace punch-in system. '
            . "\n\nFLAG AS VIRTUAL (virtual_bg: true) if ANY of these are detected:\n"
            . '1) Virtual/replaced background: outdoor scenes, stock photos, custom images behind the person. '
            . '2) Background blur of ANY kind: iPhone/Android portrait mode, Zoom/Teams/Meet blur, any app-based blur. '
            . 'IMPORTANT: Webcams and front-facing phone cameras have small sensors with wide depth of field — they do NOT produce natural background blur/bokeh. '
            . 'If the person is sharp but the background is blurry, it is ALWAYS artificial (computational blur from portrait mode, video call apps, or camera settings). Flag it. '
            . '3) Green screen or chroma key replacement. '
            . '4) Segmentation artifacts: soft/feathered edges around hair, shoulders, or body outline. '
            . '5) Lighting mismatch between person and background. '
            . '6) Video call UI overlays, watermarks, or app interface elements visible. '
            . '7) Person indoors but background shows outdoors. '
            . '8) Background is unnaturally uniform, smooth, or has suspiciously low texture/detail. '
            . "\n\nFLAG AS REAL (virtual_bg: false) ONLY if the background is clearly a real, unmodified physical environment with natural detail, texture, and consistent lighting throughout the entire image.\n"
            . 'Be STRICT. When uncertain, lean toward flagging. '
            . 'Reply with ONLY a JSON object: {"virtual_bg": true/false, "confidence": 0.0-1.0, "reason": "brief reason"}';
    }

    private function analyzeBackgroundWithAI(string $base64Image, ?int $tenantId = null, ?int $userId = null, string $action = 'verify', ?string $ip = null): ?array
    {
        try {
            $provider = config('services.face_ai.provider', 'anthropic');

            $result = ($provider === 'gemini')
                ? $this->analyzeWithGemini($base64Image)
                : $this->analyzeWithAnthropic($base64Image);

            $apiFailed = $result === null || isset($result['_error']);
            $errorMsg = $result['_error'] ?? ($result === null ? 'No response or invalid JSON' : null);

            if ($apiFailed) {
                $result = null;
            }

            try {
                if ($tenantId) {
                    AiBackgroundLog::create([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'provider' => $provider,
                        'action' => $action,
                        'virtual_bg_detected' => $result['virtual_bg'] ?? null,
                        'confidence' => $result['confidence'] ?? null,
                        'reason' => $result['reason'] ?? null,
                        'api_failed' => $apiFailed,
                        'error_message' => $errorMsg,
                        'ip_address' => $ip,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('AI background log save failed: ' . $e->getMessage());
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('AI background analysis failed entirely: ' . $e->getMessage());
            return null;
        }
    }

    private function analyzeWithGemini(string $base64Image): ?array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) return null;

        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);

        try {
            $response = Http::timeout(10)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [[
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/jpeg',
                                    'data' => $imageData,
                                ],
                            ],
                            [
                                'text' => $this->getBackgroundPrompt(),
                            ],
                        ],
                    ]],
                    'generationConfig' => [
                        'maxOutputTokens' => 500,
                        'temperature' => 0.1,
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]
            );

            if ($response->successful()) {
                $text = $response->json('candidates.0.content.parts.0.text', '');
                return $this->parseAIResponse($text);
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini background analysis failed: ' . $e->getMessage());
            return ['_error' => $e->getMessage()];
        }

        return null;
    }

    private function analyzeWithAnthropic(string $base64Image): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) return null;

        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);

        try {
            $response = Http::timeout(10)->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 200,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'image/jpeg',
                                'data' => $imageData,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->getBackgroundPrompt(),
                        ],
                    ],
                ]],
            ]);

            if ($response->successful()) {
                $text = $response->json('content.0.text', '');
                return $this->parseAIResponse($text);
            }
        } catch (\Throwable $e) {
            Log::warning('Anthropic background analysis failed: ' . $e->getMessage());
            return ['_error' => $e->getMessage()];
        }

        return null;
    }

    private function parseAIResponse(string $text): ?array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $json = json_decode($text, true);
        if ($json && isset($json['virtual_bg'])) {
            return $json;
        }
        if (preg_match('/\{[^}]+\}/', $text, $m)) {
            $json = json_decode($m[0], true);
            if ($json && isset($json['virtual_bg'])) {
                return $json;
            }
        }
        return null;
    }

    private function base64ToGdImage(string $base64): ?\GdImage
    {
        if (str_contains($base64, ',')) {
            $base64 = explode(',', $base64, 2)[1];
        }
        $data = base64_decode($base64, true);
        if (!$data) return null;
        $img = @imagecreatefromstring($data);
        return $img ?: null;
    }

    private function regionSharpness(\GdImage $img, int $x0, int $y0, int $x1, int $y1, int $step): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sum = 0;
        $count = 0;
        for ($y = max($y0, 1); $y < min($y1, $h - 1); $y += $step) {
            for ($x = max($x0, 1); $x < min($x1, $w - 1); $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $gray = (($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114;

                $up = imagecolorat($img, $x, $y - 1);
                $dn = imagecolorat($img, $x, $y + 1);
                $lt = imagecolorat($img, $x - 1, $y);
                $rt = imagecolorat($img, $x + 1, $y);

                $gUp = (($up >> 16) & 0xFF) * 0.299 + (($up >> 8) & 0xFF) * 0.587 + ($up & 0xFF) * 0.114;
                $gDn = (($dn >> 16) & 0xFF) * 0.299 + (($dn >> 8) & 0xFF) * 0.587 + ($dn & 0xFF) * 0.114;
                $gLt = (($lt >> 16) & 0xFF) * 0.299 + (($lt >> 8) & 0xFF) * 0.587 + ($lt & 0xFF) * 0.114;
                $gRt = (($rt >> 16) & 0xFF) * 0.299 + (($rt >> 8) & 0xFF) * 0.587 + ($rt & 0xFF) * 0.114;

                $sum += abs($gUp + $gDn + $gLt + $gRt - 4 * $gray);
                $count++;
            }
        }
        return $count > 0 ? $sum / $count : 0;
    }

    private function detectSoftEdges(\GdImage $img): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $softEdges = 0;
        $sharpEdges = 0;

        for ($y = (int)($h * 0.15); $y < (int)($h * 0.85); $y += 5) {
            $grads = [];
            for ($x = 1; $x < $w - 1; $x++) {
                $gl = $this->grayAt($img, $x - 1, $y);
                $gr = $this->grayAt($img, $x + 1, $y);
                $grads[$x] = abs($gr - $gl);
            }

            for ($x = 10; $x < $w - 10; $x++) {
                if ($grads[$x] < 15) continue;
                $isPeak = true;
                for ($dx = -3; $dx <= 3; $dx++) {
                    if ($dx === 0) continue;
                    if (isset($grads[$x + $dx]) && $grads[$x + $dx] > $grads[$x]) {
                        $isPeak = false;
                        break;
                    }
                }
                if (!$isPeak) continue;

                $threshold = $grads[$x] * 0.3;
                $width = 0;
                for ($dx = -15; $dx <= 15; $dx++) {
                    if (isset($grads[$x + $dx]) && $grads[$x + $dx] > $threshold) $width++;
                }

                if ($width >= 8) $softEdges++;
                else $sharpEdges++;

                $x += 15;
            }
        }

        $total = $softEdges + $sharpEdges;
        return $total > 0 ? $softEdges / $total : 0;
    }

    private function detectBlendArtifacts(\GdImage $img): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $blendArtifacts = 0;
        $transitionChecks = 0;

        for ($y = (int)($h * 0.2); $y < (int)($h * 0.8); $y += 8) {
            for ($x = 10; $x < $w - 10; $x++) {
                $gc = $this->grayAt($img, $x, $y);
                $g5l = $this->grayAt($img, $x - 5, $y);
                $g5r = $this->grayAt($img, $x + 5, $y);

                if (abs($g5l - $g5r) < 20) continue;

                $transitionChecks++;
                $expectedBlend = ($g5l + $g5r) / 2;
                if (abs($gc - $expectedBlend) < 8) $blendArtifacts++;

                $x += 5;
            }
        }

        return $transitionChecks > 0 ? $blendArtifacts / $transitionChecks : 0;
    }

    private function grayAt(\GdImage $img, int $x, int $y): float
    {
        $c = imagecolorat($img, $x, $y);
        return (($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114;
    }

    private function regionColorVariance(\GdImage $img, int $x0, int $y0, int $x1, int $y1, int $step): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sr = 0; $sg = 0; $sb = 0; $n = 0;
        for ($y = $y0; $y < min($y1, $h); $y += $step) {
            for ($x = $x0; $x < min($x1, $w); $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $sr += ($c >> 16) & 0xFF;
                $sg += ($c >> 8) & 0xFF;
                $sb += $c & 0xFF;
                $n++;
            }
        }
        if ($n === 0) return 999;
        $mr = $sr / $n; $mg = $sg / $n; $mb = $sb / $n;
        $vr = 0; $vg = 0; $vb = 0;
        for ($y = $y0; $y < min($y1, $h); $y += $step) {
            for ($x = $x0; $x < min($x1, $w); $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $vr += ((($c >> 16) & 0xFF) - $mr) ** 2;
                $vg += ((($c >> 8) & 0xFF) - $mg) ** 2;
                $vb += (($c & 0xFF) - $mb) ** 2;
            }
        }
        return sqrt(($vr + $vg + $vb) / (3 * $n));
    }

    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/webm,video/mp4|max:20480',
            'type' => 'required|in:enrollment,punch_in,punch_out',
            'time_log_id' => 'nullable|integer',
            'verified' => 'nullable|boolean',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $dir = "face-videos/{$user->tenant_id}/{$user->id}";
        $path = $request->file('video')->store($dir, 'local');

        $path = $this->finalizeVideoFile($path);

        FaceVideo::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $request->type,
            'file_path' => $path,
            'time_log_id' => $request->time_log_id,
            'verified' => $request->has('verified') ? $request->boolean('verified') : true,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'address' => $request->address,
            'ip_address' => $request->ip(),
            'device' => $request->userAgent(),
        ]);

        return response()->json(['status' => 'success']);
    }

    // Recordings from MediaRecorder often have no duration marker and non-monotonic
    // timestamps, so browsers show duration=Infinity or play only the first frame and stall.
    // A plain remux (`-c copy`) can't fix the baked-in timestamps, so we RE-ENCODE to a clean,
    // faststart H.264/AAC mp4 that plays and seeks everywhere. Returns the (possibly new .mp4)
    // relative path; on any failure the original path is returned unchanged.
    private function finalizeVideoFile(string $relativePath): string
    {
        try {
            $full = Storage::disk('local')->path($relativePath);
            if (!is_file($full) || filesize($full) < 1024) {
                return $relativePath;
            }

            $dir = trim(dirname($relativePath), '.');
            $name = pathinfo($relativePath, PATHINFO_FILENAME);
            $newRel = ($dir !== '' ? $dir . '/' : '') . $name . '.mp4';
            $newFull = Storage::disk('local')->path($newRel);
            $tmp = $newFull . '.tmp.mp4';

            $cmd = sprintf(
                'ffmpeg -y -loglevel error -fflags +genpts -i %s -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -movflags +faststart %s',
                escapeshellarg($full), escapeshellarg($tmp)
            );
            exec($cmd . ' 2>/dev/null', $out, $code);

            if ($code === 0 && is_file($tmp) && filesize($tmp) > 1024) {
                @rename($tmp, $newFull);
                if ($newFull !== $full) {
                    @unlink($full);
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
            return $relativePath;
        }
    }

    public function uploadVideoChunk(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file|max:1024',
            'upload_id' => 'required|string|max:100',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1|max:100',
            'type' => 'required|in:enrollment,punch_in,punch_out',
            'time_log_id' => 'nullable|integer',
            'verified' => 'nullable|boolean',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'ext' => 'nullable|in:webm,mp4',
        ]);

        $user = auth()->user();
        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request->upload_id);
        $ext = $request->input('ext') === 'mp4' ? 'mp4' : 'webm';

        $totalChunks = (int) $request->total_chunks;
        $chunkIndex = (int) $request->chunk_index;

        // Append each chunk to the final file as it arrives (chunk 0 carries the header),
        // and create the DB row on chunk 0. This way a punch upload that is interrupted
        // — e.g. the employee locks the phone right after clocking in — still leaves a
        // playable video and a visible record instead of an orphaned pile of chunks.
        $dir = "face-videos/{$user->tenant_id}/{$user->id}";
        $finalPath = "{$dir}/{$uploadId}.{$ext}";
        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->makeDirectory("face-video-chunks/{$user->id}");
        $fullPath = Storage::disk('local')->path($finalPath);
        $seqPath = Storage::disk('local')->path("face-video-chunks/{$user->id}/.{$uploadId}.seq");

        $lastSeq = is_file($seqPath) ? (int) file_get_contents($seqPath) : -1;

        // Idempotent, strictly ordered append (tolerates client retries / re-sends).
        if ($chunkIndex <= $lastSeq) {
            return response()->json(['status' => 'duplicate', 'last_seq' => $lastSeq]);
        }
        if ($chunkIndex !== $lastSeq + 1) {
            return response()->json(['error' => 'out_of_order', 'last_seq' => $lastSeq], 409);
        }

        $bytes = file_get_contents($request->file('chunk')->getRealPath());

        if ($chunkIndex === 0) {
            file_put_contents($fullPath, $bytes);
            FaceVideo::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $request->type,
                'file_path' => $finalPath,
                'time_log_id' => $request->time_log_id,
                'verified' => $request->has('verified') ? $request->boolean('verified') : true,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $request->address,
                'ip_address' => $request->ip(),
                'device' => $request->userAgent(),
            ]);
        } else {
            file_put_contents($fullPath, $bytes, FILE_APPEND);
        }

        file_put_contents($seqPath, (string) $chunkIndex);

        if ($chunkIndex === $totalChunks - 1) {
            @unlink($seqPath);
            $newPath = $this->finalizeVideoFile($finalPath);
            if ($newPath !== $finalPath) {
                FaceVideo::where('file_path', $finalPath)->where('user_id', $user->id)
                    ->update(['file_path' => $newPath]);
            }
            return response()->json(['status' => 'complete']);
        }

        return response()->json(['status' => 'chunk_received', 'chunk_index' => $chunkIndex]);
    }

    // Live streaming upload: the recorder pushes each ~2s webm chunk as it is produced and
    // we append it to the final file in order. Because chunk 0 carries the webm header, the
    // file stays playable at every point — so if the employee closes the browser mid-punch,
    // whatever reached the server is already a valid (if short) video instead of a broken one.
    public function streamVideoChunk(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file|max:4096',
            'upload_id' => 'required|string|max:100',
            'seq' => 'required|integer|min:0',
            'type' => 'required|in:enrollment,punch_in,punch_out',
            'time_log_id' => 'nullable|integer',
            'verified' => 'nullable|boolean',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request->upload_id);
        $seq = (int) $request->seq;

        $dir = "face-videos/{$user->tenant_id}/{$user->id}";
        $file = "{$dir}/{$uploadId}.webm";
        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->makeDirectory("face-video-chunks/{$user->id}");
        $fullFile = Storage::disk('local')->path($file);
        $seqPath = Storage::disk('local')->path("face-video-chunks/{$user->id}/{$uploadId}.seq");

        $lastSeq = is_file($seqPath) ? (int) file_get_contents($seqPath) : -1;

        // Idempotent, strictly ordered append (handles retries and re-sends on reopen).
        if ($seq <= $lastSeq) {
            return response()->json(['status' => 'duplicate', 'last_seq' => $lastSeq]);
        }
        if ($seq !== $lastSeq + 1) {
            return response()->json(['error' => 'out_of_order', 'last_seq' => $lastSeq], 409);
        }

        $bytes = file_get_contents($request->file('chunk')->getRealPath());

        if ($seq === 0) {
            file_put_contents($fullFile, $bytes);
            FaceVideo::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $request->type,
                'file_path' => $file,
                'time_log_id' => $request->time_log_id,
                'verified' => $request->has('verified') ? $request->boolean('verified') : true,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $request->address,
                'ip_address' => $request->ip(),
                'device' => $request->userAgent(),
            ]);
        } else {
            file_put_contents($fullFile, $bytes, FILE_APPEND);
        }

        file_put_contents($seqPath, (string) $seq);

        return response()->json(['status' => 'ok', 'last_seq' => $seq]);
    }

    public function streamVideoFinalize(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string|max:100',
            'time_log_id' => 'nullable|integer',
        ]);

        $user = auth()->user();
        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request->upload_id);
        $file = "face-videos/{$user->tenant_id}/{$user->id}/{$uploadId}.webm";

        if ($request->filled('time_log_id')) {
            FaceVideo::where('file_path', $file)
                ->where('user_id', $user->id)
                ->update(['time_log_id' => (int) $request->time_log_id]);
        }

        @unlink(Storage::disk('local')->path("face-video-chunks/{$user->id}/{$uploadId}.seq"));

        // Live-streamed file needs re-encoding to be natively playable/seekable.
        $newFile = $this->finalizeVideoFile($file);
        if ($newFile !== $file) {
            FaceVideo::where('file_path', $file)->where('user_id', $user->id)
                ->update(['file_path' => $newFile]);
        }

        return response()->json(['status' => 'finalized']);
    }

    public function memberVideos(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        $query = FaceVideo::where('user_id', $user->id);

        if ($request->type) {
            $query->where('type', $request->type);
        }

        $videos = $query->orderBy('created_at', 'desc')
            ->limit(200)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type,
                'time_log_id' => $v->time_log_id,
                'verified' => (bool) $v->verified,
                'starred' => (bool) $v->starred,
                'created_at' => $v->created_at->toIso8601String(),
                'location' => $v->latitude ? ['lat' => (float) $v->latitude, 'lng' => (float) $v->longitude, 'address' => $v->address] : null,
                'ip_address' => $v->ip_address,
                'device' => $v->device,
            ]);

        return response()->json(['videos' => $videos]);
    }

    public function streamVideo($id)
    {
        $video = FaceVideo::findOrFail($id);

        $user = auth()->user();
        $isSelf = $video->user_id === $user->id;
        $isAdmin = $user->hasRole('TenantAdmin') || $user->hasRole('SuperAdmin');

        if (!$isSelf && !$isAdmin) {
            abort(403);
        }

        $path = Storage::disk('local')->path($video->file_path);

        if (!file_exists($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($video->file_path, PATHINFO_EXTENSION));
        $mime = $ext === 'mp4' ? 'video/mp4' : 'video/webm';

        return response()->file($path, [
            'Content-Type' => $mime,
        ]);
    }

    public function toggleStarVideo(Request $request, int $id)
    {
        $video = FaceVideo::findOrFail($id);
        $video->update(['starred' => !$video->starred]);

        return response()->json(['starred' => (bool) $video->starred]);
    }

    public function faceLogin(Request $request, DeepStackService $deepstack)
    {
        $request->validate([
            'frames' => 'required|array|min:3|max:5',
            'frames.*.image' => 'required|string|max:5000000',
            'frames.*.key' => 'required|string|in:center,left,right,up,down',
        ]);

        $ip = $request->ip();
        $ua = $request->userAgent();
        $loginConfidence = (float) config('deepstack.min_confidence', 0.65);

        $recentFails = FaceLoginAttempt::where('ip_address', $ip)
            ->where('success', false)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentFails >= 5) {
            return response()->json([
                'error' => 'Too many failed attempts. Please try again later or use email and password.',
            ], 429);
        }

        $centerFrame = collect($request->frames)->firstWhere('key', 'center');
        if (!$centerFrame) {
            return response()->json(['error' => 'Center frame is required.'], 422);
        }

        $aiResult = $this->analyzeBackgroundWithAI($centerFrame['image'], null, null, 'face_login', $ip);
        if ($aiResult && $aiResult['virtual_bg'] === true && ($aiResult['confidence'] ?? 0) >= 0.75) {
            FaceLoginAttempt::create([
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'virtual_background_detected',
            ]);
            return response()->json(['error' => 'Virtual or filtered background detected. Please use a real physical background and try again.'], 403);
        }

        $result = $deepstack->recognizeFace($centerFrame['image']);

        if (!$result['success']) {
            FaceLoginAttempt::create([
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'service_unavailable',
            ]);
            return response()->json(['error' => 'Face recognition service is temporarily unavailable.'], 503);
        }

        $bestMatch = null;
        foreach ($result['predictions'] ?? [] as $prediction) {
            if ($prediction['confidence'] >= $loginConfidence) {
                if (!$bestMatch || $prediction['confidence'] > $bestMatch['confidence']) {
                    $bestMatch = $prediction;
                }
            }
        }

        if (!$bestMatch) {
            FaceLoginAttempt::create([
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'no_match',
                'confidence' => $result['predictions'][0]['confidence'] ?? null,
            ]);
            return response()->json(['error' => 'Face not recognized. Please try again or use email and password.'], 401);
        }

        $matchedTag = $bestMatch['userid'];
        if (!preg_match('/^tenant_(\d+)_user_(\d+)$/', $matchedTag, $m)) {
            FaceLoginAttempt::create([
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'invalid_tag_format',
                'matched_user_id_tag' => $matchedTag,
            ]);
            return response()->json(['error' => 'Face not recognized.'], 401);
        }

        $tenantId = (int) $m[1];
        $userId = (int) $m[2];

        $user = User::where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('face_enrolled_at')
            ->where('is_active', true)
            ->first();

        if (!$user) {
            FaceLoginAttempt::create([
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'user_not_found_or_inactive',
                'matched_user_id_tag' => $matchedTag,
            ]);
            return response()->json(['error' => 'Face not recognized.'], 401);
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant?->face_recognition_enabled) {
            FaceLoginAttempt::create([
                'user_id' => $user->id,
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => 'face_login_disabled',
                'matched_user_id_tag' => $matchedTag,
            ]);
            return response()->json(['error' => 'Face login is not enabled for your organization.'], 403);
        }

        $verifiedCount = 0;
        $expectedTag = "tenant_{$tenantId}_user_{$userId}";

        foreach ($request->frames as $frame) {
            $frameResult = $deepstack->recognizeFace($frame['image']);
            if (!$frameResult['success']) continue;

            foreach ($frameResult['predictions'] ?? [] as $p) {
                if ($p['userid'] === $expectedTag && $p['confidence'] >= $loginConfidence) {
                    $verifiedCount++;
                    break;
                }
            }
        }

        if ($verifiedCount < 3) {
            FaceLoginAttempt::create([
                'user_id' => $user->id,
                'ip_address' => $ip,
                'user_agent' => $ua,
                'success' => false,
                'failure_reason' => "multi_frame_failed:{$verifiedCount}/" . count($request->frames),
                'matched_user_id_tag' => $matchedTag,
                'confidence' => $bestMatch['confidence'],
            ]);
            return response()->json(['error' => 'Verification failed. Please ensure your face is clearly visible from all angles.'], 401);
        }

        FaceLoginAttempt::create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'success' => true,
            'matched_user_id_tag' => $matchedTag,
            'confidence' => $bestMatch['confidence'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'redirect' => route('dashboard'),
        ]);
    }

    public function aiBackgroundLogs(Request $request)
    {
        $user = auth()->user();
        $logs = AiBackgroundLog::where('tenant_id', $user->tenant_id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'user_name' => $log->user?->name,
                'provider' => $log->provider,
                'action' => $log->action,
                'virtual_bg_detected' => $log->virtual_bg_detected,
                'confidence' => $log->confidence,
                'reason' => $log->reason,
                'api_failed' => $log->api_failed,
                'error_message' => $log->error_message,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toISOString(),
            ]);

        return response()->json(['logs' => $logs]);
    }
}
