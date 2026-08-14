<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\QualityPlanCharacteristicFactory;
use Override;

/**
 * A single expected characteristic of a {@see QualityPlan}: the measured trait
 * and its tolerance band. Mirrors {@see QualityCheckMeasurement} so a plan can
 * seed the measurements an operator records at execution time.
 *
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $quality_plan_id
 * @property string $characteristic
 * @property string|null $nominal
 * @property string|null $lower_limit
 * @property string|null $upper_limit
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperQualityPlanCharacteristic
 */
final class QualityPlanCharacteristic extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_quality_plan_characteristics';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'quality_plan_id',
        'characteristic',
        'nominal',
        'lower_limit',
        'upper_limit',
        'sort_order',
    ];

    /**
     * @return BelongsTo<QualityPlan, $this>
     */
    public function qualityPlan(): BelongsTo
    {
        return $this->belongsTo(QualityPlan::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<QualityPlanCharacteristic>
     */
    protected static function newFactory(): Factory
    {
        return QualityPlanCharacteristicFactory::new();
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
            'sort_order' => 'integer',
        ];
    }
}
