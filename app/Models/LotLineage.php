<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\LotLineageFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $parent_lot_id
 * @property int $child_lot_id
 * @property int|null $production_order_id
 * @property string|null $quantity
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperLotLineage
 */
final class LotLineage extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_lot_lineages';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'parent_lot_id',
        'child_lot_id',
        'production_order_id',
        'quantity',
    ];

    /**
     * The consumed (input) lot.
     *
     * @return BelongsTo<LotNumber, $this>
     */
    public function parentLot(): BelongsTo
    {
        return $this->belongsTo(LotNumber::class, 'parent_lot_id');
    }

    /**
     * The produced (output) lot.
     *
     * @return BelongsTo<LotNumber, $this>
     */
    public function childLot(): BelongsTo
    {
        return $this->belongsTo(LotNumber::class, 'child_lot_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<LotLineage>
     */
    protected static function newFactory(): Factory
    {
        return LotLineageFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }
}
