<?php

namespace App\Http\Controllers;

use App\Helpers\InputSanitizer;
use App\Models\Company;
use App\Models\CompanyPost;
use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\Interest;
use App\Models\JobOffer;
use App\Models\Application;
use App\Models\Post;
use App\Models\PostContent;
use App\Models\SavedNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    private function resolveFollowerCompanyActor(Request $request, ?User $user)
    {
        if (!$request->filled('follower_company_id')) {
            return null;
        }

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ]);
        }

        $company = Company::find((int) $request->follower_company_id);
        if (!$company || (int) $company->is_suspended === 1) {
            return response()->json([
                'status' => false,
                'message' => 'Company is not allowed to interact.',
            ]);
        }

        if ((int) $company->owner_user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Only the linked company owner can use company mode.',
            ]);
        }

        return $company;
    }

    /**
     * Register a new company account.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => [
                'required',
                'string',
                'min:8',
                'max:100',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).+$/',
            ],
            'sector' => 'nullable|string|max:100',
            'user_id' => 'nullable|integer|exists:users,id',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|integer',
        ], [
            'password.regex' => 'Password must include an uppercase letter, a lowercase letter, a number, and a special character.',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $normalizedEmail = strtolower(trim($request->email));

        $existing = Company::where('email', $normalizedEmail)->first();
        if ($existing) {
            return response()->json(['status' => false, 'message' => 'A company with this email already exists.']);
        }

        $company = new Company();
        if ($request->filled('user_id')) {
            $company->owner_user_id = (int) $request->user_id;
        }
        $company->name = InputSanitizer::sanitizeText($request->name, 255);
        $company->email = $normalizedEmail;
        $company->password = Hash::make($request->password);
        $company->sector = InputSanitizer::sanitizeText($request->sector, 100);
        if ($request->filled('device_token')) {
            $company->device_token = InputSanitizer::sanitizeSearch($request->device_token, 500);
        }
        $company->save();

        $this->issueEmailVerification($company);
        $mailSent = $this->sendEmailVerificationCode($company);

        $company = Company::find($company->id);

        return response()->json([
            'status' => true,
            'message' => $mailSent
                ? 'Company registered successfully. Please verify your email with the code sent to your inbox.'
                : 'Company registered successfully. We could not send verification email right now. Please use resend verification.',
            'verification_required' => true,
            'data' => $company,
        ]);
    }

    /**
     * Login for company account.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'user_id' => 'nullable|integer|exists:users,id',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::where('email', strtolower(trim($request->email)))->first();

        if (!$company || !Hash::check($request->password, $company->password)) {
            return response()->json(['status' => false, 'message' => 'Invalid email or password.']);
        }

        if ((int) $company->is_verified !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Please verify your email before login.',
                'error_code' => 'email_not_verified',
                'data' => [
                    'email' => $company->email,
                ],
            ]);
        }

        if ($company->is_suspended) {
            return response()->json(['status' => false, 'message' => 'This company account has been suspended.']);
        }

        $this->applyCompanyDeviceFromRequest($company, $request);
        $ownerResult = $this->ensureCompanyOwnerUser($company, $request);
        if (isset($ownerResult['error'])) {
            return $ownerResult['error'];
        }
        $company = $ownerResult['company'];

        return response()->json([
            'status' => true,
            'message' => $this->companyOwnerMessage('Login successful.', $ownerResult),
            'auth_token' => isset($ownerResult['user']) ? $this->issueUserApiToken($ownerResult['user'], $request) : null,
            'data' => $company,
            'owner_user' => $ownerResult['user'] ?? null,
            'owner_user_auto_created' => $ownerResult['auto_created'] ?? false,
            'owner_user_auto_linked' => $ownerResult['auto_linked'] ?? false,
        ]);
    }

    /**
     * Verify company email using a one-time code.
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|min:6|max:6',
            'user_id' => 'nullable|integer|exists:users,id',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $email = strtolower(trim($request->email));
        $code = trim($request->code);

        $company = Company::where('email', $email)->first();

        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ((int) $company->is_verified === 1) {
            $this->applyCompanyDeviceFromRequest($company, $request);
            $ownerResult = $this->ensureCompanyOwnerUser($company, $request);
            if (isset($ownerResult['error'])) {
                return $ownerResult['error'];
            }

            return response()->json([
                'status' => true,
                'message' => $this->companyOwnerMessage('Email already verified.', $ownerResult),
                'auth_token' => isset($ownerResult['user']) ? $this->issueUserApiToken($ownerResult['user'], $request) : null,
                'data' => $ownerResult['company'],
                'owner_user' => $ownerResult['user'] ?? null,
                'owner_user_auto_created' => $ownerResult['auto_created'] ?? false,
                'owner_user_auto_linked' => $ownerResult['auto_linked'] ?? false,
            ]);
        }

        if (!$company->email_verification_code || !$company->email_verification_expires_at) {
            return response()->json(['status' => false, 'message' => 'Verification code missing. Please request a new code.']);
        }

        if ($company->email_verification_code !== $code) {
            return response()->json(['status' => false, 'message' => 'Invalid verification code.']);
        }

        if (now()->greaterThan($company->email_verification_expires_at)) {
            return response()->json(['status' => false, 'message' => 'Verification code expired. Please request a new one.']);
        }

        $company->is_verified = 1;
        $company->email_verified_at = now();
        $company->email_verification_code = null;
        $company->email_verification_expires_at = null;
        $company->save();

        $this->applyCompanyDeviceFromRequest($company, $request);
        $ownerResult = $this->ensureCompanyOwnerUser($company, $request);
        if (isset($ownerResult['error'])) {
            return $ownerResult['error'];
        }
        $company = $ownerResult['company'];

        return response()->json([
            'status' => true,
            'message' => $this->companyOwnerMessage('Email verified successfully.', $ownerResult),
            'auth_token' => isset($ownerResult['user']) ? $this->issueUserApiToken($ownerResult['user'], $request) : null,
            'data' => $company,
            'owner_user' => $ownerResult['user'] ?? null,
            'owner_user_auto_created' => $ownerResult['auto_created'] ?? false,
            'owner_user_auto_linked' => $ownerResult['auto_linked'] ?? false,
        ]);
    }

    private function ensureCompanyOwnerUser(Company $company, Request $request): array
    {
        if ($request->filled('user_id')) {
            $ownerId = (int) $request->user_id;
            if ($company->owner_user_id && (int) $company->owner_user_id !== $ownerId) {
                return [
                    'error' => response()->json([
                        'status' => false,
                        'message' => 'This company account is linked to another ITGA user.',
                    ]),
                ];
            }

            $user = User::find($ownerId);
            if (!$user) {
                return [
                    'error' => response()->json([
                        'status' => false,
                        'message' => 'Linked ITGA user not found.',
                    ]),
                ];
            }

            $linked = false;
            if (!$company->owner_user_id) {
                $company->owner_user_id = $ownerId;
                $company->save();
                $linked = true;
            }

            $this->completeCompanyOwnerUser($user, $company, $request);

            return [
                'company' => Company::find($company->id),
                'user' => User::find($user->id),
                'auto_created' => false,
                'auto_linked' => $linked,
            ];
        }

        if ($company->owner_user_id) {
            return [
                'company' => $company,
                'user' => User::find($company->owner_user_id),
                'auto_created' => false,
                'auto_linked' => false,
            ];
        }

        return DB::transaction(function () use ($company) {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->first();
            if (!$lockedCompany) {
                return [
                    'error' => response()->json([
                        'status' => false,
                        'message' => 'Company not found.',
                    ]),
                ];
            }

            if ($lockedCompany->owner_user_id) {
                return [
                    'company' => $lockedCompany,
                    'user' => User::find($lockedCompany->owner_user_id),
                    'auto_created' => false,
                    'auto_linked' => false,
                ];
            }

            $normalizedEmail = strtolower(trim((string) $lockedCompany->email));
            $user = User::where('identity', $normalizedEmail)->first();
            $created = false;

            if (!$user) {
                $user = new User();
                $user->identity = $normalizedEmail;
                $user->login_type = 2;
                $created = true;
            }

            $this->completeCompanyOwnerUser($user, $lockedCompany, $request);

            $lockedCompany->owner_user_id = $user->id;
            $lockedCompany->save();

            return [
                'company' => Company::find($lockedCompany->id),
                'user' => User::find($user->id),
                'auto_created' => $created,
                'auto_linked' => !$created,
            ];
        });
    }

    private function applyCompanyDeviceFromRequest(Company $company, Request $request): void
    {
        if (!$request->filled('device_token')) {
            return;
        }

        $company->device_token = InputSanitizer::sanitizeSearch($request->device_token, 500);
        $company->save();
    }

    private function completeCompanyOwnerUser(User $user, Company $company, ?Request $request = null): void
    {
        $name = InputSanitizer::sanitizeText($company->name ?: 'ITGA Company', 100) ?: 'ITGA Company';
        $email = strtolower(trim((string) $company->email));

        if (!$user->full_name) {
            $user->full_name = $name;
        }
        if (!$user->username) {
            $user->username = $this->uniqueCompanyUsername($company);
        }
        if (!$user->bio) {
            $user->bio = InputSanitizer::sanitizeText(
                $company->description ?: "Profil entreprise ITGA associe a {$name}.",
                255
            );
        }
        if (!$user->profile && $company->logo) {
            $user->profile = $company->logo;
        }
        if (Schema::hasColumn('users', 'email') && !$user->email) {
            $user->email = $email;
        }
        if (Schema::hasColumn('users', 'interest_ids') && !$user->interest_ids) {
            $user->interest_ids = $this->defaultCompanyInterestIds();
        }
        if (Schema::hasColumn('users', 'interests') && !$user->interests) {
            $user->interests = $this->defaultCompanyInterestIds();
        }
        if (Schema::hasColumn('users', 'headline') && !$user->headline) {
            $user->headline = InputSanitizer::sanitizeText($company->sector ?: 'Entreprise partenaire ITGA', 255);
        }
        if (Schema::hasColumn('users', 'about') && !$user->about) {
            $user->about = InputSanitizer::sanitizeText($company->description ?: $company->rse_commitments, 5000);
        }
        if (Schema::hasColumn('users', 'location') && !$user->location) {
            $location = trim(implode(', ', array_filter([$company->city, $company->country])));
            if ($location !== '') {
                $user->location = InputSanitizer::sanitizeText($location, 255);
            }
        }
        if (Schema::hasColumn('users', 'website') && !$user->website && $company->website) {
            $user->website = InputSanitizer::sanitizeText($company->website, 500);
        }
        if (Schema::hasColumn('users', 'following') && $user->following === null) {
            $user->following = 0;
        }
        if (Schema::hasColumn('users', 'followers') && $user->followers === null) {
            $user->followers = 0;
        }
        if (Schema::hasColumn('users', 'is_push_notifications') && $user->is_push_notifications === null) {
            $user->is_push_notifications = 1;
        }
        if (Schema::hasColumn('users', 'is_invited_to_room') && $user->is_invited_to_room === null) {
            $user->is_invited_to_room = 1;
        }
        if (Schema::hasColumn('users', 'is_verified') && $user->is_verified === null) {
            $user->is_verified = 0;
        }
        if (Schema::hasColumn('users', 'is_block') && $user->is_block === null) {
            $user->is_block = 0;
        }
        if (Schema::hasColumn('users', 'is_moderator') && $user->is_moderator === null) {
            $user->is_moderator = 0;
        }

        if ($request && $request->filled('device_type')) {
            $user->device_type = (int) $request->device_type;
        } else {
            $user->device_type = $user->device_type ?? 2;
        }

        $requestDeviceToken = $request && $request->filled('device_token')
            ? InputSanitizer::sanitizeSearch($request->device_token, 500)
            : null;
        $companyDeviceToken = $requestDeviceToken ?: $company->device_token;

        if (Schema::hasColumn('users', 'device_token') && ($requestDeviceToken || !$user->device_token)) {
            $user->device_token = $companyDeviceToken ?: "company-owner-{$company->id}";
        }
        if (Schema::hasColumn('users', 'onesignal_player_id') && ($requestDeviceToken || !$user->onesignal_player_id)) {
            $user->onesignal_player_id = $companyDeviceToken ?: "company-owner-{$company->id}";
        }

        $user->save();
    }

    private function uniqueCompanyUsername(Company $company): string
    {
        $base = strtolower((string) $company->name);
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base);
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = "company-{$company->id}";
        }
        $base = substr($base, 0, 38);

        $username = $base;
        $attempt = 0;
        while (User::where('username', $username)->exists()) {
            $attempt++;
            $suffix = "-{$company->id}" . ($attempt > 1 ? "-{$attempt}" : '');
            $username = substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
        }

        return $username;
    }

    private function defaultCompanyInterestIds(): string
    {
        if (Schema::hasTable('interests')) {
            $interestId = Interest::query()->orderBy('id')->value('id');
            if ($interestId) {
                return (string) $interestId;
            }
        }

        return '1';
    }

    private function companyOwnerMessage(string $base, array $ownerResult): string
    {
        if (!empty($ownerResult['auto_created'])) {
            return $base . ' Un profil ITGA associe a ete cree automatiquement; vous pouvez maintenant interagir sur le feed comme une entreprise.';
        }

        if (!empty($ownerResult['auto_linked'])) {
            return $base . ' Votre entreprise est maintenant associee a un profil ITGA; vous pouvez interagir sur le feed.';
        }

        return $base;
    }

    /**
     * Resend company email verification code.
     */
    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $email = strtolower(trim($request->email));
        $company = Company::where('email', $email)->first();

        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ((int) $company->is_verified === 1) {
            return response()->json(['status' => true, 'message' => 'Email already verified.', 'data' => $company]);
        }

        $this->issueEmailVerification($company);
        $mailSent = $this->sendEmailVerificationCode($company);

        if (!$mailSent) {
            return response()->json(['status' => false, 'message' => 'Unable to send verification email right now. Please try again later.']);
        }

        return response()->json([
            'status' => true,
            'message' => 'Verification code sent successfully.',
        ]);
    }

    private function issueEmailVerification(Company $company): void
    {
        $company->email_verification_code = (string) random_int(100000, 999999);
        $company->email_verification_expires_at = now()->addMinutes(30);
        $company->save();
    }

    private function sendEmailVerificationCode(Company $company): bool
    {
        try {
            $code = $company->email_verification_code;

            Mail::raw(
                "Hello {$company->name},\n\nYour ITGA company verification code is: {$code}\n\nThis code expires in 30 minutes.\n\nIf you did not request this, please ignore this email.",
                function ($message) use ($company) {
                    $message
                        ->to($company->email)
                        ->subject('ITGA Company - Verify your email');
                }
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('Company verification email send failed', [
                'company_id' => $company->id,
                'email' => $company->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Edit company profile.
     */
    public function editProfile(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'logo' => 'nullable|file|max:4096|mimes:jpeg,jpg,png,webp',
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

        if ($request->has('name')) {
            $company->name = InputSanitizer::sanitizeText($request->name, 255);
        }
        if ($request->has('description')) {
            $company->description = InputSanitizer::sanitizeText($request->description, 5000);
        }
        if ($request->has('sector')) {
            $company->sector = InputSanitizer::sanitizeText($request->sector, 100);
        }
        if ($request->has('rse_commitments')) {
            $company->rse_commitments = InputSanitizer::sanitizeText($request->rse_commitments, 5000);
        }
        if ($request->has('website')) {
            $company->website = InputSanitizer::sanitizeText($request->website, 500);
        }
        if ($request->has('phone')) {
            $company->phone = InputSanitizer::sanitizeText($request->phone, 30);
        }
        if ($request->has('city')) {
            $company->city = InputSanitizer::sanitizeText($request->city, 100);
        }
        if ($request->has('country')) {
            $company->country = InputSanitizer::sanitizeText($request->country, 100);
        }
        if ($request->has('company_size')) {
            $company->company_size = (int) $request->company_size;
        }
        if ($request->has('device_token')) {
            $company->device_token = InputSanitizer::sanitizeSearch($request->device_token, 500);
        }
        if ($request->hasFile('logo')) {
            if ($company->logo) {
                GlobalFunction::deleteFile($company->logo);
            }
            $company->logo = GlobalFunction::saveFileAndGivePath($request->file('logo'));
        }

        $company->save();
        $this->syncOwnerUserFromCompany($company);
        $company = Company::find($company->id);

        return response()->json([
            'status' => true,
            'message' => 'Profile updated.',
            'data' => $company,
        ]);
    }

    private function syncOwnerUserFromCompany(Company $company): void
    {
        if (!$company->owner_user_id) {
            return;
        }

        $user = User::find($company->owner_user_id);
        if (!$user) {
            return;
        }

        if ($company->name) {
            $user->full_name = InputSanitizer::sanitizeText($company->name, 100);
        }
        if ($company->description) {
            $user->bio = InputSanitizer::sanitizeText($company->description, 500);
            if (Schema::hasColumn('users', 'about')) {
                $user->about = InputSanitizer::sanitizeText($company->description, 2000);
            }
        }
        if ($company->sector && Schema::hasColumn('users', 'headline')) {
            $user->headline = InputSanitizer::sanitizeText($company->sector, 200);
        }
        if ($company->logo) {
            $user->profile = $company->logo;
        }
        if ($company->website && Schema::hasColumn('users', 'website')) {
            $user->website = InputSanitizer::sanitizeSearch($company->website, 500);
        }
        if (Schema::hasColumn('users', 'location')) {
            $location = trim(implode(', ', array_filter([$company->city, $company->country])));
            if ($location !== '') {
                $user->location = InputSanitizer::sanitizeText($location, 200);
            }
        }
        if ($company->device_token && Schema::hasColumn('users', 'device_token')) {
            $user->device_token = InputSanitizer::sanitizeSearch($company->device_token, 500);
        }

        $user->save();
    }

    /**
     * Fetch company public profile.
     */
    public function fetchProfile(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
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

        $company->published_offers_count = $company->publishedOffers()->count();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $company,
        ]);
    }

    /**
     * Company dashboard: stats + recent offers.
     */
    public function fetchDashboard(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'nullable|integer|exists:users,id',
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

        $totalOffers = JobOffer::where('company_id', $company->id)->count();
        $publishedOffers = JobOffer::where('company_id', $company->id)->where('status', 'published')->count();
        $draftOffers = JobOffer::where('company_id', $company->id)->where('status', 'draft')->count();
        $totalApplications = Application::whereIn('job_offer_id',
            JobOffer::where('company_id', $company->id)->pluck('id')
        )->count();
        $totalViews = JobOffer::where('company_id', $company->id)->sum('views_count');

        $recentOffers = JobOffer::where('company_id', $company->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'company' => $company,
                'stats' => [
                    'total_offers' => $totalOffers,
                    'published_offers' => $publishedOffers,
                    'draft_offers' => $draftOffers,
                    'total_applications' => $totalApplications,
                    'total_views' => (int) $totalViews,
                ],
                'recent_offers' => $recentOffers,
            ],
        ]);
    }

    /**
     * Public company profile — viewable by any authenticated ITGA user.
     * Returns company info + published jobs + followers count + is_following flag.
     */
    public function publicProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer',
            'user_id'    => 'nullable|integer',
            'follower_company_id' => 'nullable|integer',
            'start'      => 'nullable|integer|min:0',
            'limit'      => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::where('id', $request->company_id)
            ->where('is_verified', 1)
            ->where('is_suspended', 0)
            ->first();

        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        $viewer = $request->user_id ? User::where('id', $request->user_id)->first() : null;
        $followerCompany = $this->resolveFollowerCompanyActor($request, $viewer);
        if ($followerCompany instanceof \Illuminate\Http\JsonResponse) {
            return $followerCompany;
        }

        $start = (int) ($request->start ?? 0);
        $limit = (int) ($request->limit ?? 10);

        $jobs = JobOffer::where('company_id', $company->id)
            ->where('status', 'published')
            ->orderBy('created_at', 'desc')
            ->skip($start)
            ->take($limit)
            ->get();

        $recentPosts = Post::with(['user', 'content', 'company'])
            ->where('company_id', $company->id)
            ->where('is_restricted', 0)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($request->user_id) {
            $recentPosts = Post::processPosts(
                $recentPosts,
                (int) $request->user_id,
                $followerCompany ? (int) $followerCompany->id : null
            );
        }

        $followersCount = DB::table('company_followers')->where('company_id', $company->id)->count();
        $isFollowing = false;

        if ($viewer) {
            if ($followerCompany) {
                $isFollowing = (int) $followerCompany->id !== (int) $company->id
                    && DB::table('company_followers')
                        ->where('user_id', (int) $request->user_id)
                        ->where('follower_company_id', $followerCompany->id)
                        ->where('company_id', $company->id)
                        ->exists();
            } else {
                $isFollowing = DB::table('company_followers')
                    ->where('user_id', (int) $request->user_id)
                    ->where('company_id', $company->id)
                    ->whereNull('follower_company_id')
                    ->exists();
            }
        }

        $company->followers_count = $followersCount;
        $company->is_following = $isFollowing ? 1 : 0;
        $company->published_offers_count = JobOffer::where('company_id', $company->id)->where('status', 'published')->count();

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => [
                'company' => $company,
                'jobs'    => $jobs,
                'recent_posts' => $recentPosts,
            ],
        ]);
    }

    /**
     * Follow a company (ITGA user → company).
     */
    public function followCompany(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|integer',
            'company_id' => 'required|integer',
            'follower_company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found.']);
        }

        $followerCompany = $this->resolveFollowerCompanyActor($request, $user);
        if ($followerCompany instanceof \Illuminate\Http\JsonResponse) {
            return $followerCompany;
        }

        $company = Company::where('id', $request->company_id)->where('is_verified', 1)->where('is_suspended', 0)->first();
        if (!$company) {
            return response()->json(['status' => false, 'message' => 'Company not found.']);
        }

        if ($followerCompany && (int) $followerCompany->id === (int) $company->id) {
            return response()->json(['status' => false, 'message' => 'A company cannot follow itself.']);
        }

        $query = DB::table('company_followers')
            ->where('user_id', $request->user_id)
            ->where('company_id', $request->company_id);
        $followerCompany
            ? $query->where('follower_company_id', $followerCompany->id)
            : $query->whereNull('follower_company_id');
        $exists = $query->exists();

        if (!$exists) {
            DB::table('company_followers')->insert([
                'user_id'    => $request->user_id,
                'follower_company_id' => $followerCompany ? (int) $followerCompany->id : null,
                'company_id' => $request->company_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($company->owner_user_id && (int) $company->owner_user_id !== (int) $request->user_id) {
                $owner = User::find($company->owner_user_id);
                if ($owner) {
                    $actorName = $followerCompany ? $followerCompany->name : $user->full_name;
                    if ($owner->is_push_notifications == 1) {
                        GlobalFunction::sendPushNotificationToUser(
                            $actorName . ' has started following ' . $company->name . '.',
                            $owner->device_token,
                            $owner->device_type,
                            ['type' => Constants::notificationTypeFollow]
                        );
                    }

                    $savedNotification = new SavedNotification();
                    $savedNotification->my_user_id = (int) $owner->id;
                    $savedNotification->user_id = (int) $request->user_id;
                    $savedNotification->item_id = (int) $company->id;
                    if ($followerCompany) {
                        $savedNotification->company_id = (int) $followerCompany->id;
                    }
                    $savedNotification->type = Constants::notificationTypeFollow;
                    $savedNotification->save();
                }
            }
        }

        $followersCount = DB::table('company_followers')->where('company_id', $request->company_id)->count();

        return response()->json([
            'status'          => true,
            'message'         => 'Following.',
            'followers_count' => $followersCount,
            'is_following'    => 1,
        ]);
    }

    /**
     * Unfollow a company.
     */
    public function unfollowCompany(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|integer',
            'company_id' => 'required|integer',
            'follower_company_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found.']);
        }

        $followerCompany = $this->resolveFollowerCompanyActor($request, $user);
        if ($followerCompany instanceof \Illuminate\Http\JsonResponse) {
            return $followerCompany;
        }

        $query = DB::table('company_followers')
            ->where('user_id', $request->user_id)
            ->where('company_id', $request->company_id);
        $followerCompany
            ? $query->where('follower_company_id', $followerCompany->id)
            : $query->whereNull('follower_company_id');
        if ($followerCompany) {
            $notificationQuery = SavedNotification::where('user_id', $request->user_id)
                ->where('company_id', $followerCompany->id)
                ->where('type', Constants::notificationTypeFollow);
        } else {
            $notificationQuery = SavedNotification::where('user_id', $request->user_id)
                ->whereNull('company_id')
                ->where('type', Constants::notificationTypeFollow);
        }
        $targetOwnerId = Company::where('id', $request->company_id)->value('owner_user_id');
        if ($targetOwnerId) {
            $notificationQuery
                ->where('my_user_id', $targetOwnerId)
                ->where('item_id', $request->company_id)
                ->delete();
        }

        $query->delete();

        $followersCount = DB::table('company_followers')->where('company_id', $request->company_id)->count();

        return response()->json([
            'status'          => true,
            'message'         => 'Unfollowed.',
            'followers_count' => $followersCount,
            'is_following'    => 0,
        ]);
    }

    /**
     * Fetch all companies followed by a user.
     */
    public function fetchFollowedCompanies(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'follower_company_id' => 'nullable|integer',
            'start'   => 'nullable|integer|min:0',
            'limit'   => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $start = (int) ($request->start ?? 0);
        $limit = (int) ($request->limit ?? 20);

        $user = User::where('id', $request->user_id)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found.']);
        }

        $followerCompany = $this->resolveFollowerCompanyActor($request, $user);
        if ($followerCompany instanceof \Illuminate\Http\JsonResponse) {
            return $followerCompany;
        }

        $companyIdsQuery = DB::table('company_followers')
            ->where('user_id', $request->user_id);
        $followerCompany
            ? $companyIdsQuery->where('follower_company_id', $followerCompany->id)
            : $companyIdsQuery->whereNull('follower_company_id');
        $companyIds = $companyIdsQuery->pluck('company_id');

        $companies = Company::whereIn('id', $companyIds)
            ->where('is_verified', 1)
            ->where('is_suspended', 0)
            ->orderBy('name')
            ->skip($start)
            ->take($limit)
            ->get()
            ->map(function ($company) {
                $company->published_offers_count = JobOffer::where('company_id', $company->id)
                    ->where('status', 'published')->count();
                $company->is_following = 1;
                return $company;
            });

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => $companies,
        ]);
    }

    /**
     * Publish a social post as a company (LinkedIn-like company activity feed).
     * Uses a linked ITGA user as actor for moderation compatibility.
     */
    public function createPost(Request $request)
    {
        if ($authErr = $this->enforceAuthenticatedUserField($request)) {
            return $authErr;
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id',
            'user_id' => 'required|integer|exists:users,id',
            'desc' => 'nullable|string|max:5000',
            'tags' => 'nullable|string|max:1000',
            'link_preview_json' => 'nullable|string',
            'interest_ids' => 'nullable|string',
            'content' => 'nullable|array',
            'content.*' => 'file|max:20480',
            'content_type' => 'required_with:content|string|in:0,1,2',
            'thumbnail' => 'nullable|array',
            'thumbnail.*' => 'file|mimes:jpeg,png,jpg,webp',
            'audio_waves' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $company = Company::find($request->company_id);
        if (!$company || (int) $company->is_suspended === 1) {
            return response()->json(['status' => false, 'message' => 'Company is not allowed to publish.']);
        }

        $actor = User::where('id', $request->user_id)->where('is_block', 0)->first();
        if (!$actor) {
            return response()->json(['status' => false, 'message' => 'Linked ITGA user not found.']);
        }

        if (!$company->owner_user_id || (int) $company->owner_user_id !== (int) $actor->id) {
            return response()->json(['status' => false, 'message' => 'Only the linked company owner can publish as company.']);
        }

        $post = new Post();
        $post->user_id = $actor->id;
        $post->company_id = $company->id;
        $post->desc = InputSanitizer::sanitizeText($request->desc);
        $post->tags = InputSanitizer::sanitizeText($request->tags, 1000);
        $post->link_preview_json = InputSanitizer::sanitizeJson($request->link_preview_json);
        $post->interest_ids = InputSanitizer::sanitizeIdList($request->interest_ids);
        $post->save();

        if ($request->hasFile('content')) {
            foreach ($request->file('content') as $index => $file) {
                $postContent = new PostContent();
                $postContent->post_id = $post->id;
                $postContent->content = GlobalFunction::saveFileAndGivePath($file);
                $postContent->content_type = $request->content_type;

                if ($request->hasFile("thumbnail.{$index}")) {
                    $postContent->thumbnail = GlobalFunction::saveFileAndGivePath($request->file("thumbnail.{$index}"));
                }

                if ($request->filled('audio_waves')) {
                    $postContent->audio_waves = $request->audio_waves;
                }

                $postContent->save();
            }
        }

        CompanyPost::create([
            'company_id' => $company->id,
            'post_id' => $post->id,
            'created_by_user_id' => $actor->id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Company post published successfully.',
            'data' => $post->load(['user', 'content', 'company']),
        ]);
    }
}
