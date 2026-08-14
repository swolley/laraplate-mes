<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\ERP\Events\SalesOrderConfirmed;
use Modules\MES\Services\SalesOrderProductionPlanner;

/**
 * Turns a confirmed sales order into production orders for its manufactured
 * lines. Runs on the MES queue; planning is idempotent so a retry is safe.
 */
final class CreateProductionOrdersForSalesOrder implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private SalesOrderProductionPlanner $planner) {}

    public function viaConnection(): string
    {
        return (string) config('mes.queue.connection');
    }

    public function viaQueue(): string
    {
        return (string) config('mes.queue.name');
    }

    public function handle(SalesOrderConfirmed $event): void
    {
        $this->planner->planForOrder($event->salesOrder);
    }
}
