<?php

namespace Tests\Unit;

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\PostController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class CompanyActorAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_company_owner_authorization_error_rejects_company_without_owner(): void
    {
        $controller = $this->applicationController();
        $company = (object) ['owner_user_id' => null];
        $request = Request::create('/', 'POST', []);

        $response = $controller->checkCompanyOwnerAuthorization($company, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('company_owner_migration_required', $response->getData(true)['error_code']);
    }

    public function test_company_owner_authorization_error_requires_owner_user_payload(): void
    {
        $controller = $this->applicationController();
        $company = (object) ['owner_user_id' => 15];
        $request = Request::create('/', 'POST', []);

        $response = $controller->checkCompanyOwnerAuthorization($company, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('company_owner_required', $response->getData(true)['error_code']);
    }

    public function test_company_owner_authorization_error_rejects_wrong_owner(): void
    {
        $controller = $this->applicationController();
        $company = (object) ['owner_user_id' => 15];
        $request = Request::create('/', 'POST', ['user_id' => 16]);

        $response = $controller->checkCompanyOwnerAuthorization($company, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('company_owner_mismatch', $response->getData(true)['error_code']);
    }

    public function test_company_owner_authorization_error_allows_matching_owner(): void
    {
        $controller = $this->applicationController();
        $company = (object) ['owner_user_id' => 15];
        $request = Request::create('/', 'POST', ['user_id' => 15]);

        $this->assertNull($controller->checkCompanyOwnerAuthorization($company, $request));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_resolve_company_actor_validates_missing_suspended_and_wrong_owner_companies(): void
    {
        $activeCompany = (object) ['id' => 10, 'owner_user_id' => 100, 'is_suspended' => 0, 'name' => 'ITGA'];
        $suspendedCompany = (object) ['id' => 20, 'owner_user_id' => 100, 'is_suspended' => 1, 'name' => 'Suspended'];
        $wrongOwnerCompany = (object) ['id' => 30, 'owner_user_id' => 200, 'is_suspended' => 0, 'name' => 'Other'];

        $companyMock = Mockery::mock('alias:App\Models\Company');
        $companyMock->shouldReceive('find')->with(10)->andReturn($activeCompany);
        $companyMock->shouldReceive('find')->with(20)->andReturn($suspendedCompany);
        $companyMock->shouldReceive('find')->with(30)->andReturn($wrongOwnerCompany);
        $companyMock->shouldReceive('find')->with(40)->andReturn(null);

        $user = new User();
        $user->id = 100;

        $controller = new PostController();
        $method = (new ReflectionClass(PostController::class))->getMethod('resolveCompanyActor');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, Request::create('/', 'POST', []), $user));
        $this->assertSame($activeCompany, $method->invoke($controller, Request::create('/', 'POST', ['company_id' => 10]), $user));

        $suspendedResponse = $method->invoke($controller, Request::create('/', 'POST', ['company_id' => 20]), $user);
        $this->assertInstanceOf(JsonResponse::class, $suspendedResponse);
        $this->assertSame('Company is not allowed to interact.', $suspendedResponse->getData(true)['message']);

        $wrongOwnerResponse = $method->invoke($controller, Request::create('/', 'POST', ['company_id' => 30]), $user);
        $this->assertInstanceOf(JsonResponse::class, $wrongOwnerResponse);
        $this->assertSame('Only the linked company owner can use company mode.', $wrongOwnerResponse->getData(true)['message']);

        $missingResponse = $method->invoke($controller, Request::create('/', 'POST', ['company_id' => 40]), $user);
        $this->assertInstanceOf(JsonResponse::class, $missingResponse);
        $this->assertSame('Company is not allowed to interact.', $missingResponse->getData(true)['message']);
    }

    private function applicationController()
    {
        return new class extends ApplicationController {
            public function checkCompanyOwnerAuthorization($company, Request $request)
            {
                return $this->companyOwnerAuthorizationError($company, $request);
            }
        };
    }
}
