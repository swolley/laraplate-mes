<?php

declare(strict_types=1);

namespace Modules\MES\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

final class MESPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'MES';
    }

    public function getId(): string
    {
        return 'mes';
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Own the module's navigation group instead of having the panel list every module:
     * the group is created here, with this module's icon, when the plugin registers.
     */
    public function afterRegister(Panel $panel): void
    {
        $panel->navigationGroups([
            NavigationGroup::make()
                ->label('MES')
                ->icon(Heroicon::OutlinedWrenchScrewdriver),
        ]);
    }
}
