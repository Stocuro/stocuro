<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: app.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — Stocuro</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">
</head>
<body>
  <div class="login-wrap">
    <div style="text-align:right;"><button type="button" id="langToggle" class="lang-toggle">NL</button></div>
    <h1>Stocuro</h1>
    <div id="loginError" class="login-error" style="display:none;"></div>
    <form id="loginForm">
      <div class="form-group">
        <label id="lblEmail">Email</label>
        <input type="email" id="email" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label id="lblPassword">Password</label>
        <input type="password" id="wachtwoord" autocomplete="current-password" required>
      </div>
      <button type="submit" id="btnSubmit" class="btn-save" style="width:100%;">Sign in</button>
    </form>
  </div>
  <script>
    // Kleine, op zichzelf staande vertaling voor dit losstaande inlogscherm
    // (draait vóór de SPA/app.js geladen wordt, dus geen gedeelde t()-functie
    // hier -- zie assets/js/app.js voor de vertaalinfrastructuur van
    // de rest van de applicatie).
    var LOGIN_STRINGS = {
      en: { email: 'Email', password: 'Password', submit: 'Sign in', failed: 'Sign in failed', noConnection: 'Could not connect to the server' },
      nl: { email: 'E-mail', password: 'Wachtwoord', submit: 'Inloggen', failed: 'Inloggen mislukt', noConnection: 'Kon geen verbinding maken met de server' },
    };
    var lang = localStorage.getItem('stocuro_lang') || 'en';

    function applyLoginLang() {
      var s = LOGIN_STRINGS[lang];
      document.getElementById('lblEmail').textContent = s.email;
      document.getElementById('lblPassword').textContent = s.password;
      document.getElementById('btnSubmit').textContent = s.submit;
      document.getElementById('langToggle').textContent = lang === 'en' ? 'NL' : 'EN';
      document.documentElement.lang = lang;
    }
    applyLoginLang();

    document.getElementById('langToggle').addEventListener('click', function () {
      lang = lang === 'en' ? 'nl' : 'en';
      localStorage.setItem('stocuro_lang', lang);
      applyLoginLang();
    });

    document.getElementById('loginForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var errorEl = document.getElementById('loginError');
      errorEl.style.display = 'none';

      fetch('api/login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: document.getElementById('email').value,
          wachtwoord: document.getElementById('wachtwoord').value,
        }),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success) {
            window.location.href = 'app.php';
          } else {
            errorEl.textContent = data.error || LOGIN_STRINGS[lang].failed;
            errorEl.style.display = 'block';
          }
        })
        .catch(function () {
          errorEl.textContent = LOGIN_STRINGS[lang].noConnection;
          errorEl.style.display = 'block';
        });
    });
  </script>
</body>
</html>
