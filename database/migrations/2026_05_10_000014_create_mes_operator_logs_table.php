<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\OperatorLogAction;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::OperatorLogs->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index("{$table_name}_user_id_IDX");
            $table->foreignId('production_order_operation_id')
                ->nullable()
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->cascadeOnDelete();
            $table->foreignId('shift_instance_id')
                ->nullable()
                ->constrained(MESTables::ShiftInstances->value, 'id', "{$table_name}_shift_instance_id_FK")
                ->nullOnDelete();
            $table->enum('action', OperatorLogAction::values());
            $table->dateTime('logged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::OperatorLogs->value);
    }
};
