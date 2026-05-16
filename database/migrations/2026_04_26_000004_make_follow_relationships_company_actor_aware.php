<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('following_lists')) {
            if (!Schema::hasColumn('following_lists', 'company_id')) {
                Schema::table('following_lists', function (Blueprint $table) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('my_user_id');
                    $table->index('company_id', 'idx_following_company_id');
                    $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }

            $this->safeDropUnique('following_lists', 'following_composite_unique');
            $this->safeIndex('following_lists', ['my_user_id', 'company_id', 'user_id'], 'idx_following_actor_user');
            $this->safeIndex('following_lists', ['user_id', 'company_id'], 'idx_following_target_actor');
        }

        if (Schema::hasTable('company_followers')) {
            if (!Schema::hasColumn('company_followers', 'follower_company_id')) {
                Schema::table('company_followers', function (Blueprint $table) {
                    $table->unsignedBigInteger('follower_company_id')->nullable()->after('user_id');
                    $table->index('follower_company_id', 'idx_company_followers_actor_company');
                    $table->foreign('follower_company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }

            $this->safeDropUnique('company_followers', 'company_followers_user_id_company_id_unique');
            $this->safeIndex('company_followers', ['user_id', 'follower_company_id', 'company_id'], 'idx_company_followers_actor_target');
        }

        if (Schema::hasTable('saved_notifications') && !Schema::hasColumn('saved_notifications', 'item_id')) {
            $after = Schema::hasColumn('saved_notifications', 'company_id') ? 'company_id' : 'user_id';
            Schema::table('saved_notifications', function (Blueprint $table) use ($after) {
                $table->unsignedBigInteger('item_id')->nullable()->after($after);
                $table->index('item_id', 'idx_saved_notifications_item_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('saved_notifications') && Schema::hasColumn('saved_notifications', 'item_id')) {
            Schema::table('saved_notifications', function (Blueprint $table) {
                $table->dropIndex('idx_saved_notifications_item_id');
                $table->dropColumn('item_id');
            });
        }

        if (Schema::hasTable('company_followers')) {
            $this->safeDropIndex('company_followers', 'idx_company_followers_actor_target');

            if (Schema::hasColumn('company_followers', 'follower_company_id')) {
                Schema::table('company_followers', function (Blueprint $table) {
                    $table->dropForeign(['follower_company_id']);
                    $table->dropIndex('idx_company_followers_actor_company');
                    $table->dropColumn('follower_company_id');
                });
            }
        }

        if (Schema::hasTable('following_lists')) {
            $this->safeDropIndex('following_lists', 'idx_following_target_actor');
            $this->safeDropIndex('following_lists', 'idx_following_actor_user');

            if (Schema::hasColumn('following_lists', 'company_id')) {
                Schema::table('following_lists', function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                    $table->dropIndex('idx_following_company_id');
                    $table->dropColumn('company_id');
                });
            }
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

    private function safeDropUnique(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        } catch (\Exception $e) {
            // Unique index may not exist in production.
        }
    }
};
