<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\MES\Enums\MESTables;

uses(RefreshDatabase::class);

it('creates mes_work_center_calendars table', function (): void {
    expect(Schema::hasTable(MESTables::WorkCenterCalendars->value))->toBeTrue();
});

it('has the expected columns on mes_work_center_calendars', function (): void {
    expect(Schema::hasColumns(MESTables::WorkCenterCalendars->value, [
        'id',
        'work_center_id',
        'day_of_week',
        'start_time',
        'end_time',
    ]))->toBeTrue();
});

it('enforces the foreign key from work_center_id to mes_work_centers', function (): void {
    expect(Schema::hasColumn(MESTables::WorkCenterCalendars->value, 'work_center_id'))->toBeTrue()
        ->and(Schema::hasTable(MESTables::WorkCenters->value))->toBeTrue();
});
