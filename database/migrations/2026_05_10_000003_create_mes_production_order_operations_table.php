<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\ProductionOrderOperationStatus;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::ProductionOrderOperations->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('production_order_id')
                ->constrained(MESTables::ProductionOrders->value, 'id', "{$table_name}_production_order_id_FK")
                ->cascadeOnDelete();
            $table->unsignedBigInteger('routing_operation_id')->nullable();
            $table->foreignId('work_center_id')
                ->constrained(MESTables::WorkCenters->value, 'id', "{$table_name}_work_center_id_FK")
                ->restrictOnDelete();
            $table->integer('sequence');
            $table->string('description', 255);
            $table->enum('status', ProductionOrderOperationStatus::values())
                ->default(ProductionOrderOperationStatus::Planned->value)
                ->index("{$table_name}_status_IDX");
            $table->integer('setup_time_minutes')->default(0);
            $table->decimal('cycle_time_minutes', 10, 4)->default(0);
            $table->boolean('is_parallel')->default(false);
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->decimal('actual_minutes', 12, 4)->nullable();
            $table->decimal('efficiency', 6, 2)->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'sequence'], "{$table_name}_order_sequence_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::ProductionOrderOperations->value);
    }
};
