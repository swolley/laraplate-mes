<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;

/**
 * Decodes a Sparkplug B `Payload` into the fields the pipeline needs: the payload timestamp and
 * sequence, and each metric's name, alias, time, datatype, flags and scalar value. Field numbers and
 * datatypes are those of the Sparkplug B specification (the `sparkplug_b.proto` of Eclipse Tahu);
 * the fixtures in `tests/Fixtures/machine-protocol/normalizers/sparkplug_b` are encoded from that
 * schema by `protoc`. Fields it does not know are skipped.
 */
final class SparkplugPayloadDecoder
{
    private const int DATATYPE_INT8 = 1;

    private const int DATATYPE_INT16 = 2;

    private const int DATATYPE_INT32 = 3;

    private const int DATATYPE_INT64 = 4;

    private const int DATATYPE_UINT8 = 5;

    private const int DATATYPE_UINT16 = 6;

    private const int DATATYPE_UINT32 = 7;

    private const int DATATYPE_UINT64 = 8;

    private const int DATATYPE_FLOAT = 9;

    private const int DATATYPE_DOUBLE = 10;

    private const int DATATYPE_BOOLEAN = 11;

    private const int DATATYPE_STRING = 12;

    private const int DATATYPE_DATETIME = 13;

    private const int DATATYPE_TEXT = 14;

    private const int DATATYPE_UUID = 15;

    public function decode(string $bytes): SparkplugPayload
    {
        $reader = new ProtobufReader($bytes);
        $timestamp = null;
        $seq = null;
        $metrics = [];

        while (! $reader->eof()) {
            ['field' => $field, 'wire' => $wire] = $reader->readTag();

            match (true) {
                $field === 1 && $wire === ProtobufReader::WIRE_VARINT => $timestamp = $reader->readVarint(),
                $field === 2 && $wire === ProtobufReader::WIRE_LENGTH_DELIMITED => $metrics[] = $this->decodeMetric($reader->readLengthDelimited()),
                $field === 3 && $wire === ProtobufReader::WIRE_VARINT => $seq = $reader->readVarint(),
                in_array($field, [1, 2, 3], true) => throw new UnreadableMachinePayload("Payload field {$field} has an unexpected wire type."),
                default => $reader->skip($wire),
            };
        }

        return new SparkplugPayload($timestamp, $seq, $metrics);
    }

    private function decodeMetric(string $bytes): SparkplugMetric
    {
        $reader = new ProtobufReader($bytes);
        $name = null;
        $alias = null;
        $timestamp = null;
        $datatype = 0;
        $is_null = false;
        $is_historical = false;
        $raw = [];

        while (! $reader->eof()) {
            ['field' => $field, 'wire' => $wire] = $reader->readTag();
            $expected = match ($field) {
                1, 15, 16 => ProtobufReader::WIRE_LENGTH_DELIMITED,
                2, 3, 4, 5, 6, 7, 10, 11, 14 => ProtobufReader::WIRE_VARINT,
                12 => ProtobufReader::WIRE_FIXED32,
                13 => ProtobufReader::WIRE_FIXED64,
                default => null,
            };

            if ($expected === null) {
                $reader->skip($wire);

                continue;
            }

            if ($wire !== $expected) {
                throw new UnreadableMachinePayload("Metric field {$field} has an unexpected wire type.");
            }

            match ($field) {
                1 => $name = $this->text($reader->readLengthDelimited()),
                2 => $alias = $reader->readVarint(),
                3 => $timestamp = $reader->readVarint(),
                4 => $datatype = $reader->readVarint(),
                5 => $is_historical = $reader->readVarint() !== 0,
                7 => $is_null = $reader->readVarint() !== 0,
                10, 11, 14 => $raw[$field] = $reader->readVarint(),
                12 => $raw[$field] = $this->float($reader->readFixed32()),
                13 => $raw[$field] = $this->double($reader->readFixed64()),
                15 => $raw[$field] = $this->text($reader->readLengthDelimited()),
                16 => $raw[$field] = $reader->readLengthDelimited(),
                default => $reader->skip($wire),
            };
        }

        return new SparkplugMetric(
            name: $name,
            alias: $alias,
            timestamp_ms: $timestamp,
            datatype: $datatype,
            is_null: $is_null,
            is_historical: $is_historical,
            value: $this->value($datatype, $raw),
            supported: $datatype >= self::DATATYPE_INT8 && $datatype <= self::DATATYPE_UUID,
        );
    }

    /**
     * The scalar value of a metric by its datatype. Signed integers arrive as unsigned wire values and
     * are converted back; a value the datatype does not carry, or that is not scalar, is null.
     *
     * @param  array<int, int|float|string>  $raw  the value fields present, by field number
     */
    private function value(int $datatype, array $raw): int|float|bool|string|null
    {
        $int = $raw[10] ?? null;
        $long = $raw[11] ?? null;

        return match ($datatype) {
            self::DATATYPE_INT8 => is_int($int) ? $this->signed($int, 8) : $this->wide($long),
            self::DATATYPE_INT16 => is_int($int) ? $this->signed($int, 16) : $this->wide($long),
            self::DATATYPE_INT32 => is_int($int) ? $this->signed($int, 32) : $this->wide($long),
            self::DATATYPE_INT64 => $this->wide($long ?? $int),
            self::DATATYPE_UINT8 => is_int($int) ? $int & 0xFF : null,
            self::DATATYPE_UINT16 => is_int($int) ? $int & 0xFFFF : null,
            self::DATATYPE_UINT32 => is_int($int) ? $int & 0xFFFFFFFF : null,
            self::DATATYPE_UINT64 => is_int($long) ? ($long >= 0 ? $long : sprintf('%u', $long)) : (is_int($int) ? $int & 0xFFFFFFFF : null),
            self::DATATYPE_DATETIME => $this->wide($long),
            self::DATATYPE_FLOAT => is_float($raw[12] ?? null) ? $raw[12] : null,
            self::DATATYPE_DOUBLE => is_float($raw[13] ?? null) ? $raw[13] : null,
            self::DATATYPE_BOOLEAN => is_int($raw[14] ?? null) ? $raw[14] !== 0 : null,
            self::DATATYPE_STRING, self::DATATYPE_TEXT, self::DATATYPE_UUID => is_string($raw[15] ?? null) ? $raw[15] : null,
            default => null,
        };
    }

    private function signed(int $value, int $bits): int
    {
        $masked = $value & ((1 << $bits) - 1);

        return $masked >= (1 << ($bits - 1)) ? $masked - (1 << $bits) : $masked;
    }

    private function wide(int|float|string|null $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function float(string $bytes): float
    {
        $unpacked = unpack('g', $bytes);

        return is_array($unpacked) ? (float) $unpacked[1] : 0.0;
    }

    private function double(string $bytes): float
    {
        $unpacked = unpack('e', $bytes);

        return is_array($unpacked) ? (float) $unpacked[1] : 0.0;
    }

    private function text(string $bytes): string
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new UnreadableMachinePayload('A string field is not valid UTF-8.');
        }

        return $bytes;
    }
}
