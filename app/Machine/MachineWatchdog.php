<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Modules\MES\Enums\MachineIncidentType;
use Illuminate\Support\Facades\Cache;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\Mqtt\MachineBridge;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;

/**
 * Notices what stopped sending. A device silent for longer than its source's `heartbeat_timeout_seconds`
 * opens one `device_silent` incident, which closes when it is heard again; a stopped MQTT bridge opens
 * `bridge_down` (see {@see self::checkBridge()}). It also queues again the messages left pending. The
 * synthetic `Offline` state interval belongs to the state step.
 */
final class MachineWatchdog
{
    private const int STALE_PENDING_MINUTES = 5;

    private const int BRIDGE_STALE_SECONDS = 60;

    public function __construct(
        private readonly MachineIncidentRecorder $incidents,
    ) {}

    /**
     * @return int how many incidents were opened
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $open = MachineIncident::query()
            ->withoutGlobalScopes()
            ->unresolved()
            ->where('type', MachineIncidentType::DeviceSilent->value)
            ->pluck('device_id')
            ->all();
        $opened = 0;

        $devices = MachineDevice::query()
            ->where('is_active', true)
            ->whereHas('source', static fn ($sources) => $sources->where('is_active', true))
            ->with('source')
            ->get();

        foreach ($devices as $device) {
            $source = $device->source;

            if (! $source instanceof MachineSource) {
                continue;
            }

            $seen = CarbonImmutable::instance($device->last_seen_at ?? $device->created_at);
            $silent_seconds = (int) $seen->diffInSeconds($now, true);
            $is_silent = $silent_seconds > $source->heartbeat_timeout_seconds;
            $has_incident = in_array($device->id, $open, true);

            if ($is_silent && ! $has_incident) {
                $this->incidents->record($source, MachineIncidentType::DeviceSilent, [
                    'device' => $device->external_id,
                    'silent_seconds' => $silent_seconds,
                ], $device, $now);
                $opened++;
            } elseif (! $is_silent && $has_incident) {
                $this->incidents->resolve($source, MachineIncidentType::DeviceSilent, $device);
            }
        }

        $this->requeueStalePending($now);

        return $opened + $this->checkBridge($now);
    }

    /**
     * The bridge writes a heartbeat every few seconds. A heartbeat older than a minute, or none, means
     * the bridge is down: one `bridge_down` incident per active mqtt source (incidents belong to a
     * source), closed when the heartbeat is fresh again. Nothing while no mqtt source is active.
     *
     * @return int how many incidents were opened
     */
    private function checkBridge(CarbonImmutable $now): int
    {
        $sources = MachineSource::query()->where('transport', MachineTransport::Mqtt->value)->where('is_active', true)->get();

        if ($sources->isEmpty()) {
            return 0;
        }

        $heartbeat = Cache::get(MachineBridge::HEARTBEAT_KEY);
        $age = is_numeric($heartbeat) ? max(0, $now->getTimestamp() - (int) $heartbeat) : null;
        $is_down = $age === null || $age > self::BRIDGE_STALE_SECONDS;
        $open = MachineIncident::query()
            ->withoutGlobalScopes()
            ->unresolved()
            ->where('type', MachineIncidentType::BridgeDown->value)
            ->pluck('source_id')
            ->all();
        $opened = 0;

        foreach ($sources as $source) {
            $has_incident = in_array($source->id, $open, true);

            if ($is_down && ! $has_incident) {
                $this->incidents->record($source, MachineIncidentType::BridgeDown, ['heartbeat_age_seconds' => $age], null, $now);
                $opened++;
            } elseif (! $is_down && $has_incident) {
                $this->incidents->resolve($source, MachineIncidentType::BridgeDown);
            }
        }

        return $opened;
    }

    /**
     * A message still pending a few minutes after it arrived lost its job (the queue was down when it
     * was stored, or the worker died): queue it again. Processing is idempotent, so a job that is in
     * fact still waiting does no harm.
     */
    private function requeueStalePending(CarbonImmutable $now): void
    {
        MachineMessage::query()
            ->withoutGlobalScopes()
            ->where('status', MachineMessageStatus::Pending->value)
            ->where('received_at', '<', $now->subMinutes(self::STALE_PENDING_MINUTES))
            ->each(static fn (MachineMessage $message) => ProcessMachineMessageJob::dispatch($message->id, $message->source_id));
    }
}
