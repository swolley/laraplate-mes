<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\MES\Enums\WorkCenterType;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper: create a minimal Company to satisfy FK constraints
// ---------------------------------------------------------------------------

function makeCompany(): Company
{
    return Company::query()->withoutGlobalScopes()->create([
        'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
        'name' => fake()->company(),
        'fiscal_country' => 'IT',
        'default_currency' => 'EUR',
    ]);
}

// ---------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------

it('can be created via the factory', function (): void {
    $wc = WorkCenter::factory()->make();

    expect($wc)->toBeInstanceOf(WorkCenter::class)
        ->and($wc->code)->not->toBeEmpty()
        ->and($wc->name)->not->toBeEmpty()
        ->and($wc->capacity_per_hour)->toBeGreaterThan(0)
        ->and($wc->is_active)->toBeTrue();
});

it('persists all fillable attributes to the database', function (): void {
    $company = makeCompany();

    $wc = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-001',
        'name' => 'Laser Cutter',
        'type' => WorkCenterType::Machine->value,
        'capacity_per_hour' => 25.5,
        'capacity_uom' => 'pcs',
        'is_active' => true,
    ]);

    $fresh = WorkCenter::withoutGlobalScopes()->findOrFail($wc->id);

    expect($fresh->code)->toBe('WC-001')
        ->and($fresh->name)->toBe('Laser Cutter')
        ->and($fresh->type)->toBe(WorkCenterType::Machine)
        ->and((float) $fresh->capacity_per_hour)->toBe(25.5)
        ->and($fresh->capacity_uom)->toBe('pcs')
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->company_id)->toBe($company->id);
});

// ---------------------------------------------------------------------------
// Casts
// ---------------------------------------------------------------------------

it('casts type to WorkCenterType enum', function (): void {
    $company = makeCompany();

    $wc = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-CAST',
        'name' => 'Assembly Line',
        'type' => 'line',
        'capacity_per_hour' => 10.0,
        'capacity_uom' => 'pcs',
    ]);

    expect($wc->type)->toBe(WorkCenterType::Line);
});

it('casts is_active as boolean', function (): void {
    $company = makeCompany();

    $wc = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-BOOL',
        'name' => 'Manual Station',
        'type' => WorkCenterType::ManualStation->value,
        'capacity_per_hour' => 5.0,
        'capacity_uom' => 'pcs',
        'is_active' => false,
    ]);

    expect($wc->is_active)->toBeFalse()
        ->and(gettype($wc->is_active))->toBe('boolean');
});

// ---------------------------------------------------------------------------
// Scope active()
// ---------------------------------------------------------------------------

it('scope active() returns only active work centers', function (): void {
    $company = makeCompany();

    $active = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-A',
        'name' => 'Active WC',
        'type' => WorkCenterType::Cell->value,
        'capacity_per_hour' => 8.0,
        'capacity_uom' => 'pcs',
        'is_active' => true,
    ]);

    $inactive = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-I',
        'name' => 'Inactive WC',
        'type' => WorkCenterType::Cell->value,
        'capacity_per_hour' => 8.0,
        'capacity_uom' => 'pcs',
        'is_active' => false,
    ]);

    $result = WorkCenter::withoutGlobalScopes()->active()->get();

    expect($result->contains($active))->toBeTrue()
        ->and($result->contains($inactive))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Relation: calendar()
// ---------------------------------------------------------------------------

it('has a calendar() hasMany relation with WorkCenterCalendar', function (): void {
    $company = makeCompany();

    $wc = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-CAL',
        'name' => 'Calendar WC',
        'type' => WorkCenterType::Machine->value,
        'capacity_per_hour' => 20.0,
        'capacity_uom' => 'pcs',
    ]);

    WorkCenterCalendar::query()->create([
        'work_center_id' => $wc->id,
        'day_of_week' => 0,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
    ]);

    WorkCenterCalendar::query()->create([
        'work_center_id' => $wc->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
    ]);

    expect($wc->calendar()->count())->toBe(2)
        ->and($wc->calendar->first())->toBeInstanceOf(WorkCenterCalendar::class);
});

// ---------------------------------------------------------------------------
// Soft delete
// ---------------------------------------------------------------------------

it('has deleted_at column for soft delete support', function (): void {
    $company = makeCompany();

    $wc = WorkCenter::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => 'WC-DEL',
        'name' => 'To Delete',
        'type' => WorkCenterType::Machine->value,
        'capacity_per_hour' => 5.0,
        'capacity_uom' => 'pcs',
    ]);

    $wc->delete();

    // Verify record is still in DB (withTrashed) but marked as deleted
    $with_trashed = WorkCenter::withoutGlobalScopes()->withTrashed()->find($wc->id);
    expect($with_trashed)->not->toBeNull()
        ->and($with_trashed->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// getRules()
// ---------------------------------------------------------------------------

it('getRules() returns create and update keys', function (): void {
    $wc = new WorkCenter;
    $rules = $wc->getRules();

    expect($rules)->toHaveKey('create')
        ->and($rules)->toHaveKey('update')
        ->and($rules['create'])->toHaveKey('code')
        ->and($rules['create'])->toHaveKey('type')
        ->and($rules['create'])->toHaveKey('capacity_per_hour');
});
