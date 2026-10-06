<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\UnmappedSignalFactory;
use Override;

/**
 * @property int $id
 * @property int $company_id
 * @property int $source_id
 * @property string $device_external_id
 * @property string $signal_key
 * @property ?string $last_value
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property int $seen_count
 */
final class UnmappedSignal extends Model
{
    /** @use HasFactory<UnmappedSignalFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_unmapped_signals';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'source_id',
        'device_external_id',
        'signal_key',
        'last_value',
        'last_seen_at',
        'seen_count',
    ];

    /**
     * @return BelongsTo<MachineSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MachineSource::class, 'source_id');
    }

    /**
     * @return Factory<UnmappedSignal>
     */
    protected static function newFactory(): Factory
    {
        return UnmappedSignalFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'seen_count' => 'integer',
        ];
    }
}
