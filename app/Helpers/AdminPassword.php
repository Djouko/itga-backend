<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class AdminPassword
{
    public static function matches(object $admin, string $password): bool
    {
        $storedPassword = self::storedPassword($admin);

        if ($storedPassword === '') {
            return false;
        }

        if (Hash::check($password, $storedPassword)) {
            return true;
        }

        try {
            return hash_equals((string) Crypt::decrypt($storedPassword), $password);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function set(object $admin, string $password): void
    {
        $admin->user_password = Hash::make($password);
    }

    public static function rehashIfNeeded(object $admin, string $password): bool
    {
        $storedPassword = self::storedPassword($admin);

        if ($storedPassword === '' || !Hash::needsRehash($storedPassword)) {
            return false;
        }

        if (!self::matches($admin, $password)) {
            return false;
        }

        self::set($admin, $password);

        return true;
    }

    private static function storedPassword(object $admin): string
    {
        return (string) ($admin->user_password ?? '');
    }
}
