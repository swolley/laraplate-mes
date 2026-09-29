# Changelog

All notable changes to this project will be documented in this file.

## [1.1.1] - 2026-09-29

### 🚀 Features

- *(mes)* Order and head the production dashboard widget
- *(mes)* Create and edit pages say Close until something is unsaved

### 🐛 Bug Fixes

- *(docs)* Update README with PHP version badge and logo size adjustment

### 💼 Other

- Drop tracing_type patch migration on ERP items

The column now belongs to the ERP items schema (intrinsic Item attribute used
across purchase/warehouse/sales, not only production). MES keeps consuming
item.tracing_type in ProductionOrderService without owning the column.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>

### 🚜 Refactor

- *(migrations)* Fold column/index/enum alters into their create migrations
- *(migrations)* Fold FK-column alters into their create by moving the referenced table earlier
- *(migrations)* Fold cascade FK-column alters into their create migrations
- *(models)* Drop the IdeHelper mixins, declare the activation contract
- Narrow findOrFail to a single record with whereKey()->firstOrFail()
- *(mes)* Reject a non-numeric record id instead of casting it
- *(mes)* Drop unread rate limit setting and config default for the lot format
- *(mes)* Seed settings without the module prefix
- *(mes)* Lots.number_format setting name

### 📚 Documentation

- *(rag)* Describe how the module is released from the application

### ⚡ Performance

- *(migrations)* Index all foreign-key and row-scoping columns

### ⚙️ Miscellaneous Tasks

- Rimuove docblock ide-helper generati dai model
- Add IdeHelper mixin annotations to model classes
- Trim the docblocks the IdeHelper mixins left behind
- Rename composer package to swolley/laraplate-mes

## [1.1.0] - 2026-09-15

### 🚀 Features

- *(filament)* Expose the MES surfaces in the admin panel
- *(mes)* Enhance module description and add color support

### 🚜 Refactor

- *(tables)* Remove IconColumn from multiple tables
- *(models)* Remove unnecessary comment lines in model properties

### 📚 Documentation

- *(changelog)* Regenerate with the corrected git-cliff configuration

### 🎨 Styling

- Format with the application's Pint configuration

### ⚙️ Miscellaneous Tasks

- The module carries functionality, not the toolchain

## [1.0.1] - 2026-09-09

### 🚜 Refactor

- *(mes)* Update permission handling in DevMESDatabaseSeeder
- *(mes)* Declare domain permissions instead of seeding a private list

### ⚙️ Miscellaneous Tasks

- *(models)* Remove unnecessary comment lines in model docblocks

## [1.0.0] - 2026-08-25

### 🚀 Features

- *(mes)* Refactor MES module structure and add stock movement functionality
- *(config)* Clean up configuration file and add runtime setting definitions
- *(mes)* Introduce WorkCenter and WorkCenterCalendar models with associated functionality
- *(mes)* Add initial Swagger documentation and ItemTracingType tests
- *(mes)* Add production order migrations and configuration checks
- *(mes)* Enhance MESTables enum with utility traits
- *(mes)* Routing and routing operations (Task 5)
- *(mes)* Bill of materials and multi-level explosion (Task 4)
- *(mes)* Production order lifecycle with immutable snapshots (Task 6)
- *(mes)* Production order operation execution (Task 7)
- *(mes)* Material backflush on operation completion (Task 8)
- *(mes)* Lot and serial traceability (Task 9)
- *(mes)* Quality checks and non-conformance handling (Task 10)
- *(mes)* Work-center capacity and scheduling service (Task 11)
- *(mes)* Machine downtime and OEE calculation (Task 12)
- *(mes)* Shifts, shift instances and operator logging (Task 13)
- *(mes)* HTTP domain actions via Core registry and policy (Task 14)
- *(mes)* Production dashboard widget (Task 15)
- *(mes)* Manual material consumption (deferred Task 8 follow-up)
- *(mes)* Filament resources for core entities (Task 15 follow-up)
- *(mes)* Filament resources for quality, downtime and shifts (Task 15 complete)
- *(mes)* Auto-create production orders from confirmed sales orders
- *(mes)* Auto quality checks from plans and stock-shortage detection
- *(mes)* Partial consumption on stock shortage
- *(mes)* Notify recipients on material shortage

### 🐛 Bug Fixes

- *(tests)* Add TestCase class for AI tests in MES module
- *(mes)* Cap Company slug length in factory and test helpers
- *(mes)* Green test suite on PHP 8.5 (is_deleted column + factory states)
- *(mes)* RoutingOperation factory state as array (PHP 8.5 binding)

### 🚜 Refactor

- *(tests)* Update module dependency tests and refine stock movement assertions
- *(mes)* Update WorkCenter model to enhance factory and scope functionality
- *(mes)* Seed definitions via SeedReconciler, uniform column set
- *(mes)* Run aggregate transactions on the aggregate root connection

### 📚 Documentation

- *(mdc)* Add MES module context rules for ERP integration and operational guidelines
- Fix glossary cross-references
- Update glossary and module context with new production order and backflush details
- *(mes)* Developer RAG reference and simple user guide (Task 17)
- *(mes)* RAG user and developer docs for the new capabilities

### 🧪 Testing

- *(stock)* Enhance StockMovementData tests for immutability and DateTime handling
- *(mes)* End-to-end production cycle and snapshot invariant (Task 16)
- *(mes)* Add dev seeder for the MES SPA with demo data, roles and users

### ⚙️ Miscellaneous Tasks

- *(tests)* Update PHPUnit configuration and enhance test suite structure
- *(mes)* Bump version to v1.0.1
- *(mes)* Normalize PHPDoc spacing in WorkCenter model
- *(mes)* Mark module as laraplate_owned
- Correct version bump to v1.1.0
- Add versioning scripts and setup hooks to composer.json

<!-- generated by git-cliff -->
