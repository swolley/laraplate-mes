<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int $id
 * @property int $company_id
 * @property string $number
 */
final class ProductionOrder extends Model
{
    use BelongsToCompany;

    /**
     * Stride for the per-order material line id: `order_id * STRIDE + line_index`.
     * It keeps every order's material-line ids inside a disjoint range (so holds
     * never pool across orders) and bounds a single order to this many component
     * lines, far above any real BOM, while staying well inside the signed bigint
     * range. {@see self::assertBomWithinStride()} rejects a BOM that would reach
     * the stride, so a collision can never pass unnoticed.
     */
    private const int MATERIAL_LINE_STRIDE = 1000;

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
     * Operations materialised from the routing snapshot on release.
     *
     * @return HasMany<ProductionOrderOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(ProductionOrderOperation::class);
    }

    /**
     * Materials consumed by this order, by backflush or manual record.
     *
     * @return HasMany<MaterialConsumption, $this>
     */
    public function materialConsumptions(): HasMany
    {
        return $this->hasMany(MaterialConsumption::class);
    }

    /**
     * Quality checks raised for this order.
     *
     * @return HasMany<QualityCheck, $this>
     */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    /**
     * Lots produced by this order.
     *
     * @return HasMany<LotNumber, $this>
     */
    public function lotNumbers(): HasMany
    {
        return $this->hasMany(LotNumber::class);
    }

    /**
     * Guard and stamp the frozen BOM snapshot on every creation path (service,
     * factory, import, direct create): reject a snapshot that would overflow the
     * material-line id stride before insert, then stamp each line's per-order
     * `material_line_id` after insert, once the order has its primary key.
     */
    #[Override]
    protected static function booted(): void
    {
        self::creating(static function (self $order): void {
            $order->assertBomWithinStride();
        });

        self::created(static function (self $order): void {
            $order->stampMaterialLineIds();
        });
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

    /**
     * Reject a frozen BOM snapshot that carries {@see self::MATERIAL_LINE_STRIDE}
     * or more component lines: the `order_id * STRIDE + line_index` scheme would
     * spill into the next order's id range and silently pool reservations across
     * orders. Runs before insert, so no row is left behind.
     *
     * @throws DomainException when the snapshot has too many component lines.
     */
    private function assertBomWithinStride(): void
    {
        $snapshot = $this->bom_snapshot ?? [];
        $lines = $snapshot['lines'] ?? [];
        $count = count($lines);

        if ($count >= self::MATERIAL_LINE_STRIDE) {
            throw new DomainException(sprintf(
                'A production order BOM snapshot cannot carry %d or more component lines (got %d): the material_line_id stride would collide with the next order.',
                self::MATERIAL_LINE_STRIDE,
                $count,
            ));
        }
    }

    /**
     * Stamp every frozen BOM snapshot line that lacks one with an id unique to
     * THIS order and stable for its whole lifecycle (reserve at release, release
     * at cancel, consume at backflush). The template `bom_line_id` is shared by
     * every order built from the same BOM, so it cannot key per-order component
     * reservations; this derived id can. A line that already carries a
     * `material_line_id` (e.g. one supplied by a test fixture) is left untouched.
     * The id is assigned after insert because it is seeded from the order's own
     * primary key.
     */
    private function stampMaterialLineIds(): void
    {
        $snapshot = $this->bom_snapshot ?? [];
        $lines = $snapshot['lines'] ?? [];

        if ($lines === []) {
            return;
        }

        $changed = false;

        foreach ($lines as $index => $line) {
            if (($line['material_line_id'] ?? null) !== null) {
                continue;
            }

            $line['material_line_id'] = $this->id * self::MATERIAL_LINE_STRIDE + (int) $index;
            $lines[$index] = $line;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $snapshot['lines'] = $lines;
        $this->forceFill(['bom_snapshot' => $snapshot])->save();
    }
}
