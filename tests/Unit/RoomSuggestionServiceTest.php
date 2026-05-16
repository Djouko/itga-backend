<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomSuggestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoomSuggestionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInMemoryDatabase();
        $this->createSchema();
        DB::table('settings')->insert([
            'setRoomUsersLimit' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_suggestions_require_verified_creator_and_matching_interest(): void
    {
        $viewer = $this->createUser(['interest_ids' => '1,2']);
        $verifiedAdmin = $this->createUser(['is_verified' => 2]);
        $unverifiedAdmin = $this->createUser(['is_verified' => 0]);
        $blockedAdmin = $this->createUser(['is_verified' => 2, 'is_block' => 1]);
        $companyOwner = $this->createUser(['is_verified' => 0]);
        $verifiedCompany = $this->createCompany($companyOwner->id, ['is_verified' => 1]);

        $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Verified user match',
            'interest_ids' => '2,4',
            'total_member' => 8,
        ]);
        $this->createRoom([
            'admin_id' => $companyOwner->id,
            'company_id' => $verifiedCompany->id,
            'title' => 'Verified company match',
            'interest_ids' => '1,3',
            'total_member' => 7,
        ]);
        $this->createRoom([
            'admin_id' => $unverifiedAdmin->id,
            'title' => 'Unverified match',
            'interest_ids' => '1',
            'total_member' => 50,
        ]);
        $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Verified no interest',
            'interest_ids' => '9',
            'total_member' => 40,
        ]);
        $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Private match',
            'interest_ids' => '1',
            'is_private' => 1,
            'total_member' => 30,
        ]);
        $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Full match',
            'interest_ids' => '1',
            'total_member' => 500,
        ]);
        $this->createRoom([
            'admin_id' => $blockedAdmin->id,
            'title' => 'Blocked admin match',
            'interest_ids' => '1',
            'total_member' => 10,
        ]);

        $titles = app(RoomSuggestionService::class)
            ->forViewer($viewer, null, 10)
            ->pluck('title')
            ->all();

        $this->assertContains('Verified user match', $titles);
        $this->assertContains('Verified company match', $titles);
        $this->assertNotContains('Unverified match', $titles);
        $this->assertNotContains('Verified no interest', $titles);
        $this->assertNotContains('Private match', $titles);
        $this->assertNotContains('Full match', $titles);
        $this->assertNotContains('Blocked admin match', $titles);
    }

    public function test_suggestions_do_not_fall_back_to_random_rooms_without_interests(): void
    {
        $viewer = $this->createUser(['interest_ids' => null]);
        $verifiedAdmin = $this->createUser(['is_verified' => 2]);

        $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Good but unrelated',
            'interest_ids' => '1',
        ]);

        $rooms = app(RoomSuggestionService::class)->forViewer($viewer, null, 10);

        $this->assertCount(0, $rooms);
    }

    public function test_company_mode_uses_company_membership_separately_from_personal_membership(): void
    {
        $viewer = $this->createUser(['interest_ids' => '1']);
        $company = $this->createCompany($viewer->id);
        $verifiedAdmin = $this->createUser(['is_verified' => 2]);
        $room = $this->createRoom([
            'admin_id' => $verifiedAdmin->id,
            'title' => 'Separate actor room',
            'interest_ids' => '1',
        ]);

        DB::table('room_users')->insert([
            'room_id' => $room->id,
            'user_id' => $viewer->id,
            'company_id' => null,
            'type' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personalTitles = app(RoomSuggestionService::class)
            ->forViewer($viewer, null, 10)
            ->pluck('title')
            ->all();
        $companyRooms = app(RoomSuggestionService::class)
            ->forViewer($viewer, $company, 10);

        $this->assertNotContains('Separate actor room', $personalTitles);
        $this->assertContains('Separate actor room', $companyRooms->pluck('title')->all());
        $this->assertSame(0, (int) $companyRooms->firstWhere('id', $room->id)->userRoomStatus);
    }

    private function configureInMemoryDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    private function createSchema(): void
    {
        foreach (['room_users', 'rooms', 'companies', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->integer('setRoomUsersLimit')->default(500);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->text('interest_ids')->nullable();
            $table->integer('is_verified')->default(0);
            $table->integer('is_block')->default(0);
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('is_verified')->default(0);
            $table->integer('is_suspended')->default(0);
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('title');
            $table->text('desc')->nullable();
            $table->string('photo')->nullable();
            $table->text('interest_ids')->nullable();
            $table->integer('is_private')->default(0);
            $table->integer('is_join_request_enable')->default(0);
            $table->integer('total_member')->default(0);
            $table->timestamps();
        });

        Schema::create('room_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->integer('type')->default(0);
            $table->timestamps();
        });
    }

    private function createUser(array $overrides = []): User
    {
        $user = new User();
        $user->full_name = $overrides['full_name'] ?? 'Test User';
        $user->interest_ids = array_key_exists('interest_ids', $overrides)
            ? $overrides['interest_ids']
            : '1';
        $user->is_verified = $overrides['is_verified'] ?? 0;
        $user->is_block = $overrides['is_block'] ?? 0;
        $user->save();

        return $user;
    }

    private function createCompany(int $ownerUserId, array $overrides = []): Company
    {
        $company = new Company();
        $company->owner_user_id = $ownerUserId;
        $company->name = $overrides['name'] ?? 'ITGA Company';
        $company->is_verified = $overrides['is_verified'] ?? 1;
        $company->is_suspended = $overrides['is_suspended'] ?? 0;
        $company->save();

        return $company;
    }

    private function createRoom(array $overrides = []): Room
    {
        $room = new Room();
        $room->admin_id = $overrides['admin_id'];
        $room->company_id = $overrides['company_id'] ?? null;
        $room->title = $overrides['title'] ?? 'Suggested room';
        $room->desc = $overrides['desc'] ?? null;
        $room->photo = $overrides['photo'] ?? null;
        $room->interest_ids = $overrides['interest_ids'] ?? '1';
        $room->is_private = $overrides['is_private'] ?? 0;
        $room->is_join_request_enable = $overrides['is_join_request_enable'] ?? 0;
        $room->total_member = $overrides['total_member'] ?? 1;
        $room->save();

        return $room;
    }
}
