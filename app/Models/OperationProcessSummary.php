<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\OperationProcessSummaryFactory;
use Override;

/**
 * What one process signal did while an operation ran. Permanent: the link from a lot to the process
 * parameters it was made with.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_operation_id
 * @property int $signal_id
 * @property string $min
 * @property string $max
 * @property string $avg
 * @property int $count
 * @property int $out_of_range_count
 * @property \Illuminate\Support\Carbon $first_ts
 * @property \Illuminate\Support\Carbon $last_ts
 */
final class OperationProcessSummary extends Model
{
    /** @use HasFactory<OperationProcessSummaryFactory> */
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
    protected $table = 'mes_operation_process_summaries';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'production_order_operation_id',
        'signal_id',
        'min',
        'max',
        'avg',
        'count',
        'out_of_range_count',
        'first_ts',
        'last_ts',
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
     * @return Factory<OperationProcessSummary>
     */
    protected static function newFactory(): Factory
    {
        return OperationProcessSummaryFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'min' => 'decimal:6',
            'max' => 'decimal:6',
            'avg' => 'decimal:6',
            'count' => 'integer',
            'out_of_range_count' => 'integer',
            'first_ts' => 'datetime',
            'last_ts' => 'datetime',
        ];
    }
}
