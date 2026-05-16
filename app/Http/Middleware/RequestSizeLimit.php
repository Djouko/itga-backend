<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RequestSizeLimit
{
    /**
     * Maximum allowed request body size in bytes.
     * Default: 20 MB (sufficient for images/videos, blocks oversized payloads).
     */
    protected int $maxSize = 20 * 1024 * 1024;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, $maxSizeMB = null)
    {
        if ($maxSizeMB !== null) {
            $this->maxSize = (int) $maxSizeMB * 1024 * 1024;
        }

        $contentLength = $request->header('Content-Length');

        if ($contentLength && (int) $contentLength > $this->maxSize) {
            return $this->tooLargeResponse();
        }

        $uploadedFilesSize = $this->uploadedFilesSize($request->allFiles());
        if ($uploadedFilesSize > $this->maxSize) {
            return $this->tooLargeResponse();
        }

        return $next($request);
    }

    /**
     * @param array<string, mixed> $files
     */
    private function uploadedFilesSize(array $files): int
    {
        $total = 0;

        foreach ($files as $file) {
            if (is_array($file)) {
                $total += $this->uploadedFilesSize($file);
                continue;
            }

            if ($file instanceof UploadedFile) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }

    private function tooLargeResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'Request body too large. Maximum allowed size is ' . ($this->maxSize / 1024 / 1024) . ' MB.',
        ], 413);
    }
}
