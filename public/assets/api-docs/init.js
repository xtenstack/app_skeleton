// Swagger UI for the Stack API. The bundle is served locally because the CSP is script-src 'self'.
window.addEventListener('load', function () {
  window.ui = SwaggerUIBundle({
    url: document.getElementById('swagger-ui').dataset.spec,
    dom_id: '#swagger-ui',
    deepLinking: true,
    docExpansion: 'none',
    defaultModelsExpandDepth: 0,
    tryItOutEnabled: false,
    persistAuthorization: false,
    requestInterceptor: function (req) { req.credentials = 'same-origin'; return req; }
  });
});
