<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum SignalRole: string
{
    case State = 'state';
    case Alarm = 'alarm';
    case GoodCount = 'good_count';
    case ScrapCount = 'scrap_count';
    case TotalCount = 'total_count';
    case Measurement = 'measurement';
    case ProcessValue = 'process_value';
    case OrderReference = 'order_reference';
    case OperationReference = 'operation_reference';

    /**
     * Returns an 'in:...' validation rule string for all enum values.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', array_column(self::cases(), 'value'));
    }

    /**
     * Returns all enum values as an array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether the role carries a piece counter.
     */
    public function isCount(): bool
    {
        return in_array($this, [self::GoodCount, self::ScrapCount, self::TotalCount], true);
    }

    /**
     * Whether the role carries a reference to an order or an operation.
     */
    public function isReference(): bool
    {
        return $this === self::OrderReference || $this === self::OperationReference;
    }
}
