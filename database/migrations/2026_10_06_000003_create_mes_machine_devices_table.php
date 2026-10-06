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
        $table_name = MESTables::MachineDevices->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('source_id')
                ->constrained(MESTables::MachineSources->value, 'id', "{$table_name}_source_id_FK")
                ->cascadeOnDelete();
            $table->string('external_id', 128);
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->restrictOnDelete();
            $table->unsignedBigInteger('machine_profile_id')->nullable();
            $table->foreign('machine_profile_id', "{$table_name}_machine_profile_id_FK")
                ->references('id')->on(MESTables::MachineProfiles->value)
                ->nullOnDelete();
            $table->string('profile_version', 32)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->unique(['source_id', 'external_id'], "{$table_name}_source_external_UN");
            MigrateUtils::prefixIndex($table, 'company_id');
            MigrateUtils::prefixIndex($table, 'work_center_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineDevices->value);
    }
};
