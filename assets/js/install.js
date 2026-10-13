// SPDX-License-Identifier: GPL-3.0-or-later
//
// The web installer (D43, templates/install.php). Two jobs:
//  - a form marked data-install-busy shows that it is working once sent
//    (Composer, the final checks: they take a while);
//  - the Secure step's probe: fetch, from this browser, what must NOT be
//    served (a canary in data/, conf/, composer.json, .git/) and what must
//    (the front controller, assets/), then post what came back. Done from
//    the browser rather than the server: it sees the site exactly as a
//    visitor does, and a server asking itself would hang the one-request
//    PHP development server.
(function () {
  'use strict';

  document.querySelectorAll('form[data-install-busy]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type=submit]');
      if (button) {
        button.disabled = true;
        button.textContent = form.getAttribute('data-install-busy');
      }
    });
  });

  var configNode = document.getElementById('install-probe-config');
  var form = document.getElementById('install-probes');
  if (!configNode || !form) {
    return;
  }
  var config = JSON.parse(configNode.textContent || '{}');
  var status = form.querySelector('[data-probe-status]');
  var button = form.querySelector('button[type=submit]');
  if (status) {
    status.textContent = '';
  }

  function probe(path) {
    // credentials: 'omit': a visitor's view, without the installer's cookie
    var url = config.base + '/' + path + (path.indexOf('?') < 0 ? '?probe=' : '&probe=') + Date.now();
    return fetch(url, {
      credentials: 'omit',
      cache: 'no-store',
      redirect: 'follow'
    }).then(function (response) {
      return response.text().then(function (body) {
        var result = { status: response.status, body: body.slice(0, 4000) };
        if (response.redirected) {
          result.redirected = response.url;
        }
        return result;
      });
    }).catch(function (error) {
      // the browser hides why a request failed (CORS, TLS, refused, blocked);
      // say what it does tell, and from which page it was made
      var message = error && error.message ? String(error.message) : String(error);
      return {
        status: 0,
        body: '',
        error: message.slice(0, 200) + ': page ' + location.protocol + '//' + location.host + ', request ' + url.split('?')[0]
      };
    });
  }

  function run() {
    if (button) {
      button.disabled = true;
    }
    if (status) {
      status.textContent = config.running;
    }
    var results = {};
    Promise.all(config.paths.map(function (path) {
      return probe(path).then(function (result) {
        results[path] = result;
      });
    })).then(function () {
      form.elements.results.value = JSON.stringify(results);
      form.submit();
    });
  }

  form.addEventListener('submit', function (event) {
    if (form.elements.results.value === '') {
      event.preventDefault();
      run();
    }
  });

  if (config.auto) {
    run();
  }
})();
