<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MES\Database\Factories\RoutingOperationFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $routing_id
 * @property int $work_center_id
 * @property int $sequence
 * @property string $description
 * @property int $setup_time_minutes
 * @property string $cycle_time_minutes
 * @property bool $is_parallel
 *
 * @mixin IdeHelperRoutingOperation
 */
final class RoutingOperation extends Model
{
    use HasFactory;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_routing_operations';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'routing_id',
        'work_center_id',
        'sequence',
        'description',
        'setup_time_minutes',
        'cycle_time_minutes',
        'is_parallel',
    ];

    /**
     * The routing this operation belongs to.
     *
     * @return BelongsTo<Routing, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    /**
     * The work center this operation runs on.
     *
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<RoutingOperation>
     */
    protected static function newFactory(): Factory
    {
        return RoutingOperationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'sequence' => 'int',
            'setup_time_minutes' => 'int',
            'cycle_time_minutes' => 'decimal:4',
            'is_parallel' => 'boolean',
        ];
    }
}
