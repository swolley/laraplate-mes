<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\MachineCountFactory;
use Override;

/**
 * The delta one count signal reported at one moment. The column of the signal's role holds it (the other
 * two are 0); `raw_value` is what the machine sent, kept so the next delta can be computed.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int $signal_id
 * @property int $device_id
 * @property int $work_center_id
 * @property int|null $production_order_operation_id
 * @property \Illuminate\Support\Carbon $ts
 * @property string $good
 * @property string $scrap
 * @property string $total
 * @property string $raw_value
 */
final class MachineCount extends Model implements IsPartOfParent
{
    /** @use HasFactory<MachineCountFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * Times keep their milliseconds, like the state intervals.
     *
     * @var string
     */
    #[Override]
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_counts';

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
        'good',
        'scrap',
        'total',
        'raw_value',
    ];

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'signal';
    }

    /**
     * @return BelongsTo<MachineSignal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(MachineSignal::class, 'signal_id');
    }

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'device_id');
    }

    /**
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return Factory<MachineCount>
     */
    protected static function newFactory(): Factory
    {
        return MachineCountFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'good' => 'decimal:4',
            'scrap' => 'decimal:4',
            'total' => 'decimal:4',
            'raw_value' => 'decimal:4',
        ];
    }
}
