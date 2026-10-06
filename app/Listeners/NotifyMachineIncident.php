<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use function user_class;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Modules\MES\Events\MachineIncidentRecorded;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Notifications\MachineIncidentNotification;

/**
 * Notifies the configured recipients when a machine incident is recorded. Runs on the MES queue;
 * recipients are resolved by role from `mes.notifications.machine_incident`.
 */
final class NotifyMachineIncident implements ShouldQueue
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

    public function handle(MachineIncidentRecorded $event): void
    {
        $incident = MachineIncident::query()->withoutGlobalScopes()->find($event->incident_id);
        /** @var array<int, string> $roles */
        $roles = config('mes.notifications.machine_incident.recipients.roles', []);

        if (! $incident instanceof MachineIncident || $roles === []) {
            return;
        }

        $recipients = user_class()::query()->role($roles)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new MachineIncidentNotification($incident));
    }
}
