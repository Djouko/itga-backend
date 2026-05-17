<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Application;
use App\Models\Company;
use App\Models\GlobalFunction;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApplicationController extends Controller
{
    /**
     * Apply to a job offer.
     */
    public function applyToJob(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
            'cover_letter' => 'nullable|string|max:5000',
            'cv_file' => 'nullable|file|max:5120|mimes:pdf,doc,docx',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::find($request->user_id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found.']);
        }

        $offer = JobOffer::where('id', $request->job_offer_id)
            ->where('status', 'published')
            ->first();
        if (!$offer) {
            return response()->json(['status' => false, 'message' => 'Job offer not found or no longer available.']);
        }

        // Check if user already applied
        $existing = Application::where('user_id', $request->user_id)
            ->where('job_offer_id', $request->job_offer_id)
            ->first();
        if ($existing) {
            return response()->json(['status' => false, 'message' => 'You have already applied to this job.']);
        }

        $application = new Application();
        $application->user_id = (int) $request->user_id;
        $application->job_offer_id = (int) $request->job_offer_id;
        $application->cover_letter = InputSanitizer::sanitizeText($request->cover_letter, 5000);

        if ($request->hasFile('cv_file')) {
            $application->cv_file = GlobalFunction::saveFileAndGivePath($request->file('cv_file'));
        }

        $application->status = 'received';
        $application->save();

        // Increment applications count on the job offer
        $offer->increment('applications_count');

        return response()->json([
            'status' => true,
            'message' => 'Application submitted successfully.',
            'data' => $application->load(['user', 'jobOffer']),
        ]);
    }

    /**
     * Fetch user's applications (mes candidatures).
     */
    public function fetchMyApplications(Request $request)
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

        $applications = Application::with(['jobOffer.company'])
            ->where('user_id', $request->user_id)
            ->orderBy('created_at', 'desc')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $applications,
        ]);
    }

    /**
     * Fetch applications for a specific job offer (company side).
     */
    public function fetchJobApplications(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'job_offer_id' => 'required|integer|exists:job_offers,id',
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

        // Verify the offer belongs to the company
        $offer = JobOffer::where('id', $request->job_offer_id)
            ->where('company_id', $request->company_id)
            ->first();
        if (!$offer) {
            return response()->json(['status' => false, 'message' => 'Job offer not found.']);
        }

        $query = Application::with('user')
            ->where('job_offer_id', $request->job_offer_id);

        $statusFilter = $request->status ?? null;
        if ($statusFilter && in_array($statusFilter, ['received', 'in_review', 'interview', 'accepted', 'rejected'])) {
            $query->where('status', $statusFilter);
        }

        $applications = $query->orderBy('created_at', 'desc')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'offer' => $offer,
                'applications' => $applications,
            ],
        ]);
    }

    /**
     * Update application status (company side).
     */
    public function updateApplicationStatus(Request $request)
    {
        if ($authError = $this->enforceAuthenticatedUserField($request, 'user_id')) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'application_id' => 'required|integer|exists:applications,id',
            'status' => 'required|string|in:received,in_review,interview,accepted,rejected',
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

        $application = Application::with('jobOffer')->find($request->application_id);
        if (!$application || $application->jobOffer->company_id != $request->company_id) {
            return response()->json(['status' => false, 'message' => 'Application not found.']);
        }

        $application->status = $request->status;
        if ($request->has('company_note')) {
            $application->company_note = InputSanitizer::sanitizeText($request->company_note, 2000);
        }
        $application->save();

        return response()->json([
            'status' => true,
            'message' => 'Application status updated.',
            'data' => $application->load(['user', 'jobOffer']),
        ]);
    }

    // ─── ADMIN: Moderation ───

    /**
     * Fetch all job offers for admin moderation.
     */
    public function fetchAllJobsAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $statusFilter = $request->status_filter ?? null;

        $query = JobOffer::with('company')
            ->orderBy('created_at', 'desc');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $offers = $query->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $offers,
        ]);
    }

    /**
     * Admin: change job offer status (approve/reject).
     */
    public function moderateJob(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'job_offer_id' => 'required|integer|exists:job_offers,id',
            'action' => 'required|string|in:approve,reject',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $offer = JobOffer::find($request->job_offer_id);
        if (!$offer) {
            return response()->json(['status' => false, 'message' => 'Job offer not found.']);
        }

        $offer->status = $request->action === 'approve' ? 'published' : 'rejected';
        $offer->save();

        return response()->json([
            'status' => true,
            'message' => 'Job offer ' . ($request->action === 'approve' ? 'approved' : 'rejected') . '.',
            'data' => $offer,
        ]);
    }

    /**
     * Admin: fetch all companies.
     */
    public function fetchCompaniesAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|integer|min:0',
            'limit' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $companies = Company::withCount('jobOffers')
            ->orderBy('created_at', 'desc')
            ->skip((int) $request->start)
            ->take((int) $request->limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $companies,
        ]);
    }

    /**
     * Admin: suspend or unsuspend company.
     */
    public function toggleSuspendCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        $company->is_suspended = $company->is_suspended ? 0 : 1;
        $company->save();

        return response()->json([
            'status' => true,
            'message' => $company->is_suspended ? 'Company suspended.' : 'Company unsuspended.',
            'data' => $company,
        ]);
    }

    /**
     * Admin: grant or remove the public ITGA company certification badge.
     */
    public function toggleVerifyCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::find($request->company_id);
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        $company->is_verified = $company->is_verified ? 0 : 1;
        $company->save();

        return response()->json([
            'status' => true,
            'message' => $company->is_verified ? 'Company certified.' : 'Company certification removed.',
            'data' => $company,
        ]);
    }

    /**
     * Admin: Job board KPIs.
     */
    public function fetchJobKPIs(Request $request)
    {
        $totalCompanies = Company::count();
        $totalOffers = JobOffer::count();
        $publishedOffers = JobOffer::where('status', 'published')->count();
        $totalApplications = Application::count();
        $acceptedApplications = Application::where('status', 'accepted')->count();
        $totalViews = (int) JobOffer::sum('views_count');

        $conversionRate = $totalViews > 0
            ? round(($totalApplications / $totalViews) * 100, 1)
            : 0;

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'total_companies' => $totalCompanies,
                'total_offers' => $totalOffers,
                'published_offers' => $publishedOffers,
                'total_applications' => $totalApplications,
                'accepted_applications' => $acceptedApplications,
                'total_views' => $totalViews,
                'conversion_rate' => $conversionRate,
            ],
        ]);
    }

    public function adminJobs(Request $request)
    {
        $status = $request->input('status');
        $query = JobOffer::with('company')->withCount('applications')->orderByDesc('created_at');

        if (in_array($status, ['draft', 'published', 'closed', 'rejected'])) {
            $query->where('status', $status);
        }

        if ($request->filled('keyword')) {
            $keyword = InputSanitizer::sanitizeSearch($request->keyword, 200);
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('domain', 'LIKE', "%{$keyword}%")
                    ->orWhereHas('company', function ($cq) use ($keyword) {
                        $cq->where('name', 'LIKE', "%{$keyword}%")
                            ->orWhere('email', 'LIKE', "%{$keyword}%");
                    });
            });
        }

        $jobs = $query->paginate(20)->appends($request->query());

        $kpis = [
            'total_companies' => Company::count(),
            'total_offers' => JobOffer::count(),
            'published_offers' => JobOffer::where('status', 'published')->count(),
            'draft_offers' => JobOffer::where('status', 'draft')->count(),
            'rejected_offers' => JobOffer::where('status', 'rejected')->count(),
            'closed_offers' => JobOffer::where('status', 'closed')->count(),
            'total_applications' => Application::count(),
        ];

        return view('adminJobs', compact('jobs', 'kpis', 'status'));
    }

    public function adminModerateJobWeb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'job_offer_id' => 'required|integer|exists:job_offers,id',
            'status' => 'required|string|in:draft,published,closed,rejected',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $offer = JobOffer::find($request->job_offer_id);
        $offer->status = $request->status;
        $offer->save();

        return back()->with('success', 'Statut de l’offre mis à jour.');
    }

    public function adminCompanies(Request $request)
    {
        $query = Company::with('owner')->withCount(['jobOffers', 'publishedOffers'])->orderByDesc('created_at');

        if ($request->filled('keyword')) {
            $keyword = InputSanitizer::sanitizeSearch($request->keyword, 200);
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('email', 'LIKE', "%{$keyword}%")
                    ->orWhere('sector', 'LIKE', "%{$keyword}%")
                    ->orWhereHas('owner', function ($oq) use ($keyword) {
                        $oq->where('full_name', 'LIKE', "%{$keyword}%")
                            ->orWhere('username', 'LIKE', "%{$keyword}%");
                    });
            });
        }

        if ($request->filled('suspended')) {
            $query->where('is_suspended', (int) $request->suspended);
        }

        $companies = $query->paginate(20)->appends($request->query());

        $kpis = [
            'total_companies' => Company::count(),
            'verified_companies' => Company::where('is_verified', 1)->count(),
            'suspended_companies' => Company::where('is_suspended', 1)->count(),
            'total_offers' => JobOffer::count(),
            'total_applications' => Application::count(),
        ];

        return view('adminCompanies', compact('companies', 'kpis'));
    }

    public function adminToggleSuspendCompanyWeb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $company = Company::find($request->company_id);
        $company->is_suspended = $company->is_suspended ? 0 : 1;
        $company->save();

        return back()->with('success', $company->is_suspended ? 'Entreprise suspendue.' : 'Entreprise réactivée.');
    }

    public function adminToggleVerifyCompanyWeb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $company = Company::find($request->company_id);
        $company->is_verified = $company->is_verified ? 0 : 1;
        $company->save();

        return back()->with('success', $company->is_verified ? 'Entreprise certifiee.' : 'Certification entreprise retiree.');
    }
}
