<?php

namespace Tests\Unit;

use App\Models\GlobalFunction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GlobalFunctionStorageUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->integer('storage_type')->default(0);
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'storage_type' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_local_storage_url_is_valid_when_app_url_has_no_trailing_slash(): void
    {
        putenv('APP_URL=https://itga.example.test');
        $_ENV['APP_URL'] = 'https://itga.example.test';
        $_SERVER['APP_URL'] = 'https://itga.example.test';

        Storage::fake('public');

        $url = GlobalFunction::saveFileAndGivePath(
            UploadedFile::fake()->create('profile.pdf', 1, 'application/pdf')
        );

        $this->assertStringStartsWith('https://itga.example.test/storage/uploads/', $url);
        $this->assertStringNotContainsString('itga.example.teststorage', $url);
    }
}
