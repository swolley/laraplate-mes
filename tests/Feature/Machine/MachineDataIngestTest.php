<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Support\CrudApiExposure;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\MachineSourceTokenService;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CrudApiExposure::enable();
    MesTestHelpers::makeCompany();
    Queue::fake();
});

function ingestEnvelope(string $message_id = 'm-1', int $samples = 1): array
{
    return [
        'protocol' => 'laraplate-machine/1',
        'message_id' => $message_id,
        'source_seq' => 1,
        'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [[
            'device' => 'd1',
            'type' => 'data',
            'samples' => array_map(static fn (int $i): array => ['signal' => "s{$i}", 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => $i], range(1, $samples)),
        ]],
    ];
}

function ingest(array $envelope, ?string $token): Illuminate\Testing\TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

    return test()->postJson(route('mes.api.machine-data.ingest'), $envelope, $headers);
}

function sourceWithToken(array $attributes = []): array
{
    $source = MachineSource::factory()->create($attributes);

    return [$source, resolve(MachineSourceTokenService::class)->issue($source)];
}

it('serves the documented path and route name', function (): void {
    expect(route('mes.api.machine-data.ingest', absolute: false))->toBe('/api/v1/mes/machine-data');
});

it('accepts a valid envelope with 202, stores it and queues the job', function (): void {
    [$source, $token] = sourceWithToken();

    ingest(ingestEnvelope(), $token)->assertStatus(202)->assertJson(['status' => 'accepted', 'message_id' => 'm-1']);

    expect(MachineMessage::query()->where('source_id', $source->id)->count())->toBe(1);
    Queue::assertPushed(ProcessMachineMessageJob::class, 1);
});

it('answers a resend with 200 and duplicate true', function (): void {
    [, $token] = sourceWithToken();
    ingest(ingestEnvelope(), $token)->assertStatus(202);

    ingest(ingestEnvelope(), $token)->assertOk()->assertJson(['status' => 'duplicate', 'duplicate' => true, 'message_id' => 'm-1']);

    expect(MachineMessage::query()->count())->toBe(1);
    Queue::assertPushed(ProcessMachineMessageJob::class, 1);
});

it('refuses a missing or unknown token with 401', function (?string $token): void {
    ingest(ingestEnvelope(), $token)->assertUnauthorized();
})->with([
    'no token' => [null],
    'unknown token' => ['999|nonsense'],
]);

it('refuses a token without the ingest ability, or of an inactive source, with 403', function (): void {
    $source = MachineSource::factory()->create();
    $other_ability = $source->createToken('t', ['something:else'])->plainTextToken;
    ingest(ingestEnvelope(), $other_ability)->assertForbidden();

    [, $inactive_token] = sourceWithToken(['code' => 'off', 'is_active' => false]);
    ingest(ingestEnvelope('m-2'), $inactive_token)->assertForbidden();

    expect(MachineMessage::query()->count())->toBe(0)
        ->and(MachineIncident::query()->where('type', MachineIncidentType::AuthFailure->value)->count())->toBe(2);
});

it('records an authentication failure incident once in a while, not on every request', function (): void {
    [$source, $token] = sourceWithToken(['is_active' => false]);

    ingest(ingestEnvelope('a'), $token)->assertForbidden();
    ingest(ingestEnvelope('b'), $token)->assertForbidden();

    expect(MachineIncident::query()->where('source_id', $source->id)->count())->toBe(1);
});

it('refuses a source that is not an http canonical source with 403', function (array $attributes): void {
    [, $token] = sourceWithToken($attributes);

    ingest(ingestEnvelope(), $token)->assertForbidden();
})->with([
    'mqtt transport' => [['transport' => 'mqtt']],
    'mapped_json normaliser' => [['normalizer' => 'mapped_json']],
]);

it('refuses an invalid envelope with 422 and the schema errors', function (): void {
    [, $token] = sourceWithToken();
    $envelope = ingestEnvelope();
    $envelope['protocol'] = 'laraplate-machine/9';

    ingest($envelope, $token)->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['protocol']]);
    expect(MachineMessage::query()->count())->toBe(0);
});

it('refuses a body that is not JSON with 422', function (): void {
    [, $token] = sourceWithToken();

    test()->call('POST', route('mes.api.machine-data.ingest'), [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'application/json'], '{broken')->assertStatus(422);
});

it('refuses a body above the size limit and a message above the sample limit with 413', function (): void {
    [, $token] = sourceWithToken();

    config(['mes.machine.max_body_kb' => 1]);
    ingest(ingestEnvelope('big', 60), $token)->assertStatus(413);

    config(['mes.machine.max_body_kb' => 1024, 'mes.machine.max_samples' => 2]);
    ingest(ingestEnvelope('many', 3), $token)->assertStatus(413);

    expect(MachineMessage::query()->count())->toBe(0);
});

it('limits requests per source and says when to retry', function (): void {
    config(['mes.machine.rate_limit_per_minute' => 2]);
    [, $token] = sourceWithToken();

    ingest(ingestEnvelope('a'), $token)->assertStatus(202);
    ingest(ingestEnvelope('b'), $token)->assertStatus(202);

    ingest(ingestEnvelope('c'), $token)->assertStatus(429)->assertHeader('Retry-After');
});

it('keeps tokens and payloads out of the logs', function (): void {
    $log = Log::spy();
    [, $token] = sourceWithToken(['is_active' => false]);

    ingest(ingestEnvelope('secret-payload'), $token)->assertForbidden();
    ingest(ingestEnvelope('secret-payload'), '999|nonsense')->assertUnauthorized();

    $log->shouldHaveReceived('warning')->atLeast()->once()->withArgs(static fn (string $message, array $context = []): bool => ! str_contains($message . json_encode($context), $token)
        && ! str_contains($message . json_encode($context), 'nonsense')
        && ! str_contains($message . json_encode($context), 'secret-payload'));
});

it('lets a source hold one token at a time', function (): void {
    $source = MachineSource::factory()->create();
    $service = resolve(MachineSourceTokenService::class);
    $first = $service->issue($source);
    $second = $service->issue($source);

    expect($source->tokens()->count())->toBe(1);
    ingest(ingestEnvelope('a'), $first)->assertUnauthorized();
    ingest(ingestEnvelope('b'), $second)->assertStatus(202);
    expect($service->revoke($source))->toBe(1);
    ingest(ingestEnvelope('c'), $second)->assertUnauthorized();
});

it('limits failed authentications per address', function (): void {
    for ($i = 0; $i < 30; $i++) {
        ingest(ingestEnvelope(), "999|random-{$i}")->assertUnauthorized();
    }

    ingest(ingestEnvelope(), '999|random-last')->assertStatus(429)->assertHeader('Retry-After');
    Illuminate\Support\Facades\RateLimiter::clear('mes:machine:auth-failures:127.0.0.1');
});

it('refuses too many samples before validating the envelope', function (): void {
    [, $token] = sourceWithToken();
    config(['mes.machine.max_samples' => 2]);
    $envelope = ingestEnvelope('big', 3);
    $envelope['protocol'] = 'laraplate-machine/9';

    ingest($envelope, $token)->assertStatus(413);
});
