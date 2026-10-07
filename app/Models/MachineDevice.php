<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\MachineDeviceFactory;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\SignalRole;
use Override;

/**
 * @property int $id
 * @property int|null $company_id
 * @property int|null $source_id
 * @property string $external_id
 * @property int $work_center_id
 * @property ?int $machine_profile_id
 * @property ?string $profile_version
 * @property ?\Illuminate\Support\Carbon $last_seen_at
 * @property bool $is_active
 */
final class MachineDevice extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_devices';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'source_id',
        'external_id',
        'work_center_id',
        'machine_profile_id',
        'profile_version',
        'last_seen_at',
        'is_active',
    ];

    #[Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saving(static function (self $device): void {
            if (! $device->is_active || ! $device->isDirty(['is_active', 'work_center_id'])) {
                return;
            }

            $has_state = MachineSignal::query()->withoutGlobalScopes()->where('device_id', $device->id)->where('role', SignalRole::State->value)->exists();
            $taken = $has_state && self::query()
                ->withoutGlobalScopes()
                ->where('work_center_id', $device->work_center_id)
                ->where('is_active', true)
                ->whereKeyNot($device->id)
                ->whereIn('id', MachineSignal::query()->withoutGlobalScopes()->where('role', SignalRole::State->value)->whereNull('deleted_at')->select('device_id'))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['work_center_id' => ['Another device of this work center already has a state signal.']]);
            }
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $unique = $this->source_id === null ? [] : [
            Rule::unique(MESTables::MachineDevices->value, 'external_id')->where('source_id', $this->source_id)->ignore($this->getKey()),
        ];

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'source_id' => ['required', 'integer', 'exists:' . MESTables::MachineSources->value . ',id'],
            'external_id' => ['required', 'string', 'max:128', ...$unique],
            'work_center_id' => ['required', 'integer', 'exists:' . MESTables::WorkCenters->value . ',id'],
            'machine_profile_id' => ['nullable', 'integer', 'exists:' . MESTables::MachineProfiles->value . ',id'],
            'profile_version' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'external_id' => ['sometimes', 'string', 'max:128', ...$unique],
            'work_center_id' => ['sometimes', 'integer', 'exists:' . MESTables::WorkCenters->value . ',id'],
            'machine_profile_id' => ['nullable', 'integer', 'exists:' . MESTables::MachineProfiles->value . ',id'],
            'profile_version' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * @return BelongsTo<MachineSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MachineSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * @return BelongsTo<MachineProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MachineProfile::class, 'machine_profile_id');
    }

    /**
     * @return HasMany<MachineSignal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(MachineSignal::class, 'device_id');
    }

    /**
     * @return Factory<MachineDevice>
     */
    protected static function newFactory(): Factory
    {
        return MachineDeviceFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
