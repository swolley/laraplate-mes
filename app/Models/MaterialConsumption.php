<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\Warehouse;
use Modules\MES\Database\Factories\MaterialConsumptionFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $production_order_id
 * @property int|null $production_order_operation_id
 * @property int $item_id
 * @property int $warehouse_id
 * @property string $quantity_planned
 * @property string $quantity_consumed
 * @property string $variance
 * @property string $uom
 * @property bool $is_backflush
 * @property bool $stock_shortage
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperMaterialConsumption
 */
final class MaterialConsumption extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_material_consumptions';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'production_order_id',
        'production_order_operation_id',
        'item_id',
        'warehouse_id',
        'quantity_planned',
        'quantity_consumed',
        'variance',
        'uom',
        'is_backflush',
        'stock_shortage',
        'recorded_at',
    ];

    /**
     * The production order this consumption belongs to.
     *
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * The operation that triggered this consumption, when backflushed.
     *
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * The consumed component item.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The warehouse the component was drawn from.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<MaterialConsumption>
     */
    protected static function newFactory(): Factory
    {
        return MaterialConsumptionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity_planned' => 'decimal:4',
            'quantity_consumed' => 'decimal:4',
            'variance' => 'decimal:4',
            'is_backflush' => 'boolean',
            'stock_shortage' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }
}
