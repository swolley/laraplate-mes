# MES module glossary

Canonical English names for MES entities in this module. Use these terms in code, APIs, and cross-module documentation.

## Module scope

| Term | Meaning |
|------|---------|
| **MES_System** | The MES module: manufacturing execution, shop-floor tracking, traceability. |
| **mes_** prefix | All MES tables use this prefix to avoid collisions with ERP/Core tables. |
| **ERP dependency** | MES depends on ERP for `items`, `warehouses`, `stock_movements`, `companies`, and costing contracts. Dependency is unidirectional: MES → ERP. |

## Multi-tenancy (from ERP)

| Term | Meaning |
|------|---------|
| **Company** | Tenant root from ERP; every MES row is company-scoped. |
| **BelongsToCompany** | ERP trait + global scope on the company-owned MES headers (`WorkCenter`, `Bom`, `Routing`, `ProductionOrder`). Other rows carry a plain `company_id` or reach the company through their parent. |

## Work centers

| Term | Meaning |
|------|---------|
| **WorkCenter** | Physical production resource (machine, cell, line, manual station) with capacity and calendar. `code` is unique per company (database index and validation rule). |
| **WorkCenterType** | Enum: `machine`, `cell`, `line`, `manual_station`. |
| **WorkCenterCalendar** | Weekly availability slot for a `WorkCenter` (`day_of_week` 0 = Monday to 6 = Sunday, start/end time), edited inline in the work-center form. `CapacityService` reads it for available minutes and planning (`WorkCalendar`); with no slot at all a work center works 08:00 to 16:00 every day. |
| **capacity_per_hour** | Decimal capacity on `WorkCenter`; paired with `capacity_uom`. |
| **scopeActive()** | Query scope returning only `is_active` work centers. |

## Bill of materials

| Term | Meaning |
|------|---------|
| **BOM** | Bill of Materials: hierarchical list of components and quantities for a finished or semi-finished item. |
| **BomLine** | Single BOM row: component `item_id` (ERP), quantity, UOM, consumption method (`backflush` or `manual`), optional `routing_operation_id` naming the operation that backflushes it. |
| **Snapshot** | Frozen copy of the active BOM lines (`bom_snapshot`) and routing operations (`routing_snapshot`) stored on a `ProductionOrder` at creation; later BOM or routing edits never change it. |
| **Backflush** | Automatic consumption of `backflush` BOM lines when their routing operation completes (the order's last operation when the line names none), by the idempotent `BackflushMaterialsJob`. |

## Routing and operations

| Term | Meaning |
|------|---------|
| **Routing** | Ordered sequence of operations required to produce an item. |
| **RoutingOperation** | One routing step: work center, setup time, cycle time. |
| **ProductionOrder** | Manufacturing order for a quantity of an item; number from ERP `DocumentNumberAllocator`, status `draft` → `released` → `completed` (or `cancelled`), optional link to an ERP `SalesOrder` and line. It cannot complete while one of its operations is in progress. |
| **ProductionOrderOperation** | Instance of a `RoutingOperation` on a specific `ProductionOrder`, generated on release, with status (`planned`, `ready`, `in_progress`, `completed`, `skipped`), actual times and efficiency. |

## Materials and inventory integration

| Term | Meaning |
|------|---------|
| **Item** (ERP) | Product master referenced by BOM lines and production orders via FK on `items`. |
| **Warehouse** (ERP) | Storage location referenced for issues and receipts. |
| **MaterialConsumption** | Record of actual component usage on an order operation, backflushed or recorded manually, with planned and consumed quantity, `variance` and the `stock_shortage` flag. |
| **StockMovementRecorder** | MES contract for inbound/outbound stock postings; MES calls it without knowing FIFO/costing internals. |
| **ErpStockMovementRecorder** | MES adapter implementing `StockMovementRecorder` over the ERP stock service, bound in `MESServiceProvider`. |
| **ProductionCostReader** | Read-side contract (`ErpProductionCostReader`) returning the cost of the stock-outs posted against a production order; the unit cost of the finished-goods receipt comes from it. |
| **StockReader** | Read-side contract (`ErpStockReader` over ERP `StockLevel`) letting MES check on-hand availability before consuming. |
| **MaterialShortageDetected** | Event emitted when a consumption cannot be fully covered: the available quantity is consumed, the shortfall is flagged (`stock_shortage`, negative `variance`), and `NotifyMaterialShortage` sends a notification. |
| **SalesOrderProductionPlanner** | Creates production orders for the manufactured lines of a confirmed sales order (ERP event `SalesOrderConfirmed`). |

## Traceability

| Term | Meaning |
|------|---------|
| **LotNumber** | Batch identifier for traceability of produced or purchased quantities; generated when an order completes for an item traced by lot or serial (`tracing_type`). |
| **SerialNumber** | Unique identifier per manufactured unit, optionally within a lot. |
| **LotLineage** | Parent → child edge between two lots (optionally with the order and quantity); `LotTracingService` walks it forward and backward. |

## Quality

| Term | Meaning |
|------|---------|
| **QualityPlan** | Date-effective set of expected characteristics for an item, optionally scoped to a routing operation; drives automatic `QualityCheck` creation on operation/order completion. |
| **QualityPlanCharacteristic** | Expected trait and tolerance band (nominal, lower/upper limit) within a `QualityPlan`. |
| **QualityCheck** | Inspection on an order or operation with measurements and outcome (`pending`, `passed`, `failed`, `conditional`); a failed check opens a non-conformance. |
| **NonConformance** | Defect, scrap, or rework event during or after production (`open`, `under_review`, `resolved`, `closed`), resolved with a disposition (`scrap`, `rework`, `use_as_is`, `return_to_supplier`); `rework` spawns a linked production order. |

## Planning, capacity, downtime and shifts

| Term | Meaning |
|------|---------|
| **Downtime** | Recorded work-center stop with a `DowntimeCause` (`breakdown`, `setup`, `changeover`, `material_shortage`, `quality`, `planned_maintenance`, `other`), opened and closed by `DowntimeService`, duration computed on close. An open downtime marks the work center down. |
| **Unplanned downtime** | Every downtime except `planned_maintenance`; it lowers OEE availability and the capacity available minutes. |
| **OEE** | Overall Equipment Effectiveness = Availability × Performance × Quality for a work center over a window, each factor and the result clamped to [0, 1] (`OeeCalculatorService`). Materialised per work center and day by `mes:kpis:materialize` and shown in the work-center list; availability counts only the part of each unplanned downtime inside the window. |
| **Capacity load** | `CapacityService::getCapacityLoad()`: standard minutes (setup + cycle × planned quantity) of the operations planned on a work center within a window. |
| **Available minutes** | `CapacityService::availableMinutes()`: working minutes of the work center calendar in the window (08:00 to 16:00 every day when it has none), less every downtime overlapping it, planned maintenance included; never negative. `checkOverload()` compares the load against it. |
| **Schedule** | `CapacityService::getSchedule()`: operations of a company's orders planned within a window, by work center and sequence. There is no stored schedule entity. |
| **Shift** | Named daily time window of a company (start/end time). |
| **ShiftInstance** | A shift on a given date for a work center (`starts_at`/`ends_at`); a missing one only raises a non-blocking warning. |
| **OperatorLog** | Operator action (`started`, `completed`, `paused`, `resumed`) on an operation, written on every start and complete and linked to the shift instance covering that moment. |

## Document numbering

| Term | Meaning |
|------|---------|
| **DocumentNumberAllocator** | ERP service for per-company document sequences; production order numbers use `DocumentType::ProductionOrder`. |

## Machine connectivity

| Term | Meaning |
|------|---------|
| **Machine source** | Whatever sends machine data: our edge agent or a customer gateway. One source serves many devices; it has a normaliser, a transport and one token. |
| **Machine device** | A physical machine as its source names it (`external_id`), tied to a work center. |
| **Machine signal** | One tag of a device, with a role and a per-role config. |
| **Signal role** | What a signal means: `state`, `alarm`, `good_count`, `scrap_count`, `total_count`, `measurement`, `process_value`, `order_reference`, `operation_reference`. |
| **Machine profile** | A reusable, importable and exportable set of signals and maps for a machine model; applying it copies its signals onto a device. |
| **Machine message** | One stored raw delivery (`mes_machine_messages`): pending, processed or failed; reprocessable. |
| **Normaliser** | Turns a source's payload into the canonical samples (`canonical`, `mapped_json`). |
| **Unmapped signal** | A device or signal a source sent that nobody configured, or a raw state value missing from a state map (`{key}#{value}`). |
| **Attribution** | Deciding which operation a sample belongs to, by the sample time: an explicit reference, else the single operation running then. |
| **MQTT bridge** | The long-running command `mes:machine-bridge`: subscribes to the broker for the active mqtt sources and feeds the machine message inbox; its heartbeat is watched by the watchdog (`bridge_down`). |
| **Sparkplug B** | An MQTT payload format for industrial data (`spBv1.0/{group}/{type}/{node}[/{device}]`), read by the `sparkplug_b` normaliser. |
| **Edge node** | In Sparkplug B, the gateway that publishes for itself and for its devices; its id is the MES device id of node-level metrics. |
| **Alias** | In Sparkplug B, a number a birth gives to a metric name so that later data can carry the number only; kept in `mes_sparkplug_aliases`. |
| **Machine incident** | A connectivity problem worth a human: `seq_gap`, `clock_skew`, `message_failed`, `auth_failure`, `device_silent`. |

## External ERP integration

| Term | Meaning |
|------|---------|
| **ERPBridge** | Optional separate module syncing external ERP data into Laraplate ERP tables and implementing MES contracts. Not part of MES core. |
| **SalesOrder** (ERP) | Customer order; `ProductionOrder` may optionally reference it via nullable FK. |

## General

| Term | Meaning |
|------|---------|
| **UOM** | Unit of measure. |
| **Domain action** | A verb on a record run through Core's domain-action registry (`release`, `start`, `explode`, ...), registered by `MesDomainActionRegistrar` and authorized by `MesModelPolicy` against seeded permissions. |
| **State interval** | A stretch of time a machine device spent in one canonical state (`mes_machine_state_intervals`); the open one has no end. |
| **Micro-stop** | A stop no longer than the work center's `micro_stop_threshold_seconds`; it leaves no downtime. |
| **Connected work center** | A work center with an active device, of an active source, with a state signal: its downtimes come from the machine and its OEE availability follows ISO 22400. |
| **Machine downtime** | A downtime derived from a state interval (`source = machine`); its times are locked, its cause and notes can be edited. |

## Related reading

- `docs/superpowers/specs/2026-07-09-mes-module-decisions-design.md` — locked decisions (scope, ERP integration, backflush)
- `docs/superpowers/plans/2026-06-19-mes-module-full-implementation.md` — implementation plan and task breakdown
- `Modules/ERP/docs/GLOSSARY.md` — ERP entities consumed by MES
- `docs/rag/GLOSSARY.md` — RAG-optimized copy for documentation indexing
