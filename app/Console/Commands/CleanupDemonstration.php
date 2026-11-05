<?php

namespace App\Console\Commands;

use App\Services\DemonstrationCleanup;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CleanupDemonstration extends Command
{
    protected $signature = 'battlefront:cleanup-demo {--scope= : orders or all} {--confirm-demo} {--apply}';

    protected $description = 'Dry-run-first cleanup of explicitly proven demonstration rehearsals';

    public function handle(DemonstrationCleanup $cleanup): int
    {
        if (! $this->option('confirm-demo')) {
            $this->error('--confirm-demo is required, including for dry runs.');

            return self::FAILURE;
        }
        try {
            $cleanup->assertTarget();
            $rows = $this->option('apply')
                ? Cache::lock('battlefront-demo-rehearsal', 120)->block(10, fn () => $cleanup->execute((string) $this->option('scope'), true))
                : $cleanup->execute((string) $this->option('scope'), false);
            if (! is_array($rows)) {
                throw new \RuntimeException('Cleanup did not return a valid result.');
            }
            $this->info($this->option('apply') ? 'Applied supported cleanup actions.' : 'DRY RUN: no cleanup writes.');
            $this->table(['Type', 'ID', 'Action', 'Reason'], $rows);
            $this->line(json_encode(collect($rows)->countBy('action')->all(), JSON_THROW_ON_ERROR));
            $this->warn('Baseline fixtures, payments, notifications and history remain. This is not a complete database refresh.');

            return collect($rows)->contains('action', 'excluded') ? 2 : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof QueryException ? $exception->getMessage() : 'Cleanup failed and database changes were rolled back; inspect private diagnostics.');

            return self::FAILURE;
        }
    }
}
