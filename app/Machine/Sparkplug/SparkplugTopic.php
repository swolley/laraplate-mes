<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

/**
 * A topic of the `spBv1.0` namespace: `spBv1.0/{group}/{type}/{edge node}[/{device}]`. Node message
 * types (`NBIRTH`, `NDEATH`, `NDATA`, `NCMD`) have no device, device types (`DBIRTH`, `DDEATH`,
 * `DDATA`, `DCMD`) have one. Anything else, STATE topics included, is not parsed.
 */
final readonly class SparkplugTopic
{
    public const string NAMESPACE = 'spBv1.0';

    private const array NODE_TYPES = ['NBIRTH', 'NDEATH', 'NDATA', 'NCMD'];

    private const array DEVICE_TYPES = ['DBIRTH', 'DDEATH', 'DDATA', 'DCMD'];

    public function __construct(
        public string $group,
        public string $type,
        public string $edge_node,
        public ?string $device,
    ) {}

    public static function parse(string $topic): ?self
    {
        $levels = explode('/', $topic);

        if ($levels[0] !== self::NAMESPACE || count($levels) < 4 || count($levels) > 5) {
            return null;
        }

        [, $group, $type, $edge_node] = $levels;
        $device = $levels[4] ?? null;
        $is_node_type = in_array($type, self::NODE_TYPES, true);
        $is_device_type = in_array($type, self::DEVICE_TYPES, true);

        if ($group === '' || $edge_node === '' || ($is_node_type && $device !== null) || ($is_device_type && ($device === null || $device === '')) || (! $is_node_type && ! $is_device_type)) {
            return null;
        }

        return new self($group, $type, $edge_node, $device);
    }

    /**
     * The identity of the device the message belongs to in the MES: the edge node id for node-level
     * messages, `{edge node}/{device}` for device-level ones.
     */
    public function deviceExternalId(): string
    {
        return $this->device === null ? $this->edge_node : "{$this->edge_node}/{$this->device}";
    }
}
