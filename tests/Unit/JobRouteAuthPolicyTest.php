<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class JobRouteAuthPolicyTest extends TestCase
{
    public function test_job_user_facing_routes_require_authorize_user(): void
    {
        $this->assertRouteHasMiddleware('Job/fetchJobs', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/fetchJobs', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/fetchJobDetail', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/fetchJobDetail', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/toggleSaveJob', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/toggleSaveJob', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/fetchSavedJobs', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/fetchSavedJobs', 'authorizeUser');
    }

    public function test_job_company_facing_routes_require_authorize_user(): void
    {
        $this->assertRouteHasMiddleware('Job/createJob', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/createJob', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/editJob', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/editJob', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/deleteJob', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/deleteJob', 'authorizeUser');

        $this->assertRouteHasMiddleware('Job/fetchCompanyJobs', 'checkHeader');
        $this->assertRouteHasMiddleware('Job/fetchCompanyJobs', 'authorizeUser');
    }

    public function test_job_applications_routes_require_authorize_user(): void
    {
        $this->assertRouteHasMiddleware('Application/applyToJob', 'checkHeader');
        $this->assertRouteHasMiddleware('Application/applyToJob', 'authorizeUser');

        $this->assertRouteHasMiddleware('Application/fetchMyApplications', 'checkHeader');
        $this->assertRouteHasMiddleware('Application/fetchMyApplications', 'authorizeUser');

        $this->assertRouteHasMiddleware('Application/fetchJobApplications', 'checkHeader');
        $this->assertRouteHasMiddleware('Application/fetchJobApplications', 'authorizeUser');

        $this->assertRouteHasMiddleware('Application/updateApplicationStatus', 'checkHeader');
        $this->assertRouteHasMiddleware('Application/updateApplicationStatus', 'authorizeUser');
    }

    public function test_job_mutation_routes_keep_rate_limits(): void
    {
        foreach ([
            'Job/createJob',
            'Job/editJob',
            'Job/deleteJob',
            'Job/toggleSaveJob',
            'Application/applyToJob',
            'Application/updateApplicationStatus',
        ] as $uri) {
            $this->assertRouteHasMiddleware($uri, 'throttle:writes');
        }
    }

    private function assertRouteHasMiddleware(string $uri, string $middleware): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($uri) {
            return trim($route->uri(), '/') === trim('api/' . $uri, '/');
        });

        $this->assertNotNull($route, "Route [{$uri}] should exist.");
        $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$uri}] must include middleware [{$middleware}].");
    }
}
