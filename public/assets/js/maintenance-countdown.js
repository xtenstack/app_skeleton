// Ticket#22: this used to be an inline <script> block in
// maintenance.phtml, blocked outright by CSP's script-src 'self' (no
// 'unsafe-inline') — the browser silently refused to run it, so the
// countdown never ticked past its static "calculating…" placeholder
// regardless of what date format was entered. Extracted here, same
// pattern as every other AdminLTE inline script (Session 13).
(function () {
  var el = document.getElementById('maintenance-countdown');
  if (!el) {
    return;
  }

  var target = new Date(el.getAttribute('data-until')).getTime();

  function tick() {
    if (isNaN(target)) {
      el.textContent = 'soon';
      return;
    }

    var diff = target - Date.now();

    if (diff <= 0) {
      el.textContent = 'any moment now';
      return;
    }

    var minutes = Math.floor(diff / 60000);
    var days    = Math.floor(minutes / 1440);
    var hours   = Math.floor((minutes % 1440) / 60);
    var mins    = minutes % 60;

    el.textContent = days + 'd ' + hours + 'h ' + mins + 'm';
  }

  tick();
  setInterval(tick, 30000);
})();
