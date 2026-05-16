<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('like_reel_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('reel_comment_id');
            $table->timestamps();

            $table->index(['user_id', 'reel_comment_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('like_reel_comments');
    }
};
