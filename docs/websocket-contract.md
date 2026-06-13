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

Per incoming event phloWS spawns a **one-shot PHP CLI call**
(`<php> <app>/www/app.php websocket::<hook> <args>`). Every message is one
full Phlo request lifecycle with all resources available (DB, session, etc),
but without persistent worker state between messages.

The app side picks its port with the `websocket:` argument of `phlo_app()`;
there are no fixed port numbers, any free local port works.

## App hooks

The engine `websocket` resource maps the four hooks onto plain app functions
when they exist (`function_exists`):

| Hook | App function | When | Behaviour |
|---|---|---|---|
| `websocket::auth` | `wsAuth($host, $token, $socket)` | During the HTTP upgrade | Reject by throwing (`error()`); a clean exit accepts |
| `websocket::connect` | `wsConnect($host, $token, $socket)` | After the connection is accepted | Side effects only |
| `websocket::receive` | `wsReceive($host, $token, $socket, ...$data)` | Every client message (JSON-decoded into arguments) | Lines printed to stdout are streamed back to this client |
| `websocket::close` | `wsClose($host, $token, $socket)` | Connection closed | Side effects only |

Authentication happens **at the upgrade**: phloWS reads the `token` cookie
(no cookie = immediate 401) and runs `websocket::auth`. Validate the token
against `%session->token` or your own lookup and call `error()` to reject.
Note: if the app defines no `wsAuth`, every connection that carries a token
cookie is accepted.

`$socket` is an opaque per-connection identifier; `$token` groups all
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

- **One-shot CLI per event**: each message costs a PHP startup (~50-100ms).
  Fine for inbox-style flows; a bottleneck for high-frequency telemetry.
- **Single process**: one phloWS per runtime; if it crashes, realtime
  features are down until restart (run it under systemd/supervisor).
- **No payload versioning**: shape per app is implicit; migrate all clients
  together when refactoring.
