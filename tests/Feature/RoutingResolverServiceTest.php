<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Services\RoutingResolverService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('resolves the active routing effective on a given date', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);

    $old = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'v1',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => now()->subMonth()->toDateString(),
    ]);

    $new = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'v2',
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => null,
    ]);

    $resolved = resolve(RoutingResolverService::class)->getActiveRouting($item->id, now());

    expect($resolved?->id)->toBe($new->id)
        ->and($resolved?->id)->not->toBe($old->id);
});

it('returns null when no routing is effective on the date', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);

    Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'future',
        'valid_from' => now()->addMonth()->toDateString(),
        'valid_to' => null,
    ]);

    expect(resolve(RoutingResolverService::class)->getActiveRouting($item->id, now()))->toBeNull();
});

it('ignores inactive routings', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);

    Routing::factory()->inactive()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'off',
        'valid_from' => now()->subDay()->toDateString(),
        'valid_to' => null,
    ]);

    expect(resolve(RoutingResolverService::class)->getActiveRouting($item->id, now()))->toBeNull();
});

it('orders routing operations by sequence', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $work_center = Modules\MES\Models\WorkCenter::factory()->create(['company_id' => $company->id]);

    $routing = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
    ]);

    RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 20,
        'description' => 'Second',
        'setup_time_minutes' => 5,
        'cycle_time_minutes' => 2,
    ]);
    RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 10,
        'description' => 'First',
        'setup_time_minutes' => 5,
        'cycle_time_minutes' => 2,
    ]);

    expect($routing->routingOperations->pluck('sequence')->all())->toBe([10, 20]);
});

it('rejects duplicate sequence within the same routing', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $work_center = Modules\MES\Models\WorkCenter::factory()->create(['company_id' => $company->id]);

    $routing = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
    ]);

    RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 10,
        'description' => 'First',
        'setup_time_minutes' => 5,
        'cycle_time_minutes' => 2,
    ]);

    expect(fn () => RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 10,
        'description' => 'Dup',
        'setup_time_minutes' => 5,
        'cycle_time_minutes' => 2,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
