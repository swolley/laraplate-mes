<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

/**
 * The content of one machine message after normalisation.
 */
final readonly class NormalizedMessage
{
    /**
     * @param  list<NormalizedSample>  $samples
     * @param  list<DeviceNotice>  $notices
     */
    public function __construct(
        public array $samples = [],
        public array $notices = [],
    ) {}
}
