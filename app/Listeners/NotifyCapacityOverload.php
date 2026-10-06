<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use function user_class;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Modules\MES\Events\CapacityOverloadDetected;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Notifications\CapacityOverloadNotification;

/**
 * Notifies the configured recipients when an operation starts on an overloaded
 * work center. Runs on the MES queue; recipients are resolved by role from config.
 */
final class NotifyCapacityOverload implements ShouldQueue
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

    public function handle(CapacityOverloadDetected $event): void
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CapacityOverloadNotification($event, $this->workCenterLabel($event->work_center_id)));
    }

    /**
     * @return Collection<int, Model>
     */
    private function recipients(): Collection
    {
        /** @var array<int, string> $roles */
        $roles = config('mes.notifications.capacity_overload.recipients.roles', []);

        if ($roles === []) {
            return new Collection();
        }

        return user_class()::query()->role($roles)->get();
    }

    private function workCenterLabel(int $work_center_id): string
    {
        $work_center = WorkCenter::query()->withoutGlobalScopes()->find($work_center_id);

        return $work_center instanceof WorkCenter ? $work_center->code : "#{$work_center_id}";
    }
}
