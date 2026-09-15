<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\LotNumberFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int $item_id
 * @property int|null $production_order_id
 * @property int|null $warehouse_id
 * @property string $code
 * @property string $quantity
 * @property \Illuminate\Support\Carbon|null $produced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperLotNumber
 */
final class LotNumber extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_lot_numbers';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'item_id',
        'production_order_id',
        'warehouse_id',
        'code',
        'quantity',
        'produced_at',
    ];

    /**
     * The traced item.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The production order that produced this lot.
     *
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * Lineage rows where this lot is the produced (child) lot.
     *
     * @return HasMany<LotLineage, $this>
     */
    public function parentLinks(): HasMany
    {
        return $this->hasMany(LotLineage::class, 'child_lot_id');
    }

    /**
     * Lineage rows where this lot is the consumed (parent) lot.
     *
     * @return HasMany<LotLineage, $this>
     */
    public function childLinks(): HasMany
    {
        return $this->hasMany(LotLineage::class, 'parent_lot_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<LotNumber>
     */
    protected static function newFactory(): Factory
    {
        return LotNumberFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'produced_at' => 'datetime',
        ];
    }
}
