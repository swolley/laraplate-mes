<?php

declare(strict_types=1);

namespace Modules\MES\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Exceptions\ConfigurationException;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\ERP\Events\SalesOrderConfirmed;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Listeners\CreateProductionOrdersForSalesOrder;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Policies\MesModelPolicy;
use Modules\MES\Services\DomainActions\MesDomainActionRegistrar;
use Modules\MES\Services\ErpStockMovementRecorder;
use Modules\MES\Services\ErpStockReader;
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

        // MES depends on ERP → registers the concrete ERP implementations here.
        // The ERP module has no knowledge of MES (dependency flows one way only).
        $this->app->singleton(
            StockMovementRecorder::class,
            ErpStockMovementRecorder::class,
        );

        $this->app->singleton(
            StockReader::class,
            ErpStockReader::class,
        );
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        foreach ($this->policyModels() as $model) {
            Gate::policy($model, MesModelPolicy::class);
        }

        resolve(MesDomainActionRegistrar::class)->register(resolve(DomainActionRegistry::class));

        Event::listen(SalesOrderConfirmed::class, CreateProductionOrdersForSalesOrder::class);
    }

    /**
     * MES models that expose domain actions through {@see MesModelPolicy}.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function policyModels(): array
    {
        return [
            Bom::class,
            ProductionOrder::class,
            ProductionOrderOperation::class,
            QualityCheck::class,
            NonConformance::class,
            Downtime::class,
            LotNumber::class,
        ];
    }
}
