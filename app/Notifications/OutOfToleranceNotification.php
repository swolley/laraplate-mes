<?php

declare(strict_types=1);

namespace Modules\MES\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\MES\Events\OutOfToleranceMeasured;

/**
 * Tells the quality people that a probe measured a value outside the limits of its characteristic.
 */
final class OutOfToleranceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly OutOfToleranceMeasured $event,
        private readonly string $characteristic_label,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> */
        return config('mes.notifications.out_of_tolerance.channels', ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app_name = config()->string('app.name');
        $lower = $this->event->lower ?? '-';
        $upper = $this->event->upper ?? '-';

        return new MailMessage()
            ->subject("[{$app_name}] Out of tolerance: {$this->characteristic_label}")
            ->greeting('Hello!')
            ->line('A probe measured a value outside the limits of its characteristic.')
            ->line("- **Characteristic**: {$this->characteristic_label}")
            ->line("- **Value**: {$this->event->value}")
            ->line("- **Limits**: {$lower} to {$upper}")
            ->salutation('Best regards');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'out_of_tolerance',
            'company_id' => $this->event->company_id,
            'quality_check_id' => $this->event->quality_check_id,
            'signal_id' => $this->event->signal_id,
            'characteristic' => $this->characteristic_label,
            'value' => $this->event->value,
            'lower' => $this->event->lower,
            'upper' => $this->event->upper,
        ];
    }
}
