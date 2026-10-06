<?php

declare(strict_types=1);

namespace Modules\MES\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Machine\MachineIncidentRecorder;
use Modules\MES\Machine\MachineSourceTokenService;
use Modules\MES\Models\MachineSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a machine source by the bearer token it was issued. The token identifies the source;
 * an unknown token is `401`, a token that is not a source's, lacks the ingest ability or belongs to an
 * inactive source is `403`. Failures are logged without the token, and a source's failures become one
 * incident per five minutes, so a misconfigured agent cannot flood either.
 */
final class AuthenticateMachineSource
{
    public const string REQUEST_ATTRIBUTE = 'machine_source';

    public function __construct(
        private readonly MachineIncidentRecorder $incidents,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = is_string($plain) && $plain !== '' ? PersonalAccessToken::findToken($plain) : null;
        $source = $token?->tokenable;

        if ($token === null || ! $source instanceof MachineSource) {
            $this->logUnknownToken($request);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $token->can(MachineSourceTokenService::ABILITY) || ! $source->is_active) {
            $this->recordRefusal($source, $token->can(MachineSourceTokenService::ABILITY) ? 'inactive_source' : 'missing_ability');

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $source);

        return $next($request);
    }

    private function logUnknownToken(Request $request): void
    {
        if (Cache::add('mes:machine:auth-failure-ip:' . $request->ip(), true, 60)) {
            Log::warning('Machine ingest authentication failed.', ['ip' => $request->ip()]);
        }
    }

    private function recordRefusal(MachineSource $source, string $reason): void
    {
        if (Cache::add("mes:machine:auth-failure-source:{$source->id}", true, 300)) {
            $this->incidents->record($source, MachineIncidentType::AuthFailure, ['reason' => $reason]);
            Log::warning('Machine ingest refused.', ['source_id' => $source->id, 'reason' => $reason]);
        }
    }
}
