# Holler Back — install

End-to-end walkthrough. Assumes Debian/Ubuntu, nginx, PHP 8.2+, and a
subdomain (e.g. `chat.example.com`) already pointing at this box.

## 1. Files

```bash
sudo mkdir -p /var/www/holler-back
sudo chown $USER:www-data /var/www/holler-back
git clone <your fork> /var/www/holler-back    # or rsync from a working copy
cd /var/www/holler-back
cp config.example.php config.php
```

Edit `config.php`:
- `server_name` — display name
- `server_url` — canonical https URL
- `cookie_secret` — `openssl rand -hex 32`
- `vapid_subject` — a `mailto:` you actually monitor

Permissions:

```bash
sudo chown -R $USER:www-data /var/www/holler-back
sudo chmod 640 /var/www/holler-back/config.php
sudo chmod -R g+w /var/www/holler-back/data
```

## 2. PHP + extensions

```bash
sudo apt install php8.3-cli php8.3-fpm php8.3-sqlite3 php8.3-gmp php8.3-curl php8.3-mbstring
```

`pcntl` is used by `ws-server.php` for SIGTERM handling — it's built into
the CLI SAPI on Debian/Ubuntu, no separate package needed.

## 3. WebSocket process + notification worker — systemd

```bash
sudo cp deploy/holler-back.service deploy/holler-back-notify.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now holler-back holler-back-notify
sudo systemctl status holler-back holler-back-notify
```

`holler-back-notify` sends Web Push, Discord alerts and the away message.
Without it chat still works, but nothing notifies you.

If it fails to start, check:

```bash
sudo journalctl -u holler-back --since '5 min ago'
sudo tail -n 100 /var/log/holler-back.log
```

## 4. Nginx

```bash
sudo cp deploy/holler-back.nginx.conf /etc/nginx/sites-available/chat.example.com
sudo ln -s /etc/nginx/sites-available/chat.example.com /etc/nginx/sites-enabled/
# edit server_name in the file first, then:
sudo nginx -t && sudo systemctl reload nginx
```

## 5. TLS

```bash
sudo certbot --nginx -d chat.example.com --redirect --agree-tos -m ops@example.com
```

## 6. Cron

```bash
sudo tee /etc/cron.d/holler-back <<'EOF'
17 3 * * * www-data /usr/bin/php /var/www/holler-back/worker/purge.php
EOF
```

`purge.php` logs to `data/holler.log` itself. Don't redirect into
`/var/log/holler-back.log`: systemd creates that file as root, so a
www-data redirect fails and the purge never runs.

## 7. First-run

Set `setup_key` in config.php first (`openssl rand -hex 16`). The setup
page asks for it, so a stranger who reaches a fresh install first can't
claim it. You can pre-fill it: `https://chat.example.com/admin/setup.php?key=…`.
Restricting `/admin/` to a VPN in nginx (`allow …; deny all;`) is extra
defense, but your phone then needs the VPN to get the console.

Visit `https://chat.example.com/admin/` — you'll be sent to the setup wizard.
Set a passphrase (≥10 chars), enter the allowed origins for your widget
(the domains your site runs on — one per line), and save.

The wizard shows you the `<script>` snippet with your workspace's install
token baked in. Paste it into any page you want the bubble on:

```html
<script src="https://chat.example.com/widget.js"
        data-holler-id="xy2p9m"
        async defer></script>
```

## 8. Push notifications

In the console top bar, click **Enable push**. Your browser will ask for
notification permission — grant it. On desktop you're done.

On phone:
- **Android** (Chrome, Edge, Firefox): open the console URL in the browser,
  tap the ⋮ menu → **Install app** (or **Add to Home Screen**), then open
  the installed app and click **Enable push**.
- **iPhone / iPad, iOS 16.4+**: open the console URL in Safari, tap the
  Share icon → **Add to Home Screen**, then open the installed app and
  click **Enable push**. Push doesn't work from the browser tab itself on
  iOS — only from the installed PWA.

Push only fires when no console window is on screen. A console left open in a
background tab or a minimized window doesn't block it. Settings → Notifications
→ **Send push test** checks every subscribed device.

## 8b. Discord alerts and the away message (optional)

Settings → **Notifications**:

- **Discord webhook URL**: in Discord, open the channel's settings →
  Integrations → Webhooks → New Webhook → Copy Webhook URL, then paste it here
  and click **Save**, then **Send Discord test**. Once saved, the URL is shown
  masked and never sent back to the browser (anyone with it can post to that
  channel).
- **New conversations** pings when a buyer first writes. **Unanswered messages
  after N minutes** pings once per unanswered stretch. **Include the buyer's
  message text** is off by default, so the channel sees only a name and a link.
- **Away message**: if nobody replies within N minutes, this text is posted
  into the buyer's chat once per conversation. Links in it are clickable, e.g.
  a Discord invite or a contact page.

## 9. Verify end-to-end

1. Visit any page with your widget snippet. Click the bubble, enter a name,
   send a message.
2. In the admin console the conversation shows up on the left in real time.
3. Reply from the console. Buyer sees the reply live.
4. Close (or switch away from) the admin tab. Send another message from the
   widget. Your phone buzzes.

If step 4 doesn't work, first check `systemctl status holler-back-notify`, then
`sudo tail /var/log/holler-back.log` for `push:` and `notify:` lines — non-2xx from the push provider (Google/Apple/Mozilla)
is logged with the status code and body preview.

## Troubleshooting

**Widget shows "Could not start chat"** — origin isn't in the workspace's
allowed origins. Add it in the console Settings tab.

**"Reconnecting…" banner never clears** — `ws-server` isn't running or
nginx WS proxy isn't reaching it. Check `curl http://127.0.0.1:8090/`
returns `426 Upgrade Required` (means it's listening). nginx must use
`proxy_pass http://127.0.0.1:8090;` with **no** trailing slash — the server
routes on the full `/ws/...` path.

**Push subscribe fails with "vapid_not_configured"** — the VAPID keys
weren't generated. Run `php -r 'require "lib/bootstrap.php"; require
"lib/Vapid.php"; Vapid::ensureKeys();'` and check `data/vapid.pem` exists
with `0600` perms.

**Wallet/keys look wrong** — nothing here holds spending secrets. VAPID
keys are per-install push identity only; regenerate freely by deleting
`data/vapid.pem` and the `vapid_public` row in the `kv` table (subs
issued under the old key will just stop working; browsers re-subscribe
automatically next time the console is opened).
