<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('job_offer_id');
            $table->text('cover_letter')->nullable();
            $table->string('cv_file')->nullable();
            $table->string('status')->default('received'); // received, in_review, interview, accepted, rejected
            $table->text('company_note')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('job_offer_id')->references('id')->on('job_offers')->onDelete('cascade');
            $table->unique(['user_id', 'job_offer_id']);
            $table->index('user_id');
            $table->index('job_offer_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('applications');
    }
};
