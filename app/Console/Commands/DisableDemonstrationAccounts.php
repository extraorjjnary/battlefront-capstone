<?php

namespace App\Console\Commands;

use App\Services\DemonstrationProvisioning;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class DisableDemonstrationAccounts extends Command
{
    protected $signature = 'battlefront:disable-demo-accounts {--confirm-demo} {--apply}';

    protected $description = 'Validate or apply explicitly gated capstone demonstration changes; dry run by default';

    public function handle(DemonstrationProvisioning $provisioning): int
    {
        try {
            if (! $this->option('confirm-demo')) {
                throw new \RuntimeException('Explicit --confirm-demo acknowledgement is required.');
            }
            $result = $provisioning->disableAccounts((bool) $this->option('apply'));
            $this->info(($this->option('apply') ? 'Applied: ' : 'Dry run: ').json_encode($result).'. Existing stock and historical records preserved.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof QueryException
                ? $exception->getMessage() : 'Demonstration operation failed; transaction rolled back.');

            return self::FAILURE;
        }
    }
}
