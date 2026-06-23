# Connectors

Connectors are reusable resources that wrap a third-party HTTP API behind a
small, uniform Phlo interface. They live under `resources/connectors/`, read
their credentials from `creds.ini` (see
[creds.example.ini](creds.example.ini)), and every call returns the same
normalized result object, so an app (or an agent) can talk to Shopify, Slack or
Moneybird without learning three different client shapes.

This is Phlo's answer to the "n8n node" idea: the same simplicity, backed by an
efficient PHP server architecture. This first set covers connectors as plain
resources; inbound webhooks/triggers and workflow orchestration are deliberately
out of scope for now.

## How they work

Every connector extends the `Connector` base class
(`resources/connectors/Connector.phlo`), which provides:

- credential resolution from a named `creds.ini` section,
- `get` / `post` / `put` / `patch` / `del` / `form` request helpers built on a
  curl transport that returns the HTTP status code,
- automatic JSON encoding and decoding,
- optional retry on `429`/`5xx` (off by default; set `$conn->retries`),
- pagination via `paginate()`,
- a single result contract.

### The result contract

Every connector method returns an `obj`:

```
obj(ok: true,  status: 200, data: <decoded JSON or raw string>)
obj(ok: false, status: 401, error: 'human readable message')
```

So calling code is always the same shape:

```phlo
$slack = new Slack
$res = $slack->send('#general', 'Deploy finished')
$res->ok ? notify('sent') : error($res->error)
```

A method whose credentials are missing fails closed (`ok: false`,
`error: '<Section> credentials not configured (...)'`) and never touches the
network.

### Enabling a connector

1. Add it to your app's `data/app.json` `resources` list using slash notation,
   e.g. `"connectors/chat/Slack"`. Required base resources (`Connector`,
   `creds`, and for OAuth2 connectors `TokenStore`/`OAuth2`) resolve
   automatically.
2. Add the matching section to `data/creds.ini` (copy from
   [creds.example.ini](creds.example.ini)).
3. Use it: `new Slack` (reads `[Slack]`), or `new Slack($config)` to inject a
   config array directly (handy for multi-account use and tests).

## OAuth2 connectors

Exact Online and the Google connectors use OAuth2 with refresh tokens. They
extend `OAuthConnector`, which fetches and caches a valid access token through
`TokenStore` (`resources/connectors/TokenStore.phlo`). `TokenStore` persists
tokens under `data/tokens/<section>.json` and refreshes them via the engine's
`OAuth2` resource when they expire. Seed the initial `refresh_token` in
`creds.ini`; the store rotates it from then on.

Microsoft Graph instead uses the app-only client-credentials flow, so it needs
no refresh token; its token is cached in APCu when available.

## Connector reference

Methods that read return the API payload in `data`; methods that act (create,
send, update) return the API's response for the created/affected resource.

### Webshops

**Shopify** &middot; section `[Shopify]` &middot; `connectors/shops/Shopify`
Creds: `shop_domain`, optional `api_version`, `access_token`.
Read: `customers(query)`, `searchCustomers(query, limit)`, `customer(id)`,
`orders(query)`, `products(query)`.
Act: `createDraftOrder(order)`, `createProduct(product)`,
`setInventory(inventoryItemId, locationId, available)`.

**Lightspeed** (Retail V3) &middot; section `[Lightspeed]` &middot; `connectors/shops/Lightspeed`
Creds: `cluster_id`, optional `language`, `api_key`, `api_secret`.
Read: `customers(query)`, `findCustomer(participant)`, `customer(id)`,
`sales(query)`.
Act: `createCustomer(customer)`.

### Messaging / channels

**Slack** &middot; section `[Slack]` &middot; `connectors/chat/Slack`
Creds: `bot_token`, optional `signing_secret`.
Read: `history(channel, limit)`, `channels(limit, types)`.
Act: `send(channel, text, extra)`.

**Telegram** &middot; section `[Telegram]` &middot; `connectors/chat/Telegram`
Creds: `bot_token`, optional `webhook_secret`.
Read: `updates(offset, limit)`.
Act: `send(chatId, text, extra)`, `photo(chatId, photo, caption)`,
`document(chatId, document, caption)`.

**Twilio** &middot; section `[Twilio]` &middot; `connectors/chat/Twilio`
Creds: `account_sid`, `from_number` or `messaging_service_sid`, `auth_token`.
Read: `message(sid)`.
Act: `sms(to, body, extra)`.

**MessageBird** &middot; section `[MessageBird]` &middot; `connectors/chat/MessageBird`
Creds: `originator`, `access_key`.
Act: `sms(to, body, extra)`.

**Resend** &middot; section `[Resend]` &middot; `connectors/chat/Resend`
Creds: `from_email`, `api_key`.
Act: `send(to, subject, html, extra)`.

### Accounting / finance

**Moneybird** &middot; section `[Moneybird]` &middot; `connectors/finance/Moneybird`
Creds: `administration_id`, `access_token`.
Read: `contacts(query)`, `findContact(query)`, `contact(id)`, `invoices(query)`.
Act: `createContact(contact)`, `createInvoice(invoice)`.

**Exact Online** (OAuth2) &middot; section `[ExactOnline]` &middot; `connectors/finance/ExactOnline`
Creds: `division`, `client_id`, `client_secret`, `refresh_token`.
Read: `invoices(query)`, `accounts(query)`.
Act: `createInvoice(invoice)`.

### Productivity / cloud

**Microsoft Graph** (app-only) &middot; section `[Microsoft]` &middot; `connectors/cloud/MicrosoftGraph`
Creds: `tenant_id`, `client_id`, optional `mailbox`, `client_secret`.
Read: `users(query)`, `user(id)`, `events(user, query)`.
Act: `sendMail(message, user, save)`, `createEvent(event, user)`.

**Google Calendar** (OAuth2) &middot; section `[Google]` &middot; `connectors/cloud/GoogleCalendar`
Creds: `client_id`, `client_secret`, `refresh_token`.
Read: `events(calendarId, query)`.
Act: `createEvent(event, calendarId)`.

**Google Sheets** (OAuth2) &middot; section `[Google]` &middot; `connectors/cloud/GoogleSheets`
Creds: `client_id`, `client_secret`, `refresh_token`.
Read: `values(spreadsheetId, range)`.
Act: `append(spreadsheetId, range, rows, valueInputOption)`.

## Writing your own connector

A connector is a `.phlo` file that extends `Connector`, names its creds section
and base URL, builds its auth headers, and exposes one method per action:

```phlo
@ version:  1.0
@ summary:  Example API connector
@ extends:  Connector
@ package:  connectors
@ requires: @Connector creds:Example
@ tags:     example connector

const section = 'Example'

method base => 'https://api.example.com/v1'

method headers => [static::bearer($this->config['api_key'] ?? void)]

static fields => arr(
	section: 'Example',
	secret: arr(api_key: 'API key'),
)

method things(array $query = []):obj {
	if ($m = $this->missing('api_key')) return $m
	return $this->get('things', $query)
}

method create(array $thing):obj {
	if ($m = $this->missing('api_key')) return $m
	return $this->post('things', $thing)
}
```

Helpers available from the base: `bearer()`, `basic()`, `configured(...$keys)`,
`missing(...$keys)` (returns a ready-made failure obj or null), `paginate()`,
and `fields()` for self-describing credential metadata. Override
`errorMessage($data, $raw, $status)` when an API reports failures in a
non-standard shape, or `result(obj $res)` to post-process a response (e.g. Slack
and Telegram, which return `200` with an `ok: false` body). For OAuth2 APIs with
refresh tokens, extend `OAuthConnector` and set `const tokenUrl` instead.
