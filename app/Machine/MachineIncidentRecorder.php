<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Events\MachineIncidentRecorded;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineSource;

/**
 * Opens, deduplicates and closes machine incidents.
 */
final class MachineIncidentRecorder
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function record(MachineSource $source, MachineIncidentType $type, array $detail = [], ?MachineDevice $device = null, ?CarbonInterface $at = null): MachineIncident
    {
        $incident = MachineIncident::query()->create([
            'company_id' => $source->company_id,
            'source_id' => $source->id,
            'device_id' => $device?->id,
            'type' => $type->value,
            'detail' => $detail,
            'occurred_at' => $at ?? now(),
        ]);

        MachineIncidentRecorded::dispatch((int) $source->company_id, $incident->id);

        return $incident;
    }

    /**
     * Records the incident unless an unresolved one of the same source, device and type exists.
     *
     * @param  array<string, mixed>  $detail
     */
    public function recordOnce(MachineSource $source, MachineIncidentType $type, array $detail = [], ?MachineDevice $device = null, ?CarbonInterface $at = null): MachineIncident
    {
        return $this->open($source, $type, $device)->first()
            ?? $this->record($source, $type, $detail, $device, $at);
    }

    /**
     * Closes the unresolved incidents of a source, device and type.
     *
     * @return int how many were closed
     */
    public function resolve(MachineSource $source, MachineIncidentType $type, ?MachineDevice $device = null): int
    {
        return $this->open($source, $type, $device)->update(['resolved_at' => now()]);
    }

    /**
     * @return Builder<MachineIncident>
     */
    private function open(MachineSource $source, MachineIncidentType $type, ?MachineDevice $device): Builder
    {
        return MachineIncident::query()
            ->withoutGlobalScopes()
            ->where('source_id', $source->id)
            ->where('type', $type->value)
            ->when($device instanceof MachineDevice, static fn ($query) => $query->where('device_id', $device?->id), static fn ($query) => $query->whereNull('device_id'))
            ->whereNull('resolved_at');
    }
}
