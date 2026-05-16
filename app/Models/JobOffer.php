<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobOffer extends Model
{
    use HasFactory;
    public $table = "job_offers";

    protected $fillable = [
        'company_id',
        'title',
        'contract_type',
        'location_type',
        'location_city',
        'domain',
        'description',
        'missions',
        'required_skills',
        'salary_min',
        'salary_max',
        'salary_period',
        'experience_level',
        'deadline',
        'status',
        'is_featured',
    ];

    protected $casts = [
        'required_skills' => 'array',
        'salary_min' => 'float',
        'salary_max' => 'float',
        'deadline' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'job_offer_id', 'id');
    }

    public function savedBy()
    {
        return $this->hasMany(SavedJobOffer::class, 'job_offer_id', 'id');
    }

    /**
     * Add is_saved and is_applied flags for a given user.
     */
    public static function processOffers($offers, $userId)
    {
        $offerIds = $offers->pluck('id');

        $savedIds = SavedJobOffer::where('user_id', $userId)
            ->whereIn('job_offer_id', $offerIds)
            ->pluck('job_offer_id')
            ->toArray();

        $appliedIds = Application::where('user_id', $userId)
            ->whereIn('job_offer_id', $offerIds)
            ->pluck('job_offer_id')
            ->toArray();

        foreach ($offers as $offer) {
            $offer->is_saved = in_array($offer->id, $savedIds) ? 1 : 0;
            $offer->is_applied = in_array($offer->id, $appliedIds) ? 1 : 0;
        }

        return $offers;
    }
}
