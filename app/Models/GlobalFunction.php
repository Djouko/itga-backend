<?php

namespace App\Models;

use App\Helpers\FirebaseCredentials;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GlobalFunction extends Model
{
    use HasFactory;

    public static function sendPushNotificationToAllUsers($title, $description)
    {
        $accessToken = self::getFcmAccessToken();
        $projectId = self::getFcmProjectId();

        if (!$accessToken || !$projectId) {
            return json_encode([
                'status' => false,
                'message' => 'FCM credentials not configured',
            ]);
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
        $notificationArray = array('title' => $title, 'body' => $description);

        // Construct message for iOS
        $fields_ios = array(
            'message' => [
                'topic' => env('NOTIFICATION_TOPIC') . '_ios',
                'data' => $notificationArray,
                'notification' => $notificationArray,
                'apns' => [
                    'payload' => [
                        'aps' => ['sound' => 'default']
                    ]
                ]
            ],
        );

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields_ios));
        $result_ios = curl_exec($ch);
        if ($result_ios === false) { Log::error('FCM iOS topic error: ' . curl_error($ch)); }
        curl_close($ch);

        $fields_android = [
            'message' => [
                'topic' => env('NOTIFICATION_TOPIC') . '_android',
                'data' => $notificationArray,
                'notification' => $notificationArray,
                'android' => ['priority' => 'high'],
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields_android));
        $result_android = curl_exec($ch);
        if ($result_android === false) { Log::error('FCM Android topic error: ' . curl_error($ch)); }
        curl_close($ch);

        return json_encode(['status' => true, 'message' => 'Broadcast notification sent']);
    }

    private static function getFcmAccessToken(): ?string
    {
        return FirebaseCredentials::getAccessToken();
    }

    private static function getFcmProjectId(): ?string
    {
        return FirebaseCredentials::getProjectId();
    }

    public static function sendPushNotificationToUser($notificationDesc, $deviceToken, $device_type, $extraData = [])
    {
        if (empty($deviceToken)) {
            return null;
        }

        $accessToken = self::getFcmAccessToken();
        $projectId = self::getFcmProjectId();

        if (!$accessToken || !$projectId) {
            Log::error('FCM: Missing access token or project ID');
            return null;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

        $notificationArray = array_merge(
            ['title' => env('APP_NAME'), 'body' => $notificationDesc],
            array_map('strval', $extraData)
        );

        $fields = [
            'message' => [
                'token' => $deviceToken,
                'data' => $notificationArray,
                'notification' => ['title' => env('APP_NAME'), 'body' => $notificationDesc],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'high_importance_channel',
                        'default_vibrate_timings' => true,
                        'default_light_settings' => true,
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => ['sound' => 'default', 'badge' => 1]
                    ]
                ]
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            Log::error('FCM cURL error: ' . $curlError);
            return null;
        }

        $decoded = json_decode($result, true);
        if (isset($decoded['error'])) {
            Log::warning('FCM send failed: ' . json_encode($decoded['error']));
        }

        return $decoded;
    }

    public static function deleteFile($url)
    {
        if ($url == null) {
            return;
        }

        // Define the base URLs for AWS, DigitalOcean, and Local storage
        $baseURLAWS = rtrim(env('ITEM_BASE_URL'), '/') . '/';
        $baseURLDO = rtrim(env('DO_SPACE_URL'), '/') . '/';
        $baseURLLocal = rtrim(env('APP_URL'), '/') . '/storage/';

        // Remove the base URLs from the given URL to get the file paths
        $fileNameAWS = str_replace($baseURLAWS, '', $url);
        $fileNameDO = str_replace($baseURLDO, '', $fileNameAWS);
        $fileNameLocal = str_replace($baseURLLocal, '', $url);

        // Check and delete the file from local storage
        if (Storage::disk('local')->exists('public/' . $fileNameLocal)) {
            Storage::disk('local')->delete('public/' . $fileNameLocal);
            return;
        }

        try {
            if (Storage::disk('digitalocean')->exists($fileNameDO)) {
                Storage::disk('digitalocean')->delete($fileNameDO);
                return;
            }
        } catch (\Exception $e) {
        }

        try {
            if (Storage::disk('s3')->exists($fileNameAWS)) {
                Storage::disk('s3')->delete($fileNameAWS);
            }
        } catch (\Exception $e) {
        }
    }

    public static function saveFileAndGivePath($file)
    {
        $storageType = Setting::first()->storage_type;

        $storageConfig = [
            1 => ['disk' => 's3', 'base_url' => env('ITEM_BASE_URL')],
            2 => ['disk' => 'digitalocean', 'base_url' => env('DO_SPACE_URL')],
        ];

        $storageDisk = $storageConfig[$storageType]['disk'] ?? 'public';
        $baseUrl = $storageConfig[$storageType]['base_url'] ?? env('APP_URL') . 'storage/';

        $appName = env('APP_NAME') ? env('APP_NAME') . '/' : '';

        // Instagram/Twitter/WhatsApp pattern: convert images to WebP server-side
        $fileContent = file_get_contents($file);
        $originalName = $file->getClientOriginalName();
        $mime = $file->getMimeType();

        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp']);

        if ($isImage && function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $convertedContent = self::convertImageToWebP($fileContent, $mime);
            if ($convertedContent !== null) {
                $fileContent = $convertedContent;
                // Change extension to .webp
                $originalName = pathinfo($originalName, PATHINFO_FILENAME) . '.webp';
            }
        }

        $fileName = time() . '_' . env('APP_NAME') . '_' . str_replace(" ", "_", $originalName);
        $filePath = ($storageDisk === 'public') ? 'uploads/' . $fileName : $appName . 'uploads/' . $fileName;

        Storage::disk($storageDisk)->put($filePath, $fileContent, 'public');

        return $baseUrl . $filePath;
    }

    /**
     * Convert image to WebP format using PHP GD library.
     * Instagram/Twitter/WhatsApp all serve images as WebP — 30-50% smaller.
     *
     * @param string $fileContent Raw image bytes
     * @param string $mime Original MIME type
     * @return string|null WebP bytes, or null if conversion fails
     */
    private static function convertImageToWebP($fileContent, $mime)
    {
        try {
            // Skip if already WebP
            if ($mime === 'image/webp') {
                return null;
            }

            $image = imagecreatefromstring($fileContent);
            if ($image === false) {
                return null;
            }

            // Preserve transparency for PNG/GIF
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            // Resize if larger than 2048px on any side (save storage + bandwidth)
            $width = imagesx($image);
            $height = imagesy($image);
            $maxDimension = 2048;

            if ($width > $maxDimension || $height > $maxDimension) {
                $ratio = min($maxDimension / $width, $maxDimension / $height);
                $newWidth = (int)($width * $ratio);
                $newHeight = (int)($height * $ratio);

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }

            // Convert to WebP with quality 80 (good balance of quality vs size)
            ob_start();
            imagewebp($image, null, 80);
            $webpContent = ob_get_clean();
            imagedestroy($image);

            if ($webpContent === false || strlen($webpContent) === 0) {
                return null;
            }

            $originalSize = strlen($fileContent);
            $webpSize = strlen($webpContent);
            $savings = round((1 - $webpSize / $originalSize) * 100, 1);
            Log::info("WebP conversion: {$originalSize}B → {$webpSize}B (-{$savings}%)");

            return $webpContent;
        } catch (\Exception $e) {
            Log::warning('WebP conversion failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function saveFileInLocal($file, $filename)
    {
        if ($file != null) {
            // Define the full path to the public/assets/img directory
            $filePath = public_path('asset/img/' . $filename);

            // Check if the file already exists and delete it if necessary
            if (file_exists($filePath)) {
                unlink($filePath);  // Remove the existing file
            }

            // Move the uploaded file to the public/assets/img directory with the specified name
            $file->move(public_path('asset/img'), $filename);

            return $filePath; // Return the full path to the file
        } else {
            return null;
        }
    }

    public static function sendDataResponse($status, $msg, $data)
    {
        return response()->json(['status' => $status, 'message' => $msg, 'data' => $data]);
    }

    public static function sendSimpleResponse($status, $msg)
    {
        return response()->json(['status' => $status, 'message' => $msg]);
    }
}
