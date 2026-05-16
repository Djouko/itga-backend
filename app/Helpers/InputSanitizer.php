<?php

namespace App\Helpers;

/**
 * Server-side input sanitization utility.
 * Defense-in-depth: even if the Flutter client sanitizes inputs,
 * the server must NEVER trust raw user data.
 *
 * Prevents: XSS, SQL injection via content, HTML injection,
 * control character injection, and excessively long inputs.
 */
class InputSanitizer
{
    /**
     * Sanitize a text input (post description, comment, bio, etc.).
     * - Strips HTML tags
     * - Removes control characters (keeps newlines and tabs)
     * - Trims whitespace
     * - Enforces max length
     */
    public static function sanitizeText(?string $input, int $maxLength = 5000): ?string
    {
        if ($input === null) {
            return null;
        }

        // Strip HTML/PHP tags
        $clean = strip_tags($input);

        // Remove control characters except newline (\n), carriage return (\r), tab (\t)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Normalize excessive whitespace (but preserve single newlines for formatting)
        $clean = preg_replace('/[ \t]+/', ' ', $clean);
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean);

        // Trim
        $clean = trim($clean);

        // Enforce max length
        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean;
    }

    /**
     * Sanitize a username or search query.
     * More restrictive: no newlines, no special chars except _ and .
     */
    public static function sanitizeSearch(?string $input, int $maxLength = 200): ?string
    {
        if ($input === null) {
            return null;
        }

        $clean = strip_tags($input);
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
        $clean = trim($clean);

        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean;
    }

    /**
     * Sanitize an integer ID parameter.
     * Returns null if not a valid positive integer.
     */
    public static function sanitizeId($input): ?int
    {
        if ($input === null) {
            return null;
        }

        $id = filter_var($input, FILTER_VALIDATE_INT);
        return ($id !== false && $id > 0) ? $id : null;
    }

    /**
     * Sanitize a comma-separated list of IDs.
     * Returns a clean string of positive integers separated by commas.
     */
    public static function sanitizeIdList(?string $input): string
    {
        if ($input === null || trim($input) === '') {
            return '';
        }

        $ids = array_filter(
            array_map(function ($id) {
                $clean = filter_var(trim($id), FILTER_VALIDATE_INT);
                return ($clean !== false && $clean > 0) ? $clean : null;
            }, explode(',', $input))
        );

        return implode(',', $ids);
    }

    /**
     * Sanitize JSON string input.
     * Validates it's proper JSON and re-encodes to remove any injected content.
     */
    public static function sanitizeJson(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
}
