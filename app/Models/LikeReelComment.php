<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LikeReelComment extends Model
{
    use HasFactory;
    protected $table = "like_reel_comments";

    protected $fillable = [
        'user_id',
        'company_id',
        'reel_comment_id',
    ];

    public function reelComment()
    {
        return $this->belongsTo(ReelComment::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}
