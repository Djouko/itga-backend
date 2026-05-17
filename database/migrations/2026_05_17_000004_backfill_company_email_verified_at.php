<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::table('companies')
            ->where('is_verified', 1)
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down()
    {
        // Intentionally non-destructive: email_verified_at may now be the source of account access.
    }
};
