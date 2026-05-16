<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('reels') && !Schema::hasColumn('reels', 'company_id')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
                $table->index('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (Schema::hasTable('reel_comments') && !Schema::hasColumn('reel_comments', 'company_id')) {
            Schema::table('reel_comments', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
                $table->index('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (Schema::hasTable('like_reel_comments') && !Schema::hasColumn('like_reel_comments', 'company_id')) {
            Schema::table('like_reel_comments', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
                $table->index('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (Schema::hasTable('likes')) {
            $this->safeDropUnique('likes', 'likes_user_post_unique');
            $this->safeIndex('likes', ['user_id', 'company_id', 'post_id'], 'idx_likes_actor_post');
            $this->safeIndex('likes', ['user_id', 'company_id', 'reel_id'], 'idx_likes_actor_reel');
        }

        if (Schema::hasTable('like_comments')) {
            $this->safeIndex('like_comments', ['user_id', 'company_id', 'comment_id'], 'idx_like_comments_actor_comment');
        }

        if (Schema::hasTable('like_reel_comments')) {
            $this->safeIndex('like_reel_comments', ['user_id', 'company_id', 'reel_comment_id'], 'idx_like_reel_comments_actor_comment');
        }
    }

    public function down()
    {
        if (Schema::hasTable('like_reel_comments')) {
            $this->safeDropIndex('like_reel_comments', 'idx_like_reel_comments_actor_comment');
        }

        if (Schema::hasTable('like_comments')) {
            $this->safeDropIndex('like_comments', 'idx_like_comments_actor_comment');
        }

        if (Schema::hasTable('likes')) {
            $this->safeDropIndex('likes', 'idx_likes_actor_post');
            $this->safeDropIndex('likes', 'idx_likes_actor_reel');
        }

        if (Schema::hasTable('like_reel_comments') && Schema::hasColumn('like_reel_comments', 'company_id')) {
            Schema::table('like_reel_comments', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }

        if (Schema::hasTable('reel_comments') && Schema::hasColumn('reel_comments', 'company_id')) {
            Schema::table('reel_comments', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }

        if (Schema::hasTable('reels') && Schema::hasColumn('reels', 'company_id')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id']);
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
