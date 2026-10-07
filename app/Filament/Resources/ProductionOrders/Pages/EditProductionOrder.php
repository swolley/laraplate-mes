<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\Pages;

use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Core\Models\User;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Policies\MesModelPolicy;
use Modules\MES\Services\ProductionOrderService;
use Override;

final class EditProductionOrder extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = ProductionOrderResource::class;

    /**
     * Release, complete and cancel run the same service and the same policy
     * (state guard plus seeded permission) as the domain actions of the generic CRUD.
     * The policy is called directly: the gate would let a superadmin past the state guard.
     *
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('release')
                ->label('Release')
                ->icon(Heroicon::OutlinedPlay)
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->policyAllows('release'))
                ->action(fn () => $this->runTransition('Order released', static fn (ProductionOrderService $service, ProductionOrder $order) => $service->release($order))),
            Action::make('complete')
                ->label('Complete')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->fillForm(fn (): array => ['quantity_produced' => $this->lastOperationDeclaredGood()])
                ->schema([
                    TextInput::make('quantity_produced')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('lot_code')
                        ->maxLength(255),
                ])
                ->visible(fn (): bool => $this->policyAllows('complete'))
                ->action(fn (array $data) => $this->runTransition('Order completed', static fn (ProductionOrderService $service, ProductionOrder $order) => $service->complete(
                    $order,
                    (float) $data['quantity_produced'],
                    filled($data['lot_code'] ?? null) ? (string) $data['lot_code'] : null,
                ))),
            Action::make('cancel')
                ->label('Cancel order')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->policyAllows('cancel'))
                ->action(fn () => $this->runTransition('Order cancelled', static fn (ProductionOrderService $service, ProductionOrder $order) => $service->cancel($order))),
        ];
    }

    /**
     * The produced quantity is proposed from the last operation: what its operator declared (the machine
     * count, corrected). Nothing is proposed while no operation has a declaration.
     */
    private function lastOperationDeclaredGood(): ?float
    {
        if (! $this->record instanceof ProductionOrder) {
            return null;
        }

        $last = $this->record->operations()->orderByDesc('sequence')->first();

        return $last instanceof ProductionOrderOperation && $last->declared_good_quantity !== null ? (float) $last->declared_good_quantity : null;
    }

    private function policyAllows(string $ability): bool
    {
        $user = auth()->user();

        return $this->record instanceof ProductionOrder
            && $user instanceof User
            && resolve(MesModelPolicy::class)->{$ability}($user, $this->record);
    }

    /**
     * @param  Closure(ProductionOrderService, ProductionOrder): mixed  $transition
     */
    private function runTransition(string $success_title, Closure $transition): void
    {
        /** @var ProductionOrder $order */
        $order = $this->record;

        try {
            $transition(resolve(ProductionOrderService::class), $order);
        } catch (DomainException $domain_exception) {
            Notification::make()
                ->title($domain_exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $order->refresh();
        $this->refreshFormData(['status', 'quantity_produced', 'actual_start_at', 'actual_end_at']);

        Notification::make()
            ->title($success_title)
            ->success()
            ->send();
    }
}
