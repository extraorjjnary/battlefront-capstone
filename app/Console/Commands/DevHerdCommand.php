<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\DevCommands;

#[Signature('dev:herd {--list : List the processes without starting them}')]
#[Description('Run Vite, queue listeners, and the scheduler while Herd serves the application.')]
class DevHerdCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        DevCommands::except('server');

        if ($this->option('list')) {
            return $this->call('dev:list', ['--json' => true]);
        }

        return $this->call('dev');
    }
}
