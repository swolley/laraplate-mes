<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Modules\MES\Models\WorkCenterCalendar;

/**
 * Working time of a work center, read from its weekly calendar (`day_of_week`
 * 0 = Monday to 6 = Sunday, several slots per day allowed). A work center with
 * no calendar slot at all works 08:00 to 16:00 every day, so existing data keeps
 * its 480 minutes a day.
 */
final class WorkCalendar
{
    private const string DEFAULT_START = '08:00';

    private const string DEFAULT_END = '16:00';

    private const int HORIZON_DAYS = 366;

    /**
     * Working minutes inside a window, each slot clipped to it.
     */
    public function workingMinutesBetween(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $window_start = CarbonImmutable::instance($from);
        $window_end = CarbonImmutable::instance($to);
        $slots = $this->weeklySlots($work_center_id);
        $seconds = 0;

        for ($day = $window_start->startOfDay(); $day <= $window_end; $day = $day->addDay()) {
            foreach ($this->slotsOn($slots, $day) as [$start, $end]) {
                $clipped_start = $start->max($window_start);
                $clipped_end = $end->min($window_end);

                if ($clipped_end > $clipped_start) {
                    $seconds += $clipped_start->diffInSeconds($clipped_end, true);
                }
            }
        }

        return $seconds / 60;
    }

    /**
     * The first working instant at or after a moment.
     *
     * @throws DomainException when the calendar offers no working time within a year.
     */
    public function alignToWorking(int $work_center_id, DateTimeInterface $at): CarbonImmutable
    {
        return $this->advance($this->weeklySlots($work_center_id), CarbonImmutable::instance($at), 0.0);
    }

    /**
     * The instant at which a number of working minutes, started at a moment, are done.
     *
     * @throws DomainException when the calendar offers no working time within a year.
     */
    public function addWorkingMinutes(int $work_center_id, DateTimeInterface $start, float $minutes): CarbonImmutable
    {
        return $this->advance($this->weeklySlots($work_center_id), CarbonImmutable::instance($start), $minutes);
    }

    /**
     * @param  array<int, list<array{string, string}>>  $slots
     */
    private function advance(array $slots, CarbonImmutable $cursor, float $minutes): CarbonImmutable
    {
        $remaining = (int) round($minutes * 60);
        $started = false;

        for ($offset = 0; $offset <= self::HORIZON_DAYS; $offset++) {
            foreach ($this->slotsOn($slots, $cursor->startOfDay()->addDays($offset)) as [$start, $end]) {
                $from = $start->max($cursor);

                if ($from >= $end) {
                    continue;
                }

                $available = (int) $from->diffInSeconds($end, true);

                if ($remaining <= $available) {
                    return $from->addSeconds($remaining);
                }

                $remaining -= $available;
                $started = true;
            }
        }

        throw new DomainException($started ? 'The work center calendar cannot absorb that many working minutes within a year.' : 'The work center calendar offers no working time within a year.');
    }

    /**
     * @return array<int, list<array{string, string}>> slots by day of week, [] when no slot is configured at all
     */
    private function weeklySlots(int $work_center_id): array
    {
        $rows = WorkCenterCalendar::query()->where('work_center_id', $work_center_id)->orderBy('start_time')->get();

        if ($rows->isEmpty()) {
            return array_fill(0, 7, [[self::DEFAULT_START, self::DEFAULT_END]]);
        }

        $slots = array_fill(0, 7, []);

        foreach ($rows as $row) {
            $slots[$row->day_of_week][] = [(string) $row->start_time, (string) $row->end_time];
        }

        return $slots;
    }

    /**
     * @param  array<int, list<array{string, string}>>  $slots
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function slotsOn(array $slots, CarbonImmutable $day): array
    {
        $resolved = [];

        foreach ($slots[$day->dayOfWeekIso - 1] ?? [] as [$start_time, $end_time]) {
            $start = CarbonImmutable::parse($day->toDateString() . ' ' . $start_time);
            $end = CarbonImmutable::parse($day->toDateString() . ' ' . $end_time);

            if ($end > $start) {
                $resolved[] = [$start, $end];
            }
        }

        return $resolved;
    }
}
