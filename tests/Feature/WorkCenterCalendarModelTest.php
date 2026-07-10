<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\MES\Enums\WorkCenterType;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper: create a minimal WorkCenter bypassing global scopes
// ---------------------------------------------------------------------------

function makeWC(): WorkCenter
{
    $company = Company::query()->withoutGlobalScopes()->create([
        'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
        'name' => fake()->company(),
        'fiscal_country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    return WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => mb_strtoupper(fake()->unique()->lexify('WC-????')),
        'name' => fake()->words(3, true),
        'type' => WorkCenterType::Machine->value,
        'capacity_per_hour' => 10.0,
        'capacity_uom' => 'pcs',
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------

it('can be created with all fillable attributes', function (): void {
    $workCenter = makeWC();

    $calendar = WorkCenterCalendar::query()->create([
        'work_center_id' => $workCenter->id,
        'day_of_week' => 2,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
    ]);

    expect($calendar->id)->toBeGreaterThan(0)
        ->and($calendar->work_center_id)->toBe($workCenter->id)
        ->and($calendar->day_of_week)->toBe(2)
        ->and($calendar->start_time)->toBe('08:00:00')
        ->and($calendar->end_time)->toBe('17:00:00');
});

it('persists all fillable attributes to the database', function (): void {
    $workCenter = makeWC();

    $calendar = WorkCenterCalendar::query()->create([
        'work_center_id' => $workCenter->id,
        'day_of_week' => 4,
        'start_time' => '06:00:00',
        'end_time' => '14:00:00',
    ]);

    $fresh = WorkCenterCalendar::query()->findOrFail($calendar->id);

    expect($fresh->work_center_id)->toBe($workCenter->id)
        ->and($fresh->day_of_week)->toBe(4)
        ->and($fresh->start_time)->toBe('06:00:00')
        ->and($fresh->end_time)->toBe('14:00:00');
});

// ---------------------------------------------------------------------------
// Casts
// ---------------------------------------------------------------------------

it('casts day_of_week as integer', function (): void {
    $workCenter = makeWC();

    $calendar = WorkCenterCalendar::query()->create([
        'work_center_id' => $workCenter->id,
        'day_of_week' => 0,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
    ]);

    expect($calendar->day_of_week)->toBe(0)
        ->and(gettype($calendar->day_of_week))->toBe('integer');
});

// ---------------------------------------------------------------------------
// Relation: workCenter()
// ---------------------------------------------------------------------------

it('has a workCenter() belongsTo relation', function (): void {
    $workCenter = makeWC();

    $calendar = WorkCenterCalendar::query()->create([
        'work_center_id' => $workCenter->id,
        'day_of_week' => 1,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
    ]);

    expect($calendar->workCenter())->toBeInstanceOf(BelongsTo::class);
});

it('belongs to the correct work center', function (): void {
    $workCenter = makeWC();

    $calendar = WorkCenterCalendar::query()->create([
        'work_center_id' => $workCenter->id,
        'day_of_week' => 3,
        'start_time' => '07:00:00',
        'end_time' => '15:00:00',
    ]);

    $relatedWorkCenter = WorkCenter::withoutGlobalScopes()
        ->where('id', $calendar->work_center_id)
        ->firstOrFail();

    expect($relatedWorkCenter->id)->toBe($workCenter->id)
        ->and($relatedWorkCenter->code)->toBe($workCenter->code);
});

// ---------------------------------------------------------------------------
// Factory
// ---------------------------------------------------------------------------

it('can be created via the factory', function (): void {
    $calendar = WorkCenterCalendar::factory()->create();

    expect($calendar)->toBeInstanceOf(WorkCenterCalendar::class)
        ->and($calendar->id)->toBeGreaterThan(0)
        ->and($calendar->work_center_id)->toBeGreaterThan(0);
});
