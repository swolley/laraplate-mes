<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\UnattributedMeasurementFactory;
use Override;

/**
 * A probe measurement that has no quality check to go to yet: no operation was attributed, the operation has
 * no check, or the signal has no plan characteristic. It waits to be attached when the check of its operation
 * is created, or to be assigned by hand.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int $signal_id
 * @property int $device_id
 * @property int $work_center_id
 * @property int|null $production_order_operation_id
 * @property \Illuminate\Support\Carbon $ts
 * @property string $value
 * @property string|null $serial
 * @property array<string, mixed>|null $context
 * @property \Illuminate\Support\Carbon|null $assigned_at
 */
final class UnattributedMeasurement extends Model
{
    /** @use HasFactory<UnattributedMeasurementFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_unattributed_measurements';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'signal_id',
        'device_id',
        'work_center_id',
        'production_order_operation_id',
        'ts',
        'value',
        'serial',
        'context',
        'assigned_at',
    ];

    /**
     * @return BelongsTo<MachineSignal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(MachineSignal::class, 'signal_id');
    }

    /**
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return Factory<UnattributedMeasurement>
     */
    protected static function newFactory(): Factory
    {
        return UnattributedMeasurementFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'value' => 'decimal:4',
            'context' => 'array',
            'assigned_at' => 'datetime',
        ];
    }
}
