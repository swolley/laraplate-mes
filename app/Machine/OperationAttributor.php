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
    /**
     * @var array{company_id: int, from: CarbonImmutable, to: CarbonImmutable, by_work_center: array<int, list<array{id: int, order_id: int, start: CarbonImmutable, end: ?CarbonImmutable}>>}|null
     */
    private ?array $prepared = null;

    /**
     * @var array<string, ?ProductionOrder>
     */
    private array $orders = [];

    /**
     * Loads, in one query, the operations that may attribute the samples of a message, so that
     * attributing thousands of samples costs no further query.
     *
     * @param  list<int>  $work_center_ids
     */
    public function prepare(int $company_id, array $work_center_ids, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $from = $from->setTimezone(config()->string('app.timezone'));
        $to = $to->setTimezone(config()->string('app.timezone'));
        $by_work_center = array_fill_keys($work_center_ids, []);

        $operations = ProductionOrderOperation::query()
            ->withoutGlobalScopes()
            ->whereIn('work_center_id', $work_center_ids)
            ->whereHas('productionOrder', static fn (Builder $orders): Builder => $orders->withoutGlobalScopes()->where('company_id', $company_id))
            ->where('actual_start_at', '<=', $to)
            ->where(static fn (Builder $query): Builder => $query->whereNull('actual_end_at')->orWhere('actual_end_at', '>', $from))
            ->get();

        foreach ($operations as $operation) {
            if ($operation->actual_start_at === null) {
                continue;
            }

            $by_work_center[$operation->work_center_id][] = [
                'id' => $operation->id,
                'order_id' => $operation->production_order_id,
                'start' => CarbonImmutable::instance($operation->actual_start_at),
                'end' => $operation->actual_end_at === null ? null : CarbonImmutable::instance($operation->actual_end_at),
            ];
        }

        $this->prepared = ['company_id' => $company_id, 'from' => $from, 'to' => $to, 'by_work_center' => $by_work_center];
    }

    public function attribute(ResolvedSignal $target, NormalizedSample $sample, ?string $order_reference = null, ?string $operation_reference = null): Attribution
    {
        $operation_ref = $sample->context['operation_ref'] ?? $operation_reference;
        $order_ref = $sample->context['order_ref'] ?? $order_reference;
        $ts = $sample->ts->setTimezone(config()->string('app.timezone'));

        // Nothing has happened yet at a time that has not come: a sender clock far ahead attributes to none.
        if ($ts > CarbonImmutable::now()->addSeconds(config()->integer('mes.machine.clock_skew_seconds'))) {
            return Attribution::none();
        }

        if ($operation_ref !== null || $order_ref !== null) {
            $id = $this->explicit($target, $ts, $order_ref, $operation_ref);

            return $id === null ? Attribution::none() : new Attribution($id, Attribution::EXPLICIT);
        }

        $id = $this->singleActive($target, $ts, null);

        return $id === null ? Attribution::none() : new Attribution($id, Attribution::SINGLE_ACTIVE);
    }

    private function explicit(ResolvedSignal $target, CarbonImmutable $ts, ?string $order_ref, ?string $operation_ref): ?int
    {
        $order = $order_ref === null ? null : ($this->orders[$target->company_id . '|' . $order_ref] ??= ProductionOrder::query()
            ->withoutGlobalScopes()
            ->where('company_id', $target->company_id)
            ->where('number', $order_ref)
            ->first());

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
        $prepared = $this->prepared;

        if ($prepared !== null && $prepared['company_id'] === $target->company_id && isset($prepared['by_work_center'][$target->work_center_id]) && $ts >= $prepared['from'] && $ts <= $prepared['to']) {
            $active = array_values(array_filter(
                $prepared['by_work_center'][$target->work_center_id],
                static fn (array $operation): bool => $operation['start'] <= $ts
                    && ($operation['end'] === null || $operation['end'] > $ts)
                    && ($order_id === null || $operation['order_id'] === $order_id),
            ));

            return count($active) === 1 ? $active[0]['id'] : null;
        }

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
