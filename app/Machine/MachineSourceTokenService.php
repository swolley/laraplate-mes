<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Modules\MES\Models\MachineSource;

/**
 * The credentials of a machine source: one Sanctum token, with the ingest ability only,
 * shown once at the moment it is issued.
 */
final class MachineSourceTokenService
{
    public const string ABILITY = 'mes:machine-ingest';

    /**
     * Revokes the source's tokens and issues a new one.
     *
     * @return string the plain text token, which is not stored anywhere
     */
    public function issue(MachineSource $source): string
    {
        $this->revoke($source);

        return $source->createToken('machine-ingest', [self::ABILITY])->plainTextToken;
    }

    /**
     * @return int how many tokens were revoked
     */
    public function revoke(MachineSource $source): int
    {
        $revoked = $source->tokens()->count();
        $source->tokens()->delete();

        return $revoked;
    }
}
