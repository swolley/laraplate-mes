<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\DowntimeFactory;
use Modules\MES\Enums\DowntimeCause;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Machine\MachineConnectivity;
use Override;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_center_id
 * @property int|null $production_order_operation_id
 * @property DowntimeCause $cause
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $duration_minutes
 * @property string|null $notes
 * @property DowntimeSource $source
 * @property int|null $machine_device_id
 * @property string|null $alarm_code
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Downtime extends Model
{
    use HasFactory;

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
    protected $table = 'mes_downtimes';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'work_center_id',
        'production_order_operation_id',
        'cause',
        'started_at',
        'ended_at',
        'duration_minutes',
        'notes',
        'source',
        'machine_device_id',
        'alarm_code',
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'source' => 'manual',
    ];

    private static bool $machine_writes = false;

    /**
     * Runs a callback that may write machine downtimes: only the machine path creates them and moves
     * their times (the operator edits cause and notes). The permission is restored afterwards, even on an exception.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function writingAsMachine(Closure $callback): mixed
    {
        $previous = self::$machine_writes;
        self::$machine_writes = true;

        try {
            return $callback();
        } finally {
            self::$machine_writes = $previous;
        }
    }

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (self $downtime): void {
            if ($downtime->source === DowntimeSource::Machine) {
                if (! self::$machine_writes) {
                    throw ValidationException::withMessages(['source' => ['A machine downtime is written by the machine pipeline only.']]);
                }

                return;
            }

            if (resolve(MachineConnectivity::class)->isConnected((int) $downtime->work_center_id)) {
                throw ValidationException::withMessages(['work_center_id' => ['This work center is connected to a machine: its downtimes come from the machine, not from the keyboard.']]);
            }
        });

        static::updating(static function (self $downtime): void {
            $locked = ['started_at', 'ended_at', 'duration_minutes', 'source'];

            if (self::$machine_writes || $downtime->getOriginal('source') !== DowntimeSource::Machine) {
                return;
            }

            foreach ($locked as $column) {
                if ($downtime->isDirty($column)) {
                    throw ValidationException::withMessages([$column => ['The times of a machine downtime come from the machine and cannot be changed; the cause and the notes can.']]);
                }
            }
        });
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'machine_device_id');
    }

    /**
     * @return BelongsTo<ProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<Downtime>
     */
    protected static function newFactory(): Factory
    {
        return DowntimeFactory::new();
    }

    /**
     * Scope to still-open (not yet ended) downtimes.
     *
     * @param  Builder<Downtime>  $query
     * @return Builder<Downtime>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'cause' => DowntimeCause::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'decimal:4',
            'source' => DowntimeSource::class,
        ];
    }
}
