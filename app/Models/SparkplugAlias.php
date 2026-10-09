<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\MES\Database\Factories\SparkplugAliasFactory;
use Override;

/**
 * The name a Sparkplug B alias stands for on a device, learned from its birth message.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int $source_id
 * @property string $device_external_id
 * @property int $alias
 * @property string $name
 * @property \Illuminate\Support\Carbon $declared_at
 */
final class SparkplugAlias extends Model implements IsPartOfParent
{
    /** @use HasFactory<SparkplugAliasFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_sparkplug_aliases';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'source_id',
        'device_external_id',
        'alias',
        'name',
        'declared_at',
    ];

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'source';
    }

    /**
     * The source whose device declared the alias.
     *
     * @return BelongsTo<MachineSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MachineSource::class, 'source_id');
    }

    /**
     * @return Factory<SparkplugAlias>
     */
    protected static function newFactory(): Factory
    {
        return SparkplugAliasFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'alias' => 'integer',
            'declared_at' => 'datetime',
        ];
    }
}
