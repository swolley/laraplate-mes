<?php

declare(strict_types=1);

namespace Modules\MES\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\MES\Events\CapacityOverloadDetected;

/**
 * Tells the planners that work started on a work center whose load for the day
 * exceeds its available minutes.
 */
final class CapacityOverloadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CapacityOverloadDetected $event,
        private readonly string $work_center_label,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> */
        return config('mes.notifications.capacity_overload.channels', ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app_name = config()->string('app.name');

        return new MailMessage()
            ->subject("[{$app_name}] Work center {$this->work_center_label} is overloaded")
            ->greeting('Hello!')
            ->line("An operation started on a work center whose load for today exceeds its capacity.")
            ->line("- **Work center**: {$this->work_center_label}")
            ->line("- **Load (standard minutes)**: {$this->event->capacity_load}")
            ->line("- **Available minutes**: {$this->event->available_minutes}")
            ->line("- **Production order**: {$this->event->production_order_id}")
            ->salutation('Best regards');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'capacity_overload',
            'company_id' => $this->event->company_id,
            'work_center_id' => $this->event->work_center_id,
            'work_center_label' => $this->work_center_label,
            'production_order_id' => $this->event->production_order_id,
            'production_order_operation_id' => $this->event->production_order_operation_id,
            'capacity_load' => $this->event->capacity_load,
            'available_minutes' => $this->event->available_minutes,
        ];
    }
}
