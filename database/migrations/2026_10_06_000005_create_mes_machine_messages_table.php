<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = MESTables::MachineMessages->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('company_id')->constrained(ERPTables::Companies->value, 'id', "{$table_name}_company_id_FK")->restrictOnDelete();
            $table->foreignId('source_id')
                ->constrained(MESTables::MachineSources->value, 'id', "{$table_name}_source_id_FK")
                ->cascadeOnDelete();
            $table->string('message_id', 128);
            $table->unsignedBigInteger('source_seq')->nullable();
            $table->enum('transport', MachineTransport::values());
            $table->longText('payload');
            $table->dateTime('received_at');
            $table->enum('status', MachineMessageStatus::values())->default(MachineMessageStatus::Pending->value);
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('processed_at')->nullable();

            MigrateUtils::timestamps($table, hasCreateUpdate: true);

            $table->unique(['source_id', 'message_id'], "{$table_name}_source_message_UN");
            $table->index(['source_id', 'received_at'], "{$table_name}_source_received_IDX");
            $table->index(['status', 'received_at'], "{$table_name}_status_received_IDX");
            MigrateUtils::prefixIndex($table, 'company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MESTables::MachineMessages->value);
    }
};
