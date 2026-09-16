<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::QualityPlans->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('routing_operation_id')
                ->nullable()
                ->constrained(MESTables::RoutingOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->nullOnDelete();
            $table->string('name', 255);
            $table->string('version', 32);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true)->index("{$table_name}_is_active_IDX");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::QualityPlans->value);
    }
};
