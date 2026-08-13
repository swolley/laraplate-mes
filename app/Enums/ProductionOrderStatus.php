<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum ProductionOrderStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

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
     * Whether the order can still transition to the released state.
     */
    public function canRelease(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the order can still be cancelled.
     */
    public function canCancel(): bool
    {
        return $this === self::Draft || $this === self::Released;
    }
}
