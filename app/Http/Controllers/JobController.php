<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Application;
use App\Models\Company;
use App\Models\GlobalFunction;
use App\Models\JobOffer;
use App\Models\SavedJobOffer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobController extends Controller
{
    // ─── PUBLIC: Fetch job offers (for utilisatrices) ───

    /**
     * Fetch published job offers with filters, search, and pagination.
     */
    public function fetchJobs(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $userId = (int) $request->user_id;
        $query = JobOffer::with('company')
            ->where('status', 'published');

        // Filter: contract type
        if ($request->has('contract_type') && $request->contract_type) {
            $query->where('contract_type', $request->contract_type);
        }

        // Filter: location type
        if ($request->has('location_type') && $request->location_type) {
            $query->where('location_type', $request->location_type);
        }

        // Filter: domain
        if ($request->has('domain') && $request->domain) {
            $query->where('domain', $request->domain);
        }

        // Filter: experience level
        if ($request->has('experience_level') && $request->experience_level) {
            $query->where('experience_level', $request->experience_level);
        }

        // Search by keyword (title + description + missions + skills)
        if ($request->has('keyword') && $request->keyword) {
            $keyword = InputSanitizer::sanitizeSearch($request->keyword, 200);
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'LIKE', "%{$keyword}%")
                  ->orWhere('description', 'LIKE', "%{$keyword}%")
                  ->orWhere('missions', 'LIKE', "%{$keyword}%")
                  ->orWhere('location_city', 'LIKE', "%{$keyword}%");
            });
        }

        // Sort: always by featured + date; relevance is computed in PHP
        $query->orderBy('is_featured', 'desc')->orderBy('created_at', 'desc');

        $offers = $query->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        $offers = JobOffer::processOffers($offers, $userId);

        // Compute match score for each offer based on user skills
        $user = User::find($userId);
        $userSkills = $user && $user->skills ? (is_array($user->skills) ? $user->skills : json_decode($user->skills, true)) : [];
        $userSkillsLower = array_map('strtolower', $userSkills ?? []);

        foreach ($offers as $offer) {
            $requiredSkills = $offer->required_skills ?? [];
            if (is_string($requiredSkills)) {
                $requiredSkills = json_decode($requiredSkills, true) ?? [];
            }
            $requiredLower = array_map('strtolower', $requiredSkills);

            $matchCount = count(array_intersect($userSkillsLower, $requiredLower));
            $totalRequired = count($requiredLower);
            $offer->match_score = $totalRequired > 0 ? round(($matchCount / $totalRequired) * 100) : 0;
            $offer->is_match = $offer->match_score >= 50 ? 1 : 0;
        }

        // If sort_by=relevance, re-sort by match_score descending in PHP
        $sortBy = $request->sort_by ?? 'date';
        if ($sortBy === 'relevance' && !empty($userSkillsLower)) {
            $sorted = $offers->sortByDesc('match_score')->values();
            $offers = $sorted;
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $offers,
        ]);
    }

    /**
     * Fetch a single job offer detail.
     */
    public function fetchJobDetail(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $offer = JobOffer::with('company')->find($request->job_offer_id);
        if (!$offer || $offer->status !== 'published') {
            return response()->json(['status' => false, 'message' => 'Job offer not found.']);
        }

        // Increment view count
        $offer->increment('views_count');

        // Add user-specific flags
        $userId = (int) $request->user_id;
        $offer->is_saved = SavedJobOffer::where('user_id', $userId)
            ->where('job_offer_id', $offer->id)->exists() ? 1 : 0;
        $offer->is_applied = Application::where('user_id', $userId)
            ->where('job_offer_id', $offer->id)->exists() ? 1 : 0;

        // Match score
        $user = User::find($userId);
        $userSkills = $user && $user->skills ? (is_array($user->skills) ? $user->skills : json_decode($user->skills, true)) : [];
        $requiredSkills = $offer->required_skills ?? [];
        if (is_string($requiredSkills)) {
            $requiredSkills = json_decode($requiredSkills, true) ?? [];
        }
        $userLower = array_map('strtolower', $userSkills ?? []);
        $reqLower = array_map('strtolower', $requiredSkills);
        $matchCount = count(array_intersect($userLower, $reqLower));
        $offer->match_score = count($reqLower) > 0 ? round(($matchCount / count($reqLower)) * 100) : 0;
        $offer->is_match = $offer->match_score >= 50 ? 1 : 0;

        // Application status if already applied
        $application = Application::where('user_id', $userId)
            ->where('job_offer_id', $offer->id)->first();
        $offer->application_status = $application ? $application->status : null;

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $offer,
        ]);
    }

    /**
     * Toggle save/unsave a job offer.
     */
    public function toggleSaveJob(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $existing = SavedJobOffer::where('user_id', $request->user_id)
            ->where('job_offer_id', $request->job_offer_id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'status' => true,
                'message' => 'Job unsaved.',
                'data' => ['is_saved' => 0],
            ]);
        }

        SavedJobOffer::create([
            'user_id' => (int) $request->user_id,
            'job_offer_id' => (int) $request->job_offer_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Job saved.',
            'data' => ['is_saved' => 1],
        ]);
    }

    /**
     * Fetch saved job offers for a user.
     */
    public function fetchSavedJobs(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $savedIds = SavedJobOffer::where('user_id', $request->user_id)
            ->orderBy('created_at', 'desc')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->pluck('job_offer_id');

        $offers = JobOffer::with('company')
            ->whereIn('id', $savedIds)
            ->where('status', 'published')
            ->get()
            ->sortBy(function ($offer) use ($savedIds) {
                return array_search($offer->id, $savedIds->toArray());
            })
            ->values();

        $offerIds = $offers->pluck('id');
        $appliedIds = Application::where('user_id', $request->user_id)
            ->whereIn('job_offer_id', $offerIds)
            ->pluck('job_offer_id')
            ->toArray();

        foreach ($offers as $offer) {
            $offer->is_saved = 1;
            $offer->is_applied = in_array($offer->id, $appliedIds) ? 1 : 0;
        }

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $offers,
        ]);
    }

    // ─── COMPANY: Job management ───

    /**
     * Create a new job offer.
     */
    public function createJob(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'contract_type' => 'required|string|in:stage,alternance,cdi,cdd,freelance',
            'location_type' => 'required|string|in:remote,hybrid,onsite',
            'description' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ($company->is_suspended) {
            return response()->json(['status' => false, 'message' => 'Company account is suspended.']);
        }

        if ($authError = $this->companyOwnerAuthorizationError($company, $request)) {
            return $authError;
        }

        $offer = new JobOffer();
        $offer->company_id = $company->id;
        $offer->title = InputSanitizer::sanitizeText($request->title, 255);
        $offer->contract_type = $request->contract_type;
        $offer->location_type = $request->location_type;
        $offer->location_city = InputSanitizer::sanitizeText($request->location_city, 200);
        $offer->domain = InputSanitizer::sanitizeText($request->domain, 100);
        $offer->description = InputSanitizer::sanitizeText($request->description, 10000);
        $offer->missions = InputSanitizer::sanitizeText($request->missions, 10000);

        if ($request->has('required_skills')) {
            $skills = $request->required_skills;
            if (is_string($skills)) {
                $skills = json_decode($skills, true);
            }
            $offer->required_skills = is_array($skills) ? $skills : [];
        }

        if ($request->has('salary_min')) $offer->salary_min = (float) $request->salary_min;
        if ($request->has('salary_max')) $offer->salary_max = (float) $request->salary_max;
        if ($request->has('salary_period')) $offer->salary_period = $request->salary_period;
        if ($request->has('experience_level')) $offer->experience_level = $request->experience_level;
        if ($request->has('deadline')) $offer->deadline = $request->deadline;

        $offer->status = $request->status === 'draft' ? 'draft' : 'published';
        $offer->save();

        return response()->json([
            'status' => true,
            'message' => 'Job offer created.',
            'data' => $offer->load('company'),
        ]);
    }

    /**
     * Edit an existing job offer.
     */
    public function editJob(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $offer = JobOffer::where('id', $request->job_offer_id)
            ->where('company_id', $request->company_id)
            ->first();

        if (!$offer) {
            return response()->json(['status' => false, 'message' => 'Job offer not found.']);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ($authError = $this->companyOwnerAuthorizationError($company, $request)) {
            return $authError;
        }

        if ($request->has('title')) $offer->title = InputSanitizer::sanitizeText($request->title, 255);
        if ($request->has('contract_type')) $offer->contract_type = $request->contract_type;
        if ($request->has('location_type')) $offer->location_type = $request->location_type;
        if ($request->has('location_city')) $offer->location_city = InputSanitizer::sanitizeText($request->location_city, 200);
        if ($request->has('domain')) $offer->domain = InputSanitizer::sanitizeText($request->domain, 100);
        if ($request->has('description')) $offer->description = InputSanitizer::sanitizeText($request->description, 10000);
        if ($request->has('missions')) $offer->missions = InputSanitizer::sanitizeText($request->missions, 10000);

        if ($request->has('required_skills')) {
            $skills = $request->required_skills;
            if (is_string($skills)) $skills = json_decode($skills, true);
            $offer->required_skills = is_array($skills) ? $skills : [];
        }

        if ($request->has('salary_min')) $offer->salary_min = (float) $request->salary_min;
        if ($request->has('salary_max')) $offer->salary_max = (float) $request->salary_max;
        if ($request->has('salary_period')) $offer->salary_period = $request->salary_period;
        if ($request->has('experience_level')) $offer->experience_level = $request->experience_level;
        if ($request->has('deadline')) $offer->deadline = $request->deadline;
        if ($request->has('status') && in_array($request->status, ['draft', 'published', 'closed'])) {
            $offer->status = $request->status;
        }

        $offer->save();

        return response()->json([
            'status' => true,
            'message' => 'Job offer updated.',
            'data' => $offer->load('company'),
        ]);
    }

    /**
     * Delete a job offer.
     */
    public function deleteJob(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $offer = JobOffer::where('id', $request->job_offer_id)
            ->where('company_id', $request->company_id)
            ->first();

        if (!$offer) {
            return response()->json(['status' => false, 'message' => 'Job offer not found.']);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ($authError = $this->companyOwnerAuthorizationError($company, $request)) {
            return $authError;
        }

        $offer->delete();

        return response()->json([
            'status' => true,
            'message' => 'Job offer deleted.',
        ]);
    }

    /**
     * Fetch company's own job offers.
     */
    public function fetchCompanyJobs(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ($authError = $this->companyOwnerAuthorizationError($company, $request)) {
            return $authError;
        }

        $offers = JobOffer::withCount('applications')
            ->where('company_id', $request->company_id)
            ->orderBy('created_at', 'desc')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $offers,
        ]);
    }
}
