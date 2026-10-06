<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Machine\Data\Attribution;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSignal;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Decides which production order operation a sample belongs to, by the sample time and never by
 * the arrival time, so data delayed by an outage still lands on the right operation.
 *
 * An explicit reference wins: `operation_ref` is an operation id, `order_ref` a production order
 * number, taken from the sample context or else from the reference signals of the message. A
 * reference that does not resolve (unknown, another company's, an operation not of the referenced
 * order) attributes nothing rather than falling back to a guess. Without a reference, the single
 * operation on the work center that was running at the sample time; none or several, nothing.
 */
final class OperationAttributor
{
    public function attribute(ResolvedSignal $target, NormalizedSample $sample, ?string $order_reference = null, ?string $operation_reference = null): Attribution
    {
        $operation_ref = $sample->context['operation_ref'] ?? $operation_reference;
        $order_ref = $sample->context['order_ref'] ?? $order_reference;
        $ts = $sample->ts->setTimezone(config()->string('app.timezone'));

        if ($operation_ref !== null || $order_ref !== null) {
            $id = $this->explicit($target, $ts, $order_ref, $operation_ref);

            return $id === null ? Attribution::none() : new Attribution($id, Attribution::EXPLICIT);
        }

        $id = $this->singleActive($target, $ts, null);

        return $id === null ? Attribution::none() : new Attribution($id, Attribution::SINGLE_ACTIVE);
    }

    private function explicit(ResolvedSignal $target, CarbonImmutable $ts, ?string $order_ref, ?string $operation_ref): ?int
    {
        $order = $order_ref === null ? null : ProductionOrder::query()
            ->withoutGlobalScopes()
            ->where('company_id', $target->company_id)
            ->where('number', $order_ref)
            ->first();

        if ($order_ref !== null && ! $order instanceof ProductionOrder) {
            return null;
        }

        if ($operation_ref === null) {
            return $this->singleActive($target, $ts, $order?->id);
        }

        if (! ctype_digit($operation_ref)) {
            return null;
        }

        $operation = ProductionOrderOperation::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $operation_ref)
            ->whereHas('productionOrder', static fn (Builder $orders): Builder => $orders->withoutGlobalScopes()->where('company_id', $target->company_id))
            ->first();

        if (! $operation instanceof ProductionOrderOperation || ($order instanceof ProductionOrder && $operation->production_order_id !== $order->id)) {
            return null;
        }

        return $operation->id;
    }

    /**
     * The only operation (of the order, when one is given) on the device's work center that was running at `$ts`.
     */
    private function singleActive(ResolvedSignal $target, CarbonImmutable $ts, ?int $order_id): ?int
    {
        $ids = ProductionOrderOperation::query()
            ->withoutGlobalScopes()
            ->where('work_center_id', $target->work_center_id)
            ->whereHas('productionOrder', static fn (Builder $orders): Builder => $orders->withoutGlobalScopes()->where('company_id', $target->company_id))
            ->when($order_id !== null, static fn (Builder $query): Builder => $query->where('production_order_id', $order_id))
            ->where('actual_start_at', '<=', $ts)
            ->where(static fn (Builder $query): Builder => $query->whereNull('actual_end_at')->orWhere('actual_end_at', '>', $ts))
            ->limit(2)
            ->pluck('id');

        $only = $ids->first();

        return $ids->count() === 1 && is_numeric($only) ? (int) $only : null;
    }
}
