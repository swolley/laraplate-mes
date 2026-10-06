<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Normalizers;

use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Models\MachineSource;

/**
 * Turns the raw payload a source delivers into the canonical shape of the pipeline.
 * A source picks its normaliser by `mes_machine_sources.normalizer`.
 */
interface MachineMessageNormalizer
{
    /**
     * The registry key a source names in `mes_machine_sources.normalizer`.
     */
    public function key(): string;

    /**
     * The identity of the message, read before it is stored.
     *
     * @throws UnreadableMachinePayload
     */
    public function meta(MachineSource $source, string $payload): MessageMeta;

    /**
     * @throws UnreadableMachinePayload
     */
    public function normalize(MachineSource $source, string $payload): NormalizedMessage;
}
