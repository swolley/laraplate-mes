<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

/**
 * The operation a sample belongs to, and why.
 */
final readonly class Attribution
{
    public const string EXPLICIT = 'explicit';

    public const string SINGLE_ACTIVE = 'single_active';

    public const string NONE = 'none';

    public function __construct(
        public ?int $production_order_operation_id,
        public string $reason,
    ) {}

    public static function none(): self
    {
        return new self(null, self::NONE);
    }
}
