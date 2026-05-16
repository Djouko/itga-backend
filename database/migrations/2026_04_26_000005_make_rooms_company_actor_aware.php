<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rooms') && !Schema::hasColumn('rooms', 'company_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('admin_id');
                $table->index('company_id', 'idx_rooms_company_id');
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (Schema::hasTable('room_users')) {
            if (!Schema::hasColumn('room_users', 'company_id')) {
                $after = Schema::hasColumn('room_users', 'user_id') ? 'user_id' : 'id';
                Schema::table('room_users', function (Blueprint $table) use ($after) {
                    $table->unsignedBigInteger('company_id')->nullable()->after($after);
                    $table->index('company_id', 'idx_room_users_company_id');
                    $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }

            if (!Schema::hasColumn('room_users', 'invited_by_company_id')) {
                $after = Schema::hasColumn('room_users', 'invited_by') ? 'invited_by' : 'company_id';
                Schema::table('room_users', function (Blueprint $table) use ($after) {
                    $table->unsignedBigInteger('invited_by_company_id')->nullable()->after($after);
                    $table->index('invited_by_company_id', 'idx_room_users_invited_by_company');
                    $table->foreign('invited_by_company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }

            $this->safeIndex('room_users', ['room_id', 'user_id', 'company_id'], 'idx_room_users_actor');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('room_users')) {
            $this->safeDropIndex('room_users', 'idx_room_users_actor');

            if (Schema::hasColumn('room_users', 'invited_by_company_id')) {
                Schema::table('room_users', function (Blueprint $table) {
                    $table->dropForeign(['invited_by_company_id']);
                    $table->dropIndex('idx_room_users_invited_by_company');
                    $table->dropColumn('invited_by_company_id');
                });
            }

            if (Schema::hasColumn('room_users', 'company_id')) {
                Schema::table('room_users', function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                    $table->dropIndex('idx_room_users_company_id');
                    $table->dropColumn('company_id');
                });
            }
        }

        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'company_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex('idx_rooms_company_id');
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
