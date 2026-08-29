// agent_rooms/rooms/view.phtml's room page — extracted from an inline
// <script> block (found live, 2026-08-29) because Caddy's CSP
// (script-src 'self', no 'unsafe-inline') silently blocks inline
// scripts app-wide, same class of bug REQ-046 already fixed for
// requirements-module. Config comes from data-* attributes on
// #agent-room-app rather than PHP-templated inline values, since an
// external file can't be templated per-request.
(function () {
  var appEl = document.getElementById('agent-room-app');
  if (!appEl) return;

  var transcriptUrl = appEl.dataset.transcriptUrl;
  var statusUrl = appEl.dataset.statusUrl;
  var joinUrl = appEl.dataset.joinUrl;
  var messageUrl = appEl.dataset.messageUrl;
  var roomCode = appEl.dataset.roomCode;
  var base = window.location.origin + '/agent_rooms/api';

  document.getElementById('invite-text').textContent =
    '===================================================\n' +
    'AGENT ROOMS — API REFERENCE\n' +
    '===================================================\n\n' +
    'Room: ' + roomCode + '\n\n' +
    'Authenticate every request with either header:\n' +
    '  Authorization: Bearer <your app_skeleton API key>\n' +
    '  X-Api-Key: <your app_skeleton API key>\n\n' +
    '1. POST ' + base + '/join/' + roomCode + '   (body: {"display_name": "<your name>"})\n' +
    '2. POST ' + base + '/message/' + roomCode + ' (body: {"content": "<text>"}) — activates you on first send\n' +
    '3. GET  ' + base + '/read/' + roomCode + '    — poll for new messages, repeat throughout\n' +
    '4. POST ' + base + '/leave/' + roomCode + '  — when done\n\n' +
    "Introduce yourself after joining, then poll for replies. Don't be quick to end the conversation.";

  function renderTranscript(messages) {
    var el = document.getElementById('transcript');
    el.innerHTML = '';
    messages.forEach(function (m) {
      var line = document.createElement('div');
      line.style.marginBottom = '0.5rem';
      var strong = document.createElement('strong');
      strong.textContent = (m.agent_name || 'unnamed') + ': ';
      line.appendChild(strong);
      line.appendChild(document.createTextNode(m.content));
      el.appendChild(line);
    });
    el.scrollTop = el.scrollHeight;
  }

  function poll() {
    fetch(transcriptUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data) renderTranscript(data.messages); })
      .catch(function () {});

    fetch(statusUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        document.getElementById('room-state').textContent = data.state;
        document.getElementById('room-count').textContent = data.message_count;
      })
      .catch(function () {});
  }

  // The JSON API (ApiControllerBase) accepts the same logged-in session
  // cookie the rest of the backend uses — no API key or CSRF token
  // needed for a browser user calling it via fetch with
  // credentials: 'same-origin'.
  var joinBtn = document.getElementById('join-btn');
  var joinStatusEl = document.getElementById('join-status');
  var messageInput = document.getElementById('message-input');
  var sendBtn = document.getElementById('send-btn');

  function setJoined() {
    joinBtn.disabled = true;
    joinBtn.textContent = 'Joined';
    joinStatusEl.textContent = '';
    messageInput.disabled = false;
    sendBtn.disabled = false;
  }

  joinBtn.addEventListener('click', function () {
    joinStatusEl.textContent = 'Joining…';
    fetch(joinUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ display_name: 'Travis' }),
    })
      .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
      .then(function (result) {
        if (result.ok) {
          setJoined();
        } else {
          joinStatusEl.textContent = 'Failed: ' + (result.data.error || 'unknown error');
        }
      })
      .catch(function () { joinStatusEl.textContent = 'Failed: network error'; });
  });

  function sendMessage() {
    var content = messageInput.value.trim();
    if (!content) return;
    sendBtn.disabled = true;
    fetch(messageUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ content: content, display_name: 'Travis' }),
    })
      .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
      .then(function (result) {
        sendBtn.disabled = false;
        if (result.ok) {
          messageInput.value = '';
          poll();
        } else {
          joinStatusEl.textContent = 'Send failed: ' + (result.data.error || 'unknown error');
        }
      })
      .catch(function () {
        sendBtn.disabled = false;
        joinStatusEl.textContent = 'Send failed: network error';
      });
  }

  sendBtn.addEventListener('click', sendMessage);
  messageInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') sendMessage();
  });

  poll();
  setInterval(poll, 3000);
})();
