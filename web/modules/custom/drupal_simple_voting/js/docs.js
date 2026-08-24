((Drupal, once) => {
  class ApiExplorer {
    constructor(element, settings) {
      this.element = element;
      this.specUrl = settings.spec;
      this.tokenUrl = settings.csrf;
      this.token = null;
    }

    async start() {
      await this.readToken();
      window.SwaggerUIBundle({
        url: this.specUrl,
        domNode: this.element,
        deepLinking: true,
        withCredentials: true,
        persistAuthorization: true,
        defaultModelsExpandDepth: -1,
        requestInterceptor: (request) => this.sign(request),
      });
    }

    async readToken() {
      try {
        const response = await fetch(this.tokenUrl, { credentials: 'same-origin' });
        this.token = response.ok ? await response.text() : null;
      }
      catch {
        this.token = null;
      }
    }

    sign(request) {
      const writes = ['POST', 'PATCH', 'DELETE', 'PUT'];
      if (this.token && writes.includes((request.method || '').toUpperCase())) {
        request.headers['X-CSRF-Token'] = this.token;
      }
      request.credentials = 'same-origin';
      return request;
    }
  }

  Drupal.behaviors.votingApiDocs = {
    attach(context, settings) {
      once('voting-api-docs', '#voting-api-docs', context).forEach((element) => {
        new ApiExplorer(element, settings.votingApi).start();
      });
    },
  };
})(Drupal, once);
