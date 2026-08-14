<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use function user_class;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\Warehouse;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Notifications\MaterialShortageNotification;

/**
 * Notifies the configured recipients when a material shortage is detected.
 * Runs on the MES queue; recipients are resolved by role from config.
 */
final class NotifyMaterialShortage implements ShouldQueue
{
    use InteractsWithQueue;

    public function viaConnection(): string
    {
        return (string) config('mes.queue.connection');
    }

    public function viaQueue(): string
    {
        return (string) config('mes.queue.name');
    }

    public function handle(MaterialShortageDetected $event): void
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new MaterialShortageNotification(
            $event,
            $this->itemLabel($event->item_id),
            $this->warehouseLabel($event->warehouse_id),
        ));
    }

    private function recipients(): Collection
    {
        /** @var array<int, string> $roles */
        $roles = config('mes.notifications.stock_shortage.recipients.roles', []);

        if ($roles === []) {
            return new Collection();
        }

        $user_class = user_class();

        return $user_class::query()
            ->whereHas('roles', static fn ($query) => $query->whereIn('name', $roles))
            ->get();
    }

    private function itemLabel(int $item_id): string
    {
        return (string) (Item::query()->withoutGlobalScopes()->whereKey($item_id)->value('name') ?? "#{$item_id}");
    }

    private function warehouseLabel(int $warehouse_id): string
    {
        return (string) (Warehouse::query()->withoutGlobalScopes()->whereKey($warehouse_id)->value('code') ?? "#{$warehouse_id}");
    }
}
