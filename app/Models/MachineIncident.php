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
use Modules\MES\Database\Factories\MachineIncidentFactory;
use Modules\MES\Enums\MachineIncidentType;
use Override;

/**
 * @property int $id
 * @property int $company_id
 * @property int $source_id
 * @property ?int $device_id
 * @property MachineIncidentType $type
 * @property array<string, mixed> $detail
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property ?\Illuminate\Support\Carbon $resolved_at
 */
final class MachineIncident extends Model
{
    /** @use HasFactory<MachineIncidentFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_incidents';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'source_id',
        'device_id',
        'type',
        'detail',
        'occurred_at',
        'resolved_at',
    ];

    /**
     * @return BelongsTo<MachineSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MachineSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'device_id');
    }

    /**
     * @return Factory<MachineIncident>
     */
    protected static function newFactory(): Factory
    {
        return MachineIncidentFactory::new();
    }

    /**
     * @param  Builder<MachineIncident>  $query
     * @return Builder<MachineIncident>
     */
    #[Scope]
    protected function unresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => MachineIncidentType::class,
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
