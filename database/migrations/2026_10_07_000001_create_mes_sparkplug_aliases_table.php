<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::SparkplugAliases->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('source_id')
                ->constrained(MESTables::MachineSources->value, 'id', "{$table_name}_source_id_FK")
                ->cascadeOnDelete();
            $table->string('device_external_id', 160);
            $table->unsignedBigInteger('alias');
            $table->string('name', 255);
            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['source_id', 'device_external_id', 'alias'], "{$table_name}_source_device_alias_UN");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::SparkplugAliases->value);
    }
};
