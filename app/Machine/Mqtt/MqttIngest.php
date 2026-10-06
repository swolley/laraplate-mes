<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Machine\Data\InboxResult;
use Modules\MES\Machine\MachineIncidentRecorder;
use Modules\MES\Machine\MachineMessageInbox;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Machine\Protocol\MachineEnvelopeValidator;
use Modules\MES\Models\MachineSource;

/**
 * One message from the broker into the inbox. There is nobody to answer, so a message that cannot be
 * stored is dropped: an unknown, inactive or ambiguous topic is only logged (once a minute), an
 * unreadable canonical envelope opens a `message_failed` incident naming the topic.
 */
final class MqttIngest implements MqttMessageHandler
{
    public function __construct(
        private readonly MqttMessageRouter $router,
        private readonly MachineMessageInbox $inbox,
        private readonly MachineEnvelopeValidator $validator,
        private readonly MachineIncidentRecorder $incidents,
    ) {}

    public function handle(MqttMessage $message): ?InboxResult
    {
        $source = $this->router->sourceFor($message->topic);

        if (! $source instanceof MachineSource) {
            if (Cache::add('mes:machine:mqtt-dropped:' . sha1($message->topic), true, 60)) {
                Log::warning('Machine MQTT message dropped: the topic belongs to no single active source.', ['topic' => $message->topic]);
            }

            return null;
        }

        $stored = match ($source->normalizer) {
            'sparkplug_b' => MqttPayloadEnvelope::wrap($message->topic, $message->payload),
            'canonical' => $this->canonical($source, $message),
            default => $message->payload,
        };

        if ($stored === null) {
            return null;
        }

        try {
            return $this->inbox->accept($source, $stored, MachineTransport::Mqtt);
        } catch (UnreadableMachinePayload $unreadable) {
            $this->fail($source, $message->topic, ['payload' => [$unreadable->getMessage()]]);

            return null;
        }
    }

    private function canonical(MachineSource $source, MqttMessage $message): ?string
    {
        try {
            $envelope = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail($source, $message->topic, ['payload' => ['The payload is not valid JSON.']]);

            return null;
        }

        $errors = is_array($envelope) ? $this->validator->validate($envelope) : ['payload' => ['The payload is not a JSON object.']];

        if ($errors !== []) {
            $this->fail($source, $message->topic, $errors);

            return null;
        }

        return $message->payload;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function fail(MachineSource $source, string $topic, array $errors): void
    {
        $this->incidents->recordOnce($source, MachineIncidentType::MessageFailed, ['topic' => $topic, 'errors' => $errors]);
    }
}
