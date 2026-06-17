# Phlo WebSocket contract

WebSocket support is **optional**. It is provided by phloWS, a small
standalone Node.js server (its own repository; single dependency: `ws`).
Nothing else in the framework depends on it.

## Runtime model

One phloWS process serves one or more hosts on a single local port:

```js
require('./phloWS.js')(3001, '/usr/bin/php', {
    'example.com': '/srv/example.com/www/app.php',
    'other.app':   '/srv/other.app/www/app.php',
})
```

Arguments: `(port, phpBinary, hostMap, listen = '127.0.0.1', maxBody = 1MB)`.
Routing is by `Host` (or `X-Forwarded-Host`) header. The same port handles
three endpoints:

| Endpoint | Method | Purpose |
|---|---|---|
| `/websocket` | upgrade | Client WebSocket connections |
| `/message` | POST | Server-to-client casts (used by the `wsCast` resource) |
| `/health` | GET | Status: configured hosts, connected tokens/sockets |

phloWS dispatches each event to the app one of two ways, chosen per host in the
host map:

- **Persistent worker (recommended for production).** Configure a host as
  `{ app: '…/app.php', workers: N }`. phloWS keeps a pool of `N` long-lived
  `php <app>/www/app.php phlo_ws_serve` processes per host. Each boots the app
  **once** and then answers events over a private stdin/stdout pipe, running
  `phlo('tech/reset')` + `gc_collect_cycles()` between events (the same
  isolation the FrankenPHP worker loop uses). No PHP startup per message;
  throughput grows with `N`, bounded by CPU cores. A worker handles one event
  at a time, so a host's per-message concurrency is its worker count.
- **One-shot CLI (default / development).** Configure a host as a plain string
  `'…/app.php'` and phloWS spawns a fresh `<php> <app>/www/app.php
  websocket::<hook> <args>` per event. Simple and isolated, but pays a PHP
  startup each message. Use this for `build: true` dev (hot-reload just works)
  or low-traffic hosts.

Either way each message is a full Phlo lifecycle with all resources available
(DB, session, etc); the persistent worker simply keeps the boot and the
`objPers` connections (DB, etc) alive between messages. The worker loop lives in
engine core (`phlo_ws_serve()` in `phlo.php`) and dispatches to whichever
`websocket` class the app provides, so app handlers are unchanged.

The app side picks its port with the `websocket:` argument of `phlo_app()`;
there are no fixed port numbers, any free local port works.

**Worker-safe handlers.** In persistent mode the process is reused, so the four
hooks must follow the same discipline as FrankenPHP worker mode (see
`deploy.md`): no request/user state in `static`s, always commit/rollback DB
work, no dangling global ini/locale changes. `phlo('tech/reset')` drops the
transient object registry between events but cannot undo those. Handlers that
were safe under one-shot CLI but rely on the process dying to clean up need a
look before switching a host to `workers`.

## App hooks

The engine `websocket` resource maps the four hooks onto plain app functions
when they exist (`function_exists`):

| Hook | App function | When | Behaviour |
|---|---|---|---|
| `websocket::auth` | `wsAuth($wsHost, $wsToken, $wsSocket)` | During the HTTP upgrade | Reject by throwing (`error()`); a clean exit accepts |
| `websocket::connect` | `wsConnect($wsHost, $wsToken, $wsSocket)` | After the connection is accepted | Side effects only |
| `websocket::receive` | `wsReceive($wsHost, $wsToken, $wsSocket, ...$data)` | Every client message (JSON-decoded into arguments) | Lines printed to stdout are streamed back to this client |
| `websocket::close` | `wsClose($wsHost, $wsToken, $wsSocket)` | Connection closed | Side effects only |

The connection context arguments are **`ws`-prefixed by convention** (`$wsHost`,
`$wsToken`, `$wsSocket`), matching `wsCast`. This is not cosmetic: `wsReceive`
spreads the JSON payload into named arguments (`...$data`), so an unprefixed
`$host`/`$token`/`$socket` parameter would fatally collide with a payload that
carries a `host`, `token` or `socket` key (PHP: "named parameter overwrites
previous argument"). Keep the prefix and your payload keys stay free.

Authentication happens **at the upgrade**: phloWS reads the `token` cookie
(no cookie = immediate 401) and runs `websocket::auth`. Validate the token
against `%session->token` or your own lookup and call `error()` to reject.
Note: if the app defines no `wsAuth`, every connection that carries a token
cookie is accepted.

`$wsSocket` is an opaque per-connection identifier; `$wsToken` groups all
connections of the same user/session.

## Server-to-client: wsCast

```phlo
wsCast('all', host, websocket, channel: 'inbox', type: 'message.new')
```

`wsCast($target, $host, $port, ...$data)` posts `{host, target, data}` to
`/message`. Targets:

- `all`: every client on this host
- `token:<token>`: all connections of one token
- `token:not:<token>`: everyone except one token (e.g. the sender)

**No retry, no dead-letter, no ACK.** If phloWS is down the POST fails
silently. For guaranteed delivery (financial events, etc) pair it with a
DB queue.

## Message envelope (convention)

Payload shape is per app, but the stack convention is:

```json
{
  "channel": "inbox",
  "type": "message.new",
  "data": {},
  "id": "uuid-v4"
}
```

## Client side

The `DOM/websocket` resource provides the browser client: connect, token
cookie, and exponential-backoff reconnect. phloWS itself never retries.

## Known limitations

- **One-shot CLI mode costs a PHP startup per event** (~50-100ms). Fine for
  inbox-style flows; for high-frequency telemetry switch the host to a
  persistent `workers` pool, which removes that cost entirely.
- **Persistent workers serialize per worker**: a worker handles one event at a
  time, so a slow handler (e.g. a long LLM stream) blocks that worker until it
  finishes. Size `workers` for the expected concurrency and rely on the
  per-request timeout; keep genuinely long jobs off the receive path (trigger
  them in the background and stream results via `wsCast`).
- **Persistent workers hold old code until restarted**: after deploying new
  handler code, restart phloWS (or the workers) so they reload — exactly like
  FrankenPHP worker mode.
- **Single process**: one phloWS per runtime; if it crashes, realtime
  features are down until restart (run it under systemd/supervisor).
- **No payload versioning**: shape per app is implicit; migrate all clients
  together when refactoring.
