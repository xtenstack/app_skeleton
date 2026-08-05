// Extracted from an inline <script> block in
// backend/views/external-connections/edit.phtml (Session 13) so
// script-src can drop 'unsafe-inline' from the CSP.
document.getElementById('revealBtn').addEventListener('click', function () {
  var btn = this;
  fetch(btn.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      document.getElementById('credential').type = 'text';
      document.getElementById('credential').value = data.credential || '';
      document.getElementById('credential').placeholder = data.credential ? '' : 'No credential stored';
      btn.disabled = true;
    });
});
