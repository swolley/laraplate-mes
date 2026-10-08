# Machine connectivity

Machines and probes push their data into the MES through the `laraplate-machine/1` protocol, over HTTP or
through the customer's MQTT broker. This covers the foundation, the MQTT bridge and the machine states, the piece counts, the probe measurements and the process values (steps 1 to 6 of the
machine data acquisition design): the protocol, the HTTP endpoint, the bridge and the `sparkplug_b`
normaliser, a durable inbox, the asynchronous pipeline with its configuration, the state history with the
downtimes derived from it, the stored piece counts with the OEE performance and quality built on them, and
the probe measurements that fill quality checks, the process values with their aggregates and per-operation
summaries, and the backoffice. The pipeline dispatches typed events (`MachineStateObserved`, `PartsCounted`,
`ProbeMeasured`, `ProcessValuesSampled`), and each has a consumer.

Everything in the machine data acquisition design is built.

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

Things to know when running it:

- **One bridge per client id.** Two bridges with the same `MES_MACHINE_MQTT_CLIENT_ID` take each other's session over
  in an endless loop; run exactly one, or give each its own id (and its own sources).
- **A shared cache.** The heartbeat lives in the application cache: the bridge and the scheduler must see the same
  store (Redis, database, file). With a per-process store (`array`) every mqtt source gets a permanent false
  `bridge_down`.
- **The broker acknowledges first.** The MQTT library acknowledges a QoS 1 message before it hands it over, so a
  message the database cannot store right then will not come back from the broker. The bridge retries a failing
  store for about a minute (1, 2, 4, 8, 16, 30 seconds) before giving that message up with a log line (topic and
  kind of failure, never the payload).
- **Topics must not overlap.** A source's topic filter must be valid MQTT and cannot overlap the topic of any other
  source, in any company and whether active or not (the default topic of a canonical source includes its code, so two
  companies cannot both have a source `gw-1`). The model refuses the second.

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

## States, downtimes and OEE availability

**State history.** Every state a device reports is kept as an interval in `mes_machine_state_intervals`
(device, work center, state, start, end; the open one has no end). Recording is idempotent: the same state at
the same instant, or inside an interval already in that state, changes nothing, and the first state written
for an instant wins. A state older than the open interval (a late sample) splits the interval that holds its
moment; two neighbours left in the same state are merged. Times keep milliseconds.

**Connected work center.** A work center is connected when it has an active device, of an active source, with
a state signal. A work center has at most one state device (a second state signal on another device of the
same work center is refused). On a connected work center the downtimes come from the machine:

- a manual downtime is refused (`DowntimeService::open()` and the create page both say so);
- a machine downtime cannot be closed by hand and its start, end and work center are locked; its cause and
  notes stay editable.

**Deriving downtimes.** A stop is a downtime when its state is one of the work center's `downtime_states`
(by default `fault`, `stopped`, `setup` and `maintenance`; an empty list means the defaults, and `running` and `offline` never count) and it lasted strictly longer than
`micro_stop_threshold_seconds` (default 60; both are edited on the work center). A shorter stop is a
micro-stop and leaves no downtime. A downtime follows its interval in place: when a late sample moves or
shortens the interval, only the times of the downtime change, so the operator's cause and notes survive; a
downtime whose interval no longer qualifies is removed. A downtime is unique per work center and start.

**Events.** `DowntimeOpened` and `DowntimeClosed` announce a downtime; `DowntimeDiscarded` (company, work
center, downtime id) announces that a derived downtime went away (a late sample made its stop a micro-stop,
or it merged into an earlier one), so listeners can undo what they did on `DowntimeOpened`.

**Constraints.** A device cannot be activated, or moved, onto a work center that already has an active state
device. A manual downtime cannot be turned into a machine one, cannot be moved onto a connected work center,
and cannot share its start with another downtime of the same work center (validation error). Times are
stored as naive local times of `app.timezone`: keep it on a zone without daylight saving time (UTC, the
default), because the repeated hour of a clock change would map two instants to one value.

**Cause.** In order: the `map` (alarm code to downtime cause) of the device's alarm signals, for the alarm
code seen during the stop; then the default of the state (`setup` gives Setup, `maintenance` gives Planned
maintenance); otherwise `unclassified`, which the operator sorts out in the backoffice. The first alarm code
seen during a stop tags that interval and is stored on the downtime as `alarm_code`.

**Stops that go on.** A stop that is still open produces no new message, so `mes:machine-open-stops` runs
every minute (`withoutOverlapping()->onOneServer()`) and opens the downtime of every open stop that has
outlasted the threshold; the downtime closes when a later sample ends the interval.

**Offline.** When a device goes silent (see below) and has a state signal, the watchdog records a synthetic
`Offline` interval from the moment it was last heard (or just after the device's last interval when the
device clock runs ahead of the server), and closes it at that moment when the device is heard
again (a state sample arriving first just ends it). `Offline` is never a downtime: it is a gap in the data.

**OEE availability (ISO 22400).** For a connected work center the busy time is the working calendar time of the window minus the
planned maintenance (both planned maintenance and stops are measured by their working time, so a machine
standing still overnight is not a loss), and availability is the share of the busy time not lost to unplanned downtime, clamped
to [0, 1] (1 when there is no busy time). Planned maintenance therefore no longer counts against it. Work
centers without a machine keep the planned-time formula. The daily KPIs also carry an **incomplete data**
flag, true when an `Offline` stretch overlaps the day; the work center list shows `OEE (today)` with
`(incomplete)` then. KPI cache keys are `mes:kpi:v2:...`, so figures cached before this step are not read.

## Piece counts and OEE performance and quality

**Count rows.** Every sample of a good, scrap or total counter signal becomes a row of `mes_machine_counts`
(device, work center, operation or none, `ts` with milliseconds, `good`, `scrap`, `total`, `raw_value`),
unique per signal and moment, so the same message twice stores nothing new. The row holds the **delta** in the
column of the signal's role (the other two are 0). A signal with `mode: delta` is used as received; a
`cumulative` one is compared with the previous sample by time. The first cumulative sample is only a baseline
(delta 0). A drop is a **rollover** when the signal has `rollover_max` and the previous value was above half
of it (`rollover_max` is the highest value the counter shows, 65535 for a 16-bit one; the wrap to 0 is one count, so delta = `rollover_max - previous + value + 1`), otherwise a **reset** (counting again from zero, delta =
the new value). A delta is never negative. Caveat: an operator reset of a counter that was above half of `rollover_max` cannot be told from a rollover and adds phantom pieces; reset counters when they are low, or use `delta` mode. A late sample is inserted by its time and the row after it is
recomputed against it.

**Operation quantities.** `machine_good_quantity` and `machine_scrap_quantity` of an operation are the sums of
its attributed rows and can be recomputed at any time. When the good pieces reach the order's planned
quantity, `target_reached_at` is stamped and `OperationTargetReached` is dispatched **once**, with a
notification to the roles of `mes.notifications.operation_target` (default admin and superadmin); a recount
never clears the stamp. The machine never completes an operation.

**Declared quantities.** When an operation is completed, `declared_good_quantity` and
`declared_scrap_quantity` are prefilled from the machine ones (only when the machine counted something and
nothing was declared yet). The operator corrects them with the "Declare quantities" action of the operations
table; each changed value leaves a row in `mes_operation_quantity_audits` (old value, new value, user), and an
unchanged value leaves none. The Complete action of the order proposes the declared good quantity of its last
operation as the produced quantity.

**Unattributed counts.** Counts with no operation stay on the work center and count towards its OEE. The
"Assign machine counts" action of the operations table gives an operation the unattributed counts of its work
center inside a time range (`from <= ts < to`).

**OEE (ISO 22400).** For a work center with count rows in the window, performance is the ideal time of the
counted pieces over the run time: the ideal cycle is the operation's `cycle_time_minutes`, and counts nobody
attributed use `60 / capacity_per_hour` of the work center; the run time is the busy time minus the unplanned
downtime (working time for a connected work center). Quality is good pieces over total pieces, where the total
is the sent total or good plus scrap. Both stay in [0, 1], and with no run time performance is 1. Without count
rows in the window, or only rows with no piece in them, the old order-based formulas apply. An operation without a cycle time uses the work center's ideal cycle. Late counts of an operation that is no longer in progress still update its machine quantities but announce nothing. KPI cache keys are `mes:kpi:v3:...`.

## Probe measurements and quality checks

**Where a measurement goes.** A `measurement` signal points at a characteristic of a quality plan
(`quality_plan_characteristic_id`). A sample goes to the quality check of its attributed operation whose plan
holds that characteristic, whatever the check's status. The nominal and the limits are copied from the
characteristic, the row records `source = machine`, the signal, the measuring time and the serial from the
sample context. A limit is **inclusive**: a value exactly on it is within.

**Completion.** A check resolves by itself when every characteristic of its plan has at least its
`required_samples` (default 1, a column of the plan characteristic) among the check's machine measurements.
Manual measurements do not count towards that. The status is `failed` when any measurement of the check is out
of limits, else `passed`; a failure opens the usual non-conformance, once. `QualityCheckService::execute()`
still records and resolves in one go for a person entering values; it is now `record()` followed by
`resolve()`.

**Out of tolerance.** An out-of-limit value dispatches `OutOfToleranceMeasured` the first time it is stored,
even before the check resolves and even while the measurement waits for a check (`quality_check_id` is then
null), with a notification to the roles of `mes.notifications.out_of_tolerance` (default admin and
superadmin). A replay or a reprocess never announces it again. A value out of limits that arrives after the
check resolved is stored on the check and opens a non-conformance linked to it; the status of the check does
not change.

**Measurements that wait.** The quality check of an operation is created when the operation completes, so a
probe measuring during production finds none; the recorder never creates a check. Such a measurement (and one
with no attributed operation) goes to `mes_machine_unattributed_measurements` (signal, time, value, serial,
context, the operation when known, `assigned_at`). When the check of that operation is created, the waiting
measurements of its plan's characteristics are put on it in time order and the check resolves if it is
complete. The others are assigned one by one from "Unattributed measurements" in the "Machine connectivity"
group. Idempotency comes from the unique `(signal_id, ts)` of that table plus an existence check under a lock
on the signal, not from a unique index on the measurements: a nullable composite unique would break manual
rows on some databases.

## Process values

**The store.** Samples of `process_value` signals go to a `ProcessValueStore` (contract: `write`,
`aggregates`, `rollup`, `prune`, `pruneAggregates`, `operationStatistics`). The default driver is relational
(`mes.machine.process_store = database`); another name is refused at start with the list of the known ones, so a
time-series driver can be added later behind the same contract. The backend does not downsample on arrival:
the agent decides what to send (on change beyond a deadband, plus a heartbeat).

**Raw samples.** `mes_process_samples`: signal, time with milliseconds, value, quality and the attributed
operation, unique per signal and moment, so the same message twice stores nothing new. Order and operation
reference samples and values that are not numbers are ignored; so are values the column cannot hold (not finite, or
beyond 12 integer digits), which are dropped and logged without failing the rest of the message. A `bad` sample is stored but left out of
aggregates and summaries. A sample older than `mes.machine.raw_retention_days` (30) is not stored at all: the next
prune would delete it and its minute could no longer be rebuilt. A message is written in batches of 500 rows, so
the number of queries does not grow with the number of samples.

**Aggregates.** Each stored sample marks its minute as dirty (`mes_process_dirty_buckets`). `mes:machine-rollup`
runs every minute (`withoutOverlapping()->onOneServer()`) and rebuilds the marked minutes, 500 at a time and
batch after batch until none waits or 50 seconds have passed, from the raw samples into `mes_process_aggregates` (resolution `1m`: min, max, average, last value, count), then the hours they
touch (`1h`). A mark set while a rollup runs is kept for the next run, so a late sample or a reprocessed message
is always absorbed. The hour is rebuilt from the minute aggregates (min of mins, max of maxes, counts added, the
average weighted by the counts, the last value of the last minute), because raw samples are pruned long before.
A minute whose raw samples are gone is never recomputed, so an aggregate is never replaced by an empty one.
Buckets follow the application timezone (keep it free of daylight saving time, as for the other machine data).

**Retention.** `mes:machine-prune-process-values` runs daily: raw samples older than
`mes.machine.raw_retention_days` (30) and minute aggregates older than
`mes.machine.minute_aggregate_retention_days` (90) are deleted, a chunk of rows at a time so no statement locks
millions of rows; hour aggregates and the summaries are kept.

**Per-operation summaries.** `mes_operation_process_summaries` has one row per operation and signal: minimum,
maximum, average, count, how many samples fall outside the signal's `config.min` / `config.max` (bounds are
inclusive; none set means 0), first and last time. It is written by a queued job
(`SummarizeOperationProcessValuesJob`, unique per operation until it starts) that is queued when the operation
completes, and again when late samples attributed to an already completed operation are stored (never for a
replay that stores nothing new), so a buffer of late samples costs one scan. Completing an operation never waits
for it nor fails because of it. A row is only replaced by a summary that counts at least as many samples as it
did: pruning shrinks the samples the store holds, and the summary is the permanent record, so it is never emptied
or degraded; an operation that ran longer than the raw retention keeps the summary of what survived at the moment
it completed. `LotTracingService::processSummaries($lot_id)` returns the
summaries of the operations of the lot's production order: the link from a lot to the process parameters it was
made with. The "Process summaries" list in the "Machine connectivity" group shows them.

## Incidents and health

Incidents (`mes_machine_incidents`): `seq_gap`, `clock_skew`, `message_failed`, `auth_failure`,
`device_silent` and `bridge_down`. `mes:machine-watchdog` runs every minute: a
device silent for longer than its source's `heartbeat_timeout_seconds` opens a `device_silent` incident,
which closes when it is heard again (and the synthetic `Offline` interval with it). Incidents notify the roles and channels set under
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
