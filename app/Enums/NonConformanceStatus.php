<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum NonConformanceStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Closed = 'closed';

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
     * Whether the non-conformance is still actionable.
     */
    public function isActionable(): bool
    {
        return $this === self::Open || $this === self::UnderReview;
    }
}
