<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlanCharacteristic;

/**
 * Records measurements on a quality check and resolves the check against their tolerance limits, opening a
 * non-conformance when it fails. {@see self::record()} and {@see self::resolve()} are the two halves that
 * `execute()` runs together for a person entering the measurements; machines record as samples arrive and
 * the check resolves once {@see self::isComplete()}.
 *
 * @phpstan-type MeasurementInput array{characteristic: string, measured_value: float|int|string, nominal?: float|int|string|null, lower_limit?: float|int|string|null, upper_limit?: float|int|string|null, quality_plan_characteristic_id?: int|null, serial?: string|null, measured_at?: mixed, source?: string, machine_signal_id?: int|null}
 */
final class QualityCheckService
{
    /**
     * Record measurements and resolve the check status.
     *
     * @param  list<MeasurementInput>  $measurements
     */
    public function execute(QualityCheck $check, array $measurements): QualityCheck
    {
        return $check->getConnection()->transaction(function () use ($check, $measurements): QualityCheck {
            $check = $this->lock($check);
            $rows = $this->store($check, $measurements);
            $passed = collect($rows)->every(static fn (QualityCheckMeasurement $row): bool => $row->is_within_limits)
                && ! $this->hasOutOfLimits($check, 'machine');

            return $this->settle($check, $passed, true);
        });
    }

    /**
     * Store measurements on the check. A pending check is not resolved; one that is already resolved gets a
     * non-conformance for each out-of-limit measurement, its status unchanged.
     *
     * @param  list<MeasurementInput>  $measurements
     * @return list<QualityCheckMeasurement>
     */
    public function record(QualityCheck $check, array $measurements): array
    {
        return $check->getConnection()->transaction(function () use ($check, $measurements): array {
            $check = $this->lock($check);
            $rows = $this->store($check, $measurements);

            if ($check->status !== QualityCheckStatus::Pending) {
                foreach ($rows as $row) {
                    if (! $row->is_within_limits) {
                        $this->openNonConformance($check, "Measurement out of limits after the check was resolved: {$row->characteristic} = {$row->measured_value}");
                    }
                }
            }

            return $rows;
        });
    }

    /**
     * Resolve the check from all its measurements: failed when any is out of limits, else passed. A failure
     * opens a non-conformance once, when the check enters the failed status.
     */
    public function resolve(QualityCheck $check): QualityCheck
    {
        return $check->getConnection()->transaction(function () use ($check): QualityCheck {
            $check = $this->lock($check);

            return $this->settle($check, ! $this->hasOutOfLimits($check), false);
        });
    }

    /**
     * Resolve a pending check once it is complete. The check is locked and its status read again first, so
     * two processes completing it together resolve it once.
     *
     * @return bool whether this call resolved the check
     */
    public function resolveWhenComplete(QualityCheck $check): bool
    {
        return $check->getConnection()->transaction(function () use ($check): bool {
            $check = $this->lock($check);

            if ($check->status !== QualityCheckStatus::Pending || ! $this->isComplete($check)) {
                return false;
            }

            $this->settle($check, ! $this->hasOutOfLimits($check), false);

            return true;
        });
    }

    /**
     * Whether every characteristic of the plan has at least its required samples among the check's
     * measurements. A check without a plan, or a plan without characteristics, is never complete.
     */
    public function isComplete(QualityCheck $check): bool
    {
        if ($check->quality_plan_id === null) {
            return false;
        }

        $characteristics = QualityPlanCharacteristic::query()->where('quality_plan_id', $check->quality_plan_id)->get();

        if ($characteristics->isEmpty()) {
            return false;
        }

        $counts = QualityCheckMeasurement::query()
            ->where('quality_check_id', $check->id)
            ->whereNotNull('quality_plan_characteristic_id')
            ->toBase()
            ->selectRaw('quality_plan_characteristic_id, COUNT(*) as samples')
            ->groupBy('quality_plan_characteristic_id')
            ->pluck('samples', 'quality_plan_characteristic_id');

        return $characteristics->every(static fn (QualityPlanCharacteristic $characteristic): bool => (is_numeric($counts[$characteristic->id] ?? null) ? (int) $counts[$characteristic->id] : 0) >= max(1, $characteristic->required_samples));
    }

    /**
     * The check as the database holds it now, locked until the transaction ends: two writers of one check are
     * serialised, and the status a caller loaded earlier is never trusted.
     */
    private function lock(QualityCheck $check): QualityCheck
    {
        return QualityCheck::query()->withoutGlobalScopes()->whereKey($check->id)->lockForUpdate()->firstOrFail();
    }

    private function hasOutOfLimits(QualityCheck $check, ?string $source = null): bool
    {
        return QualityCheckMeasurement::query()
            ->where('quality_check_id', $check->id)
            ->where('is_within_limits', false)
            ->when($source !== null, static fn ($query) => $query->where('source', $source))
            ->exists();
    }

    /**
     * @param  list<MeasurementInput>  $measurements
     * @return list<QualityCheckMeasurement>
     */
    private function store(QualityCheck $check, array $measurements): array
    {
        $rows = [];

        foreach ($measurements as $measurement) {
            $rows[] = QualityCheckMeasurement::query()->create([
                'quality_check_id' => $check->id,
                'characteristic' => $measurement['characteristic'],
                'nominal' => $measurement['nominal'] ?? null,
                'lower_limit' => $measurement['lower_limit'] ?? null,
                'upper_limit' => $measurement['upper_limit'] ?? null,
                'measured_value' => $measurement['measured_value'],
                'is_within_limits' => $this->isWithinLimits($measurement),
                'quality_plan_characteristic_id' => $measurement['quality_plan_characteristic_id'] ?? null,
                'serial' => $measurement['serial'] ?? null,
                'measured_at' => $measurement['measured_at'] ?? null,
                'source' => $measurement['source'] ?? 'manual',
                'machine_signal_id' => $measurement['machine_signal_id'] ?? null,
            ]);
        }

        return $rows;
    }

    private function settle(QualityCheck $check, bool $passed, bool $always_open_non_conformance): QualityCheck
    {
        $was_failed = $check->status === QualityCheckStatus::Failed;
        $status = $passed ? QualityCheckStatus::Passed : QualityCheckStatus::Failed;
        $check->update(['status' => $status->value, 'checked_at' => now()]);

        if ($status === QualityCheckStatus::Failed && ($always_open_non_conformance || ! $was_failed)) {
            $this->openNonConformance($check, "Quality check failed: {$check->name}");
        }

        return $check->refresh();
    }

    /**
     * Limits are inclusive.
     *
     * @param  MeasurementInput  $measurement
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

    private function openNonConformance(QualityCheck $check, string $description): NonConformance
    {
        return NonConformance::query()->create([
            'company_id' => $check->company_id,
            'production_order_id' => $check->production_order_id,
            'quality_check_id' => $check->id,
            'item_id' => $check->item_id,
            'status' => NonConformanceStatus::Open->value,
            'quantity' => 0,
            'description' => $description,
        ]);
    }
}
