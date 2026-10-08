<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\ProcessSampleFactory;
use Modules\MES\Enums\SampleQuality;
use Override;

/**
 * One reading of a process value signal, as the relational store keeps it. A `bad` sample is kept but left out
 * of the aggregates and summaries.
 *
 * @property int $id
 * @property int $company_id
 * @property int $signal_id
 * @property int $device_id
 * @property int $work_center_id
 * @property int|null $production_order_operation_id
 * @property \Illuminate\Support\Carbon $ts
 * @property string $value
 * @property SampleQuality $quality
 */
final class ProcessSample extends Model
{
    /** @use HasFactory<ProcessSampleFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * Times keep their milliseconds.
     *
     * @var string
     */
    #[Override]
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_process_samples';

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
        'quality',
    ];

    /**
     * @return BelongsTo<MachineSignal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(MachineSignal::class, 'signal_id');
    }

    /**
     * @return Factory<ProcessSample>
     */
    protected static function newFactory(): Factory
    {
        return ProcessSampleFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'value' => 'decimal:6',
            'quality' => SampleQuality::class,
        ];
    }
}
