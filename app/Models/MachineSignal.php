<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\MachineSignalFactory;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Override;

final class MachineSignal extends Model
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
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $unique = $this->device_id === null ? [] : [
            Rule::unique(MESTables::MachineSignals->value, 'key')->where('device_id', $this->device_id)->ignore($this->getKey()),
        ];
        $role_rules = $this->roleRules();

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'device_id' => ['required', 'integer', 'exists:' . MESTables::MachineDevices->value . ',id'],
            'key' => ['required', 'string', 'max:128', ...$unique],
            'role' => ['required', 'string', SignalRole::validationRule()],
            'data_type' => ['required', 'string', 'in:number,boolean,string'],
            'unit' => ['nullable', 'string', 'max:16'],
        ], $role_rules);

        $rules['update'] = array_merge($rules['update'], [
            'key' => ['sometimes', 'string', 'max:128', ...$unique],
            'role' => ['sometimes', 'string', SignalRole::validationRule()],
            'data_type' => ['sometimes', 'string', 'in:number,boolean,string'],
            'unit' => ['nullable', 'string', 'max:16'],
        ], $role_rules);

        return $rules;
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
     * Rules of `config` and of the characteristic link, which depend on the role.
     *
     * @return array<string, array<int, mixed>>
     */
    private function roleRules(): array
    {
        $characteristic = ['nullable', 'integer', 'exists:' . MESTables::QualityPlanCharacteristics->value . ',id'];

        return match ($this->role) {
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
}
