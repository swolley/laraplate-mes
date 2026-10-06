<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\MES\Jobs\MaterializeWorkCenterKpisJob;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * Queues the KPI materialisation of every active work center for a day (today by default).
 */
final class MaterializeKpisCommand extends Command
{
    #[Override]
    protected $signature = 'mes:kpis:materialize
                            {--day= : Day to materialise as Y-m-d, today by default}';

    #[Override]
    protected $description = 'Queue the OEE and capacity materialisation of every active work center <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(): int
    {
        $day = Carbon::parse($this->option('day') ?: 'today')->toDateString();
        $dispatched = 0;

        WorkCenter::query()
            ->withoutGlobalScopes()
            ->active()
            ->each(static function (WorkCenter $work_center) use ($day, &$dispatched): void {
                MaterializeWorkCenterKpisJob::dispatch($work_center->id, $day);
                $dispatched++;
            });

        $this->info("Queued KPI materialisation for {$dispatched} work centers ({$day}).");

        return self::SUCCESS;
    }
}
