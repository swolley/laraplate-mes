<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\NonConformanceFactory;
use Modules\MES\Enums\NonConformanceDisposition;
use Modules\MES\Enums\NonConformanceStatus;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $production_order_id
 * @property int|null $quality_check_id
 * @property int $item_id
 * @property int|null $rework_production_order_id
 * @property NonConformanceStatus $status
 * @property NonConformanceDisposition|null $disposition
 * @property string $quantity
 * @property string $description
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperNonConformance
 */
final class NonConformance extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_non_conformances';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'production_order_id',
        'quality_check_id',
        'item_id',
        'rework_production_order_id',
        'status',
        'disposition',
        'quantity',
        'description',
        'resolved_at',
    ];

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<QualityCheck, $this>
     */
    public function qualityCheck(): BelongsTo
    {
        return $this->belongsTo(QualityCheck::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The rework production order created when the disposition is rework.
     *
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function reworkOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'rework_production_order_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<NonConformance>
     */
    protected static function newFactory(): Factory
    {
        return NonConformanceFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => NonConformanceStatus::class,
            'disposition' => NonConformanceDisposition::class,
            'quantity' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }
}
