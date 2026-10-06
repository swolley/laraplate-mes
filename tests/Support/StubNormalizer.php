<?php

declare(strict_types=1);

namespace Modules\MES\Tests\Support;

use Closure;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\Normalizers\MachineMessageNormalizer;
use Modules\MES\Models\MachineSource;

/**
 * A normaliser whose behaviour the test supplies.
 */
final class StubNormalizer implements MachineMessageNormalizer
{
    /**
     * @param  Closure(string): MessageMeta|null  $meta
     * @param  Closure(string): NormalizedMessage|null  $normalize
     */
    public function __construct(
        private readonly string $key = 'stub',
        private readonly ?Closure $meta = null,
        private readonly ?Closure $normalize = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function meta(MachineSource $source, string $payload): MessageMeta
    {
        return $this->meta instanceof Closure ? ($this->meta)($payload) : new MessageMeta(sha1($payload));
    }

    public function normalize(MachineSource $source, string $payload): NormalizedMessage
    {
        return $this->normalize instanceof Closure ? ($this->normalize)($payload) : new NormalizedMessage();
    }
}
