<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineProfiles\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Modules\MES\Machine\MachineProfileService;
use Modules\MES\Models\MachineProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Concerns\ChecksMesPolicy;
use Modules\MES\Filament\Resources\MachineProfiles\MachineProfileResource;
use Override;

final class EditMachineProfile extends EditRecord
{
    use ChecksMesPolicy;
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineProfileResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export profile')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->policyAllows('export', $this->record))
                ->action(function (): StreamedResponse {
                    /** @var MachineProfile $profile */
                    $profile = $this->record;
                    $json = resolve(MachineProfileService::class)->export($profile);
                    $name = mb_strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', "{$profile->vendor}-{$profile->model}-{$profile->version}") ?? 'profile');

                    return response()->streamDownload(static function () use ($json): void {
                        echo $json;
                    }, "{$name}.json", ['Content-Type' => 'application/json']);
                }),
            DeleteAction::make(),
        ];
    }
}
