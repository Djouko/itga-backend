<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_audit_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_log_id')->index();
            $table->unsignedBigInteger('appellant_user_id')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('reason', 500);
            $table->text('details')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('reviewed_by', 120)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['appellant_user_id', 'status']);
            $table->index(['audit_log_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_audit_appeals');
    }
};
