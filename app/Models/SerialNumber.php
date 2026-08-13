<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\SerialNumberFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int $item_id
 * @property int|null $lot_number_id
 * @property int|null $production_order_id
 * @property int|null $warehouse_id
 * @property string $serial
 * @property \Illuminate\Support\Carbon|null $produced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperSerialNumber
 */
final class SerialNumber extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_serial_numbers';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'item_id',
        'lot_number_id',
        'production_order_id',
        'warehouse_id',
        'serial',
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
     * The lot this serial belongs to, when lot-and-serial traced.
     *
     * @return BelongsTo<LotNumber, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotNumber::class, 'lot_number_id');
    }

    /**
     * The production order that produced this serial.
     *
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<SerialNumber>
     */
    protected static function newFactory(): Factory
    {
        return SerialNumberFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'produced_at' => 'datetime',
        ];
    }
}
