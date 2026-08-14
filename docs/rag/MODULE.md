# MES Module — Developer Reference

Manufacturing Execution System for Laraplate. Turns confirmed demand and item
master data into executable production, tracks shop-floor progress, consumes
materials, traces lots/serials, records quality and downtime, and exposes
production KPIs.

## Boundaries

- **Depends on ERP** (one-way: ERP knows nothing of MES). Physical FKs point at
  ERP `companies`, `items`, `warehouses`, `sales_orders`, `sales_order_lines`.
- Every table is prefixed `mes_` and centralised in `MESTables`.
- Stock movements are defined by the MES `StockMovementRecorder` contract and
  executed by the ERP adapter `ErpStockMovementRecorder`.
- Production order numbers are allocated by ERP `DocumentNumberAllocator` using
  `DocumentType::ProductionOrder`.

## Entities

| Area | Models |
|------|--------|
| Work centers | `WorkCenter`, `WorkCenterCalendar` |
| BOM | `Bom`, `BomLine` (validity window; audited) |
| Routing | `Routing`, `RoutingOperation` (validity window; audited) |
| Production | `ProductionOrder` (immutable BOM/routing snapshots; audited), `ProductionOrderOperation` |
| Materials | `MaterialConsumption` |
| Traceability | `LotNumber`, `SerialNumber`, `LotLineage` |
| Quality | `QualityCheck`, `QualityCheckMeasurement`, `NonConformance` |
| Downtime | `Downtime` |
| Shifts | `Shift`, `ShiftInstance`, `OperatorLog` |

## Core flow

1. **Create** (`ProductionOrderService::create`) — allocates the number and
   freezes the effective BOM lines and routing operations into immutable JSON
   snapshots (`bom_snapshot`, `routing_snapshot`). Status `draft`.
2. **Release** (`release`) — materialises `ProductionOrderOperation` rows from
   the routing snapshot. Status `released`.
3. **Execute operations** (`ProductionOrderOperationService`) —
   `start`/`complete`/`skip`; efficiency = standard / actual, clamped
   `[0, 999.99]`. Completing an operation logs the operator (`OperatorLog`) and
   dispatches `BackflushMaterialsJob`.
4. **Backflush** (`BackflushMaterialsJob`, idempotent) — consumes snapshot BOM
   lines marked `backflush` whose `routing_operation_id` matches the operation
   (or the order's last operation when null — decision D5), recording a stock
   `out` movement per line.
5. **Complete order** (`complete`) — sets produced quantity; generates the
   finished `LotNumber` when the item is lot/serial-traced.

## Sales-order-driven creation

Confirming an ERP sales order dispatches `Modules\ERP\Events\SalesOrderConfirmed`
(fired on create-as-confirmed and on the `draft → confirmed` transition). MES
listens with the queued `CreateProductionOrdersForSalesOrder`, which delegates to
`SalesOrderProductionPlanner`. Per line the planner creates a draft production
order only when the line has an item **with an active BOM**; it plans the
outstanding quantity (`qty_ordered − qty_delivered`) and links the order back via
`sales_order_id` / `sales_order_line_id`. Planning is idempotent per line (an
existing order short-circuits), so replays and re-confirmations never duplicate.
The receiving warehouse comes from `ProductionWarehouseResolver` (config map →
company's sole warehouse → skip when ambiguous); planned dates come from
`ProductionLeadTimeEstimator` (routing standard minutes ÷ daily minutes, or the
default lead time when the item has no routing). Purchased/service lines, already
delivered lines and multi-warehouse-ambiguous lines are skipped with a reason
(`ProductionPlanningSkipReason`) rather than guessed.

## Services

`BomExplosionService` (multi-level explosion + active BOM), `RoutingResolverService`
(date-effective routing), `ProductionOrderService`, `ProductionOrderOperationService`,
`LotTracingService` (forward/backward genealogy), `QualityCheckService`
(limits → non-conformance), `NonConformanceService` (dispositions; rework spawns a
linked order), `CapacityService` (work-center load), `OeeCalculatorService`
(A×P×Q, clamped), `DowntimeService`, `ShiftVerificationService`,
`SalesOrderProductionPlanner` (auto-creation from confirmed sales orders, with
`ProductionWarehouseResolver` and `ProductionLeadTimeEstimator`).

## HTTP surface

No custom routes. Entities are reachable through Core's generic CRUD
(`/app/crud/{verb}/mes/{entity}`). Domain verbs use Core's domain-action
registry (`POST /app/crud/{action}/mes/{entity}`): production-orders
`release`/`complete`/`cancel`, operations `start`/`complete`/`skip`,
quality-checks `execute`, non-conformances `resolve`/`close`, downtimes `close`,
boms `explode`, lot-numbers `forward_trace`/`backward_trace`. Registered by
`MesDomainActionRegistrar`; authorized by `MesModelPolicy` against seeded
`{connection}.{table}.{action}` permissions. Aggregate KPIs (OEE, capacity,
production totals) are surfaced as the `ProductionDashboardWidget`, not routes.

## Configuration

- `mes.queue.connection` / `mes.queue.name` — queue for backflush and PO jobs.
- `mes.lot_number_format` — lot code tokens `{YEAR}{MONTH}{DAY}{SEQ}`.
- `mes.rate_limit` — API requests per minute (seeded setting).
- `mes.production.default_warehouse` — `[company_id => warehouse_id]` map for
  sales-order-driven PO creation (falls back to the company's sole warehouse).
- `mes.production.daily_minutes` / `mes.production.default_lead_time_days` —
  routing-based lead-time estimation and its no-routing fallback.

## Locked decisions

See `docs/superpowers/specs/2026-07-09-mes-module-decisions-design.md` (D1–D10):
complete scope, ERP-based numbering, dual sales-order link, operation-scoped
backflush with last-operation fallback, non-blocking shift warning, snapshot
immutability, DIFF audit on `ProductionOrder`/`Bom`/`Routing`, materialised KPIs.
