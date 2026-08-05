// Extracted from an inline <script> block in
// backend/views/tickets/index.phtml (Session 13) so script-src can drop
// 'unsafe-inline' from the CSP. See RB-03 (list-view convention) for the
// bulk-select-all / "N selected" pattern this implements.
(function () {
  var selectAll = document.getElementById('bulk-select-all');
  var count = document.getElementById('bulk-selected-count');

  function rowCheckboxes() {
    return Array.prototype.slice.call(document.querySelectorAll('.bulk-row-checkbox'));
  }

  function updateCount() {
    var checked = rowCheckboxes().filter(function (cb) { return cb.checked; }).length;
    count.textContent = checked + ' selected';
  }

  selectAll.addEventListener('change', function () {
    rowCheckboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
    updateCount();
  });

  rowCheckboxes().forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (!cb.checked) {
        selectAll.checked = false;
      }
      updateCount();
    });
  });
})();
