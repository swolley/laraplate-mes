<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Protocol;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Validates a `laraplate-machine/1` envelope. The rules mirror the published JSON
 * Schema (`resources/protocol/laraplate-machine-1.schema.json`); the shared fixtures keep the
 * two, and the agent's validator, from drifting.
 */
final class MachineEnvelopeValidator
{
    public const string PROTOCOL = 'laraplate-machine/1';

    private const string ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * @param  array<mixed>  $envelope
     * @return array<string, list<string>> the errors by field; empty when the envelope is valid
     */
    public function validate(array $envelope): array
    {
        return Validator::make($envelope, $this->rules())->errors()->toArray();
    }

    /**
     * Total number of samples across the `data` devices of an envelope.
     *
     * @param  array<mixed>  $envelope
     */
    public function sampleCount(array $envelope): int
    {
        $count = 0;

        foreach (is_array($envelope['devices'] ?? null) ? $envelope['devices'] : [] as $device) {
            if (is_array($device) && is_array($device['samples'] ?? null)) {
                $count += count($device['samples']);
            }
        }

        return $count;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'protocol' => ['required', Rule::in([self::PROTOCOL])],
            'message_id' => ['required', 'string', 'min:1', 'max:128'],
            'source_seq' => ['required', 'integer:strict', 'min:0'],
            'sent_at' => ['required', 'string', $this->isoDateTime()],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*.device' => ['required', 'string', 'min:1', 'max:128'],
            'devices.*.type' => ['required', Rule::in(['data', 'birth', 'death'])],
            'devices.*.samples' => ['required_if:devices.*.type,data', 'prohibited_unless:devices.*.type,data', 'array', 'min:1'],
            'devices.*.samples.*.signal' => ['required', 'string', 'min:1', 'max:160'],
            'devices.*.samples.*.ts' => ['required', 'string', $this->isoDateTime()],
            'devices.*.samples.*.value' => ['present', $this->scalarValue()],
            'devices.*.samples.*.quality' => ['sometimes', Rule::in(['good', 'uncertain', 'bad'])],
            'devices.*.samples.*.context' => ['sometimes', 'array:order_ref,operation_ref,serial,lot'],
            'devices.*.samples.*.context.*' => ['string'],
            'devices.*.signals' => ['required_if:devices.*.type,birth', 'prohibited_unless:devices.*.type,birth', 'array', 'min:1'],
            'devices.*.signals.*.signal' => ['required', 'string', 'min:1', 'max:160'],
            'devices.*.signals.*.data_type' => ['required', Rule::in(['number', 'boolean', 'string'])],
            'devices.*.signals.*.unit' => ['nullable', 'string', 'max:16'],
        ];
    }

    private function isoDateTime(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match(self::ISO_8601, $value) === 1) {
                try {
                    CarbonImmutable::parse($value);

                    return;
                } catch (Throwable) {
                    // falls through to the failure below
                }
            }

            $fail("The {$attribute} must be an ISO 8601 date-time.");
        };
    }

    private function scalarValue(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_float($value) && ! is_bool($value) && ! is_string($value)) {
                $fail("The {$attribute} must be a number, a boolean or a string.");
            }
        };
    }
}
