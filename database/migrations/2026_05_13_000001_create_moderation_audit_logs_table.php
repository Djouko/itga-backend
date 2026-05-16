<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('moderation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moderator_user_id');
            $table->string('action', 80);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('target_owner_user_id')->nullable();
            $table->string('status', 40)->default('success');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('moderator_user_id');
            $table->index(['target_type', 'target_id']);
            $table->index('target_owner_user_id');
            $table->index('action');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('moderation_audit_logs');
    }
};
