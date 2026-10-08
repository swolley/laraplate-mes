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
        $table_name = MESTables::ProcessAggregates->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('signal_id')
                ->constrained(MESTables::MachineSignals->value, 'id', "{$table_name}_signal_id_FK")
                ->cascadeOnDelete();
            $table->enum('resolution', ['1m', '1h']);
            $table->dateTime('bucket_start', 3);
            $table->decimal('min', 18, 6);
            $table->decimal('max', 18, 6);
            $table->decimal('avg', 18, 6);
            $table->decimal('last', 18, 6);
            $table->unsignedInteger('count');
            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['signal_id', 'resolution', 'bucket_start'], "{$table_name}_bucket_UN");
            $table->index(['resolution', 'bucket_start'], "{$table_name}_resolution_bucket_IDX");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::ProcessAggregates->value);
    }
};
