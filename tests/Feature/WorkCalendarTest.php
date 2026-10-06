<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;
use Modules\MES\Services\WorkCalendar;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function calendarWorkCenter(array $slots = []): WorkCenter
{
    $work_center = WorkCenter::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);

    foreach ($slots as [$day, $start, $end]) {
        WorkCenterCalendar::query()->create(['work_center_id' => $work_center->id, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
    }

    return $work_center;
}

/** Monday to Friday, 09:00 to 17:00. */
function weekdayCalendar(): WorkCenter
{
    return calendarWorkCenter(array_map(static fn (int $day): array => [$day, '09:00', '17:00'], range(0, 4)));
}

it('works 08:00 to 16:00 every day when the work center has no calendar', function (): void {
    $work_center = calendarWorkCenter();
    $calendar = resolve(WorkCalendar::class);

    // 2026-10-05 is a Monday.
    expect($calendar->addWorkingMinutes($work_center->id, Carbon::parse('2026-10-05 15:00'), 120)->toDateTimeString())->toBe('2026-10-06 09:00:00')
        ->and($calendar->workingMinutesBetween($work_center->id, Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59')))->toBe(480.0);
});

it('counts only the calendar slots, clipped to the window', function (): void {
    $work_center = weekdayCalendar();
    $calendar = resolve(WorkCalendar::class);

    expect($calendar->workingMinutesBetween($work_center->id, Carbon::parse('2026-10-09 00:00'), Carbon::parse('2026-10-12 23:59')))->toBe(960.0)
        ->and($calendar->workingMinutesBetween($work_center->id, Carbon::parse('2026-10-10 00:00'), Carbon::parse('2026-10-11 23:59')))->toBe(0.0)
        ->and($calendar->workingMinutesBetween($work_center->id, Carbon::parse('2026-10-05 12:00'), Carbon::parse('2026-10-05 14:00')))->toBe(120.0);
});

it('skips non-working time when adding working minutes', function (): void {
    $work_center = weekdayCalendar();
    $calendar = resolve(WorkCalendar::class);

    expect($calendar->addWorkingMinutes($work_center->id, Carbon::parse('2026-10-09 16:00'), 120)->toDateTimeString())->toBe('2026-10-12 10:00:00')
        ->and($calendar->alignToWorking($work_center->id, Carbon::parse('2026-10-10 10:00'))->toDateTimeString())->toBe('2026-10-12 09:00:00')
        ->and($calendar->alignToWorking($work_center->id, Carbon::parse('2026-10-05 12:00'))->toDateTimeString())->toBe('2026-10-05 12:00:00');
});

it('supports several slots in the same day', function (): void {
    $work_center = calendarWorkCenter([[0, '08:00', '12:00'], [0, '13:00', '17:00']]);
    $calendar = resolve(WorkCalendar::class);

    // 3h in the morning slot from 09:00 leaves 60 minutes for the afternoon slot.
    expect($calendar->addWorkingMinutes($work_center->id, Carbon::parse('2026-10-05 09:00'), 240)->toDateTimeString())->toBe('2026-10-05 14:00:00')
        ->and($calendar->workingMinutesBetween($work_center->id, Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59')))->toBe(480.0);
});

it('fails clearly when the calendar never offers working time', function (): void {
    $work_center = calendarWorkCenter([[0, '09:00', '09:00']]);

    expect(fn () => resolve(WorkCalendar::class)->addWorkingMinutes($work_center->id, Carbon::parse('2026-10-05 09:00'), 60))
        ->toThrow(DomainException::class);
});
