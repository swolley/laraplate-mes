<?php

declare(strict_types=1);

namespace Modules\MES\Providers;

use Exception;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\MES\Contracts\StockMovementRecorder;
use Nwidart\Modules\Facades\Module;
use Override;

/**
 * @property \Illuminate\Foundation\Application $app
 */
final class MESServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'MES';

    #[Override]
    protected string $nameLower = 'mes';

    #[Override]
    public function register(): void
    {
        throw_unless(Module::find('ERP'), Exception::class, 'ERP is required and must be enabled');

        parent::register();

        $this->app->singleton(StockMovementRecorder::class, config('mes.services.stock_movement_recorder'));
    }
}
