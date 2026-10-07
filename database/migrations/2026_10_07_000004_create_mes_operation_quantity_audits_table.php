<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\MES\Enums\MESTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::OperationQuantityAudits->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('operation_id')
                ->constrained(MESTables::ProductionOrderOperations->value, 'id', "{$table_name}_operation_id_FK")
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('field', 64);
            $table->decimal('old_value', 12, 4)->nullable();
            $table->decimal('new_value', 12, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('operation_id', "{$table_name}_operation_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::OperationQuantityAudits->value);
    }
};
