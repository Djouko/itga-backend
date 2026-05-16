<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;
    public $table = "companies";

    protected $fillable = [
        'owner_user_id',
        'name',
        'email',
        'password',
        'logo',
        'description',
        'sector',
        'rse_commitments',
        'website',
        'phone',
        'city',
        'country',
        'company_size',
        'is_verified',
        'email_verified_at',
        'is_suspended',
        'email_verification_code',
        'email_verification_expires_at',
        'device_token',
    ];

    protected $hidden = [
        'password',
        'email_verification_code',
    ];

    protected $casts = [
        'is_verified' => 'integer',
        'is_suspended' => 'integer',
        'email_verified_at' => 'datetime',
        'email_verification_expires_at' => 'datetime',
    ];

    public function jobOffers()
    {
        return $this->hasMany(JobOffer::class, 'company_id', 'id');
    }

    public function publishedOffers()
    {
        return $this->hasMany(JobOffer::class, 'company_id', 'id')->where('status', 'published');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id', 'id');
    }

    public function companyPosts()
    {
        return $this->hasMany(CompanyPost::class, 'company_id', 'id');
    }

    public function stories()
    {
        return $this->hasMany(Story::class, 'company_id', 'id');
    }
}
