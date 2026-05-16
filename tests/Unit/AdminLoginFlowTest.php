<?php

namespace Tests\Unit;

use App\Http\Controllers\LoginController;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class AdminLoginFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_login_handles_legacy_password_payload_without_instance_method_dependency(): void
    {
        $admin = new class {
            public string $user_name = 'admin';
            public int $user_type = 1;
            public string $user_password;
            public bool $saved = false;

            public function save(): void
            {
                $this->saved = true;
            }
        };
        $admin->user_password = Crypt::encrypt('Legacy123!');

        $query = Mockery::mock();
        $query->shouldReceive('first')->once()->andReturn($admin);

        $adminMock = Mockery::mock('alias:App\Models\Admin');
        $adminMock->shouldReceive('where')
            ->once()
            ->with('user_name', 'admin')
            ->andReturn($query);

        $request = Request::create('/loginForm', 'POST', [
            'user_name' => 'admin',
            'user_password' => 'Legacy123!',
        ]);

        $session = new Store('test', new ArraySessionHandler(120));
        $request->setLaravelSession($session);

        $response = (new LoginController())->checklogin($request);
        $payload = $response->getData(true);

        $this->assertTrue($payload['status']);
        $this->assertSame('Login Successfully.', $payload['message']);
        $this->assertSame('admin', $session->get('user_name'));
        $this->assertSame(1, $session->get('user_type'));
        $this->assertTrue($admin->saved);
        $this->assertTrue(Hash::check('Legacy123!', $admin->user_password));
    }
}
