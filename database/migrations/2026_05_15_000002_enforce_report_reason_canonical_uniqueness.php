<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'uq_report_reasons_canonical_title';

    public function up()
    {
        if (!Schema::hasTable('report_reasons')) {
            Schema::create('report_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('canonical_title', 191)->unique(self::UNIQUE_INDEX);
                $table->timestamps();
            });

            return;
        }

        if (!Schema::hasColumn('report_reasons', 'canonical_title')) {
            Schema::table('report_reasons', function (Blueprint $table) {
                $table->string('canonical_title', 191)->nullable()->after('title');
            });
        }

        $seenCanonical = [];
        $duplicateIds = [];

        $rows = DB::table('report_reasons')
            ->select(['id', 'title'])
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($rows as $row) {
            $normalizedTitle = $this->normalizeTitle((string) $row->title);
            $canonicalTitle = $this->canonicalKey($normalizedTitle);

            if ($canonicalTitle === '') {
                $duplicateIds[] = (int) $row->id;
                continue;
            }

            if (isset($seenCanonical[$canonicalTitle])) {
                $duplicateIds[] = (int) $row->id;
                continue;
            }

            DB::table('report_reasons')
                ->where('id', (int) $row->id)
                ->update([
                    'title' => $normalizedTitle,
                    'canonical_title' => $canonicalTitle,
                    'updated_at' => now(),
                ]);

            $seenCanonical[$canonicalTitle] = (int) $row->id;
        }

        if (!empty($duplicateIds)) {
            DB::table('report_reasons')->whereIn('id', $duplicateIds)->delete();
        }

        $this->enforceNotNullCanonicalTitleIfSupported();

        try {
            Schema::table('report_reasons', function (Blueprint $table) {
                $table->unique('canonical_title', self::UNIQUE_INDEX);
            });
        } catch (\Throwable $e) {
            // Unique index may already exist in production.
        }
    }

    public function down()
    {
        if (!Schema::hasTable('report_reasons')) {
            return;
        }

        try {
            Schema::table('report_reasons', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        } catch (\Throwable $e) {
            // Index may not exist in all environments.
        }

        if (Schema::hasColumn('report_reasons', 'canonical_title')) {
            try {
                Schema::table('report_reasons', function (Blueprint $table) {
                    $table->dropColumn('canonical_title');
                });
            } catch (\Throwable $e) {
                // Some legacy databases may block drop-column without doctrine/dbal.
            }
        }
    }

    private function normalizeTitle(string $title): string
    {
        $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $withoutTags = strip_tags($decoded);
        return preg_replace('/\s+/u', ' ', trim($withoutTags)) ?? '';
    }

    private function canonicalKey(string $title): string
    {
        return mb_strtolower($this->normalizeTitle($title), 'UTF-8');
    }

    private function enforceNotNullCanonicalTitleIfSupported(): void
    {
        $driver = DB::getDriverName();

        try {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE report_reasons MODIFY canonical_title VARCHAR(191) NOT NULL');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE report_reasons ALTER COLUMN canonical_title SET NOT NULL');
            }
        } catch (\Throwable $e) {
            // Keep nullable if current engine/version does not support this alteration safely.
        }
    }
};
