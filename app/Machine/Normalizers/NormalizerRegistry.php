<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Normalizers;

use InvalidArgumentException;
use Modules\MES\Models\MachineSource;

/**
 * The normalisers a source can name. New ones register here without touching the pipeline.
 */
final class NormalizerRegistry
{
    /**
     * @var array<string, MachineMessageNormalizer>
     */
    private array $normalizers = [];

    public function register(MachineMessageNormalizer $normalizer): void
    {
        $this->normalizers[$normalizer->key()] = $normalizer;
    }

    /**
     * @throws InvalidArgumentException when the source names a normaliser nobody registered.
     */
    public function for(MachineSource $source): MachineMessageNormalizer
    {
        return $this->normalizers[$source->normalizer]
            ?? throw new InvalidArgumentException("Unknown machine normalizer [{$source->normalizer}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->normalizers);
    }
}
