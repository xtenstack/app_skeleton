// Shared by backend/views/index.phtml, frontend/views/index.phtml, and
// requirements-module/views/index.phtml (REQ-046) — extracted from an
// inline <script> block (Session 13) so script-src can drop
// 'unsafe-inline' from the CSP without breaking any of them.
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

// ListView::pagination()'s "…" jump-to-page control. A large dataset's
// pagination shows only the 3 smallest and 3 largest page numbers (see
// that method) rather than one button per page, so the "…" between them
// is the only way to reach a page in the middle — this prompts for a
// number and navigates there. Delegated on document, not per-element
// listeners, so it works for every list view without per-page wiring.
document.addEventListener('click', function (event) {
  var el = event.target.closest('.page-jump');
  if (!el) return;
  event.preventDefault();

  var totalPages = parseInt(el.getAttribute('data-total-pages'), 10);
  var answer = window.prompt('Go to page (1-' + totalPages + '):');
  if (answer === null) return;

  var target = parseInt(answer, 10);
  if (isNaN(target) || target < 1 || target > totalPages) return;

  var preserve = {};
  try {
    preserve = JSON.parse(el.getAttribute('data-preserve') || '{}');
  } catch (e) {
    preserve = {};
  }
  preserve.page = target;

  var qs = Object.keys(preserve).map(function (key) {
    return encodeURIComponent(key) + '=' + encodeURIComponent(preserve[key]);
  }).join('&');

  window.location.href = el.getAttribute('data-action') + '?' + qs;
});
