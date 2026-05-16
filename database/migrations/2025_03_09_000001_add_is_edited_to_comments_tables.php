<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->tinyInteger('is_edited')->default(0)->after('desc');
        });

        Schema::table('reel_comments', function (Blueprint $table) {
            $table->tinyInteger('is_edited')->default(0)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('is_edited');
        });

        Schema::table('reel_comments', function (Blueprint $table) {
            $table->dropColumn('is_edited');
        });
    }
};
