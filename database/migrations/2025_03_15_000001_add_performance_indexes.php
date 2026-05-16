<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Performance indexes for high-traffic queries.
     * Critical for scalability to millions of users.
     * Each index targets the most frequent WHERE/ORDER BY clauses.
     *
     * Uses safe hasColumn() checks because some tables were extended
     * outside of migrations (columns added manually in production).
     */
    public function up()
    {
        // Posts: fetched by user_id (profile), ordered by created_at (feed)
        if (Schema::hasColumn('posts', 'user_id')) {
            $this->safeIndex('posts', 'user_id', 'idx_posts_user_id');
        }
        if (Schema::hasColumn('posts', 'created_at')) {
            $this->safeIndex('posts', 'created_at', 'idx_posts_created_at');
        }
        if (Schema::hasColumn('posts', 'user_id') && Schema::hasColumn('posts', 'created_at')) {
            $this->safeCompositeIndex('posts', ['user_id', 'created_at'], 'idx_posts_user_created');
        }

        // Post contents: always joined with posts via post_id
        if (Schema::hasColumn('post_contents', 'post_id')) {
            $this->safeIndex('post_contents', 'post_id', 'idx_post_contents_post_id');
        }

        // Comments: fetched by post_id, ordered by created_at
        if (Schema::hasColumn('comments', 'post_id')) {
            $this->safeIndex('comments', 'post_id', 'idx_comments_post_id');
            if (Schema::hasColumn('comments', 'created_at')) {
                $this->safeCompositeIndex('comments', ['post_id', 'created_at'], 'idx_comments_post_created');
            }
        }
        if (Schema::hasColumn('comments', 'parent_id')) {
            $this->safeIndex('comments', 'parent_id', 'idx_comments_parent_id');
        }

        // Likes: checked per user+post pair, counted per post
        if (Schema::hasColumn('likes', 'post_id')) {
            $this->safeIndex('likes', 'post_id', 'idx_likes_post_id');
        }
        if (Schema::hasColumn('likes', 'user_id')) {
            $this->safeIndex('likes', 'user_id', 'idx_likes_user_id');
        }
        if (Schema::hasColumn('likes', 'user_id') && Schema::hasColumn('likes', 'post_id')) {
            $this->safeCompositeIndex('likes', ['user_id', 'post_id'], 'idx_likes_user_post');
        }

        // Following: actual columns are my_user_id and user_id
        if (Schema::hasColumn('following_lists', 'my_user_id')) {
            $this->safeIndex('following_lists', 'my_user_id', 'idx_following_my_user');
        }
        if (Schema::hasColumn('following_lists', 'user_id')) {
            $this->safeIndex('following_lists', 'user_id', 'idx_following_user');
        }
        if (Schema::hasColumn('following_lists', 'my_user_id') && Schema::hasColumn('following_lists', 'user_id')) {
            $this->safeCompositeIndex('following_lists', ['my_user_id', 'user_id'], 'idx_following_pair');
        }

        // Rooms: ordered by created_at
        if (Schema::hasColumn('rooms', 'created_at')) {
            $this->safeIndex('rooms', 'created_at', 'idx_rooms_created_at');
        }
        if (Schema::hasColumn('rooms', 'admin_id')) {
            $this->safeIndex('rooms', 'admin_id', 'idx_rooms_admin_id');
        }

        // Room users: fetched by room_id and user_id (columns may exist in production)
        if (Schema::hasTable('room_users')) {
            if (Schema::hasColumn('room_users', 'room_id')) {
                $this->safeIndex('room_users', 'room_id', 'idx_room_users_room_id');
            }
            if (Schema::hasColumn('room_users', 'user_id')) {
                $this->safeIndex('room_users', 'user_id', 'idx_room_users_user_id');
            }
            if (Schema::hasColumn('room_users', 'room_id') && Schema::hasColumn('room_users', 'user_id')) {
                $this->safeCompositeIndex('room_users', ['room_id', 'user_id'], 'idx_room_users_pair');
            }
        }

        // User notifications: fetched by user_id, ordered by created_at
        if (Schema::hasTable('user_notifications')) {
            if (Schema::hasColumn('user_notifications', 'user_id')) {
                $this->safeIndex('user_notifications', 'user_id', 'idx_user_notif_user_id');
                if (Schema::hasColumn('user_notifications', 'created_at')) {
                    $this->safeCompositeIndex('user_notifications', ['user_id', 'created_at'], 'idx_user_notif_user_created');
                }
            }
        }

        // Stories: fetched by user_id (columns may exist in production)
        if (Schema::hasTable('stories')) {
            if (Schema::hasColumn('stories', 'user_id')) {
                $this->safeIndex('stories', 'user_id', 'idx_stories_user_id');
            }
            if (Schema::hasColumn('stories', 'created_at')) {
                $this->safeIndex('stories', 'created_at', 'idx_stories_created_at');
            }
        }

        // Users: searched by username
        if (Schema::hasColumn('users', 'username')) {
            $this->safeIndex('users', 'username', 'idx_users_username');
        }

        // Reels: fetched by user_id (profile), ordered by created_at (feed)
        if (Schema::hasTable('reels')) {
            if (Schema::hasColumn('reels', 'user_id')) {
                $this->safeIndex('reels', 'user_id', 'idx_reels_user_id');
            }
            if (Schema::hasColumn('reels', 'created_at')) {
                $this->safeIndex('reels', 'created_at', 'idx_reels_created_at');
            }
            if (Schema::hasColumn('reels', 'user_id') && Schema::hasColumn('reels', 'created_at')) {
                $this->safeCompositeIndex('reels', ['user_id', 'created_at'], 'idx_reels_user_created');
            }
        }

        // Reel comments: fetched by reel_id
        if (Schema::hasTable('reel_comments')) {
            if (Schema::hasColumn('reel_comments', 'reel_id')) {
                $this->safeIndex('reel_comments', 'reel_id', 'idx_reel_comments_reel_id');
            }
            if (Schema::hasColumn('reel_comments', 'parent_id')) {
                $this->safeIndex('reel_comments', 'parent_id', 'idx_reel_comments_parent_id');
            }
        }

        // Reel likes: checked per user+reel pair
        if (Schema::hasTable('reel_likes')) {
            if (Schema::hasColumn('reel_likes', 'user_id') && Schema::hasColumn('reel_likes', 'reel_id')) {
                $this->safeCompositeIndex('reel_likes', ['user_id', 'reel_id'], 'idx_reel_likes_user_reel');
            }
            if (Schema::hasColumn('reel_likes', 'reel_id')) {
                $this->safeIndex('reel_likes', 'reel_id', 'idx_reel_likes_reel_id');
            }
        }

        // Saved notifications: fetched by my_user_id, ordered by created_at
        if (Schema::hasTable('saved_notifications')) {
            if (Schema::hasColumn('saved_notifications', 'my_user_id')) {
                $this->safeIndex('saved_notifications', 'my_user_id', 'idx_saved_notif_my_user');
            }
            if (Schema::hasColumn('saved_notifications', 'my_user_id') && Schema::hasColumn('saved_notifications', 'created_at')) {
                $this->safeCompositeIndex('saved_notifications', ['my_user_id', 'created_at'], 'idx_saved_notif_user_created');
            }
        }

        // Like comments: checked per user+comment pair
        if (Schema::hasTable('like_comments')) {
            if (Schema::hasColumn('like_comments', 'user_id') && Schema::hasColumn('like_comments', 'comment_id')) {
                $this->safeCompositeIndex('like_comments', ['user_id', 'comment_id'], 'idx_like_comments_user_comment');
            }
        }

        // Reports: admin lookup by status
        if (Schema::hasTable('reports')) {
            if (Schema::hasColumn('reports', 'user_id')) {
                $this->safeIndex('reports', 'user_id', 'idx_reports_user_id');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        $this->safeDropIndex('posts', 'idx_posts_user_id');
        $this->safeDropIndex('posts', 'idx_posts_created_at');
        $this->safeDropIndex('posts', 'idx_posts_user_created');
        $this->safeDropIndex('post_contents', 'idx_post_contents_post_id');
        $this->safeDropIndex('comments', 'idx_comments_post_id');
        $this->safeDropIndex('comments', 'idx_comments_post_created');
        $this->safeDropIndex('comments', 'idx_comments_parent_id');
        $this->safeDropIndex('likes', 'idx_likes_post_id');
        $this->safeDropIndex('likes', 'idx_likes_user_id');
        $this->safeDropIndex('likes', 'idx_likes_user_post');
        $this->safeDropIndex('following_lists', 'idx_following_my_user');
        $this->safeDropIndex('following_lists', 'idx_following_user');
        $this->safeDropIndex('following_lists', 'idx_following_pair');
        $this->safeDropIndex('rooms', 'idx_rooms_created_at');
        $this->safeDropIndex('rooms', 'idx_rooms_admin_id');
        $this->safeDropIndex('room_users', 'idx_room_users_room_id');
        $this->safeDropIndex('room_users', 'idx_room_users_user_id');
        $this->safeDropIndex('room_users', 'idx_room_users_pair');
        $this->safeDropIndex('user_notifications', 'idx_user_notif_user_id');
        $this->safeDropIndex('user_notifications', 'idx_user_notif_user_created');
        $this->safeDropIndex('stories', 'idx_stories_user_id');
        $this->safeDropIndex('stories', 'idx_stories_created_at');
        $this->safeDropIndex('users', 'idx_users_username');
        $this->safeDropIndex('reels', 'idx_reels_user_id');
        $this->safeDropIndex('reels', 'idx_reels_created_at');
        $this->safeDropIndex('reels', 'idx_reels_user_created');
        $this->safeDropIndex('reel_comments', 'idx_reel_comments_reel_id');
        $this->safeDropIndex('reel_comments', 'idx_reel_comments_parent_id');
        $this->safeDropIndex('reel_likes', 'idx_reel_likes_user_reel');
        $this->safeDropIndex('reel_likes', 'idx_reel_likes_reel_id');
        $this->safeDropIndex('saved_notifications', 'idx_saved_notif_my_user');
        $this->safeDropIndex('saved_notifications', 'idx_saved_notif_user_created');
        $this->safeDropIndex('like_comments', 'idx_like_comments_user_comment');
        $this->safeDropIndex('reports', 'idx_reports_user_id');
    }

    /**
     * Safely add a single-column index (skips if already exists).
     */
    private function safeIndex(string $table, string $column, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $indexName) {
                $t->index($column, $indexName);
            });
        } catch (\Exception $e) {
            // Index may already exist — skip silently
        }
    }

    /**
     * Safely add a composite index (skips if already exists).
     */
    private function safeCompositeIndex(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        } catch (\Exception $e) {
            // Index may already exist — skip silently
        }
    }

    /**
     * Safely drop an index (skips if table/index doesn't exist).
     */
    private function safeDropIndex(string $table, string $indexName): void
    {
        try {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            }
        } catch (\Exception $e) {
            // Index may not exist — skip silently
        }
    }
};
