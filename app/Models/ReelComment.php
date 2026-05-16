<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReelComment extends Model
{
    use HasFactory;
    public $table = "reel_comments";

    protected $fillable = [
        'user_id',
        'company_id',
        'reel_id',
        'parent_id',
        'description',
        'is_edited',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function likes()
    {
        return $this->hasMany(LikeReelComment::class);
    }

    public function replies()
    {
        return $this->hasMany(ReelComment::class, 'parent_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(ReelComment::class, 'parent_id');
    }
}
