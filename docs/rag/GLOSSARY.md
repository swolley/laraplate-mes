# MES module glossary

Canonical English names for MES entities in this module. Use these terms in code, APIs, and cross-module documentation.

## Module scope


| Term              | Meaning                                                                                         |
| ----------------- | ----------------------------------------------------------------------------------------------- |
| **MES_System**    | The MES module: manufacturing execution, shop-floor tracking, traceability.                     |
| **mes_** prefix   | All MES tables use this prefix to avoid collisions with ERP/Core tables.                          |
| **ERP dependency**| MES depends on ERP for `items`, `warehouses`, `stock_movements`, `companies`, and costing contracts. Dependency is unidirectional: MES → ERP. |


## Multi-tenancy (from ERP)


| Term                 | Meaning                                                       |
| -------------------- | ------------------------------------------------------------- |
| **Company**          | Tenant root from ERP; every MES row is company-scoped.        |
| **BelongsToCompany** | ERP trait + global scope on the company-owned MES headers (`WorkCenter`, `Bom`, `Routing`, `ProductionOrder`). Other rows carry a plain `company_id` or reach the company through their parent. |


## Work centers


| Term                      | Meaning                                                                                  |
| ------------------------- | ---------------------------------------------------------------------------------------- |
| **WorkCenter**            | Physical production resource (machine, cell, line, manual station) with capacity and calendar. `code` is unique per company (database index and validation rule). |
| **WorkCenterType**        | Enum: `machine`, `cell`, `line`, `manual_station`.                                       |
| **WorkCenterCalendar**    | Weekly availability slot for a `WorkCenter` (`day_of_week` 0 = Monday to 6 = Sunday, start/end time), edited inline in the work-center form. Capacity calculations do not read it yet. |
| **capacity_per_hour**     | Decimal capacity on `WorkCenter`; paired with `capacity_uom`.                            |
| **scopeActive()**         | Query scope returning only `is_active` work centers.                                     |


## Bill of materials


| Term           | Meaning                                                                                          |
| -------------- | ------------------------------------------------------------------------------------------------ |
| **BOM**        | Bill of Materials: hierarchical list of components and quantities for a finished or semi-finished item. |
| **BomLine**    | Single BOM row: component `item_id` (ERP), quantity, UOM, consumption method (`backflush` or `manual`), optional `routing_operation_id` naming the operation that backflushes it. |
| **Snapshot** | Frozen copy of the active BOM lines (`bom_snapshot`) and routing operations (`routing_snapshot`) stored on a `ProductionOrder` at creation; later BOM or routing edits never change it. |
| **Backflush**  | Automatic consumption of `backflush` BOM lines when their routing operation completes (the order's last operation when the line names none), by the idempotent `BackflushMaterialsJob`. |


## Routing and operations


| Term                            | Meaning                                                                                  |
| ------------------------------- | ---------------------------------------------------------------------------------------- |
| **Routing**                     | Ordered sequence of operations required to produce an item.                              |
| **RoutingOperation**            | One routing step: work center, setup time, cycle time.                                   |
| **ProductionOrder**             | Manufacturing order for a quantity of an item; number from ERP `DocumentNumberAllocator`, status `draft` → `released` → `completed` (or `cancelled`), optional link to an ERP `SalesOrder` and line. It cannot complete while one of its operations is in progress. |
| **ProductionOrderOperation**    | Instance of a `RoutingOperation` on a specific `ProductionOrder`, generated on release, with status (`planned`, `ready`, `in_progress`, `completed`, `skipped`), actual times and efficiency. |


## Materials and inventory integration


| Term                              | Meaning                                                                                       |
| --------------------------------- | --------------------------------------------------------------------------------------------- |
| **Item** (ERP)                    | Product master referenced by BOM lines and production orders via FK on `items`.               |
| **Warehouse** (ERP)               | Storage location referenced for issues and receipts.                                          |
| **MaterialConsumption**           | Record of actual component usage on an order operation, backflushed or recorded manually, with planned and consumed quantity, `variance` and the `stock_shortage` flag. |
| **StockMovementRecorder**         | MES contract for inbound/outbound stock postings; MES calls it without knowing FIFO/costing internals. |
| **ErpStockMovementRecorder** | MES adapter implementing `StockMovementRecorder` over the ERP stock service, bound in `MESServiceProvider`. |
| **StockReader**                   | Read-side contract (`ErpStockReader` over ERP `StockLevel`) letting MES check on-hand availability before consuming. |
| **MaterialShortageDetected**      | Event emitted when a consumption cannot be fully covered: the available quantity is consumed, the shortfall is flagged (`stock_shortage`, negative `variance`), and `NotifyMaterialShortage` sends a notification. |
| **SalesOrderProductionPlanner**   | Creates production orders for the manufactured lines of a confirmed sales order (ERP event `SalesOrderConfirmed`). |


## Traceability


| Term              | Meaning                                                         |
| ----------------- | --------------------------------------------------------------- |
| **LotNumber**     | Batch identifier for traceability of produced or purchased quantities; generated when an order completes for an item traced by lot or serial (`tracing_type`). |
| **SerialNumber**  | Unique identifier per manufactured unit, optionally within a lot. |
| **LotLineage** | Parent → child edge between two lots (optionally with the order and quantity); `LotTracingService` walks it forward and backward. |


## Quality


| Term                 | Meaning                                                              |
| -------------------- | -------------------------------------------------------------------- |
| **QualityPlan**      | Date-effective set of expected characteristics for an item, optionally scoped to a routing operation; drives automatic `QualityCheck` creation on operation/order completion. |
| **QualityPlanCharacteristic** | Expected trait and tolerance band (nominal, lower/upper limit) within a `QualityPlan`. |
| **QualityCheck**     | Inspection on an order or operation with measurements and outcome (`pending`, `passed`, `failed`, `conditional`); a failed check opens a non-conformance. |
| **NonConformance**   | Defect, scrap, or rework event during or after production (`open`, `under_review`, `resolved`, `closed`), resolved with a disposition (`scrap`, `rework`, `use_as_is`, `return_to_supplier`); `rework` spawns a linked production order. |


## Planning, capacity, downtime and shifts


| Term                    | Meaning                                                              |
| ----------------------- | -------------------------------------------------------------------- |
| **Downtime**            | Recorded work-center stop with a `DowntimeCause` (`breakdown`, `setup`, `changeover`, `material_shortage`, `quality`, `planned_maintenance`, `other`), opened and closed by `DowntimeService`, duration computed on close. An open downtime marks the work center down. |
| **Unplanned downtime** | Every downtime except `planned_maintenance`; it lowers OEE availability and the capacity available minutes. |
| **OEE** | Overall Equipment Effectiveness = Availability × Performance × Quality for a work center over a window, each factor and the result clamped to [0, 1] (`OeeCalculatorService`). Computed on request; not materialised and not shown in the panel yet. |
| **Capacity load** | `CapacityService::getCapacityLoad()`: standard minutes (setup + cycle × planned quantity) of the operations planned on a work center within a window. |
| **Available minutes** | `CapacityService::availableMinutes()`: 480 default minutes per calendar day of the window, less the unplanned downtime overlapping it; never negative. `checkOverload()` compares the load against it. |
| **Schedule** | `CapacityService::getSchedule()`: operations of a company's orders planned within a window, by work center and sequence. There is no stored schedule entity. |
| **Shift**               | Named daily time window of a company (start/end time). |
| **ShiftInstance** | A shift on a given date for a work center (`starts_at`/`ends_at`); a missing one only raises a non-blocking warning. |
| **OperatorLog**         | Operator action (`started`, `completed`, `paused`, `resumed`) on an operation, written on every start and complete and linked to the shift instance covering that moment. |


## Document numbering


| Term                         | Meaning                                                                              |
| ---------------------------- | ------------------------------------------------------------------------------------ |
| **DocumentNumberAllocator**  | ERP service for per-company document sequences; production order numbers use `DocumentType::ProductionOrder`. |


## External ERP integration


| Term              | Meaning                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------ |
| **ERPBridge**     | Optional separate module syncing external ERP data into Laraplate ERP tables and implementing MES contracts. Not part of MES core. |
| **SalesOrder** (ERP) | Customer order; `ProductionOrder` may optionally reference it via nullable FK.                |


## General


| Term    | Meaning            |
| ------- | ------------------ |
| **UOM** | Unit of measure.   |
| **Domain action** | A verb on a record run through Core's domain-action registry (`release`, `start`, `explode`, ...), registered by `MesDomainActionRegistrar` and authorized by `MesModelPolicy` against seeded permissions. |


## Related reading

- `docs/superpowers/specs/2026-07-09-mes-module-decisions-design.md` — locked MES decisions
- `docs/superpowers/plans/2026-06-19-mes-module-full-implementation.md` — implementation plan
- `Modules/ERP/docs/GLOSSARY.md` — ERP entities consumed by MES
- `docs/GLOSSARY.md` — developer-oriented glossary (this file is the RAG index copy)
