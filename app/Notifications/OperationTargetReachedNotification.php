<?php

declare(strict_types=1);

namespace Modules\MES\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\MES\Events\OperationTargetReached;

/**
 * Tells the planners that the machine counted the planned quantity of an operation. The operation stays
 * open: somebody has to complete it.
 */
final class OperationTargetReachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly OperationTargetReached $event,
        private readonly string $work_center_label,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> */
        return config('mes.notifications.operation_target.channels', ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app_name = config()->string('app.name');

        return new MailMessage()
            ->subject("[{$app_name}] Planned quantity reached on {$this->work_center_label}")
            ->greeting('Hello!')
            ->line('The machine counted the planned quantity of an operation. It is still open: complete it when the work is done.')
            ->line("- **Work center**: {$this->work_center_label}")
            ->line("- **Good pieces**: {$this->event->good}")
            ->line("- **Planned quantity**: {$this->event->planned}")
            ->line("- **Production order**: {$this->event->production_order_id}")
            ->salutation('Best regards');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'operation_target',
            'company_id' => $this->event->company_id,
            'work_center_id' => $this->event->work_center_id,
            'work_center_label' => $this->work_center_label,
            'production_order_id' => $this->event->production_order_id,
            'production_order_operation_id' => $this->event->production_order_operation_id,
            'good' => $this->event->good,
            'planned' => $this->event->planned,
        ];
    }
}
