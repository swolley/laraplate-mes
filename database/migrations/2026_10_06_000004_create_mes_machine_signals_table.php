<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\MachineTransport;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MachineSignals->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('device_id')
                ->constrained(MESTables::MachineDevices->value, 'id', "{$table_name}_device_id_FK")
                ->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('role', 32);
            $table->string('data_type', 16)->default('number');
            $table->string('unit', 16)->nullable();
            $table->json('config')->nullable();
            $table->unsignedBigInteger('quality_plan_characteristic_id')->nullable();
            $table->foreign('quality_plan_characteristic_id', "{$table_name}_characteristic_id_FK")
                ->references('id')->on(MESTables::QualityPlanCharacteristics->value)
                ->nullOnDelete();

            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['device_id', 'key'], "{$table_name}_device_key_UN");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineSignals->value);
    }
};
