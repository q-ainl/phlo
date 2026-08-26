# Connection: who decides that the server is gone

Phlo has no clock that asks whether the server is still there. A verdict on the connection
comes from the traffic itself, and there are exactly three sources of truth:

| Signal | Verdict |
|---|---|
| A request that gets no answer at all (`error`, `timeout` on the XHR) | offline |
| A websocket reconnect attempt that fails | offline |
| Any answer to a request, whatever its status, or a websocket that opens | online |

A websocket that merely closes is **not** a verdict: sockets drop for a hundred reasons while
HTTP keeps working, and the reconnect that follows (backoff tripling to half a minute, and at
once when the network says it is back or the tab becomes visible) is the only probe there is.
A blip of a second therefore never shows as offline: the close says nothing, the reconnect
succeeds, and the verdict never moved.

## The pieces

- `phlo.request` returns the `XMLHttpRequest` it made, or nothing when a blocking request was
  already running. It sets no timeout and shows nothing on its own: a request that needs a
  deadline sets `xhr.timeout` itself, and a stream that may take minutes sets none.
- `app.websocket.send()` answers `true` or `false`. Whether a message that could not go out is
  queued or dropped is the app's call.
- `DOM/connection` (opt-in) turns those signals into one verdict: `app.online` (read-only),
  `offline` on `<body>` for the stylesheet, and `app.connection.on('change', (online, reason) =>
  ...)` for anything that must pause or refuse.

## What an app does with it

The resource decides nothing about what a visitor sees; that differs per kind of app.

- A **site** treats a lost connection as an inconvenience: the page that is there stays
  usable, a navigation that fails falls back to a full page load, and the socket reconnects
  quietly.
- A **back office** must not lose work: a save that fails keeps the form and its values and
  says so, a live view pauses and shows when it was last updated, and refreshes itself on
  `change`.
- A **device** such as a till lets the verdict steer behaviour rather than messages: whatever
  works locally keeps working, whatever needs the server refuses with a reason, a queue replays
  when the verdict flips back, and the first thing after a reconnect is to fetch the truth
  (which day, which version), because a cast that was missed while offline is gone.

That last rule holds everywhere: a websocket cast is an event, not state. Anything a client
must know after a reconnect it asks for on `connect`; nothing is kept against a socket that a
row in the database can hold instead.
