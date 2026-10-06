<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use JsonException;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;

/**
 * What the inbox stores for a message whose payload is binary and whose topic matters (Sparkplug B):
 * JSON `{"topic": ..., "payload_base64": ...}`.
 */
final class MqttPayloadEnvelope
{
    public static function wrap(string $topic, string $payload): string
    {
        return json_encode(['topic' => $topic, 'payload_base64' => base64_encode($payload)], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{topic: string, payload: string}
     *
     * @throws UnreadableMachinePayload
     */
    public static function unwrap(string $stored): array
    {
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnreadableMachinePayload('The stored MQTT message is not valid JSON.', 0, $jsonException);
        }

        $topic = is_array($decoded) ? ($decoded['topic'] ?? null) : null;
        $encoded = is_array($decoded) ? ($decoded['payload_base64'] ?? null) : null;
        $payload = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (! is_string($topic) || $payload === false) {
            throw new UnreadableMachinePayload('The stored MQTT message has no topic or payload.');
        }

        return ['topic' => $topic, 'payload' => $payload];
    }
}
