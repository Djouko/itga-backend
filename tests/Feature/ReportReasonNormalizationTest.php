<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportReasonNormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->configureInMemoryDatabase();
        $this->createSchema();
    }

    public function test_super_admin_add_report_reason_normalizes_title_before_save(): void
    {
        $response = $this
            ->withSession([
                'user_name' => 'super-admin',
                'user_type' => 1,
            ])
            ->postJson('/addreportReason', [
                'title' => '  <b>Spam   or   scam</b>  ',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Reason Added Successfully',
            ]);

        $this->assertDatabaseHas('report_reasons', [
            'title' => 'Spam or scam',
            'canonical_title' => 'spam or scam',
        ]);
    }

    public function test_super_admin_add_report_reason_rejects_canonical_duplicate(): void
    {
        DB::table('report_reasons')->insert([
            'title' => 'Spam or scam',
            'canonical_title' => 'spam or scam',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withSession([
                'user_name' => 'super-admin',
                'user_type' => 1,
            ])
            ->postJson('/addreportReason', [
                'title' => '  spam   OR  scam  ',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => 'Report Reason Already Exists',
            ]);

        $this->assertSame(1, DB::table('report_reasons')->count());
    }

    public function test_super_admin_update_report_reason_rejects_duplicate_after_normalization(): void
    {
        $firstReasonId = DB::table('report_reasons')->insertGetId([
            'title' => 'Harassment',
            'canonical_title' => 'harassment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondReasonId = DB::table('report_reasons')->insertGetId([
            'title' => 'Fake profile',
            'canonical_title' => 'fake profile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withSession([
                'user_name' => 'super-admin',
                'user_type' => 1,
            ])
            ->postJson('/updateReportReason/' . $secondReasonId, [
                'title' => '  harassment  ',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => 'Reason Already Exist',
            ]);

        $this->assertDatabaseHas('report_reasons', [
            'id' => $firstReasonId,
            'title' => 'Harassment',
            'canonical_title' => 'harassment',
        ]);
        $this->assertDatabaseHas('report_reasons', [
            'id' => $secondReasonId,
            'title' => 'Fake profile',
            'canonical_title' => 'fake profile',
        ]);
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
        Schema::dropIfExists('report_reasons');

        Schema::create('report_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('canonical_title')->nullable();
            $table->timestamps();
        });
    }
}
