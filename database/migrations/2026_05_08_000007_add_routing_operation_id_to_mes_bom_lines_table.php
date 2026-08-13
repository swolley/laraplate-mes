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
        $table_name = MESTables::BomLines->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->foreignId('routing_operation_id')
                ->nullable()
                ->after('consumption_method')
                ->constrained(MESTables::RoutingOperations->value, 'id', "{$table_name}_routing_operation_id_FK")
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table_name = MESTables::BomLines->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->dropForeign("{$table_name}_routing_operation_id_FK");
            $table->dropColumn('routing_operation_id');
        });
    }
};
