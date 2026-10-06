<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineSource;
use Override;

/**
 * @extends Factory<MachineIncident>
 */
final class MachineIncidentFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineIncident>
     */
    protected $model = MachineIncident::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'source_id' => MachineSource::factory(),
            'type' => MachineIncidentType::SeqGap->value,
            'detail' => [],
            'occurred_at' => now(),
        ];
    }
}
