<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('saved_notifications') || Schema::hasColumn('saved_notifications', 'company_id')) {
            return;
        }

        Schema::table('saved_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('saved_notifications') || !Schema::hasColumn('saved_notifications', 'company_id')) {
            return;
        }

        Schema::table('saved_notifications', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
