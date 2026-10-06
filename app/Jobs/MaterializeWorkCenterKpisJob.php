<?php

declare(strict_types=1);

namespace Modules\MES\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\WorkCenterKpiMaterializer;

/**
 * Materialises the OEE and capacity of one work center for one day (Y-m-d).
 */
final class MaterializeWorkCenterKpisJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $work_center_id, public string $day)
    {
        $this->onConnection(config()->string('mes.queue.connection'));
        $this->onQueue(config()->string('mes.queue.name'));
    }

    public function handle(WorkCenterKpiMaterializer $materializer): void
    {
        $work_center = WorkCenter::query()->withoutGlobalScopes()->find($this->work_center_id);

        if ($work_center === null) {
            return;
        }

        $materializer->materialize($work_center, Carbon::parse($this->day));
    }
}
