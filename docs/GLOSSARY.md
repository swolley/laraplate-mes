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
| **BelongsToCompany** | ERP trait + global scope imported by all MES models. |

## Work centers (implemented)

| Term | Meaning |
|------|---------|
| **WorkCenter** | Physical production resource (machine, cell, line, manual station) with capacity and calendar. |
| **WorkCenterType** | Enum: `machine`, `cell`, `line`, `manual_station`. |
| **WorkCenterCalendar** | Weekly availability slot for a `WorkCenter` (day-of-week, start/end time). |
| **capacity_per_hour** | Decimal capacity on `WorkCenter`; paired with `capacity_uom`. |
| **scopeActive()** | Query scope returning only `is_active` work centers. |

## Bill of materials (planned)

| Term | Meaning |
|------|---------|
| **BOM** | Bill of Materials: hierarchical list of components and quantities for a finished or semi-finished item. |
| **BomLine** | Single BOM row: component `item_id` (ERP), quantity, UOM, consumption method (`backflush` or `manual`). |
| **Backflush** | Automatic material consumption at operation or order completion. |

## Routing and operations (planned)

| Term | Meaning |
|------|---------|
| **Routing** | Ordered sequence of operations required to produce an item. |
| **RoutingOperation** | One routing step: work center, setup time, cycle time. |
| **ProductionOrder** | Manufacturing order for a quantity of an item; optional link to ERP `SalesOrder`. |
| **ProductionOrderOperation** | Instance of a `RoutingOperation` on a specific `ProductionOrder` with planned/actual times and status. |

## Materials and inventory integration

| Term | Meaning |
|------|---------|
| **Item** (ERP) | Product master referenced by BOM lines and production orders via FK on `items`. |
| **Warehouse** (ERP) | Storage location referenced for issues and receipts. |
| **MaterialConsumption** | Record of actual component usage during production. |
| **StockMovementRecorder** | Contract implemented by ERP (`StockMovementService`); MES calls it for inbound/outbound postings without knowing FIFO/costing internals. |
| **MesStockMovementRecorderAdapter** | MES-side adapter binding to the ERP contract (registered in `MESServiceProvider`). |

## Traceability (planned)

| Term | Meaning |
|------|---------|
| **LotNumber** | Batch identifier for traceability of produced or purchased quantities. |
| **SerialNumber** | Unique identifier per manufactured unit. |

## Quality (planned)

| Term | Meaning |
|------|---------|
| **QualityCheck** | Inspection on a lot or operation with measurements and outcome. |
| **NonConformance** | Defect, scrap, or rework event during or after production. |

## Planning and capacity (planned)

| Term | Meaning |
|------|---------|
| **ProductionSchedule** | Plan assigning production orders to work centers and time windows. |
| **CapacityLoad** | Planned load vs available capacity for a `WorkCenter` in a period. |
| **Downtime** | Recorded machine stop (breakdown, maintenance, setup, waiting). |
| **Shift** | Work shift with operators and covered work centers. |
| **OperatorLog** | Operator activity log: operations, times, materials consumed. |

## Document numbering (planned)

| Term | Meaning |
|------|---------|
| **DocumentNumberAllocator** | ERP service for per-company document sequences; MES production documents will reuse this pattern. |

## External ERP integration

| Term | Meaning |
|------|---------|
| **ERPBridge** | Optional separate module syncing external ERP data into Laraplate ERP tables and implementing MES contracts. Not part of MES core. |
| **SalesOrder** (ERP) | Customer order; `ProductionOrder` may optionally reference it via nullable FK. |

## General

| Term | Meaning |
|------|---------|
| **UOM** | Unit of measure. |

## Related reading

- `.kiro/specs/mes-module/requirements.md` — full requirements and acceptance criteria
- `Modules/ERP/docs/GLOSSARY.md` — ERP entities consumed by MES
- `docs/rag/GLOSSARY.md` — RAG-optimized copy for documentation indexing
