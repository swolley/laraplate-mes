<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Exceptions\BomLockedException;
use Modules\MES\Models\Bom;
use Modules\MES\Models\ProductionOrder;

/**
 * Explodes a bill of materials into its leaf component requirements and guards
 * against edits to BOMs already frozen into a released production order.
 */
final class BomExplosionService
{
    /**
     * Recursively explode the active BOM for an item into leaf components.
     *
     * A line whose item has its own active BOM is expanded further; otherwise
     * it is emitted as a leaf requirement scaled by the parent quantity.
     *
     * @return list<array{item_id: int, quantity: float, uom: string, consumption_method: string, level: int}>
     */
    public function explode(int $item_id, float $quantity, CarbonInterface $on_date, int $level = 0): array
    {
        $bom = $this->getActiveBom($item_id, $on_date);

        if ($bom === null) {
            return [];
        }

        $result = [];

        foreach ($bom->bomLines as $line) {
            $line_quantity = (float) $line->quantity * $quantity;

            if ($this->getActiveBom($line->item_id, $on_date) !== null) {
                foreach ($this->explode($line->item_id, $line_quantity, $on_date, $level + 1) as $child) {
                    $result[] = $child;
                }

                continue;
            }

            $result[] = [
                'item_id' => $line->item_id,
                'quantity' => $line_quantity,
                'uom' => $line->uom,
                'consumption_method' => $line->consumption_method->value,
                'level' => $level,
            ];
        }

        return $result;
    }

    /**
     * Return the active BOM for an item on a given date, or null when none is
     * effective. The most recently effective version wins on overlap.
     */
    public function getActiveBom(int $item_id, CarbonInterface $on_date): ?Bom
    {
        return Bom::query()
            ->where('item_id', $item_id)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $on_date)
            ->where(function (Builder $query) use ($on_date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on_date);
            })
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @throws BomLockedException when a released production order froze this BOM.
     */
    public function assertNotLocked(Bom $bom): void
    {
        $locked = ProductionOrder::query()
            ->where('status', '!=', 'draft')
            ->where('bom_snapshot->id', $bom->id)
            ->exists();

        if ($locked) {
            throw new BomLockedException("BOM {$bom->id} is locked by a released production order.");
        }
    }
}
