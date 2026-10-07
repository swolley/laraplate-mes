<?php

declare(strict_types=1);

namespace Modules\MES\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Exceptions\ConfigurationException;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\ERP\Events\SalesOrderConfirmed;
use Modules\MES\Console\MachineOpenStopsCommand;
use Modules\MES\Console\MachineWatchdogCommand;
use Modules\MES\Console\MaterializeKpisCommand;
use Modules\MES\Contracts\ProductionCostReader;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Events\CapacityOverloadDetected;
use Modules\MES\Events\MachineIncidentRecorded;
use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Events\ProductionOrderCancelled;
use Modules\MES\Events\ProductionOrderCompleted;
use Modules\MES\Events\ProductionOrderReleased;
use Modules\MES\Listeners\CreateProductionOrdersForSalesOrder;
use Modules\MES\Listeners\NotifyCapacityOverload;
use Modules\MES\Listeners\MachineStateRecorder;
use Modules\MES\Listeners\NotifyMachineIncident;
use Modules\MES\Listeners\NotifyMaterialShortage;
use Modules\MES\Listeners\ReleaseComponentsAfterCompletion;
use Modules\MES\Listeners\ReleaseComponentsForProductionOrder;
use Modules\MES\Listeners\ReserveComponentsForProductionOrder;
use Modules\MES\Machine\Mqtt\MachineMessageSubscriber;
use Modules\MES\Machine\Mqtt\MqttIngest;
use Modules\MES\Machine\Mqtt\MqttMessageHandler;
use Modules\MES\Machine\Mqtt\PhpMqttSubscriber;
use Modules\MES\Machine\Normalizers\CanonicalNormalizer;
use Modules\MES\Machine\Normalizers\MappedJsonNormalizer;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Sparkplug\SparkplugBNormalizer;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineProfile;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Observers\MachineConfigurationObserver;
use Modules\MES\Policies\MesModelPolicy;
use Modules\MES\Services\DomainActions\MesDomainActionRegistrar;
use Modules\MES\Services\ErpProductionCostReader;
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

        $this->app->bind(MachineMessageSubscriber::class, PhpMqttSubscriber::class);
        $this->app->bind(MqttMessageHandler::class, MqttIngest::class);

        $this->app->singleton(NormalizerRegistry::class, static function (): NormalizerRegistry {
            $registry = new NormalizerRegistry();
            $registry->register(new CanonicalNormalizer());
            $registry->register(new MappedJsonNormalizer());
            $registry->register(resolve(SparkplugBNormalizer::class));

            return $registry;
        });

        $this->app->singleton(
            ProductionCostReader::class,
            ErpProductionCostReader::class,
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

        // The throttle runs before the source is authenticated, so it counts by the token, which belongs to one source.
        MachineDevice::observe(MachineConfigurationObserver::class);
        MachineSignal::observe(MachineConfigurationObserver::class);

        RateLimiter::for('mes-machine-ingest', static fn (Request $request): Limit => Limit::perMinute(config()->integer('mes.machine.rate_limit_per_minute'))
            ->by(sha1((string) $request->bearerToken())));

        resolve(MesDomainActionRegistrar::class)->register(resolve(DomainActionRegistry::class));

        Event::listen(SalesOrderConfirmed::class, CreateProductionOrdersForSalesOrder::class);
        Event::listen(ProductionOrderReleased::class, ReserveComponentsForProductionOrder::class);
        Event::listen(ProductionOrderCancelled::class, ReleaseComponentsForProductionOrder::class);
        Event::listen(ProductionOrderCompleted::class, ReleaseComponentsAfterCompletion::class);
        Event::listen(MaterialShortageDetected::class, NotifyMaterialShortage::class);
        Event::listen(CapacityOverloadDetected::class, NotifyCapacityOverload::class);
        Event::listen(MachineIncidentRecorded::class, NotifyMachineIncident::class);
        Event::listen(MachineStateObserved::class, MachineStateRecorder::class);
    }

    #[Override]
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(MaterializeKpisCommand::class)
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();

            $this->app->make(Schedule::class)
                ->command(MachineWatchdogCommand::class)
                ->everyMinute()
                ->withoutOverlapping()
                ->onOneServer();

            $this->app->make(Schedule::class)
                ->command(MachineOpenStopsCommand::class)
                ->everyMinute()
                ->withoutOverlapping()
                ->onOneServer();

            $this->app->make(Schedule::class)
                ->command('model:prune', ['--model' => [MachineMessage::class]])
                ->daily()
                ->onOneServer();
        });
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
            WorkCenter::class,
            MachineMessage::class,
            MachineSource::class,
            MachineDevice::class,
            MachineProfile::class,
        ];
    }
}
