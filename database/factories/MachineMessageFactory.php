<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Override;

/**
 * @extends Factory<MachineMessage>
 */
final class MachineMessageFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineMessage>
     */
    protected $model = MachineMessage::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'source_id' => MachineSource::factory(),
            'message_id' => (string) Str::uuid(),
            'source_seq' => null,
            'transport' => MachineTransport::Http->value,
            'payload' => '{}',
            'received_at' => now(),
            'status' => MachineMessageStatus::Pending->value,
            'attempts' => 0,
        ];
    }

    public function processed(): static
    {
        return $this->state(['status' => MachineMessageStatus::Processed->value, 'processed_at' => now(), 'attempts' => 1]);
    }

    public function failed(): static
    {
        return $this->state(['status' => MachineMessageStatus::Failed->value, 'error' => 'boom', 'attempts' => 3]);
    }
}
