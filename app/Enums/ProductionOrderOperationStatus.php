<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum ProductionOrderOperationStatus: string
{
    case Planned = 'planned';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

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
     * Whether the operation may transition into the in-progress state.
     */
    public function canStart(): bool
    {
        return $this === self::Planned || $this === self::Ready;
    }
}
