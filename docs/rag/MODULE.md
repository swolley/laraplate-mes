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
| Quality | `QualityPlan`, `QualityPlanCharacteristic` (validity window), `QualityCheck`, `QualityCheckMeasurement`, `NonConformance` |
| Downtime | `Downtime` |
| Shifts | `Shift`, `ShiftInstance`, `OperatorLog` |
| Machine connectivity | `MachineSource` (Sanctum tokenable), `MachineProfile`, `MachineDevice`, `MachineSignal`, `UnmappedSignal`, `MachineMessage`, `MachineIncident` |

## Core flow

1. **Create** (`ProductionOrderService::create`) — allocates the number and
   freezes the effective BOM lines and routing operations into immutable JSON
   snapshots (`bom_snapshot`, `routing_snapshot`). Status `draft`.
2. **Release** (`release`) — materialises `ProductionOrderOperation` rows from
   the routing snapshot. Status `released`.
3. **Execute operations** (`ProductionOrderOperationService`) —
   `start`/`complete`/`skip`; efficiency = standard / actual, clamped
   `[0, 999.99]`. Completing an operation logs the operator (`OperatorLog`),
   dispatches `BackflushMaterialsJob`, and creates the in-process
   `QualityCheck` from any active plan (see «Quality plans»).
4. **Backflush** (`BackflushMaterialsJob`, idempotent) — consumes snapshot BOM
   lines marked `backflush` whose `routing_operation_id` matches the operation
   (or the order's last operation when null — decision D5). Each line reads
   on-hand stock via `StockReader` and consumes the **available** quantity: the
   stock `out` is posted for what exists, and any shortfall flags
   `mes_material_consumptions.stock_shortage` (with a negative `variance`) and
   emits `MaterialShortageDetected`. Non-blocking and stock never goes negative.
5. **Order state machine** — `draft → released` (`release`, emits
   `ProductionOrderReleased`) `→ in_progress` (the first `ProductionOrderOperationService::start()`
   on a released order, emits `ProductionOrderStarted`) `→ completed` (`complete`, emits
   `ProductionOrderCompleted`). `cancel` is allowed from draft, released and in progress: the
   operations that did not complete (planned or running) become `skipped`, and
   `ProductionOrderCancelled` is emitted. Operations emit `OperationStarted`,
   `OperationCompleted` and `OperationSkipped`. Events carry ids only and are dispatched
   after the transition is persisted.
6. **Complete order** (`complete`) — receives the finished goods into the order's warehouse
   (`FinishedGoodsReceiptService`, see below), refused (`DomainException`) while any
   operation is `in_progress`; sets produced quantity; generates the
   finished `LotNumber` when the item is lot/serial-traced; creates the
   final-inspection `QualityCheck` from any active plan.

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

## Quality plans

A `QualityPlan` is a date-effective (`version`/`valid_from`/`valid_to`/`is_active`)
set of expected characteristics (`QualityPlanCharacteristic`: `nominal`,
`lower_limit`, `upper_limit` — the same shape as `QualityCheckMeasurement`) for an
item, optionally scoped to a `routing_operation_id`. When an operation completes,
`QualityCheckPlanner` resolves the plan for `(item, routing_operation)` and creates
a pending `QualityCheck` linked to it; when the order completes it resolves the plan
with `routing_operation_id = null` (final inspection). Creation is non-blocking (no
plan → no-op) and idempotent per `(order, plan, operation)`. Operators later run the
existing `execute` action, which evaluates measurements and opens a non-conformance
on failure.

## Finished goods receipt and valuation

Completing an order posts an inbound movement of `quantity_produced` for the finished item in
the order's warehouse, in the same transaction as the completion (a failing receipt rolls the
completion back; nothing is posted for a quantity of zero). The unit cost is the order's
material cost over the quantity produced. The material cost is read through the
`ProductionCostReader` contract (`ErpProductionCostReader`): the sum of quantity x unit cost
of the ERP stock-outs sourced to the order, valued by the ERP (FIFO layer or weighted
average). `ErpStockMovementRecorder` now passes the production order as the movement source,
so every MES movement is traceable to it, and `StockMovementData` carries an optional
`unit_cost` for inbound movements. Labour and machine time have no cost rate, so they add
nothing. A backflush still queued when the order completes is not part of the cost.

## Stock shortage

`StockReader` (ERP-backed `ErpStockReader` over `StockLevel`) is the read side of
the stock boundary. Backflush and manual consumption read availability and consume
what exists: the stock-out is posted for the available quantity
(`quantity_consumed`), the shortfall is recorded as a negative `variance` with
`stock_shortage = true`, and `MaterialShortageDetected` is emitted. This keeps the
stock ledger truthful (never negative, which the ERP `recordOutbound` rejects) and
turns a hard ERP exception into a structured, non-blocking early warning. The
queued `NotifyMaterialShortage` listener sends `MaterialShortageNotification` to
the recipients configured by role (`mes.notifications.stock_shortage`).

## Services

`BomExplosionService` (multi-level explosion + active BOM), `RoutingResolverService`
(date-effective routing), `ProductionOrderService`, `ProductionOrderOperationService`,
`LotTracingService` (forward/backward genealogy), `QualityCheckService`
(limits → non-conformance), `NonConformanceService` (dispositions; rework spawns a
linked order), `CapacityService` (work-center load, available minutes, overload), `OeeCalculatorService`
(A×P×Q, clamped), `DowntimeService`, `ShiftVerificationService`,
`SalesOrderProductionPlanner` (auto-creation from confirmed sales orders, with
`ProductionWarehouseResolver` and `ProductionLeadTimeEstimator`),
`QualityPlanResolver` + `QualityCheckPlanner` (auto quality checks on completion),
`ErpStockReader` (`StockReader` on-hand read for shortage detection).

## HTTP surface

No custom routes. Entities are reachable through Core's generic CRUD
(`/app/crud/{verb}/mes/{entity}`). Domain verbs use Core's domain-action
registry (`POST /app/crud/{action}/mes/{entity}`): production-orders
`release`/`complete`/`cancel`, operations `start`/`complete`/`skip`,
quality-checks `execute`, non-conformances `resolve`/`close`, downtimes `close`, work-centers `open_downtime`,
boms `explode`, lot-numbers `forward_trace`/`backward_trace`. Registered by
`MesDomainActionRegistrar`; authorized by `MesModelPolicy` against seeded
`{connection}.{table}.{action}` permissions (seeded by `MESDatabaseSeeder`
from `MESPermissions`). A downtime is opened with the `open_downtime` action on its
work center (payload `cause`, optional `production_order_operation_id` and `notes`), which
refuses a second open downtime on the same work center and emits `DowntimeOpened`
(`close` emits `DowntimeClosed`); the generic insert still exists for back-filling
history and bypasses those rules. Aggregate reads have no routes: the `ProductionDashboardWidget`
shows four counts (open orders, running operations, completed orders, open
non-conformances) cached for 60 seconds. OEE and capacity are materialised
(decision D10): `mes:kpis:materialize` (hourly, `--day=Y-m-d` to backfill) queues one
`MaterializeWorkCenterKpisJob` per active work center, which stores a `WorkCenterKpis`
(availability, performance, quality, OEE, capacity load, available minutes, overload flag)
in the cache through `WorkCenterKpiMaterializer` and `WorkCenterKpiStore`. Reads never
recompute: a day not materialised yet has no figure. The work-center list shows the
OEE of today.

## Capacity and downtime

`CapacityService::getCapacityLoad()` sums the standard minutes (setup + cycle ×
planned quantity) of the operations planned on a work center in a window. An
operation with planned dates counts in proportion to its overlap with the window; one
without dates counts whole when its order overlaps it.
`availableMinutes()` is the working minutes of the work center calendar in the window
(`WorkCalendar`: `WorkCenterCalendar` slots by `day_of_week`, several per day; a work
center with no slot works 08:00 to 16:00 every day) less every downtime overlapping it (`DowntimeService::outOfServiceMinutesWithin()`:
planned maintenance included, because the work center is out of service either way;
each downtime is clipped to the window and an open one runs until now), never negative.
OEE availability keeps excluding planned maintenance
(`unplannedMinutesWithin()`) and clips downtimes to the window the same way.
`checkOverload(work_center, from, to, ?available)` compares the load with the
available minutes, or with an explicit budget when one is passed.

Planning is forward and infinite-capacity. `scheduleOperations(order)` (called by
`ProductionOrderService::release()`) writes `planned_start_at` / `planned_end_at` on each
planned operation: it starts when the previous one ends (a parallel one with it), at the
first working instant of its own work center, and lasts its standard minutes of working
time; it never looks at what else is planned on that work center.
`estimateCompletionDate()` plans the operations still to do from now (or from the planned
start while that is ahead) and returns the end of the last one; with nothing left it
returns the last actual end, or the order's `planned_end_at` when it has no operation.
`rescheduleOperation(operation, work_center, ?planned_start_at)` moves the operation and,
given a date, plans it again from there on the new calendar. Starting an operation on a work center whose materialised
KPIs flag an overload emits `CapacityOverloadDetected` (non-blocking, nothing is
recomputed live), notified to the roles in `mes.notifications.capacity_overload`.

## Machine connectivity

User guide and protocol: `docs/MACHINE_CONNECTIVITY.md`. Step 1 of the machine data acquisition design:
the foundation, with no consumer of the machine data yet.

- **Ingress.** `POST api/v1/mes/machine-data` (`mes.api.machine-data.ingest`):
  `AuthenticateMachineSource` (bearer token of a `MachineSource`, ability `mes:machine-ingest`; 401 unknown,
  403 missing ability, inactive source, or not an http canonical source), a per-token throttle
  (`mes.machine.rate_limit_per_minute`; Redis-backed, and the throttle runs before the source is known, so
  it keys on the token), `MachineDataIngestController` (413 body or sample limits, 422
  `MachineEnvelopeValidator`), then `MachineMessageInbox::accept()`, which stores the raw message through
  `IdempotentWriter` (insert-or-ignore on five drivers, a unique-violation fallback on Oracle), records
  `seq_gap` and `clock_skew` incidents and queues `ProcessMachineMessageJob`.
- **Job.** `ProcessMachineMessageJob` (queue `mes.machine.queue`, `WithoutOverlapping` keyed by source,
  3 tries) owns retries, status and the `message_failed` incident; the pipeline is
  `MachineMessageProcessor`: `NormalizerRegistry` (`canonical`, `mapped_json`), `SignalResolver` (one cached
  map per source, forgotten by `MachineConfigurationObserver`), `OperationAttributor` (explicit reference,
  else the single operation running at the sample time; `prepare()` loads the candidates of a message in one
  query), `UnmappedSignalRecorder` (batched; counts once per message and never on a reprocess), and the typed
  events `MachineStateObserved`, `PartsCounted`, `ProbeMeasured`, `ProcessValuesSampled`.
- **Tables.** Configuration tables (`mes_machine_sources/profiles/devices/signals`) extend Core's model with
  soft delete; pipeline tables (`mes_machine_messages`, `mes_unmapped_signals`, `mes_machine_incidents`) are
  plain Eloquent models. Messages are `MassPrunable` (processed ones past `inbox_retention_days`).
- **Operations.** `MachineMessageReprocessor` (per message, per source range), `MachineWatchdog` and
  `mes:machine-watchdog` (every minute: `device_silent` incidents), `MachineIncidentRecorder` and the
  `NotifyMachineIncident` listener (`mes.notifications.machine_incident`), `MachineProfileService` (apply,
  import, export; `MachineSignal::rulesForRole()` validates a signal's config by role),
  `UnmappedSignalMapper`, `MachineSourceTokenService` (one token per source, shown once).
- **Domain actions** (`MESPermissions`): `MachineMessage` `reprocess`; `MachineSource` `issue_token`,
  `revoke_token`, `reprocess_range`; `MachineDevice` `apply_profile`; `MachineProfile` `export`.
- **Backoffice** (group "Machine connectivity"): sources, devices with a signals relation manager, profiles
  (import and export), unmapped signals (map in place), the message inbox (reprocess), incidents.
  Not built yet: the MQTT bridge, `sparkplug_b`, states to downtime, counts, probe measurements, process
  values.

## Backoffice

The Filament resources are the superadmin backoffice. Work centers edit their
weekly calendar and BOMs their lines inline (relationship repeaters; line edits
are versioned). Production orders are list and edit only, with read-only
relation managers for operations, material consumptions, quality checks and
lots, and the edit page carries Release, Complete (produced quantity, optional lot
code) and Cancel header actions. They call `ProductionOrderService` and are shown by
`MesModelPolicy` (called directly: the gate would let a superadmin past the state
guard); a refused transition is a notification. Day-to-day execution still belongs to
the application built on the services and domain actions.

## Configuration

- `mes.queue.connection` / `mes.queue.name` — queue for backflush and PO jobs.
- `mes.lots.number_format` — lot code tokens `{YEAR}{MONTH}{DAY}{SEQ}`.
- `mes.production.default_warehouse` — `[company_id => warehouse_id]` map for
  sales-order-driven PO creation (falls back to the company's sole warehouse).
- `mes.production.daily_minutes` / `mes.production.default_lead_time_days` —
  routing-based lead-time estimation and its no-routing fallback.
- `mes.notifications.stock_shortage.channels` / `.recipients.roles` — channels
  (default `database`) and recipient roles for the shortage notification.
- `mes.machine.queue` (`mes-machine`), `.max_samples` (5000), `.max_body_kb` (1024),
  `.clock_skew_seconds` (30), `.inbox_retention_days` (7), `.rate_limit_per_minute` (600) — machine
  connectivity; env `MES_MACHINE_*`. `mes.notifications.machine_incident` — channels and roles for incidents.

## Locked decisions

See `docs/superpowers/specs/2026-07-09-mes-module-decisions-design.md` (D1–D11):
complete scope, ERP-based numbering, dual sales-order link, operation-scoped
backflush with last-operation fallback, non-blocking shift warning, snapshot
immutability, DIFF audit on `ProductionOrder`/`Bom`/`Routing` and on their lines and operations (`BomLine`, `RoutingOperation`; per-table setting `versioning.strategy.{table}`, seeded to `DIFF`; lines and operations are validated on every write and deleted for good), materialised KPIs (decided, not implemented yet),
and no lock by use: a BOM or routing stays editable because every order runs from its own snapshot.

## Releases

This module is released from the application, not from its own repository: it carries no release scripts and no `cliff.toml`. From the `laraplate` root, `scripts/version.sh` bumps the `version` field of `Modules/MES/composer.json`, regenerates `Modules/MES/CHANGELOG.md` with the application's `cliff.toml`, commits `chore(release): vX.Y.Z` in the module repository, tags it and pushes both.

```bash
composer run version:dry MES      # print the plan, write nothing
composer run version:minor MES    # release with a forced level (also version:major, version:patch)
composer run version:all             # every module with pending commits, then the application
```

Without a forced level, git-cliff infers it from the conventional commits since the module's last tag. `CHANGELOG.md` lists released versions only. Releasing the module alone does not touch the application; `version:all` records the module in the application with a commit typed after the module's release level. Full reference: `docs/releasing.md` in the application.
