<?php

namespace App\Http\Controllers;

use App\Models\FaceLoginAttempt;
use App\Models\FaceVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeepStackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $bestConfidence = 0;
        $bestMatchUserId = null;
        $faceDetected = false;

        foreach ($request->images as $image) {
            $result = $deepstack->recognizeFace($image);

            if (!$result['success']) continue;

            foreach ($result['predictions'] ?? [] as $prediction) {
                if (!str_starts_with($prediction['userid'], $tenantPrefix)) continue;

                $faceDetected = true;

                if ($prediction['userid'] === $expectedUserId && $prediction['confidence'] >= $minConfidence) {
                    return response()->json([
                        'verified' => true,
                        'confidence' => round($prediction['confidence'], 4),
                    ]);
                }

                if ($prediction['confidence'] > $bestConfidence) {
                    $bestConfidence = $prediction['confidence'];
                    $bestMatchUserId = $prediction['userid'];
                }
            }
        }

        if (!$faceDetected) {
            return response()->json([
                'verified' => false,
                'error' => 'No face detected. Please ensure your face is clearly visible and well-lit.',
            ]);
        }

        if ($bestMatchUserId === $expectedUserId && $bestConfidence > 0) {
            return response()->json([
                'verified' => false,
                'error' => 'Face recognized but confidence too low (' . round($bestConfidence * 100) . '%). Move closer and ensure good lighting.',
            ]);
        }

        return response()->json([
            'verified' => false,
            'error' => 'Face did not match your profile. Please re-enroll your face from Profile settings.',
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
        ]);

        $user = auth()->user();
        $dir = "face-videos/{$user->tenant_id}/{$user->id}";
        $path = $request->file('video')->store($dir, 'local');

        FaceVideo::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $request->type,
            'file_path' => $path,
            'time_log_id' => $request->time_log_id,
            'verified' => $request->has('verified') ? $request->boolean('verified') : true,
        ]);

        return response()->json(['status' => 'success']);
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
        ]);

        $user = auth()->user();
        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request->upload_id);
        $chunkDir = "face-video-chunks/{$user->id}/{$uploadId}";

        $request->file('chunk')->storeAs($chunkDir, "chunk_{$request->chunk_index}", 'local');

        $totalChunks = (int) $request->total_chunks;
        $chunkIndex = (int) $request->chunk_index;

        if ($chunkIndex === $totalChunks - 1) {
            $dir = "face-videos/{$user->tenant_id}/{$user->id}";
            $filename = "{$uploadId}.webm";
            $finalPath = "{$dir}/{$filename}";

            Storage::disk('local')->makeDirectory($dir);
            $fullPath = Storage::disk('local')->path($finalPath);
            $out = fopen($fullPath, 'wb');

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = Storage::disk('local')->path("{$chunkDir}/chunk_{$i}");
                if (!file_exists($chunkPath)) {
                    fclose($out);
                    @unlink($fullPath);
                    return response()->json(['error' => "Missing chunk {$i}"], 422);
                }
                $in = fopen($chunkPath, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            Storage::disk('local')->deleteDirectory($chunkDir);

            FaceVideo::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $request->type,
                'file_path' => $finalPath,
                'time_log_id' => $request->time_log_id,
                'verified' => $request->has('verified') ? $request->boolean('verified') : true,
            ]);

            return response()->json(['status' => 'complete']);
        }

        return response()->json(['status' => 'chunk_received', 'chunk_index' => $chunkIndex]);
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

        return response()->file($path, [
            'Content-Type' => 'video/webm',
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
}
