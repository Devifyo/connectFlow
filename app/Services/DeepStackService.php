<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DeepStackService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('deepstack.url'), '/');
        $this->apiKey = config('deepstack.api_key', '');
    }

    public function registerFace(string $userId, string $base64Image): array
    {
        $imageData = $this->decodeBase64Image($base64Image);
        if (!$imageData) {
            return ['success' => false, 'message' => 'Invalid image data.'];
        }

        try {
            $request = Http::timeout(30)
                ->attach('image', $imageData, 'face.jpg');

            if ($this->apiKey) {
                $request = $request->withHeaders(['api_key' => $this->apiKey]);
            }

            $response = $request->post("{$this->baseUrl}/v1/vision/face/register", [
                'userid' => $userId,
            ]);

            $data = $response->json();

            return [
                'success' => ($data['success'] ?? false) === true,
                'message' => $data['message'] ?? 'Unknown error',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'DeepStack service unavailable: ' . $e->getMessage()];
        }
    }

    public function recognizeFace(string $base64Image): array
    {
        $imageData = $this->decodeBase64Image($base64Image);
        if (!$imageData) {
            return ['success' => false, 'predictions' => []];
        }

        try {
            $request = Http::timeout(30)
                ->attach('image', $imageData, 'face.jpg');

            if ($this->apiKey) {
                $request = $request->withHeaders(['api_key' => $this->apiKey]);
            }

            $response = $request->post("{$this->baseUrl}/v1/vision/face/recognize");

            $data = $response->json();

            return [
                'success' => ($data['success'] ?? false) === true,
                'predictions' => $data['predictions'] ?? [],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'predictions' => []];
        }
    }

    public function deleteFace(string $userId): array
    {
        try {
            $request = Http::timeout(15);

            if ($this->apiKey) {
                $request = $request->withHeaders(['api_key' => $this->apiKey]);
            }

            $response = $request->post("{$this->baseUrl}/v1/vision/face/delete", [
                'userid' => $userId,
            ]);

            $data = $response->json();

            return [
                'success' => ($data['success'] ?? false) === true,
            ];
        } catch (\Throwable $e) {
            return ['success' => false];
        }
    }

    private function decodeBase64Image(string $base64): ?string
    {
        if (str_contains($base64, ',')) {
            $base64 = explode(',', $base64, 2)[1];
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false || strlen($decoded) < 100) {
            return null;
        }

        return $decoded;
    }
}
