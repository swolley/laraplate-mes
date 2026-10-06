<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Events\PartsCounted;
use Modules\MES\Events\ProbeMeasured;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Machine\Data\DeviceNotice;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Machine\Data\ResolvedSignal;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSource;

/**
 * The pipeline of one normalised message: resolve every sample to its signal, record what is not
 * configured, attribute, and dispatch the typed events grouped by device and role.
 *
 * Resolve an instance per message (the container does): the resolver and the attributor keep what they
 * read for the duration of the message.
 */
final class MachineMessageProcessor
{
    public function __construct(
        private readonly SignalResolver $resolver,
        private readonly OperationAttributor $attributor,
        private readonly UnmappedSignalRecorder $unmapped,
    ) {}

    /**
     * @param  bool  $count_unmapped  false when the message is reprocessed, so counters do not grow
     */
    public function process(MachineSource $source, NormalizedMessage $message, CarbonInterface $received_at, bool $count_unmapped): void
    {
        $unmapped = [];
        $resolved = [];

        foreach ($message->samples as $sample) {
            $target = $this->resolver->resolve($source, $sample->device, $sample->signal);

            if (! $target instanceof ResolvedSignal) {
                $unmapped[] = $this->entry($sample->device, $sample->signal, $sample->value, $sample->ts);

                continue;
            }

            $resolved[] = [$target, $sample];
        }

        usort($resolved, static fn (array $a, array $b): int => $a[1]->ts <=> $b[1]->ts);
        $touched = [];
        $groups = [];
        $references = $this->references($resolved);
        $this->prepareAttribution($source, $resolved);

        foreach ($resolved as [$target, $sample]) {
            $touched[$target->device->id] = $target->device->id;

            if ($sample->quality === SampleQuality::Bad || $target->signal->role->isReference()) {
                continue;
            }

            $state = null;
            $alarm_code = null;

            if ($target->signal->role === SignalRole::State) {
                $state = $this->state($target, $sample);

                if (! $state instanceof MachineState) {
                    $unmapped[] = $this->entry($sample->device, "{$sample->signal}#" . $this->text($sample->value), $sample->value, $sample->ts);

                    continue;
                }
            } elseif ($target->signal->role === SignalRole::Alarm) {
                $alarm_code = $this->text($sample->value);
            }

            $attribution = $this->attributor->attribute($target, $sample, $references[$target->device->id]['order'] ?? null, $references[$target->device->id]['operation'] ?? null);
            $groups[$target->device->id][$this->eventFor($target->signal->role)][] = new ResolvedSample($target->device, $target->signal, $sample, $attribution->production_order_operation_id, $state, $alarm_code);
        }

        foreach ($message->notices as $notice) {
            $device = $this->resolver->device($source, $notice->device);

            if ($device instanceof MachineDevice) {
                $touched[$device->id] = $device->id;
            }

            if ($notice->type === DeviceNotice::BIRTH) {
                foreach ($notice->signals as $announced) {
                    if (! $this->resolver->resolve($source, $notice->device, $announced['signal']) instanceof ResolvedSignal) {
                        $unmapped[] = $this->entry($notice->device, $announced['signal'], null, $notice->ts);
                    }
                }
            } elseif ($device instanceof MachineDevice) {
                $offline = $this->offline($device, $notice);

                if ($offline instanceof ResolvedSample) {
                    $groups[$device->id][MachineStateObserved::class][] = $offline;
                }
            }
        }

        $this->unmapped->recordMany($source, $unmapped, $count_unmapped);
        $this->touch($source, $touched, $received_at);
        $this->dispatch($groups);
    }

    /**
     * The latest order and operation reference values of each device in the message.
     *
     * @param  list<array{ResolvedSignal, NormalizedSample}>  $resolved  in time order
     * @return array<int, array<string, string>>
     */
    private function references(array $resolved): array
    {
        $references = [];

        foreach ($resolved as [$target, $sample]) {
            if ($sample->quality === SampleQuality::Bad) {
                continue;
            }

            if ($target->signal->role === SignalRole::OrderReference) {
                $references[$target->device->id]['order'] = $this->text($sample->value);
            } elseif ($target->signal->role === SignalRole::OperationReference) {
                $references[$target->device->id]['operation'] = $this->text($sample->value);
            }
        }

        return $references;
    }

    /**
     * @param  list<array{ResolvedSignal, NormalizedSample}>  $resolved
     */
    private function prepareAttribution(MachineSource $source, array $resolved): void
    {
        if ($resolved === []) {
            return;
        }

        $work_centers = array_values(array_unique(array_map(static fn (array $pair): int => $pair[0]->work_center_id, $resolved)));
        $first = $resolved[0][1]->ts;
        $last = $resolved[array_key_last($resolved)][1]->ts;

        $this->attributor->prepare((int) $source->company_id, $work_centers, $first, $last);
    }

    private function state(ResolvedSignal $target, NormalizedSample $sample): ?MachineState
    {
        $map = $target->signal->config['map'] ?? [];
        $mapped = is_array($map) ? ($map[$this->text($sample->value)] ?? null) : null;

        return is_string($mapped) ? MachineState::tryFrom($mapped) : null;
    }

    private function offline(MachineDevice $device, DeviceNotice $notice): ?ResolvedSample
    {
        $signal = $device->signals->firstWhere('role', SignalRole::State);

        if ($signal === null) {
            return null;
        }

        $sample = new NormalizedSample($notice->device, $signal->key, $notice->ts, MachineState::Offline->value);

        return new ResolvedSample($device, $signal, $sample, null, MachineState::Offline);
    }

    /**
     * @return class-string
     */
    private function eventFor(SignalRole $role): string
    {
        return match ($role) {
            SignalRole::State, SignalRole::Alarm => MachineStateObserved::class,
            SignalRole::GoodCount, SignalRole::ScrapCount, SignalRole::TotalCount => PartsCounted::class,
            SignalRole::Measurement => ProbeMeasured::class,
            default => ProcessValuesSampled::class,
        };
    }

    /**
     * @param  array<int, array<class-string, list<ResolvedSample>>>  $groups
     */
    private function dispatch(array $groups): void
    {
        foreach ($groups as $by_event) {
            foreach ([MachineStateObserved::class, PartsCounted::class, ProbeMeasured::class, ProcessValuesSampled::class] as $event) {
                $samples = $by_event[$event] ?? [];

                if ($samples === []) {
                    continue;
                }

                usort($samples, static fn (ResolvedSample $a, ResolvedSample $b): int => $a->sample->ts <=> $b->sample->ts);
                $device = $samples[0]->device;
                $event::dispatch((int) $device->company_id, $device->id, (int) $device->work_center_id, $samples);
            }
        }
    }

    /**
     * Moves `last_seen_at` of the source and of the devices forward, never back.
     *
     * @param  array<int, int>  $device_ids
     */
    private function touch(MachineSource $source, array $device_ids, CarbonInterface $at): void
    {
        $forward = static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $at));

        $forward(MachineSource::query()->withoutGlobalScopes()->toBase()->where('id', $source->id))->update(['last_seen_at' => $at]);

        if ($device_ids !== []) {
            $forward(MachineDevice::query()->withoutGlobalScopes()->toBase()->whereIn('id', array_values($device_ids)))->update(['last_seen_at' => $at]);
        }
    }

    /**
     * @return array{device: string, key: string, value: ?string, ts: CarbonImmutable}
     */
    private function entry(string $device, string $key, int|float|bool|string|null $value, CarbonImmutable $ts): array
    {
        return ['device' => $device, 'key' => $key, 'value' => $value === null ? null : $this->text($value), 'ts' => $ts];
    }

    private function text(int|float|bool|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
