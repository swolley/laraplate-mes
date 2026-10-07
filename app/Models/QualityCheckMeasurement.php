<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\QualityCheckMeasurementFactory;
use Override;

final class QualityCheckMeasurement extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_quality_check_measurements';

    /**
     * Machine measurements keep their milliseconds.
     *
     * @var string
     */
    #[Override]
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'quality_check_id',
        'characteristic',
        'nominal',
        'lower_limit',
        'upper_limit',
        'measured_value',
        'is_within_limits',
        'quality_plan_characteristic_id',
        'serial',
        'measured_at',
        'source',
        'machine_signal_id',
    ];

    /**
     * @return BelongsTo<QualityCheck, $this>
     */
    public function qualityCheck(): BelongsTo
    {
        return $this->belongsTo(QualityCheck::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<QualityCheckMeasurement>
     */
    protected static function newFactory(): Factory
    {
        return QualityCheckMeasurementFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:4',
            'lower_limit' => 'decimal:4',
            'upper_limit' => 'decimal:4',
            'measured_value' => 'decimal:4',
            'is_within_limits' => 'boolean',
            'measured_at' => 'datetime',
        ];
    }
}
