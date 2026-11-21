<?php

namespace App\Console\Commands;

use App\Services\ActivityCheckService;
use Illuminate\Console\Command;

class ProcessActivityChecks extends Command
{
    protected $signature = 'attendance:process-activity-checks';
    protected $description = 'Expire unanswered activity checks (auto punch-out), detect inactivity, top up checks';

    public function handle(ActivityCheckService $service): int
    {
        $stats = $service->process();
        if (array_sum($stats) > 0) $this->info(json_encode($stats));
        return self::SUCCESS;
    }
}
