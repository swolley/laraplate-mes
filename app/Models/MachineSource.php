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
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Machine\Mqtt\MqttMessageRouter;
use Override;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $code
 * @property string $name
 * @property string $normalizer
 * @property MachineTransport $transport
 * @property ?string $mqtt_topic
 * @property array<string, mixed>|null $normalizer_options
 * @property string $protocol_version
 * @property int $heartbeat_timeout_seconds
 * @property ?\Illuminate\Support\Carbon $last_seen_at
 * @property ?int $last_seq
 * @property bool $is_active
 */
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
        // Only a canonical source has a topic to derive; any other normaliser needs its topic given.
        $topic_required = Rule::requiredIf(fn (): bool => $this->transport === MachineTransport::Mqtt && $this->normalizer !== 'canonical');
        $unique = $this->company_id === null ? [] : [
            Rule::unique(MESTables::MachineSources->value, 'code')->where('company_id', $this->company_id)->ignore($this->getKey()),
        ];

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'code' => ['required', 'string', 'max:64', ...$unique],
            'name' => ['required', 'string', 'max:255'],
            'normalizer' => ['sometimes', 'string', 'max:64'],
            'transport' => ['sometimes', 'string', MachineTransport::validationRule()],
            'mqtt_topic' => [$topic_required, 'nullable', 'string', 'max:255'],
            'normalizer_options' => ['nullable', 'array'],
            'heartbeat_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'code' => ['sometimes', 'string', 'max:64', ...$unique],
            'name' => ['sometimes', 'string', 'max:255'],
            'normalizer' => ['sometimes', 'string', 'max:64'],
            'transport' => ['sometimes', 'string', MachineTransport::validationRule()],
            'mqtt_topic' => [$topic_required, 'nullable', 'string', 'max:255'],
            'normalizer_options' => ['nullable', 'array'],
            'heartbeat_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    #[Override]
    protected static function booted(): void
    {
        static::saving(static function (self $source): void {
            $source->assertTopicIsUsable();
        });
    }

    /**
     * An mqtt source needs a valid topic filter that no other source can also match, in any company and
     * whatever the other's state: the topic is what tells the sources apart, and an overlap would send one
     * company's machines to another's source, or to nobody.
     *
     * @throws ValidationException
     */
    private function assertTopicIsUsable(): void
    {
        if ($this->transport !== MachineTransport::Mqtt) {
            return;
        }

        $topic = $this->effectiveMqttTopic();

        if ($topic === '') {
            return;
        }

        if (! MqttMessageRouter::isValidFilter($topic)) {
            throw ValidationException::withMessages(['mqtt_topic' => ['The topic is not a valid MQTT topic filter.']]);
        }

        foreach (self::query()->withoutGlobalScopes()->where('transport', MachineTransport::Mqtt->value)->whereNull('deleted_at')->get() as $other) {
            if ($other->getKey() !== $this->getKey() && MqttMessageRouter::overlaps($topic, $other->effectiveMqttTopic())) {
                throw ValidationException::withMessages(['mqtt_topic' => ["The topic overlaps the one of the source {$other->code}."]]);
            }
        }
    }

    /**
     * The topic this source's messages arrive on: the configured one, or for a canonical source
     * `{topic_prefix}/laraplate-machine/1/{code}`. Empty when there is none to derive.
     */
    public function effectiveMqttTopic(): string
    {
        if (is_string($this->mqtt_topic) && $this->mqtt_topic !== '') {
            return $this->mqtt_topic;
        }

        return $this->normalizer === 'canonical'
            ? config()->string('mes.machine.mqtt.topic_prefix') . '/laraplate-machine/1/' . $this->code
            : '';
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
