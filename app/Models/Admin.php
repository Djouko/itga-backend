<?php

namespace App\Models;

use App\Helpers\AdminPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    use HasFactory;

    public $table = "admins";

    protected $hidden = ['user_password'];

    public function passwordMatches(string $password): bool
    {
        return AdminPassword::matches($this, $password);
    }

    public function setPassword(string $password): void
    {
        AdminPassword::set($this, $password);
    }

    public function rehashPasswordIfNeeded(string $password): bool
    {
        return AdminPassword::rehashIfNeeded($this, $password);
    }
}
