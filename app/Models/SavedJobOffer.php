<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedJobOffer extends Model
{
    use HasFactory;
    public $table = "saved_job_offers";
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'job_offer_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function jobOffer()
    {
        return $this->belongsTo(JobOffer::class, 'job_offer_id', 'id');
    }
}
