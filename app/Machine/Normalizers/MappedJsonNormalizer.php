<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Normalizers;

use Carbon\CarbonImmutable;
use JsonException;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Models\MachineSource;
use Throwable;

/**
 * Reads any JSON a gateway publishes (Node-RED, Kepware IoT Gateway, ...), guided by the
 * dot-notation paths of the source's `normalizer_options`:
 *
 * - `samples_path`: path of a list of samples; absent, the root object is one sample.
 * - per sample: `device`, `signal`, `timestamp`, `value`, and optionally `quality` with a
 *   `quality_map` (raw => good, uncertain, bad); `timestamp_format` is `iso8601` (default),
 *   `unix` or `unix_ms`.
 * - `message_id_path`: path of the message id; absent, the id is the sha1 of the payload.
 *
 * A sample missing its device, signal, timestamp or value is skipped.
 */
final class MappedJsonNormalizer implements MachineMessageNormalizer
{
    public function key(): string
    {
        return 'mapped_json';
    }

    public function meta(MachineSource $source, string $payload): MessageMeta
    {
        $root = $this->decode($payload);
        $path = $this->option($source, 'message_id_path');
        $from_path = is_string($path) ? data_get($root, $path) : null;

        return new MessageMeta(is_scalar($from_path) && (string) $from_path !== '' ? (string) $from_path : sha1($payload));
    }

    public function normalize(MachineSource $source, string $payload): NormalizedMessage
    {
        $root = $this->decode($payload);
        $samples_path = $this->option($source, 'samples_path');
        $items = [$root];

        if (is_string($samples_path)) {
            $items = data_get($root, $samples_path);

            if (! is_array($items) || ! array_is_list($items)) {
                throw new UnreadableMachinePayload("The path [{$samples_path}] is not a list of samples.");
            }
        }

        $samples = [];

        foreach ($items as $item) {
            $sample = is_array($item) ? $this->sample($source, $item) : null;

            if ($sample instanceof NormalizedSample) {
                $samples[] = $sample;
            }
        }

        return new NormalizedMessage($samples);
    }

    /**
     * @param  array<mixed>  $item
     */
    private function sample(MachineSource $source, array $item): ?NormalizedSample
    {
        $device = $this->read($source, $item, 'device');
        $signal = $this->read($source, $item, 'signal');
        $timestamp = $this->read($source, $item, 'timestamp');
        $value = $this->read($source, $item, 'value');

        if (! is_scalar($device) || ! is_scalar($signal) || ! is_scalar($value) || $timestamp === null || (string) $device === '' || (string) $signal === '') {
            return null;
        }

        $format = $this->option($source, 'timestamp_format');
        $ts = $this->timestamp($timestamp, is_string($format) ? $format : 'iso8601');

        if (! $ts instanceof CarbonImmutable) {
            return null;
        }

        return new NormalizedSample((string) $device, (string) $signal, $ts, $value, $this->quality($source, $item));
    }

    private function timestamp(mixed $raw, string $format): ?CarbonImmutable
    {
        try {
            return match ($format) {
                'unix' => is_numeric($raw) ? CarbonImmutable::createFromTimestampUTC((int) $raw) : null,
                'unix_ms' => is_numeric($raw) ? CarbonImmutable::createFromTimestampMsUTC((int) $raw) : null,
                default => is_string($raw) ? CarbonImmutable::parse($raw)->utc() : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $item
     */
    private function quality(MachineSource $source, array $item): SampleQuality
    {
        $raw = $this->read($source, $item, 'quality');

        if (! is_scalar($raw)) {
            return SampleQuality::Good;
        }

        $map = $this->option($source, 'quality_map');
        $mapped = is_array($map) ? ($map[(string) $raw] ?? $raw) : $raw;

        return is_string($mapped) ? (SampleQuality::tryFrom($mapped) ?? SampleQuality::Good) : SampleQuality::Good;
    }

    /**
     * @param  array<mixed>  $item
     */
    private function read(MachineSource $source, array $item, string $option): mixed
    {
        $path = $this->option($source, $option);

        return is_string($path) ? data_get($item, $path) : null;
    }

    private function option(MachineSource $source, string $name): mixed
    {
        $options = $source->normalizer_options;

        return is_array($options) ? ($options[$name] ?? null) : null;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnreadableMachinePayload('The payload is not valid JSON.', 0, $jsonException);
        }

        if (! is_array($decoded)) {
            throw new UnreadableMachinePayload('The payload is not a JSON object.');
        }

        return $decoded;
    }
}
