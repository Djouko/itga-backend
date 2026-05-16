<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stories')) {
            return;
        }

        if (!Schema::hasColumn('stories', 'company_id')) {
            $after = Schema::hasColumn('stories', 'user_id') ? 'user_id' : 'id';
            Schema::table('stories', function (Blueprint $table) use ($after) {
                $table->unsignedBigInteger('company_id')->nullable()->after($after);
                $table->index('company_id', 'idx_stories_company_id');
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('stories', 'view_by_company_ids')) {
            $after = Schema::hasColumn('stories', 'view_by_user_ids') ? 'view_by_user_ids' : 'company_id';
            Schema::table('stories', function (Blueprint $table) use ($after) {
                $table->text('view_by_company_ids')->nullable()->after($after);
            });
        }

        if (
            Schema::hasColumn('stories', 'user_id')
            && Schema::hasColumn('stories', 'company_id')
            && Schema::hasColumn('stories', 'created_at')
        ) {
            $this->safeIndex('stories', ['user_id', 'company_id', 'created_at'], 'idx_stories_actor_created');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('stories')) {
            return;
        }

        $this->safeDropIndex('stories', 'idx_stories_actor_created');

        if (Schema::hasColumn('stories', 'view_by_company_ids')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropColumn('view_by_company_ids');
            });
        }

        if (Schema::hasColumn('stories', 'company_id')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex('idx_stories_company_id');
                $table->dropColumn('company_id');
            });
        }
    }

    private function safeIndex(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        } catch (\Exception $e) {
            // Index may already exist in production.
        }
    }

    private function safeDropIndex(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        } catch (\Exception $e) {
            // Index may not exist in production.
        }
    }
};
