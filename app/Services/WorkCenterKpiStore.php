<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\MES\Data\WorkCenterKpis;

/**
 * Cache-backed home of the materialised work-center KPIs. Reads never recompute:
 * a day nobody materialised yet is simply absent.
 */
final class WorkCenterKpiStore
{
    private const int TTL_DAYS = 3;

    public function get(int $work_center_id, DateTimeInterface $day): ?WorkCenterKpis
    {
        $kpis = Cache::get($this->key($work_center_id, $day));

        return $kpis instanceof WorkCenterKpis ? $kpis : null;
    }

    public function put(WorkCenterKpis $kpis): void
    {
        Cache::put($this->key($kpis->work_center_id, Carbon::parse($kpis->day)), $kpis, now()->addDays(self::TTL_DAYS));
    }

    private function key(int $work_center_id, DateTimeInterface $day): string
    {
        return sprintf('mes:kpi:%d:%s', $work_center_id, Carbon::parse($day)->toDateString());
    }
}
