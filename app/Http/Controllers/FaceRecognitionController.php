<?php

namespace App\Http\Controllers;

use App\Models\FaceVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeepStackService;
use Illuminate\Http\Request;
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
            ->limit(50)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type,
                'time_log_id' => $v->time_log_id,
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
}
