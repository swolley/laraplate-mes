<?php

declare(strict_types=1);

namespace Modules\MES\Services\DomainActions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\MES\Enums\NonConformanceDisposition;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Services\BomExplosionService;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Services\LotTracingService;
use Modules\MES\Services\MaterialConsumptionService;
use Modules\MES\Services\NonConformanceService;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Services\QualityCheckService;

/**
 * Maps MES domain actions onto the services that implement them.
 *
 * Handlers stay thin: state guards and permissions live in
 * {@see \Modules\MES\Policies\MesModelPolicy} and the services, exactly as they
 * do for the Filament actions that drive the same code.
 */
final class MesDomainActionRegistrar
{
    public function register(DomainActionRegistry $registry): void
    {
        $registry->register(ProductionOrder::class, 'release', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderService::class)->release($record));
        $registry->register(ProductionOrder::class, 'complete', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderService::class)->complete($record, (float) ($payload['quantity_produced'] ?? 0), $payload['lot_code'] ?? null));
        $registry->register(ProductionOrder::class, 'cancel', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderService::class)->cancel($record));
        $registry->register(ProductionOrder::class, 'record_consumption', static fn (Model $record, array $payload, User $user): Model => resolve(MaterialConsumptionService::class)->recordManual(
            $record,
            (int) $payload['item_id'],
            (float) $payload['quantity'],
            (string) ($payload['uom'] ?? 'pcs'),
            isset($payload['production_order_operation_id']) ? (int) $payload['production_order_operation_id'] : null,
        ));

        $registry->register(ProductionOrderOperation::class, 'start', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderOperationService::class)->start($record));
        $registry->register(ProductionOrderOperation::class, 'complete', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderOperationService::class)->complete($record, isset($payload['actual_minutes']) ? (float) $payload['actual_minutes'] : null));
        $registry->register(ProductionOrderOperation::class, 'skip', static fn (Model $record, array $payload, User $user): Model => resolve(ProductionOrderOperationService::class)->skip($record));

        $registry->register(QualityCheck::class, 'execute', static fn (Model $record, array $payload, User $user): Model => resolve(QualityCheckService::class)->execute($record, $payload['measurements'] ?? []));

        $registry->register(NonConformance::class, 'resolve', static fn (Model $record, array $payload, User $user): Model => resolve(NonConformanceService::class)->resolve($record, NonConformanceDisposition::from((string) $payload['disposition'])));
        $registry->register(NonConformance::class, 'close', static fn (Model $record, array $payload, User $user): Model => resolve(NonConformanceService::class)->close($record));

        $registry->register(Downtime::class, 'close', static fn (Model $record, array $payload, User $user): Model => resolve(DowntimeService::class)->close($record));

        $registry->register(Bom::class, 'explode', static fn (Model $record, array $payload, User $user): array => resolve(BomExplosionService::class)->explode($record->item_id, (float) ($payload['quantity'] ?? 1), Carbon::now()));

        $registry->register(LotNumber::class, 'forward_trace', static fn (Model $record, array $payload, User $user): array => resolve(LotTracingService::class)->forwardTrace($record->id));
        $registry->register(LotNumber::class, 'backward_trace', static fn (Model $record, array $payload, User $user): array => resolve(LotTracingService::class)->backwardTrace($record->id));
    }
}
