<?php

declare(strict_types=1);

namespace Modules\MES\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\MES\Models\MachineIncident;

/**
 * Tells the plant administrators that a machine source or device needs attention.
 */
final class MachineIncidentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MachineIncident $incident,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> */
        return config('mes.notifications.machine_incident.channels', ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app_name = config()->string('app.name');

        return new MailMessage()
            ->subject("[{$app_name}] Machine incident: {$this->incident->type->value}")
            ->greeting('Hello!')
            ->line("A machine connectivity incident was recorded: {$this->incident->type->value}.")
            ->line('- **Source**: ' . $this->incident->source_id)
            ->line('- **Device**: ' . ($this->incident->device_id ?? '-'))
            ->line('- **Detail**: ' . json_encode($this->incident->detail, JSON_THROW_ON_ERROR))
            ->salutation('Best regards');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->incident->type->value,
            'company_id' => $this->incident->company_id,
            'source_id' => $this->incident->source_id,
            'device_id' => $this->incident->device_id,
            'detail' => $this->incident->detail,
            'occurred_at' => $this->incident->occurred_at->toIso8601String(),
        ];
    }
}
