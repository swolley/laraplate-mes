<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;

/**
 * Executes a quality check against its measurements, deriving pass/fail from the
 * tolerance limits and opening a non-conformance when the check fails.
 */
final class QualityCheckService
{
    /**
     * Record measurements and resolve the check status.
     *
     * @param  list<array{characteristic: string, measured_value: float|int|string, nominal?: float|int|string|null, lower_limit?: float|int|string|null, upper_limit?: float|int|string|null}>  $measurements
     */
    public function execute(QualityCheck $check, array $measurements): QualityCheck
    {
        return DB::transaction(function () use ($check, $measurements): QualityCheck {
            $all_within_limits = true;

            foreach ($measurements as $measurement) {
                $within = $this->isWithinLimits($measurement);
                $all_within_limits = $all_within_limits && $within;

                QualityCheckMeasurement::query()->create([
                    'quality_check_id' => $check->id,
                    'characteristic' => $measurement['characteristic'],
                    'nominal' => $measurement['nominal'] ?? null,
                    'lower_limit' => $measurement['lower_limit'] ?? null,
                    'upper_limit' => $measurement['upper_limit'] ?? null,
                    'measured_value' => $measurement['measured_value'],
                    'is_within_limits' => $within,
                ]);
            }

            $status = $all_within_limits ? QualityCheckStatus::Passed : QualityCheckStatus::Failed;
            $check->update(['status' => $status->value, 'checked_at' => now()]);

            if ($status === QualityCheckStatus::Failed) {
                $this->openNonConformance($check);
            }

            return $check->refresh();
        });
    }

    /**
     * @param  array{measured_value: float|int|string, lower_limit?: float|int|string|null, upper_limit?: float|int|string|null}  $measurement
     */
    private function isWithinLimits(array $measurement): bool
    {
        $value = (float) $measurement['measured_value'];
        $lower = $measurement['lower_limit'] ?? null;
        $upper = $measurement['upper_limit'] ?? null;

        if ($lower !== null && $value < (float) $lower) {
            return false;
        }

        return ! ($upper !== null && $value > (float) $upper);
    }

    private function openNonConformance(QualityCheck $check): NonConformance
    {
        return NonConformance::query()->create([
            'company_id' => $check->company_id,
            'production_order_id' => $check->production_order_id,
            'quality_check_id' => $check->id,
            'item_id' => $check->item_id,
            'status' => NonConformanceStatus::Open->value,
            'quantity' => 0,
            'description' => "Quality check failed: {$check->name}",
        ]);
    }
}
