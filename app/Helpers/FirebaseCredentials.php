<?php

namespace App\Helpers;

use Google\Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FirebaseCredentials
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function getPath(): ?string
    {
        $explicitCandidates = [
            env('GOOGLE_APPLICATION_CREDENTIALS'),
            env('GOOGLE_CREDENTIALS_PATH'),
        ];

        $hasExplicitCandidate = false;

        foreach ($explicitCandidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $hasExplicitCandidate = true;
            $path = self::normalizePath($candidate);

            if (File::exists($path)) {
                return $path;
            }
        }

        if ($hasExplicitCandidate) {
            Log::warning('FCM credentials file not found at configured path. Check GOOGLE_APPLICATION_CREDENTIALS / GOOGLE_CREDENTIALS_PATH.');

            return null;
        }

        $fallbackPath = base_path('googleCredentials.json');
        if (File::exists($fallbackPath)) {
            return $fallbackPath;
        }

        Log::warning('FCM credentials file not found. Configure GOOGLE_APPLICATION_CREDENTIALS or GOOGLE_CREDENTIALS_PATH.');

        return null;
    }

    public static function getAccessToken(): ?string
    {
        $credentialsPath = self::getPath();
        if ($credentialsPath === null) {
            return null;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->addScope(self::FCM_SCOPE);
            $client->fetchAccessTokenWithAssertion();

            $token = $client->getAccessToken();
            $accessToken = $token['access_token'] ?? null;

            if (!is_string($accessToken) || trim($accessToken) === '') {
                Log::error('FCM access token missing in Google auth response.');
                return null;
            }

            return $accessToken;
        } catch (\Throwable $e) {
            Log::error('FCM token error: ' . $e->getMessage());
            return null;
        }
    }

    public static function getProjectId(): ?string
    {
        $credentialsPath = self::getPath();
        if ($credentialsPath === null) {
            return null;
        }

        try {
            $contents = File::get($credentialsPath);
            $json = json_decode($contents, true);

            if (!is_array($json)) {
                Log::error('FCM credentials JSON is invalid.');
                return null;
            }

            $projectId = $json['project_id'] ?? null;
            if (!is_string($projectId) || trim($projectId) === '') {
                Log::error('FCM project_id missing in credentials file.');
                return null;
            }

            return $projectId;
        } catch (\Throwable $e) {
            Log::error('FCM credentials error: ' . $e->getMessage());
            return null;
        }
    }

    private static function normalizePath(string $path): string
    {
        $trimmed = trim($path);

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $trimmed) === 1 || str_starts_with($trimmed, DIRECTORY_SEPARATOR)) {
            return $trimmed;
        }

        return base_path($trimmed);
    }
}
