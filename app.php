<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

/**
 * Gast-modus : een bezoeker die niet is ingelogd
 * krijgt hier geen redirect meer naar login.php, maar een synthetische
 * gast-gebruiker (currentUserOfGast(), includes/auth.php) -- alle
 * dashboards/formulieren/beheerschermen blijven bekijkbaar zonder
 * account, voor demonstratiedoeleinden. Elk daadwerkelijk schrijf-
 * endpoint blokkeert die gast-rol zelf (apiRequireWriteAccess(), zie
 * includes/api_helpers.php) -- dit bestand regelt alleen de weergave
 * (banner + "Log in" i.p.v. "Sign out"), geen beveiliging op zich.
 */
$user = currentUserOfGast();
$isGast = $user['rol'] === ROL_GAST;
// Welke schermen deze gebruiker mag zien, één keer per
// paginalaad berekend en meegestuurd in CURRENT_USER hieronder -- de
// frontend (showHome() in assets/js/app.js) filtert het menu hierop i.p.v.
// op losse rol-checks. Live berekend (niet in de sessie gecachet), zodat
// een rechten-wijziging door een beheerder meteen ingaat bij de
// eerstvolgende paginalaad, zonder opnieuw te hoeven inloggen.
$user['schermen'] = gebruikerSchermen($user['id'], (bool) $user['is_beheerder'], $isGast);
// Welk scherm deze
// gebruiker direct na inloggen moet zien i.p.v. altijd de volledige
// menupagina -- alleen gezet als al zijn/haar groepen het daar
// ondubbelzinnig over eens zijn (zie gebruikerLandingScherm()). null
// betekent: gewoon Home, zoals voorheen. Alleen toegepast door
// bootstrapRoute() in assets/js/app.js wanneer er geen hash in de URL
// staat -- een bladwijzer/herlaad naar een specifiek scherm blijft altijd
// leidend.
$user['landingScherm'] = gebruikerLandingScherm($user['id'], (bool) $user['is_beheerder'], $isGast);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stocuro</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">
</head>
<body>
  <div class="header">
    <div class="header-bar">
      <h1><a href="https://stocuro.com/" target="_blank" rel="noopener" class="header-logo-link">📊 Stocuro</a></h1>
      <!-- ("release nummer terug zien in
           de bovenbalk"): APP_VERSION (config/constants.php) is al het
           bestaande, centrale versielabel -- hier puur zichtbaar gemaakt
           naast het logo, geen nieuwe bron van waarheid. -->
      <span class="header-version">v<?= htmlspecialchars(APP_VERSION) ?></span>
      <span>
        <select id="langSelect" class="lang-select" title="Language / Taal">
          <option value="en">EN</option>
          <option value="nl">NL</option>
        </select>
        <?php if ($isGast): ?>
          <span class="header-account">
            <span class="logout-link" id="logoutLink" data-gast="1"><span data-i18n="loginLink">Log in</span></span>
          </span>
        <?php else: ?>
          <!-- "Profielpagina": de naam is nu een apart klikbaar element
               (-> showProfiel(), assets/js/app.js) i.p.v. onderdeel van de
               ene logout-span hieronder -- klikken op de naam zelf logt dus
               niet meer uit, alleen "Sign out" doet dat nog. Beide spans
               zitten samen in .header-account, dat de absolute positionering
               overneemt die voorheen rechtstreeks op .logout-link stond (zie
               style.css) -- anders zou de naam los van "Sign out" in de
               normale flex-flow naast de taalkeuze belanden i.p.v. samen
               rechts uitgelijnd te blijven. -->
          <span class="header-account">
            <span class="profile-link" id="profileLink"><?= htmlspecialchars($user['naam']) ?></span> ·
            <span class="logout-link" id="logoutLink"><span data-i18n="logout">Sign out</span></span>
          </span>
        <?php endif; ?>
      </span>
    </div>
  </div>
  <?php if ($isGast): ?>
    <div class="gast-banner" id="gastBanner">
      <span data-i18n="gastBannerText">🔍 You're viewing a read-only demo — nothing you enter or change here is saved.</span>
      <a href="login.php" data-i18n="gastBannerLink">Log in with a test account to try editing →</a>
    </div>
  <?php endif; ?>
  <!--
    "Mobiel/Tablet
    is niet van toepassing, alles gebeurt vanaf een computer" -- daarom
    een vaste desktop-zijbalk i.p.v. bv. een mobiele onderbalk. #content
    blijft het enige element dat alle render*()-functies in assets/js/
    app.js aanpassen; de zijbalk (renderSidebar(), zelfde bestand) is er
    puur naast gezet en weet niets van wat er in #content gebeurt.
  -->
  <div class="app-shell">
    <nav class="app-sidebar" id="appSidebar">
      <div class="sidebar-search"><input type="text" id="sidebarSearchInput"></div>
      <div id="sidebarNav"></div>
    </nav>
    <div class="app-main"><div class="container"><div id="content"></div></div></div>
  </div>

  <div id="bestelModal" class="modal">
    <div class="modal-content">
      <span class="modal-close" id="modalCloseBtn">&times;</span>
      <h2 data-i18n="modalOrderTitle">📦 Create order</h2>
      <div class="form-group">
        <label data-i18n="product">Product:</label>
        <input type="text" id="modalProduct" readonly style="background: #f0f0f0;">
      </div>
      <div class="form-group">
        <label>EAN:</label>
        <input type="text" id="modalEan" readonly style="background: #f0f0f0;">
      </div>
      <div class="form-group">
        <label data-i18n="quantity">Quantity:</label>
        <input type="number" id="modalAantal" min="1" required>
      </div>
      <p class="form-hint" id="modalBestellerHint"></p>
      <div class="form-group">
        <label data-i18n="channel">Channel:</label>
        <select id="modalKanaal">
          <option value="OBZ">OBZ</option>
          <option value="Telefoon" data-i18n="channelPhone">Phone</option>
          <option value="Email">Email</option>
          <option value="Anders" data-i18n="channelOther">Other</option>
        </select>
      </div>
      <div class="form-group">
        <label data-i18n="noteOptional">Note (optional):</label>
        <textarea id="modalOpmerking" rows="2"></textarea>
      </div>
      <div class="modal-buttons">
        <button class="btn-cancel" id="modalCancelBtn" data-i18n="cancel">Cancel</button>
        <button class="btn-save" id="modalSaveBtn" data-i18n="save">Save</button>
      </div>
    </div>
  </div>

  <div id="photoModal" class="photo-modal">
    <span class="photo-modal-close" id="photoModalCloseBtn">&times;</span>
    <img id="photoModalImg" src="" alt="Product photo enlarged">
  </div>

  <script>window.CURRENT_USER = <?= json_encode($user, JSON_UNESCAPED_UNICODE) ?>;</script>
  <script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?: time() ?>"></script>
</body>
</html>
