<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Role;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Listeners\NotifyMaterialShortage;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Notifications\MaterialShortageNotification;
use Modules\MES\Services\MaterialConsumptionService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function shortageEvent(int $company_id, int $item_id, int $warehouse_id): MaterialShortageDetected
{
    return new MaterialShortageDetected(
        company_id: $company_id,
        item_id: $item_id,
        warehouse_id: $warehouse_id,
        production_order_id: 1,
        production_order_operation_id: null,
        required_quantity: 20.0,
        available_quantity: 5.0,
        is_backflush: true,
    );
}

function makeUserWithRole(string $role): object
{
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate($role, 'web'));

    return $user;
}

it('notifies recipients holding the configured role', function (): void {
    Notification::fake();
    config(['mes.notifications.stock_shortage.recipients.roles' => ['shortage_admin']]);
    $user = makeUserWithRole('shortage_admin');

    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    new NotifyMaterialShortage()->handle(shortageEvent($company->id, $item->id, $warehouse->id));

    Notification::assertSentTo($user, MaterialShortageNotification::class);
});

it('sends nothing when no recipient holds the configured role', function (): void {
    Notification::fake();
    config(['mes.notifications.stock_shortage.recipients.roles' => ['shortage_admin']]);
    user_class()::factory()->create(); // no role assigned

    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    new NotifyMaterialShortage()->handle(shortageEvent($company->id, $item->id, $warehouse->id));

    Notification::assertNothingSent();
});

it('notifies through the event when a manual consumption falls short', function (): void {
    Notification::fake();
    config([
        'mes.queue.connection' => 'sync',
        'mes.notifications.stock_shortage.recipients.roles' => ['shortage_admin'],
    ]);
    $user = makeUserWithRole('shortage_admin');

    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $order = ProductionOrder::factory()->create(['company_id' => $company->id]);

    // No stock on hand → shortage → event → listener → notification.
    resolve(MaterialConsumptionService::class)->recordManual($order, $component->id, 7.0, 'pcs');

    Notification::assertSentTo($user, MaterialShortageNotification::class);
});
