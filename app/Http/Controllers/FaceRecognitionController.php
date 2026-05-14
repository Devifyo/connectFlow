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
            'image' => 'required|string|max:5000000',
        ]);

        $user = auth()->user();
        $minConfidence = config('deepstack.min_confidence');
        $expectedUserId = "tenant_{$user->tenant_id}_user_{$user->id}";

        $result = $deepstack->recognizeFace($request->image);

        if (!$result['success']) {
            return response()->json([
                'verified' => false,
                'error' => 'Face recognition service is temporarily unavailable.',
            ], 503);
        }

        foreach ($result['predictions'] ?? [] as $prediction) {
            if ($prediction['userid'] === $expectedUserId && $prediction['confidence'] >= $minConfidence) {
                return response()->json([
                    'verified' => true,
                    'confidence' => round($prediction['confidence'], 4),
                ]);
            }
        }

        return response()->json([
            'verified' => false,
            'error' => 'Face not recognized. Please try again with better lighting.',
        ]);
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
