<?php

namespace App\Console\Commands;

use App\Support\PublicLaunchReadiness;
use Illuminate\Console\Command;

class PublicLaunchReadinessCommand extends Command
{
    protected $signature = 'ops:public-readiness {--json : Output the readiness report as JSON}';

    protected $description = 'Validate the minimum public-launch guardrails for ITGA.';

    public function handle(PublicLaunchReadiness $readiness): int
    {
        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('ITGA public launch readiness');
        $this->line('Environment: ' . $report['environment']);

        foreach ($report['checks'] as $name => $check) {
            $label = $check['ok'] ? '[OK]' : ($check['severity'] === 'blocker' ? '[BLOCKER]' : '[WARN]');
            $this->line(sprintf('%s %s - %s', $label, $name, $check['message']));
        }

        if ($report['ok']) {
            if ($report['warnings'] !== []) {
                $this->warn('Launch blockers are clear, but warnings remain: ' . implode(', ', $report['warnings']));
            }

            return self::SUCCESS;
        }

        $this->error('Public launch blocked by: ' . implode(', ', $report['blockers']));

        return self::FAILURE;
    }
}
