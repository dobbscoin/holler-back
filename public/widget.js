/**
 * Holler Back widget. Drop into any page with:
 *   <script src="https://chat.example.com/widget.js" data-holler-id="xy2p9m" async defer></script>
 *
 * Optional data-holler-mount="#selector" puts the chat button inside that
 * element (e.g. a site's top bar) instead of floating it bottom-right.
 *
 * No external deps. CSS is injected inline so nothing else needs to load.
 * WebSocket for realtime; short polling fallback if WS fails to open.
 *
 * The conversation token is stored in localStorage so a page reload keeps
 * the same chat. Purged server-side on retention_days boundary.
 */
(function () {
  'use strict';
  var script = document.currentScript ||
    (function () {
      var s = document.getElementsByTagName('script');
      for (var i = s.length - 1; i >= 0; i--) {
        if (s[i].src && s[i].src.indexOf('widget.js') !== -1) return s[i];
      }
      return null;
    })();
  if (!script) return;

  var workspaceId = script.getAttribute('data-holler-id');
  if (!workspaceId) { console.warn('holler-back: data-holler-id missing'); return; }

  // Derive server root from the script src
  var srcUrl = new URL(script.src, window.location.href);
  var base = srcUrl.origin;
  var wsBase = base.replace(/^http/, 'ws');

  var STORAGE_KEY = 'hollerback:conv:' + workspaceId;
  var IDENT_KEY   = 'hollerback:ident:' + workspaceId;

  /* ---------- styles ---------- */
  var css = [
    '.hb-btn{position:fixed;right:20px;bottom:20px;width:56px;height:56px;border-radius:50%;background:#2f6feb;color:#fff;border:none;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer;z-index:2147483000;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif}',
    '.hb-btn svg{width:26px;height:26px;fill:#fff}',
    '.hb-btn .hb-badge{position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;font-size:11px;font-weight:600;min-width:18px;height:18px;border-radius:9px;display:none;align-items:center;justify-content:center;padding:0 5px}',
    '.hb-btn .hb-badge.on{display:flex}',
    '.hb-pane{position:fixed;right:20px;bottom:88px;width:340px;max-width:calc(100vw - 40px);height:480px;max-height:calc(100vh - 120px);background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.24);display:none;flex-direction:column;overflow:hidden;z-index:2147483000;font-family:system-ui,sans-serif;color:#222}',
    '.hb-pane.on{display:flex}',
    '.hb-hd{background:#2f6feb;color:#fff;padding:12px 14px;font-weight:600;display:flex;align-items:center;justify-content:space-between}',
    '.hb-hd .hb-x{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;padding:0 4px}',
    '.hb-log{flex:1;overflow-y:auto;padding:12px;background:#f6f7fb;font-size:14px;line-height:1.4}',
    '.hb-m{margin:6px 0;padding:8px 10px;border-radius:10px;max-width:80%;word-wrap:break-word}',
    '.hb-m.buyer{background:#2f6feb;color:#fff;margin-left:auto;border-bottom-right-radius:2px}',
    '.hb-m.operator{background:#fff;color:#222;border:1px solid #e2e5ee;border-bottom-left-radius:2px}',
    '.hb-m a{color:inherit;text-decoration:underline;word-break:break-all}',
    '.hb-m.system{background:transparent;color:#666;font-style:italic;text-align:center;font-size:12px;max-width:100%}',
    '.hb-form{border-top:1px solid #e2e5ee;padding:10px;background:#fff;display:flex;gap:8px}',
    '.hb-form textarea{flex:1;resize:none;border:1px solid #d6d9e3;border-radius:8px;padding:8px;font-family:inherit;font-size:14px;min-height:40px;max-height:120px;box-sizing:border-box}',
    '.hb-form textarea:focus{outline:none;border-color:#2f6feb}',
    '.hb-form button{background:#2f6feb;color:#fff;border:none;border-radius:8px;padding:0 14px;font-weight:600;cursor:pointer}',
    '.hb-form button[disabled]{opacity:.5;cursor:default}',
    '.hb-ident{padding:14px;font-size:14px;color:#333;background:#fff;border-top:1px solid #e2e5ee}',
    '.hb-ident label{display:block;margin:6px 0 3px;font-size:12px;color:#666}',
    '.hb-ident input{width:100%;padding:7px 8px;border:1px solid #d6d9e3;border-radius:6px;font-size:14px;box-sizing:border-box}',
    '.hb-ident button{margin-top:10px;width:100%;background:#2f6feb;color:#fff;border:none;border-radius:8px;padding:9px;font-weight:600;cursor:pointer}',
    '.hb-off{padding:8px 12px;font-size:12px;color:#a00;background:#fff3f2;border-top:1px solid #ffd8d5;display:none}',
    '.hb-off.on{display:block}',
    '@media (max-width:480px){.hb-pane{right:10px;bottom:78px;width:calc(100vw - 20px)}.hb-btn{right:14px;bottom:14px}}',
    // Mounted mode: the button sits inside a host element (a top bar, say)
    // and takes the host's color; the pane drops down from under it.
    '.hb-btn.hb-inline{position:relative;right:auto;bottom:auto;width:auto;height:auto;padding:4px 6px;background:none;box-shadow:none;color:inherit;z-index:auto}',
    '.hb-btn.hb-inline svg{width:18px;height:18px;fill:currentColor}',
    '.hb-pane.hb-top{bottom:auto;right:10px}'
  ].join('\n');
  var styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  /* ---------- DOM ---------- */
  var btn = document.createElement('button');
  btn.className = 'hb-btn';
  btn.setAttribute('aria-label', 'Open chat');
  btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg><span class="hb-badge" data-count>0</span>';
  var badge = btn.querySelector('.hb-badge');

  var pane = document.createElement('div');
  pane.className = 'hb-pane';
  pane.innerHTML =
    '<div class="hb-hd"><span data-title>Chat</span><button class="hb-x" aria-label="Close">&times;</button></div>' +
    '<div class="hb-log" data-log></div>' +
    '<div class="hb-off" data-off>Reconnecting…</div>' +
    '<div class="hb-ident" data-ident style="display:none">' +
      '<label>Your name</label><input data-name maxlength="80">' +
      '<label>Email (optional)</label><input data-email type="email" maxlength="120">' +
      '<button data-startbtn>Start chat</button>' +
    '</div>' +
    '<form class="hb-form" data-form>' +
      '<textarea placeholder="Type a message…" rows="1" data-input></textarea>' +
      '<button type="submit">Send</button>' +
    '</form>';

  // data-holler-mount="#selector" puts the button inside that element
  // instead of floating it bottom-right.
  var mountSel = script.getAttribute('data-holler-mount');
  var mount = null;
  if (mountSel) { try { mount = document.querySelector(mountSel); } catch (e) {} }
  if (mountSel && !mount) console.warn('holler-back: mount ' + mountSel + ' not found, floating instead');
  if (mount) {
    btn.className += ' hb-inline';
    pane.className += ' hb-top';
    mount.appendChild(btn);
  } else {
    document.body.appendChild(btn);
  }
  document.body.appendChild(pane);

  var els = {
    log:    pane.querySelector('[data-log]'),
    off:    pane.querySelector('[data-off]'),
    title:  pane.querySelector('[data-title]'),
    ident:  pane.querySelector('[data-ident]'),
    name:   pane.querySelector('[data-name]'),
    email:  pane.querySelector('[data-email]'),
    startbtn: pane.querySelector('[data-startbtn]'),
    form:   pane.querySelector('[data-form]'),
    input:  pane.querySelector('[data-input]'),
    close:  pane.querySelector('.hb-x'),
  };

  /* ---------- state ---------- */
  var state = {
    convToken: null,
    convId: null,
    ws: null,
    unread: 0,
    lastMsgId: 0,
    seen: {},        // message ids already on screen (own sends + server pushes)
    started: false,  // conversation set up once per page load
    pollTimer: null,
    ident: (function () { try { return JSON.parse(localStorage.getItem(IDENT_KEY) || 'null'); } catch (e) { return null; } })(),
  };
  try { state.convToken = localStorage.getItem(STORAGE_KEY); } catch (e) {}

  /* ---------- helpers ---------- */
  function setBadge(n) {
    state.unread = n;
    badge.textContent = String(n);
    badge.classList.toggle('on', n > 0);
  }
  function setOffline(off) { els.off.classList.toggle('on', !!off); }
  function paneOpen() { return pane.classList.contains('on'); }
  function api(path, body) {
    var opts = { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'omit' };
    var payload = Object.assign({ workspace: workspaceId }, body || {});
    opts.body = JSON.stringify(payload);
    return fetch(base + path, opts).then(function (r) {
      return r.json().then(function (j) { return { code: r.status, body: j }; });
    });
  }
  // Operator and system text gets clickable https links; built with DOM nodes, never innerHTML.
  function linkify(el, text, raw) {
    if (!text) { el.textContent = raw; return; }
    text.split(/(https?:\/\/[^\s<>"]+[^\s<>".,;:!?)])/).forEach(function (part, i) {
      if (i % 2) {
        var a = document.createElement('a');
        a.href = part; a.textContent = part; a.target = '_blank'; a.rel = 'noopener noreferrer';
        el.appendChild(a);
      } else if (part) {
        el.appendChild(document.createTextNode(part));
      }
    });
  }
  function appendMsg(m) {
    if (m.id && state.seen[m.id]) return;
    if (m.id) state.seen[m.id] = true;
    var d = document.createElement('div');
    d.className = 'hb-m ' + (m.sender || 'system');
    linkify(d, m.sender === 'buyer' ? '' : String(m.body || ''), m.body);
    els.log.appendChild(d);
    els.log.scrollTop = els.log.scrollHeight;
    if (m.id && m.sender !== 'buyer') state.lastMsgId = Math.max(state.lastMsgId, m.id);
    if (!paneOpen() && m.sender === 'operator') setBadge(state.unread + 1);
  }

  /* ---------- open / close ---------- */
  function closePane() {
    pane.classList.remove('on');
    btn.style.display = '';
  }
  btn.addEventListener('click', function () {
    if (mount) {
      // A mounted button stays put and toggles.
      if (paneOpen()) { closePane(); return; }
      var top = Math.round(btn.getBoundingClientRect().bottom) + 8;
      pane.style.top = top + 'px';
      pane.style.maxHeight = 'calc(100vh - ' + (top + 10) + 'px)';
    } else {
      btn.style.display = 'none';
    }
    pane.classList.add('on');
    setBadge(0);
    if (!state.started) { state.started = true; ensureConversation(); }
    setTimeout(function () { els.input.focus(); }, 50);
  });
  els.close.addEventListener('click', closePane);

  /* ---------- conversation lifecycle ---------- */
  function ensureConversation() {
    if (state.convToken) { hydrate(); openWS(); return; }
    api('/api/conversation/create', {}).catch(function () { return { code: 0, body: {} }; }).then(function (r) {
      if (r.code !== 200) {
        appendMsg({ sender: 'system', body: r.code === 429 ? 'Too many chats started — try again later.' : 'Could not start chat.' });
        state.started = false;
        return;
      }
      state.convToken = r.body.token;
      state.convId    = r.body.conv_id;
      try { localStorage.setItem(STORAGE_KEY, r.body.token); } catch (e) {}
      els.title.textContent = r.body.operator_name || 'Chat';
      if (r.body.greeting) appendMsg({ id: r.body.greeting_id || 0, sender: 'system', body: r.body.greeting });
      maybeAskIdentity();
      openWS();
    });
  }

  function hydrate() {
    api('/api/conversation/history', { token: state.convToken, since: 0 }).then(function (r) {
      if (r.code !== 200) {
        // stale token — start fresh
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        state.convToken = null;
        ensureConversation();
        return;
      }
      (r.body.messages || []).forEach(appendMsg);
      maybeAskIdentity();
    });
  }

  function maybeAskIdentity() {
    if (state.ident) return;
    els.ident.style.display = '';
    els.form.style.display = 'none';
    els.startbtn.addEventListener('click', function () {
      var n = (els.name.value || '').trim();
      var e = (els.email.value || '').trim();
      if (!n) { els.name.focus(); return; }
      state.ident = { name: n, email: e };
      try { localStorage.setItem(IDENT_KEY, JSON.stringify(state.ident)); } catch (er) {}
      api('/api/conversation/identify', { token: state.convToken, name: n, email: e }).then(function () {
        els.ident.style.display = 'none';
        els.form.style.display = '';
        els.input.focus();
      });
    }, { once: true });
  }

  /* ---------- WebSocket ---------- */
  var reconnectDelay = 1000;
  function openWS() {
    if (!state.convToken) return;
    if (state.ws) {
      // detach first so the old socket's onclose doesn't schedule another reconnect
      state.ws.onclose = null; state.ws.onerror = null;
      try { state.ws.close(); } catch (e) {}
      state.ws = null;
    }
    var url = wsBase + '/ws/widget/' + state.convToken + '?workspace=' + encodeURIComponent(workspaceId);
    var ws;
    try { ws = new WebSocket(url); } catch (e) { startPolling(); return; }
    state.ws = ws;
    ws.onopen = function () {
      setOffline(false);
      reconnectDelay = 1000;
      stopPolling();
    };
    ws.onmessage = function (ev) {
      var env; try { env = JSON.parse(ev.data); } catch (e) { return; }
      if (env.type === 'message.new') appendMsg(env.msg);
      if (env.type === 'conversation.closed') appendMsg({ sender: 'system', body: 'The operator closed this chat.' });
    };
    ws.onclose = function () {
      state.ws = null;
      setOffline(true);
      // Fall back to polling immediately, then try to reconnect WS with backoff
      startPolling();
      setTimeout(openWS, reconnectDelay);
      reconnectDelay = Math.min(reconnectDelay * 2, 30000);
    };
    ws.onerror = function () { try { ws.close(); } catch (e) {} };
  }

  /* ---------- polling fallback ---------- */
  function startPolling() {
    if (state.pollTimer) return;
    state.pollTimer = setInterval(function () {
      api('/api/conversation/history', { token: state.convToken, since: state.lastMsgId }).then(function (r) {
        if (r.code === 200) { (r.body.messages || []).forEach(appendMsg); }
      });
    }, 3000);
  }
  function stopPolling() { if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; } }

  /* ---------- send ---------- */
  els.form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var text = (els.input.value || '').trim();
    if (!text) return;
    if (!state.convToken) { ensureConversation(); return; }
    els.input.value = '';
    // Optimistic append — the server won't echo our own message back
    appendMsg({ id: 0, sender: 'buyer', body: text, sent_at: Math.floor(Date.now() / 1000) });
    api('/api/message/send', { token: state.convToken, body: text }).then(function (r) {
      if (r.code === 200) { if (r.body.id) state.seen[r.body.id] = true; }
      else if (r.code === 429) appendMsg({ sender: 'system', body: 'Too many messages — please slow down.' });
      else appendMsg({ sender: 'system', body: 'Message failed to send.' });
    }).catch(function () { appendMsg({ sender: 'system', body: 'Message failed to send.' }); });
  });
  els.input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); els.form.requestSubmit(); }
  });
})();
