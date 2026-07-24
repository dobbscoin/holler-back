# Holler Back — design

Status: **scoping doc, no code yet.** Greenfield project.

## Goals (in priority order)

1. **Small operator friendly.** Someone running a single VPS with xmr-cart
   or a static blog should be able to add a chat bubble to their site in
   one afternoon.
2. **Actually self-hosted.** No third-party push provider dependency, no
   phone-home, no vendor lock-in. If you can `curl` your VPS from outside,
   Holler Back works.
3. **Privacy-respecting by default.** Visitor IPs hashed at ingest, chat
   log retention configurable and rolling-purged, no third-party trackers
   embedded in the widget.
4. **One phone, no app store.** Operator can chat back from their phone
   via a PWA installed from the admin console URL — no Play Store, no
   App Store, no separate codebase.
5. **Minimal ops.** One PHP process, one SQLite file, one nginx vhost.
   Same shape as xmr-cart.

## Non-goals

- Multi-tenant SaaS
- Voice / video calls
- CRM features (contact lists, tags, custom fields)
- Team-chat (Slack-style) internal use
- Native mobile apps (see notification architecture below for why not)

## Three pieces

### 1. `widget` — the JS bundle you drop on any page

Small (~5–10 KB gzipped) vanilla JS. Renders a chat bubble in the corner,
opens a chat pane on click, connects to your Holler Back server over
WebSocket (or falls back to SSE / long-poll if WS is blocked).

```html
<script src="https://chat.mystore.com/widget.js"
        data-holler-id="xy2p9m"
        async defer></script>
```

That's the whole integration. The `data-holler-id` is a per-install token
generated in your Holler Back admin — it identifies which "workspace" the
message belongs to. Origin is checked on the server against an
allowed-origins list (so someone can't scrape your widget ID and embed it
on their site to spam you).

### 2. `server` — the backend you run on your VPS

Stack: **PHP 8.2+ / SQLite / WebSockets** (via [Ratchet](http://socketo.me/)
or a `stream_socket_server` implementation to avoid pulling Composer).
Same shape as xmr-cart: one code tree, one SQLite DB, one `config.php`,
one cron for cleanup.

**HTTP endpoints:**
- `GET  /widget.js` — serves the widget bundle, with `data-holler-id`
   configured origin whitelist
- `POST /api/conversation/create` — widget starts a new conversation
- `POST /api/message/send` — widget or admin posts a message
- `GET  /api/admin/conversations` — operator lists open chats
- `GET  /api/admin/conversation/{id}/messages` — full transcript
- `POST /api/admin/subscribe` — register a Web Push subscription
- `GET  /admin/` — the operator console PWA
- `GET  /manifest.json` + `/sw.js` — PWA manifest + service worker

**WebSocket endpoint:**
- `WS /ws/widget/{conv_id}` — buyer's live channel
- `WS /ws/admin` — operator's fan-in from all open conversations

**Storage:**
- `workspaces` — id, install_token (the `data-holler-id`), allowed_origins,
  operator_display_name, retention_days
- `conversations` — id, workspace_id, buyer_visitor_hash, buyer_name,
  buyer_email, created_at, last_activity_at, status
- `messages` — id, conversation_id, sender ('buyer'|'operator'|'system'),
  body, sent_at
- `push_subscriptions` — id, workspace_id, endpoint, p256dh, auth, ua_hint,
  subscribed_at
- `kv` — operator passphrase hash, VAPID keys, config

### 3. `admin` — operator console (PWA)

Browser-native. Runs at `https://chat.mystore.com/admin/`. Passphrase-gated
just like xmr-cart's admin (first-run wizard sets the hash, kv table stores
it).

**Layout (single-page):**
- Left column: list of conversations (recent activity first, unread bold)
- Right column: currently-selected conversation, transcript, reply box
- Top bar: workspace picker (if operator manages multiple sites),
  online/offline toggle, settings gear

**Push / notification story — three tiers:**

1. **Admin tab open, focused** — reply box has focus, incoming messages
   just appear inline. No notification needed.
2. **Admin tab open, backgrounded (or another tab)** — the service worker
   fires a browser `Notification` when a message arrives on any of the
   operator's WebSocket channels. Tab title also updates ("(2) Admin").
3. **Admin tab closed OR admin console never open** — Web Push (VAPID)
   wakes the service worker in the background and shows a system-level
   notification. Tapping the notification opens the admin console at the
   right conversation.

Tier 3 is the "phone in pocket" story. Operator visits the admin console
URL on their phone in a browser once, taps "Add to Home Screen" (PWA
manifest tells the browser this is app-shaped), grants notification
permission, done. From then on:

- Chrome / Edge on Android: full background Web Push, indistinguishable
  from a native app notification.
- Safari on iOS 16.4+: Web Push works, but *only* if the site was added
  to home screen — a real constraint we surface in the setup wizard.
- Firefox on Android / desktop: works.
- Older iOS: no push. Operator sees chats only when they open the URL.
  Documented explicitly, not "silently degraded."

**Why not a native Android app** (or iOS, for that matter):
- Doubles the codebase for the notification path
- Introduces app-store distribution overhead + signing / renewal
- Ties push to FCM (Google) or UnifiedPush (niche) — breaks self-hosted
  ethos
- Modern PWAs on Android are 95% as good; on iOS 16.4+ they're close
- If it turns out to be a real production pain-point later, the server
  API doesn't need to change — a native app can be added without
  refactor

## Data flow — buyer sends "Do you ship to Canada?"

1. Widget loads. `GET /widget.js?id=xy2p9m` → server checks Origin against
   allowed_origins for that workspace, serves the bundle.
2. Buyer clicks the chat bubble. Widget opens `WSS /ws/widget/new` and
   POSTs `/api/conversation/create` with workspace ID. Server returns
   conv_id.
3. Widget upgrades WS to `WSS /ws/widget/{conv_id}`.
4. Buyer types + sends. Widget POSTs `/api/message/send` (or emits over
   WS — MVP picks one, probably POST for clarity).
5. Server persists to `messages`, then fans out:
   - To any `WS /ws/admin` connections for this workspace → live-appears
     in admin console
   - To any active `push_subscriptions` for this workspace whose
     `endpoint` last succeeded → Web Push
6. Operator's phone buzzes. They tap the notification, PWA opens at the
   conversation, they reply.
7. Reply flows back: `POST /api/message/send` from admin → persist →
   fan out over `WS /ws/widget/{conv_id}` → buyer sees the reply in their
   still-open chat bubble.

## Privacy properties

- **Visitor IP** hashed with a per-workspace salt before storage. Raw IP
  never written to disk. Salt rotates on `retention_days` boundary so
  buckets don't stay correlatable forever.
- **Chat log retention** — operator-configurable, default 30 days. A
  nightly cron purges expired conversations wholesale.
- **No third-party trackers.** Widget bundle has zero external calls
  other than the Holler Back backend the operator specifies.
- **TLS required.** Server refuses to accept messages over plaintext HTTP
  (except localhost in dev mode).
- **Origin-locked.** Widget-ID is public (it's in the `<script>` tag) —
  spam protection is at the server via Origin header check.

## MVP scope

Ship in one push:

1. Server: HTTP endpoints above, WS endpoints, SQLite schema, seedable
   `config.php`, first-run passphrase wizard, VAPID keypair generation
2. Widget: bubble UI, opens pane on click, name+email prompt, send/receive
   over WS with SSE fallback
3. Admin console PWA: passphrase login, conversation list + reply pane,
   Web Push subscribe flow, service worker with notification handler
4. Nginx vhost recipe + cron for log purge
5. `install.md` — a walkthrough that a small operator can follow end to end

## Post-MVP

- Multi-operator (assign chats, presence indicators, typing indicators)
- Canned replies / keyboard shortcuts
- File uploads (images) with size caps and MIME whitelist
- Off-hours autoresponder ("we're closed — leave your email and we'll
  get back")
- Multi-language (buyer-side UI strings only)
- Rich text / markdown in messages
- Email fallback: if operator hasn't responded in X minutes AND buyer
  gave an email, send the transcript there
- Native Android app (only if PWA push reliability turns out to be a real
  problem)

## Integration with xmr-cart

xmr-cart's `config.php` gets two optional keys:

```php
'holler_back_url'       => '',   // e.g. https://chat.mystore.com
'holler_back_widget_id' => '',   // paste the install token from Holler Back admin
```

If both are set, the storefront template injects the widget `<script>` tag.
Nothing else changes in xmr-cart — the two projects stay decoupled.

## Deployment shape (matches xmr-cart)

```
/var/www/chat.mystore.com/
├── config.php               # widget IDs, allowed origins, admin hash, VAPID keys
├── data/
│   └── holler.sqlite        # conversations, messages, subscriptions
├── lib/                     # server-side classes
├── public/
│   ├── widget.js            # served as-is
│   ├── manifest.json        # PWA manifest
│   ├── sw.js                # service worker
│   └── admin/               # PWA console
├── worker/
│   └── purge.php            # nightly retention purge
└── ws-server.php            # long-running WebSocket process (systemd unit)
```

Nginx reverse-proxies WS to `ws-server.php` (localhost). Systemd unit
runs `php ws-server.php` and restarts on failure. Same discipline as
running `monerod` on guru.

## Open questions

- **WebSocket library choice.** Ratchet (Composer, mature) vs a
  hand-rolled `stream_socket_server` (zero dependencies, more code to
  own). Xmr-cart's ethos is "no framework", so leaning hand-rolled — but
  Ratchet is the pragmatic pick if WS spec compliance eats too many
  weekends.
- **VAPID key generation.** PHP has no built-in — probably use
  `web-token/jwt-signature` for JWS signing, or shell out to `openssl`
  for the raw ECDSA. Weighing on the same "no Composer if possible" axis.
- **iOS notification story.** Web Push on iOS 16.4+ is real but constrained
  (must be installed as PWA first). Should the setup wizard detect Safari
  on iOS and show explicit instructions? Probably yes.
- **Multi-workspace vs single-workspace MVP.** Single is simpler to ship,
  but multi is table stakes for someone running 3 sites. Might design the
  schema multi-tenant from day one but MVP the UI as single-workspace.
