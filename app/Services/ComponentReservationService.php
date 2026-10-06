<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use function Modules\ERP\Helpers\with_company;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\ERP\Enums\StockReservationState;
use Modules\ERP\Exceptions\InsufficientStockException;
use Modules\ERP\Services\Inventory\StockReservationService;
use Modules\ERP\Support\Decimal;
use Modules\MES\Models\ProductionOrder;

/**
 * Holds, frees and closes ERP stock reservations for a production order's BOM
 * material lines. MES depends on ERP (declared dependency), so consuming the
 * ERP reservation service here is intentional; ERP has no knowledge of MES and
 * only sees the opaque {@see self::SOURCE_TYPE} source alias.
 *
 * Every quantity is exact decimal math via {@see Decimal}; the reservation
 * service itself never receives a native float.
 */
final readonly class ComponentReservationService
{
    /**
     * Opaque reservation source alias for a production order's BOM material line.
     * The source id is the frozen snapshot line's `material_line_id`, which is
     * unique to one order (the shared template `bom_line_id` would pool holds
     * across every order built from the same BOM).
     */
    public const string SOURCE_TYPE = 'mes.production_order_material';

    public function __construct(private StockReservationService $reservations) {}

    /**
     * Best-effort hold of each BOM component at release: for every snapshot line
     * with an item, reserve `min(required, company available)` as a hard
     * reservation pinned to the order's warehouse. The quantity is pre-clamped to
     * the current availability so {@see StockReservationService::reserve()} does
     * not raise `InsufficientStockException`; a lost race or a lock timeout from
     * the service is caught and logged per line so one component can never abort
     * the release transition. A short component simply reserves what exists.
     */
    public function reserveForOrder(ProductionOrder $order): void
    {
        $company_id = (int) $order->company_id;
        $warehouse_id = (int) $order->warehouse_id;
        $basis = $this->basis($order);

        with_company($company_id, function () use ($order, $company_id, $warehouse_id, $basis): void {
            foreach ($order->bom_snapshot['lines'] ?? [] as $line) {
                $line_id = $line['material_line_id'] ?? null;
                $item_id = $line['item_id'] ?? null;

                if ($line_id === null || $item_id === null) {
                    continue;
                }

                $required = Decimal::mul(Decimal::format((string) ($line['quantity'] ?? '0')), $basis);
                $quantity = $this->min($required, $this->reservations->available($company_id, (int) $item_id));

                if (Decimal::isZero($quantity) || Decimal::isNegative($quantity)) {
                    continue;
                }

                try {
                    $this->reservations->reserve(
                        $company_id,
                        (int) $item_id,
                        $quantity,
                        StockReservationState::Hard,
                        self::SOURCE_TYPE,
                        (int) $line_id,
                        $warehouse_id,
                    );
                } catch (InsufficientStockException|LockTimeoutException|ValidationException $exception) {
                    Log::warning('MES component reservation failed at release; the component is left unreserved.', [
                        'company_id' => $company_id,
                        'production_order_id' => (int) $order->id,
                        'item_id' => (int) $item_id,
                        'material_line_id' => (int) $line_id,
                        'quantity' => $quantity,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Give back every still-held reservation of the order's material lines.
     * Idempotent: lines that never reserved are skipped by the service.
     */
    public function releaseForOrder(ProductionOrder $order): void
    {
        with_company((int) $order->company_id, function () use ($order): void {
            foreach ($order->bom_snapshot['lines'] ?? [] as $line) {
                $line_id = $line['material_line_id'] ?? null;

                if ($line_id === null) {
                    continue;
                }

                $this->reservations->release(self::SOURCE_TYPE, (int) $line_id);
            }
        });
    }

    /**
     * The quantity this order still holds as a hard reservation for the line.
     * The backflush adds it back to {@see ErpStockReader}
     * availability (which subtracts every warehouse-pinned hold, this order's
     * included) so the order is never blocked from consuming the very stock it
     * reserved for itself; the effective consumable stays `on hand − other
     * orders' holds`.
     */
    public function reservedForLine(ProductionOrder $order, int $lineId): string
    {
        return (string) with_company(
            (int) $order->company_id,
            fn (): string => $this->reservations->reservedQuantity(self::SOURCE_TYPE, $lineId),
        );
    }

    /**
     * Close the line's reservation for the quantity just backflushed, clamped to
     * what is still hard-reserved so it never consumes more than was held. A line
     * that never reserved (zero hard quantity) is a no-op.
     */
    public function consumeForLine(ProductionOrder $order, int $lineId, string $consumedQuantity): void
    {
        with_company((int) $order->company_id, function () use ($lineId, $consumedQuantity): void {
            $quantity = $this->min(
                Decimal::format($consumedQuantity),
                $this->reservations->reservedQuantity(self::SOURCE_TYPE, $lineId),
            );

            if (Decimal::isZero($quantity) || Decimal::isNegative($quantity)) {
                return;
            }

            $this->reservations->consume(self::SOURCE_TYPE, $lineId, $quantity);
        });
    }

    /**
     * The quantity multiplier for a per-unit BOM line: produced quantity once the
     * order has run, otherwise the planned quantity.
     */
    private function basis(ProductionOrder $order): string
    {
        $value = $order->quantity_produced ?? $order->quantity_planned;

        return Decimal::format((string) ($value ?? '0'));
    }

    /**
     * The smaller of two scale-4 decimal strings.
     */
    private function min(string $a, string $b): string
    {
        return Decimal::isNegative(Decimal::sub($a, $b)) ? $a : $b;
    }
}
