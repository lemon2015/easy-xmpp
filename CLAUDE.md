# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Easy-XMPP is a PHP XMPP client library (based on xmpphp), published as `mark-fish/easy-xmpp` on Packagist. It supports TCP and BOSH (HTTP long-polling) connections, PLAIN and DIGEST-MD5 authentication, and TLS encryption.

## Build / Test

```
composer install          # Install dependencies
composer cs               # PSR-12 check (phpcs)
php tests/E2E.php         # End-to-end test against a real XMPP server (env overridable)
php tests/MucE2E.php      # XEP-0045 MUC end-to-end test (env overridable)
php tests/PubSubE2E.php   # XEP-0060 PubSub end-to-end test (env overridable)
php tests/Client.php      # Interactive manual test client (requires a real XMPP server)
```

There is no test framework (PHPUnit/Pest) or CI configured. `tests/E2E.php` is a bounded integration test; `tests/Client.php` is an interactive manual client.

## Architecture

```
EasyXmpp\Stream\XMLStream    # TCP socket, XML parsing, event dispatch, handler routing
  └── EasyXmpp\XMPP          # XMPP protocol: auth, TLS, message/presence/roster/vcard
        └── EasyXmpp\BOSH    # XMPP over BOSH (HTTP long-polling via cURL)

EasyXmpp\Util\XMLObj          # Parsed XML element tree (name, ns, attrs, subs, data)
EasyXmpp\Util\Log             # Default PSR-3 logger, writes via error_log when enabled
EasyXmpp\Support\Config       # ArrayAccess with dot-notation get()
EasyXmpp\XEP\Roster           # Contact list and presence tracking
EasyXmpp\XEP\MUC              # XEP-0045 MUC rooms (standard presence/message/IQ only)
EasyXmpp\XEP\PubSub            # XEP-0060 PubSub nodes/items/subscriptions (standard IQ/message)
EasyXmpp\Exceptions\Exception  # Base exception
EasyXmpp\Exceptions\MyException # Extended exception for connection errors
```

### Event-driven design

`XMLStream` uses PHP's `xml_parser` to parse the XMPP stream. When a complete XML stanza is parsed (depth returns to 1), it is dispatched through three handler chains:

1. **XPath handlers** (`addXPathHandler`) — namespace-aware path matching, e.g. `{jabber:client}message`
2. **Namespace handlers** (`addHandler`) — legacy name+ns matching
3. **ID handlers** (`addIdHandler`) — one-shot match on `id` attribute, auto-removed after firing

Handlers are registered in `XMPP.__construct()` and called as methods on `$this` (e.g., `features_handler`, `message_handler`).

### Event loop

The typical usage pattern is:

```php
$conn->connect();
while (!$conn->isDisconnected()) {
    $payloads = $conn->processUntil(['message', 'presence', 'end_stream', 'session_start', 'vcard']);
    foreach ($payloads as $event) {
        // $event[0] = event name, $event[1] = payload array
    }
}
```

`processUntil()` blocks until one of the specified events fires or a timeout occurs. Events are dispatched by `XMLStream.event()` which triggers both registered event handlers and the `processUntil` wait list.

### Connection flow

TCP (`XMPP`): stream open → TLS negotiation (optional) → SASL auth (PLAIN or DIGEST-MD5) → resource bind → session start → ready for stanzas.

BOSH (`BOSH`): HTTP POST to `/bosh` endpoint with session management via `$_SESSION`. Reuses the same XMPP protocol handlers from the parent class.

### Key design notes

- The parser is reset on TLS negotiation and stream restart (`reset()`), which resends the stream header and reinitializes the XML parser.
- `$authed` flag controls whether `featuresHandler` proceeds to bind or auth.
- `stream_socket_client` is used for TCP; `curl` for BOSH HTTP transport.
- Automatic reconnect is **off by default** (`reconnect` config, default `false`); long-running processes are expected to be supervised externally. When disabled, connection failures call `dropConnection()` and set `disconnected = true`.
- When reconnect is enabled, `doReconnect()` is rate-limited to at most one attempt per second to avoid reconnect storms.
- `processUntil()` waits using the remaining timeout and `processOnce(..., true)` returns as soon as data arrives, so idle waits do not busy-poll.
- Logging uses `Psr\Log\LoggerInterface`; pass any PSR-3 logger via the `logger` config key, otherwise the built-in `Log` is used.
- XEP modules use `XMPP::requestIq()` for synchronous IQ request/response; it matches responses by id and throws on timeout/error.
- The only external dependency is `psr/log`; BOSH additionally needs `ext-curl`/`ext-simplexml`/`ext-dom`.