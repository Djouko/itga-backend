<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reel extends Model
{
    use HasFactory;
    public $table = "reels";

    protected $fillable = [
        'user_id',
        'company_id',
        'description',
        'interest_ids',
        'hashtags',
        'content',
        'thumbnail',
        'music_id',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function music()
    {
        return $this->hasOne(Music::class, 'id', 'music_id');
    }

    public static function processReels($reels, $userId, $companyId = null)
    {
        $reelIds = $reels->pluck('id');
        $userIds = $reels->pluck('user_id');

        $likeData = Like::whereIn('reel_id', $reelIds)
                        ->where('user_id', $userId)
                        ->where('company_id', $companyId)
                        ->pluck('reel_id')
                        ->toArray();
    
        $commentCounts = ReelComment::whereIn('reel_id', $reelIds)
                                    ->selectRaw('reel_id, COUNT(*) as count')
                                    ->groupBy('reel_id')
                                    ->pluck('count', 'reel_id');
    
        $likeCounts = Like::whereIn('reel_id', $reelIds)
                          ->selectRaw('reel_id, COUNT(*) as count')
                          ->groupBy('reel_id')
                          ->pluck('count', 'reel_id');
    
        $followingData = FollowingList::where('my_user_id', $userId)
                                      ->whereIn('user_id', $userIds)
                                      ->when($companyId, function ($query) use ($companyId) {
                                          $query->where('company_id', $companyId);
                                      }, function ($query) {
                                          $query->whereNull('company_id');
                                      })
                                      ->pluck('user_id')
                                      ->toArray();

        $musicIds = $reels->pluck('music_id')->filter()->unique()->toArray();
        $musics = !empty($musicIds) ? Music::whereIn('id', $musicIds)->get()->keyBy('id') : collect();

        $reels->loadMissing(['user', 'company']);

        foreach ($reels as $reel) {
            $reel->is_like = in_array($reel->id, $likeData) ? 1 : 0;
            if ($reel->user) {
                $reel->user->followingStatus = in_array($reel->user->id, $followingData) ? 2 : 0;
            }
            $reel->comments_count = $commentCounts[$reel->id] ?? 0;
            $reel->likes_count = $likeCounts[$reel->id] ?? 0;
            $reel->music = $musics[$reel->music_id] ?? null;
        }
    
        return $reels;
    }
    


}
