<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Carbon\CarbonImmutable;

/**
 * What the inbox needs to know about a message before it is processed.
 */
final readonly class MessageMeta
{
    public function __construct(
        public string $message_id,
        public ?int $source_seq = null,
        public ?CarbonImmutable $sent_at = null,
    ) {}
}
