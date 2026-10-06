<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Events\DowntimeClosed;
use Modules\MES\Events\DowntimeOpened;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function downtimeWorkCenter(): WorkCenter
{
    return WorkCenter::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);
}

it('opens a downtime and emits DowntimeOpened', function (): void {
    Event::fake([DowntimeOpened::class]);
    $work_center = downtimeWorkCenter();

    $downtime = resolve(DowntimeService::class)->open($work_center, DowntimeCause::Breakdown, null, 'jam');

    expect($downtime->ended_at)->toBeNull()
        ->and($downtime->cause)->toBe(DowntimeCause::Breakdown);
    Event::assertDispatched(DowntimeOpened::class, static fn (DowntimeOpened $event): bool => $event->downtime_id === $downtime->id
        && $event->work_center_id === $work_center->id
        && $event->cause === DowntimeCause::Breakdown->value);
});

it('refuses to open a second downtime while one is open on the same work center', function (): void {
    $work_center = downtimeWorkCenter();
    $service = resolve(DowntimeService::class);
    $service->open($work_center, DowntimeCause::Setup);

    expect(fn () => $service->open($work_center, DowntimeCause::Breakdown))
        ->toThrow(DomainException::class, 'already has an open downtime');
    expect(Downtime::query()->where('work_center_id', $work_center->id)->count())->toBe(1);
});

it('allows a new downtime once the previous one is closed, and emits DowntimeClosed', function (): void {
    $work_center = downtimeWorkCenter();
    $service = resolve(DowntimeService::class);
    $first = $service->open($work_center, DowntimeCause::Setup);
    Event::fake([DowntimeClosed::class]);

    $service->close($first);
    $second = $service->open($work_center, DowntimeCause::Breakdown);

    expect($second->ended_at)->toBeNull();
    Event::assertDispatched(DowntimeClosed::class, static fn (DowntimeClosed $event): bool => $event->downtime_id === $first->id);
});

it('opens a downtime through the open_downtime domain action on the work center', function (): void {
    $work_center = downtimeWorkCenter();
    $handler = resolve(DomainActionRegistry::class)->resolve(WorkCenter::class, 'open_downtime');

    $downtime = $handler($work_center, ['cause' => 'material_shortage', 'notes' => 'no steel'], Modules\Core\Models\User::factory()->create());

    expect($downtime)->toBeInstanceOf(Downtime::class)
        ->and($downtime->cause)->toBe(DowntimeCause::MaterialShortage)
        ->and($downtime->work_center_id)->toBe($work_center->id);
});
