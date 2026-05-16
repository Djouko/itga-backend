<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;
    public $table = "posts";

    protected $fillable = [
        'user_id',
        'company_id',
        'desc',
        'tags',
        'link_preview_json',
        'interest_ids',
        'original_post_id',
    ];
    
    public function content()
    {
        return $this->hasMany(PostContent::class, 'post_id', 'id');
    }
    
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function originalPost()
    {
        return $this->belongsTo(Post::class, 'original_post_id', 'id');
    }

    public static function processPosts($posts, $userId, $companyId = null)
    {
        $postIds = $posts->pluck('id');

        $likedPosts = Like::whereIn('post_id', $postIds)
                        ->where('user_id', $userId)
                        ->where('company_id', $companyId)
                        ->pluck('post_id')
                        ->toArray();

        foreach ($posts as $post) {
            $post->is_like = in_array($post->id, $likedPosts) ? 1 : 0;
        }

        return $posts;
    }

}
