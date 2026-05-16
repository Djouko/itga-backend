<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FetchSettingReportReasonsPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->configureInMemoryDatabase();
        $this->createSchema();
        Cache::flush();
    }

    public function test_fetch_setting_returns_report_reasons_ordered_unique_and_non_empty(): void
    {
        DB::table('settings')->insert([
            'setRoomUsersLimit' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('interests')->insert([
            'id' => 1,
            'title' => 'Backend',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => 1,
            'is_block' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rooms')->insert([
            'id' => 1,
            'user_id' => 1,
            'is_private' => 0,
            'total_member' => 1,
            'interest_ids' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_reasons')->insert([
            [
                'id' => 10,
                'title' => '  Spam  ',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 11,
                'title' => 'spam',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 12,
                'title' => '   ',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 13,
                'title' => '<b>Harassment</b>',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 14,
                'title' => 'Other issue',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->postJson('/api/fetchSetting');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Fetch Setting',
            ]);

        $reportReasons = $response->json('data.reportReasons');
        $titles = collect($reportReasons)->pluck('title')->values()->all();
        $ids = collect($reportReasons)->pluck('id')->values()->all();

        $this->assertSame(['Spam', 'Harassment', 'Other issue'], $titles);
        $this->assertSame([10, 13, 14], $ids);

        $nonEmptyTitles = array_filter($titles, static fn ($title) => trim((string) $title) !== '');
        $this->assertCount(count($titles), $nonEmptyTitles);

        $lowerTitles = array_map(static fn ($title) => mb_strtolower((string) $title, 'UTF-8'), $titles);
        $this->assertCount(count($titles), array_unique($lowerTitles));
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
        foreach (['settings', 'interests', 'users', 'rooms', 'document_types', 'report_reasons', 'username_restrictions'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->integer('setRoomUsersLimit')->default(500);
            $table->timestamps();
        });

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->integer('is_block')->default(0);
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('is_private')->default(0);
            $table->integer('total_member')->default(0);
            $table->string('interest_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('report_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('canonical_title')->nullable();
            $table->timestamps();
        });

        Schema::create('username_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }
}
