// Shared by backend/views/index.phtml and frontend/views/index.phtml —
// extracted from an inline <script> block (Session 13) so script-src can
// drop 'unsafe-inline' from the CSP without breaking either layout.
//
// Cache-Control: no-store (see each module's ControllerBase) stops most
// browsers from bfcache-restoring a page at all, but Safari has
// historically been inconsistent about honoring that for every
// back/forward navigation path — belt and suspenders: force a real
// reload if a page ever does come back from bfcache, so a stale
// embedded CSRF token can never be resubmitted.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) {
    window.location.reload();
  }
});

// Auto-injects the session's CSRF field into every <form method="post">
// on the page that doesn't already carry one — see each layout's
// <meta name="csrf-key">/<meta name="csrf-token">.
(function () {
  var keyMeta = document.querySelector('meta[name="csrf-key"]');
  var tokenMeta = document.querySelector('meta[name="csrf-token"]');
  if (!keyMeta || !tokenMeta) return;
  document.querySelectorAll('form').forEach(function (form) {
    if ((form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="' + keyMeta.content + '"]')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = keyMeta.content;
    input.value = tokenMeta.content;
    form.appendChild(input);
  });
})();
