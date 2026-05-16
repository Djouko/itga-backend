<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('headline')->nullable()->after('bio');
            $table->text('about')->nullable()->after('headline');
            $table->json('experience')->nullable()->after('about');
            $table->json('education')->nullable()->after('experience');
            $table->json('skills')->nullable()->after('education');
            $table->string('location')->nullable()->after('skills');
            $table->string('website')->nullable()->after('location');
            $table->string('pronouns')->nullable()->after('website');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['headline', 'about', 'experience', 'education', 'skills', 'location', 'website', 'pronouns']);
        });
    }
};
