<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Override;

/**
 * @extends Factory<UnmappedSignal>
 */
final class UnmappedSignalFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<UnmappedSignal>
     */
    protected $model = UnmappedSignal::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'source_id' => MachineSource::factory(),
            'device_external_id' => mb_strtolower(fake()->unique()->bothify('dev-####')),
            'signal_key' => mb_strtolower(fake()->unique()->bothify('sig_####')),
            'last_seen_at' => now(),
            'seen_count' => 0,
        ];
    }
}
