<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('is_verified');
            $table->string('email_verification_code', 6)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_code');
            $table->index('email_verification_code');
        });
    }

    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['email_verification_code']);
            $table->dropColumn([
                'email_verified_at',
                'email_verification_code',
                'email_verification_expires_at',
            ]);
        });
    }
};
