<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\ERP\Models\Warehouse;

/**
 * Resolves the receiving warehouse for a production order auto-created from a
 * sales order, which carries no warehouse of its own.
 *
 * Precedence:
 *   1. the per-company map in config `mes.production.default_warehouse`, when it
 *      points to a warehouse owned by the company;
 *   2. the company's sole warehouse, when it owns exactly one;
 *   3. unresolved (null) — the caller skips the line rather than guessing.
 */
final class ProductionWarehouseResolver
{
    public function resolve(int $company_id): ?int
    {
        return $this->fromConfig($company_id) ?? $this->soleWarehouse($company_id);
    }

    private function fromConfig(int $company_id): ?int
    {
        /** @var array<int|string, int|string> $map */
        $map = config('mes.production.default_warehouse', []);
        $warehouse_id = $map[$company_id] ?? null;

        if ($warehouse_id === null) {
            return null;
        }

        $exists = Warehouse::query()
            ->withoutGlobalScopes()
            ->whereKey($warehouse_id)
            ->where('company_id', $company_id)
            ->exists();

        return $exists ? (int) $warehouse_id : null;
    }

    private function soleWarehouse(int $company_id): ?int
    {
        $warehouses = Warehouse::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company_id)
            ->limit(2)
            ->pluck('id');

        return $warehouses->count() === 1 ? (int) $warehouses->first() : null;
    }
}
