<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FollowingList extends Model
{
    use HasFactory;
    public $table = "following_lists";

    protected $fillable = [
        'my_user_id',
        'company_id',
        'user_id',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
    
    public function followerUser()
    {
        return $this->hasOne(User::class, 'id', 'my_user_id');
    }

    public function story()
    {
        return $this->hasMany(Story::class, 'user_id', 'user_id');
    }



}
