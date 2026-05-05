<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/swolley" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="400" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided.

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Current Bootstrap Status](#current-bootstrap-status)
-   [Roadmap](#roadmap)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The MES Module provides the Manufacturing Execution System foundation for Laraplate.
It is designed to host production workflows, work orders, shop-floor events, traceability, and manufacturing KPIs.

At this stage, the module is intentionally initialized with a minimal structure to support incremental, test-driven development.

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
```

> The effective set of environment variables will be expanded as domain features are introduced.

## Current Bootstrap Status

-   Module metadata (`module.json`) configured with provider registration
-   Service providers scaffolded (`MESServiceProvider`, `RouteServiceProvider`, `EventServiceProvider`)
-   Base folders for HTTP, config, routes, resources, database, and tests in place
-   Composer package scaffolded with scripts and autoload mappings
-   Independent git repository initialized under `Modules/MES` (ready to be switched to submodule workflow)

## Roadmap

Planned next steps for MES domain design and implementation:

-   Manufacturing domain model (work centers, operations, routings, work orders)
-   Production execution workflows and status transitions
-   Material issue/consumption and reporting integration
-   Quality checkpoints and non-conformance handling
-   OEE-oriented telemetry and analytics endpoints

## Scripts

Run commands from the **MES module root** after `composer install`.

```bash
# Run all tests and quality checks
composer test

# Run specific checks
composer test:unit
composer test:type-coverage
composer test:lint
composer test:types
composer test:refactor
```

```bash
# Local formatting (dirty files only from project root)
vendor/bin/pint --dirty
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

MES Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).
