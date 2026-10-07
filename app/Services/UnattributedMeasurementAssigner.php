<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;

/**
 * Puts a waiting probe measurement on a quality check: by hand from the backoffice, or through
 * {@see UnattributedMeasurementAttacher} when the check of the measurement's operation is created. The limits
 * are the ones of the plan characteristic; a pending check that becomes complete resolves.
 */
final class UnattributedMeasurementAssigner
{
    public function __construct(
        private readonly QualityCheckService $checks,
    ) {}

    /**
     * @throws DomainException when the row is already assigned, the check belongs to another company, or its
     *                         plan does not hold the characteristic of the row's signal
     */
    public function assign(UnattributedMeasurement $row, QualityCheck $check): QualityCheckMeasurement
    {
        return $check->getConnection()->transaction(function () use ($row, $check): QualityCheckMeasurement {
            $locked = UnattributedMeasurement::query()->withoutGlobalScopes()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            throw_if($locked->assigned_at !== null, new DomainException("Measurement {$locked->id} is already assigned."));
            throw_if((int) $locked->company_id !== (int) $check->company_id, new DomainException('The quality check belongs to another company.'));

            $characteristic_id = $locked->signal?->quality_plan_characteristic_id;
            $characteristic = $characteristic_id === null ? null : QualityPlanCharacteristic::query()->find($characteristic_id);

            throw_if(
                ! $characteristic instanceof QualityPlanCharacteristic || $characteristic->quality_plan_id !== $check->quality_plan_id,
                new DomainException('The plan of the quality check does not hold the characteristic of this signal.'),
            );

            [$measurement] = $this->checks->record($check, [[
                'characteristic' => $characteristic->characteristic,
                'nominal' => $characteristic->nominal,
                'lower_limit' => $characteristic->lower_limit,
                'upper_limit' => $characteristic->upper_limit,
                'measured_value' => $locked->value,
                'quality_plan_characteristic_id' => $characteristic->id,
                'serial' => $locked->serial,
                'measured_at' => MachineTime::local($locked->ts),
                'source' => 'machine',
                'machine_signal_id' => $locked->signal_id,
            ]]);

            $locked->forceFill(['assigned_at' => now()])->save();

            if ($check->refresh()->status === QualityCheckStatus::Pending && $this->checks->isComplete($check)) {
                $this->checks->resolve($check);
            }

            return $measurement;
        });
    }
}
