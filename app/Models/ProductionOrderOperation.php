<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\ProductionOrderOperationFactory;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $production_order_id
 * @property int|null $routing_operation_id
 * @property int $work_center_id
 * @property int $sequence
 * @property string $description
 * @property ProductionOrderOperationStatus $status
 * @property int $setup_time_minutes
 * @property string $cycle_time_minutes
 * @property bool $is_parallel
 * @property \Illuminate\Support\Carbon|null $actual_start_at
 * @property \Illuminate\Support\Carbon|null $actual_end_at
 * @property string|null $actual_minutes
 * @property string|null $efficiency
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperProductionOrderOperation
 */
final class ProductionOrderOperation extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_production_order_operations';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'production_order_id',
        'routing_operation_id',
        'work_center_id',
        'sequence',
        'description',
        'status',
        'setup_time_minutes',
        'cycle_time_minutes',
        'is_parallel',
        'actual_start_at',
        'actual_end_at',
        'actual_minutes',
        'efficiency',
    ];

    /**
     * The production order this operation belongs to.
     *
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * The work center this operation runs on.
     *
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<ProductionOrderOperation>
     */
    protected static function newFactory(): Factory
    {
        return ProductionOrderOperationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => ProductionOrderOperationStatus::class,
            'routing_operation_id' => 'int',
            'sequence' => 'int',
            'setup_time_minutes' => 'int',
            'cycle_time_minutes' => 'decimal:4',
            'is_parallel' => 'boolean',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'actual_minutes' => 'decimal:4',
            'efficiency' => 'decimal:2',
        ];
    }
}
