<?php

namespace App\Services;

class JobUrlParserService
{
    /**
     * Parses a freelance job URL and extracts the clean job ID.
     * Supports Upwork and Freelancer URLs.
     */
    public function extractCleanJobId(string $url): ?string
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['path'])) {
            return null;
        }

        $path = $parsedUrl['path'];
        $host = strtolower($parsedUrl['host'] ?? '');

        // Upwork Job URL format: https://www.upwork.com/jobs/~01xyz123...
        if (str_contains($host, 'upwork.com')) {
            if (preg_match('/~[a-zA-Z0-9]+/', $path, $matches)) {
                return $matches[0];
            }
        }

        // Freelancer Job URL format: https://www.freelancer.com/projects/php/project-name-12345
        if (str_contains($host, 'freelancer.com')) {
            $parts = explode('/', trim($path, '/'));
            return end($parts); // Return the last segment as the job identifier
        }

        // Fallback for unknown platforms, try to clean the path
        return md5($path);
    }
}
