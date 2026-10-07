<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\DowntimeFactory;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Override;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_center_id
 * @property int|null $production_order_operation_id
 * @property DowntimeCause $cause
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $duration_minutes
 * @property string|null $notes
 * @property DowntimeSource $source
 * @property int|null $machine_device_id
 * @property string|null $alarm_code
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Downtime extends Model
{
    use HasFactory;

    /**
     * Times keep their milliseconds: a machine downtime has to match its state interval exactly.
     *
     * @var string
     */
    #[Override]
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_downtimes';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'work_center_id',
        'production_order_operation_id',
        'cause',
        'started_at',
        'ended_at',
        'duration_minutes',
        'notes',
        'source',
        'machine_device_id',
        'alarm_code',
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'source' => 'manual',
    ];

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'machine_device_id');
    }

    /**
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<Downtime>
     */
    protected static function newFactory(): Factory
    {
        return DowntimeFactory::new();
    }

    /**
     * Scope to still-open (not yet ended) downtimes.
     *
     * @param  Builder<Downtime>  $query
     * @return Builder<Downtime>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'cause' => DowntimeCause::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'decimal:4',
            'source' => DowntimeSource::class,
        ];
    }
}
