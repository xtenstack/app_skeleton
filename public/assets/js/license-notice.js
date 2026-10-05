// Opens the past-grace licence modal (backend/views/partials/license-modal.phtml)
// on page load. Its own file because the CSP is script-src 'self': an inline
// script would be blocked silently and the modal would never appear. Loaded
// after jQuery and Bootstrap, and only on pages that render the modal.
(function () {
  var modal = document.getElementById('license-modal');
  if (!modal || !window.jQuery || !window.jQuery.fn.modal) return;
  window.jQuery(modal).modal('show');
})();
