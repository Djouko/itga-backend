<?php

namespace App\Console\Commands;

use App\Models\ModerationAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ModerationAuditMaintenanceCommand extends Command
{
    protected $signature = 'moderation:audit-maintenance
                            {--retention-days= : Delete logs older than this number of days}
                            {--anonymize-days= : Anonymize IP/user-agent older than this number of days}
                            {--dry-run : Show impact without writing changes}';

    protected $description = 'Apply GDPR maintenance on moderation audit logs (anonymization + retention).';

    public function handle(): int
    {
        if (!Schema::hasTable('moderation_audit_logs')) {
            $this->warn('moderation_audit_logs table does not exist.');
            return self::SUCCESS;
        }

        $retentionDays = (int) ($this->option('retention-days') ?: env('MODERATION_AUDIT_RETENTION_DAYS', 365));
        $anonymizeDays = (int) ($this->option('anonymize-days') ?: env('MODERATION_AUDIT_ANONYMIZE_DAYS', 90));
        $dryRun = (bool) $this->option('dry-run');

        if ($retentionDays <= 0 || $anonymizeDays <= 0) {
            $this->error('retention-days and anonymize-days must be positive integers.');
            return self::FAILURE;
        }

        $anonymizeCutoff = now()->subDays($anonymizeDays);
        $deleteCutoff = now()->subDays($retentionDays);

        $anonymizeQuery = ModerationAuditLog::query()
            ->where('created_at', '<=', $anonymizeCutoff)
            ->where(function ($query) {
                $query->whereNotNull('ip_address')
                    ->orWhereNotNull('user_agent');
            });

        $deleteQuery = ModerationAuditLog::query()
            ->where('created_at', '<=', $deleteCutoff);

        $anonymizedCount = (clone $anonymizeQuery)->count();
        $deletedCount = (clone $deleteQuery)->count();

        if (!$dryRun) {
            if ($anonymizedCount > 0) {
                $anonymizeQuery->update([
                    'ip_address' => null,
                    'user_agent' => null,
                    'updated_at' => now(),
                ]);
            }

            if ($deletedCount > 0) {
                $deleteQuery->delete();
            }
        }

        $this->info('Moderation audit maintenance completed.');
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'apply'));
        $this->line('Anonymize cutoff: ' . $anonymizeCutoff->toDateTimeString());
        $this->line('Delete cutoff: ' . $deleteCutoff->toDateTimeString());
        $this->line('Rows to anonymize: ' . $anonymizedCount);
        $this->line('Rows to delete: ' . $deletedCount);

        return self::SUCCESS;
    }
}
