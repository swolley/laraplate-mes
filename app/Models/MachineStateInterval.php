<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\MachineStateIntervalFactory;
use Modules\MES\Enums\MachineState;
use Override;

/**
 * A stretch of time a machine spent in one state; the full history of states of a device. An open
 * interval (no `ended_at`) is the current state.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int $device_id
 * @property int $work_center_id
 * @property MachineState $state
 * @property string|null $alarm_code
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
final class MachineStateInterval extends Model
{
    /** @use HasFactory<MachineStateIntervalFactory> */
    use BelongsToCompany, HasFactory;

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
    protected $table = 'mes_machine_state_intervals';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'device_id',
        'work_center_id',
        'state',
        'alarm_code',
        'started_at',
        'ended_at',
    ];

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'device_id');
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @param  Builder<MachineStateInterval>  $query
     * @return Builder<MachineStateInterval>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return Factory<MachineStateInterval>
     */
    protected static function newFactory(): Factory
    {
        return MachineStateIntervalFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'state' => MachineState::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
