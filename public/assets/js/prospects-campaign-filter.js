// Campaign multi-select dropdown on marketing-module's Prospects list.
// External file, not an inline <script>: the site-wide CSP is
// script-src 'self' with no 'unsafe-inline', so inline JS is silently
// blocked (same class of bug as REQ-046 / agent-rooms-room.js). Found
// live 2026-09-16 when the dropdown "did nothing" -- its submit handler
// had never run.
//
// The form itself works with no JS at all: the checkboxes are named
// campaign[] and sit inside a <form> inside the .dropdown-menu, which
// Bootstrap 4 already keeps open on clicks. This file only adds the
// conveniences: type-to-filter the list, select all shown, clear.
(function () {
  var menu = document.querySelector('#campaign-filter .dropdown-menu');
  if (!menu) return;

  // Belt and braces for the keep-open behaviour (Bootstrap's own rule is
  // scoped to '.dropdown form', which this markup satisfies, but a
  // future markup change shouldn't silently break the picker).
  menu.addEventListener('click', function (e) { e.stopPropagation(); });

  var rows = function () { return Array.prototype.slice.call(menu.querySelectorAll('.campaign-filter-option')); };
  var search = document.getElementById('campaign-filter-search');
  var all = document.getElementById('campaign-filter-all');
  var none = document.getElementById('campaign-filter-none');

  if (search) {
    search.addEventListener('input', function () {
      var needle = (search.value || '').toLowerCase();
      rows().forEach(function (row) {
        row.hidden = !(needle === '' || (row.getAttribute('data-title') || '').indexOf(needle) !== -1);
      });
    });
    // Enter in the filter box must not submit the (still unfiltered) form.
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  }

  if (all) {
    all.addEventListener('click', function (e) {
      e.preventDefault();
      rows().forEach(function (row) {
        if (!row.hidden) { row.querySelector('input[type=checkbox]').checked = true; }
      });
    });
  }

  if (none) {
    none.addEventListener('click', function (e) {
      e.preventDefault();
      rows().forEach(function (row) { row.querySelector('input[type=checkbox]').checked = false; });
    });
  }
})();
