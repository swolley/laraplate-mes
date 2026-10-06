<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Normalizers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Machine\Data\DeviceNotice;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Models\MachineSource;
use Throwable;

/**
 * Reads the `laraplate-machine/1` envelope as it is, with no translation.
 */
final class CanonicalNormalizer implements MachineMessageNormalizer
{
    public function key(): string
    {
        return 'canonical';
    }

    public function meta(MachineSource $source, string $payload): MessageMeta
    {
        $envelope = $this->decode($payload);

        try {
            return new MessageMeta(
                message_id: Arr::string($envelope, 'message_id'),
                source_seq: isset($envelope['source_seq']) ? Arr::integer($envelope, 'source_seq') : null,
                sent_at: isset($envelope['sent_at']) ? CarbonImmutable::parse(Arr::string($envelope, 'sent_at'))->utc() : null,
            );
        } catch (Throwable $throwable) {
            throw new UnreadableMachinePayload('The envelope metadata is unreadable: ' . $throwable->getMessage(), 0, $throwable);
        }
    }

    public function normalize(MachineSource $source, string $payload): NormalizedMessage
    {
        $envelope = $this->decode($payload);
        $samples = [];
        $notices = [];

        try {
            $sent_at = CarbonImmutable::parse(Arr::string($envelope, 'sent_at'))->utc();

            foreach (Arr::array($envelope, 'devices') as $device) {
                if (! is_array($device)) {
                    throw new UnreadableMachinePayload('A device entry is not an object.');
                }

                $name = Arr::string($device, 'device');

                match (Arr::string($device, 'type')) {
                    'birth' => $notices[] = new DeviceNotice($name, DeviceNotice::BIRTH, $sent_at, $this->birthSignals($device)),
                    'death' => $notices[] = new DeviceNotice($name, DeviceNotice::DEATH, $sent_at),
                    default => $this->collectSamples($name, Arr::array($device, 'samples'), $samples),
                };
            }
        } catch (UnreadableMachinePayload $unreadable) {
            throw $unreadable;
        } catch (Throwable $throwable) {
            throw new UnreadableMachinePayload('The envelope is unreadable: ' . $throwable->getMessage(), 0, $throwable);
        }

        return new NormalizedMessage($samples, $notices);
    }

    /**
     * @param  array<mixed>  $device
     * @return list<array{signal: string, data_type: string, unit: ?string}>
     */
    private function birthSignals(array $device): array
    {
        $signals = [];

        foreach (Arr::array($device, 'signals') as $signal) {
            if (! is_array($signal)) {
                throw new UnreadableMachinePayload('A birth signal is not an object.');
            }

            $signals[] = [
                'signal' => Arr::string($signal, 'signal'),
                'data_type' => Arr::string($signal, 'data_type'),
                'unit' => isset($signal['unit']) ? Arr::string($signal, 'unit') : null,
            ];
        }

        return $signals;
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<NormalizedSample>  $samples
     */
    private function collectSamples(string $device, array $raw, array &$samples): void
    {
        foreach ($raw as $sample) {
            if (! is_array($sample)) {
                throw new UnreadableMachinePayload('A sample is not an object.');
            }

            $value = $sample['value'] ?? null;

            if (! is_int($value) && ! is_float($value) && ! is_bool($value) && ! is_string($value)) {
                throw new UnreadableMachinePayload('A sample value is not a number, a boolean or a string.');
            }

            $context = [];

            foreach (is_array($sample['context'] ?? null) ? $sample['context'] : [] as $key => $entry) {
                if (is_string($key) && is_string($entry)) {
                    $context[$key] = $entry;
                }
            }

            $samples[] = new NormalizedSample(
                device: $device,
                signal: Arr::string($sample, 'signal'),
                ts: CarbonImmutable::parse(Arr::string($sample, 'ts'))->utc(),
                value: $value,
                quality: SampleQuality::from(isset($sample['quality']) ? Arr::string($sample, 'quality') : 'good'),
                context: $context,
            );
        }
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
