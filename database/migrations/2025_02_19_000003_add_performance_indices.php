<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPerformanceIndices extends Migration
{
    public function up()
    {
        // posts — most queried table
        Schema::table('posts', function (Blueprint $table) {
            if (!$this->indexExists('posts', 'posts_user_id_index')) {
                $table->index('user_id', 'posts_user_id_index');
            }
            if (!$this->indexExists('posts', 'posts_created_at_index')) {
                $table->index('created_at', 'posts_created_at_index');
            }
            if (!$this->indexExists('posts', 'posts_original_post_id_index')) {
                $table->index('original_post_id', 'posts_original_post_id_index');
            }
            if (!$this->indexExists('posts', 'posts_is_restricted_index')) {
                $table->index('is_restricted', 'posts_is_restricted_index');
            }
            // composite: feed query filters user_id + created_at
            if (!$this->indexExists('posts', 'posts_user_id_created_at_index')) {
                $table->index(['user_id', 'created_at'], 'posts_user_id_created_at_index');
            }
        });

        // likes — used in processPosts bulk lookup
        Schema::table('likes', function (Blueprint $table) {
            if (!$this->indexExists('likes', 'likes_post_id_index')) {
                $table->index('post_id', 'likes_post_id_index');
            }
            if (!$this->indexExists('likes', 'likes_user_id_index')) {
                $table->index('user_id', 'likes_user_id_index');
            }
            // composite unique: prevents duplicate likes
            if (!$this->indexExists('likes', 'likes_user_post_unique')) {
                $table->unique(['user_id', 'post_id'], 'likes_user_post_unique');
            }
        });

        // comments — fetched by post_id
        Schema::table('comments', function (Blueprint $table) {
            if (!$this->indexExists('comments', 'comments_post_id_index')) {
                $table->index('post_id', 'comments_post_id_index');
            }
            if (!$this->indexExists('comments', 'comments_user_id_index')) {
                $table->index('user_id', 'comments_user_id_index');
            }
            if (!$this->indexExists('comments', 'comments_parent_id_index')) {
                $table->index('parent_id', 'comments_parent_id_index');
            }
        });

        // following_lists — follow check is very frequent
        Schema::table('following_lists', function (Blueprint $table) {
            if (!$this->indexExists('following_lists', 'following_my_user_id_index')) {
                $table->index('my_user_id', 'following_my_user_id_index');
            }
            if (!$this->indexExists('following_lists', 'following_user_id_index')) {
                $table->index('user_id', 'following_user_id_index');
            }
            if (!$this->indexExists('following_lists', 'following_composite_unique')) {
                $table->unique(['my_user_id', 'user_id'], 'following_composite_unique');
            }
        });

        // users — searched by email/phone/username constantly
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'users_identity_index')) {
                $table->index('identity', 'users_identity_index');
            }
            if (!$this->indexExists('users', 'users_is_block_index')) {
                $table->index('is_block', 'users_is_block_index');
            }
        });

        // user_notifications — fetched per user ordered by date
        Schema::table('user_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('user_notifications', 'to_user_id')) {
                if (!$this->indexExists('user_notifications', 'notif_to_user_id_index')) {
                    $table->index('to_user_id', 'notif_to_user_id_index');
                }
            }
            if (Schema::hasColumn('user_notifications', 'created_at')) {
                if (!$this->indexExists('user_notifications', 'notif_created_at_index')) {
                    $table->index('created_at', 'notif_created_at_index');
                }
            }
        });
    }

    public function down()
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndexIfExists('posts_user_id_index');
            $table->dropIndexIfExists('posts_created_at_index');
            $table->dropIndexIfExists('posts_original_post_id_index');
            $table->dropIndexIfExists('posts_is_restricted_index');
            $table->dropIndexIfExists('posts_user_id_created_at_index');
        });
        Schema::table('likes', function (Blueprint $table) {
            $table->dropIndexIfExists('likes_post_id_index');
            $table->dropIndexIfExists('likes_user_id_index');
            $table->dropUniqueIfExists('likes_user_post_unique');
        });
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndexIfExists('comments_post_id_index');
            $table->dropIndexIfExists('comments_user_id_index');
            $table->dropIndexIfExists('comments_parent_id_index');
        });
        Schema::table('following_lists', function (Blueprint $table) {
            $table->dropIndexIfExists('following_my_user_id_index');
            $table->dropIndexIfExists('following_user_id_index');
            $table->dropUniqueIfExists('following_composite_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndexIfExists('users_identity_index');
            $table->dropIndexIfExists('users_is_block_index');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = \DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
