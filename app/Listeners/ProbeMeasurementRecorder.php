<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use DomainException;
use Modules\MES\Events\OutOfToleranceMeasured;
use Modules\MES\Events\ProbeMeasured;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;
use Modules\MES\Services\QualityCheckService;
use Modules\MES\Services\UnattributedMeasurementAssigner;

/**
 * Puts each probe measurement on the quality check of its operation: the check whose plan holds the
 * characteristic of the signal. The limits come from that characteristic. The check resolves when every
 * characteristic has its required samples. A measurement that no check can take waits in the unattributed
 * table. A value outside the limits is announced the first time it is stored, whatever happens to the check.
 * A sample is stored once, so processing a message again changes nothing.
 */
final class ProbeMeasurementRecorder
{
    public function __construct(
        private readonly QualityCheckService $checks,
        private readonly UnattributedMeasurementAssigner $assigner,
    ) {}

    public function handle(ProbeMeasured $event): void
    {
        foreach ($event->samples as $sample) {
            if (! is_numeric($sample->sample->value)) {
                continue;
            }

            $announcement = $this->record($event, $sample);

            if ($announcement instanceof OutOfToleranceMeasured) {
                OutOfToleranceMeasured::dispatch(...[
                    'company_id' => $announcement->company_id,
                    'quality_check_id' => $announcement->quality_check_id,
                    'signal_id' => $announcement->signal_id,
                    'characteristic' => $announcement->characteristic,
                    'value' => $announcement->value,
                    'lower' => $announcement->lower,
                    'upper' => $announcement->upper,
                ]);
            }
        }
    }

    /**
     * @return OutOfToleranceMeasured|null what to announce, when the sample was stored now and is out of limits
     */
    private function record(ProbeMeasured $event, ResolvedSample $sample): ?OutOfToleranceMeasured
    {
        return $sample->device->getConnection()->transaction(function () use ($event, $sample): ?OutOfToleranceMeasured {
            // One writer per signal at a time: the existence checks below are not backed by a unique index.
            MachineSignal::query()->withoutGlobalScopes()->whereKey($sample->signal->id)->lockForUpdate()->first();

            $moment = MachineTime::db($sample->sample->ts);

            if ($this->alreadyStored($sample->signal->id, $moment)) {
                return null;
            }

            $characteristic = $sample->signal->quality_plan_characteristic_id === null ? null : QualityPlanCharacteristic::query()->find($sample->signal->quality_plan_characteristic_id);
            $value = (float) $sample->sample->value;
            $serial = isset($sample->sample->context['serial']) ? (string) $sample->sample->context['serial'] : null;
            $check = $characteristic instanceof QualityPlanCharacteristic ? $this->checkFor($sample->production_order_operation_id, $characteristic) : null;

            if ($check instanceof QualityCheck) {
                $this->checks->record($check, [[
                    'characteristic' => $characteristic->characteristic,
                    'nominal' => $characteristic->nominal,
                    'lower_limit' => $characteristic->lower_limit,
                    'upper_limit' => $characteristic->upper_limit,
                    'measured_value' => $value,
                    'quality_plan_characteristic_id' => $characteristic->id,
                    'serial' => $serial,
                    'measured_at' => MachineTime::local($sample->sample->ts),
                    'source' => 'machine',
                    'machine_signal_id' => $sample->signal->id,
                ]]);

                $this->checks->resolveWhenComplete($check);
            } else {
                $waiting = UnattributedMeasurement::query()->create([
                    'company_id' => $event->company_id,
                    'signal_id' => $sample->signal->id,
                    'device_id' => $event->device_id,
                    'work_center_id' => $event->work_center_id,
                    'production_order_operation_id' => $sample->production_order_operation_id,
                    'ts' => MachineTime::local($sample->sample->ts),
                    'value' => $value,
                    'serial' => $serial,
                    'context' => $sample->sample->context === [] ? null : $sample->sample->context,
                ]);

                // The check of the operation may have been created while this was being stored: it would not
                // look for a measurement that was not there yet, so look for its check once more.
                $check = $characteristic instanceof QualityPlanCharacteristic ? $this->checkFor($sample->production_order_operation_id, $characteristic) : null;

                if ($check instanceof QualityCheck) {
                    try {
                        $this->assigner->assign($waiting, $check);
                    } catch (DomainException) {
                        // Taken by the attaching of the check meanwhile.
                    }
                }
            }

            if (! $characteristic instanceof QualityPlanCharacteristic || ! $this->isOutOfLimits($characteristic, $value)) {
                return null;
            }

            return new OutOfToleranceMeasured(
                $event->company_id,
                $check?->id,
                $sample->signal->id,
                $characteristic->characteristic,
                $value,
                $characteristic->lower_limit === null ? null : (float) $characteristic->lower_limit,
                $characteristic->upper_limit === null ? null : (float) $characteristic->upper_limit,
            );
        });
    }

    private function alreadyStored(int $signal_id, string $moment): bool
    {
        return UnattributedMeasurement::query()->withoutGlobalScopes()->where('signal_id', $signal_id)->where('ts', $moment)->exists()
            || QualityCheckMeasurement::query()->where('machine_signal_id', $signal_id)->where('measured_at', $moment)->exists();
    }

    /**
     * The check of the operation whose plan holds the characteristic, whatever its status.
     */
    private function checkFor(?int $operation_id, QualityPlanCharacteristic $characteristic): ?QualityCheck
    {
        if ($operation_id === null) {
            return null;
        }

        return QualityCheck::query()
            ->where('production_order_operation_id', $operation_id)
            ->where('quality_plan_id', $characteristic->quality_plan_id)
            ->orderByDesc('id')
            ->first();
    }

    private function isOutOfLimits(QualityPlanCharacteristic $characteristic, float $value): bool
    {
        return ($characteristic->lower_limit !== null && $value < (float) $characteristic->lower_limit)
            || ($characteristic->upper_limit !== null && $value > (float) $characteristic->upper_limit);
    }
}
