<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Illuminate\Database\Eloquent\Collection;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Models\MachineSource;

/**
 * Maps a topic to the machine source it belongs to. The topic identifies the source: no match, or
 * a match of several sources, identifies none.
 */
final class MqttMessageRouter
{
    /**
     * The topic filters of every active mqtt source, once each.
     *
     * @return list<string>
     */
    public function subscriptions(): array
    {
        return array_values(array_unique(array_filter(
            $this->sources()->map(static fn (MachineSource $source): string => $source->effectiveMqttTopic())->all(),
            static fn (string $topic): bool => $topic !== '',
        )));
    }

    public function sourceFor(string $topic): ?MachineSource
    {
        $matches = $this->sources()->filter(static fn (MachineSource $source): bool => self::matches($source->effectiveMqttTopic(), $topic));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * MQTT topic filter matching: `+` is one level, `#` is the rest (and the parent level).
     */
    public static function matches(string $filter, string $topic): bool
    {
        if ($filter === '') {
            return false;
        }

        $filter_levels = explode('/', $filter);
        $topic_levels = explode('/', $topic);

        foreach ($filter_levels as $index => $level) {
            if ($level === '#') {
                return $index === count($filter_levels) - 1;
            }

            if (! array_key_exists($index, $topic_levels) || ($level !== '+' && $level !== $topic_levels[$index])) {
                return false;
            }
        }

        return count($filter_levels) === count($topic_levels);
    }

    /**
     * @return Collection<int, MachineSource>
     */
    private function sources(): Collection
    {
        return MachineSource::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('transport', MachineTransport::Mqtt->value)
            ->where('is_active', true)
            ->get();
    }
}
