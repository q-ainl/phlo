# Phlo WebSocket contract

## Runtime

PhloWS = Node.js WebSocket-server in `/srv/websocket/phloWS.js`. Centraal proces op poort `3001` voor de hele Wapps-stack. Multi-host: vhost-routing via subprotocol-veld of host-header in de WS-handshake.

Per inkomend WS-event start PhloWS een **one-shot CLI-call** naar PHP (`php-zts /srv/<app>/www/app.php ws::<event>`). Dat betekent: elke message = 1 PHP-request lifecycle, met alle resources beschikbaar (DB, session, etc), maar zonder persistent worker-state tussen messages.

## App-lifecycle hooks

In `<app>/websocket.phlo` definieert een app 4 statische methods. PhloWS roept ze aan op de juiste momenten.

| Hook | Signature | Wanneer | Return |
|---|---|---|---|
| `wsConnect` | `($host, $socket)` | Direct na WS-handshake | true = accept, false = reject |
| `wsAuth` | `($host, $token, $socket)` | Eerste auth-message (zie auth-flow hieronder) | true = authenticated, false = sluiten |
| `wsReceive` | `($host, $data, $socket)` | Voor elk inkomend bericht | void (broadcasts via wsCast indien nodig) |
| `wsClose` | `($host, $socket)` | Verbinding sluit (door client of server) | void |

`$socket` is een opaque identifier (string) waarmee je terug naar deze client kunt broadcasten.

## Auth-flow

PhloWS implementeert een 2-staps handshake:

1. Browser opent WS naar `wss://<host>:3001/`.
2. PhloWS roept `wsConnect`. Bij `false`: sluit.
3. Eerste inkomend bericht moet `{type: 'auth', token: '<string>'}` zijn binnen N seconden.
4. PhloWS roept `wsAuth($host, $token, $socket)`. App valideert token tegen `%session->token` of een eigen lookup.
5. Bij `false`: sluit verbinding. Bij `true`: socket marked authenticated.
6. Daarna roept PhloWS `wsReceive` voor elk volgend bericht.

Token in step 3 komt typisch uit `%user->token` (per ingelogde user) of een Stripe/OAuth-style API-key.

## Server-naar-client (wsCast)

PHP broadcast via `%wsCast->emit($target, $data)`:
- `$target`: array van `$socket`-identifiers, of `*` voor alle clients op deze vhost
- `$data`: serializable payload

Onder de motorkap: HTTP POST naar `localhost:3001/message` met `{host, target, data}`. PhloWS pusht naar betreffende sockets.

**Geen retry, geen dead-letter, geen ACK**. Als PhloWS down is, faalt de POST stil. Voor zekere delivery (financial events, etc): dubbel via DB-queue + ws.

## Message envelope (aanbevolen)

Apps zijn vrij in payload-format, maar de stack-conventie is:

```json
{
  "channel": "inbox",
  "type": "message.new",
  "data": {...},
  "id": "uuid-v4"
}
```

Met deze envelope kan een client-side helper `phlo.ws.onMessage(channel, type, cb)` filteren zonder per app eigen routing te schrijven.

## Reconnect (client-side)

PhloWS doet geen herhalingen aan zijn kant. De client moet bij `close`-event een exponential-backoff-reconnect doen (1s, 2s, 4s, 8s, max 30s). Standaard helper hiervoor: `dom/websocket` resource (zie `phlo.dom websocket client helper.txt` prompt).

## Bekende limitaties

- **One-shot CLI per event**: elke message kost een PHP-opstart (~50-100ms). Bij hoog volume (POS realtime, telemetry burst) = bottleneck. Niet kritiek voor inbox-flows; wel voor real-time-trading scenarios.
- **Geen versiebeheer**: payload-shape per app is impliciet. Bij refactor: alle clients tegelijk migreren.
- **Single point of failure**: 1 PhloWS-proces voor stack. Bij crash: alle realtime-features down tot restart.

## Multi-host routing

PhloWS gebruikt de WS-handshake `Host` header om te bepalen welke `<app>/websocket.phlo` aan te roepen. Mapping in `/srv/websocket/phloWS.js`. Toevoegen van een nieuwe vhost = JSON-config edit + PhloWS restart.
