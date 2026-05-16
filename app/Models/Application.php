<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;
    public $table = "applications";

    protected $fillable = [
        'user_id',
        'job_offer_id',
        'cover_letter',
        'cv_file',
        'status',
        'company_note',
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
