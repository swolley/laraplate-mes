<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\MachineMessageFactory;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Override;

/**
 * @property int $id
 * @property int $company_id
 * @property int $source_id
 * @property string $message_id
 * @property ?int $source_seq
 * @property MachineTransport $transport
 * @property string $payload
 * @property \Illuminate\Support\Carbon $received_at
 * @property MachineMessageStatus $status
 * @property ?string $error
 * @property int $attempts
 * @property ?\Illuminate\Support\Carbon $processed_at
 */
final class MachineMessage extends Model
{
    /** @use HasFactory<MachineMessageFactory> */
    use BelongsToCompany, HasFactory, MassPrunable;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_messages';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'source_id',
        'message_id',
        'source_seq',
        'transport',
        'payload',
        'received_at',
        'status',
        'error',
        'attempts',
        'processed_at',
    ];

    /**
     * @return BelongsTo<MachineSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MachineSource::class, 'source_id');
    }

    /**
     * The messages the scheduled `model:prune` removes: processed ones older than the inbox
     * retention. Failed and pending ones stay, so a failure can still be read and reprocessed.
     *
     * @return Builder<MachineMessage>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->withoutGlobalScopes()
            ->where('status', MachineMessageStatus::Processed->value)
            ->where('received_at', '<', now()->subDays(config()->integer('mes.machine.inbox_retention_days')));
    }

    /**
     * @return Factory<MachineMessage>
     */
    protected static function newFactory(): Factory
    {
        return MachineMessageFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'source_seq' => 'integer',
            'transport' => MachineTransport::class,
            'received_at' => 'datetime',
            'status' => MachineMessageStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
