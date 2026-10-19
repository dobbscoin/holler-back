/**
 * Admin console client: conversation list, reply pane, WS live updates, push subscribe.
 * Loaded from /admin/index.php. Depends on the window.HB object seeded there.
 */
(function () {
  var HB = window.HB;
  var state = { convs: [], current: null, lastMsgId: 0, ws: null, reconnectDelay: 1000 };

  function api(method, path, body) {
    var opts = {
      method: method,
      headers: { 'Content-Type': 'application/json', 'X-CSRF': HB.csrf },
      credentials: 'same-origin',
    };
    if (body != null) opts.body = JSON.stringify(body);
    return fetch(path, opts).then(function (r) {
      // Session expired (12h) — go sign in rather than retrying forever
      if (r.status === 401) { location.href = '/admin/login.php'; return new Promise(function () {}); }
      return r.json().then(function (j) { return { code: r.status, body: j }; }).catch(function () { return { code: r.status, body: {} }; });
    });
  }

  /* --------- WebSocket connection to /ws/admin --------- */
  function openWS() {
    api('GET', '/api/admin/ws-token').then(function (r) {
      if (r.code !== 200) { setTimeout(openWS, 3000); return; }
      var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
      var url = proto + '//' + location.host + '/ws/admin?token=' + encodeURIComponent(r.body.token);
      var ws;
      try { ws = new WebSocket(url); } catch (e) { setTimeout(openWS, 3000); return; }
      state.ws = ws;
      ws.onopen = function () {
        setConn('online');
        state.reconnectDelay = 1000;
        sendPresence();
      };
      ws.onmessage = function (ev) {
        var env; try { env = JSON.parse(ev.data); } catch (e) { return; }
        onEnvelope(env);
      };
      ws.onclose = function () {
        setConn('offline');
        setTimeout(openWS, state.reconnectDelay);
        state.reconnectDelay = Math.min(state.reconnectDelay * 2, 30000);
      };
      ws.onerror = function () { try { ws.close(); } catch (e) {} };
    });
  }
  // Tell the server whether this console is on screen: push is skipped only
  // while one is visible, so a forgotten background tab doesn't swallow alerts.
  function sendPresence() {
    if (state.ws && state.ws.readyState === 1) {
      state.ws.send(JSON.stringify({ type: 'presence', visible: document.visibilityState === 'visible' }));
    }
  }
  document.addEventListener('visibilitychange', sendPresence);

  function setConn(s) {
    var el = document.getElementById('hb-conn');
    if (!el) return;
    el.textContent = s === 'online' ? '● live' : '○ offline';
    el.style.color = s === 'online' ? '#5eb85e' : '#c88';
  }

  function onEnvelope(env) {
    if (env.type === 'message.new') {
      // Our own replies are already on screen (appended when sent)
      if (state.current && state.current.id === env.conv_id && env.msg.sender !== 'operator') {
        appendMsg(env.msg);
        state.lastMsgId = Math.max(state.lastMsgId, env.msg.id);
      }
      refreshConvs();
    }
    if (env.type === 'conversation.created') { refreshConvs(); }
    if (env.type === 'conversation.closed' && state.current && state.current.id === env.conv_id) {
      showEmpty('This conversation was closed.');
    }
  }

  /* --------- Inbox rendering --------- */
  function refreshConvs() {
    api('GET', '/api/admin/conversations').then(function (r) {
      if (r.code !== 200) return;
      state.convs = r.body.conversations || [];
      renderList();
      // update tab badge count in bar
      var un = state.convs.reduce(function (n, c) { return n + (c.unread_admin > 0 ? 1 : 0); }, 0);
      document.title = (un > 0 ? '(' + un + ') ' : '') + 'Console — Holler Back';
    });
  }
  function renderList() {
    var el = document.getElementById('hb-list');
    if (!el) return;
    if (!state.convs.length) { el.innerHTML = '<div class="hb-empty">No conversations yet.</div>'; return; }
    el.innerHTML = '';
    state.convs.forEach(function (c) {
      var d = document.createElement('div');
      d.className = 'hb-conv' + (c.unread_admin > 0 ? ' unread' : '') + (state.current && state.current.id === c.id ? ' on' : '');
      var who = c.buyer_name || 'Visitor';
      var preview = c.last_body ? String(c.last_body).slice(0, 90) : '';
      d.innerHTML =
        '<div class="n"><span class="dot"></span>' + escape(who) + '</div>' +
        '<div class="p">' + escape(preview) + '</div>' +
        '<div class="t">' + relTime(c.last_activity_at) + '</div>';
      d.addEventListener('click', function () { openConversation(c); });
      el.appendChild(d);
    });
  }

  function openConversation(c) {
    state.current = c;
    state.lastMsgId = 0;
    renderList();
    renderPaneHeader(c);
    api('GET', '/api/admin/messages?conv_id=' + c.id).then(function (r) {
      if (r.code !== 200) return;
      var log = document.getElementById('hb-log');
      if (log) log.innerHTML = '';
      (r.body.messages || []).forEach(appendMsg);
    });
  }

  function renderPaneHeader(c) {
    var pane = document.getElementById('hb-pane');
    if (!pane) return;
    var who = escape(c.buyer_name || 'Visitor');
    var email = c.buyer_email ? ' &lt;' + escape(c.buyer_email) + '&gt;' : '';
    pane.innerHTML =
      '<div class="hb-phdr"><strong>' + who + '</strong>' + email +
        '<button type="button" id="hb-close">Close chat</button></div>' +
      '<div class="hb-plog" id="hb-log"></div>' +
      '<form class="hb-reply" id="hb-replyform">' +
        '<textarea id="hb-replybox" rows="1" placeholder="Reply…"></textarea>' +
        '<button type="submit">Send</button>' +
      '</form>';
    document.getElementById('hb-close').addEventListener('click', function () {
      if (!confirm('Close this conversation?')) return;
      api('POST', '/api/admin/close', { conv_id: c.id }).then(function (r) {
        if (r.code !== 200) { alert('Close failed (' + r.code + ')'); return; }
        refreshConvs(); showEmpty('Conversation closed.');
      });
    });
    document.getElementById('hb-replyform').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var box = document.getElementById('hb-replybox');
      var text = (box.value || '').trim();
      if (!text) return;
      box.value = '';
      appendMsg({ id: 0, sender: 'operator', body: text, sent_at: Math.floor(Date.now() / 1000) });
      api('POST', '/api/admin/reply', { conv_id: c.id, body: text }).then(function (r) {
        if (r.code === 200) { state.lastMsgId = Math.max(state.lastMsgId, r.body.id || 0); return; }
        appendMsg({ id: 0, sender: 'system', body: 'Not delivered (' + (r.body.error || r.code) + '): ' + text });
      }).catch(function () {
        appendMsg({ id: 0, sender: 'system', body: 'Not delivered (network): ' + text });
      });
    });
    document.getElementById('hb-replybox').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        document.getElementById('hb-replyform').requestSubmit();
      }
    });
  }
  function showEmpty(msg) {
    var pane = document.getElementById('hb-pane');
    if (pane) pane.innerHTML = '<div class="hb-empty">' + escape(msg) + '</div>';
    state.current = null;
    renderList();
  }
  function appendMsg(m) {
    var log = document.getElementById('hb-log');
    if (!log) return;
    var d = document.createElement('div');
    d.className = 'hb-m ' + (m.sender || 'system');
    d.textContent = m.body;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    if (m.id) state.lastMsgId = Math.max(state.lastMsgId, m.id);
  }

  /* --------- Settings save --------- */
  function bindSettings() {
    var f = document.getElementById('hb-wsform');
    if (!f) return;
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var body = {};
      Array.from(f.elements).forEach(function (el) {
        if (el.name) body[el.name] = el.value;
      });
      api('POST', '/api/admin/workspace/save', body).then(function (r) {
        var msg = document.getElementById('hb-savemsg');
        if (r.code === 200) msg.innerHTML = '<div class="hb-ok">Saved.</div>';
        else msg.innerHTML = '<div class="hb-err">Save failed.</div>';
      });
    });
  }

  /* --------- Push subscribe (VAPID) --------- */
  function urlB64ToUint8(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var s = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(s);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
    return out;
  }
  function bindPushBtn() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    var btn = document.getElementById('hb-pushbtn');
    if (!btn) return;
    btn.style.display = '';
    var on = false;
    function show(v) { on = v; btn.textContent = v ? 'Push on' : 'Enable push'; btn.title = v ? 'Click to turn push off on this device' : ''; }
    // Reflect the real state: an existing subscription means push is on. Re-send
    // it so a server that pruned it picks it up again.
    navigator.serviceWorker.getRegistration('/sw.js').then(function (reg) {
      if (!reg || Notification.permission !== 'granted') return null;
      return reg.pushManager.getSubscription();
    }).then(function (sub) {
      if (!sub) return;
      show(true);
      api('POST', '/api/admin/subscribe', JSON.parse(JSON.stringify(sub)));
    }).catch(function () {});
    btn.addEventListener('click', function () {
      if (!on) { subscribeToPush().then(show); return; }
      if (!confirm('Turn off push notifications on this device?')) return;
      navigator.serviceWorker.getRegistration('/sw.js').then(function (reg) {
        return reg && reg.pushManager.getSubscription();
      }).then(function (sub) {
        if (!sub) { show(false); return; }
        var ep = sub.endpoint;
        return sub.unsubscribe().then(function () {
          return api('POST', '/api/admin/unsubscribe', { endpoint: ep });
        }).then(function () { show(false); });
      });
    });
  }
  function subscribeToPush() {
    return navigator.serviceWorker.register('/sw.js').then(function (reg) {
      return api('GET', '/api/admin/vapid-public').then(function (r) {
        if (r.code !== 200 || !r.body.key) { alert('Push not configured on server.'); return false; }
        return reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlB64ToUint8(r.body.key),
        }).then(function (sub) {
          var body = JSON.parse(JSON.stringify(sub));
          return api('POST', '/api/admin/subscribe', body).then(function (rr) { return rr.code === 200; });
        });
      });
    }).catch(function (e) {
      console.error('push subscribe failed', e);
      alert('Push subscription failed: ' + e.message);
      return false;
    });
  }

  /* --------- utils --------- */
  /* --------- Notifications settings --------- */
  function bindNotify() {
    var f = document.getElementById('hb-notifyform');
    if (!f) return;
    var msg = document.getElementById('hb-notifymsg');
    function say(ok, text) { msg.innerHTML = '<div class="' + (ok ? 'hb-ok' : 'hb-err') + '">' + escape(text) + '</div>'; }
    var errors = {
      bad_discord_url: 'That is not a Discord webhook URL (https://discord.com/api/webhooks/…).',
      no_webhook: 'Save a webhook URL first.',
      no_devices: 'No devices yet: click Enable push on each device first.',
      rate_limited: 'Slow down: try again in a minute.',
    };
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var body = {};
      Array.from(f.elements).forEach(function (el) {
        if (!el.name) return;
        body[el.name] = el.type === 'checkbox' ? el.checked : el.value;
      });
      api('POST', '/api/admin/notify/save', body).then(function (r) {
        if (r.code !== 200) { say(false, errors[r.body.error] || 'Save failed.'); return; }
        // Reload so the masked webhook hint and Remove box reflect what was saved
        location.reload();
      });
    });
    document.getElementById('hb-discordtest').addEventListener('click', function () {
      api('POST', '/api/admin/notify/test', {}).then(function (r) {
        if (r.code === 200) { say(true, 'Sent: check your Discord channel.'); return; }
        say(false, errors[r.body.error] || ('Discord said: HTTP ' + (r.body.code || r.code) + ' ' + (r.body.error || '')));
      });
    });
    document.getElementById('hb-pushtest').addEventListener('click', function () {
      api('POST', '/api/admin/push-test', {}).then(function (r) {
        if (r.code === 200) { say(true, 'Queued for ' + r.body.devices + ' device(s): it should arrive within a few seconds.'); return; }
        say(false, errors[r.body.error] || 'Push test failed.');
      });
    });
  }

  function escape(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function relTime(ts) {
    var d = Math.floor(Date.now() / 1000) - Number(ts);
    if (d < 60) return d + 's ago';
    if (d < 3600) return Math.floor(d / 60) + 'm ago';
    if (d < 86400) return Math.floor(d / 3600) + 'h ago';
    return Math.floor(d / 86400) + 'd ago';
  }

  /* --------- boot --------- */
  if (HB.tab === 'inbox') {
    refreshConvs();
    openWS();
    // Deep-link: #conv=N opens that conversation once list is loaded
    var m = /conv=(\d+)/.exec(location.hash);
    if (m) {
      var want = parseInt(m[1], 10);
      var iv = setInterval(function () {
        var c = state.convs.find(function (x) { return x.id === want; });
        if (c) { openConversation(c); clearInterval(iv); }
      }, 200);
      setTimeout(function () { clearInterval(iv); }, 5000);
    }
  }
  else {
    // Only the inbox holds a live socket; elsewhere the indicator would sit on "connecting…"
    var conn = document.getElementById('hb-conn');
    if (conn) conn.style.display = 'none';
  }
  if (HB.tab === 'settings') { bindSettings(); bindNotify(); }
  bindPushBtn();
})();
