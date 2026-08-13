<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\BomLineFactory;
use Modules\MES\Enums\ConsumptionMethod;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $bom_id
 * @property int $item_id
 * @property string $quantity
 * @property string $uom
 * @property ConsumptionMethod $consumption_method
 * @property int|null $routing_operation_id
 * @property int $sort_order
 *
 * @mixin IdeHelperBomLine
 */
final class BomLine extends Model
{
    use HasFactory;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_bom_lines';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'bom_id',
        'item_id',
        'quantity',
        'uom',
        'consumption_method',
        'routing_operation_id',
        'sort_order',
    ];

    /**
     * The bill of materials this line belongs to.
     *
     * @return BelongsTo<Bom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /**
     * The component item consumed by this line.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The routing operation that backflushes this line, when set.
     *
     * @return BelongsTo<RoutingOperation, $this>
     */
    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<BomLine>
     */
    protected static function newFactory(): Factory
    {
        return BomLineFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'consumption_method' => ConsumptionMethod::class,
            'routing_operation_id' => 'int',
            'sort_order' => 'int',
        ];
    }
}
