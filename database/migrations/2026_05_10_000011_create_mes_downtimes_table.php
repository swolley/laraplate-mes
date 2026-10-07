<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::Downtimes->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            MigrateUtils::prefixIndex($table, 'company_id');
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('production_order_operation_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'production_order_operation_id');
            $table->enum('cause', DowntimeCause::values());
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->decimal('duration_minutes', 12, 4)->nullable();
            $table->text('notes')->nullable();
            $table->enum('source', DowntimeSource::values())->default(DowntimeSource::Manual->value);
            // No foreign key: the devices table comes later than this one, and a machine downtime outlives a deleted device's configuration.
            $table->unsignedBigInteger('machine_device_id')->nullable();
            $table->string('alarm_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['work_center_id', 'started_at'], "{$table_name}_work_center_started_UN");
            $table->index(['machine_device_id', 'started_at'], "{$table_name}_device_started_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::Downtimes->value);
    }
};
