<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use function user_class;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Modules\MES\Events\OutOfToleranceMeasured;
use Modules\MES\Notifications\OutOfToleranceNotification;

/**
 * Notifies the configured recipients when a probe measured a value outside the
 * limits of its characteristic. Runs on the MES queue; recipients are resolved by role from config.
 */
final class NotifyOutOfTolerance implements ShouldQueue
{
    use InteractsWithQueue;

    public function viaConnection(): string
    {
        return config()->string('mes.queue.connection');
    }

    public function viaQueue(): string
    {
        return config()->string('mes.queue.name');
    }

    public function handle(OutOfToleranceMeasured $event): void
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new OutOfToleranceNotification($event, $this->characteristicLabel($event)));
    }

    /**
     * @return Collection<int, Model>
     */
    private function recipients(): Collection
    {
        /** @var array<int, string> $roles */
        $roles = config('mes.notifications.out_of_tolerance.recipients.roles', []);

        if ($roles === []) {
            return new Collection();
        }

        return user_class()::query()->role($roles)->get();
    }

    private function characteristicLabel(OutOfToleranceMeasured $event): string
    {
        return $event->characteristic;
    }
}
