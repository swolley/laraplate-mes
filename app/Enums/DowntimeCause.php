<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum DowntimeCause: string
{
    case Breakdown = 'breakdown';
    case Setup = 'setup';
    case Changeover = 'changeover';
    case MaterialShortage = 'material_shortage';
    case Quality = 'quality';
    case PlannedMaintenance = 'planned_maintenance';
    case Other = 'other';

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
     * Whether this cause is planned (excluded from availability loss).
     */
    public function isPlanned(): bool
    {
        return $this === self::PlannedMaintenance;
    }
}
