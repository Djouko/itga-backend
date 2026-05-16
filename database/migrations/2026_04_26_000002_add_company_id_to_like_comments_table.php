<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('like_comments') || Schema::hasColumn('like_comments', 'company_id')) {
            return;
        }

        Schema::table('like_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
            $table->index('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down()
    {
        if (!Schema::hasTable('like_comments') || !Schema::hasColumn('like_comments', 'company_id')) {
            return;
        }

        Schema::table('like_comments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
