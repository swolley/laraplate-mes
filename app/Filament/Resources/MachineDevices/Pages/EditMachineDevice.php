<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Modules\MES\Machine\MachineProfileService;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineProfile;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Concerns\ChecksMesPolicy;
use Modules\MES\Filament\Resources\MachineDevices\MachineDeviceResource;
use Override;

final class EditMachineDevice extends EditRecord
{
    use ChecksMesPolicy;
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineDeviceResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply_profile')
                ->label('Apply profile')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->schema([
                    Select::make('profile_id')
                        ->label('Profile')
                        ->options(static fn (): array => MachineProfile::query()->get()->mapWithKeys(static fn (MachineProfile $profile): array => [$profile->id => "{$profile->vendor} {$profile->model} {$profile->version}"])->all())
                        ->required(),
                ])
                ->visible(fn (): bool => $this->policyAllows('applyProfile', $this->record))
                ->action(function (array $data): void {
                    /** @var MachineDevice $device */
                    $device = $this->record;

                    try {
                        resolve(MachineProfileService::class)->apply($device, MachineProfile::query()->findOrFail((int) $data['profile_id']));
                    } catch (ValidationException $validationException) {
                        Notification::make()->title('The profile could not be applied')->body(collect($validationException->errors())->flatten()->implode(' '))->danger()->send();

                        return;
                    }

                    Notification::make()->title('Profile applied')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
