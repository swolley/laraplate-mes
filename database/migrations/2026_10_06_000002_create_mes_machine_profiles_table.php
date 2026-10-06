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
        $table_name = MESTables::MachineProfiles->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->string('vendor', 128);
            $table->string('model', 128);
            $table->string('version', 32);
            $table->json('definition');

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->unique(['company_id', 'vendor', 'model', 'version'], "{$table_name}_company_vendor_model_version_UN");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineProfiles->value);
    }
};
