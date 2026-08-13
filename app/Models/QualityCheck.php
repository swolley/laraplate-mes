<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\QualityCheckFactory;
use Modules\MES\Enums\QualityCheckStatus;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int|null $production_order_operation_id
 * @property int $item_id
 * @property string $name
 * @property QualityCheckStatus $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperQualityCheck
 */
final class QualityCheck extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_quality_checks';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'production_order_id',
        'production_order_operation_id',
        'item_id',
        'name',
        'status',
        'notes',
        'checked_at',
    ];

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<QualityCheckMeasurement, $this>
     */
    public function measurements(): HasMany
    {
        return $this->hasMany(QualityCheckMeasurement::class);
    }

    /**
     * @return HasMany<NonConformance, $this>
     */
    public function nonConformances(): HasMany
    {
        return $this->hasMany(NonConformance::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<QualityCheck>
     */
    protected static function newFactory(): Factory
    {
        return QualityCheckFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => QualityCheckStatus::class,
            'checked_at' => 'datetime',
        ];
    }
}
