<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\MachineSourceFactory;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\MachineTransport;
use Override;

final class MachineSource extends Model
{
    use BelongsToCompany, HasApiTokens;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_sources';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'normalizer',
        'transport',
        'mqtt_topic',
        'normalizer_options',
        'protocol_version',
        'heartbeat_timeout_seconds',
        'last_seen_at',
        'last_seq',
        'is_active',
    ];

    /**
     * Mirrors the column defaults, so a new instance reads them before it is saved.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'normalizer' => 'canonical',
        'transport' => 'http',
        'protocol_version' => '1',
        'heartbeat_timeout_seconds' => 120,
        'is_active' => true,
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $unique = $this->company_id === null ? [] : [
            Rule::unique(MESTables::MachineSources->value, 'code')->where('company_id', $this->company_id)->ignore($this->getKey()),
        ];

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'code' => ['required', 'string', 'max:64', ...$unique],
            'name' => ['required', 'string', 'max:255'],
            'normalizer' => ['sometimes', 'string', 'max:64'],
            'transport' => ['sometimes', 'string', MachineTransport::validationRule()],
            'mqtt_topic' => ['nullable', 'string', 'max:255'],
            'normalizer_options' => ['nullable', 'array'],
            'heartbeat_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'code' => ['sometimes', 'string', 'max:64', ...$unique],
            'name' => ['sometimes', 'string', 'max:255'],
            'normalizer' => ['sometimes', 'string', 'max:64'],
            'transport' => ['sometimes', 'string', MachineTransport::validationRule()],
            'mqtt_topic' => ['nullable', 'string', 'max:255'],
            'normalizer_options' => ['nullable', 'array'],
            'heartbeat_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * @return HasMany<MachineDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(MachineDevice::class, 'source_id');
    }

    /**
     * @return Factory<MachineSource>
     */
    protected static function newFactory(): Factory
    {
        return MachineSourceFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'transport' => MachineTransport::class,
            'normalizer_options' => 'array',
            'heartbeat_timeout_seconds' => 'integer',
            'last_seen_at' => 'datetime',
            'last_seq' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
