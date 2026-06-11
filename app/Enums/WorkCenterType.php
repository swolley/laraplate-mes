<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum WorkCenterType: string
{
    case Machine = 'machine';
    case Cell = 'cell';
    case Line = 'line';
    case ManualStation = 'manual_station';

    /**
     * Returns an 'in:...' validation rule string for all enum values.
     */
    public static function validationRule(): string
    {
        $values = implode(',', array_column(self::cases(), 'value'));

        return 'in:' . $values;
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
}
