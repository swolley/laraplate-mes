<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\NonConformanceStatus;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::NonConformances->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('production_order_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->nullOnDelete();
            $table->foreignId('quality_check_id')
                ->nullable()
                ->constrained(MESTables::QualityChecks->value, 'id', "{$table_name}_quality_check_id_FK")
                ->nullOnDelete();
            $table->foreignId('item_id')
                ->constrained(ERPTables::Items->value, 'id', "{$table_name}_item_id_FK")
                ->restrictOnDelete();
            $table->foreignId('rework_production_order_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_rework_order_id_FK")
                ->nullOnDelete();
            $table->enum('status', NonConformanceStatus::values())
                ->default(NonConformanceStatus::Open->value)
                ->index("{$table_name}_status_IDX");
            $table->string('disposition', 32)->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->text('description');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::NonConformances->value);
    }
};
