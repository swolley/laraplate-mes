<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

use Carbon\CarbonImmutable;
use Modules\MES\Machine\Data\DeviceNotice;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Mqtt\MqttPayloadEnvelope;
use Modules\MES\Machine\Normalizers\MachineMessageNormalizer;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSource;

/**
 * Reads Sparkplug B messages the bridge stored with their topic (see {@see MqttPayloadEnvelope}).
 *
 * A device is the edge node (`node`) for node-level messages and `node/device` for device-level ones.
 * Births declare the signals and the alias-to-name map; data may carry only the alias, and one that
 * is not known yet becomes the signal `alias#{n}`. Control metrics (`bdSeq`, `Node Control/...`,
 * `Device Control/...`), null metrics and datatypes without a scalar value are not signals. The
 * Sparkplug `seq` wraps 0 to 255 and is not a source sequence, so none is reported.
 */
final class SparkplugBNormalizer implements MachineMessageNormalizer
{
    public function __construct(
        private readonly SparkplugPayloadDecoder $decoder,
        private readonly SparkplugAliasStore $aliases,
    ) {}

    public function key(): string
    {
        return 'sparkplug_b';
    }

    public function meta(MachineSource $source, string $payload): MessageMeta
    {
        ['topic' => $topic, 'payload' => $bytes] = MqttPayloadEnvelope::unwrap($payload);

        if (! SparkplugTopic::parse($topic) instanceof SparkplugTopic) {
            return new MessageMeta("sparkplug:ignored:{$topic}:" . sha1($bytes));
        }

        try {
            $decoded = $this->decoder->decode($bytes);
        } catch (UnreadableMachinePayload) {
            // Stored all the same: the job marks it failed, and it can be reprocessed.
            return new MessageMeta("sparkplug:{$topic}:unreadable:" . sha1($bytes));
        }

        return new MessageMeta(
            message_id: "sparkplug:{$topic}:" . ($decoded->seq ?? '-') . ':' . ($decoded->timestamp_ms ?? '-'),
            sent_at: $decoded->timestamp_ms === null ? null : CarbonImmutable::createFromTimestampMsUTC($decoded->timestamp_ms),
        );
    }

    public function normalize(MachineSource $source, string $payload): NormalizedMessage
    {
        ['topic' => $topic, 'payload' => $bytes] = MqttPayloadEnvelope::unwrap($payload);
        $parsed = SparkplugTopic::parse($topic);

        if (! $parsed instanceof SparkplugTopic || in_array($parsed->type, ['NCMD', 'DCMD'], true)) {
            return new NormalizedMessage();
        }

        $decoded = $this->decoder->decode($bytes);
        $fallback = $decoded->timestamp_ms === null ? CarbonImmutable::now() : CarbonImmutable::createFromTimestampMsUTC($decoded->timestamp_ms);

        return match ($parsed->type) {
            'NBIRTH', 'DBIRTH' => $this->birth($source, $parsed, $decoded, $fallback),
            'NDATA', 'DDATA' => new NormalizedMessage($this->samples($source, $parsed, $decoded, $fallback, null)),
            'NDEATH' => $this->nodeDeath($source, $parsed, $fallback),
            'DDEATH' => new NormalizedMessage([], [new DeviceNotice($parsed->deviceExternalId(), DeviceNotice::DEATH, $fallback)]),
            default => new NormalizedMessage(),
        };
    }

    private function birth(MachineSource $source, SparkplugTopic $topic, SparkplugPayload $payload, CarbonImmutable $fallback): NormalizedMessage
    {
        $device = $topic->deviceExternalId();
        $aliases = [];
        $signals = [];

        foreach ($payload->metrics as $metric) {
            if ($metric->name === null || self::isControl($metric->name)) {
                continue;
            }

            if ($metric->alias !== null) {
                $aliases[$metric->alias] = $metric->name;
            }

            $type = $this->dataType($metric->datatype);

            if ($type !== null) {
                $signals[] = ['signal' => $metric->name, 'data_type' => $type, 'unit' => null];
            }
        }

        $this->aliases->remember($source, $device, $aliases);

        return new NormalizedMessage(
            $this->samples($source, $topic, $payload, $fallback, $aliases),
            [new DeviceNotice($device, DeviceNotice::BIRTH, $fallback, $signals)],
        );
    }

    private function nodeDeath(MachineSource $source, SparkplugTopic $topic, CarbonImmutable $at): NormalizedMessage
    {
        $node = $topic->edge_node;
        $ids = [$node];

        foreach (MachineDevice::query()->withoutGlobalScopes()->where('source_id', $source->id)->whereNull('deleted_at')->get() as $device) {
            if (str_starts_with($device->external_id, $node . '/')) {
                $ids[] = $device->external_id;
            }
        }

        $ids = array_values(array_unique($ids));

        return new NormalizedMessage([], array_map(static fn (string $id): DeviceNotice => new DeviceNotice($id, DeviceNotice::DEATH, $at), $ids));
    }

    /**
     * @param  array<int, string>|null  $known  the aliases a birth has just declared; read from the store when null
     * @return list<NormalizedSample>
     */
    private function samples(MachineSource $source, SparkplugTopic $topic, SparkplugPayload $payload, CarbonImmutable $fallback, ?array $known): array
    {
        $device = $topic->deviceExternalId();
        $aliases = $known ?? $this->aliases->forDevice($source, $device);
        $samples = [];

        foreach ($payload->metrics as $metric) {
            if ($metric->is_null || ! $metric->supported || $metric->value === null) {
                continue;
            }

            $name = $metric->name ?? ($metric->alias === null ? null : ($aliases[$metric->alias] ?? "alias#{$metric->alias}"));

            if ($name === null || self::isControl($name)) {
                continue;
            }

            $ts = $metric->timestamp_ms === null ? $fallback : CarbonImmutable::createFromTimestampMsUTC($metric->timestamp_ms);
            $samples[] = new NormalizedSample($device, $name, $ts, $metric->value);
        }

        return $samples;
    }

    private static function isControl(string $name): bool
    {
        return $name === 'bdSeq' || str_starts_with($name, 'Node Control/') || str_starts_with($name, 'Device Control/');
    }

    private function dataType(int $datatype): ?string
    {
        return match (true) {
            $datatype === 11 => 'boolean',
            $datatype === 12, $datatype === 14, $datatype === 15 => 'string',
            $datatype >= 1 && $datatype <= 10, $datatype === 13 => 'number',
            default => null,
        };
    }
}
