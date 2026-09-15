<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MES\Database\Factories\ShiftInstanceFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $shift_id
 * @property int|null $work_center_id
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperShiftInstance
 */
final class ShiftInstance extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_shift_instances';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'shift_id',
        'work_center_id',
        'date',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @return HasMany<OperatorLog, $this>
     */
    public function operatorLogs(): HasMany
    {
        return $this->hasMany(OperatorLog::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<ShiftInstance>
     */
    protected static function newFactory(): Factory
    {
        return ShiftInstanceFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
