<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use Modules\MES\Models\OperationQuantityAudit;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * The one place that corrects the declared quantities of an operation: every change leaves an audit row
 * with the old value, the new value and the user. A value equal to the current one changes nothing.
 */
final class OperationQuantityDeclarer
{
    public function declare(ProductionOrderOperation $operation, ?float $good, ?float $scrap, ?int $user_id = null): ProductionOrderOperation
    {
        foreach ([$good, $scrap] as $value) {
            throw_if($value !== null && $value < 0.0, new DomainException('A declared quantity cannot be negative.'));
        }

        return $operation->getConnection()->transaction(function () use ($operation, $good, $scrap, $user_id): ProductionOrderOperation {
            foreach (['declared_good_quantity' => $good, 'declared_scrap_quantity' => $scrap] as $field => $value) {
                if ($value === null) {
                    continue;
                }

                $old = $operation->{$field};

                if ($old !== null && abs((float) $old - $value) < 0.00005) {
                    continue;
                }

                OperationQuantityAudit::query()->create([
                    'operation_id' => $operation->id,
                    'user_id' => $user_id,
                    'field' => $field,
                    'old_value' => $old,
                    'new_value' => $value,
                ]);

                $operation->forceFill([$field => $value]);
            }

            $operation->save();

            return $operation->refresh();
        });
    }
}
