<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\MachineSignalFactory;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\SignalRole;
use Override;

/**
 * @property int $id
 * @property int|null $company_id
 * @property int|null $device_id
 * @property string $key
 * @property SignalRole $role
 * @property string $data_type
 * @property ?string $unit
 * @property array<string, mixed>|null $config
 * @property ?int $quality_plan_characteristic_id
 */
final class MachineSignal extends Model implements IsPartOfParent
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_signals';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'device_id',
        'key',
        'role',
        'data_type',
        'unit',
        'config',
        'quality_plan_characteristic_id',
    ];

    /**
     * Rules of `config` and of the characteristic link, which depend on the role.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesForRole(?SignalRole $role): array
    {
        $characteristic = ['nullable', 'integer', 'exists:' . MESTables::QualityPlanCharacteristics->value . ',id'];

        return match ($role) {
            SignalRole::State => [
                'config' => ['required', 'array'],
                'config.map' => ['required', 'array', 'min:1'],
                'config.map.*' => ['string', Rule::in(MachineState::values())],
                'quality_plan_characteristic_id' => ['prohibited'],
            ],
            SignalRole::Alarm => [
                'config' => ['required', 'array'],
                'config.map' => ['required', 'array', 'min:1'],
                'config.map.*' => ['string', Rule::in(DowntimeCause::values())],
                'quality_plan_characteristic_id' => ['prohibited'],
            ],
            SignalRole::GoodCount, SignalRole::ScrapCount, SignalRole::TotalCount => [
                'config' => ['required', 'array'],
                'config.mode' => ['required', 'string', 'in:cumulative,delta'],
                'config.rollover_max' => ['nullable', 'integer', 'min:1'],
                'quality_plan_characteristic_id' => ['prohibited'],
            ],
            SignalRole::ProcessValue => [
                'config' => ['nullable', 'array'],
                'config.min' => ['nullable', 'numeric'],
                'config.max' => ['nullable', 'numeric', 'gte:config.min'],
                'quality_plan_characteristic_id' => ['prohibited'],
            ],
            SignalRole::Measurement => [
                'config' => ['nullable', 'array', 'max:0'],
                'quality_plan_characteristic_id' => ['required', ...$characteristic],
            ],
            default => [
                'config' => ['nullable', 'array', 'max:0'],
                'quality_plan_characteristic_id' => ['prohibited'],
            ],
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $unique = $this->device_id === null ? [] : [
            Rule::unique(MESTables::MachineSignals->value, 'key')->where('device_id', $this->device_id)->ignore($this->getKey()),
        ];
        $role_rules = self::rulesForRole($this->role);

        if ($this->role === SignalRole::State) {
            $role_rules['device_id'] = ['required', 'integer', 'exists:' . MESTables::MachineDevices->value . ',id', $this->oneStateDevicePerWorkCenter()];
        }

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'device_id' => ['required', 'integer', 'exists:' . MESTables::MachineDevices->value . ',id'],
            'key' => ['required', 'string', 'max:160', ...$unique],
            'role' => ['required', 'string', SignalRole::validationRule()],
            'data_type' => ['required', 'string', 'in:number,boolean,string'],
            'unit' => ['nullable', 'string', 'max:16'],
        ], $role_rules);

        $rules['update'] = array_merge($rules['update'], [
            'key' => ['sometimes', 'string', 'max:160', ...$unique],
            'role' => ['sometimes', 'string', SignalRole::validationRule()],
            'data_type' => ['sometimes', 'string', 'in:number,boolean,string'],
            'unit' => ['nullable', 'string', 'max:16'],
        ], $role_rules);

        return $rules;
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'device';
    }

    /**
     * @return BelongsTo<MachineDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MachineDevice::class, 'device_id');
    }

    /**
     * @return BelongsTo<QualityPlanCharacteristic, $this>
     */
    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(QualityPlanCharacteristic::class, 'quality_plan_characteristic_id');
    }

    /**
     * @return Factory<MachineSignal>
     */
    protected static function newFactory(): Factory
    {
        return MachineSignalFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'role' => SignalRole::class,
            'config' => 'array',
        ];
    }

    /**
     * Two devices with a state signal on one work center would overlap their stops and count them twice:
     * the work center keeps one. An inactive device is checked when it is activated (see the device model).
     */
    private function oneStateDevicePerWorkCenter(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $device = MachineDevice::query()->withoutGlobalScopes()->find($value);

            if (! $device instanceof MachineDevice || ! $device->is_active) {
                return;
            }

            $taken = self::query()
                ->withoutGlobalScopes()
                ->where('role', SignalRole::State->value)
                ->whereNull('deleted_at')
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->whereIn('device_id', MachineDevice::query()->withoutGlobalScopes()->where('work_center_id', $device->work_center_id)->where('is_active', true)->whereNull('deleted_at')->where('id', '!=', $device->id)->select('id'))
                ->exists();

            if ($taken) {
                $fail('Another device of this work center already has a state signal.');
            }
        };
    }
}
