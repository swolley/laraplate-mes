# Machine connectivity

Machines and probes push their data into the MES through the `laraplate-machine/1` protocol. This is the
foundation (step 1 of the machine data acquisition design): the protocol, the HTTP endpoint, a durable
inbox, the asynchronous pipeline with its configuration, and the backoffice. Nothing consumes the machine
data yet: the pipeline dispatches typed events (`MachineStateObserved`, `PartsCounted`, `ProbeMeasured`,
`ProcessValuesSampled`) that the later steps will listen to.

**Not built yet** (later steps): the MQTT bridge and the `sparkplug_b` normaliser (step 2), machine state
intervals and automatic downtimes (step 3), piece counts and ISO 22400 OEE (step 4), probe measurements
filling quality checks (step 5), process values and their storage (step 6).

## How a message travels

```
agent or gateway --HTTP--> MachineMessageInbox::accept() --> mes_machine_messages (pending)
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

## Normalisers

| Key | Input | Notes |
|---|---|---|
| `canonical` | `laraplate-machine/1` | No translation. The only one the HTTP endpoint accepts. |
| `mapped_json` | Any JSON (Node-RED, Kepware IoT Gateway, ...) | Configured by the source's `normalizer_options`, below. |

`mapped_json` options, as dot-notation paths read with `data_get`: `samples_path` (path of a list of samples;
absent, the root object is one sample), and per sample `device`, `signal`, `timestamp`, `value`, optionally
`quality` with a `quality_map` (raw value => `good`/`uncertain`/`bad`); `timestamp_format` is `iso8601`
(default), `unix` or `unix_ms`; `message_id_path` names the message id, otherwise it is the `sha1` of the
payload. A sample missing its device, signal, timestamp or value is skipped. The MQTT bridge (step 2) feeds
these.

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
`device_silent` (`bridge_down` arrives with the MQTT bridge). `mes:machine-watchdog` runs every minute: a
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
