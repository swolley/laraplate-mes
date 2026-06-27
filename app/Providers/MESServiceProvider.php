<?php

declare(strict_types=1);

namespace Modules\MES\Providers;

use Modules\Core\Exceptions\ConfigurationException;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Services\ErpStockMovementRecorder;
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
        throw_unless(Module::find('ERP'), ConfigurationException::class, 'ERP is required and must be enabled');

        parent::register();

        // MES depends on ERP → registers the concrete ERP implementation here.
        // The ERP module has no knowledge of MES (dependency flows one way only).
        $this->app->singleton(
            StockMovementRecorder::class,
            ErpStockMovementRecorder::class,
        );
    }
}
