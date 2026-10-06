<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/swolley" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="300" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>
<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.5+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/License-GNU_AGPL_v3-green?style=for-the-badge" alt="MIT License">
</p>
<p>&nbsp;</p>

# Laraplate Manufacturing Execution Module

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to instroduce your ideas. Let me know your ideas...

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Current Status](#current-status)
-   [Roadmap](#roadmap)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The MES Module provides the Manufacturing Execution System foundation for Laraplate.
It is designed to host production workflows, work orders, shop-floor events, traceability, and manufacturing KPIs.

It depends on the ERP module (items, warehouses, stock, companies, sales orders); the dependency runs one way, ERP knows nothing of MES.

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-core.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-mes.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer swolley/laraplate-core swolley/laraplate-mes
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
php artisan module:install MES
```

## Configuration

The module configuration is automatically mapped as `mes.*` when the module is active.
Configuration file: `Modules/MES/config/config.php`.

```env
# MES activation toggle (example)
MES_ENABLED=true

# Queue used by MES jobs and listeners (backflush, production-order auto-creation)
MES_QUEUE_CONNECTION=database
MES_QUEUE_NAME=mes

# Production-order auto-creation from confirmed sales orders
MES_PRODUCTION_DAILY_MINUTES=480
MES_PRODUCTION_DEFAULT_LEAD_TIME_DAYS=5

# Machine connectivity (see docs/MACHINE_CONNECTIVITY.md)
MES_MACHINE_QUEUE=mes-machine
MES_MACHINE_MAX_SAMPLES=5000
MES_MACHINE_MAX_BODY_KB=1024
MES_MACHINE_CLOCK_SKEW_SECONDS=30
MES_MACHINE_INBOX_RETENTION_DAYS=7
MES_MACHINE_RATE_LIMIT_PER_MINUTE=600

# MQTT broker of the bridge (`php artisan mes:machine-bridge`), one per installation
MES_MACHINE_MQTT_HOST=127.0.0.1
MES_MACHINE_MQTT_PORT=1883
MES_MACHINE_MQTT_USERNAME=
MES_MACHINE_MQTT_PASSWORD=
MES_MACHINE_MQTT_TLS=false
MES_MACHINE_MQTT_CLIENT_ID=laraplate-mes-bridge
MES_MACHINE_MQTT_TOPIC_PREFIX=laraplate
```

The machine variables set the config keys `mes.machine.queue`, `mes.machine.max_samples`,
`mes.machine.max_body_kb`, `mes.machine.clock_skew_seconds`, `mes.machine.inbox_retention_days` and
`mes.machine.rate_limit_per_minute`; the MQTT ones set `mes.machine.mqtt.host`, `mes.machine.mqtt.port`, `mes.machine.mqtt.username`, `mes.machine.mqtt.password`, `mes.machine.mqtt.tls`, `mes.machine.mqtt.client_id` and `mes.machine.mqtt.topic_prefix`. The machine queue (`mes-machine`) needs its own Horizon supervisor;
incident notifications go to the roles under `mes.notifications.machine_incident`.

The receiving warehouse for auto-created production orders is resolved per company.
Set it explicitly through the `mes.production.default_warehouse` config map
(`[company_id => warehouse_id]`) when a company owns more than one warehouse; with a
single warehouse the module picks it automatically.

> The effective set of environment variables will be expanded as domain features are introduced.

## Current Status

The manufacturing domain is implemented and covered by the module test suite:

-   Work centers with a weekly calendar, BOMs and routings with validity windows and DIFF versioning (lines and operations included)
-   Production orders numbered by ERP `DocumentNumberAllocator`, with immutable BOM/routing snapshots, release/complete/cancel. Starting the first operation moves a released order to `in_progress`; cancelling an order that has not completed ends its running and pending operations as skipped; an order cannot complete while an operation is in progress. Every transition emits a typed event (`ProductionOrderReleased/Started/Completed/Cancelled`, `OperationStarted/Completed/Skipped`, `DowntimeOpened/Closed`)
-   Automatic draft orders from confirmed ERP sales orders (`SalesOrderConfirmed` listener)
-   Operation execution (start/complete/skip) with efficiency, operator logs and non-blocking shift warnings
-   Backflush and manual material consumption through the `StockMovementRecorder` contract, with partial consumption and a shortage notification when stock is short
-   Completion receives the finished goods into stock, valued at the material cost consumed per unit produced (labour and machine time carry no cost rate yet)
-   Lot and serial generation with forward/backward lot genealogy
-   Quality plans, automatic quality checks, non-conformances with dispositions (rework spawns a linked order)
-   Downtime, OEE (A x P x Q, clamped to [0, 1]) and work-center capacity load, schedule and overload check (available minutes from the work center calendar, net of every downtime, planned maintenance included; operations get planned dates on release, forward and infinite-capacity, and `estimateCompletionDate()` plans what is left from now). A downtime is opened through the `open_downtime` action on its work center (one open downtime per work center). OEE and capacity are materialised per work center and day by `mes:kpis:materialize` (hourly, queued) into the cache and shown as an OEE column in the work-center list; starting an operation on a work center materialised as overloaded emits `CapacityOverloadDetected`, notified through `mes.notifications.capacity_overload`
-   No custom routes: entities go through Core's generic CRUD, domain verbs through the domain-action registry (`MesDomainActionRegistrar`, `MesModelPolicy`, permissions seeded by `MESDatabaseSeeder`)
-   Filament backoffice (`Modules\MES\Filament\MESPlugin`): resources for work centers (with calendar), BOMs (with lines), routings, production orders (read-only operations, consumptions, quality checks and lots, and Release/Complete/Cancel header actions), quality plans, quality checks, non-conformances, downtimes and shifts, plus a production dashboard widget with four cached counts

-   Machine connectivity foundation: machines and probes push `laraplate-machine/1` messages over HTTP (`POST api/v1/mes/machine-data`, one token per source) into a durable inbox processed by an idempotent, per-source serialised job (normalise, resolve, attribute, typed events); unmapped signals, incidents, a watchdog for silent devices, reprocessing, importable machine profiles, and a "Machine connectivity" backoffice. Guide: `docs/MACHINE_CONNECTIVITY.md`

Developer reference: `docs/rag/MODULE.md`. Operator guide (Italian): `docs/MES_GUIDA_SEMPLICE.md`.

## Roadmap

Machine data acquisition (design: `docs/superpowers/specs/2026-10-05-mes-machine-data-acquisition-design.md` at the stack root). The foundation is built; each step below has its own plan:

-   MQTT bridge and the `sparkplug_b` normaliser
-   Machine states to downtimes, and OEE availability with machine data
-   Piece counts, and OEE performance and quality (ISO 22400)
-   Probe measurements filling quality checks
-   Process values (relational store, rollups, per-operation summaries)

## Scripts

The module has no Composer scripts of its own. Run tests and checks from the **laraplate root**:

```bash
# MES test suite
php artisan test --compact Modules/MES/tests

# Static analysis of the module with the root configuration
vendor/bin/phpstan analyse Modules/MES/app

# Formatting: always pass an explicit file list
vendor/bin/pint --format agent Modules/MES/app/Services/CapacityService.php
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

MES Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).
