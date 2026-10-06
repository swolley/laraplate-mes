<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineProfiles\Pages;

use function Modules\ERP\Helpers\current_company_id;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\ERP\Models\Company;
use Modules\MES\Filament\Resources\MachineProfiles\MachineProfileResource;
use Modules\MES\Machine\MachineProfileService;
use Override;

final class ListMachineProfiles extends ListRecords
{
    #[Override]
    protected static string $resource = MachineProfileResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('import')
                ->label('Import profile')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(static fn (): array => Company::query()->withoutGlobalScopes()->get()->pluck('name', 'id')->all())
                        ->default(static fn (): ?int => current_company_id())
                        ->required(),
                    FileUpload::make('file')
                        ->label('Profile file (JSON)')
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'];

                    try {
                        $json = $file instanceof TemporaryUploadedFile ? (string) file_get_contents($file->getRealPath()) : '';
                        $profile = resolve(MachineProfileService::class)->import((int) $data['company_id'], $json);
                    } catch (ValidationException $validationException) {
                        Notification::make()
                            ->title('The profile was not imported')
                            ->body(collect($validationException->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Profile {$profile->vendor} {$profile->model} {$profile->version} imported")
                        ->success()
                        ->send();
                }),
        ];
    }
}
