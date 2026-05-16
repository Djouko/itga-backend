<?php

namespace Tests\Unit;

use App\Models\Admin;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordTest extends TestCase
{
    public function test_admin_password_matches_hashed_password()
    {
        $admin = new Admin();
        $admin->setPassword('Secret123!');

        $this->assertTrue($admin->passwordMatches('Secret123!'));
        $this->assertFalse($admin->passwordMatches('Wrong123!'));
        $this->assertTrue(Hash::check('Secret123!', $admin->user_password));
    }

    public function test_admin_password_matches_and_migrates_legacy_encrypted_password()
    {
        $admin = new Admin();
        $admin->user_password = Crypt::encrypt('Legacy123!');

        $this->assertTrue($admin->passwordMatches('Legacy123!'));
        $this->assertTrue($admin->rehashPasswordIfNeeded('Legacy123!'));
        $this->assertTrue(Hash::check('Legacy123!', $admin->user_password));
    }

    public function test_admin_password_is_hidden_from_array_output()
    {
        $admin = new Admin();
        $admin->user_name = 'admin';
        $admin->setPassword('Secret123!');

        $this->assertArrayNotHasKey('user_password', $admin->toArray());
    }
}
