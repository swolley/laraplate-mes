<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Modules\MES\Machine\MachineMessageReprocessor;
use Modules\MES\Machine\MachineSourceTokenService;
use Modules\MES\Models\MachineSource;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Concerns\ChecksMesPolicy;
use Modules\MES\Filament\Resources\MachineSources\MachineSourceResource;
use Override;

final class EditMachineSource extends EditRecord
{
    use ChecksMesPolicy;
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineSourceResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue_token')
                ->label('Issue token')
                ->icon(Heroicon::OutlinedKey)
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('Issuing a token revokes the previous one. The new token is shown once.')
                ->visible(fn (): bool => $this->policyAllows('issueToken', $this->record))
                ->action(function (): void {
                    /** @var MachineSource $source */
                    $source = $this->record;
                    $token = resolve(MachineSourceTokenService::class)->issue($source);

                    Notification::make()
                        ->title('Token issued')
                        ->body("Copy it now, it is shown only once: {$token}")
                        ->persistent()
                        ->success()
                        ->send();
                }),
            Action::make('revoke_token')
                ->label('Revoke token')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->policyAllows('revokeToken', $this->record))
                ->action(function (): void {
                    /** @var MachineSource $source */
                    $source = $this->record;
                    $revoked = resolve(MachineSourceTokenService::class)->revoke($source);

                    Notification::make()->title("Tokens revoked: {$revoked}")->success()->send();
                }),
            Action::make('reprocess_range')
                ->label('Reprocess range')
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([
                    DateTimePicker::make('from')->required(),
                    DateTimePicker::make('to')->required()->after('from'),
                ])
                ->visible(fn (): bool => $this->policyAllows('reprocessRange', $this->record))
                ->action(function (array $data): void {
                    /** @var MachineSource $source */
                    $source = $this->record;
                    $queued = resolve(MachineMessageReprocessor::class)->reprocessRange($source, Carbon::parse($data['from']), Carbon::parse($data['to']));

                    Notification::make()->title("Messages queued for reprocessing: {$queued}")->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
