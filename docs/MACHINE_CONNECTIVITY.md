# Machine connectivity

Machines and probes push their data into the MES through the `laraplate-machine/1` protocol, over HTTP or
through the customer's MQTT broker. This covers the foundation and the MQTT bridge (steps 1 and 2 of the
machine data acquisition design): the protocol, the HTTP endpoint, the bridge and the `sparkplug_b`
normaliser, a durable inbox, the asynchronous pipeline with its configuration, and the backoffice. Nothing
consumes the machine data yet: the pipeline dispatches typed events (`MachineStateObserved`,
`PartsCounted`, `ProbeMeasured`, `ProcessValuesSampled`) that the later steps will listen to.

**Not built yet** (later steps): machine state intervals and automatic downtimes (step 3), piece counts and
ISO 22400 OEE (step 4), probe measurements filling quality checks (step 5), process values and their
storage (step 6).

## How a message travels

```
agent or gateway --HTTP--> MachineMessageInbox::accept() --> mes_machine_messages (pending)
                 --MQTT--> broker --> mes:machine-bridge --> MqttIngest --^
                                                       \--> ProcessMachineMessageJob (queue mes-machine)
                                                              1. normalise (the source's normaliser)
                                                              2. resolve (device, signal, role, work center)
                                                              3. attribute (operation, by sample time)
                                                              4. dispatch typed events
```

The HTTP response comes after the message is stored, never after it is processed. Messages of one source
are processed one at a time, in the order they were queued. Processing a message twice leaves the data as
one processing would; a message that failed stays `failed` with its error and can be reprocessed from the
backoffice.

## Sources, devices, signals

- A **machine source** is whatever sends data (our edge agent or a customer gateway). It has a `normalizer`
  (`canonical` or `mapped_json`), a `transport` (`http` or `mqtt`), a heartbeat timeout and a token.
- A **machine device** is a physical machine as the source names it (`external_id`), tied to a work center.
- A **machine signal** is one tag of a device with a **role**: `state`, `alarm`, `good_count`, `scrap_count`,
  `total_count`, `measurement`, `process_value`, `order_reference`, `operation_reference`. The role decides
  its `config`:

| Role | Config |
|---|---|
| `state` | `{"map": {raw value: machine state}}`, states `running`, `idle`, `setup`, `stopped`, `fault`, `maintenance`, `offline` |
| `alarm` | `{"map": {alarm code: downtime cause}}` |
| `good_count`, `scrap_count`, `total_count` | `{"mode": "cumulative" or "delta", "rollover_max": n}` |
| `process_value` | optional `{"min": n, "max": n}` |
| `measurement` | none; points at a quality plan characteristic |
| `order_reference`, `operation_reference` | none |

A **machine profile** is a reusable, importable and exportable set of signals and maps for a machine
model. Applying it to a device copies its signals; local edits stay, and the device records the profile
version. A profile cannot declare `measurement` signals (a characteristic is company data).

### Machine states and PackML

A state signal maps whatever the machine reports to the canonical states. A usual mapping of the PackML
states, which a profile sets as its `state_map`:

| PackML state | Canonical state |
|---|---|
| Execute | `running` |
| Idle, Standby, Complete, Completing | `idle` |
| Starting, Resetting | `setup` |
| Held, Holding, Unholding, Suspended, Suspending, Unsuspending, Stopped, Stopping | `stopped` |
| Aborted, Aborting, Clearing | `fault` |
| (maintenance mode) | `maintenance` |
| no data | `offline` |

## The HTTP endpoint

`POST api/v1/mes/machine-data`, route name `mes.api.machine-data.ingest`. The source authenticates with
its bearer token (Sanctum, ability `mes:machine-ingest`); the URL carries no source id. Use TLS.

| Response | Meaning | Agent behaviour |
|---|---|---|
| `202` | accepted | drop from the buffer |
| `200` with `duplicate: true` | already accepted | drop from the buffer |
| `401` | unknown token | stop sending, report |
| `403` | missing ability, inactive source, or a source that is not an HTTP canonical source | stop sending, report |
| `413` | too many samples (`mes.machine.max_samples`) or body too large (`mes.machine.max_body_kb`) | split the batch |
| `422` | invalid envelope; the schema errors are returned | drop, log locally |
| `429` / `5xx` / network error | temporary | retry with backoff, honour `Retry-After` |

Tokens are issued and revoked in the backoffice (source page, "Issue token"): one token per source,
shown once, never stored in clear. Authentication failures are logged without the token and rate limited;
a refused source also opens an `auth_failure` incident (at most one every five minutes).

### The envelope

```json
{
  "protocol": "laraplate-machine/1",
  "message_id": "0192f1c4-7a2e-7c1b-9f00-3b2d5e6a7c10",
  "source_seq": 1842,
  "sent_at": "2026-10-05T08:15:02.120Z",
  "devices": [
    { "device": "press-07", "type": "data", "samples": [
      { "signal": "state", "ts": "2026-10-05T08:15:01.900Z", "value": "EXECUTE", "quality": "good" },
      { "signal": "bore_d", "ts": "2026-10-05T08:15:02.010Z", "value": 12.004,
        "context": { "serial": "SN123", "order_ref": "PO-2026-0042" } }
    ] }
  ]
}
```

- `message_id` is the idempotency key, unique per source. `source_seq` increases per source; a gap records
  a `seq_gap` incident. `sent_at` is the sender clock; a difference above `mes.machine.clock_skew_seconds`
  records a `clock_skew` incident, and processing carries on.
- `type` is `data` (samples), `birth` (the device announces its `signals`: `signal`, `data_type` in
  `number`, `boolean`, `string`, optional `unit`) or `death` (the device goes `offline`).
- `ts` is device time (UTC); all processing uses it, never the arrival time. `value` is a number, a
  boolean or a string. `quality` is `good`, `uncertain` or `bad`; `bad` samples are stored and ignored.
- `context` may carry `order_ref`, `operation_ref`, `serial`, `lot`. Adding optional fields keeps version
  1; any other change is `laraplate-machine/2`.

The JSON Schema is `resources/protocol/laraplate-machine-1.schema.json`. The fixtures in
`tests/Fixtures/machine-protocol/` pair valid and invalid envelopes (and each normaliser's input with its
expected samples); the agent repository validates the same files with a real schema validator.

## MQTT

`php artisan mes:machine-bridge` is a long-running subscriber to the customer's MQTT broker (one broker per
installation; host, port, credentials, TLS and the client id come from `MES_MACHINE_MQTT_*`, see the README).
It subscribes at QoS 1 with a persistent session under a stable client id, so messages published while the
bridge is down wait on the broker; a message delivered twice is stored once (`message_id`). It follows the
sources: the topics of the active `mqtt` sources are read at start and again every minute, so a new source
needs no restart.

| Source | Topic | Payload |
|---|---|---|
| `canonical` | `{prefix}/laraplate-machine/1/{source_code}` (`prefix` is `MES_MACHINE_MQTT_TOPIC_PREFIX`, `laraplate` by default; the source's own `mqtt_topic` wins) | the `laraplate-machine/1` envelope, as over HTTP |
| `mapped_json` | the source's `mqtt_topic` (required) | any JSON, mapped by `normalizer_options` |
| `sparkplug_b` | `spBv1.0/{group}/#` in the source's `mqtt_topic` (required) | Sparkplug B protobuf |

The topic identifies the source; there is nobody to answer on MQTT, so a message that cannot be stored is
dropped: a topic that belongs to no active source, or to two, is only logged (once a minute), and an invalid
canonical envelope opens a `message_failed` incident naming the topic and the schema errors.

The bridge writes a heartbeat every few seconds; when it stops for more than a minute the watchdog opens one
`bridge_down` incident per active `mqtt` source, and closes it when the heartbeat returns. It stops cleanly on
`SIGTERM` (and `SIGINT`): it finishes the message it holds, stores it, and exits. When the broker connection
drops it reconnects with backoff (1, 2, 4 ... 60 seconds) and subscribes again.

Run it under systemd or supervisor, and give the machine queue its worker as above:

```ini
# /etc/systemd/system/mes-machine-bridge.service
[Unit]
Description=Laraplate MES machine bridge
After=network.target

[Service]
User=www-data
WorkingDirectory=/srv/http/laraplate
ExecStart=/usr/bin/php artisan mes:machine-bridge
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```ini
; supervisor
[program:mes-machine-bridge]
command=php artisan mes:machine-bridge
directory=/srv/http/laraplate
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

### Broker setup

Laraplate has no broker of its own. A minimal Mosquitto setup with one credential per agent, so an agent can
publish only on its own topic, and one for the bridge that can only read:

```
# /etc/mosquitto/conf.d/laraplate.conf
listener 8883
cafile /etc/mosquitto/certs/ca.crt
certfile /etc/mosquitto/certs/server.crt
keyfile /etc/mosquitto/certs/server.key
password_file /etc/mosquitto/passwd
acl_file /etc/mosquitto/acl
persistence true
persistent_client_expiration 7d
allow_anonymous false
```

```
# /etc/mosquitto/acl
user mes-bridge
topic read laraplate/laraplate-machine/1/#
topic read spBv1.0/#

user agent-gw-1
topic write laraplate/laraplate-machine/1/gw-1

user sparkplug-plant
topic write spBv1.0/plant/#
```

`persistence true` is what lets the broker keep the bridge's session, and its queued messages, while the bridge
is down. EMQX needs the same two ideas: persistent sessions, and an authorization rule per user that allows
publishing only on that user's topic and subscribing only for the bridge user (see EMQX's file authorization
documentation for the syntax of your version). Use TLS on the listener.

### Sparkplug B

The `sparkplug_b` normaliser reads the payload of `spBv1.0/{group}/{type}/{edge node}[/{device}]` topics with
a small protobuf reader (no protobuf library), from the Sparkplug B specification.

- **Devices.** Node-level metrics (`NBIRTH`, `NDATA`) belong to a device whose `external_id` is the edge node
  id; device-level metrics (`DBIRTH`, `DDATA`) to `{edge node}/{device}`. The group is the source's.
- **Births** declare the signals (a `birth` notice, with the name, a data type and the values) and the
  alias-to-name map. **Data** may carry only the alias: it is resolved from the map the birth stored
  (`mes_sparkplug_aliases`). Data that arrives before its birth is not an error: it shows up in the unmapped
  signals as `alias#{n}`; once the birth has been processed, reprocess the message and it resolves. To make an
  edge node announce itself again (a rebirth), restart it, or have your Sparkplug host application publish a
  rebirth command; Laraplate does not write to machines.
- **Deaths.** `NDEATH` makes the node and every configured device under it `offline`; `DDEATH` the device.
- **Not signals:** the control metrics (`bdSeq`, `Node Control/...`, `Device Control/...`), null metrics, and
  datatypes without a scalar value (data sets, templates, bytes, files). Signed integers are restored from
  their unsigned wire values; an unsigned 64-bit value above the integer range is kept as a string. Sparkplug
  carries no per-metric quality, so every sample is `good`.
- **Identity.** The message id is `sparkplug:{topic}:{seq}:{timestamp}`. The Sparkplug sequence wraps from 255
  to 0, so it is not used as the source sequence and never records a gap. `NCMD`, `DCMD` and `STATE` topics
  are ignored.

## Normalisers

| Key | Input | Notes |
|---|---|---|
| `canonical` | `laraplate-machine/1` | No translation. The only one the HTTP endpoint accepts. |
| `mapped_json` | Any JSON (Node-RED, Kepware IoT Gateway, ...) | Configured by the source's `normalizer_options`, below. |

`mapped_json` options, as dot-notation paths read with `data_get`: `samples_path` (path of a list of samples;
absent, the root object is one sample), and per sample `device`, `signal`, `timestamp`, `value`, optionally
`quality` with a `quality_map` (raw value => `good`/`uncertain`/`bad`); `timestamp_format` is `iso8601`
(default), `unix` or `unix_ms`; `message_id_path` names the message id, otherwise it is the `sha1` of the
payload. A sample missing its device, signal, timestamp or value is skipped. Sources with these normalisers
arrive through the MQTT bridge.

## What the pipeline does with a sample

- A device or signal nobody configured lands in **unmapped signals** (not an error): map it in place in
  the backoffice. A raw state value missing from the state signal's map is recorded as `{key}#{value}`; it
  is fixed by editing the map, then reprocessing.
- The sample is **attributed** to an operation by its own time: an explicit reference first (`operation_ref`
  is an operation id, `order_ref` a production order number, from the sample context or the device's
  reference signals in the same message; a reference that does not resolve attributes nothing); otherwise
  the single operation on the work center running at the sample time; none or several, nothing.
- Typed events are dispatched per message, device and role, in time order.

## Incidents and health

Incidents (`mes_machine_incidents`): `seq_gap`, `clock_skew`, `message_failed`, `auth_failure`,
`device_silent` and `bridge_down`. `mes:machine-watchdog` runs every minute: a
device silent for longer than its source's `heartbeat_timeout_seconds` opens a `device_silent` incident,
which closes when it is heard again. Incidents notify the roles and channels set under
`mes.notifications.machine_incident`.

## Operations

- Queue: `mes.machine.queue` (default `mes-machine`) on the `mes.queue.connection` connection. Give it its
  own Horizon supervisor so machine traffic never delays other work, for example in `config/horizon.php`:

```php
'supervisor-mes-machine' => [
    'connection' => 'redis',
    'queue' => ['mes-machine'],
    'balance' => 'auto',
    'maxProcesses' => 4,
],
```

- Retention: processed raw messages older than `mes.machine.inbox_retention_days` are pruned daily
  (`model:prune`); failed ones are kept.
- Reprocessing: per message, or for a range of a source, from the backoffice. A reprocess never inflates
  the unmapped signal counters.
- Permissions: the CRUD permissions of the machine tables, plus the domain actions `reprocess` (messages),
  `issue_token`, `revoke_token`, `reprocess_range` (sources), `apply_profile` (devices) and `export`
  (profiles).
