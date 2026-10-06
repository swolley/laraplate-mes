<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;

/**
 * Reads the protobuf wire format from a byte string: just enough of it for Sparkplug B. Anything
 * truncated, overlong or of an unknown wire type is {@see UnreadableMachinePayload}.
 */
final class ProtobufReader
{
    public const int WIRE_VARINT = 0;

    public const int WIRE_FIXED64 = 1;

    public const int WIRE_LENGTH_DELIMITED = 2;

    public const int WIRE_FIXED32 = 5;

    private int $offset = 0;

    private readonly int $length;

    public function __construct(private readonly string $bytes)
    {
        $this->length = strlen($bytes);
    }

    public function eof(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * An unsigned varint of up to 64 bits; one with the top bit set comes back as a negative int.
     */
    public function readVarint(): int
    {
        $result = 0;

        for ($shift = 0; $shift < 70; $shift += 7) {
            if ($this->eof()) {
                throw new UnreadableMachinePayload('The payload ends inside a varint.');
            }

            $byte = ord($this->bytes[$this->offset++]);

            if ($shift === 63 && ($byte & 0x7E) !== 0) {
                throw new UnreadableMachinePayload('A varint is longer than 64 bits.');
            }

            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $result;
            }
        }

        throw new UnreadableMachinePayload('A varint is longer than 10 bytes.');
    }

    /**
     * @return array{field: int, wire: int}
     */
    public function readTag(): array
    {
        $tag = $this->readVarint();

        return ['field' => ($tag >> 3) & 0x1FFFFFFFFFFFFFFF, 'wire' => $tag & 7];
    }

    public function readLengthDelimited(): string
    {
        $length = $this->readVarint();

        if ($length < 0 || $length > $this->length - $this->offset) {
            throw new UnreadableMachinePayload('A length-delimited field runs past the end of the payload.');
        }

        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function readFixed32(): string
    {
        return $this->take(4);
    }

    public function readFixed64(): string
    {
        return $this->take(8);
    }

    public function skip(int $wire): void
    {
        match ($wire) {
            self::WIRE_VARINT => $this->readVarint(),
            self::WIRE_FIXED64 => $this->take(8),
            self::WIRE_LENGTH_DELIMITED => $this->readLengthDelimited(),
            self::WIRE_FIXED32 => $this->take(4),
            default => throw new UnreadableMachinePayload("Unsupported protobuf wire type {$wire}."),
        };
    }

    private function take(int $count): string
    {
        if ($count > $this->length - $this->offset) {
            throw new UnreadableMachinePayload('The payload ends inside a fixed-width field.');
        }

        $value = substr($this->bytes, $this->offset, $count);
        $this->offset += $count;

        return $value;
    }
}
