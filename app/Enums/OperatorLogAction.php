<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum OperatorLogAction: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Paused = 'paused';
    case Resumed = 'resumed';

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
}
