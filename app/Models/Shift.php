<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\MES\Database\Factories\ShiftFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperShift
 */
final class Shift extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_shifts';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'name',
        'start_time',
        'end_time',
        'is_active',
    ];

    /**
     * @return HasMany<ShiftInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(ShiftInstance::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<Shift>
     */
    protected static function newFactory(): Factory
    {
        return ShiftFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
