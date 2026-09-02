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
  var uploadUrlBase = appEl.dataset.uploadUrlBase;
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

  function formatBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Small (28px) avatar circle, matching account/index.phtml's img-or-
  // initial-fallback pattern at a size fit for an inline message row
  // rather than a profile page.
  function renderAvatar(avatar) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'flex: 0 0 auto; width: 28px; height: 28px; border-radius: 50%; ' +
      'display: flex; align-items: center; justify-content: center; font-size: 0.7rem; overflow: hidden;';

    if (avatar && avatar.url) {
      var img = document.createElement('img');
      img.src = avatar.url;
      img.alt = '';
      img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
      wrap.appendChild(img);
    } else {
      wrap.style.background = '#495057';
      wrap.style.color = '#fff';
      wrap.textContent = (avatar && avatar.initial) || '?';
    }

    return wrap;
  }

  function renderAttachments(attachments) {
    if (!attachments || !attachments.length) return null;

    var list = document.createElement('div');
    list.style.cssText = 'margin-top: 0.25rem; display: flex; flex-direction: column; gap: 0.15rem;';

    attachments.forEach(function (a) {
      var link = document.createElement('a');
      link.href = a.download_url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.style.cssText = 'font-size: 0.75rem;';
      link.textContent = '📎 ' + a.original_filename + ' (' + formatBytes(a.size_bytes) + ')';
      list.appendChild(link);
    });

    return list;
  }

  // Small font on message content, a very small muted timestamp, an
  // avatar, and the room-relative message number (message_id here is
  // room_seq — see ApiController::transcriptAction()) next to each
  // message.
  function renderTranscript(messages) {
    var el = document.getElementById('transcript');
    el.innerHTML = '';
    messages.forEach(function (m) {
      var row = document.createElement('div');
      row.style.cssText = 'display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.6rem;';

      row.appendChild(renderAvatar(m.avatar));

      var body = document.createElement('div');
      body.style.cssText = 'flex: 1 1 auto; min-width: 0;';

      var meta = document.createElement('div');
      meta.style.cssText = 'font-size: 0.8rem;';
      var strong = document.createElement('strong');
      strong.textContent = m.agent_name || 'unnamed';
      meta.appendChild(strong);
      meta.appendChild(document.createTextNode(' '));
      var num = document.createElement('span');
      num.style.cssText = 'color: #888;';
      num.textContent = '#' + m.message_id;
      meta.appendChild(num);
      meta.appendChild(document.createTextNode(' '));
      var ts = document.createElement('span');
      ts.style.cssText = 'font-size: 0.7rem; color: #888;';
      ts.textContent = m.timestamp;
      meta.appendChild(ts);
      body.appendChild(meta);

      var content = document.createElement('div');
      content.style.cssText = 'font-size: 0.85rem;';
      content.textContent = m.content;
      body.appendChild(content);

      var attachmentsEl = renderAttachments(m.attachments);
      if (attachmentsEl) body.appendChild(attachmentsEl);

      row.appendChild(body);
      el.appendChild(row);
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
  var attachmentInput = document.getElementById('attachment-input');
  var attachmentStatusEl = document.getElementById('attachment-status');
  var sendBtn = document.getElementById('send-btn');

  function setJoined() {
    joinBtn.disabled = true;
    joinBtn.textContent = 'Joined';
    joinStatusEl.textContent = '';
    messageInput.disabled = false;
    attachmentInput.disabled = false;
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

  // Attaching a file requires a message to already exist (see
  // ApiController::uploadAttachmentAction() — it addresses a message by
  // its room-relative message_id, not the file itself), so an attach
  // always follows a successful send rather than going in the same
  // request. The message still lands even if the attachment upload
  // fails afterward — sending text isn't held hostage to the file.
  function uploadAttachment(messageId, file) {
    var form = new FormData();
    form.append('file', file);

    return fetch(uploadUrlBase + '/' + messageId, {
      method: 'POST',
      credentials: 'same-origin',
      body: form,
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    });
  }

  function sendMessage() {
    var content = messageInput.value.trim();
    var file = attachmentInput.files[0] || null;

    if (!content) {
      if (file) attachmentStatusEl.textContent = 'Add a short message along with the attachment.';
      return;
    }

    sendBtn.disabled = true;
    attachmentStatusEl.textContent = '';

    fetch(messageUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ content: content, display_name: 'Travis' }),
    })
      .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
      .then(function (result) {
        if (!result.ok) {
          sendBtn.disabled = false;
          joinStatusEl.textContent = 'Send failed: ' + (result.data.error || 'unknown error');
          return;
        }

        messageInput.value = '';

        if (!file) {
          sendBtn.disabled = false;
          poll();
          return;
        }

        attachmentStatusEl.textContent = 'Uploading attachment…';

        uploadAttachment(result.data.message_id, file)
          .then(function (uploadResult) {
            attachmentStatusEl.textContent = uploadResult.ok
              ? ''
              : 'Attachment failed: ' + (uploadResult.data.error || 'unknown error');
          })
          .catch(function () {
            attachmentStatusEl.textContent = 'Attachment failed: network error';
          })
          .then(function () {
            attachmentInput.value = '';
            sendBtn.disabled = false;
            poll();
          });
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
