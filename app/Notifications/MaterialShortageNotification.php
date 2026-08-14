<?php

declare(strict_types=1);

namespace Modules\MES\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\MES\Events\MaterialShortageDetected;

/**
 * Notifies operators that a material consumption could not be fully covered by
 * available stock. The available quantity was consumed; the shortfall is the
 * amount still missing.
 */
final class MaterialShortageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MaterialShortageDetected $event,
        private readonly string $item_label,
        private readonly string $warehouse_label,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> */
        return config('mes.notifications.stock_shortage.channels', ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app_name = config('app.name');
        $source = $this->event->is_backflush ? 'backflush' : 'manual consumption';

        return new MailMessage()
            ->subject("[{$app_name}] Material shortage on production order {$this->event->production_order_id}")
            ->greeting('Hello!')
            ->line("A {$source} could not be fully covered by available stock.")
            ->line("- **Item**: {$this->item_label}")
            ->line("- **Warehouse**: {$this->warehouse_label}")
            ->line("- **Required**: {$this->event->required_quantity}")
            ->line("- **Available**: {$this->event->available_quantity}")
            ->line('- **Shortfall**: ' . $this->shortfall())
            ->line("- **Production order**: {$this->event->production_order_id}")
            ->line('The available quantity was consumed; please replenish and reconcile the shortfall.')
            ->salutation('Best regards');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'material_shortage',
            'company_id' => $this->event->company_id,
            'item_id' => $this->event->item_id,
            'item_label' => $this->item_label,
            'warehouse_id' => $this->event->warehouse_id,
            'warehouse_label' => $this->warehouse_label,
            'production_order_id' => $this->event->production_order_id,
            'production_order_operation_id' => $this->event->production_order_operation_id,
            'required_quantity' => $this->event->required_quantity,
            'available_quantity' => $this->event->available_quantity,
            'shortfall' => $this->shortfall(),
            'is_backflush' => $this->event->is_backflush,
        ];
    }

    private function shortfall(): float
    {
        return max(0.0, $this->event->required_quantity - $this->event->available_quantity);
    }
}
