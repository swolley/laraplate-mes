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
        $table_name = MESTables::ProductionOrders->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->foreignId('sales_order_line_id')
                ->nullable()
                ->after('sales_order_id')
                ->constrained(ERPTables::SalesOrderLines->value, 'id', "{$table_name}_sales_order_line_id_FK")
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table_name = MESTables::ProductionOrders->value;
        Schema::table($table_name, function (Blueprint $table) use ($table_name): void {
            $table->dropForeign("{$table_name}_sales_order_line_id_FK");
            $table->dropColumn('sales_order_line_id');
        });
    }
};
