<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\ProcessAggregateFactory;
use Override;

/**
 * The minimum, maximum, average, last value and sample count of one signal in one minute (`1m`) or hour (`1h`).
 *
 * @property int $id
 * @property int $company_id
 * @property int $signal_id
 * @property string $resolution
 * @property \Illuminate\Support\Carbon $bucket_start
 * @property string $min
 * @property string $max
 * @property string $avg
 * @property string $last
 * @property int $count
 */
final class ProcessAggregate extends Model implements IsPartOfParent
{
    /** @use HasFactory<ProcessAggregateFactory> */
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
    protected $table = 'mes_process_aggregates';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'signal_id',
        'resolution',
        'bucket_start',
        'min',
        'max',
        'avg',
        'last',
        'count',
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
     * @return Factory<ProcessAggregate>
     */
    protected static function newFactory(): Factory
    {
        return ProcessAggregateFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'min' => 'decimal:6',
            'max' => 'decimal:6',
            'avg' => 'decimal:6',
            'last' => 'decimal:6',
            'count' => 'integer',
        ];
    }
}
