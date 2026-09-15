<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\SalesOrder;
use Modules\ERP\Models\SalesOrderLine;
use Modules\ERP\Models\Warehouse;
use Modules\MES\Database\Factories\ProductionOrderFactory;
use Modules\MES\Enums\ProductionOrderStatus;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property string $number
 * @property int $item_id
 * @property string $quantity_planned
 * @property string|null $quantity_produced
 * @property string|null $quantity_scrapped
 * @property string $uom
 * @property ProductionOrderStatus $status
 * @property \Illuminate\Support\Carbon $planned_start_at
 * @property \Illuminate\Support\Carbon $planned_end_at
 * @property \Illuminate\Support\Carbon|null $actual_start_at
 * @property \Illuminate\Support\Carbon|null $actual_end_at
 * @property int $warehouse_id
 * @property int|null $sales_order_id
 * @property int|null $sales_order_line_id
 * @property array<string, mixed> $bom_snapshot
 * @property array<string, mixed> $routing_snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @mixin IdeHelperProductionOrder
 */
final class ProductionOrder extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_production_orders';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'number',
        'item_id',
        'quantity_planned',
        'quantity_produced',
        'quantity_scrapped',
        'uom',
        'status',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'warehouse_id',
        'sales_order_id',
        'sales_order_line_id',
        'bom_snapshot',
        'routing_snapshot',
    ];

    /**
     * Validation rules for create and update operations.
     *
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'item_id' => ['required', 'integer', 'exists:' . ERPTables::Items->value . ',id'],
            'quantity_planned' => ['required', 'numeric', 'gt:0'],
            'uom' => ['required', 'string', 'max:16'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after_or_equal:planned_start_at'],
            'warehouse_id' => ['required', 'integer', 'exists:' . ERPTables::Warehouses->value . ',id'],
            'sales_order_id' => ['nullable', 'integer', 'exists:' . ERPTables::SalesOrders->value . ',id'],
            'sales_order_line_id' => ['nullable', 'integer', 'exists:' . ERPTables::SalesOrderLines->value . ',id'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'quantity_produced' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'quantity_scrapped' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'planned_start_at' => ['sometimes', 'date'],
            'planned_end_at' => ['sometimes', 'date', 'after_or_equal:planned_start_at'],
        ]);

        return $rules;
    }

    /**
     * The item this production order manufactures.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The warehouse receiving the produced goods.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The originating sales order, when demand-driven.
     *
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * The originating sales order line, when demand-driven.
     *
     * @return BelongsTo<SalesOrderLine, $this>
     */
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<ProductionOrder>
     */
    protected static function newFactory(): Factory
    {
        return ProductionOrderFactory::new();
    }

    /**
     * Scope to a given status.
     *
     * @param  Builder<ProductionOrder>  $query
     * @return Builder<ProductionOrder>
     */
    #[Scope]
    protected function withStatus(Builder $query, ProductionOrderStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => ProductionOrderStatus::class,
            'quantity_planned' => 'decimal:4',
            'quantity_produced' => 'decimal:4',
            'quantity_scrapped' => 'decimal:4',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'bom_snapshot' => 'array',
            'routing_snapshot' => 'array',
        ];
    }
}
