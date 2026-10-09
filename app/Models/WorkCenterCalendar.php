<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\MES\Database\Factories\WorkCenterCalendarFactory;
use Override;

/**
 * @property int $id
 * @property int $work_center_id
 * @property int $day_of_week 0 = Monday to 6 = Sunday
 * @property string $start_time
 * @property string $end_time
 */
final class WorkCenterCalendar extends Model implements IsPartOfParent
{
    use HasFactory;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_work_center_calendars';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'work_center_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'workCenter';
    }

    /**
     * The work center this calendar slot belongs to.
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
     * @return Factory<WorkCenterCalendar>
     */
    protected static function newFactory(): Factory
    {
        return WorkCenterCalendarFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'day_of_week' => 'int',
        ];
    }
}
