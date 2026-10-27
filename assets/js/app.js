// =======================================================================
// COMPATIBILITEITSLAAG: StocuroRun-shim (voorheen google.script.run)
// -----------------------------------------------------------------------
// De rest van dit bestand (vanaf "INGEBOUWDE CODE128-BARCODEGENERATOR")
// is vrijwel ongewijzigd overgenomen uit de oude Apps Script-frontend
// (DashboardAll.gs doGet()) -- migratieplan hoofdstuk 5/10: "bestaande
// HTML/CSS/JS uit doGet() vrijwel ongewijzigd hergebruiken". Die code
// riep oorspronkelijk google.script.run.withSuccessHandler(cb).functie(args)
// aan (de echte Apps Script-API). In plaats van elke render-functie
// handmatig te herschrijven naar fetch(), biedt StocuroRun (hieronder)
// dezelfde chainable API na maar fetch't onder water naar /api/*.php.
// Minder risico op transcriptiefouten dan alles herschrijven, en de
// bestaande, al geteste dashboardlogica blijft intact.
//
// Beperking t.o.v. de echte Apps Script-API: er is hier maar één actieve
// success/failure-handler tegelijk (geen aparte "run-instantie" per
// aanroep). Voor dit dashboard is dat geen probleem: de app doet nooit
// twee StocuroRun-aanroepen tegelijk in elkaar geschoven.
// =======================================================================

const API_MAP = {
  getOverzichtData:             { method: 'GET',  url: 'api/overzicht.php' },
  getRecenteTransacties:         { method: 'GET',  url: 'api/transactielog.php' },
  // 'taken' (één scherm) opgesplitst in Beheerder/Laborant.
  getTakenBeheerder:              { method: 'GET',  url: 'api/taken_beheerder.php' },
  getTakenLaborant:               { method: 'GET',  url: 'api/taken_laborant.php' },
  // Periodefilter (1m/3m/6m/1j/2j/totaal), zie AFBOEKINGEN_PERIODES.
  getAfboekingenOverzicht:        { method: 'GET',  url: 'api/afboekingen.php', params: ['periode'] },
  getTellingArtikelen:            { method: 'GET',  url: 'api/telling.php', params: ['locatie'] },
  // Kastlocatie-keuzelijst voor de "opnieuw telling
  // uitvoeren"-actie, zie kastlocatiesVoorLocatie() in includes/locaties.php.
  getKastlocatiesVoorLocatie:      { method: 'GET',  url: 'api/telling.php', params: ['locatie', 'actie'] },
  getProductDetail:             { method: 'GET',  url: 'api/product_detail.php', params: ['ean'] },
  getArtikelEditData:           { method: 'GET',  url: 'api/product_edit.php', params: ['ean'] },
  updateArtikelVeld:            { method: 'POST', url: 'api/product_edit.php', params: ['ean', 'updates'] },
  getFotoDataUri:                { method: 'GET',  url: 'api/foto.php', params: ['url'] },
  getProductList:                { method: 'GET',  url: 'api/product_list.php' },
  getBoodschappenData:           { method: 'GET',  url: 'api/boodschappenlijst.php' },
  getBergingData:                 { method: 'GET',  url: 'api/berging.php' },
  getVervaldatumDetail:           { method: 'GET',  url: 'api/vervaldatums.php' },
  getLotTracking:                  { method: 'GET',  url: 'api/lot_tracking.php' },
  getVerbruiksgebruik:             { method: 'GET',  url: 'api/verbruik.php' },
  getBestellersData:                { method: 'GET',  url: 'api/bestellers.php' },
  getVoorraadWaarde:                 { method: 'GET',  url: 'api/voorraadwaarde.php' },
  getActiecentrumData:                { method: 'GET',  url: 'api/actiecentrum.php' },
  getVervaldatumFocus:                 { method: 'GET',  url: 'api/vervalfocus.php' },
  getCategorieOverzicht:                { method: 'GET',  url: 'api/categorieen.php' },
  getOpenOrders:                         { method: 'GET',  url: 'api/open_orders.php' },
  // Order-detail/-bewerking, klikbaar vanuit Actiecentrum,
  // Overzicht en Open Orders zelf.
  getOrderDetail:                        { method: 'GET',  url: 'api/order_detail.php', params: ['id'] },
  getOrderDetailVoorEan:                 { method: 'GET',  url: 'api/order_detail.php', params: ['ean'] },
  updateOrder:                           { method: 'POST', url: 'api/order_detail.php', params: ['actie', 'id', 'besteld', 'ontvangen', 'besteller', 'kanaal', 'opmerking'] },
  getVerbruiksTrends:                     { method: 'GET',  url: 'api/trends.php' },
  // Product-drilldown achter een cel op het Trends-tabblad.
  getVerbruiksTrendDetail:                { method: 'GET',  url: 'api/verbruikstrend_detail.php', params: ['maand', 'reeks', 'sub'] },
  getFoutlog:                              { method: 'GET',  url: 'api/foutlog.php' },
  getLeaderboardData:                       { method: 'GET',  url: 'api/leaderboard.php', params: ['periode', 'procedure_type'] },
  // "wie is aan de beurt" -- zelfde endpoint,
  // aparte actie (zie api/leaderboard.php).
  getPiccRotatie:                            { method: 'GET',  url: 'api/leaderboard.php', params: ['actie'] },
  getMinMaxAdviesOverzicht:                  { method: 'GET',  url: 'api/minmax_advies.php' },
  getVerplaatsAdviesOverzicht:               { method: 'GET',  url: 'api/verplaatsadvies.php' },
  applyMinMaxAdvies:                          { method: 'POST', url: 'api/minmax_advies.php', params: ['ean', 'locatie', 'min', 'max'] },
  // Voorstel negeren -- verdwijnt tot MINMAX_NEGEER_MAANDEN later.
  negeerMinMaxAdvies:                          { method: 'POST', url: 'api/minmax_advies.php', params: ['actie', 'ean', 'locatie'] },
  getOpenEenmaligeBestellingen:              { method: 'GET',  url: 'api/eenmalige_bestelling.php', params: ['open'] },
  getEenmaligeBestellingDetail:              { method: 'GET',  url: 'api/eenmalige_bestelling.php', params: ['id'] },
  // Voor de Home-widget "Mijn Bestellingen": alleen bruikbaar voor een
  // echt ingelogd 'specialist'-account, zie api/eenmalige_bestelling.php.
  getEigenEenmaligeBestellingen:             { method: 'GET',  url: 'api/eenmalige_bestelling.php', params: ['mijn'] },
  // Reguliere-orders-tegenhanger van getEigenEenmaligeBestellingen()
  // hierboven -- samen voeden ze de Home-widget "Mijn Bestellingen".
  getEigenOpenOrders:                         { method: 'GET',  url: 'api/open_orders.php', params: ['mijn'] },
  getSpecialisten:                          { method: 'GET',  url: 'api/specialisten.php' },
  getProcedureTypen:                         { method: 'GET',  url: 'api/procedure_typen.php' },
  // Beheerde categorielijst + categorie-specifieke
  // eigenschappen (niet te verwarren met api/categorieen.php, het
  // bestaande Categorieën-dashboard).
  getCategorieenMetSpecs:                     { method: 'GET',  url: 'api/categorie_eigenschappen.php' },
  // Was Nieuws/Het Team/Contactpersonen samen, nu drie losse endpoints.
  getNieuwsDashboard:                         { method: 'GET',  url: 'api/nieuws_dashboard.php' },
  getTeamOverzicht:                           { method: 'GET',  url: 'api/team_overzicht.php' },
  getContactpersonenOverzicht:                { method: 'GET',  url: 'api/contactpersonen.php' },
  getProducenten:                            { method: 'GET',  url: 'api/producenten.php' },
  // Suggestielijst voor het kastlocatie-dropdown-veld.
  getKastlocatieSuggesties:                  { method: 'GET',  url: 'api/kastlocaties.php' },
  // Samengesteld beschikbaarheids-
  // overzicht (bedoeld als landingsscherm voor artsen).
  getBeschikbaarheid:                        { method: 'GET',  url: 'api/beschikbaarheid.php' },
  getVoorraadTabel:                          { method: 'GET',  url: 'api/voorraad_tabel.php' },
  getProducentDetail:                        { method: 'GET',  url: 'api/producenten.php', params: ['id'] },
  getLocaties:                                { method: 'GET',  url: 'api/locaties.php' },
  updateLocatie:                               { method: 'POST', url: 'api/locaties.php', params: ['slot', 'naam', 'actief'] },
  // 'besteller' niet meer meegestuurd -- de server zet dit
  // altijd zelf op de ingelogde gebruiker (zie api/bestelling.php).
  submitHandmatigeBestellingen:             { method: 'POST', url: 'api/bestelling.php', params: ['ean', 'aantal', 'kanaal', 'opmerking'] },
  // Naamkeuzelijst voor "Besteld door"-dropdowns.
  getGebruikersNamen:                       { method: 'GET',  url: 'api/gebruikers_lijst.php' },
  getOnbekendeScans:                        { method: 'GET',  url: 'api/onbekende_scans.php' },
  // (groepensysteem)
  getGroepen:                                { method: 'GET',  url: 'api/groepen.php' },
  getGroepDetail:                            { method: 'GET',  url: 'api/groepen.php', params: ['id'] },
  getGroepTemplate:                          { method: 'GET',  url: 'api/groepen.php', params: ['nieuw'] },
};

function callApi(functionName, args, onSuccess, onFailure) {
  const cfg = API_MAP[functionName];
  if (!cfg) {
    console.error('Unknown function in StocuroRun shim:', functionName);
    if (onFailure) onFailure({ message: 'Unknown function: ' + functionName });
    return;
  }

  let fetchPromise;
  if (cfg.method === 'GET') {
    const qs = (cfg.params || [])
      .map((p, i) => encodeURIComponent(p) + '=' + encodeURIComponent(args[i] !== undefined ? args[i] : ''))
      .join('&');
    fetchPromise = fetch(cfg.url + (qs ? '?' + qs : ''), { credentials: 'same-origin' });
  } else {
    const body = {};
    (cfg.params || []).forEach((p, i) => { body[p] = args[i]; });
    fetchPromise = fetch(cfg.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }

  fetchPromise
    .then(function (r) {
      if (r.status === 401) { window.location.href = 'login.php'; throw new Error('Not logged in'); }
      if (!r.ok) {
        // Lees de body mee in de foutmelding -- bij een PHP-fout (bijv. een
        // niet-geconfigureerde database) is dit vaak een HTML-foutpagina in
        // plaats van JSON, en dat is precies waardoor r.json() hieronder zou
        // stuklopen zonder duidelijke reden. Dit maakt zichtbaar wat er echt
        // mis is in plaats van alleen "HTTP 500".
        return r.text().then(function (bodyText) {
          throw new Error('HTTP ' + r.status + ' on ' + cfg.url + (bodyText ? ' -- ' + bodyText.slice(0, 300) : ''));
        });
      }
      return r.json().catch(function () {
        // Response was 200 OK maar geen geldige JSON -- meestal een PHP
        // warning/notice die vóór de json_encode()-output werd ge-echood.
        throw new Error('Invalid JSON response from ' + cfg.url + ' (check the PHP error log)');
      });
    })
    .then(function (data) { if (onSuccess) onSuccess(data); })
    .catch(function (err) {
      console.error(err);
      if (onFailure) {
        onFailure(err);
        return;
      }
      // Geen expliciete failure-handler gezet (de meeste render-aanroepen
      // zetten er geen) -- zonder dit zou het scherm voor altijd op
      // "Loading..." blijven staan zonder enige melding. Toon in plaats
      // daarvan een zichtbare foutmelding in #content.
      const el = document.getElementById('content');
      if (el) {
        el.innerHTML = '<button class="back-btn" id="backBtn">← Back</button><h2>Something went wrong</h2>'
          + '<p>' + escapeHtml(err && err.message ? err.message : String(err)) + '</p>'
          + '<p class="form-hint">Check the browser console (F12) for details, and the server-side PHP error log if this keeps happening.</p>';
        const backBtn = document.getElementById('backBtn');
        if (backBtn) backBtn.addEventListener('click', goBack);
      }
    });
}

function createScriptRunProxy() {
  let successHandler = null;
  let failureHandler = null;
  // Wordt hieronder gevuld met de daadwerkelijke Proxy-instantie (niet het
  // kale target-object) -- withSuccessHandler/withFailureHandler moeten
  // DIE teruggeven, anders loopt de aaneengeschakelde aanroep
  // (.withSuccessHandler(cb).getX()) buiten de Proxy's get-trap om en
  // bestaat "getX" simpelweg niet op het kale object. Dat was hier eerder
  // een bug: withSuccessHandler gaf het kale target-object terug in plaats
  // van de Proxy, waardoor élke StocuroRun-aanroep "... is not a function"
  // gaf zodra er een success/failure-handler werd doorgegeven (dus praktisch
  // altijd) -- dit was de eigenlijke oorzaak van het "blijft op laden staan"
  // gedrag, niet de eerder vermoede cache- of naamconflict-oorzaken.
  let instance;

  const target = {
    withSuccessHandler: function (cb) { successHandler = cb; return instance; },
    withFailureHandler: function (cb) { failureHandler = cb; return instance; },
  };

  instance = new Proxy(target, {
    get: function (t, prop) {
      if (prop in t) return t[prop];
      return function () {
        const args = Array.prototype.slice.call(arguments);
        const sh = successHandler, fh = failureHandler;
        successHandler = null; failureHandler = null;
        callApi(prop, args, sh, fh);
        return instance;
      };
    },
  });

  return instance;
}

// Bewust NIET "window.google" genoemd (ondanks dat de rest van dit
// bestand oorspronkelijk vanuit een Apps Script-omgeving kwam waar dat de
// natuurlijke naam was): op het open web kan Chrome's paginavertaling (of
// sommige extensies) op elk moment "window.google" overschrijven, ook ná
// het laden van de pagina -- en deze app heeft veel Nederlandstalige tekst,
// dus vertaalsuggesties zijn hier extra waarschijnlijk. Een eigen,
// uniek genaamde global voorkomt die hele bugklasse.
window.StocuroRun = createScriptRunProxy();

// =======================================================================
// VERTAALSYSTEEM: Engels is de standaardtaal van de applicatie (de keys
// hieronder zijn geschreven vanuit Engelse brontekst). t(key)/tf(key,vars)
// dekken inmiddels alle ~38 render-functies -- elk scherm (dashboards,
// formulieren, beheerpagina's), niet alleen het hoofdmenu/de header zoals
// in een eerdere versie van dit systeem. tf() ondersteunt simpele
// {placeholder}-vervanging voor teksten met een dynamische waarde (zie
// tf() hieronder). Statusbadge-teksten (LEEG/LAAG/VERLOPEN/etc.) en
// producentcategorieën (Stent/Voerdraad/etc. als interne matching-sleutel)
// zijn bewust NIET vertaald op backend-niveau: die codes worden zowel door
// de backend (PHP) als classifyStatus() hier gebruikt om kleur/urgentie/
// specificatievelden te bepalen -- vertalen vraagt een gecoördineerde
// wijziging aan beide kanten, bewust uitgesteld.
// =======================================================================
const TRANSLATIONS = {
  en: {
    signOut: 'Sign out',
    dashboards: 'Dashboards',
    // Vier samengestelde
    // widgets op de Home-pagina zelf (zie laadHomeWidgets()/
    // renderHomeWidgets() in assets/js/app.js).
    hwMijnBestellingen: '📦 My Orders',
    hwLoadError: 'Could not load this right now.',
    hwGeenOpenBestellingen: 'No open orders right now.',
    hwEnMeer: '+ {n} more',
    hwNaarMijnBestellingen: 'See all my orders',
    hwNieuws: '📰 News',
    hwGeenNieuws: 'No news right now.',
    hwNieuwProduct: 'New: {naam}',
    hwVervanger: '{naam} is temporarily replaced by {vervanger}',
    hwNaarNieuws: 'See full news',
    // Eigen brede
    // fototegel-rij op Home met de nieuwst aangemaakte producten, los van
    // de bestaande tekst-lijst hierboven.
    hwNieuweProductenTitle: '🆕 New products',
    hwNieuweProductenEmpty: 'No products added recently.',
    hwPiccTitle: "🩺 Who's up next",
    hwGeenPiccProcedures: 'No lab-technician procedures configured yet.',
    hwAanDeBeurt: '{procedure}: {naam}',
    // ("uitbreiden met foto en minimaal 3 personen op
    // volgorde van wie aan de beurt is"): badge onder de eerste persoon in
    // de rotatie-rij, zie piccRotatieHtml() in assets/js/app.js.
    hwPiccUpNext: 'up next',
    hwNaarLeaderboard: 'See full leaderboard',
    hwTopScanners: '🏆 Top 10 Scanners (this month)',
    hwTakenTitle: '✅ Tasks',
    hwGeenTaken: 'No open tasks right now.',
    hwTakenKastcontrole: '{n} location(s) due for a stock count',
    hwTakenVervallen: '{n} expired lot(s) still in stock',
    hwTakenOnbekend: '{n} unknown barcode(s) to finish',
    hwTakenOnvolledig: '{n} product(s) with missing info',
    hwTakenBatch: '{n} item(s) missing batch/expiry data',
    hwNaarTaken: 'See all tasks',
    // ("Home:
    // sectie met gebruikers/licentie-informatie").
    hwGebruikersLicentieTitle: '🔑 Users & License',
    hwGebruikersTotaal: '{n} user account(s) in total',
    hwGebruikersLicentieGeldig: '{n} with a valid license',
    hwGebruikersLicentieGeblokkeerd: '{n} without a valid license (cannot log in)',
    hwNaarGebruikers: 'Manage users',
    hwVervalfocusTitle: '⏰ Expiry Risk',
    hwVervalfocusEmpty: 'No lots nearing expiry right now.',
    hwVervalfocusSummary: '{n} lot(s) already expired, worth €{waarde}.',
    hwNaarVervalfocus: 'See full expiry overview',
    hwVerplaatsadviesTitle: '🔀 Transfer Advice',
    hwVerplaatsadviesEmpty: 'No transfer recommendations right now.',
    hwNaarVerplaatsadvies: 'See full transfer advice',
    groupActionTasks: 'Action & Tasks',
    groupStockLevels: 'Stock Levels',
    groupExpiryLots: 'Expiry & Lots',
    groupOrdering: 'Ordering',
    groupReporting: 'Reporting',
    actionCenter: '⚡ Action Center',
    // Gedeelde ingang voor Overview/Refill/Storage
    // Restock/Stock Value/Min-Max Advice -- zie VOORRAAD_TABS.
    stockHub: '📦 Stock',
    // Apart, actiegericht
    // dashboard voor laboranten -- zie showBijvullen()/renderBijvullen() in
    // dit bestand voor de reden waarom dit een eigen dashboard is naast de
    // 'refillList'-tab in Stock.
    bvTitle: '🔄 Refill',
    bvHint: 'Products below minimum that Storage ({berging}) can supply right now — scan below to transfer them.',
    bvHintGeenBerging: 'No storage location is configured, so nothing can be transferred here.',
    bvAllesOpPeil: 'All locations have sufficient stock — nothing to refill right now.',
    bvAantalProducten: '{n} product(s)',
    bvColHuidig: 'Current',
    bvColBijvullen: 'To add',
    // Een
    // kastlocatie-naam (bv. "Cabinet 1") kan in meerdere locaties
    // voorkomen, dus deze twee kolommen tonen altijd "locatie: kastlocatie"
    // samen -- nooit de kastlocatie los, dat zou dubbelzinnig zijn.
    bvColVanaf: 'From (cabinet)',
    bvColNaar: 'To (cabinet)',
    bvScanTitle: 'Scan to transfer',
    bvScanHint: 'Scan the barcodes for the boxes you are moving from Storage to the location below.',
    // Bugfix: voorheen verdween een tekort dat Storage
    // zelf niet kan vullen (Storage zelf leeg) gewoon uit deze lijst, en het
    // scherm liet dan ten onrechte "all locations have sufficient stock"
    // zien. Nu apart getoond: het tekort blijft zichtbaar, maar met deze
    // waarschuwing i.p.v. de "+N"-actie (die pas weer verschijnt zodra
    // Storage zelf weer voorraad heeft).
    bvBergingLeeg: '⚠️ Shortage of {n} — Storage itself has no stock to transfer from yet.',
    overview: '📦 Overview',
    recentTransactions: '📋 Recent Transactions',
    tasks: '🗒️ Tasks',
    // 'tasks' (above) is no longer used directly -- split
    // into two dashboards, since Unknown scans/Incomplete product data are
    // admin data-management tasks while Stock counts due/Past expiry are
    // lab technician floor-work tasks.
    tasksAdmin: '🗒️ Tasks (Admin)',
    tasksLab: '🗒️ Tasks (Lab)',
    writeOffs: '📉 Write-offs & Shortages',
    shoppingList: '🛒 Shopping List',
    // Renamed to "Refill" -- this screen now only shows the
    // simple internal refill-from-storage list (the external manual-order
    // list lives on its own "To Order" screen).
    // Renamed again -- "Refill"
    // is now the name of the new actionable scan-to-transfer dashboard
    // (bvTitle below), so this read-only list tab needed a distinct label
    // to avoid two identically-named destinations in the menu.
    refillList: '📦 Restock List',
    storage: '📦 Storage Restock',
    // Gedeelde ingang voor Expiry Dates Detail/Lot Tracking/
    // Expiry Focus -- zie VERVAL_TABS.
    expiryLotsHub: '📅 Expiry & Lots',
    // Gedeelde ingang voor To Order/Open Orders/My Orders --
    // zie ORDER_TABS_BASIS.
    ordersHub: '🛒 Orders',
    // Gedeelde ingang voor Usage per Procedure/Usage Trends/
    // Write-offs -- zie USAGE_TABS.
    usageHub: '📈 Usage & Trends',
    expiryDatesDetail: '📅 Expiry Dates Detail',
    lotTracking: '🔍 Lot Tracking',
    usage: '👨‍⚕️ Usage per Procedure',
    toOrder: '📋 To Order',
    stockValue: '💰 Stock Value',
    // Gedeelde ingang voor Search Products/Categories -- zie
    // PRODUCT_TABS.
    productsHub: '🔎 Products',
    searchProducts: '🔎 Search Products',
    producenten: '🏭 Manufacturers',
    // Gedeelde ingang voor Leaderboard/Het Team -- zie
    // TEAM_TABS.
    teamPerformanceHub: '🏆 Team & Performance',
    // Was Nieuws/Het Team/Contactpersonen samen, nu drie losse schermen.
    newsTeam: '📰 News & Team',
    newsTitle: '📰 News',
    newsNewestProducts: 'Newest products',
    newsNoProducts: 'No products yet.',
    newsUnavailableReplaced: 'Unavailable, temporarily replaced by',
    newsNoReplacements: 'No products currently unavailable with a replacement.',
    newsReplacedBy: 'Replaced by',
    newsTeamTitle: '👥 The Team',
    newsSpecialists: 'Specialists',
    newsLabTechs: 'Lab technicians',
    newsNoTeamMembers: 'No one to show yet.',
    newsContactsTitle: '📇 Contacts',
    newsNoContacts: 'No contacts recorded yet.',
    // Samengesteld beschikbaarheids-
    // overzicht, bedoeld als landingsscherm voor de artsen-groep.
    beschikbaarheidTitle: '🩺 Availability',
    bhHint: 'What is available, how much, where, its status, and recent usage -- all in one place. Click a product for full detail.',
    bhTotalUnits: 'Total units',
    bhUsageDays: 'Usage (last {n}d)',
    managementCockpitTitle: '📊 Reporting',
    mcHint: 'The most important figures from Stock Value, Usage & Trends and Team & Performance combined on one page.',
    mcTopCategories: 'Highest-value categories',
    mcSeeFullValue: 'See full stock value',
    mcLatestMonth: 'Latest month ({maand})',
    mcUsageTotal: 'Total usage',
    mcSeeFullTrends: 'See full usage & trends',
    mcTeamTitle: 'Top scans this month',
    mcSeeFullTeam: 'See full team & performance',
    // ("Rapportage-
    // pagina volledig herzien"): tab labels for the five dimensions +
    // the new Actions tab's tile labels/hint.
    rpDimVoorraad: '📦 Stock',
    rpDimVerbruik: '📈 Usage',
    rpDimTeam: '👥 Team',
    rpDimActies: '⚡ Actions',
    rpDimLicentie: '🔑 License',
    rpActiesHint: 'Open recommendations and shortages that need attention -- each tile links to its full dashboard.',
    rpAfboekingenVervallen: 'Expired (last year)',
    rpAfboekingenTekort: 'Shortages (last year)',
    sidebarHome: 'Home',
    sidebarSearchPlaceholder: '🔍 Search...',
    vtHint: 'One table for everything about current stock -- filter by what needs attention, or toggle extra columns on as needed.',
    vtColAdvies: 'Min/max advice',
    vtColUsage: 'Recent usage',
    vtColProducer: 'Manufacturer',
    vtColKastlocatie: 'Cabinet location',
    vtEmpty: 'No products match this filter/search.',
    vtFilterAlles: 'All',
    vtFilterLeeg: '🔴 Empty',
    vtFilterLaag: '🟡 Low',
    vtFilterBijvullen: '📦 Can be refilled',
    vtFilterBerging: '🚚 Storage: needs ordering',
    vtFilterHandmatig: '🛒 Manual order needed',
    vtFilterVervalt: '📅 Expires within 6mo',
    vtFilterAdvies: '📈 Advice differs',
    vtFilterVerplaats: '↔️ Transfer suggested',
    vtAdviesWaarde: 'min {min} / max {max}',
    vtVerplaatsNaar: '↔️ Move to {locatie} ({dagen}d left)',
    // Bugfix: voorheen kreeg een rij die onder minimum
    // zit maar niet bijgevuld kan worden (Storage zelf leeg) gewoon een
    // lege actiekolom ("-"), wat het tekort onzichtbaar maakte. Nu een
    // duidelijke waarschuwing i.p.v. niets.
    vtBergingLeeg: '⚠️ Storage empty',
    bsHint: 'Everything that needs ordering or is already on order, in one place -- filter by status.',
    bsColType: 'Type',
    bsTypeHandmatig: 'Manual order',
    bsTypeEenmalig: 'One-off order',
    bsTypeOrder: 'Regular order',
    bsEmpty: 'No orders match this filter.',
    bsFilterAlles: 'All',
    bsFilterTeBestellen: 'To order',
    bsFilterInBestelling: 'On order',
    bsFilterGedeeltelijk: 'Partially received',
    bsFilterMijn: 'Mine',
    ctTitle: '📇 Contacts',
    ctHint: 'Manufacturers and their contact people together -- switch view below.',
    ctViewPersonen: 'People',
    ctViewBedrijven: 'Manufacturers',
    ctNoDetails: 'No phone, email or note on file for this contact.',
    tkTitle: '✅ Tasks',
    expiryFocus: '⏳ Expiry Focus',
    transferAdvice: '🔀 Transfer Advice',
    categories: '📁 Categories',
    openOrders: '🚚 Open Orders',
    usageTrends: '📈 Usage Trends',
    errorLog: '⚠️ Error Log',
    unknownProducts: '❓ Unknown Products',
    opHint: 'Barcodes scanned during Goods Receipt, Transfer + Returns, Material Usage, Write Off Expired, or Stock Count that did not match any known product. Each one is created automatically as a real product, using the description the scanner typed in as a temporary name -- it already shows up in stock and works everywhere. Finish it up below: fill in the real name, category, price, and so on.',
    opNone: 'No unknown barcodes right now.',
    opLastSeen: 'Last seen',
    opMaakArtikel: 'Create this product',
    opAfronden: 'Finish this product',
    onbTitel: 'Unknown barcode(s)',
    onbUitleg: "These barcodes aren't recognized yet. Fill in a short description of what the product is for each one, so an admin can create it properly -- nothing is booked until you do.",
    onbPlaceholder: 'e.g. green suture set, Ethicon',
    onbIndienen: 'Submit again',
    onbOmschrijvingen: 'Description(s)',
    forms: '📋 Forms',
    scanUsage: '✅ Material Usage',
    receiveStock: '📦 Goods Receipt',
    transferReturns: '🔄 Transfer + Returns',
    manualOrder: '🛒 Manual Order',
    oneOffOrder: '🎁 One-off Order',
    addProduct: '➕ Add Product',
    writeOffExpired: '🗑️ Write Off Expired',
    admin: '⚙️ Admin',
    adminGroupAccess: 'Access',
    adminGroupSettings: 'Settings',
    adminGroupDataQuality: 'Data Quality',
    users: '👥 Users',
    masterData: '🗂️ Master Data',
    locations: '📍 Locations',
    // Statische app.php-markup (modal, koppen) via data-i18n -- moeten ook
    // in EN staan, anders valt t() voor Engelstalige gebruikers terug op
    // de kale key-naam (geen EN-tekst elders om op terug te vallen).
    logout: 'Sign out',
    loginLink: 'Log in',
    gastBannerText: "🔍 You're viewing a read-only demo — nothing you enter or change here is saved.",
    gastBannerLink: 'Log in with a test account to try editing →',
    modalOrderTitle: '📦 Create order',
    product: 'Product:',
    quantity: 'Quantity:',
    orderedBy: 'Ordered by:',
    channel: 'Channel:',
    channelPhone: 'Phone',
    channelOther: 'Other',
    noteOptional: 'Note (optional):',
    cancel: 'Cancel',
    save: 'Save',

    // Gedeelde bouwstenen: kolomkoppen, knoppen en generieke meldingen die
    // op meerdere schermen identiek terugkomen.
    backLabel: '← Back',
    errorLabel: 'Error',
    somethingWrong: 'Something went wrong',
    loading: 'Loading...',
    editLabel: 'Edit',
    deleteLabel: 'Delete',
    addLabel: 'Add',
    yesLabel: 'Yes',
    noLabel: 'No',
    activeLabel: 'Active',
    inactiveLabel: 'Inactive',
    neverLabel: 'Never',
    noneRecorded: 'None recorded.',
    noPhoto: 'No photo',
    colProduct: 'Product',
    colLocation: 'Location',
    colCabinet: 'Cabinet',
    colCurrent: 'Current',
    colMin: 'Min',
    colMax: 'Max',
    colValue: 'Value',
    colStockStatus: 'Stock Status',
    colOrderStatus: 'Order Status',
    colDate: 'Date',
    colType: 'Type',
    colQty: 'Qty',
    colQuantity: 'Quantity',
    colSpecialist: 'Specialist',
    colProcedure: 'Procedure',
    colNote: 'Note',
    colStatus: 'Status',
    colLot: 'Lot',
    colExpiryDate: 'Expiry date',
    colManufacturer: 'Manufacturer',
    colPrice: 'Price',
    colUnits: 'Units',
    colMonths: 'Months',
    colAction: 'Action',
    colOrderedOn: 'Ordered on',
    colOrdered: 'Ordered',
    colReceived: 'Received',
    // Eerste/laatste ontvangstdatum
    // zichtbaar op het orderdetailscherm (was al bijgehouden in de
    // database maar nooit getoond), zie includes/orders.php::getOrderDetail().
    odFirstReceivedOn: 'First received on',
    odLastReceivedOn: 'Last received on',
    odNotYetReceived: 'Not yet received',
    colRemaining: 'Remaining',
    colOrderedBy: 'Ordered by',
    colChannel: 'Channel',
    colDaysOpen: 'Days open',
    colTotalUsed: 'Total Used',
    colRole: 'Role',
    colName: 'Name',
    colActive: 'Active',
    colCategory: 'Category',
    colBelowMin: 'Below min',
    colLotsUnder6mo: 'Lots < 6 mo',
    colProducts: 'Products',
    colEmail: 'Email',
    colLastLogin: 'Last login',
    colLanguage: 'Language',

    // Recent Transactions
    rtHint: 'Last 7 days only. Use this to fix a scanning mistake (e.g. scanned from the wrong location) -- older history cannot be edited here.',
    rtNoneRecent: 'No transactions in the last 7 days.',
    rtEdited: 'edited',
    rtConfirmDelete: 'Delete this transaction? This directly changes the stock count and cannot be undone from here.',
    rtSave: 'Save',
    rtCancel: 'Cancel',
    rtTypeReceived: 'Goods Receipt',
    rtTypeUsed: 'Material Usage',
    rtTypeTransfer: 'Transfer',
    rtTypeTransferIn: 'Transfer in',
    rtTypeTransferOut: 'Transfer out',
    rtTypeReturned: 'Returned',
    rtTypeSurplus: 'Stocktake: surplus',
    rtTypeShortage: 'Stocktake: shortage',
    rtTypeExpired: 'Expired write-off',
    rtViewLines: 'View lines ({n})',
    rtHideLines: 'Hide lines',
    rtConfirmDeleteBatch: 'Delete this whole scan action ({n} line(s))? This directly changes the stock count for every line in it and cannot be undone from here.',
    colLines: 'Lines',

    // Overview
    ovHideEmpty: 'Hide items with zero stock',
    ovNothing: 'Nothing to show',
    ovNothingInStock: 'Nothing to show currently in stock.',
    ovNothingAll: 'Nothing to show.',

    // Tasks
    tkCountsDue: 'Stock counts due',
    tkCountsHint: 'Every location should be physically counted (scan everything present) about every {n} months. Locations below are overdue.',
    tkNothingDue: 'Nothing due right now.',
    colLastCounted: 'Last counted',
    tkStartCount: 'Start count',
    // Vrijwillige actie, geen taak --
    // "opnieuw voorraadtelling uitvoeren" voor ELKE actieve locatie, niet
    // alleen de locaties die hierboven al overdue zijn.
    tkHerTellenTitel: 'Run a stock count anytime',
    tkHerTellenHint: 'Not overdue, but want to double-check a cabinet or location right now? Pick one below and start a count whenever it suits you -- this isn\'t a required task.',
    tkHerTellenStart: 'Start count',
    tkHerTellenGeen: 'No active locations to choose from.',
    tkHerTellenKastLabel: 'Cabinet',
    tkHerTellenHeleLocatie: 'Whole location',
    tkPastExpiry: 'Past expiry date',
    tkPastExpiryHint: 'Still in stock but past their expiry date. Use 🗑️ Write Off Expired to remove them from stock.',
    tkNothingExpired: 'Nothing past its expiry date.',
    tkGoWriteOff: 'Go to Write Off Expired',
    tkOntbrekendeBatch: 'Missing batch/expiry data',
    tkOntbrekendeBatchHint: 'Stock received without ever scanning a batch/expiry code (e.g. only the box GTIN was scanned). To resolve: delete the transaction in Recent Transactions and re-scan the product together with its batch/expiry barcode.',
    tkOntbrekendeBatchGeen: 'No stock is missing batch/expiry data.',
    gs1HintNogBatch: 'Scan the batch/expiry code for this product too.',
    gs1HintDoorgaan: 'Continue without it',
    tkOnbekend: '❓ Unknown scans',
    tkOnbekendHint: "Barcodes scanned that didn't match any known product, with the description the scanner typed in. Resolve them on the Unknown Products screen.",
    tkOnbekendGeen: 'No unknown barcodes right now.',
    tkGoOnbekend: 'Go to Unknown Products',
    tkKeerGezien: 'Times seen',
    tkOnvolledig: '📋 Incomplete product data',
    tkOnvolledigHint: 'Products missing information such as manufacturer, photo, min/max, cabinet location, expiry data, price, category, or box packaging setup.',
    tkOnvolledigGeen: 'Every product has its essential information filled in.',
    tkOntbrekendeVelden: 'Missing fields',
    tkVeld_producent: 'Manufacturer',
    tkVeld_foto: 'Photo',
    tkVeld_minMax: 'Min/max',
    tkVeld_kastlocatie: 'Cabinet location',
    tkVeld_houdbaarheid: 'Shelf life data',
    tkVeld_prijs: 'Price',
    tkVeld_categorie: 'Category',
    tkVeld_doosInconsistent: 'Box packaging incomplete',

    // Stocktake (telling)
    tlTitle: '🗒️ Stock Count — ',
    tlHint: 'Physically go through this location and scan every item present, one scan per unit (scan a product 3 times if there are 3 units). Anything you don\'t scan is assumed to be zero.',
    tlScansLabel: 'GS1 scans (one per unit present)',
    tlSubmit: 'Submit count',
    tlProcessed: 'Count processed. {n} correction(s) booked',
    tlAndErrors: ', {n} scan line(s) could not be read',
    colSystem: 'System',
    colCounted: 'Counted',
    colDifference: 'Difference',
    tlAllMatched: 'Everything matched — no corrections needed.',
    tlUnreadable: 'Unreadable lines',
    tlKastHint: 'This count is scoped to cabinet "{kast}" only — items belonging to other cabinets in this location won\'t be compared or corrected.',
    tlVerkeerdeKastTitel: 'Scanned, but belongs to another cabinet',
    tlVerkeerdeKastHint: 'These items were scanned but their assigned cabinet is different from the one you\'re counting. They were not counted or corrected here — put them back in their own cabinet.',
    tlVerkeerdeKastWerkelijk: 'Actual cabinet',

    // Write-offs & Shortages
    woHint: 'Historical record of the two "resolved" task types from 🗒️ Tasks — expired stock that was written off, and items that couldn\'t be found during a stock count.',
    woExpiredTitle: 'Expired write-offs ({units} units, {value})',
    woShortageTitle: 'Stocktake shortages — not found in stock ({units} units, {value})',
    woPeriode_1m: 'Past month',
    woPeriode_3m: 'Past 3 months',
    woPeriode_6m: 'Past 6 months',
    woPeriode_1j: 'Past year',
    woPeriode_2j: 'Past 2 years',
    woPeriode_totaal: 'All time',

    // Product detail / edit
    pdEdit: '✏️ Edit',
    colEan: 'EAN',
    colCategoryLabel: 'Category',
    colOrderType: 'Order type',
    colManufacturerLabel: 'Manufacturer',
    colArticleNumber: 'Article number',
    colPricePerUnit: 'Price per unit',
    colTotalStockValue: 'Total stock value',
    colOrder: 'Order',
    colScannedBy: 'Scanned by',
    pdSpecs: '🔧 Specifications',
    pdStockByLocation: '📍 Stock by Location',
    pdLotsInStock: '📦 Lots in Stock',
    ltBekijkLocaties: 'View {n} location(s)',
    ltHideLocaties: 'Hide locations',
    lotBewerken: '✏️ Edit',
    lotGeenBarcode: 'No barcode (manual entry)',
    lotBewerkHintMetBarcode: 'This corrects this lot everywhere it appears (all locations, regardless of age) and remembers the fix for this exact barcode -- the next time this same barcode is scanned, the corrected lot/expiry date is used automatically.',
    lotBewerkHintZonderBarcode: 'This corrects this lot everywhere it appears (all locations, regardless of age). No barcode was recorded for it, so there is nothing to remember for future scans.',
    pdTransactionHistory: '📋 Transaction History (Last 20)',
    pdUsageBySpecialist: '👨‍⚕️ Usage by Specialist',
    // Productpagina: grafiek historisch verbruik.
    pdVerbruiksgrafiekTitle: '📈 Usage History (last 12 months)',
    pdVerbruiksgrafiekEmpty: 'No usage recorded for this product in the last 12 months.',
    pdOrderHistory: '📑 Order History',
    pdNoOrders: 'No orders placed yet for this product',
    pdManufacturerContact: 'View contact details →',

    // Artikel-status (niet leverbaar /
    // niet meer in gebruik) + vervangend artikel.
    statusActief: 'Active',
    statusNietLeverbaar: 'Not deliverable',
    statusNietMeerInGebruik: 'No longer in use',

    // : statusBadgeHtml() gaf voorheen altijd de ruwe
    // backend-statuscode (VERLOPEN/LAAG/MONITOREN/'In bestelling'/enz.)
    // letterlijk weer, ongeacht schermtaal -- deze sleutels vertalen die
    // badge-tekst terwijl de kleurclassificatie (classifyStatus()) op de
    // originele ruwe tekst blijft werken. Zie statusBadgeHtml() hieronder.
    statusBadgeExpired: 'Expired',
    statusBadgeUrgentUse: 'Urgent use',
    statusBadgeMonitor: 'Monitor',
    statusBadgeValid: 'Valid',
    statusBadgeLongLife: 'Long-life',
    statusBadgeOk: 'OK',
    statusBadgeEmpty: 'Empty',
    statusBadgeLow: 'Low',
    statusBadgeHigh: 'High',
    statusBadgeOnOrder: 'On order',
    statusBadgePartiallyReceived: 'Partially received',
    statusBadgeReceived: 'Received',
    statusBadgeOrdered: 'Ordered',
    statusBadgeRequested: 'Requested',
    statusBadgeUsed: 'Used',
    statusBadgeReturned: 'Returned',
    pdNietLeverbaar: '⚠️ Not deliverable — temporarily unavailable from the supplier.',
    pdNietMeerInGebruik: '⛔ No longer in use.',
    pdVervangenDoor: 'Replaced by: {naam}',
    pdNogGeenVervanger: 'No replacement product set yet.',
    pdStatusWijzigen: 'Change status →',

    // Manufacturers (migration 012)
    prHint: 'Contact details per manufacturer/supplier — website, general email/phone, and any number of contact persons. Linked to products by matching the manufacturer name (case-insensitive); the manufacturer field on a product itself stays free text.',
    prEmpty: 'No manufacturers added yet',
    colWebsite: 'Website',
    colPhone: 'Phone',
    colContacts: 'Contacts',
    colLinkedProducts: 'Products',
    colFunction: 'Role',
    prNewManufacturer: 'Add new manufacturer',
    prContactsTitle: '👥 Contacts',
    prNoContacts: 'No contacts added yet',
    prNewContact: 'Add new contact',
    prLinkedProductsTitle: '📦 Linked Products',
    prNoLinkedProducts: 'No products linked to this manufacturer (by name)',
    prEditTitle: '✏️ Edit manufacturer: ',
    prDelete: 'Delete manufacturer',
    prDeleteConfirm: 'Delete {naam}? Its contacts will be deleted too. Products using this manufacturer name are left untouched.',
    prDeleteContactConfirm: 'Delete this contact?',

    peEditTitle: '✏️ Edit ',
    peCancelTop: '← Cancel',
    peHint: 'EAN {ean} — category {cat}.',
    pePhoto: 'Photo',
    pePhotoLink: 'Photo link',
    pePhotoPlaceholder: 'Paste a Google Drive share link (Anyone with the link) or a direct image URL',
    pePhotoHint: 'Set the Drive file to “Anyone with the link”, otherwise the dashboard cannot load the photo.',
    // Geüploade foto staat rechtstreeks
    // in de database (JPG/PNG/WEBP, max 2MB) en krijgt voorrang op de
    // link hieronder -- die link blijft alleen als terugvaloptie.
    pfPhotoUploadHint: 'JPG, PNG or WEBP, max 2MB. Stored directly in the database and takes priority over the link below.',
    pePhotoFallbackHint: 'Only used as a fallback if no photo has been uploaded above.',
    peGeneralInfo: 'General information',
    peSpecifications: 'Specifications',
    peSaving: 'Saving...',
    peErrorSaving: 'Error saving: ',

    // ("I want to actually be able to
    // edit everything"): edit screen reorganized into clear sections
    // instead of one long list, plus dropdowns for manufacturer/order
    // type/category and a dedicated (cascading) EAN-change action.
    peSectionIdentification: 'Identification',
    peSectionOrderData: 'Order data',
    peSectionStock: 'Stock: Min/Max & shelf location per room',
    peSectionExtraLocations: 'Additional locations',
    peKastlocatie: 'Shelf location',
    // Kastlocatie als dropdown-met-
    // "nieuwe toevoegen" i.p.v. vrij tekstveld, zelfde patroon als Manufacturer.
    peKastlocatieNone: '(none)',
    peKastlocatieNew: '+ New shelf location',
    peKastlocatieNewPlaceholder: 'Type new shelf location',
    peEanCurrentLabel: 'Current EAN / barcode',
    peEanChangeTitle: 'Change EAN',
    peEanChangeHint: 'Only use this to correct a wrongly scanned/typed code for this SAME physical product. It rewrites the EAN everywhere it is used -- this product\'s full stock history (Recent Transactions), open orders and any snoozed Min/Max advice move along with it. If this is actually a different product, use "Create a new product" or the replacement flow below instead.',
    peEanChangeNewLabel: 'New EAN / barcode',
    peEanChangeBtn: 'Change EAN',
    peEanChangeSaving: 'Changing...',
    peEanChangeConfirm: 'Change the EAN from {oud} to {nieuw}? This rewrites it in this product\'s stock history, orders and Min/Max advice too. This cannot be undone from here.',
    peEanChangeSuccess: 'EAN changed to {ean}.',

    // Status/vervanger-sectie op het bewerkscherm.
    peStatusTitel: 'Status',
    peStatusHint: 'Mark this product as temporarily not deliverable (with an optional replacement product) or as permanently no longer in use. Both are excluded from Min/Max Advice, the Shopping List and the Storage Restock List -- existing physical stock and past transactions are unaffected.',
    peStatus: 'Status',
    peVervanger: 'Replacement product',
    peVervangerGeen: '(no replacement set)',
    peVervangerNieuwArtikel: 'Create a new product as replacement...',

    // Product search
    psPlaceholder: 'Search by product name or EAN...',
    psAllCategories: 'All categories',
    psColStockByLocation: 'Stock by location',
    colExpiry: 'Next expiry',

    // Shopping list (renamed "Refill" -- slHint1/slHint2 no
    // longer shown, kept for translation-key parity)
    slHint1: 'Refill from Storage',
    slHint2: 'To Order (Manual)',
    slRefillHint: 'Only shows what can actually be refilled from Storage right now. Nothing in Storage means nothing to show here.',
    slCurrentAt: 'Current at ',
    slNeeded: 'Needed',
    slInStorage: 'In Storage',
    slAllSufficient: 'All locations have sufficient stock',

    // Storage restock
    stTitle: '📦 Storage Restock List',
    stHint: 'Everything below minimum at Storage, computed from scans — hand this list to logistics directly, no physical count or scan needed to know what to order.',
    stAllSufficient: 'Everything sufficiently in stock',
    // Was de GS1-EAN (leverancierscode),
    // nu de interne logistiekcode -- bedoeld voor een eigen schaplabel,
    // niet voor de leverancier. Optioneel veld, dus niet elk product heeft
    // 'm; zie stNoLogistiekCode hieronder voor de lege-staat.
    colBarcode: 'Internal Code Barcode',
    stNoLogistiekCode: 'No internal logistics code set for this product',
    colProducerRef: 'Producer / Ref',
    colNeeded: 'Needed',

    // Printen/exporteren + markeren
    // als besteld voor de Berging-bestellijst.
    bpPrintKnop: '🖨️ Print / Export as PDF',
    // "Print/Export" replaced by a
    // download-PDF + mailto combo -- a mailto: link can never carry an
    // attachment automatically, so this downloads the PDF first and then
    // opens a blank-recipient email with a reminder to attach it.
    bpDownloadMailKnop: '📧 Download PDF & Email Logistics',
    bpDownloadMailHint: 'Downloads a PDF of this list, then opens a blank email -- attach the just-downloaded file yourself before sending (browsers can\'t attach files to an email automatically).',
    bpMarkeerKnop: 'Mark as ordered',
    bpMarkeerHint: 'Downloading/emailing has no side effects. "Mark as ordered" places an order for the remaining amount shown for each item above -- an item already fully covered by an existing order simply doesn\'t appear on this list. Logistics receiving it later is scanned in as usual under Goods Receipt.',
    bpTitel: 'Storage Restock List',
    bpDozen: 'boxes',
    bpStuks: 'pcs',
    bpMarkeerConfirm: 'Mark every item on this list as ordered? This places a real order for each one.',
    bpMarkeerGelukt: '{aantal} item(s) marked as ordered.',
    bpMailSubject: 'Storage restock list -- {datum}',
    bpMailIntro: 'Attached is the current storage restock list, exported from Stocuro.',
    bpMailAttachment: 'Attachment: {bestand} (just downloaded -- please attach it to this email before sending)',
    bpMailListHeader: 'Items on this list (for reference, also in the attached PDF):',

    // Expiry dates
    edTitle: '📅 Expiry Dates',
    edHint: 'The full list, every lot in stock in one flat table — for looking up a specific product/lot. Grouped by location instead? See 🔍 Lot Tracking. Euro value at risk instead? See ⏳ Expiry Focus.',
    edLegend: 'Legend:',
    edExpired: 'expired',
    edNone: 'No expiry dates entered',

    // Lot tracking
    ltTitle: '🔍 Lot Tracking by Location',
    ltHint: 'Same lot data as 📅 Expiry Dates, grouped per location — useful for checking what\'s physically in a specific cabinet.',
    ltNone: 'No lots in stock',

    // Usage per procedure
    usTitle: '👨‍⚕️ Usage per Procedure',
    usHint: 'Grouped by procedure — showing what is used on average per procedure, across all specialists. Rows scanned together in one Material Usage submission count as one procedure.',
    usNone: 'No usage data available',
    usProcedureCount: '{n} procedures',
    usAvgItems: 'avg. {n} items/procedure',
    usAvgCost: 'avg. €{bedrag}/procedure',
    colTotal: 'Total',
    colUsedIn: 'Used in',
    colAvgPerProcedure: 'Avg./procedure',
    colCostPrice: 'Cost price',

    // To order (manual)
    toTitle: '📋 To Order (Manual)',
    toAllSufficient: 'All products have sufficient stock',
    colArticleRef: 'Article Ref',
    colTotalNeeded: 'Total Needed',
    toOrdered: 'Ordered',
    toOrder2: 'Order',

    // Stock value
    svTotalValue: 'Total stock value',
    svByLocation: '📍 By Location',
    svByCategory: '🏷️ By Category',
    colNumProducts: 'Products',
    colTotalUnits: 'Total units',

    // Action center
    acVerlopen: 'Expired lots',
    acUrgent: 'Urgent use (< 3 mo)',
    acEmpty: 'Empty',
    acLow: 'Low',
    acOpenOrders: 'Open orders',
    // Tegels zijn nu klikbaar en linken door naar het
    // scherm met de bijbehorende lijst.
    acCockpitHint: 'Click a tile to see the underlying list.',
    acNothing: 'Nothing needs attention right now',
    acExpiredDiscard: 'Expired - discard',
    acUrgentUse: 'Urgent use (< 3 months)',
    acEmptyStock: 'Empty stock',
    acLowStock: 'Low stock',
    acOpenOrdersTitle: 'Open orders',

    // Expiry focus
    vfHint: 'Same lot data as 📅 Expiry Dates, but framed as euro value at risk instead of a flat list — for deciding what\'s worth prioritizing.',
    vfExpired: 'Expired',
    vfExpiresLt3: 'Expires < 3 mo',
    vfExpiresLt6: 'Expires < 6 mo',
    vfExpiresLt12: 'Expires < 12 mo',
    vfLots: 'lots',
    vfFilterAll: 'All (< 12 mo)',
    vfFilterExpired: 'Expired',
    vfFilterLt3: '< 3 mo',
    vfFilterLt6: '< 6 mo',
    vfNoneSelected: 'No lots in this selection',
    vfTotal: 'Total ({n} lots)',

    // Categories
    cgClickHint: 'Click a category for the product list.',
    cgTotal: 'Total',
    cgNoneInCategory: 'No products in this category',
    cgLoten6FilterHint: 'Showing only products in this category with a lot expiring within 6 months.',
    cgNoneLoten6: 'No products with a lot expiring within 6 months in this category.',
    cgBackToCategories: '← Categories',

    // Open orders
    ooRegularTitle: '📦 Regular Orders',
    ooNone: 'No open orders',
    ooRedHint: 'Red day count = open longer than 14 days.',
    // Order detail
    odDetailTitle: 'Order details',
    odStatusHint: 'Status: {status} (calculated from ordered/received, not editable directly).',
    odViewProduct: 'View product',
    odTransactionsTitle: 'Transactions around this order',
    odTransactionsHint: 'Stock transactions for this product between when this order was placed and when it was last received (or now, if still open). Note: transactielog has no direct link to a specific order, so if multiple orders for the same product were open at the same time, a transaction may show up under more than one of them.',
    odNoTransactions: 'No stock transactions recorded in this order\'s timeframe yet.',
    // 2e correctie: 'units' was ook fout -- n telt
    // het aantal transactielog-REGELS in deze batch (item.aantalRegels /
    // item.regels.length), niet het aantal stuks/eenheden. Consistent met
    // rtViewLines ("View lines ({n})") elders in de app.
    odBekijkLots: 'View {n} lines',
    ooOneOffTitle: '🏥 One-off Orders',
    ooOneOffHint: 'Patient-bound requests (🏥 One-off Order) that aren\'t Used or Returned yet. Click a row to see the full details (including notes) and change its status from there — once it\'s set to Used or Returned, the row disappears from this overview.',
    ooOneOffNone: 'No open one-off orders',
    ebDetailTitle: 'One-off Order Details',
    ebEditTitle: 'Edit One-off Order',

    // Usage trends
    trUsagePerMonth: 'Usage per month per category',
    trNoData: 'No usage data yet',
    trDrillHint: 'Products that make up this figure.',
    trDrillNone: 'No products found for this selection.',
    colMonth: 'Month',
    trSlowMovers: 'Slow movers (no usage in 90 days)',
    trNoSlowMovers: 'No slow movers - all stock is moving',
    trCapitalTied: 'Capital tied up in slow movers',
    trItems: 'items',
    colLastUsage: 'Last usage',
    trNever: 'Never',
    trDaysAbbrev: 'd',
    trSlowMoverHint: 'Candidates for lower min/max values or return. Becomes more reliable as more usage data accumulates.',

    trAdjustmentsTitle: 'Stock adjustments per month (from counts)',
    trAdjustmentsHint: 'Surplus/shortage corrections booked during a stock count (Tasks → Start count). Frequent shortages can be a sign of missed scans rather than stock actually disappearing.',
    colSurplus: 'Surplus',
    colShortage: 'Shortage',
    trWriteOffsTitle: 'Expired write-offs per month',
    trWriteOffsHint: 'Same data as the Write-offs dashboard, broken down by month, so you can see whether it\'s trending up or down.',
    trSavingsTitle: '💰 Savings: used just before expiry',
    trSavingsHint: 'Material that was used when it had 90 days or less of shelf life left -- a good candidate for an expiry write-off if it hadn\'t been used in time. Value is an estimate using the price snapshot at the moment of use, not a comparison against a separate forecast.',
    colWithin30: 'Used ≤30 days before expiry',
    colWithin60: 'Used 31-60 days before expiry',
    colWithin90: 'Used 61-90 days before expiry',
    trSavingsTotal: 'Estimated savings (last 12 months)',

    // Error log
    flNone: 'No errors logged',
    flHint: 'Last {n} errors, newest first.',
    // Vertaling van de vaste EAN_NOT_IN_ARTIKELEN-foutlogregel,
    // zie foutlogBerichtLabel() in app.js.
    flBerichtEanOnbekend: 'Product not found in articles',
    // ("Alleen JPG, PNG of WEBP-afbeeldingen
    // zijn toegestaan" bleef Nederlands): vertaling van de vaste
    // upload-validatiemeldingen uit includes/upload.php::decodeFotoDataUrl(),
    // zie apiErrorLabel() in app.js.
    apiErrAfbeeldingType: 'Only JPG, PNG or WEBP images are allowed',
    apiErrAfbeeldingOngeldig: 'No valid image received',
    apiErrAfbeeldingOnleesbaar: 'Could not read the image',
    apiErrAfbeeldingGeenAfbeelding: 'File is not a valid image',
    colTime: 'Time',
    colErrorType: 'Error type',
    colBarcodeCol: 'Barcode',
    colMessage: 'Message',

    // Order modal / bestelling
    omFillOrderer: 'Please fill in "Ordered by"',
    omErrorPrefix: 'Error: ',

    // Receive stock
    roHint: 'Scan the barcode(s) of incoming products. One line per product -- the scanner "types" barcode + Enter, same as before.',
    roLocation: 'Location',
    roScans: 'GS1 scans (one per line)',
    roNote: 'Note (optional)',
    roSave: 'Save',
    roProcessed: 'Processed: {n}, skipped: {m}',

    // Scan usage
    suNotEnough: 'Not enough specialists/procedures set up yet. Add them via ⚙️ Admin → Master Data.',
    suLocationUsed: 'Location used',
    suSpecialist: 'Specialist',
    suPiccHint: 'The list of names shown here depends on the procedure chosen below -- a Lab technician only appears once a procedure marked "also allowed for Lab technician" is selected (in practice, PICC line placements).',
    suProcedure: 'Procedure type',
    kkGeenOpties: 'None set up yet',
    suScans: 'GS1 scans (one per line)',
    suNote: 'Note (optional)',
    suRegister: 'Register',
    suOmgeleidTitel: 'ℹ️ Automatically booked from a different room:',
    suOmgeleidRegel: '{naam}: from {naar} (no stock in {van})',
    suProcessed: 'Processed: {n}, skipped: {m}',

    // Write off expired
    wfHint: 'For products that have reached their maximum shelf life. This removes them from stock with their own record (separate from normal usage) — see 📉 Write-offs & Shortages for the history.',
    wfLocation: 'Location',
    wfScans: 'GS1 scans (one per line)',
    wfNote: 'Note (optional)',
    wfSubmit: 'Write off',
    wfWrittenOff: 'Written off: {n}, skipped: {m}',

    // Transfer + returns
    tfWhatToDo: 'What do you want to do?',
    tfTransfer: 'Transfer',
    tfReturn: 'Return',
    tfScans: 'GS1 scans (one per line)',
    tfFromLocation: 'From location',
    tfToLocation: 'To location',
    tfReason: 'Reason',
    tfNote: 'Note (optional)',
    tfSave: 'Save',
    tfProcessed: 'Processed: {n}',

    // One-off order
    oeHint: 'For unique/custom material for one specific patient. Patient number is typed by hand (personal data, handle with care) -- once the product arrives, scan its GS1 barcode below to record the EAN, lot and expiry date. Track status and change it (Requested → Ordered → Received → Used/Returned) from 🚚 Open Orders.',
    oePatientNumber: 'Patient number',
    oeProductDescription: 'Product description',
    oeGs1Scan: 'Scan GS1 barcode(s) (once received)',
    oeGs1Placeholder: 'Scan here, one barcode per line...',
    oeGs1Hint: 'Optional -- usually empty when the order is first requested. Scan the product\'s barcode(s) once it arrives to fill in EAN, lot and expiry date automatically (overrides the fields below). A larger quantity can arrive spread across several lots -- scan one barcode per line and every lot number gets recorded, with the earliest expiry date of the batch kept as the one that matters most.',
    oeQuantity: 'Quantity needed',
    oeProcedureType: 'Procedure type',
    oeSelectProcedure: '-- select procedure --',
    oeSpecialist: 'Specialist',
    oeSelectSpecialist: '-- select specialist --',
    oeOrderedByHint: 'Ordered by: {naam} (yourself, based on your login)',
    oeNote: 'Note (optional)',
    oeRequest: 'Request',
    oeViewOverview: 'View overview',
    oeRequiredFields: 'Patient number and product description are required',
    oeRequested: 'One-off order requested',
    oeRecentRequests: 'Recent requests',
    oeNoneYet: 'No one-off orders yet',
    ebAantalHint: 'Filled in automatically from the number of GS1 barcodes scanned above -- not typed by hand.',
    ebItemsTitle: 'Scanned items',
    ebItemsEmpty: 'No items scanned yet -- scan a GS1 barcode above and save to add the first one.',
    ebItemOnbekend: 'Unrecognized product',
    ebStatusOntvangen: 'Received',
    ebStatusVerbruikt: 'Used',
    ebStatusRetour: 'Returned',
    ebMailSend: '📧 Send email',
    ebMailNoSpecialist: 'No specialist set on this order -- the "To" field will be empty, fill it in yourself.',
    ebMailNoEmail: 'No email address on file for {naam} (set one in ⚙️ Admin → Master Data) -- the "To" field will be empty, fill it in yourself.',
    ebMailSubject: 'One-off order #{id}: {omschrijving} -- status: {status}',
    ebMailLineOrder: 'Order: #{id}',
    ebMailLinePatient: 'Patient no.: {patientnummer}',
    ebMailLineDescription: 'Product: {omschrijving}',
    ebMailLineEan: 'EAN: {ean}',
    ebMailLineQuantity: 'Quantity: {n}',
    ebMailLineProcedure: 'Procedure: {procedure}',
    ebMailLineStatus: 'Status: {status}',
    ebMailLineOrderedBy: 'Ordered by: {naam}',
    colPatientNo: 'Patient no.',
    colDescription: 'Description',

    // Add product
    apHint: 'GS1 is parsed on save for the EAN -- no separate edit step needed like in the old sheet version. The article number is never derived from the scan; fill that in yourself below if you track one.',
    // Getoond als dit formulier geopend is via "Create a new
    // product as replacement..." op het bewerkscherm van een ander product.
    apVervangerHint: 'This product is being created as a replacement for another (currently not deliverable) product -- its own EAN/barcode is required below, since this is a different physical product.',
    apGs1Scan: 'GS1 scan (the EAN below is filled in automatically)',
    apEanManual: 'EAN (fallback, only if no GS1 scan is available)',
    apEanAutoHint: 'Filled in automatically once a GS1 barcode is scanned above -- only type here directly if no GS1 is available for this product.',
    apProductName: 'Product name',
    apCategory: 'Category',
    apOrderType: 'Order type',
    apOrderTypeAuto: 'Automatic',
    apOrderTypeManual: 'Manual',
    // Derde besteltype voor producten
    // die puur bestaan omdat ze ooit binnen een Eenmalige Bestelling
    // (patiëntgebonden aanvraag) gescand/aangemaakt zijn -- zie
    // verwerkAchterstalligeEenmaligeBestellingOntvangsten() in
    // includes/eenmalige_bestellingen.php.
    apOrderTypeOnce: 'One-off',
    apDoosTitle: '\u{1F4E6} Box packaging (optional)',
    apDoosHint: 'Only fill this in if this product is also delivered in a sealed box of several units (e.g. a box of 5 guidewires or PICC lines) -- leave both fields empty if it isn\'t. Scanning the box barcode will then automatically book the right number of units at once, at Goods Receipt, Transfer + Returns, Material Usage and Stock Count alike.',
    apDoosGs1Scan: 'Box GS1 scan (the EAN below is filled in automatically)',
    apDoosEan: 'Box EAN/barcode (fallback, only if no GS1 is available)',
    apEenhedenPerDoos: 'Units per box',
    doosSuffix: '= {n} boxes',
    apLogistiekTitle: '🏷️ Internal logistics code (optional)',
    apLogistiekHint: "Only fill this in if you want your own printable barcode label for this product (e.g. for a shelf label), separate from the manufacturer's EAN. Fully manual -- always entered by hand, never generated automatically. Scanning the printed label will be recognized the same as the product's own EAN, anywhere a barcode is scanned.",
    apLogistiekCode: 'Internal logistics code',
    apMinMax: 'Min/max per location',
    apMin: 'Min ',
    apMax: 'Max ',
    apManufacturer: 'Manufacturer',
    apManufacturerNone: '-- no manufacturer / unknown --',
    apManufacturerNew: '+ Add new manufacturer...',
    apManufacturerNewPlaceholder: 'New manufacturer name',
    apSpecNone: '-- select --',
    apSpecNew: '+ Add new value...',
    apSpecNewPlaceholder: 'New value',
    apPricePerUnit: 'Price per unit (EUR)',
    apAdd: 'Add',
    apAdded: 'Product added (EAN {ean})',
    catStent: 'Stent',
    catGuidewire: 'Guidewire',
    catEmbolisation: 'Embolisation',
    catSheath: 'Sheath',
    catBalloon: 'Balloon',
    catCatheter: 'Catheter/Closure',
    catOther: 'Other',

    // Master data
    mdHint: 'This list appears as a dropdown in Material Usage and One-off Order. Deactivate rather than delete, so old transactions stay readable. Specialists and Lab technicians are managed on the Users screen instead -- see Admin → Users.',
    mdNothingAdded: 'Nothing added yet',
    mdProcedureTypes: '🏥 Procedure types',
    mdLaborantToegestaan: 'Also allowed for Lab technician',
    mdLaborantHint: 'Checking this for a procedure (in practice, PICC line placements) allows a Lab technician to be picked as the performer of that procedure on the Material Usage form -- unchecked procedures require a Specialist.',
    mdNewProcedure: 'New procedure',
    mdActivate: 'Activate',
    mdDeactivate: 'Deactivate',
    mdRemove: 'Remove',
    // Beheerde categorielijst + categorie-specifieke
    // eigenschappen (bijv. Ballon -> diameter/lengte/compatibel voerdraad).
    mdCategorieen: '📁 Categories & properties',
    mdCategorieenHint: 'Which product properties (diameter, length, French size, ...) apply depends on the category. A deactivated category disappears from the list when adding a new product, but existing products keep working.',
    mdNoSpecs: 'No properties defined for this category yet.',
    mdNewSpecPlaceholder: 'New property (e.g. Diameter (mm))',
    mdNewCategorie: 'New category',
    mdSaved: 'Saved',

    // Locations
    lcHint: 'Stocuro has four location "slots" with a fixed role (two general locations, one mobile cart with no fixed cabinet, and one central/replenishment location) -- each slot\'s name is freely editable. Beyond that, you can add any number of additional locations below; they behave like a general location (their own min/max per product, included in the shopping list).',
    lcRoleGeneral1: 'General location 1',
    lcRoleGeneral2: 'General location 2',
    lcRoleMobile: 'Mobile (no fixed cabinet)',
    lcRoleCentral: 'Central / replenishment source',
    lcAdditional: 'Additional location',
    lcAddNew: 'Add a new location',
    lcNamePlaceholder: 'e.g. Room C',
    lcAddButton: 'Add location',

    // Users
    guHint: 'Language is an account setting each user can change themselves (language selector next to Sign out) — it\'s only set here at account creation.',
    guNewUser: 'New user',
    guTempPassword: 'Temporary password (min. 8 characters)',
    guRoleSpecialist: 'Specialist',
    guRoleLaborant: 'Lab technician',
    guRoleBekijker: 'Viewer',
    guFunctie: 'Function',
    guFunctieHint: 'Specialist and Lab technician can both scan/order (full access); Viewer can only look at the data. A Lab technician may only be picked as the performer of a procedure that has been marked "also allowed for Lab technician" in Master Data (in practice, PICC line placements) -- any other procedure requires a Specialist. The Admin checkbox is an extra flag on top of the function: an Admin can always manage Users/Locations/Manufacturers/Master Data, regardless of function.',
    guBeheerderVlag: 'Admin',
    guLicentie: 'License',
    guLicentieVrij: 'Free',
    guLicentieGeldigTot: 'Valid until {datum}',
    guLicentieGeblokkeerd: 'No valid license',
    guLicentieKoppelen: 'Link code',
    guLicentieCodePrompt: 'Paste the activation code for {naam}:',
    guLicentieEmailPrompt: 'Confirm the order/purchase email this code was issued for (see your purchase confirmation email):',
    guLicentieGekoppeld: 'Code linked.',
    // Nieuw
    // account aanmaken vereist nu meteen een activatiecode, zie het
    // aanmaakformulier in renderGebruikers() en api/gebruikers.php.
    guLicentieRegistratieTitle: 'License activation',
    guLicentieRegistratieHint: 'A new account can only be created together with a valid activation code -- paste the code you received by e-mail after purchase, and confirm the order e-mail address it was issued for.',
    guLicentieCodeLabel: 'Activation code',
    guLicentieEmailLabel: 'Order e-mail (confirmation)',
    // ("profielpagina"):
    // eigen accountgegevens + licentiestatus + wachtwoord wijzigen, zie
    // showProfiel()/renderProfiel() in dit bestand + api/mijn_profiel.php.
    profTitle: '👤 My profile',
    profAccountTitle: 'Account',
    profTaalHint: 'Change your language preference next to Sign out, in the header.',
    profLicentieTitle: 'License',
    profLicentieBlokkadeHint: 'Contact your administrator if you believe this is incorrect -- only an administrator can link a new activation code to your account (Users screen).',
    profWachtwoordTitle: 'Change password',
    profHuidigWachtwoord: 'Current password',
    profNieuwWachtwoord: 'New password (min. 8 characters)',
    profWachtwoordOpslaan: 'Change password',
    // Zichtbare waarschuwing als er
    // meer dan 1 geldig lokaal gratis-token bestaat -- zou nooit moeten
    // gebeuren via de normale schermen, alleen bij directe database-
    // manipulatie met het lokale geheim. Zie includes/license.php::
    // telAantalGeldigeVrijeTokens().
    guLicentieAnomalie: 'Warning: {aantal} valid free-user tokens found on this installation (expected at most 1). Only the account with the lowest ID counts as the legitimate free user; check license_activations if this is unexpected.',
    // An admin must be able to reset another user's password (no "forgot
    // password" e-mail flow exists in this app, by design -- see README
    // "Licensing & self-hosted distribution").
    guWachtwoordResetten: 'Reset password',
    guWachtwoordResetPrompt: 'New password for {naam} (min. 8 characters):',
    guWachtwoordTeKort: 'Password must be at least 8 characters.',
    guWachtwoordResetGelukt: 'Password reset. Share the new password with the user yourself -- it is not e-mailed automatically.',
    guLegacyTitle: 'Not yet migrated',
    guLegacyHint: 'Names from the old Master Data specialist list that don\'t have a login account yet. "Create account" pre-fills the name below -- fill in the rest and create the account.',
    guLegacyCreateAccount: 'Create account',
    guLanguage: 'Language',
    guCreate: 'Create',
    // Groepen zijn LOS van de functie hierboven -- functie
    // blijft klinisch (PICC-regel/leaderboard/Mijn Bestellingen), groepen
    // bepalen alleen scherm-zichtbaarheid en schrijftoegang. Zie
    // includes/groepen.php voor het volledige ontwerp.
    guGroepen: 'Groups',
    guGeenGroepen: 'No groups exist yet -- create one on the Groups screen.',
    guNaarGroepen: 'Manage groups →',
    // ("blijft heel
    // erg lelijk en onoverzichtelijk"): Groepen-cel toont voortaan compacte
    // chips + een wijzigen-knop i.p.v. een altijd-uitgeklapt kadertje, en
    // Beheerder+Actief zijn samengevoegd tot één "Rights"-kolom.
    guRechten: 'Rights',
    guGeenGroepenGekozen: 'No groups',
    guGroepenWijzigen: 'Change groups',
    guGroepenSluiten: 'Close groups',

    // ("Het wijzigen van gebruikers
    // gegevens moet via een knop i.p.v. een tekstveld die ik direct kan
    // aanpassen"): naam/e-mail staan standaard read-only (zie renderGebruikers()
    // in app.js) en worden pas bewerkbaar na een expliciete klik op deze
    // knop -- zelfde toggle-patroon als guGroepenWijzigen/guGroepenSluiten
    // hierboven.
    guNaamEmailWijzigen: 'Edit name/email',
    guNaamEmailSluiten: 'Done editing',

    // Groups: who can see/access which screens, and who can
    // write, is decided per group rather than per fixed function -- see
    // includes/groepen.php for the full two-axis design (function stays
    // clinical, groups are purely about screen access).
    groepenTitle: 'Groups',
    groepenHint: 'A group decides which screens its members can see (and whether they may write/scan/edit). A user can belong to more than one group at once -- their access is the combination of all their groups. Admin accounts always see and can edit everything, regardless of group.',
    groepenEmpty: 'No groups yet -- create one below.',
    groepNaam: 'Name',
    groepMagSchrijven: 'May write (scan/order/edit)',
    groepAantalLeden: 'Members',
    groepAantalSchermen: 'Screens',
    groepNieuw: '+ New group',
    groepSchermenTitel: 'Which screens can this group see?',
    groepSectieFormulieren: 'Forms',
    groepSectieDashboards: 'Dashboards',
    groepSectieBeheer: 'Admin',
    groepLeden: 'Members of this group',
    groepLandingSchermTitel: 'Default landing screen',
    groepLandingSchermHint: 'What members of this group see right after logging in, instead of the main menu. Only screens checked above are available. If a member belongs to several groups with different landing screens, they get the main menu instead.',
    groepLandingSchermHome: 'Home (main menu)',
    groepVerwijderenConfirm: 'Delete this group? Members lose access to its screens immediately (unless another one of their groups also grants it).',

    // Specialist self-service (rol 'specialist'/'laborant', volledige toegang -- zie includes/auth.php)
    mbTitle: 'My Orders',
    mbHint: 'One-off orders placed under your name, most recent first. Read-only -- contact the ordering team to make changes.',
    mbNone: 'No one-off orders found under your name yet.',

    // Profile photos (users + producent_contacten)
    colPhoto: 'Photo',
    fotoUpload: 'Photo',
    fotoRemove: 'Remove photo',
    fotoInvalidType: 'Please choose a JPG, PNG or WEBP image',
    fotoTooBig: 'Image is too large (max 2MB)',
    fotoRemoveConfirm: 'Remove this photo?',

    // Leaderboard
    leaderboard: '🏆 Leaderboard',
    lbHint: 'Two separate rankings: who performed the most actions (scans, transfers, stock counts) under their own account, and who performed the most procedures of a given type -- useful for a fair rotation (e.g. "who\'s next for a PICC line").',
    lbPeriod: 'Period',
    lbPeriodWeek: 'Last 7 days',
    lbPeriodMonth: 'Last 30 days',
    lbPeriodQuarter: 'Last 90 days',
    lbPeriodYear: 'Last 365 days',
    lbPeriodTotal: 'All time',
    lbPiccTitle: '🔄 Who\'s up next',
    lbPiccHint: 'Full rotation queue per PICC-eligible procedure -- whoever hasn\'t done it the longest goes first (never-done sorts first). Independent of the period filter above.',
    lbPiccGeenLaboranten: 'No lab technicians available for this procedure.',
    lbPiccLaatstUitgevoerd: 'Last performed',
    lbPiccNooitGedaan: 'Never',
    lbScansTitle: '📋 Most scans',
    lbScansHint: 'Every scan/transfer/return/stock count/write-off, counted by the account that performed it.',
    lbNoScans: 'No scans recorded (yet) in this period.',
    lbProceduresTitle: '👨‍⚕️ Most procedures',
    lbProceduresHint: 'Usage registrations ("Material Usage"), counted per specialist -- filter by procedure type to see who\'s done the most of a specific one (e.g. PICC lines).',
    lbFilterProcedure: 'Procedure type',
    lbAllProcedures: 'All procedure types',
    lbNoProcedures: 'No procedures recorded (yet) in this period/selection.',
    colCount: 'Count',
    mdType: 'Type',
    mdTypeArts: 'Doctor',
    mdTypeLaborant: 'Lab technician',
    mdTypeOverig: 'Other',

    minmaxAdvies: '📦 Min/Max Advies',
    mmHint: 'Suggested stock levels per product and location, based on historical usage, peak usage, shelf life and lead time -- we never want more stock on hand than necessary. Two formulas, depending on the location\'s role: rooms are guaranteed to be refilled to their maximum from Storage the same day, so a separate reorder minimum never had a function there -- only a maximum (par level, sized on the busiest single day seen) applies. Storage is the only location that actually waits on an external supplier, so it still has a real reorder minimum (average weekly usage x (lead time in weeks + a safety buffer)) alongside its maximum (min + peak weekly usage x a coverage window). Both maximums are capped so the suggestion never exceeds what could realistically be used before the product\'s average shelf life runs out. Nothing is applied automatically -- review each suggestion and apply it yourself, per row.',
    mmEmpty: 'No product/location combinations with a min or max currently configured.',
    mmCurrentMin: 'Current min',
    mmCurrentMax: 'Current max',
    mmAdvisedMin: 'Advised min',
    mmAdvisedMax: 'Advised max',
    // Rooms no longer have a minimum (see mmHint above) --
    // shown in the Current min/Advised min columns for room rows.
    mmNotApplicable: 'n/a',
    mmBasis: 'Basis',
    mmBasisDayStock: 'Day-stock ({dagen}d): avg {gemDag}/day, peak {piekDag}/day',
    mmBasisLeadTime: 'Lead time {leverDagen}d ({bron}): avg {gemWeek}/wk, peak {piekWeek}/wk',
    mmShelfLife: 'Shelf life',
    mmDays: 'days',
    mmMonths: 'months',
    mmUnknown: 'unknown',
    mmLeadTimeSource_product: 'this product\'s own order history',
    mmLeadTimeSource_globaal: 'global average across all products',
    mmLeadTimeSource_default: 'default assumption',
    mmInsufficientData: 'Not enough history yet ({weken} of the required {minimum} weeks) for a reliable suggestion.',
    mmApply: 'Apply',
    mmApplying: 'Applying...',
    mmApplied: 'Applied',
    mmErrorApplying: 'Error applying suggestion: ',
    mmNegeer: 'Dismiss',
    pdAdvies: 'Suggestion',
    pdAdviesOptimaal: 'Already optimal',
    pdAdviesGeen: '—',
    vaHint: 'Stock that\'s unlikely to be used up before its expiry date at its current location, with the active room that uses this product faster (so it\'s more likely to be used up in time) -- moving it there is a physical action via the Transfer form, this is advice only, nothing is moved automatically. Storage is never suggested as the destination, since that\'s where rooms are refilled from, not a consumption point.',
    vaEmpty: 'Nothing at risk of expiring before it would be used up at its current location right now.',
    vaDaysLeft: 'Days left',
    vaCurrentUsage: 'Current usage here',
    vaRecommendedLocation: 'Recommended location',
    vaPerDay: '{n}/day',
    vaInsufficientData: 'not enough usage history',
    vaNoFasterLocation: 'No faster location found',
  },
  nl: {
    signOut: 'Uitloggen',
    dashboards: 'Dashboards',
    hwMijnBestellingen: '📦 Mijn Bestellingen',
    hwLoadError: 'Kon dit nu niet laden.',
    hwGeenOpenBestellingen: 'Op dit moment geen open bestellingen.',
    hwEnMeer: '+ {n} meer',
    hwNaarMijnBestellingen: 'Bekijk al mijn bestellingen',
    hwNieuws: '📰 Nieuws',
    hwGeenNieuws: 'Op dit moment geen nieuws.',
    hwNieuwProduct: 'Nieuw: {naam}',
    hwVervanger: '{naam} wordt tijdelijk vervangen door {vervanger}',
    hwNaarNieuws: 'Bekijk volledig nieuws',
    hwNieuweProductenTitle: '🆕 Nieuwe producten',
    hwNieuweProductenEmpty: 'Geen recent toegevoegde producten.',
    hwPiccTitle: '🩺 Wie is aan de beurt',
    hwGeenPiccProcedures: 'Nog geen laborant-procedures ingesteld.',
    // ("uitbreiden met foto en minimaal 3 personen op
    // volgorde van wie aan de beurt is"): badge onder de eerste persoon in
    // de rotatie-rij, zie piccRotatieHtml() in assets/js/app.js.
    hwPiccUpNext: 'eerst aan de beurt',
    hwAanDeBeurt: '{procedure}: {naam}',
    hwNaarLeaderboard: 'Bekijk volledig leaderboard',
    hwTopScanners: '🏆 Top 10 Scanners (deze maand)',
    hwTakenTitle: '✅ Taken',
    hwGeenTaken: 'Op dit moment geen open taken.',
    hwTakenKastcontrole: '{n} locatie(s) toe aan een voorraadtelling',
    hwTakenVervallen: '{n} vervallen lot(en) nog op voorraad',
    hwTakenOnbekend: '{n} onbekende barcode(s) om af te ronden',
    hwTakenOnvolledig: '{n} product(en) met ontbrekende gegevens',
    hwTakenBatch: '{n} item(s) zonder batch-/vervaldatumgegevens',
    hwNaarTaken: 'Bekijk alle taken',
    hwGebruikersLicentieTitle: '🔑 Gebruikers & licentie',
    hwGebruikersTotaal: '{n} gebruikersaccount(s) in totaal',
    hwGebruikersLicentieGeldig: '{n} met een geldige licentie',
    hwGebruikersLicentieGeblokkeerd: '{n} zonder geldige licentie (kunnen niet inloggen)',
    hwNaarGebruikers: 'Beheer gebruikers',
    hwVervalfocusTitle: '⏰ Vervaldatum-risico',
    hwVervalfocusEmpty: 'Op dit moment geen loten die richting de vervaldatum gaan.',
    hwVervalfocusSummary: '{n} lot(en) al vervallen, ter waarde van €{waarde}.',
    hwNaarVervalfocus: 'Bekijk volledig vervaldatumoverzicht',
    hwVerplaatsadviesTitle: '🔀 Verplaatsadvies',
    hwVerplaatsadviesEmpty: 'Op dit moment geen verplaatsadviezen.',
    hwNaarVerplaatsadvies: 'Bekijk volledig verplaatsadvies',
    groupActionTasks: 'Actie & Taken',
    groupStockLevels: 'Voorraadniveaus',
    groupExpiryLots: 'Vervaldatum & Loten',
    groupOrdering: 'Bestellen',
    groupReporting: 'Rapportage',
    actionCenter: '⚡ Actiecentrum',
    stockHub: '📦 Voorraad',
    bvTitle: '🔄 Bijvullen',
    bvHint: 'Producten onder het minimum die Berging ({berging}) nu kan leveren — scan hieronder om ze te verplaatsen.',
    bvHintGeenBerging: 'Er is geen berging-locatie ingesteld, dus hier kan niets verplaatst worden.',
    bvAllesOpPeil: 'Alle locaties hebben voldoende voorraad — nu niets bij te vullen.',
    bvAantalProducten: '{n} product(en)',
    bvColHuidig: 'Huidig',
    bvColBijvullen: 'Bij te vullen',
    bvColVanaf: 'Vanuit (kastlocatie)',
    bvColNaar: 'Naar (kastlocatie)',
    bvScanTitle: 'Scannen om te verplaatsen',
    bvScanHint: 'Scan de barcodes van de dozen die je van Berging naar onderstaande locatie verplaatst.',
    bvBergingLeeg: '⚠️ Tekort van {n} — Berging heeft zelf nog geen voorraad om vanuit te verplaatsen.',
    overview: '📦 Overzicht',
    recentTransactions: '📋 Recente Transacties',
    tasks: '🗒️ Taken',
    tasksAdmin: '🗒️ Taken (Beheerder)',
    tasksLab: '🗒️ Taken (Laborant)',
    writeOffs: '📉 Afschrijvingen',
    shoppingList: '🛒 Boodschappenlijst',
    refillList: '📦 Bijvullen',
    storage: '📦 Bestellijst Berging',
    expiryLotsHub: '📅 Vervaldatums & Loten',
    ordersHub: '🛒 Bestellingen',
    usageHub: '📈 Verbruik & Trends',
    expiryDatesDetail: '📅 Vervaldatums Detail',
    lotTracking: '🔍 Lot-tracking',
    usage: '👨‍⚕️ Verbruik per Procedure',
    toOrder: '📋 Te Bestellen',
    stockValue: '💰 Voorraadwaarde',
    productsHub: '🔎 Producten',
    searchProducts: '🔎 Product Zoeken',
    producenten: '🏭 Producenten',
    teamPerformanceHub: '🏆 Team & Prestaties',
    newsTeam: '📰 Nieuws & Team',
    newsTitle: '📰 Nieuws',
    newsNewestProducts: 'Nieuwste producten',
    newsNoProducts: 'Nog geen producten.',
    newsUnavailableReplaced: 'Niet leverbaar, tijdelijk vervangen door',
    newsNoReplacements: 'Op dit moment geen niet-leverbare producten met een vervanger.',
    newsReplacedBy: 'Vervangen door',
    newsTeamTitle: '👥 Het Team',
    newsSpecialists: 'Specialisten',
    newsLabTechs: 'Laboranten',
    newsNoTeamMembers: 'Nog niemand om te tonen.',
    newsContactsTitle: '📇 Contactpersonen',
    newsNoContacts: 'Nog geen contactpersonen vastgelegd.',
    beschikbaarheidTitle: '🩺 Beschikbaarheid',
    bhHint: 'Wat is er, hoeveel, waar, wat is de status, en het recente verbruik -- allemaal in één overzicht. Klik op een product voor het volledige detail.',
    bhTotalUnits: 'Totaal stuks',
    bhUsageDays: 'Verbruik (laatste {n}d)',
    managementCockpitTitle: '📊 Rapportage',
    mcHint: 'De belangrijkste cijfers uit Voorraadwaarde, Verbruik & Trends en Team & Prestaties samengevoegd op één pagina.',
    mcTopCategories: 'Categorieën met hoogste waarde',
    mcSeeFullValue: 'Bekijk volledige voorraadwaarde',
    mcLatestMonth: 'Laatste maand ({maand})',
    mcUsageTotal: 'Totaal verbruik',
    mcSeeFullTrends: 'Bekijk volledig verbruik & trends',
    mcTeamTitle: 'Meest gescand deze maand',
    mcSeeFullTeam: 'Bekijk volledig team & prestaties',
    rpDimVoorraad: '📦 Voorraad',
    rpDimVerbruik: '📈 Verbruik',
    rpDimTeam: '👥 Team',
    rpDimActies: '⚡ Acties',
    rpDimLicentie: '🔑 Licentie',
    rpActiesHint: 'Openstaande adviezen en tekorten die aandacht vragen -- elke tegel linkt door naar het volledige dashboard.',
    rpAfboekingenVervallen: 'Vervallen (laatste jaar)',
    rpAfboekingenTekort: 'Tekorten (laatste jaar)',
    sidebarHome: 'Home',
    sidebarSearchPlaceholder: '🔍 Zoeken...',
    vtHint: 'Eén tabel voor alles over de huidige voorraad -- filter op wat aandacht vraagt, of zet extra kolommen aan waar nodig.',
    vtColAdvies: 'Min/max-advies',
    vtColUsage: 'Recent verbruik',
    vtColProducer: 'Producent',
    vtColKastlocatie: 'Kastlocatie',
    vtEmpty: 'Geen producten die bij dit filter/deze zoekopdracht passen.',
    vtFilterAlles: 'Alles',
    vtFilterLeeg: '🔴 Leeg',
    vtFilterLaag: '🟡 Laag',
    vtFilterBijvullen: '📦 Kan bijgevuld worden',
    vtFilterBerging: '🚚 Berging: moet besteld',
    vtFilterHandmatig: '🛒 Handmatig bestellen',
    vtFilterVervalt: '📅 Vervalt binnen 6mnd',
    vtFilterAdvies: '📈 Advies wijkt af',
    vtFilterVerplaats: '↔️ Verplaatsadvies',
    vtAdviesWaarde: 'min {min} / max {max}',
    vtVerplaatsNaar: '↔️ Verplaats naar {locatie} (nog {dagen}d)',
    vtBergingLeeg: '⚠️ Berging leeg',
    bsHint: 'Alles wat besteld moet worden of al in bestelling is, op één plek -- filter op status.',
    bsColType: 'Type',
    bsTypeHandmatig: 'Handmatige bestelling',
    bsTypeEenmalig: 'Eenmalige bestelling',
    bsTypeOrder: 'Reguliere order',
    bsEmpty: 'Geen bestellingen die bij dit filter passen.',
    bsFilterAlles: 'Alles',
    bsFilterTeBestellen: 'Nog te bestellen',
    bsFilterInBestelling: 'In bestelling',
    bsFilterGedeeltelijk: 'Gedeeltelijk ontvangen',
    bsFilterMijn: 'Van mij',
    ctTitle: '📇 Contacten',
    ctHint: 'Producenten en hun contactpersonen samen -- schakel hieronder tussen weergaven.',
    ctViewPersonen: 'Personen',
    ctViewBedrijven: 'Producenten',
    ctNoDetails: 'Geen telefoon, e-mail of notitie bekend voor deze contactpersoon.',
    tkTitle: '✅ Taken',
    expiryFocus: '⏳ Vervaldatum Focus',
    transferAdvice: '🔀 Verplaatsadvies',
    categories: '📁 Categorieën',
    openOrders: '🚚 Open Orders',
    usageTrends: '📈 Verbruikstrends',
    errorLog: '⚠️ Foutlog',
    unknownProducts: '❓ Onbekende Producten',
    opHint: 'Barcodes gescand bij Goederenontvangst, Verplaatsing + Retour, Materiaal verbruik, Vervallen Afschrijven of Tellen die niet bij een bekend product hoorden. Elke barcode wordt automatisch als een echt product aangemaakt, met de omschrijving die de scanner heeft ingevuld als tijdelijke naam -- het staat al in de voorraad en werkt al overal. Rond het hieronder af: vul de echte naam, categorie, prijs, etc. in.',
    opNone: 'Op dit moment geen onbekende barcodes.',
    opLastSeen: 'Laatst gezien',
    opMaakArtikel: 'Maak dit artikel aan',
    opAfronden: 'Product afmaken',
    onbTitel: 'Onbekende barcode(s)',
    onbUitleg: 'Deze barcodes zijn nog niet herkend. Vul per stuk een korte omschrijving in van wat het product is, zodat een beheerder het goed kan aanmaken -- er wordt niets geboekt totdat je dat doet.',
    onbPlaceholder: 'bijv. groene hechtset, Ethicon',
    onbIndienen: 'Opnieuw indienen',
    onbOmschrijvingen: 'Omschrijving(en)',
    forms: '📋 Formulieren',
    scanUsage: '✅ Materiaal verbruik',
    receiveStock: '📦 Goederenontvangst',
    transferReturns: '🔄 Verplaatsing + Retouren',
    manualOrder: '🛒 Handmatige Bestelling',
    oneOffOrder: '🎁 Eenmalige Bestelling',
    addProduct: '➕ Artikel Toevoegen',
    writeOffExpired: '🗑️ Vervallen Afschrijven',
    admin: '⚙️ Beheer',
    adminGroupAccess: 'Toegang',
    adminGroupSettings: 'Instellingen',
    adminGroupDataQuality: 'Datakwaliteit',
    users: '👥 Gebruikers',
    masterData: '🗂️ Stamgegevens',
    locations: '📍 Locaties',
    // Statische app.php-markup (modal, koppen) via data-i18n:
    logout: 'Uitloggen',
    loginLink: 'Inloggen',
    gastBannerText: '🔍 Je bekijkt een alleen-lezen demo -- niets wat je hier invoert of wijzigt wordt opgeslagen.',
    gastBannerLink: 'Log in met een test-account om te proberen te bewerken →',
    modalOrderTitle: '📦 Order aanmaken',
    product: 'Product:',
    quantity: 'Aantal:',
    orderedBy: 'Besteller:',
    channel: 'Kanaal:',
    channelPhone: 'Telefoon',
    channelOther: 'Anders',
    noteOptional: 'Opmerking (optioneel):',
    cancel: 'Annuleren',
    save: 'Opslaan',

    backLabel: '← Terug',
    errorLabel: 'Fout',
    somethingWrong: 'Er is iets misgegaan',
    loading: 'Bezig met laden...',
    editLabel: 'Bewerken',
    deleteLabel: 'Verwijderen',
    addLabel: 'Toevoegen',
    yesLabel: 'Ja',
    noLabel: 'Nee',
    activeLabel: 'Actief',
    inactiveLabel: 'Inactief',
    neverLabel: 'Nooit',
    noneRecorded: 'Niets geregistreerd.',
    noPhoto: 'Geen foto',
    colProduct: 'Product',
    colLocation: 'Locatie',
    colCabinet: 'Kastlocatie',
    colCurrent: 'Huidig',
    colMin: 'Min',
    colMax: 'Max',
    colValue: 'Waarde',
    colStockStatus: 'Voorraadstatus',
    colOrderStatus: 'Orderstatus',
    colDate: 'Datum',
    colType: 'Type',
    colQty: 'Aantal',
    colQuantity: 'Aantal',
    colSpecialist: 'Specialist',
    colProcedure: 'Procedure',
    colNote: 'Opmerking',
    colStatus: 'Status',
    colLot: 'Lot',
    colExpiryDate: 'Vervaldatum',
    colManufacturer: 'Fabrikant',
    colPrice: 'Prijs',
    colUnits: 'Eenheden',
    colMonths: 'Maanden',
    colAction: 'Actie',
    colOrderedOn: 'Besteld op',
    colOrdered: 'Besteld',
    colReceived: 'Ontvangen',
    odFirstReceivedOn: 'Eerst ontvangen op',
    odLastReceivedOn: 'Laatst ontvangen op',
    odNotYetReceived: 'Nog niet ontvangen',
    colRemaining: 'Resterend',
    colOrderedBy: 'Besteld door',
    colChannel: 'Kanaal',
    colDaysOpen: 'Dagen open',
    colTotalUsed: 'Totaal gebruikt',
    colRole: 'Rol',
    colName: 'Naam',
    colActive: 'Actief',
    colCategory: 'Categorie',
    colBelowMin: 'Onder min',
    colLotsUnder6mo: 'Loten < 6 mnd',
    colProducts: 'Producten',
    colEmail: 'E-mail',
    colLastLogin: 'Laatst ingelogd',
    colLanguage: 'Taal',

    rtHint: 'Alleen de laatste 7 dagen. Gebruik dit om een scanfout te herstellen (bijv. verkeerde locatie gescand) -- oudere historie kan hier niet worden bewerkt.',
    rtNoneRecent: 'Geen transacties in de afgelopen 7 dagen.',
    rtEdited: 'bewerkt',
    rtConfirmDelete: 'Deze transactie verwijderen? Dit wijzigt direct de voorraadtelling en kan hier niet ongedaan worden gemaakt.',
    rtSave: 'Opslaan',
    rtCancel: 'Annuleren',
    rtTypeReceived: 'Goederenontvangst',
    rtTypeUsed: 'Materiaalverbruik',
    rtTypeTransfer: 'Verplaatsing',
    rtTypeTransferIn: 'Verplaatsing in',
    rtTypeTransferOut: 'Verplaatsing uit',
    rtTypeReturned: 'Geretourneerd',
    rtTypeSurplus: 'Telling: overschot',
    rtTypeShortage: 'Telling: tekort',
    rtTypeExpired: 'Afschrijving vervallen',
    rtViewLines: 'Bekijk regels ({n})',
    rtHideLines: 'Verberg regels',
    rtConfirmDeleteBatch: 'Deze hele scanactie verwijderen ({n} regel(s))? Dit wijzigt direct de voorraadtelling voor elke regel erin en kan hier niet ongedaan worden gemaakt.',
    colLines: 'Regels',

    ovHideEmpty: 'Verberg artikelen zonder voorraad',
    ovNothing: 'Niets te tonen',
    ovNothingInStock: 'Niets te tonen dat momenteel op voorraad is.',
    ovNothingAll: 'Niets te tonen.',

    tkCountsDue: 'Voorraadtellingen verschuldigd',
    tkCountsHint: 'Elke locatie moet ongeveer elke {n} maanden fysiek worden geteld (alles scannen). Onderstaande locaties zijn te laat.',
    tkNothingDue: 'Op dit moment niets verschuldigd.',
    colLastCounted: 'Laatst geteld',
    tkStartCount: 'Telling starten',
    tkHerTellenTitel: 'Voorraadtelling opnieuw uitvoeren',
    tkHerTellenHint: 'Nog niet aan de beurt, maar wil je nu toch een kast of locatie controleren? Kies hieronder een locatie en start een telling op een moment dat jou uitkomt -- dit is geen verplichte taak.',
    tkHerTellenStart: 'Telling starten',
    tkHerTellenGeen: 'Geen actieve locaties om uit te kiezen.',
    tkHerTellenKastLabel: 'Kast',
    tkHerTellenHeleLocatie: 'Hele locatie',
    tkPastExpiry: 'Over de vervaldatum',
    tkPastExpiryHint: 'Nog op voorraad maar over de vervaldatum. Gebruik 🗑️ Vervallen Afschrijven om ze uit voorraad te verwijderen.',
    tkNothingExpired: 'Niets over de vervaldatum.',
    tkGoWriteOff: 'Naar Vervallen Afschrijven',
    tkOntbrekendeBatch: 'Ontbrekende batch-/vervaldatumgegevens',
    tkOntbrekendeBatchHint: 'Voorraad ontvangen zonder ooit een batch-/vervaldatumcode te scannen (bv. alleen de doos-GTIN gescand). Oplossen: verwijder de transactie bij Recente Transacties en scan het product opnieuw, samen met de batch-/vervaldatumbarcode.',
    tkOntbrekendeBatchGeen: 'Geen voorraad met ontbrekende batch-/vervaldatumgegevens.',
    gs1HintNogBatch: 'Scan ook de batch-/vervaldatumcode voor dit product.',
    gs1HintDoorgaan: 'Toch doorgaan zonder',
    tkOnbekend: '❓ Onbekende scans',
    tkOnbekendHint: 'Gescande barcodes die niet bij een bekend product hoorden, met de omschrijving die de scanner heeft ingevuld. Los ze op via het scherm Onbekende Producten.',
    tkOnbekendGeen: 'Op dit moment geen onbekende barcodes.',
    tkGoOnbekend: 'Naar Onbekende Producten',
    tkKeerGezien: 'Keer gezien',
    tkOnvolledig: '📋 Onvolledige productgegevens',
    tkOnvolledigHint: 'Producten waar informatie ontbreekt zoals producent, foto, min/max, kastlocatie, houdbaarheidsdata, prijs, categorie of doosverpakking-instellingen.',
    tkOnvolledigGeen: 'Elk product heeft zijn essentiële informatie ingevuld.',
    tkOntbrekendeVelden: 'Ontbrekende velden',
    tkVeld_producent: 'Producent',
    tkVeld_foto: 'Foto',
    tkVeld_minMax: 'Min/max',
    tkVeld_kastlocatie: 'Kastlocatie',
    tkVeld_houdbaarheid: 'Houdbaarheidsdata',
    tkVeld_prijs: 'Prijs',
    tkVeld_categorie: 'Categorie',
    tkVeld_doosInconsistent: 'Doosverpakking onvolledig',

    tlTitle: '🗒️ Voorraadtelling — ',
    tlHint: 'Loop deze locatie fysiek langs en scan elk aanwezig exemplaar, één scan per stuk (scan een product 3 keer als er 3 stuks zijn). Alles wat je niet scant wordt als nul beschouwd.',
    tlScansLabel: 'GS1-scans (één per aanwezig exemplaar)',
    tlSubmit: 'Telling indienen',
    tlProcessed: 'Telling verwerkt. {n} correctie(s) geboekt',
    tlAndErrors: ', {n} scanregel(s) konden niet worden gelezen',
    colSystem: 'Systeem',
    colCounted: 'Geteld',
    colDifference: 'Verschil',
    tlAllMatched: 'Alles klopte — geen correcties nodig.',
    tlUnreadable: 'Onleesbare regels',
    tlKastHint: 'Deze telling is beperkt tot kast "{kast}" -- producten die bij een andere kast in deze locatie horen worden niet vergeleken of gecorrigeerd.',
    tlVerkeerdeKastTitel: 'Gescand, maar hoort bij een andere kast',
    tlVerkeerdeKastHint: 'Deze producten zijn gescand, maar hun toegewezen kast wijkt af van de kast die je nu telt. Ze zijn hier niet meegeteld of gecorrigeerd -- leg ze terug in hun eigen kast.',
    tlVerkeerdeKastWerkelijk: 'Werkelijke kast',

    woHint: 'Historisch overzicht van de twee "afgehandelde" taaktypen uit 🗒️ Taken — vervallen voorraad die is afgeboekt, en artikelen die niet werden teruggevonden bij een telling.',
    woExpiredTitle: 'Afschrijvingen vervallen ({units} stuks, {value})',
    woShortageTitle: 'Tekorten bij telling — niet teruggevonden in voorraad ({units} stuks, {value})',
    woPeriode_1m: 'Afgelopen maand',
    woPeriode_3m: 'Afgelopen 3 maanden',
    woPeriode_6m: 'Afgelopen half jaar',
    woPeriode_1j: 'Afgelopen jaar',
    woPeriode_2j: 'Afgelopen 2 jaar',
    woPeriode_totaal: 'Totaal',

    pdEdit: '✏️ Bewerken',
    colEan: 'EAN',
    colCategoryLabel: 'Categorie',
    colOrderType: 'Besteltype',
    colManufacturerLabel: 'Producent',
    colArticleNumber: 'Artikelnummer',
    colPricePerUnit: 'Prijs per stuk',
    colTotalStockValue: 'Totale voorraadwaarde',
    colOrder: 'Order',
    colScannedBy: 'Gescand door',
    pdSpecs: '🔧 Specificaties',
    pdStockByLocation: '📍 Voorraad per Locatie',
    pdLotsInStock: '📦 Loten op Voorraad',
    // ("per lot een regel met aantal/vervaldatum/
    // barcode, uitklapbaar om te zien waar hoeveel ligt"): toggle-tekst
    // voor de locatie-uitklapper per lotgroep, zie renderProductDetail().
    ltBekijkLocaties: 'Bekijk {n} locatie(s)',
    ltHideLocaties: 'Verberg locaties',
    lotBewerken: '✏️ Bewerken',
    lotGeenBarcode: 'Geen barcode (handmatig ingevoerd)',
    lotBewerkHintMetBarcode: 'Dit corrigeert dit lot overal waar het voorkomt (alle locaties, ongeacht ouderdom) en onthoudt de correctie voor deze exacte barcode -- wordt dezelfde barcode nog eens gescand, dan wordt automatisch de gecorrigeerde lot/vervaldatum gebruikt.',
    lotBewerkHintZonderBarcode: 'Dit corrigeert dit lot overal waar het voorkomt (alle locaties, ongeacht ouderdom). Hier is geen barcode van vastgelegd, dus is er niets te onthouden voor toekomstige scans.',
    pdTransactionHistory: '📋 Transactiehistorie (Laatste 20)',
    pdUsageBySpecialist: '👨‍⚕️ Verbruik per Specialist',
    pdVerbruiksgrafiekTitle: '📈 Verbruikshistorie (laatste 12 maanden)',
    pdVerbruiksgrafiekEmpty: 'Geen verbruik geregistreerd voor dit product in de laatste 12 maanden.',
    pdOrderHistory: '📑 Bestelhistorie',
    pdNoOrders: 'Nog geen bestellingen geplaatst voor dit product',
    pdManufacturerContact: 'Contactgegevens bekijken →',

    statusActief: 'Actief',
    statusNietLeverbaar: 'Niet leverbaar',
    statusNietMeerInGebruik: 'Niet meer in gebruik',

    statusBadgeExpired: 'Verlopen',
    statusBadgeUrgentUse: 'Spoedgebruik',
    statusBadgeMonitor: 'Monitoren',
    statusBadgeValid: 'Geldig',
    statusBadgeLongLife: 'Langdurig',
    statusBadgeOk: 'OK',
    statusBadgeEmpty: 'Leeg',
    statusBadgeLow: 'Laag',
    statusBadgeHigh: 'Hoog',
    statusBadgeOnOrder: 'In bestelling',
    statusBadgePartiallyReceived: 'Gedeeltelijk ontvangen',
    statusBadgeReceived: 'Ontvangen',
    statusBadgeOrdered: 'Besteld',
    statusBadgeRequested: 'Aangevraagd',
    statusBadgeUsed: 'Gebruikt',
    statusBadgeReturned: 'Retourneren',
    pdNietLeverbaar: '⚠️ Niet leverbaar — tijdelijk niet te bestellen bij de leverancier.',
    pdNietMeerInGebruik: '⛔ Niet meer in gebruik.',
    pdVervangenDoor: 'Vervangen door: {naam}',
    pdNogGeenVervanger: 'Nog geen vervangend artikel ingesteld.',
    pdStatusWijzigen: 'Status wijzigen →',

    // Producenten
    prHint: 'Contactgegevens per producent/leverancier -- website, algemeen e-mailadres/telefoon, en een onbeperkt aantal contactpersonen. Gekoppeld aan producten op naam (case-insensitief); het producentveld op een product zelf blijft gewoon vrije tekst.',
    prEmpty: 'Nog geen producenten toegevoegd',
    colWebsite: 'Website',
    colPhone: 'Telefoon',
    colContacts: 'Contactpersonen',
    colLinkedProducts: 'Producten',
    colFunction: 'Functie',
    prNewManufacturer: 'Nieuwe producent toevoegen',
    prContactsTitle: '👥 Contactpersonen',
    prNoContacts: 'Nog geen contactpersonen toegevoegd',
    prNewContact: 'Nieuwe contactpersoon toevoegen',
    prLinkedProductsTitle: '📦 Gekoppelde Producten',
    prNoLinkedProducts: 'Geen producten gekoppeld aan deze producent (op naam)',
    prEditTitle: '✏️ Producent bewerken: ',
    prDelete: 'Producent verwijderen',
    prDeleteConfirm: '{naam} verwijderen? Contactpersonen worden ook verwijderd. Producten die deze producentnaam gebruiken blijven ongewijzigd.',
    prDeleteContactConfirm: 'Deze contactpersoon verwijderen?',

    peEditTitle: '✏️ Bewerken ',
    peCancelTop: '← Annuleren',
    peHint: 'EAN {ean} — categorie {cat}.',
    pePhoto: 'Foto',
    pePhotoLink: 'Fotolink',
    pePhotoPlaceholder: 'Plak een Google Drive-deellink ("Iedereen met de link") of een directe afbeeldings-URL',
    pePhotoHint: 'Zet het Drive-bestand op "Iedereen met de link", anders kan het dashboard de foto niet laden.',
    pfPhotoUploadHint: 'JPG, PNG of WEBP, max 2MB. Wordt rechtstreeks in de database opgeslagen en krijgt voorrang op de link hieronder.',
    pePhotoFallbackHint: 'Wordt alleen gebruikt als terugvaloptie als er hierboven geen foto is geüpload.',
    peGeneralInfo: 'Algemene informatie',
    peSpecifications: 'Specificaties',
    peSaving: 'Bezig met opslaan...',
    peErrorSaving: 'Fout bij opslaan: ',

    // ("ik wil daadwerkelijk alles kunnen
    // bewerken"): bewerkscherm opgesplitst in duidelijke secties i.p.v. één
    // lange lijst, plus dropdowns voor producent/besteltype/categorie en
    // een aparte (cascaderende) EAN-wijzig-actie.
    peSectionIdentification: 'Identificatie',
    peSectionOrderData: 'Besteldata',
    peSectionStock: 'Voorraad: Min/Max & kastlocatie per kamer',
    peSectionExtraLocations: 'Extra locaties',
    peKastlocatie: 'Kastlocatie',
    peKastlocatieNone: '(geen)',
    peKastlocatieNew: '+ Nieuwe kastlocatie',
    peKastlocatieNewPlaceholder: 'Typ nieuwe kastlocatie',
    peEanCurrentLabel: 'Huidige EAN / barcode',
    peEanChangeTitle: 'EAN wijzigen',
    peEanChangeHint: 'Gebruik dit alleen om een verkeerd gescande/getypte code voor DIT FYSIEKE product te corrigeren. Het schrijft de EAN overal om waar die gebruikt wordt -- de volledige voorraadhistorie van dit product (Recente Transacties), openstaande bestellingen en gesnoozed Min/Max-advies gaan mee. Gaat het eigenlijk om een ander product? Gebruik dan "Nieuw artikel aanmaken" of de vervanger-koppeling hieronder.',
    peEanChangeNewLabel: 'Nieuwe EAN / barcode',
    peEanChangeBtn: 'EAN wijzigen',
    peEanChangeSaving: 'Bezig met wijzigen...',
    peEanChangeConfirm: 'EAN wijzigen van {oud} naar {nieuw}? Dit schrijft de EAN ook om in de voorraadhistorie, bestellingen en Min/Max-advies van dit product. Dit kan hierna niet meer ongedaan gemaakt worden.',
    peEanChangeSuccess: 'EAN gewijzigd naar {ean}.',

    peStatusTitel: 'Status',
    peStatusHint: 'Markeer dit artikel als tijdelijk niet leverbaar (met optioneel een vervangend artikel) of als blijvend niet meer in gebruik. Beide worden overgeslagen bij Min/Max Advies, de Boodschappenlijst en de Berging-bestellijst -- bestaande fysieke voorraad en eerdere transacties blijven ongewijzigd.',
    peStatus: 'Status',
    peVervanger: 'Vervangend artikel',
    peVervangerGeen: '(geen vervanger ingesteld)',
    peVervangerNieuwArtikel: 'Nieuw artikel aanmaken als vervanger...',

    psPlaceholder: 'Zoeken op productnaam of EAN...',
    psAllCategories: 'Alle categorieën',
    psColStockByLocation: 'Voorraad per locatie',
    colExpiry: 'Eerstvolgende vervaldatum',

    slHint1: 'Bijvullen vanuit Berging',
    slHint2: 'Te Bestellen (Handmatig)',
    slRefillHint: 'Toont alleen wat op dit moment daadwerkelijk vanuit Berging kan worden bijgevuld. Staat er niets in Berging, dan wordt het hier ook niet getoond.',
    slCurrentAt: 'Huidig bij ',
    slNeeded: 'Nodig',
    slInStorage: 'In Berging',
    slAllSufficient: 'Alle locaties hebben voldoende voorraad',

    stTitle: '📦 Bestellijst Berging',
    stHint: 'Alles onder minimum bij Berging, berekend uit scans — geef deze lijst direct aan logistiek, geen fysieke telling of scan nodig om te weten wat besteld moet worden.',
    stAllSufficient: 'Alles voldoende op voorraad',
    colBarcode: 'Barcode interne code',
    stNoLogistiekCode: 'Geen interne logistiekcode ingesteld voor dit product',
    colProducerRef: 'Producent / Ref',
    colNeeded: 'Nodig',

    bpPrintKnop: '🖨️ Printen / Exporteren als PDF',
    bpDownloadMailKnop: '📧 Download PDF & Mail Logistiek',
    bpDownloadMailHint: 'Download eerst een PDF van deze lijst, opent daarna een lege e-mail -- voeg de zojuist gedownloade PDF er zelf als bijlage aan toe voordat je de mail verstuurt (een browser kan een e-mail niet automatisch van een bijlage voorzien).',
    bpMarkeerKnop: 'Markeer als besteld',
    bpMarkeerHint: 'Downloaden/mailen heeft geen gevolgen. "Markeer als besteld" zet voor elk item hierboven het getoonde restant als bestelling weg -- een item dat al volledig gedekt is door een bestaande order staat sowieso niet meer op deze lijst. Als Logistiek levert, scan je het gewoon als vanouds in bij Goederenontvangst.',
    bpTitel: 'Bestellijst Berging',
    bpDozen: 'dozen',
    bpStuks: 'stuks',
    bpMarkeerConfirm: 'Elk item op deze lijst als besteld markeren? Dit plaatst voor elk item een echte bestelling.',
    bpMarkeerGelukt: '{aantal} item(s) als besteld gemarkeerd.',
    bpMailSubject: 'Bestellijst Berging -- {datum}',
    bpMailIntro: 'Bijgevoegd de actuele bestellijst voor Berging, geëxporteerd uit Stocuro.',
    bpMailAttachment: 'Bijlage: {bestand} (zojuist gedownload -- voeg deze zelf toe aan deze mail voordat je verstuurt)',
    bpMailListHeader: 'Items op deze lijst (ter referentie, ook in de bijgevoegde PDF):',

    edTitle: '📅 Vervaldatums',
    edHint: 'De volledige lijst, elk lot op voorraad in één platte tabel — om een specifiek product/lot op te zoeken. Liever gegroepeerd per locatie? Zie 🔍 Lot-tracking. Liever de euro-waarde die op het spel staat? Zie ⏳ Vervaldatum Focus.',
    edLegend: 'Legenda:',
    edExpired: 'vervallen',
    edNone: 'Geen vervaldatums ingevoerd',

    ltTitle: '🔍 Lot-tracking per Locatie',
    ltHint: 'Dezelfde lotgegevens als 📅 Vervaldatums, gegroepeerd per locatie — handig om te zien wat er fysiek in een specifieke kast zit.',
    ltNone: 'Geen loten op voorraad',

    usTitle: '👨‍⚕️ Verbruik per Procedure',
    usHint: 'Gegroepeerd per procedure -- wat er gemiddeld per procedure wordt verbruikt, over alle specialisten heen. Regels die in één indiening van Materiaal verbruik samen gescand zijn, tellen als één procedure.',
    usNone: 'Geen verbruiksgegevens beschikbaar',
    usProcedureCount: '{n} procedures',
    usAvgItems: 'gem. {n} artikelen/procedure',
    usAvgCost: 'gem. €{bedrag}/procedure',
    colTotal: 'Totaal',
    colUsedIn: 'Gebruikt in',
    colAvgPerProcedure: 'Gem./procedure',
    colCostPrice: 'Kostprijs',

    toTitle: '📋 Te Bestellen (Handmatig)',
    toAllSufficient: 'Alle producten hebben voldoende voorraad',
    colArticleRef: 'Artikelref',
    colTotalNeeded: 'Totaal Nodig',
    toOrdered: 'Besteld',
    toOrder2: 'Bestellen',

    svTotalValue: 'Totale voorraadwaarde',
    svByLocation: '📍 Per Locatie',
    svByCategory: '🏷️ Per Categorie',
    colNumProducts: 'Aantal artikelen',
    colTotalUnits: 'Totaal stuks',

    acVerlopen: 'Verlopen loten',
    acUrgent: 'Spoedgebruik (< 3 mnd)',
    acEmpty: 'Leeg',
    acLow: 'Laag',
    acOpenOrders: 'Open orders',
    acCockpitHint: 'Klik op een tegel om de bijbehorende lijst te zien.',
    acNothing: 'Op dit moment vraagt niets aandacht',
    acExpiredDiscard: 'Vervallen - weggooien',
    acUrgentUse: 'Spoedgebruik (< 3 maanden)',
    acEmptyStock: 'Lege voorraad',
    acLowStock: 'Lage voorraad',
    acOpenOrdersTitle: 'Open orders',

    vfHint: 'Dezelfde lotgegevens als 📅 Vervaldatums, maar getoond als euro-waarde die op het spel staat in plaats van een platte lijst — om te bepalen wat prioriteit verdient.',
    vfExpired: 'Vervallen',
    vfExpiresLt3: 'Vervalt < 3 mnd',
    vfExpiresLt6: 'Vervalt < 6 mnd',
    vfExpiresLt12: 'Vervalt < 12 mnd',
    vfLots: 'loten',
    vfFilterAll: 'Alles (< 12 mnd)',
    vfFilterExpired: 'Vervallen',
    vfFilterLt3: '< 3 mnd',
    vfFilterLt6: '< 6 mnd',
    vfNoneSelected: 'Geen loten in deze selectie',
    vfTotal: 'Totaal ({n} loten)',

    cgClickHint: 'Klik op een categorie voor de artikellijst.',
    cgTotal: 'Totaal',
    cgNoneInCategory: 'Geen producten in deze categorie',
    cgLoten6FilterHint: 'Alleen producten in deze categorie met een lot dat binnen 6 maanden vervalt.',
    cgNoneLoten6: 'Geen producten met een lot dat binnen 6 maanden vervalt in deze categorie.',
    cgBackToCategories: '← Categorieën',

    ooRegularTitle: '📦 Reguliere Orders',
    ooNone: 'Geen open orders',
    ooRedHint: 'Rood dagenaantal = langer dan 14 dagen open.',
    // Order detail
    odDetailTitle: 'Orderdetails',
    odStatusHint: 'Status: {status} (berekend uit besteld/ontvangen, niet direct wijzigbaar).',
    odViewProduct: 'Bekijk product',
    odTransactionsTitle: 'Transacties rondom deze order',
    odTransactionsHint: 'Voorraadtransacties voor dit product tussen het moment dat deze order geplaatst is en het moment dat er voor het laatst iets op ontvangen is (of nu, als de order nog openstaat). Let op: transactielog heeft geen directe koppeling met een specifieke order -- als er meerdere orders voor hetzelfde product tegelijk openstonden, kan eenzelfde transactie bij meer dan één order getoond worden.',
    odNoTransactions: 'Nog geen voorraadtransacties geregistreerd binnen de periode van deze order.',
    // : eerst "lots" -> "stuks" gewijzigd, maar dat bleek
    // ook niet juist -- n is het aantal transactielog-REGELS in deze batch
    // (item.aantalRegels / item.regels.length), niet het aantal stuks.
    // Consistent met rtViewLines ("Bekijk regels ({n})") elders in de app.
    odBekijkLots: 'Bekijk {n} regels',
    ooOneOffTitle: '🏥 Eenmalige Bestellingen',
    ooOneOffHint: 'Patiëntgebonden aanvragen (🏥 Eenmalige Bestelling) die nog niet Gebruikt of Retourneren zijn. Klik op een regel voor de volledige inhoud (incl. opmerkingen) en wijzig de status van daaruit -- zodra deze op Gebruikt of Retourneren staat, verdwijnt de regel uit dit overzicht.',
    ooOneOffNone: 'Geen openstaande eenmalige bestellingen',
    ebDetailTitle: 'Details Eenmalige Bestelling',
    ebEditTitle: 'Eenmalige Bestelling Bewerken',

    trUsagePerMonth: 'Verbruik per maand per categorie',
    trNoData: 'Nog geen verbruiksdata',
    trDrillHint: 'Producten waaruit dit getal is opgebouwd.',
    trDrillNone: 'Geen producten gevonden voor deze selectie.',
    colMonth: 'Maand',
    trSlowMovers: 'Slow movers (geen verbruik in 90 dagen)',
    trNoSlowMovers: 'Geen slow movers - alle voorraad beweegt',
    trCapitalTied: 'Gebonden kapitaal in slow movers',
    trItems: 'artikelen',
    colLastUsage: 'Laatste verbruik',
    trNever: 'Nooit',
    trDaysAbbrev: 'd',
    trSlowMoverHint: 'Kandidaten voor lagere min/max-waarden of retour. Wordt betrouwbaarder naarmate er meer verbruiksdata is.',

    trAdjustmentsTitle: 'Voorraadaanpassingen per maand (uit tellingen)',
    trAdjustmentsHint: 'Overschot-/tekortcorrecties geboekt tijdens een kastcontrole (Taken → Start telling). Veel tekorten kunnen wijzen op gemiste scans in plaats van echt verdwenen voorraad.',
    colSurplus: 'Overschot',
    colShortage: 'Tekort',
    trWriteOffsTitle: 'Afschrijvingen wegens overdatum per maand',
    trWriteOffsHint: 'Zelfde data als het Afschrijvingen-dashboard, per maand uitgesplitst, zodat je ziet of het oploopt of afneemt.',
    trSavingsTitle: '💰 Besparing: net op tijd gebruikt vóór overdatum',
    trSavingsHint: 'Materiaal dat gebruikt is toen het nog maar 90 dagen of minder houdbaar was -- een goede kandidaat om als overdatum afgeboekt te worden als het niet op tijd gebruikt was. De waarde is een schatting op basis van de prijs op het moment van gebruik, geen vergelijking met een aparte prognose.',
    colWithin30: 'Gebruikt ≤30 dagen voor overdatum',
    colWithin60: 'Gebruikt 31-60 dagen voor overdatum',
    colWithin90: 'Gebruikt 61-90 dagen voor overdatum',
    trSavingsTotal: 'Geschatte besparing (laatste 12 maanden)',

    flNone: 'Geen fouten gelogd',
    flHint: 'Laatste {n} fouten, nieuwste eerst.',
    flBerichtEanOnbekend: 'Product niet gevonden in artikelen',
    apiErrAfbeeldingType: 'Alleen JPG, PNG of WEBP-afbeeldingen zijn toegestaan',
    apiErrAfbeeldingOngeldig: 'Geen geldige afbeelding ontvangen',
    apiErrAfbeeldingOnleesbaar: 'Kon de afbeelding niet lezen',
    apiErrAfbeeldingGeenAfbeelding: 'Bestand is geen geldige afbeelding',
    colTime: 'Tijd',
    colErrorType: 'Fouttype',
    colBarcodeCol: 'Barcode',
    colMessage: 'Bericht',

    omFillOrderer: 'Vul "Besteller" in alstublieft',
    omErrorPrefix: 'Fout: ',

    roHint: 'Scan de barcode(s) van binnenkomende producten. Eén regel per product -- de scanner "typt" barcode + Enter, zoals voorheen.',
    roLocation: 'Locatie',
    roScans: 'GS1-scans (één per regel)',
    roNote: 'Opmerking (optioneel)',
    roSave: 'Opslaan',
    roProcessed: 'Verwerkt: {n}, overgeslagen: {m}',

    suNotEnough: 'Nog niet genoeg specialisten/procedures ingesteld. Voeg ze toe via ⚙️ Beheer → Stamgegevens.',
    suLocationUsed: 'Gebruikte locatie',
    suSpecialist: 'Specialist',
    suPiccHint: 'Welke namen hier getoond worden hangt af van de procedure die je hieronder kiest -- een Laborant verschijnt pas zodra een procedure gekozen is die is aangevinkt als "ook toegestaan voor Laborant" (in de praktijk PICC-lijnen).',
    suProcedure: 'Procedure type',
    kkGeenOpties: 'Nog niets ingesteld',
    suScans: 'GS1-scans (één per regel)',
    suNote: 'Opmerking (optioneel)',
    suRegister: 'Registreren',
    suOmgeleidTitel: 'ℹ️ Automatisch afgeschreven uit een andere ruimte:',
    suOmgeleidRegel: '{naam}: uit {naar} (geen voorraad in {van})',
    suProcessed: 'Verwerkt: {n}, overgeslagen: {m}',

    wfHint: 'Voor producten die de maximale houdbaarheidsdatum hebben bereikt. Dit verwijdert ze uit voorraad met een eigen registratie (los van normaal verbruik) — zie 📉 Afschrijvingen voor de historie.',
    wfLocation: 'Locatie',
    wfScans: 'GS1-scans (één per regel)',
    wfNote: 'Opmerking (optioneel)',
    wfSubmit: 'Afboeken',
    wfWrittenOff: 'Afgeboekt: {n}, overgeslagen: {m}',

    tfWhatToDo: 'Wat wil je doen?',
    tfTransfer: 'Verplaatsen',
    tfReturn: 'Retourneren',
    tfScans: 'GS1-scans (één per regel)',
    tfFromLocation: 'Van locatie',
    tfToLocation: 'Naar locatie',
    tfReason: 'Reden',
    tfNote: 'Opmerking (optioneel)',
    tfSave: 'Opslaan',
    tfProcessed: 'Verwerkt: {n}',

    oeHint: 'Voor uniek/maatwerk materiaal voor één specifieke patiënt. Patiëntnummer wordt met de hand ingevoerd (persoonsgegevens, ga hier zorgvuldig mee om) -- scan zodra het product binnenkomt de GS1-barcode hieronder om EAN, lot en vervaldatum automatisch in te vullen. Status volgen en wijzigen (Aangevraagd → Besteld → Ontvangen → Gebruikt/Retourneren) kan via 🚚 Open Orders.',
    oePatientNumber: 'Patiëntnummer',
    oeProductDescription: 'Productomschrijving',
    oeGs1Scan: 'Scan GS1-barcode(s) (zodra ontvangen)',
    oeGs1Placeholder: 'Scan hier, één barcode per regel...',
    oeGs1Hint: 'Optioneel -- meestal leeg bij het aanvragen zelf. Scan de barcode(s) van het product zodra het binnenkomt om EAN, lot en vervaldatum automatisch in te vullen (overschrijft de velden hieronder). Een grotere hoeveelheid kan verspreid over meerdere lots binnenkomen -- scan één barcode per regel en elk lotnummer wordt vastgelegd, met de vroegste vervaldatum van de partij als de datum die het meest telt.',
    oeQuantity: 'Benodigd aantal',
    oeProcedureType: 'Procedure type',
    oeSelectProcedure: '-- kies procedure --',
    oeSpecialist: 'Specialist',
    oeSelectSpecialist: '-- kies specialist --',
    oeOrderedByHint: 'Besteld door: {naam} (jijzelf, op basis van je inlog)',
    oeNote: 'Opmerking (optioneel)',
    oeRequest: 'Aanvragen',
    oeViewOverview: 'Overzicht bekijken',
    oeRequiredFields: 'Patiëntnummer en productomschrijving zijn verplicht',
    oeRequested: 'Eenmalige bestelling aangevraagd',
    oeRecentRequests: 'Recente aanvragen',
    oeNoneYet: 'Nog geen eenmalige bestellingen',
    ebAantalHint: 'Wordt automatisch ingevuld op basis van het aantal hierboven gescande GS1-barcodes -- niet met de hand intypen.',
    ebItemsTitle: 'Gescande items',
    ebItemsEmpty: 'Nog geen items gescand -- scan hierboven een GS1-barcode en sla op om het eerste item toe te voegen.',
    ebItemOnbekend: 'Onbekend product',
    ebStatusOntvangen: 'Ontvangen',
    ebStatusVerbruikt: 'Verbruikt',
    ebStatusRetour: 'Retour',
    ebMailSend: '📧 Stuur e-mail',
    ebMailNoSpecialist: 'Geen specialist ingevuld bij deze bestelling -- het "Aan"-veld blijft leeg, vul dit zelf in.',
    ebMailNoEmail: 'Geen e-mailadres bekend voor {naam} (in te stellen via ⚙️ Beheer → Stamgegevens) -- het "Aan"-veld blijft leeg, vul dit zelf in.',
    ebMailSubject: 'Eenmalige bestelling #{id}: {omschrijving} -- status: {status}',
    ebMailLineOrder: 'Bestelling: #{id}',
    ebMailLinePatient: 'Patiëntnr.: {patientnummer}',
    ebMailLineDescription: 'Product: {omschrijving}',
    ebMailLineEan: 'EAN: {ean}',
    ebMailLineQuantity: 'Aantal: {n}',
    ebMailLineProcedure: 'Procedure: {procedure}',
    ebMailLineStatus: 'Status: {status}',
    ebMailLineOrderedBy: 'Besteld door: {naam}',
    colPatientNo: 'Patiëntnr.',
    colDescription: 'Omschrijving',

    apHint: 'GS1 wordt bij opslaan geparsed voor de EAN -- geen los bewerkingsmoment meer nodig zoals in de oude sheet-versie. Het artikelnummer wordt nooit uit de scan afgeleid; vul dat hieronder zelf in als je dat bijhoudt.',
    apVervangerHint: 'Dit artikel wordt aangemaakt als vervanger voor een ander (nu niet leverbaar) artikel -- een eigen EAN/barcode hieronder is verplicht, want dit is een ander fysiek product.',
    apGs1Scan: 'GS1-scan (de EAN hieronder wordt automatisch ingevuld)',
    apEanManual: 'EAN (terugval, alleen als er geen GS1 beschikbaar is)',
    apEanAutoHint: 'Wordt automatisch ingevuld zodra hierboven een GS1-barcode gescand is -- alleen zelf invullen als er voor dit product geen GS1 beschikbaar is.',
    apProductName: 'Productnaam',
    apCategory: 'Categorie',
    apOrderType: 'Besteltype',
    apOrderTypeAuto: 'Automatisch',
    apOrderTypeManual: 'Handmatig',
    apOrderTypeOnce: 'Eenmalig',
    apDoosTitle: '\u{1F4E6} Doosverpakking (optioneel)',
    apDoosHint: 'Alleen invullen als dit product ook per gesloten doos van meerdere stuks geleverd wordt (bijv. een doos van 5 voerdraden of PICC-lijnen) -- laat beide velden leeg als dat niet zo is. De doos-barcode scannen boekt dan automatisch het juiste aantal stuks in één keer, zowel bij Goederenontvangst, Verplaatsing + Retour, Materiaal verbruik als Tellen.',
    apDoosGs1Scan: 'Doos-GS1-scan (de EAN hieronder wordt automatisch ingevuld)',
    apDoosEan: 'Doos-EAN/barcode (terugval, alleen zonder GS1)',
    apEenhedenPerDoos: 'Stuks per doos',
    doosSuffix: '= {n} dozen',
    apLogistiekTitle: '🏷️ Interne logistiekcode (optioneel)',
    apLogistiekHint: 'Alleen invullen als je een eigen printbaar barcode-label voor dit product wilt (bijv. voor een schaplabel), los van het EAN van de fabrikant. Volledig handmatig -- altijd zelf ingevuld, nooit automatisch gegenereerd. Het geprinte label scannen wordt overal herkend alsof het EAN van het product zelf gescand is.',
    apLogistiekCode: 'Interne logistiekcode',
    apMinMax: 'Min/max per locatie',
    apMin: 'Min ',
    apMax: 'Max ',
    apManufacturer: 'Producent',
    apManufacturerNone: '-- geen producent / onbekend --',
    apManufacturerNew: '+ Nieuwe producent toevoegen...',
    apManufacturerNewPlaceholder: 'Naam nieuwe producent',
    apSpecNone: '-- kies --',
    apSpecNew: '+ Nieuwe waarde toevoegen...',
    apSpecNewPlaceholder: 'Nieuwe waarde',
    apPricePerUnit: 'Prijs per stuk (EUR)',
    apAdd: 'Toevoegen',
    apAdded: 'Product toegevoegd (EAN {ean})',
    catStent: 'Stent',
    catGuidewire: 'Voerdraad',
    catEmbolisation: 'Embolisatie',
    catSheath: 'Sheath',
    catBalloon: 'Ballon',
    catCatheter: 'Catheter/Closure',
    catOther: 'Overig',

    mdHint: 'Deze lijst verschijnt als keuzelijst bij Materiaal verbruik en Eenmalige Bestelling. Deactiveer in plaats van verwijderen, zodat oude transacties leesbaar blijven. Specialisten en Laboranten beheer je voortaan op het Gebruikers-scherm -- zie Beheer → Gebruikers.',
    mdNothingAdded: 'Nog niets toegevoegd',
    mdProcedureTypes: '🏥 Procedure typen',
    mdLaborantToegestaan: 'Ook toegestaan voor Laborant',
    mdLaborantHint: 'Dit aanvinken voor een procedure (in de praktijk PICC-lijnen) zorgt dat een Laborant gekozen kan worden als uitvoerder van die procedure op het Materiaal verbruik-formulier -- niet aangevinkte procedures vereisen een Specialist.',
    mdNewProcedure: 'Nieuwe procedure',
    mdActivate: 'Activeren',
    mdDeactivate: 'Deactiveren',
    mdRemove: 'Verwijderen',
    mdCategorieen: '📁 Categorieën & eigenschappen',
    mdCategorieenHint: 'Welke producteigenschappen (diameter, lengte, French size, ...) van toepassing zijn hangt af van de categorie. Een gedeactiveerde categorie verdwijnt uit de lijst bij het toevoegen van een nieuw product, bestaande producten blijven gewoon werken.',
    mdNoSpecs: 'Nog geen eigenschappen gedefinieerd voor deze categorie.',
    mdNewSpecPlaceholder: 'Nieuwe eigenschap (bijv. Diameter (mm))',
    mdNewCategorie: 'Nieuwe categorie',
    mdSaved: 'Opgeslagen',

    lcHint: 'Stocuro heeft vier locatie-"slots" met een vaste rol (twee algemene locaties, één mobiele kar zonder vaste kast, en één centrale/aanvullocatie) -- de naam van elk slot is vrij aanpasbaar. Daarnaast kun je hieronder een onbeperkt aantal extra locaties toevoegen; die gedragen zich als een algemene locatie (eigen min/max per product, meegenomen in de boodschappenlijst).',
    lcRoleGeneral1: 'Algemene locatie 1',
    lcRoleGeneral2: 'Algemene locatie 2',
    lcRoleMobile: 'Mobiel (geen vaste kast)',
    lcRoleCentral: 'Centraal / aanvulbron',
    lcAdditional: 'Extra locatie',
    lcAddNew: 'Nieuwe locatie toevoegen',
    lcNamePlaceholder: 'bijv. Kamer C',
    lcAddButton: 'Locatie toevoegen',

    guHint: 'Taal is een accountinstelling die elke gebruiker zelf kan wijzigen (taalkeuze naast Uitloggen) — hier wordt het alleen ingesteld bij het aanmaken van het account.',
    guNewUser: 'Nieuwe gebruiker',
    guTempPassword: 'Tijdelijk wachtwoord (min. 8 tekens)',
    guRoleSpecialist: 'Specialist',
    guRoleLaborant: 'Laborant',
    guRoleBekijker: 'Bekijker',
    guFunctie: 'Functie',
    guFunctieHint: 'Specialist en Laborant hebben allebei volledige toegang (scannen/bestellen); Bekijker kan alleen de data inzien. Een Laborant kan alleen als uitvoerder van een procedure gekozen worden als die procedure in Stamgegevens is aangevinkt als "ook toegestaan voor Laborant" (in de praktijk PICC-lijnen) -- elke andere procedure vereist een Specialist. Het Beheerder-vinkje is een extra markering los van de functie: een Beheerder kan altijd Gebruikers/Locaties/Producenten/Stamgegevens beheren, ongeacht functie.',
    guBeheerderVlag: 'Beheerder',
    guLicentie: 'Licentie',
    guLicentieVrij: 'Gratis',
    guLicentieGeldigTot: 'Geldig tot {datum}',
    guLicentieGeblokkeerd: 'Geen geldige licentie',
    guLicentieKoppelen: 'Code koppelen',
    guLicentieCodePrompt: 'Plak de activatiecode voor {naam}:',
    guLicentieEmailPrompt: 'Bevestig het besteladres waaronder deze code is gekocht (zie je aankoopbevestiging):',
    guLicentieGekoppeld: 'Code gekoppeld.',
    // Nieuw
    // account aanmaken vereist nu meteen een activatiecode.
    guLicentieRegistratieTitle: 'Licentieactivatie',
    guLicentieRegistratieHint: 'Een nieuw account kan alleen samen met een geldige activatiecode aangemaakt worden -- plak de code die je per e-mail hebt ontvangen na aankoop, en bevestig het besteladres waaronder die is uitgegeven.',
    guLicentieCodeLabel: 'Activatiecode',
    guLicentieEmailLabel: 'Besteladres (bevestiging)',
    // ("profielpagina").
    profTitle: '👤 Mijn profiel',
    profAccountTitle: 'Account',
    profTaalHint: 'Je taalvoorkeur wijzig je naast Uitloggen, in de header.',
    profLicentieTitle: 'Licentie',
    profLicentieBlokkadeHint: 'Neem contact op met je beheerder als je denkt dat dit niet klopt -- alleen een beheerder kan een nieuwe activatiecode aan je account koppelen (Gebruikers-scherm).',
    profWachtwoordTitle: 'Wachtwoord wijzigen',
    profHuidigWachtwoord: 'Huidig wachtwoord',
    profNieuwWachtwoord: 'Nieuw wachtwoord (min. 8 tekens)',
    profWachtwoordOpslaan: 'Wachtwoord wijzigen',
    guLicentieAnomalie: 'Let op: er zijn {aantal} geldige gratis-tokens gevonden op deze installatie (verwacht maximaal 1). Alleen de gebruiker met het laagste account-id telt mee als de rechtmatige gratis gebruiker; controleer license_activations als dit onverwacht is.',
    // Een beheerder moet het wachtwoord van een
    // andere gebruiker kunnen resetten (er is geen "wachtwoord vergeten"-
    // mailflow in deze app, bewust, zie README "Licensing & self-hosted
    // distribution").
    guWachtwoordResetten: 'Wachtwoord resetten',
    guWachtwoordResetPrompt: 'Nieuw wachtwoord voor {naam} (min. 8 tekens):',
    guWachtwoordTeKort: 'Wachtwoord moet minstens 8 tekens zijn.',
    guWachtwoordResetGelukt: 'Wachtwoord gereset. Geef het nieuwe wachtwoord zelf door aan de gebruiker -- dit wordt niet automatisch gemaild.',
    guLegacyTitle: 'Nog te migreren',
    guLegacyHint: 'Namen uit de oude Stamgegevens-specialistenlijst die nog geen inlogaccount hebben. "Account aanmaken" vult de naam hieronder alvast in -- vul de rest aan en maak het account aan.',
    guLegacyCreateAccount: 'Account aanmaken',
    guLanguage: 'Taal',
    guCreate: 'Aanmaken',
    guGroepen: 'Groepen',
    guGeenGroepen: 'Er bestaan nog geen groepen -- maak er één aan op het Groepen-scherm.',
    guNaarGroepen: 'Groepen beheren →',
    // ("blijft heel
    // erg lelijk en onoverzichtelijk"): Groepen-cel toont voortaan compacte
    // chips + een wijzigen-knop i.p.v. een altijd-uitgeklapt kadertje, en
    // Beheerder+Actief zijn samengevoegd tot één "Rechten"-kolom.
    guRechten: 'Rechten',
    guGeenGroepenGekozen: 'Geen groepen',
    guGroepenWijzigen: 'Groepen wijzigen',
    guGroepenSluiten: 'Groepen sluiten',
    guNaamEmailWijzigen: 'Naam/e-mail bewerken',
    guNaamEmailSluiten: 'Klaar met bewerken',

    groepenTitle: 'Groepen',
    groepenHint: 'Een groep bepaalt welke schermen de leden mogen zien (en of ze mogen schrijven/scannen/bewerken). Een gebruiker kan bij meerdere groepen tegelijk horen -- de toegang is dan de optelsom van al die groepen. Beheerder-accounts zien en mogen altijd alles, ongeacht groep.',
    groepenEmpty: 'Nog geen groepen -- maak er hieronder één aan.',
    groepNaam: 'Naam',
    groepMagSchrijven: 'Mag schrijven (scannen/bestellen/bewerken)',
    groepAantalLeden: 'Leden',
    groepAantalSchermen: 'Schermen',
    groepNieuw: '+ Nieuwe groep',
    groepSchermenTitel: 'Welke schermen mag deze groep zien?',
    groepSectieFormulieren: 'Formulieren',
    groepSectieDashboards: 'Dashboards',
    groepSectieBeheer: 'Beheer',
    groepLeden: 'Leden van deze groep',
    groepLandingSchermTitel: 'Standaard landingsscherm',
    groepLandingSchermHint: 'Wat leden van deze groep direct na inloggen zien, i.p.v. het hoofdmenu. Alleen schermen die hierboven zijn aangevinkt zijn beschikbaar. Als een lid in meerdere groepen zit met verschillende landingsschermen, krijgt die het hoofdmenu.',
    groepLandingSchermHome: 'Home (hoofdmenu)',
    groepVerwijderenConfirm: 'Deze groep verwijderen? Leden verliezen meteen toegang tot de schermen ervan (tenzij een andere groep van hen dat ook geeft).',

    mbTitle: 'Mijn Bestellingen',
    mbHint: 'Eenmalige bestellingen op jouw naam, nieuwste eerst. Alleen-lezen -- neem contact op met de besteldienst voor wijzigingen.',
    mbNone: 'Nog geen eenmalige bestellingen gevonden op jouw naam.',

    colPhoto: 'Foto',
    fotoUpload: 'Foto',
    fotoRemove: 'Foto verwijderen',
    fotoInvalidType: 'Kies een JPG-, PNG- of WEBP-afbeelding',
    fotoTooBig: 'Afbeelding is te groot (max 2MB)',
    fotoRemoveConfirm: 'Deze foto verwijderen?',

    // Leaderboard
    leaderboard: '🏆 Leaderboard',
    lbHint: 'Twee losse ranglijsten: wie de meeste acties (scans, verplaatsingen, tellingen) onder zijn/haar eigen account heeft uitgevoerd, en wie de meeste procedures van een bepaald type heeft gedaan -- handig voor een eerlijke rotatie (bijv. "wie is aan de beurt voor een PICC-lijn").',
    lbPeriod: 'Periode',
    lbPeriodWeek: 'Laatste 7 dagen',
    lbPeriodMonth: 'Laatste 30 dagen',
    lbPeriodQuarter: 'Laatste 90 dagen',
    lbPeriodYear: 'Laatste 365 dagen',
    lbPeriodTotal: 'Altijd',
    lbPiccTitle: '🔄 Wie is aan de beurt',
    lbPiccHint: 'Volledige rotatie-wachtrij per PICC-procedure -- wie het langst geleden (of nog nooit) aan de beurt was staat vooraan. Onafhankelijk van de periodefilter hierboven.',
    lbPiccGeenLaboranten: 'Geen laboranten beschikbaar voor deze procedure.',
    lbPiccLaatstUitgevoerd: 'Laatst uitgevoerd',
    lbPiccNooitGedaan: 'Nooit',
    lbScansTitle: '📋 Meeste scans',
    lbScansHint: 'Elke scan/verplaatsing/retour/telling/afboeking, geteld op basis van het account dat de actie uitvoerde.',
    lbNoScans: 'Nog geen scans geregistreerd in deze periode.',
    lbProceduresTitle: '👨‍⚕️ Meeste procedures',
    lbProceduresHint: 'Verbruiksregistraties ("Materiaal verbruik"), geteld per specialist -- filter op procedure type om te zien wie de meeste van een specifieke heeft gedaan (bijv. PICC-lijnen).',
    lbFilterProcedure: 'Procedure type',
    lbAllProcedures: 'Alle proceduretypen',
    lbNoProcedures: 'Nog geen procedures geregistreerd in deze periode/selectie.',
    colCount: 'Aantal',
    mdType: 'Type',
    mdTypeArts: 'Arts',
    mdTypeLaborant: 'Laborant',
    mdTypeOverig: 'Overig',

    minmaxAdvies: '📦 Min/Max Advies',
    mmHint: 'Geadviseerd voorraadniveau per product en locatie, op basis van historisch verbruik, piekverbruik, houdbaarheid en levertijd -- we willen nooit meer voorraad aanhouden dan nodig. Twee formules, afhankelijk van de rol van de locatie: kamers worden gegarandeerd dezelfde dag tot hun maximum bijgevuld vanuit Berging, dus een apart reorder-minimum heeft daar nooit een functie gehad -- alleen een maximum (parwaarde, gebaseerd op de drukste dag ooit gezien) is van toepassing. Berging is de enige locatie die daadwerkelijk op een externe leverancier wacht, dus die heeft nog wel een echt reorder-minimum (gemiddeld weekverbruik x (levertijd in weken + een veiligheidsmarge)) naast haar maximum (min + piekweekverbruik x een dekkingsperiode). Beide maxima begrensd zodat het advies nooit meer is dan wat er redelijkerwijs verbruikt kan worden vóórdat de gemiddelde houdbaarheid van het product verstrijkt. Er wordt niets automatisch toegepast -- beoordeel elk advies en pas het zelf toe, per rij.',
    mmEmpty: 'Geen product/locatie-combinaties met op dit moment een ingesteld min of max.',
    mmCurrentMin: 'Huidig min',
    mmCurrentMax: 'Huidig max',
    mmAdvisedMin: 'Advies min',
    mmAdvisedMax: 'Advies max',
    // Kamers hebben geen minimum meer (zie mmHint hierboven)
    // -- getoond in de Huidig min/Advies min-kolommen bij kamer-rijen.
    mmNotApplicable: 'n.v.t.',
    mmBasis: 'Basis',
    mmBasisDayStock: 'Dagvoorraad ({dagen}d): gem. {gemDag}/dag, piek {piekDag}/dag',
    mmBasisLeadTime: 'Levertijd {leverDagen}d ({bron}): gem. {gemWeek}/wk, piek {piekWeek}/wk',
    mmShelfLife: 'Houdbaarheid',
    mmDays: 'dagen',
    mmMonths: 'maanden',
    mmUnknown: 'onbekend',
    mmLeadTimeSource_product: 'eigen orderhistorie van dit product',
    mmLeadTimeSource_globaal: 'globaal gemiddelde over alle producten',
    mmLeadTimeSource_default: 'standaardaanname',
    mmInsufficientData: 'Nog onvoldoende historie ({weken} van de benodigde {minimum} weken) voor een betrouwbaar advies.',
    mmApply: 'Toepassen',
    mmApplying: 'Bezig met toepassen...',
    mmApplied: 'Toegepast',
    mmErrorApplying: 'Fout bij toepassen advies: ',
    mmNegeer: 'Negeren',
    pdAdvies: 'Voorstel',
    pdAdviesOptimaal: 'Al optimaal',
    pdAdviesGeen: '—',
    vaHint: 'Voorraad die op de huidige locatie waarschijnlijk niet op tijd wordt opgemaakt voor de vervaldatum, met de actieve kamer die dit product sneller verbruikt (dus eerder op zou maken) -- verplaatsen is een fysieke handeling via het Verplaatsing-formulier, dit is alleen een advies, er wordt niets automatisch verplaatst. Berging wordt nooit als bestemming voorgesteld, want daar worden kamers juist vanuit bijgevuld, geen verbruikspunt.',
    vaEmpty: 'Op dit moment niets dat op de huidige locatie dreigt te vervallen voordat het is opgemaakt.',
    vaDaysLeft: 'Dagen resterend',
    vaCurrentUsage: 'Verbruik hier',
    vaRecommendedLocation: 'Aanbevolen locatie',
    vaPerDay: '{n}/dag',
    vaInsufficientData: 'onvoldoende verbruikshistorie',
    vaNoFasterLocation: 'Geen snellere locatie gevonden',
  },
};

// Taal is een accountinstelling, geen browser-lokale
// (localStorage) keuze meer -- window.CURRENT_USER.taal komt van de
// PHP-sessie (app.php), dus dit staat al goed bij het eerste renderen,
// zonder flits van de verkeerde taal. login.php (vóór er een account
// bekend is) houdt wél zijn eigen, kleine losse taalkeuze -- zie het
// commentaar daar voor waarom dat een bewuste uitzondering is.
// Een gast (gast-modus) heeft ook geen account om taal op te slaan --
// zie setLanguage() hieronder -- dus die leest zijn keuze terug uit
// localStorage in plaats van window.CURRENT_USER.taal.
let CURRENT_LANG = (window.CURRENT_USER && window.CURRENT_USER.rol === 'gast')
  ? (localStorage.getItem('stocuro_gast_taal') || window.CURRENT_USER.taal || 'en')
  : (window.CURRENT_USER && window.CURRENT_USER.taal) || 'en';

function t(key) {
  return (TRANSLATIONS[CURRENT_LANG] && TRANSLATIONS[CURRENT_LANG][key]) || TRANSLATIONS.en[key] || key;
}

// Zelfde als t(), maar met {placeholder}-vervanging -- voor de weinige
// teksten die een dynamische waarde nodig hebben (aantallen, EAN, etc.)
// zonder de hele zin op te knippen in losse t()-aanroepen.
function tf(key, vars) {
  let s = t(key);
  for (const k in vars) {
    s = s.split('{' + k + '}').join(vars[k]);
  }
  return s;
}

// Past vertaling toe op alle statische [data-i18n]-elementen (modal,
// header) -- de dynamische schermen (showHome() etc.) roepen t() zelf
// aan bij het (opnieuw) renderen.
function applyStaticTranslations() {
  document.querySelectorAll('[data-i18n]').forEach(function (el) {
    el.textContent = t(el.getAttribute('data-i18n'));
  });
  const langSelect = document.getElementById('langSelect');
  if (langSelect) langSelect.value = CURRENT_LANG;
  document.documentElement.lang = CURRENT_LANG;
}

/**
 * Slaat de taalvoorkeur op het account op (api/mijn_taal.php) en herlaadt
 * de pagina -- eenvoudiger en betrouwbaarder dan de hele SPA-staat ter
 * plekke opnieuw te vertalen, en zorgt dat window.CURRENT_USER.taal (uit
 * de PHP-sessie) meteen weer klopt met wat er getoond wordt.
 *
 * Een gast heeft geen account om iets op te slaan -- api/mijn_taal.php
 * zou daar altijd 401 op geven (setEigenTaal() vereist een echte
 * sessie). Voor gast-modus daarom een client-side taalkeuze in
 * localStorage in plaats van een API-aanroep, zelfde patroon als
 * login.php al gebruikt vóór er een account is (zie README).
 */
function setLanguage(lang) {
  if (window.CURRENT_USER && window.CURRENT_USER.rol === 'gast') {
    localStorage.setItem('stocuro_gast_taal', lang);
    window.location.reload();
    return;
  }
  apiPost('api/mijn_taal.php', { taal: lang }, function () {
    window.location.reload();
  }, function (err) {
    alert(err.error || 'Could not save language preference');
  });
}

// =======================================================================
// Vanaf hier: (vrijwel) ongewijzigd overgenomen uit DashboardAll.gs.
// =======================================================================

// =================================================================
// Status-helpers: kale statustekst -> CSS-badge / rijkleur.
// =================================================================
function classifyStatus(text) {
  var t = String(text || '').toUpperCase();
  // Artikel-statussen hergebruiken de bestaande badge-
  // kleuren i.p.v. een eigen stijl te verzinnen -- 'Niet leverbaar' is
  // tijdelijk/aandacht-nodig (zelfde amber als 'monitoren'/'hoog'), 'Niet
  // meer in gebruik' is blijvend en neutraal (geen speciale kleur nodig).
  // Vóór de VERLOPEN-check hieronder, want die matcht anders niet op
  // 'NIET LEVERBAAR' toch geen risico, maar wel voor de duidelijkheid.
  if (t.indexOf('NIET LEVERBAAR') !== -1) return 'monitoren';
  if (t.indexOf('NIET MEER IN GEBRUIK') !== -1) return 'neutraal';
  if (t.indexOf('VERLOPEN') !== -1) return 'verlopen';
  if (t.indexOf('SPOED') !== -1 || t.indexOf('KRITIEK') !== -1) return 'kritiek';
  if (t.indexOf('LEEG') !== -1) return 'leeg';
  if (t.indexOf('LAAG') !== -1) return 'laag';
  if (t.indexOf('HOOG') !== -1) return 'hoog';
  if (t.indexOf('MONITOREN') !== -1) return 'monitoren';
  if (t.indexOf('LANGDURIG') !== -1) return 'langdurig';
  if (t.indexOf('GELDIG') !== -1) return 'geldig';
  if (t.indexOf('ONVOLDOENDE') !== -1) return 'onvoldoende';
  if (t.indexOf('VOLDOENDE') !== -1) return 'voldoende';
  if (t.indexOf('BESTELLING') !== -1 || t.indexOf('ONTVANGEN') !== -1 || t.indexOf('BESTELD') !== -1 || t.indexOf('TE BESTELLEN') !== -1) return 'order';
  if (t.indexOf('OK') !== -1) return 'ok';
  return 'neutraal';
}

// Vertaalt bekende backend-statuscodes (die van oudsher altijd in het
// Nederlands worden geproduceerd door includes/*.php -- zie classifyStatus()
// hierboven) naar de actieve schermtaal, voor gebruik in statusBadgeHtml().
// : de badge-pil toonde voorheen altijd de ruwe
// Nederlandse tekst ("Laag", "Verlopen", "In bestelling", "Monitoren",
// "Leeg", ...) ook wanneer de schermtaal op EN stond, wat opviel in
// screenshots van de live demo. De classificatie/kleur van de badge blijft
// gebaseerd op de originele ruwe tekst (classifyStatus), alleen de
// zichtbare labeltekst wordt hier vertaald.
var STATUS_BADGE_LABEL_KEYS = {
  'VERLOPEN': 'statusBadgeExpired',
  'SPOEDGEBRUIK': 'statusBadgeUrgentUse',
  'MONITOREN': 'statusBadgeMonitor',
  'GELDIG': 'statusBadgeValid',
  'LANGDURIG': 'statusBadgeLongLife',
  'OK': 'statusBadgeOk',
  'LEEG': 'statusBadgeEmpty',
  'LAAG': 'statusBadgeLow',
  'HOOG': 'statusBadgeHigh',
  // ("Stock status in orders: Nog te
  // bestellen" bleef Nederlands): deze status komt uit
  // bestellingenNormaliseer() (de "Handmatig, nog geen order"-rijen op het
  // Bestellingen-scherm) en ontbrak hier, dus viel statusBadgeHtml()
  // hieronder terug op de ruwe tekst. Hergebruikt dezelfde vertaalsleutel
  // als het bijbehorende filterchip-label (bsFilterTeBestellen).
  'NOG TE BESTELLEN': 'bsFilterTeBestellen',
  'IN BESTELLING': 'statusBadgeOnOrder',
  'GEDEELTELIJK ONTVANGEN': 'statusBadgePartiallyReceived',
  'ONTVANGEN': 'statusBadgeReceived',
  'BESTELD': 'statusBadgeOrdered',
  'AANGEVRAAGD': 'statusBadgeRequested',
  'GEBRUIKT': 'statusBadgeUsed',
  'RETOURNEREN': 'statusBadgeReturned',
  'ACTIEF': 'statusActief',
  'NIET LEVERBAAR': 'statusNietLeverbaar',
  'NIET MEER IN GEBRUIK': 'statusNietMeerInGebruik',
};

function statusBadgeHtml(text) {
  var raw = String(text || '').trim();
  if (!raw || raw === '-') return '-';
  var labelKey = STATUS_BADGE_LABEL_KEYS[raw.toUpperCase()];
  var label = labelKey ? t(labelKey) : raw;
  return '<span class="badge badge-' + classifyStatus(raw) + '">' + escapeHtml(label) + '</span>';
}

// ("Order details, sommige velden blijven in het
// Nederlands"): zelfde vertaal-lookup als statusBadgeHtml() hierboven
// (STATUS_BADGE_LABEL_KEYS), maar als platte, vertaalde tekst i.p.v. een
// badge-<span> -- voor plekken waar de status in een zin geïnterpoleerd
// wordt (odStatusHint, mailto-inhoud) en een badge niet past.
function statusDisplayLabel(text) {
  var raw = String(text || '').trim();
  if (!raw || raw === '-') return '-';
  var labelKey = STATUS_BADGE_LABEL_KEYS[raw.toUpperCase()];
  return labelKey ? t(labelKey) : raw;
}

// ("Error Log: Product niet gevonden in
// artikelen" bleef Nederlands): includes/logging.php::logError() schrijft
// vaste, technische berichten naar de foutlog-tabel -- de meeste komen uit
// PHP/PDO-exceptions ($e->getMessage()) en zijn dus niet zomaar vooraf te
// vertalen (elke boodschap is anders), maar een handvol aanroepen geven een
// vaste, letterlijke string mee (zie includes/transacties.php::
// EAN_NOT_IN_ARTIKELEN). Deze kleine lookup vertaalt de bekende vaste
// berichten en laat al het overige onvertaald staan i.p.v. te crashen op
// een onbekend bericht.
var FOUTLOG_BERICHT_KEYS = {
  'Product niet gevonden in artikelen': 'flBerichtEanOnbekend',
};
function foutlogBerichtLabel(bericht) {
  var raw = String(bericht || '');
  var key = FOUTLOG_BERICHT_KEYS[raw];
  return key ? t(key) : raw;
}

// : dezelfde drie besteltype-waarden (Automatisch/
// Handmatig/Eenmalig, zie bestelMethode() in includes/orders.php en het
// besteltype-veld op artikelen) worden op meerdere schermen getoond --
// Artikel Toevoegen (naBesteltype-select) had al de juiste vertaalsleutels
// (apOrderTypeAuto/Manual/Once), maar Order Detail en Productdetailpagina
// toonden de ruwe Nederlandse waarde ongevertaald. Eén gedeelde helper
// i.p.v. de mapping op drie plekken te dupliceren.
function orderTypeLabel(type) {
  var map = {
    'Automatisch': t('apOrderTypeAuto'),
    'Handmatig': t('apOrderTypeManual'),
    'Eenmalig': t('apOrderTypeOnce'),
  };
  return map[type] || type;
}

function statusRowClass(text) {
  var c = classifyStatus(text);
  if (c === 'verlopen' || c === 'kritiek') return 'critical';
  if (c === 'monitoren') return 'warning';
  return '';
}

let currentPage = 'home';

// =======================================================================
// ROUTER: hash-gebaseerde navigatie (#dashboard/param), zodat de
// browser-URL per scherm verandert en de terug/vooruit-knoppen echt
// tussen schermen bewegen -- voorheen bleef de URL altijd op app.php
// staan (alle navigatie was puur innerHTML-vervanging zonder
// history.pushState/hash), dus "terug" in de browser sprong de hele app
// uit i.p.v. één stap terug (inmiddels opgelost).
//
// Aanpak: elke showXxx()-functie roept als allereerste statement
// setRoute('routesleutel'[, param]) aan. setRoute() zet de hash en doet
// history.pushState() bij normale klik-navigatie, of history.replaceState()
// wanneer we ZELF een terug/vooruit-actie aan het afhandelen zijn
// (navigatingViaHistory) -- dat voorkomt dat het opnieuw renderen van een
// scherm via popstate zelf weer een nieuwe (dubbele) history-entry
// aanmaakt. ROUTE_DISPATCH kent elke routesleutel terug naar de bijpassende
// showXxx()-aanroep, voor gebruik door zowel de popstate-listener als de
// initiële routebepaling bij het laden van de pagina (onderaan dit bestand).
//
// Twee routes (eenmalige-bestelling-edit, producent-edit) roepen hun
// showXxxEdit()-functie niet direct aan vanuit ROUTE_DISPATCH, omdat die
// functies een al-opgehaald data-object verwachten (efficiënt bij een
// gewone klik op "Bewerken" vanaf de detailpagina) -- bij directe
// navigatie via terug/vooruit of een geplakte link is alleen het id
// bekend, dus halen twee kleine loader-wrappers de data opnieuw op via
// de API voordat ze de bewerkfunctie alsnog aanroepen. categorie-detail
// heeft een vergelijkbare wrapper omdat showCategorieDetail() leunt op
// catOverzichtData, dat pas gevuld is nadat showCategorien() heeft
// gedraaid.
// =======================================================================
let navigatingViaHistory = false;

function setRoute(key, param) {
  // De zijbalk-highlight hangt hier
  // en niet ergens verderop, want setRoute() is de ene plek die elke
  // showXxx() als allereerste statement altijd aanroept (zie toelichting
  // hierboven) -- ook wanneer de hash toevallig al klopt (early return
  // hieronder) en dus zonder deze regel de highlight nooit bijgewerkt
  // zou worden, bv. bij de allereerste paginalaad.
  updateSidebarActive(key);
  const hash = '#' + key + (param !== undefined && param !== null && param !== '' ? '/' + encodeURIComponent(String(param)) : '');
  if (window.location.hash === hash) return;
  const state = { key: key, param: param !== undefined ? param : null };
  if (navigatingViaHistory) {
    history.replaceState(state, '', hash);
  } else {
    history.pushState(state, '', hash);
  }
}

function loadCategorieDetail(cat) {
  if (catOverzichtData) {
    showCategorieDetail(cat);
  } else {
    StocuroRun.withSuccessHandler(function (data) {
      catOverzichtData = data;
      showCategorieDetail(cat);
    }).getCategorieOverzicht();
  }
}

function loadEenmaligeBestellingEdit(id) {
  StocuroRun.withSuccessHandler(function (data) {
    showEenmaligeBestellingEdit(data);
  }).getEenmaligeBestellingDetail(id);
}

function loadProducentEdit(id) {
  StocuroRun.withSuccessHandler(function (data) {
    showProducentEdit(data);
  }).getProducentDetail(id);
}

// Onderstaande sleutels zijn de
// bookmarks/landingsscherm-waarden van vóór deze migratie (oude losse
// tabblad-pagina's). De onderliggende show*()-functies bestaan allemaal
// nog gewoon (interne tabbalk-arrays als VOORRAAD_TABS/ORDER_TABS_BASIS
// roepen ze nog rechtstreeks aan, buiten ROUTE_DISPATCH om -- zie de
// toelichting bovenaan dit bestand), maar een oude bookmark of een
// groepen.landing_scherm-waarde die nog naar zo'n sleutel wijst, opent nu
// gewoon de nieuwe samengevoegde pagina die diezelfde data toont.
const ROUTE_DISPATCH = {
  'home': function () { showHome(); },
  'overzicht': function () { showVoorraad(); },
  'recente-transacties': function () { showRecenteTransacties(); },
  'taken-beheerder': function () { showTaken(); },
  'taken-laborant': function () { showTaken(); },
  'telling': function (param) { showTelling(param); },
  'afboekingen': function () { showAfboekingen(); },
  'product': function (param) { showProductDetail(param); },
  'product-edit': function (param) { showProductEdit(param); },
  'product-search': function () { showProductSearch(); },
  'boodschappenlijst': function () { showVoorraad(); },
  'berging': function () { showVoorraad(); },
  'vervaldatums': function () { showVoorraad(); },
  'lot-tracking': function () { showVoorraad(); },
  'verbruik': function () { showVerbruik(); },
  'bestellers': function () { showBestellingen(); },
  'voorraadwaarde': function () { showVoorraad(); },
  'actiecentrum': function () { showVoorraad(); },
  'vervalfocus': function () { showVoorraad(); },
  'categorien': function () { showCategorien(); },
  'categorie-detail': function (param) { loadCategorieDetail(param); },
  'open-orders': function () { showBestellingen(); },
  'order-detail': function (param) { showOrderDetail(param); },
  'mijn-bestellingen': function () { showBestellingen(); },
  'eenmalige-bestelling-detail': function (param) { showEenmaligeBestellingDetail(param); },
  'eenmalige-bestelling-edit': function (param) { loadEenmaligeBestellingEdit(param); },
  'trends': function () { showTrends(); },
  'leaderboard': function () { showLeaderboard(); },
  'minmax-advies': function () { showVoorraad(); },
  'verplaatsadvies': function () { showVoorraad(); },
  'foutlog': function () { showFoutlog(); },
  'onbekende-producten': function () { showOnbekendeProducten(); },
  'scan-ontvangst': function () { showScanOntvangst(); },
  'scan-verbruik': function () { showScanVerbruik(); },
  'verval-afboeken': function () { showVervalAfboeken(); },
  'verplaatsing': function () { showVerplaatsing(); },
  'bijvullen': function () { showBijvullen(); },
  'eenmalige-bestelling': function () { showEenmaligeBestelling(); },
  'artikel-toevoegen': function () { showArtikelToevoegen(); },
  'stamgegevens': function () { showStamgegevens(); },
  'locaties': function () { showLocatiesBeheer(); },
  'gebruikers': function () { showGebruikers(); },
  'groepen': function () { showGroepen(); },
  'groep-detail': function (param) { showGroepDetail(param); },
  'producenten': function () { showContacten(); },
  'nieuws': function () { showNieuwsDashboard(); },
  'team': function () { showTeamOverzicht(); },
  'contactpersonen': function () { showContacten(); },
  'beschikbaarheid': function () { showVoorraad(); },
  'management-cockpit': function () { showManagementCockpit(); },
  'voorraad': function () { showVoorraad(); },
  'bestellingen': function () { showBestellingen(); },
  'contacten': function () { showContacten(); },
  'taken': function () { showTaken(); },
  'producent-detail': function (param) { showProducentDetail(param); },
  'producent-edit': function (param) { loadProducentEdit(param); },
  // ("Licentiemodel:
  // registratie blokkeren zonder geldige licentie + profielpagina"): elke
  // ingelogde gebruiker (geen gast) kan hier eigen accountgegevens +
  // licentiestatus bekijken en zijn eigen wachtwoord wijzigen. Zie
  // showProfiel() verderop in dit bestand en api/mijn_profiel.php.
  'profiel': function () { showProfiel(); },
};

function dispatchRoute(state) {
  if (!state || !state.key || !ROUTE_DISPATCH[state.key]) {
    showHome();
    return;
  }
  ROUTE_DISPATCH[state.key](state.param);
}

window.addEventListener('popstate', function (event) {
  navigatingViaHistory = true;
  try {
    dispatchRoute(event.state);
  } finally {
    navigatingViaHistory = false;
  }
});

// Alle "Terug"-knoppen in de app gingen
// naar een vast, per-scherm hardgecodeerd doel (meestal showHome(), soms
// iets specifieks) -- dat voelde niet als "terug", eerder als "naar een
// vaste plek springen". Omdat elke showXxx() al via setRoute() hierboven
// een eigen history-entry krijgt (pushState), is er niets extra's nodig
// om een ECHTE stap terug te doen: window.history.back() triggert de
// popstate-listener hierboven, die het vorige scherm precies zo opnieuw
// toont als waar je vandaan kwam. Alle wire('backBtn', ...)-aanroepen in
// dit bestand zijn hierop omgezet (zie het script dat dat mechanisch
// heeft gedaan) -- geen enkele meer naar een specifiek showXxx()-scherm.
function goBack() {
  window.history.back();
}

// Of een scherm
// getoond wordt hangt nu af van window.CURRENT_USER.schermen (server-side
// berekend in app.php uit de groepen van deze gebruiker, zie includes/
// groepen.php::gebruikerSchermen()) i.p.v. losse rol/is_beheerder-checks
// per knop. Dit is ook echt afgedwongen, niet alleen cosmetisch: elk
// endpoint doet dezelfde check server-side via apiRequireScherm() (zie
// includes/api_helpers.php) -- een verborgen knop hier betekent dus ook
// een 403 bij een directe aanroep, niet alleen een onzichtbare knop.
function heeftScherm(schermKey) {
  return !!(window.CURRENT_USER && Array.isArray(window.CURRENT_USER.schermen) && window.CURRENT_USER.schermen.indexOf(schermKey) !== -1);
}

/** Kort hulpje voor attachHomeHandlers() hieronder: knoppen die niet in het menu staan (heeftScherm() === false) bestaan simpelweg niet in de DOM, dus altijd een null-check vóór addEventListener(). */
function wire(id, handler) {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', handler);
}

// =================================================================
// APP-SCHIL: vaste zijbalk-navigatie,
// : "Mobiel/Tablet is niet van toepassing, alles
// gebeurt vanaf een computer" -- dus een permanente desktop-zijbalk i.p.v.
// bv. een mobiele onderbalk, zodat je nooit meer eerst terug naar Home
// hoeft om naar een ander dashboard te springen. Zelfde heeftScherm()-
// gates en dezelfde show*()-functies als showHome() hieronder -- geen
// nieuwe navigatielogica, alleen een blijvend zichtbare kopie ernaast.
// #content/render*() weten niets van het bestaan van de zijbalk. Wordt
// precies één keer opgebouwd (renderSidebar(), aangeroepen onderaan dit
// bestand vóór bootstrapRoute()); de actieve link daarna wordt bijgehouden
// door setRoute() (zie updateSidebarActive() hieronder).
// =================================================================
function sidebarSectionHtml(labelKey, itemsHtml) {
  if (!itemsHtml) return '';
  return '<div class="sidebar-group"><div class="sidebar-group-label">' + t(labelKey) + '</div>' + itemsHtml + '</div>';
}

// routeKeys: spatiegescheiden lijst van routesleutels die deze knop als
// "actief" moet tonen -- meestal maar één sleutel, behalve bij de
// tabbalk-hubs (Voorraad/Vervaldatums/Bestellingen/Verbruik & Trends/
// Team & Prestaties/Producten): daar is elke onderliggende tab-route ook
// een "match" voor de ene hub-knop in de zijbalk.
function sidebarBtnHtml(id, routeKeys, label) {
  return '<button type="button" class="sidebar-link" id="' + id + '" data-route-keys="' + escapeAttr(routeKeys) + '">' + label + '</button>';
}

function updateSidebarActive(key) {
  document.querySelectorAll('.sidebar-link').forEach(function (btn) {
    const keys = (btn.getAttribute('data-route-keys') || '').split(' ');
    btn.classList.toggle('active', !!key && keys.indexOf(key) !== -1);
  });
}

function renderSidebar() {
  const nav = document.getElementById('sidebarNav');
  if (!nav) return;

  let html = sidebarBtnHtml('sbHome', 'home', '🏠 ' + t('sidebarHome'));

  let forms = '';
  if (heeftScherm('scan-verbruik')) forms += sidebarBtnHtml('sbScanVerbruik', 'scan-verbruik', t('scanUsage'));
  if (heeftScherm('scan-ontvangst')) forms += sidebarBtnHtml('sbScanOntvangst', 'scan-ontvangst', t('receiveStock'));
  if (heeftScherm('verplaatsing')) forms += sidebarBtnHtml('sbVerplaatsing', 'verplaatsing', t('transferReturns'));
  if (heeftScherm('bestellers')) forms += sidebarBtnHtml('sbBestellers', 'bestellers', t('manualOrder'));
  if (heeftScherm('eenmalige-bestelling')) forms += sidebarBtnHtml('sbEenmaligeBestelling', 'eenmalige-bestelling', t('oneOffOrder'));
  if (heeftScherm('artikel-toevoegen')) forms += sidebarBtnHtml('sbArtikelToevoegen', 'artikel-toevoegen', t('addProduct'));
  if (heeftScherm('verval-afboeken')) forms += sidebarBtnHtml('sbVervalAfboeken', 'verval-afboeken', t('writeOffExpired'));
  html += sidebarSectionHtml('forms', forms);

  // ("diepere
  // oplossing" i.p.v. schermen alleen achter een andere tabblad
  // verplaatsen): de vijf subgroepen hierboven (actie/voorraad/verval/
  // bestellen/rapportage, samen 16 bestemmingen) zijn vervangen door 8
  // ECHTE pagina's -- elk oud scherm bestaat als databron nog steeds
  // (zie includes/voorraad_tabel.php voor de toelichting), maar de
  // navigatie ernaartoe is teruggebracht tot deze ene, platte lijst.
  let dash = '';
  if (heeftScherm('taken-beheerder') || heeftScherm('taken-laborant')) {
    dash += sidebarBtnHtml('sbTaken', 'taken taken-beheerder taken-laborant', t('tkTitle'));
  }
  if (heeftScherm('recente-transacties')) dash += sidebarBtnHtml('sbRecenteTransacties', 'recente-transacties', t('recentTransactions'));
  const voorraadSchermen = VOORRAAD_TABS.concat(VERVAL_TABS);
  if (voorraadSchermen.some(function (tab) { return heeftScherm(tab[0]); })
    || heeftScherm('verplaatsadvies') || heeftScherm('beschikbaarheid') || heeftScherm('actiecentrum')) {
    dash += sidebarBtnHtml('sbVoorraad', 'voorraad overzicht boodschappenlijst berging voorraadwaarde minmax-advies vervaldatums lot-tracking vervalfocus verplaatsadvies beschikbaarheid actiecentrum', t('stockHub'));
  }
  if (heeftScherm('verplaatsing')) dash += sidebarBtnHtml('sbBijvullen', 'bijvullen', t('bvTitle'));
  // Was orderTabsVoorGebruiker().some(...) -- die hielp
  // hier alleen om de drie oude, nu vervallen tabbladschermen
  // (bestellers/open-orders/mijn-bestellingen) na te lopen. Rechtstreeks
  // dezelfde drie scherm-keys checken, geen rolgate meer nodig (zie
  // showMijnBestellingen() hierboven).
  if (['bestellingen', 'bestellers', 'open-orders', 'mijn-bestellingen'].some(heeftScherm)) {
    dash += sidebarBtnHtml('sbBestellingen', 'bestellingen bestellers open-orders mijn-bestellingen', t('ordersHub'));
  }
  if (heeftScherm('management-cockpit')) dash += sidebarBtnHtml('sbManagementCockpit', 'management-cockpit', t('managementCockpitTitle'));
  if (PRODUCT_TABS.some(function (tab) { return heeftScherm(tab[0]); })) {
    dash += sidebarBtnHtml('sbProductHub', PRODUCT_TABS.map(function (tab) { return tab[0]; }).join(' '), t('productsHub'));
  }
  if (heeftScherm('producenten') || heeftScherm('contactpersonen')) {
    dash += sidebarBtnHtml('sbContacten', 'contacten producenten contactpersonen', t('ctTitle'));
  }
  if (heeftScherm('nieuws')) dash += sidebarBtnHtml('sbNieuws', 'nieuws', t('newsTitle'));
  html += sidebarSectionHtml('dashboards', dash);

  const adminSchermen = ['gebruikers', 'groepen', 'stamgegevens', 'locaties', 'foutlog', 'onbekende-producten'];
  if (adminSchermen.some(heeftScherm)) {
    // Geen 3 nieuwe samengevoegde
    // beheerpagina's (te veel risico op de onderliggende CRUD-logica in de
    // beschikbare tijd) -- wel visueel opgeknipt in 3 herkenbare groepjes
    // (Toegang/Instellingen/Datakwaliteit) i.p.v. 1 platte rij van 6 knoppen.
    let toegang = '';
    if (heeftScherm('gebruikers')) toegang += sidebarBtnHtml('sbGebruikers', 'gebruikers', t('users'));
    if (heeftScherm('groepen')) toegang += sidebarBtnHtml('sbGroepen', 'groepen', t('groepenTitle'));
    let instellingen = '';
    if (heeftScherm('stamgegevens')) instellingen += sidebarBtnHtml('sbStamgegevens', 'stamgegevens', t('masterData'));
    if (heeftScherm('locaties')) instellingen += sidebarBtnHtml('sbLocaties', 'locaties', t('locations'));
    let datakwaliteit = '';
    if (heeftScherm('foutlog')) datakwaliteit += sidebarBtnHtml('sbFoutlog', 'foutlog', t('errorLog'));
    if (heeftScherm('onbekende-producten')) datakwaliteit += sidebarBtnHtml('sbOnbekendeProducten', 'onbekende-producten', t('unknownProducts'));

    html += '<div class="sidebar-group"><div class="sidebar-group-label">' + t('admin') + '</div>';
    if (toegang) html += '<div class="sidebar-subgroup-label">' + t('adminGroupAccess') + '</div>' + toegang;
    if (instellingen) html += '<div class="sidebar-subgroup-label">' + t('adminGroupSettings') + '</div>' + instellingen;
    if (datakwaliteit) html += '<div class="sidebar-subgroup-label">' + t('adminGroupDataQuality') + '</div>' + datakwaliteit;
    html += '</div>';
  }

  nav.innerHTML = html;

  wire('sbHome', showHome);
  wire('sbScanVerbruik', showScanVerbruik);
  wire('sbScanOntvangst', showScanOntvangst);
  wire('sbVerplaatsing', showVerplaatsing);
  // : linkte per ongeluk nog naar het verweesde,
  // pre-consolidatie showBestellers()-scherm (met eigen terugknop) i.p.v.
  // de huidige "Bestellingen"-hub -- ROUTE_DISPATCH['bestellers'] wijst
  // al langer naar showBestellingen(), deze knop nu ook.
  wire('sbBestellers', showBestellingen);
  wire('sbEenmaligeBestelling', showEenmaligeBestelling);
  wire('sbArtikelToevoegen', showArtikelToevoegen);
  wire('sbVervalAfboeken', showVervalAfboeken);
  wire('sbTaken', showTaken);
  wire('sbRecenteTransacties', showRecenteTransacties);
  wire('sbVoorraad', showVoorraad);
  wire('sbBijvullen', showBijvullen);
  wire('sbBestellingen', showBestellingen);
  wire('sbManagementCockpit', showManagementCockpit);
  wire('sbProductHub', showProductHub);
  wire('sbContacten', showContacten);
  wire('sbNieuws', showNieuwsDashboard);
  wire('sbGebruikers', showGebruikers);
  wire('sbGroepen', showGroepen);
  wire('sbStamgegevens', showStamgegevens);
  wire('sbLocaties', showLocatiesBeheer);
  wire('sbFoutlog', showFoutlog);
  wire('sbOnbekendeProducten', showOnbekendeProducten);
}

// Zoekveld boven de zijbalk: puur client-side tekstfilter over de al
// gerenderde knoppen (geen aparte command-palette-component) -- filtert
// zowel losse knoppen als hele groepen (incl. groepslabel) weg zodra er
// binnen die groep niets meer overblijft dat matcht.
function wireSidebarSearch() {
  const input = document.getElementById('sidebarSearchInput');
  if (!input) return;
  input.placeholder = t('sidebarSearchPlaceholder');
  input.addEventListener('keyup', function () {
    const query = this.value.toLowerCase().trim();
    document.querySelectorAll('#sidebarNav .sidebar-group').forEach(function (group) {
      let anyVisible = false;
      group.querySelectorAll('.sidebar-link').forEach(function (btn) {
        const match = !query || btn.textContent.toLowerCase().includes(query);
        btn.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
      });
      group.style.display = anyVisible ? '' : 'none';
    });
    const home = document.getElementById('sbHome');
    if (home) home.style.display = (!query || home.textContent.toLowerCase().includes(query)) ? '' : 'none';
  });
}

function showHome() {
  setRoute('home');
  currentPage = 'home';

  // De "Mijn Bestellingen"-widget was
  // hier hardcoded beperkt tot rol specialist/laborant, BOVENOP de
  // heeftScherm('mijn-bestellingen')-groepscheck. Nu "Mijn Bestellingen"
  // altijd de eigen (besteller = ingelogde gebruiker) orders/aanvragen
  // toont -- niet meer specifiek de klinische aanvragen VOOR een
  // specialist -- is die extra rolbeperking niet meer op zijn plaats: elke
  // gebruiker die zelf iets bestelt, mag dat kunnen terugzien. De
  // groepscheck (heeftScherm) blijft de enige poort.

  // Forms en Admin (Toegang/
  // Instellingen/Datakwaliteit) zijn hier nu OOK verwijderd, om dezelfde
  // reden als eerder al bij de Dashboards-sectie -- elke
  // bestemming staat al, altijd zichtbaar, in de linker zijbalk (zie
  // renderSidebar() hierboven), dus dit was gewoon een tweede, dubbele
  // knoppenlijst op de startpagina. Home bestaat vanaf nu alleen nog uit
  // de widgets hieronder (Mijn Bestellingen/Nieuws/Wie is aan de
  // beurt/Top 10 Scanners) -- samengestelde, direct bruikbare info in
  // plaats van nóg een menu.
  let html = '<div id="homeWidgets"></div>';

  document.getElementById('content').innerHTML = html;
  attachHomeHandlers();
  laadHomeWidgets();
}

/**
 * Home toont voortaan vier
 * samengestelde widgets i.p.v. alleen een kaal menu -- status van eigen
 * eenmalige bestellingen, Nieuws-samenvatting, PICC-rotatie ("wie is aan de
 * beurt") en Top 10 Scanners. Zelfde compositie-aanpak als het Management
 * Cockpit hierboven: uitsluitend bestaande (of, voor de rotatie, één nieuw
 * maar puur leeswerk componerend) endpoints combineren, geen nieuwe
 * bedrijfslogica. Elke widget is optioneel: ontbreekt het onderliggende
 * scherm-recht, dan wordt die widget gewoon overgeslagen (en toont Home,
 * net als voorheen, alsnog gewoon het menu).
 *
 * Vier StocuroRun-aanroepen worden hier bewust PARALLEL afgevuurd (niet in
 * elkaar geschoven) -- dat is veilig: callApi() sluit de success/failure-
 * handler per aanroep in een eigen closure (zie createScriptRunProxy()
 * hierboven), dus de aanroepen kunnen elkaar niet overschrijven. Dezelfde
 * aanpak wordt hierboven al gebruikt voor de twee taken-badge-aanroepen.
 */
function laadHomeWidgets() {
  const magNieuws = heeftScherm('nieuws');
  const magLeaderboard = heeftScherm('leaderboard');
  // Niet langer beperkt tot rol specialist/laborant, zie de
  // toelichting bij showHome() hierboven.
  const magMijnBestellingen = heeftScherm('mijn-bestellingen');
  // ("taken widget toevoegen"): vervangt
  // de losse, puur cosmetische takenBadge-telling die hier stond -- dat
  // richtte zich op een 'takenBadge'-element dat allang niet meer bestaat
  // (dode code sinds de zijbalk-migratie), dus de twee getTaken*()-
  // aanroepen leverden nooit zichtbaar resultaat op. Nu tellen dezelfde
  // twee aanroepen mee als vijfde widget, met echte inhoud i.p.v. een
  // badge die nergens meer aan hing.
  const magTakenBeheerder = heeftScherm('taken-beheerder');
  const magTakenLaborant = heeftScherm('taken-laborant');
  // ("materiaal wat over datum dreigt te
  // gaan" + "verplaats advies"): zelfde hergebruik-aanpak als de andere
  // widgets hierboven -- geen nieuwe endpoints, gewoon de bestaande
  // getVervaldatumFocus()/getVerplaatsAdviesOverzicht() (die ook de
  // volledige Vervaldatums&Lotbeheer- resp. Verplaatsadvies-pagina's voeden)
  // hier nogmaals aanroepen voor een compacte Home-samenvatting.
  const magVervalfocus = heeftScherm('vervalfocus');
  const magVerplaatsadvies = heeftScherm('verplaatsadvies');
  // ("Home: sectie
  // met gebruikers/licentie-informatie"): dit hergebruikt gewoon de
  // bestaande api/gebruikers.php GET (beheerder-only, apiRequireBeheerder())
  // -- geen nieuw endpoint, exact dezelfde "alleen bestaande endpoints
  // componeren"-aanpak als alle andere Home-widgets. Daarom hier gegate op
  // window.CURRENT_USER.is_beheerder (net als de rest van de app dat
  // vinkje gebruikt) i.p.v. heeftScherm(), want dat scherm-recht bestaat
  // niet los van het is_beheerder-vinkje (zie includes/auth.php::
  // requireBeheerder()).
  const magGebruikersLicentie = !!(window.CURRENT_USER && window.CURRENT_USER.is_beheerder);
  if (!magNieuws && !magLeaderboard && !magMijnBestellingen && !magTakenBeheerder && !magTakenLaborant
    && !magVervalfocus && !magVerplaatsadvies && !magGebruikersLicentie) return;

  let bestellingenData = null, eigenOrdersData = null, nieuwsData = null, leaderboardData = null, piccData = null;
  let takenBeheerderData = null, takenLaborantData = null;
  let vervalfocusData = null, verplaatsadviesData = null;
  let gebruikersLicentieData = null;
  // MagMijnBestellingen telt nu voor 2 (twee bronnen -- zie
  // toelichting bij de twee aanroepen hieronder).
  let nogTeLaden = (magMijnBestellingen ? 2 : 0) + (magNieuws ? 1 : 0) + (magLeaderboard ? 2 : 0)
    + (magTakenBeheerder ? 1 : 0) + (magTakenLaborant ? 1 : 0)
    + (magVervalfocus ? 1 : 0) + (magVerplaatsadvies ? 1 : 0)
    + (magGebruikersLicentie ? 1 : 0);

  function klaarAlsAlles() {
    if (nogTeLaden > 0) return;
    renderHomeWidgets(magMijnBestellingen, bestellingenData, eigenOrdersData, magNieuws, nieuwsData, magLeaderboard, leaderboardData, piccData,
      magTakenBeheerder, takenBeheerderData, magTakenLaborant, takenLaborantData,
      magVervalfocus, vervalfocusData, magVerplaatsadvies, verplaatsadviesData,
      magGebruikersLicentie, gebruikersLicentieData);
  }
  if (magMijnBestellingen) {
    // ("ik zie niet op het homescherm al
    // mijn bestellingen terug"): deze widget riep tot nu toe ALLEEN
    // getEigenEenmaligeBestellingen() aan -- reguliere voorraad-orders
    // (waar de ingelogde gebruiker ook gewoon besteller van kan zijn)
    // kwamen daar nooit in mee. Twee aparte, parallelle aanroepen (zelfde
    // veilige patroon als de rest van deze functie), samengevoegd in
    // renderHomeWidgets() hieronder.
    StocuroRun.withSuccessHandler(function (data) { bestellingenData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getEigenEenmaligeBestellingen(1);
    StocuroRun.withSuccessHandler(function (data) { eigenOrdersData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getEigenOpenOrders(1);
  }
  if (magNieuws) {
    StocuroRun.withSuccessHandler(function (data) { nieuwsData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getNieuwsDashboard();
  }
  if (magLeaderboard) {
    StocuroRun.withSuccessHandler(function (data) { leaderboardData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getLeaderboardData('maand', '');
    StocuroRun.withSuccessHandler(function (data) { piccData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getPiccRotatie('picc_rotatie');
  }
  if (magTakenBeheerder) {
    StocuroRun.withSuccessHandler(function (data) { takenBeheerderData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getTakenBeheerder();
  }
  if (magTakenLaborant) {
    StocuroRun.withSuccessHandler(function (data) { takenLaborantData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getTakenLaborant();
  }
  if (magVervalfocus) {
    StocuroRun.withSuccessHandler(function (data) { vervalfocusData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getVervaldatumFocus();
  }
  if (magVerplaatsadvies) {
    StocuroRun.withSuccessHandler(function (data) { verplaatsadviesData = data; nogTeLaden--; klaarAlsAlles(); })
      .withFailureHandler(function () { nogTeLaden--; klaarAlsAlles(); }).getVerplaatsAdviesOverzicht();
  }
  if (magGebruikersLicentie) {
    // Api/gebruikers.php is GEEN
    // StocuroRun-methode (zie showGebruikers() verderop -- dat scherm haalt
    // 'm ook al rechtstreeks op via fetch()), dus hier hetzelfde patroon
    // i.p.v. via de StocuroRun-proxy.
    fetch('api/gebruikers.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { gebruikersLicentieData = data; nogTeLaden--; klaarAlsAlles(); })
      .catch(function () { nogTeLaden--; klaarAlsAlles(); });
  }
}

function renderHomeWidgets(magMijnBestellingen, bestellingen, eigenOrders, magNieuws, nieuws, magLeaderboard, leaderboard, piccRotaties,
  magTakenBeheerder, takenBeheerder, magTakenLaborant, takenLaborant,
  magVervalfocus, vervalfocus, magVerplaatsadvies, verplaatsadvies,
  magGebruikersLicentie, gebruikersLicentie) {
  const container = document.getElementById('homeWidgets');
  if (!container) return; // van pagina gewisseld terwijl dit nog laadde

  let html = '<div class="home-widgets">';

  if (magMijnBestellingen) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwMijnBestellingen') + '</div>';
    // Deze widget combineert nu twee
    // bronnen -- eigen eenmalige (patiëntgebonden) bestellingen én eigen
    // reguliere voorraad-orders, waar de ingelogde gebruiker besteller of
    // (voor eenmalig) specialist van is. getEigenOpenOrders() geeft altijd
    // al alleen open orders terug (zie includes/orders.php), dus daar is
    // geen aparte statusfilter meer nodig zoals bij eenmalig hieronder.
    const openEenmalig = (bestellingen || []).filter(function (b) { return ['Gebruikt', 'Retourneren'].indexOf(b.status) === -1; })
      .map(function (b) { return { naam: b.artikelomschrijving, status: b.status }; });
    const openOrders = (eigenOrders || []).map(function (o) { return { naam: o.naam, status: o.status }; });
    const geenData = !bestellingen && !eigenOrders;
    const open = openEenmalig.concat(openOrders);
    if (geenData) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else if (open.length === 0) {
      html += '<p class="empty">' + t('hwGeenOpenBestellingen') + '</p>';
    } else {
      html += '<ul>';
      open.slice(0, 5).forEach(function (b) {
        html += '<li>' + escapeHtml(b.naam || '-') + ' -- ' + statusBadgeHtml(b.status) + '</li>';
      });
      html += '</ul>';
      if (open.length > 5) html += '<p class="form-hint">' + tf('hwEnMeer', { n: open.length - 5 }) + '</p>';
    }
    html += '<a class="mc-drill-link" id="hwNaarMijnBestellingen">' + t('hwNaarMijnBestellingen') + ' →</a></div>';
  }

  if (magNieuws) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwNieuws') + '</div>';
    if (!nieuws) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else {
      const nieuwste = nieuws.nieuwsteProducten || [];
      const vervangers = nieuws.vervangers || [];
      if (nieuwste.length === 0 && vervangers.length === 0) {
        html += '<p class="empty">' + t('hwGeenNieuws') + '</p>';
      } else {
        html += '<ul>';
        nieuwste.slice(0, 3).forEach(function (p) { html += '<li>' + tf('hwNieuwProduct', { naam: escapeHtml(p.naam) }) + '</li>'; });
        vervangers.slice(0, 2).forEach(function (v) { html += '<li>' + tf('hwVervanger', { naam: escapeHtml(v.naam), vervanger: escapeHtml(v.vervangerNaam) }) + '</li>'; });
        html += '</ul>';
      }
    }
    html += '<a class="mc-drill-link" id="hwNaarNieuws">' + t('hwNaarNieuws') + ' →</a></div>';
  }

  if (magLeaderboard) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwPiccTitle') + '</div>';
    if (!piccRotaties) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else {
      html += piccRotatieHtml(piccRotaties);
    }
    html += '<a class="mc-drill-link" id="hwNaarLeaderboardPicc">' + t('hwNaarLeaderboard') + ' →</a></div>';

    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwTopScanners') + '</div>';
    if (!leaderboard || !leaderboard.scans || leaderboard.scans.length === 0) {
      html += '<p class="empty">' + t('lbNoScans') + '</p>';
    } else {
      html += '<ul>';
      leaderboard.scans.slice(0, 10).forEach(function (r, idx) {
        html += '<li>' + (idx + 1) + '. ' + escapeHtml(r.naam) + ' -- <strong>' + r.aantal + '</strong></li>';
      });
      html += '</ul>';
    }
    html += '<a class="mc-drill-link" id="hwNaarLeaderboardScanners">' + t('hwNaarLeaderboard') + ' →</a></div>';
  }

  // ("taken widget toevoegen"): zelfde
  // opzet als de andere widgets -- een paar regels concrete inhoud i.p.v.
  // alleen een kaal getal. Combineert magTakenBeheerder/magTakenLaborant
  // in ÉÉN kaart (zoals showTaken() ze ook als één pagina met rolschakelaar
  // toont), met per onderliggende categorie een regel als er iets open
  // staat -- een lege categorie levert gewoon geen regel op, in plaats van
  // een "0"-regel die niemand iets vertelt.
  if (magTakenBeheerder || magTakenLaborant) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwTakenTitle') + '</div>';
    if (!takenBeheerder && !takenLaborant && (magTakenBeheerder || magTakenLaborant)) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else {
      const regels = [];
      if (takenLaborant) {
        if (takenLaborant.kastcontrole.length > 0) regels.push(tf('hwTakenKastcontrole', { n: takenLaborant.kastcontrole.length }));
        if (takenLaborant.vervallen.length > 0) regels.push(tf('hwTakenVervallen', { n: takenLaborant.vervallen.length }));
      }
      if (takenBeheerder) {
        if (takenBeheerder.onbekendeScans.length > 0) regels.push(tf('hwTakenOnbekend', { n: takenBeheerder.onbekendeScans.length }));
        if (takenBeheerder.onvolledigeProducten.length > 0) regels.push(tf('hwTakenOnvolledig', { n: takenBeheerder.onvolledigeProducten.length }));
        if (takenBeheerder.ontbrekendeBatch.length > 0) regels.push(tf('hwTakenBatch', { n: takenBeheerder.ontbrekendeBatch.length }));
      }
      if (regels.length === 0) {
        html += '<p class="empty">' + t('hwGeenTaken') + '</p>';
      } else {
        html += '<ul>' + regels.map(function (r) { return '<li>' + r + '</li>'; }).join('') + '</ul>';
      }
    }
    html += '<a class="mc-drill-link" id="hwNaarTaken">' + t('hwNaarTaken') + ' →</a></div>';
  }

  // ("Home: sectie
  // met gebruikers/licentie-informatie"): beheerder-only overzicht van
  // hoeveel accounts er zijn en hoeveel daarvan een geldige licentie hebben
  // -- hergebruikt gewoon api/gebruikers.php's bestaande, LIVE-geverifieerde
  // licentieStatus per rij (zie includes/license.php::
  // controleerLicentieGebruiker()), geen nieuwe telling. De licentie-
  // anomalie-waarschuwing (>1 geldig gratis-token) die al op het
  // Gebruikers-scherm zelf stond, verschijnt hier ook, zodat een beheerder
  // dat al op Home ziet i.p.v. pas na het openen van Gebruikers.
  if (magGebruikersLicentie) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwGebruikersLicentieTitle') + '</div>';
    if (!gebruikersLicentie || !Array.isArray(gebruikersLicentie)) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else {
      const totaal = gebruikersLicentie.length;
      const vrij = gebruikersLicentie.filter(function (u) { return u.licentieStatus && u.licentieStatus.vrij; }).length;
      const geldig = gebruikersLicentie.filter(function (u) { return u.licentieStatus && u.licentieStatus.ok && !u.licentieStatus.vrij; }).length;
      const geblokkeerd = gebruikersLicentie.filter(function (u) { return !u.licentieStatus || !u.licentieStatus.ok; }).length;
      const anomalieAantal = (gebruikersLicentie[0] && gebruikersLicentie[0].licentieAnomalieAantal) || 0;
      html += '<ul>';
      html += '<li>' + tf('hwGebruikersTotaal', { n: totaal }) + '</li>';
      html += '<li>' + tf('hwGebruikersLicentieGeldig', { n: vrij + geldig }) + '</li>';
      if (geblokkeerd > 0) {
        html += '<li>' + tf('hwGebruikersLicentieGeblokkeerd', { n: geblokkeerd }) + '</li>';
      }
      html += '</ul>';
      if (anomalieAantal > 1) {
        html += '<p class="empty" style="color:#b00020;">' + tf('guLicentieAnomalie', { aantal: anomalieAantal }) + '</p>';
      }
    }
    html += '<a class="mc-drill-link" id="hwNaarGebruikers">' + t('hwNaarGebruikers') + ' →</a></div>';
  }

  // Verplaatsadvies moet na Taken komen zodat Vervaldatum-Risico
  // onderaan staat als groot blok: dit widget stond hier eerder ALS
  // LAATSTE (na Vervalfocus) -- nu meteen na Taken, zodat de brede
  // Vervalfocus-kaart
  // hieronder als afsluitend, opvallend blok onderaan de widget-grid
  // staat. Inhoud van dit widget zelf ongewijzigd: hergebruikt
  // getVerplaatsAdviesOverzicht() (dezelfde bron als
  // renderVerplaatsAdvies()) -- top 5, meest urgente (kortste daysLeft)
  // eerst, want die staan al zo gesorteerd uit de backend.
  if (magVerplaatsadvies) {
    html += '<div class="home-widget-card"><div class="home-widget-title">' + t('hwVerplaatsadviesTitle') + '</div>';
    if (!verplaatsadvies) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else if (verplaatsadvies.length === 0) {
      html += '<p class="empty">' + t('hwVerplaatsadviesEmpty') + '</p>';
    } else {
      html += '<table class="home-widget-table"><tr><th>' + t('colProduct') + '</th><th>' + t('colLocation') + '</th><th>' + t('vaDaysLeft') + '</th><th>' + t('vaRecommendedLocation') + '</th></tr>';
      verplaatsadvies.slice(0, 5).forEach(function (r) {
        const aanbevolenCell = r.aanbevolenLocatie
          ? '<strong>' + escapeHtml(r.aanbevolenLocatie) + '</strong>'
          : '<em>' + t('vaNoFasterLocation') + '</em>';
        html += '<tr class="' + statusRowClass('SPOEDGEBRUIK') + '"><td>' + escapeHtml(r.product) + '</td><td>' + escapeHtml(r.huidigeLocatie) + '</td><td><strong>' + r.daysLeft + '</strong></td><td>' + aanbevolenCell + '</td></tr>';
      });
      html += '</table>';
      if (verplaatsadvies.length > 5) html += '<p class="form-hint">' + tf('hwEnMeer', { n: verplaatsadvies.length - 5 }) + '</p>';
    }
    html += '<a class="mc-drill-link" id="hwNaarVerplaatsadvies">' + t('hwNaarVerplaatsadvies') + ' →</a></div>';
  }

  // ("Nieuwe
  // producten en vervaldatum-risico moeten als laatste worden weergegeven,
  // de andere zijn namelijk kleinere blokjes waardoor het visueel
  // aantrekkelijker wordt"): deze brede fototegel-kaart stond eerder direct
  // na de (smalle) Nieuws-kaart, hier verplaatst naar vlak vóór Vervalfocus
  // zodat de twee brede kaarten (Nieuwe Producten + Vervalfocus) samen als
  // afsluitend blok onderaan de widget-grid staan, en alle kleinere kaarten
  // (Mijn Bestellingen/Nieuws/PICC/Top Scanners/Taken/Gebruikers-Licentie/
  // Verplaatsadvies) daarboven compact bij elkaar staan. Zelfde
  // 'nieuws'-payload als de tekst-lijst in de Nieuws-kaart hierboven, dus
  // nog steeds geen extra API-aanroep nodig.
  if (magNieuws) {
    html += '<div class="home-widget-card home-widget-card-wide"><div class="home-widget-title">' + t('hwNieuweProductenTitle') + '</div>';
    if (!nieuws) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else if (nieuws.nieuwsteProducten.length === 0) {
      html += '<p class="empty">' + t('hwNieuweProductenEmpty') + '</p>';
    } else {
      html += '<div class="home-product-grid">';
      nieuws.nieuwsteProducten.slice(0, 8).forEach(function (p) {
        html += '<div class="home-product-tile" data-ean="' + escapeAttr(p.ean) + '">'
          + (p.foto
            ? '<img class="home-product-photo" src="' + escapeAttr(p.foto) + '" alt="">'
            : '<div class="home-product-placeholder">' + escapeHtml((p.naam || '?').trim().charAt(0).toUpperCase() || '?') + '</div>')
          + '<div class="home-product-naam">' + escapeHtml(p.naam) + '</div>'
          + '</div>';
      });
      html += '</div>';
    }
    html += '<a class="mc-drill-link" id="hwNaarNieuweProducten">' + t('hwNaarNieuws') + ' →</a></div>';
  }

  // ("materiaal wat over datum dreigt te
  // gaan ... mag wel wat uitgebreider qua data"): in tegenstelling tot de
  // andere widgets hierboven (korte bullet-lijst) krijgt dit widget een
  // eigen brede kaart (home-widget-card-wide, zie style.css) met een
  // mini-tabel van de eerstvolgende loten -- zelfde kolommen/sortering
  // (kortste daysLeft eerst) als de volledige Vervaldatums&Lotbeheer-
  // pagina (renderVervalFocusTable()), maar zonder filterknoppen en
  // beperkt tot de top 8. Bewust het LAATSTE widget (zie
  // toelichting bij Verplaatsadvies/Nieuwe Producten hierboven), zodat dit
  // brede blok als afsluiter onderaan staat.
  if (magVervalfocus) {
    html += '<div class="home-widget-card home-widget-card-wide"><div class="home-widget-title">' + t('hwVervalfocusTitle') + '</div>';
    if (!vervalfocus) {
      html += '<p class="empty">' + t('hwLoadError') + '</p>';
    } else if (vervalfocus.items.length === 0) {
      html += '<p class="empty">' + t('hwVervalfocusEmpty') + '</p>';
    } else {
      html += '<p class="form-hint">' + tf('hwVervalfocusSummary', { n: vervalfocus.tiles.aantalVerlopen, waarde: vervalfocus.tiles.verlopen.toFixed(2) }) + '</p>';
      html += '<table class="home-widget-table"><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th><th>' + t('colUnits') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colStatus') + '</th></tr>';
      vervalfocus.items.slice(0, 8).forEach(function (item) {
        html += '<tr class="' + statusRowClass(item.status) + '"><td>' + escapeHtml(item.product) + '</td><td>' + escapeHtml(item.lot || '') + '</td><td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td><td>' + item.vervaldatum + '</td><td>' + statusBadgeHtml(item.status) + '</td></tr>';
      });
      html += '</table>';
      if (vervalfocus.items.length > 8) html += '<p class="form-hint">' + tf('hwEnMeer', { n: vervalfocus.items.length - 8 }) + '</p>';
    }
    html += '<a class="mc-drill-link" id="hwNaarVervalfocus">' + t('hwNaarVervalfocus') + ' →</a></div>';
  }

  html += '</div>';
  container.innerHTML = html;

  wire('hwNaarMijnBestellingen', showMijnBestellingen);
  wire('hwNaarNieuws', showNieuwsDashboard);
  wire('hwNaarNieuweProducten', showNieuwsDashboard);
  document.querySelectorAll('.home-product-tile').forEach(function (tile) {
    tile.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
  wire('hwNaarLeaderboardPicc', showLeaderboard);
  wire('hwNaarLeaderboardScanners', showLeaderboard);
  wire('hwNaarTaken', showTaken);
  wire('hwNaarGebruikers', showGebruikers);
  wire('hwNaarVervalfocus', showVervalFocus);
  wire('hwNaarVerplaatsadvies', showVerplaatsAdvies);
}

// Home rendert zelf geen Forms- of
// Beheer-knoppen meer (zie showHome() hierboven) -- alleen renderSidebar()
// (altijd zichtbaar in de linker zijbalk) wiert die knoppen nog, dus deze
// functie heeft nu niets meer te doen. Bewust behouden als lege, no-op
// functie i.p.v. overal de aanroep in showHome() weg te halen: als hier
// ooit weer home-specifieke knoppen bijkomen, is dit meteen de juiste plek.
function attachHomeHandlers() {
}

// -----------------------------------------------------------------
// "Mijn Bestellingen" stond hier lange tijd als eigen, uitgeklede
// pagina (met een eigen terugknop) die alleen de eigen eenmalige
// bestellingen van een
// specialist/laborant-account toonde. Dat was op twee manieren mis: (1)
// hij had, net als showOpenOrders()/showBestellers() hieronder, al geen
// eigen plek meer in ROUTE_DISPATCH (die wijst 'mijn-bestellingen' al
// naar showBestellingen()) -- dit was dode code die alleen nog bereikbaar
// was via een paar losse knoppen; (2) "eigen" betekende specialist = mijn
// naam (voor wie de aanvraag is), niet besteller = mijn naam (wie hem
// heeft geplaatst) -- dat laatste is wat bedoeld wordt met "altijd
// bestellingen van de ingelogde gebruiker zien". showBestellingen() kent
// al een 'mijn'-filterchip (zie BESTELLINGEN_FILTERS) die nu op besteller
// matcht (zie bestellingenNormaliseer()) en voor ELKE ingelogde gebruiker
// werkt, niet alleen specialist/laborant -- dus is er geen aparte pagina
// meer nodig, alleen deze pagina met dat filter alvast aan.
function showMijnBestellingen() {
  showBestellingen('mijn');
}

// Dashboard-consolidatie: de vijf
// afzonderlijke voorraadschermen (Overzicht, Bijvullen, Berging,
// Voorraadwaarde, Min/Max Advies) delen nu één menu-ingang ("Voorraad")
// met een tabbalk erboven, i.p.v. vijf losse menuknoppen -- minder
// dashboards, maar elk scherm behoudt zijn eigen bestaande data/filters
// (die blijven dus gewoon werken zoals ze deden). Rechten blijven
// ongewijzigd: heeftScherm() bepaalt per tab of hij getoond wordt, met
// dezelfde scherm-ids als voorheen (de groepenmatrix hoeft niet aangepast).
// Bugfix: "#boodschappenlijst vanuit Rapportage geeft verkeerde
// informatie, het is nu leeg terwijl de informatie/inhoud van
// #bijvullen moet worden getoond". Dit tabblad heette al "Bijvullen"
// (refillList) en wees toen naar de statische
// bijvul-lijst hieronder (showBoodschappenlijst()/getBoodschappenData()).
// Later kreeg het een HELEMAAL NIEUW, apart "Bijvullen"-dashboard
// (showBijvullen(), eigen sidebar-knop, route #bijvullen) met dezelfde
// naam maar een echte scan-to-transfer-actie erbij en een robuustere
// tekortenfilter (moetBijgevuld i.p.v. alleen magBijvullen) -- zonder dat
// dit tabblad werd bijgewerkt. Twee dingen heetten dus allebei "Bijvullen"
// terwijl alleen het sidebar-dashboard nog daadwerkelijk werkte. Dit
// tabblad wijst nu naar diezelfde showBijvullen(), zodat er nog maar één
// "Bijvullen" is en die overal dezelfde (werkende) inhoud toont. De oude
// showBoodschappenlijst()/renderBoodschappenlijst()/getBoodschappenData()
// blijven ongebruikt in de codebase staan (geen andere aanroepers meer)
// i.p.v. verwijderd, voor het geval hier ooit nog los naar teruggegrepen
// wordt.
const VOORRAAD_TABS = [
  ['overzicht', 'overview', showOverzicht],
  ['boodschappenlijst', 'refillList', showBijvullen],
  ['berging', 'storage', showBerging],
  ['voorraadwaarde', 'stockValue', showVoorraadwaarde],
  ['minmax-advies', 'minmaxAdvies', showMinMaxAdvies],
];

// Het 'boodschappenlijst'-tabblad wijst nu naar
// showBijvullen(), waarvan het scanformulier backend-side ook de
// 'verplaatsing'-rechten vereist (apiRequireScherm('verplaatsing') in
// api/verplaatsing.php) -- een groep met alleen 'boodschappenlijst' aan
// (en 'verplaatsing' uit) in de Groepen-matrix zou het tabblad anders wel
// zien maar bij het opslaan van een scan een rechten-foutmelding krijgen.
// Vandaar deze uitzondering i.p.v. gewoon heeftScherm(tab[0]).
function voorraadTabZichtbaar(schermId) {
  if (schermId === 'boodschappenlijst') return heeftScherm('boodschappenlijst') && heeftScherm('verplaatsing');
  return heeftScherm(schermId);
}

function voorraadTabsHtml(actief) {
  const zichtbaar = VOORRAAD_TABS.filter(function (tab) { return voorraadTabZichtbaar(tab[0]); });
  if (zichtbaar.length <= 1) return '';
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  zichtbaar.forEach(function (tab) {
    html += '<button class="hub-tab-btn' + (tab[0] === actief ? ' active' : '') + '" data-voorraad-tab="' + tab[0] + '">' + t(tab[1]) + '</button>';
  });
  html += '</div>';
  return html;
}

function wireVoorraadTabs() {
  document.querySelectorAll('[data-voorraad-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-voorraad-tab');
      const target = VOORRAAD_TABS.find(function (tab) { return tab[0] === id; });
      if (target) target[2]();
    });
  });
}

// Ingang vanuit het hoofdmenu: opent de eerste tab waar de ingelogde
// gebruiker rechten toe heeft (meestal Overzicht).
function showVoorraadHub() {
  const eerste = VOORRAAD_TABS.find(function (tab) { return voorraadTabZichtbaar(tab[0]); });
  if (eerste) eerste[2]();
}

function showOverzicht() {
  setRoute('overzicht');
  currentPage = 'overzicht';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderOverzicht).getOverzichtData();
}

/**
 * Eén tabellenset voor het Overview-scherm, met een optioneel client-side
 * filter op "geen voorraad" -- vervangt het losse Stock on Hand-scherm
 * (dat identieke kolommen toonde, alleen met huidig<=0 weggelaten). Geen
 * extra server-aanroep nodig: getOverzichtData() bevat alles al, dit
 * filtert alleen wat er getoond wordt.
 */
function renderOverzichtTabellen(data, hideEmpty) {
  let html = '';
  let anyRows = false;
  for (const loc in data) {
    const rows = hideEmpty ? data[loc].filter(item => item.huidig > 0) : data[loc];
    if (rows.length === 0) continue;
    anyRows = true;
    html += '<div class="section-title">' + loc + '</div><table><tr><th>' + t('colProduct') + '</th><th>' + t('colCabinet') + '</th><th>' + t('colCurrent') + '</th><th>' + t('colMin') + '</th><th>' + t('colMax') + '</th><th>' + t('colValue') + '</th><th>' + t('colStockStatus') + '</th><th>' + t('colOrderStatus') + '</th></tr>';
    rows.forEach(item => {
      const kastlocHtml = item.kastlocatie ? '<span class="kastlocatie-badge">' + escapeHtml(item.kastlocatie) + '</span>' : '-';
      const waardeHtml = '€' + item.waarde.toFixed(2);
      // Orderstatus klikbaar naar de
      // order-detailpagina, als er daadwerkelijk een openstaande order is.
      const orderStatusHtml = item.orderStatus
        ? '<a class="order-status-link" data-ean="' + item.ean + '">' + statusBadgeHtml(item.orderStatus) + '</a>'
        : statusBadgeHtml('-');
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td><td>' + kastlocHtml + '</td><td>' + item.huidig + '</td><td>' + item.min + '</td><td>' + item.max + '</td><td>' + waardeHtml + '</td><td>' + statusBadgeHtml(item.voorraadStatus) + '</td><td>' + orderStatusHtml + '</td></tr>';
    });
    html += '</table>';
  }
  if (!anyRows) html += '<p class="empty">' + (hideEmpty ? t('ovNothingInStock') : t('ovNothingAll')) + '</p>';
  return html;
}

function renderOverzicht(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  function draw(hideEmpty) {
    let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('overview') + '</h2>';
    html += voorraadTabsHtml('overzicht');
    html += '<div class="form-group"><label><input type="checkbox" id="overzichtHideEmpty"' + (hideEmpty ? ' checked' : '') + '> ' + t('ovHideEmpty') + '</label></div>';
    html += renderOverzichtTabellen(data, hideEmpty);
    document.getElementById('content').innerHTML = html;
    wire('backBtn', goBack);
    document.getElementById('overzichtHideEmpty').addEventListener('change', function () { draw(this.checked); });
    document.querySelectorAll('.product-link').forEach(el => {
      el.addEventListener('click', function() { showProductDetail(this.getAttribute('data-ean')); });
    });
    document.querySelectorAll('.order-status-link').forEach(el => {
      el.addEventListener('click', function () { showOrderDetailVoorEan(this.getAttribute('data-ean')); });
    });
    wireVoorraadTabs();
  }

  draw(false);
}

// -----------------------------------------------------------------
// Recente transacties: laatste 7 dagen, met bewerken/verwijderen om een
// scanfout (bijv. verkeerde locatie) recht te zetten. Alleen dit venster
// -- niet bedoeld om de volledige historie te herschrijven, zie
// includes/transacties.php.
// -----------------------------------------------------------------
function showRecenteTransacties() {
  setRoute('recente-transacties');
  currentPage = 'recenteTransacties';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (transacties) {
    StocuroRun.withSuccessHandler(function (specialisten) {
      StocuroRun.withSuccessHandler(function (procedures) {
        fetchLocaties(function (locRows) {
          renderRecenteTransacties(transacties, specialisten, procedures, locRows);
        });
      }).getProcedureTypen();
    }).getSpecialisten();
  }).getRecenteTransacties();
}

function transactieTypeLabel(type) {
  const map = {
    'Ontvangst': t('rtTypeReceived'), 'Verbruik': t('rtTypeUsed'),
    'Verplaatsing': t('rtTypeTransfer'),
    'Verplaatsing in': t('rtTypeTransferIn'), 'Verplaatsing uit': t('rtTypeTransferOut'),
    'Retournering': t('rtTypeReturned'),
    'Telling: overschot': t('rtTypeSurplus'), 'Telling: tekort': t('rtTypeShortage'),
    'Vervallen': t('rtTypeExpired'),
  };
  return map[type] || type;
}

// Alleen-lezen tabelletje met de losse regels van één scanactie (batch),
// getoond via de "Bekijk regels"-toggle -- niet zelf bewerkbaar, zie
// bewerkTransactieBatch()/verwijderTransactieBatch() in
// includes/transacties.php voor waarom correcties op batch-niveau lopen.
function renderRecenteTransactieRegelsTable(regels) {
  let html = '<table class="batch-regels"><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>'
    + t('colExpiryDate') + '</th><th>' + t('colQty') + '</th><th>' + t('colType') + '</th><th>' + t('colLocation') + '</th></tr>';
  regels.forEach(function (r) {
    html += '<tr><td><a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.productnaam || r.ean) + '</a></td><td>' + escapeHtml(r.lot || '-') + '</td>'
      + '<td>' + escapeHtml(r.vervaldatum ? String(r.vervaldatum).substring(0, 10) : '-') + '</td>'
      + '<td>' + r.hoeveelheid + '</td><td>' + escapeHtml(transactieTypeLabel(r.transactietype)) + '</td>'
      + '<td>' + escapeHtml(r.locatie) + '</td></tr>';
  });
  html += '</table>';
  return html;
}

// Eén rij per scanactie (batch_id, of 'row-<id>' voor oudere data zonder
// batch_id) i.p.v. voorheen één rij per losse transactielog-regel --
// zie getRecenteTransactiesGegroepeerd() in includes/transacties.php.
// index is de positie in de transacties-array (gebruikt voor DOM-ids i.p.v.
// de batch_id zelf, want een batch_id is een hex-string die met een cijfer
// kan beginnen en dus geen geldige CSS-id is).
function renderRecenteTransactieRow(item, index) {
  const editedHtml = item.bewerkt_op
    ? '<span class="kastlocatie-badge" title="Edited by ' + escapeAttr(item.bewerkt_door || '') + '">' + t('rtEdited') + '</span>' : '';
  const isTransfer = item.type === 'Verplaatsing';
  const locatieHtml = isTransfer
    ? escapeHtml(item.van_locatie || '-') + ' → ' + escapeHtml(item.naar_locatie || '-')
    : escapeHtml(item.locatie || '-');

  return '<tr id="batRow' + index + '" data-batch-id="' + escapeAttr(item.batch_id) + '" data-type="' + escapeAttr(item.type) + '">'
    + '<td>' + escapeHtml(String(item.tijdstempel).substring(0, 16)) + '</td>'
    + '<td class="trans-type">' + escapeHtml(transactieTypeLabel(item.type)) + '</td>'
    + '<td class="trans-locatie">' + locatieHtml + '</td>'
    + '<td>' + item.aantal_regels + '</td>'
    + '<td class="trans-specialist">' + escapeHtml(item.specialist || '-') + '</td>'
    + '<td class="trans-procedure">' + escapeHtml(item.procedure_type || '-') + '</td>'
    + '<td class="trans-opmerking">' + escapeHtml(item.opmerking || '-') + '</td>'
    + '<td>' + escapeHtml(item.aangemaakt_door || '-') + '</td>'
    + '<td>' + editedHtml + '</td>'
    + '<td class="trans-actions">'
    + '<button class="action-btn trans-view-btn" data-idx="' + index + '">' + tf('rtViewLines', { n: item.aantal_regels }) + '</button> '
    + '<button class="action-btn trans-edit-btn" data-idx="' + index + '">' + t('editLabel') + '</button> '
    + '<button class="action-btn trans-delete-btn" data-idx="' + index + '">' + t('deleteLabel') + '</button>'
    + '</td></tr>'
    + '<tr id="batLines' + index + '" class="batch-lines-row" style="display:none;"><td colspan="10">'
    + renderRecenteTransactieRegelsTable(item.regels) + '</td></tr>';
}

function renderRecenteTransacties(transacties, specialisten, procedures, locRows) {
  const locaties = actieveLocatieNamen(locRows);

  let html = '<h2>' + t('recentTransactions') + '</h2>';
  html += '<p class="form-hint">' + t('rtHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  if (transacties.length === 0) {
    html += '<p class="empty">' + t('rtNoneRecent') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colDate') + '</th><th>' + t('colType') + '</th><th>' + t('colLocation') + '</th><th>' + t('colLines') + '</th><th>' + t('colSpecialist') + '</th><th>' + t('colProcedure') + '</th><th>' + t('colNote') + '</th><th>' + t('colScannedBy') + '</th><th></th><th></th></tr>';
    transacties.forEach(function (item, index) {
      html += renderRecenteTransactieRow(item, index);
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  attachRecenteTransactieHandlers(transacties, locaties, specialisten, procedures);
}

function attachRecenteTransactieHandlers(transacties, locaties, specialisten, procedures) {
  document.querySelectorAll('.trans-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const idx = this.getAttribute('data-idx');
      const linesRow = document.getElementById('batLines' + idx);
      const nowExpanded = linesRow.style.display === 'none';
      linesRow.style.display = nowExpanded ? 'table-row' : 'none';
      this.textContent = nowExpanded ? t('rtHideLines') : tf('rtViewLines', { n: transacties[idx].aantal_regels });
    });
  });
  document.querySelectorAll('.trans-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openBatchEditRow(this.getAttribute('data-idx'), locaties, specialisten, procedures);
    });
  });
  document.querySelectorAll('.trans-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const idx = this.getAttribute('data-idx');
      const batchId = document.getElementById('batRow' + idx).getAttribute('data-batch-id');
      if (!confirm(tf('rtConfirmDeleteBatch', { n: transacties[idx].aantal_regels }))) return;
      apiPost('api/transactielog.php', { actie: 'verwijderen', batch_id: batchId }, function () {
        showRecenteTransacties();
      }, function (err) {
        alert(err.error || t('somethingWrong'));
      });
    });
  });
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
}

// Bewerkt een hele scanactie (batch) in één keer i.p.v. één losse regel --
// zie bewerkTransactieBatch() in includes/transacties.php. Bij een
// Verplaatsing-batch (uit- én in-boeking per gescand artikel) worden
// van_locatie/naar_locatie apart aangeboden i.p.v. één generieke locatie,
// want die twee kanten hebben allebei hun eigen betekenis.
function openBatchEditRow(index, locaties, specialisten, procedures) {
  const row = document.getElementById('batRow' + index);
  if (!row) return;
  const batchId = row.getAttribute('data-batch-id');
  const isTransfer = row.getAttribute('data-type') === 'Verplaatsing';
  const locatieCell = row.querySelector('.trans-locatie');
  const specialistHuidig = row.querySelector('.trans-specialist').textContent.trim();
  const procedureHuidig = row.querySelector('.trans-procedure').textContent.trim();
  const opmerkingHuidig = row.querySelector('.trans-opmerking').textContent.trim();

  if (isTransfer) {
    const parts = locatieCell.textContent.split('→').map(function (s) { return s.trim(); });
    locatieCell.innerHTML =
      '<label style="display:block;font-weight:normal;">' + t('tfFromLocation') + '</label>'
      + '<select id="editVanLocatie' + index + '">' + locatieOptionsHtml(locaties, parts[0]) + '</select>'
      + '<label style="display:block;font-weight:normal;margin-top:4px;">' + t('tfToLocation') + '</label>'
      + '<select id="editNaarLocatie' + index + '">' + locatieOptionsHtml(locaties, parts[1]) + '</select>';
  } else {
    const locatieHuidig = locatieCell.textContent.trim();
    locatieCell.innerHTML = '<select id="editLocatie' + index + '">' + locatieOptionsHtml(locaties, locatieHuidig) + '</select>';
  }

  row.querySelector('.trans-specialist').innerHTML = '<select id="editSpecialist' + index + '"><option value="">-</option>' + specialistOptionsHtml(specialisten, specialistHuidig !== '-' ? specialistHuidig : '') + '</select>';
  row.querySelector('.trans-procedure').innerHTML = '<select id="editProcedure' + index + '"><option value="">-</option>' + actieveNaamOptionsHtml(procedures) + '</select>';
  row.querySelector('.trans-opmerking').innerHTML = '<input type="text" id="editOpmerking' + index + '" value="' + escapeAttr(opmerkingHuidig === '-' ? '' : opmerkingHuidig) + '" style="width:100%;">';

  const specialistSelect = document.getElementById('editSpecialist' + index);
  if (specialistSelect && specialistHuidig !== '-') specialistSelect.value = specialistHuidig;
  const procedureSelect = document.getElementById('editProcedure' + index);
  if (procedureSelect && procedureHuidig !== '-') procedureSelect.value = procedureHuidig;

  row.querySelector('.trans-actions').innerHTML =
    '<button class="action-btn trans-save-btn" data-idx="' + index + '">' + t('rtSave') + '</button> '
    + '<button class="action-btn trans-cancel-btn" data-idx="' + index + '">' + t('rtCancel') + '</button>';

  row.querySelector('.trans-save-btn').addEventListener('click', function () {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    const payload = {
      actie: 'bewerken',
      batch_id: batchId,
      specialist: document.getElementById('editSpecialist' + index).value,
      procedure_type: document.getElementById('editProcedure' + index).value,
      opmerking: document.getElementById('editOpmerking' + index).value,
    };
    if (isTransfer) {
      payload.van_locatie = document.getElementById('editVanLocatie' + index).value;
      payload.naar_locatie = document.getElementById('editNaarLocatie' + index).value;
    } else {
      payload.locatie = document.getElementById('editLocatie' + index).value;
    }
    apiPost('api/transactielog.php', payload, function () {
      showRecenteTransacties();
    }, function (err) {
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
  row.querySelector('.trans-cancel-btn').addEventListener('click', function () {
    showRecenteTransacties();
  });
}

// -----------------------------------------------------------------
// Taken: afgeleide takenlijst, zie includes/taken.php. Geen aan-/
// afvinkbare taken -- "afronden" gebeurt door de onderliggende actie te
// doen (tellen resp. scannen met reden "expired", een artikel aanmaken,
// een productveld aanvullen).
//
// Dit was één scherm met vier soorten taken door elkaar. Nu twee losse
// dashboards: Laborant-taken (fysiek
// vloerwerk: kastcontrole + vervallen voorraad) en Beheerder-taken
// (databeheer: onbekende scans + onvolledige productgegevens).
// -----------------------------------------------------------------
// =================================================================
// TAKEN: Beheerder-taken en Laborant-taken waren twee losse dashboards
// achter dezelfde
// "Taken"-gedachte, alleen voor een andere doelgroep -- nu één pagina met
// een rolschakelaar i.p.v. twee aparte menu-ingangen. Backend ongewijzigd
// (getTakenBeheerder()/getTakenLaborant()), alleen de bestaande
// render-inhoud van beide is hier samengevoegd onder één back-knop.
// =================================================================
let takenWeergave = null;

function showTaken() {
  setRoute('taken');
  currentPage = 'taken';
  const heeftBeheerder = heeftScherm('taken-beheerder');
  const heeftLaborant = heeftScherm('taken-laborant');
  takenWeergave = heeftLaborant ? 'laborant' : 'beheerder';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';

  // Naast de kastcontrole-taken die
  // hieronder al binnenkomen (alleen locaties die AAN DE BEURT zijn),
  // ook meteen de volledige locatielijst ophalen -- nodig voor de
  // vrijwillige "opnieuw voorraadtelling uitvoeren"-keuze in
  // takenLaborantHtml() hieronder, waar ELKE actieve locatie (niet alleen
  // de verlopen) selecteerbaar moet zijn.
  fetchLocaties(function (locRows) {
    StocuroRun.withSuccessHandler(function (laborantData) {
      if (heeftBeheerder) {
        StocuroRun.withSuccessHandler(function (beheerderData) {
          renderTaken(laborantData, beheerderData, heeftLaborant, heeftBeheerder, locRows);
        }).getTakenBeheerder();
      } else {
        renderTaken(laborantData, null, heeftLaborant, heeftBeheerder, locRows);
      }
    }).getTakenLaborant();
  });
}

function takenLaborantHtml(data, locRows) {
  let html = '<div class="section-title">' + t('tkCountsDue') + '</div>';
  html += '<p class="form-hint">' + tf('tkCountsHint', { n: (window.TELLING_INTERVAL_MAANDEN || 3) }) + '</p>';
  if (data.kastcontrole.length === 0) {
    html += '<p class="empty">' + t('tkNothingDue') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colLocation') + '</th><th>' + t('colLastCounted') + '</th><th></th></tr>';
    data.kastcontrole.forEach(function (item) {
      const last = item.laatstGeteld ? String(item.laatstGeteld).substring(0, 10) : t('neverLabel');
      html += '<tr><td>' + escapeHtml(item.locatie) + '</td><td>' + escapeHtml(last) + '</td>'
        + '<td><button class="action-btn taak-tellen-btn" data-locatie="' + escapeAttr(item.locatie) + '">' + t('tkStartCount') + '</button></td></tr>';
    });
    html += '</table>';
  }

  // "ik wil ook de optie 'opnieuw
  // voorraadtelling uitvoeren', en dat je aldaar de kast kan selecteren
  // die je wenst te controleren -- dit kunnen alle kasten zijn, en alle
  // locaties. Het is geen taak als het niet nodig is, maar de laborant
  // moet dit op een rustig moment wel kunnen selecteren." Bewust GEEN
  // taak: telt niet mee in data.totaal, geen badge, staat hier gewoon
  // altijd -- de lijst hierboven toont alleen locaties die AAN DE BEURT
  // zijn (kastcontroleTaakVoorLocatie() in includes/taken.php), deze
  // sectie laat ELKE actieve locatie kiezen (locRows, zie fetchLocaties()
  // in showTaken()), ongeacht of die al verlopen is.
  //
  // "dat je aldaar de kast kan
  // selecteren ... het is niet heel waarschijnlijk dat ik de hele berging
  // in 1 keer ga scannen -- dus per kast per locatie." Naast de
  // locatiekeuze hieronder komt er nu ook een TWEEDE, dynamisch geladen
  // keuze (#herTellenKastPaneel, gevuld door laadHerTellenKastPaneel() via
  // getKastlocatiesVoorLocatie()) met de kasten binnen die ene locatie,
  // plus een "Hele locatie"-optie voor wie toch alles in één keer wil
  // doen. Hergebruikt showTelling(locatie, kastlocatie) -- geen apart
  // scherm nodig, alleen de bestaande Tellen-flow met een optionele
  // kastlocatie-scope (zie includes/telling.php::verwerkTelling()).
  const alleLocaties = actieveLocatieNamen(locRows || []);
  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkHerTellenTitel') + '</div>';
  html += '<p class="form-hint">' + t('tkHerTellenHint') + '</p>';
  if (alleLocaties.length === 0) {
    html += '<p class="empty">' + t('tkHerTellenGeen') + '</p>';
  } else {
    html += '<div class="form-group"><label>' + t('colLocation') + '</label>' + keuzeknoppenHtml('herTellenLocatie', alleLocaties, alleLocaties[0]) + '</div>';
    html += '<div class="form-group"><label>' + t('tkHerTellenKastLabel') + '</label><div id="herTellenKastPaneel"><p class="form-hint">' + t('loading') + '</p></div></div>';
    html += '<div class="edit-actions"><button class="action-btn" id="herTellenBtn">' + t('tkHerTellenStart') + '</button></div>';
  }

  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkPastExpiry') + '</div>';
  html += '<p class="form-hint">' + t('tkPastExpiryHint') + '</p>';
  if (data.vervallen.length === 0) {
    html += '<p class="empty">' + t('tkNothingExpired') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th><th>' + t('colQty') + '</th><th>' + t('colExpiryDate') + '</th></tr>';
    data.vervallen.forEach(function (item) {
      html += '<tr class="critical"><td><a class="product-link" data-ean="' + escapeAttr(item.ean) + '">' + escapeHtml(item.productnaam || item.ean) + '</a></td><td>' + escapeHtml(item.lot || '-') + '</td>'
        + '<td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td><td>' + escapeHtml(String(item.vervaldatum)) + '</td></tr>';
    });
    html += '</table>';
    html += '<div class="edit-actions"><button class="edit-save" id="takenGaScannenBtn">' + t('tkGoWriteOff') + '</button></div>';
  }

  return html;
}

function takenBeheerderHtml(data) {
  let html = '<div class="section-title">' + t('tkOnbekend') + '</div>';
  html += '<p class="form-hint">' + t('tkOnbekendHint') + '</p>';
  if (!data.onbekendeScans || data.onbekendeScans.length === 0) {
    html += '<p class="empty">' + t('tkOnbekendGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colEan') + '</th><th>' + t('onbOmschrijvingen') + '</th><th>' + t('colLocation') + '</th><th>' + t('tkKeerGezien') + '</th></tr>';
    data.onbekendeScans.forEach(function (item) {
      html += '<tr><td><code>' + escapeHtml(item.ean) + '</code></td><td>' + escapeHtml(item.omschrijvingen.join('; ') || '-') + '</td>'
        + '<td>' + escapeHtml(item.locaties.join(', ') || '-') + '</td><td>' + item.aantalKeerGezien + '</td></tr>';
    });
    html += '</table>';
    html += '<div class="edit-actions"><button class="edit-save" id="takenGaOnbekendBtn">' + t('tkGoOnbekend') + '</button></div>';
  }

  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkOnvolledig') + '</div>';
  html += '<p class="form-hint">' + t('tkOnvolledigHint') + '</p>';
  if (!data.onvolledigeProducten || data.onvolledigeProducten.length === 0) {
    html += '<p class="empty">' + t('tkOnvolledigGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('tkOntbrekendeVelden') + '</th><th></th></tr>';
    data.onvolledigeProducten.forEach(function (item) {
      const badges = item.ontbrekend.map(function (veld) {
        return '<span class="kastlocatie-badge" style="margin-right:4px;">' + escapeHtml(t('tkVeld_' + veld)) + '</span>';
      }).join('');
      html += '<tr><td>' + escapeHtml(item.naam) + '</td><td>' + badges + '</td>'
        + '<td><button class="action-btn taak-product-btn" data-ean="' + escapeAttr(item.ean) + '">' + t('pdEdit') + '</button></td></tr>';
    });
    html += '</table>';
  }

  // Dit stond eerder bij Laborant-taken,
  // maar is een databeheerkwestie (ooit ontvangen zonder batch-/vervaldatum-
  // code te scannen), geen fysiek vloerwerk -- daarom hier. "Afronden" is
  // geen knop naar een ander scherm (lot/vervaldatum zijn bewust niet los
  // te bewerken, zie bewerkTransactieRegel() in includes/transacties.php);
  // de aanwijzing staat in de hint-tekst: transactie verwijderen + opnieuw
  // scannen (via Recente Transacties).
  const ontbrekendeBatch = data.ontbrekendeBatch || [];
  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkOntbrekendeBatch') + '</div>';
  html += '<p class="form-hint">' + t('tkOntbrekendeBatchHint') + '</p>';
  if (ontbrekendeBatch.length === 0) {
    html += '<p class="empty">' + t('tkOntbrekendeBatchGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLocation') + '</th><th>' + t('colQty') + '</th></tr>';
    ontbrekendeBatch.forEach(function (item) {
      html += '<tr><td><a class="product-link" data-ean="' + escapeAttr(item.ean) + '">' + escapeHtml(item.productnaam || item.ean) + '</a></td><td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td></tr>';
    });
    html += '</table>';
  }
  return html;
}

function renderTaken(laborantData, beheerderData, heeftLaborant, heeftBeheerder, locRows) {
  const fout = (laborantData && laborantData.error) || (beheerderData && beheerderData.error);
  if (fout) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + fout + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('tkTitle') + '</h2>';
  if (heeftLaborant && heeftBeheerder) {
    html += '<div class="vt-filters">'
      + '<button class="filter-btn tk-weergave-btn' + (takenWeergave === 'laborant' ? ' active' : '') + '" data-weergave="laborant">' + t('tasksLab') + '</button>'
      + '<button class="filter-btn tk-weergave-btn' + (takenWeergave === 'beheerder' ? ' active' : '') + '" data-weergave="beheerder">' + t('tasksAdmin') + '</button>'
      + '</div>';
  }
  if (heeftLaborant) html += '<div id="tkLaborant"' + (takenWeergave === 'laborant' ? '' : ' style="display:none;"') + '>' + takenLaborantHtml(laborantData, locRows) + '</div>';
  if (heeftBeheerder) html += '<div id="tkBeheerder"' + (takenWeergave === 'beheerder' ? '' : ' style="display:none;"') + '>' + takenBeheerderHtml(beheerderData) + '</div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.tk-weergave-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      takenWeergave = this.getAttribute('data-weergave');
      const lab = document.getElementById('tkLaborant');
      const beh = document.getElementById('tkBeheerder');
      if (lab) lab.style.display = takenWeergave === 'laborant' ? '' : 'none';
      if (beh) beh.style.display = takenWeergave === 'beheerder' ? '' : 'none';
      document.querySelectorAll('.tk-weergave-btn').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-weergave') === takenWeergave); });
    });
  });

  const gaScannenBtn = document.getElementById('takenGaScannenBtn');
  if (gaScannenBtn) gaScannenBtn.addEventListener('click', showVervalAfboeken);
  const gaOnbekendBtn = document.getElementById('takenGaOnbekendBtn');
  if (gaOnbekendBtn) gaOnbekendBtn.addEventListener('click', showOnbekendeProducten);
  document.querySelectorAll('.taak-tellen-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { showTelling(this.getAttribute('data-locatie')); });
  });
  document.querySelectorAll('.taak-product-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { showProductEdit(this.getAttribute('data-ean')); });
  });
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });

  // Vrijwillige "opnieuw voorraadtelling uitvoeren"-actie, zie
  // takenLaborantHtml() hierboven -- alleen aanwezig als er
  // actieve locaties zijn om uit te kiezen. Kiezen van een locatie ververst
  // meteen de bijbehorende kastenlijst (laadHerTellenKastPaneel()); Start
  // stuurt beide gekozen waarden door aan showTelling().
  bindKeuzeknoppen('herTellenLocatie', function (gekozenLocatie) {
    laadHerTellenKastPaneel(gekozenLocatie);
  });
  const herTellenLocatieInput = document.getElementById('herTellenLocatie');
  if (herTellenLocatieInput && herTellenLocatieInput.value) {
    laadHerTellenKastPaneel(herTellenLocatieInput.value);
  }
  const herTellenBtn = document.getElementById('herTellenBtn');
  if (herTellenBtn) {
    herTellenBtn.addEventListener('click', function () {
      const gekozenLocatie = document.getElementById('herTellenLocatie').value;
      const kastInput = document.getElementById('herTellenKast');
      const gekozenKast = kastInput ? kastInput.value : '';
      if (gekozenLocatie) showTelling(gekozenLocatie, gekozenKast || null);
    });
  }
}

/**
 * Ververst #herTellenKastPaneel met de
 * kasten binnen ÉÉN gekozen locatie (getKastlocatiesVoorLocatie(), zie
 * kastlocatiesVoorLocatie() in includes/locaties.php), plus een altijd
 * aanwezige "Hele locatie"-optie (lege waarde -- betekent voor
 * showTelling()/verwerkTelling() een niet-kast-scoped, volledige telling).
 * Los gehouden van keuzeknoppenHtml()/bindKeuzeknoppen() omdat de lege
 * "hele locatie"-optie een vertaalde label nodig heeft i.p.v. de kale
 * waarde zelf als label (wat keuzeknoppenHtml() altijd doet).
 */
function laadHerTellenKastPaneel(locatie) {
  const paneel = document.getElementById('herTellenKastPaneel');
  if (!paneel) return;
  paneel.innerHTML = '<p class="form-hint">' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (kasten) {
    const p = document.getElementById('herTellenKastPaneel');
    if (!p) return; // van pagina gewisseld terwijl dit nog laadde
    p.innerHTML = herTellenKastKnoppenHtml(kasten || []);
    bindHerTellenKastKnoppen();
  }).withFailureHandler(function () {
    const p = document.getElementById('herTellenKastPaneel');
    if (p) p.innerHTML = '<p class="empty">' + t('hwLoadError') + '</p>';
  }).getKastlocatiesVoorLocatie(locatie, 'kastlocaties');
}

function herTellenKastKnoppenHtml(kasten) {
  let html = '<input type="hidden" id="herTellenKast" value="">';
  html += '<div class="keuzeknoppen" id="herTellenKastKnoppen">';
  html += '<button type="button" class="keuzeknop active" data-waarde="">' + t('tkHerTellenHeleLocatie') + '</button>';
  kasten.forEach(function (k) {
    html += '<button type="button" class="keuzeknop" data-waarde="' + escapeAttr(k) + '">' + escapeHtml(k) + '</button>';
  });
  html += '</div>';
  return html;
}

function bindHerTellenKastKnoppen() {
  const container = document.getElementById('herTellenKastKnoppen');
  if (!container) return;
  container.querySelectorAll('.keuzeknop').forEach(function (btn) {
    btn.addEventListener('click', function () {
      container.querySelectorAll('.keuzeknop').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      const kastInput = document.getElementById('herTellenKast');
      if (kastInput) kastInput.value = this.getAttribute('data-waarde');
    });
  });
}

function showTakenLaborant() {
  setRoute('taken-laborant');
  currentPage = 'taken-laborant';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderTakenLaborant).getTakenLaborant();
}

function renderTakenLaborant(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('tasksLab') + '</h2>';

  html += '<div class="section-title">' + t('tkCountsDue') + '</div>';
  html += '<p class="form-hint">' + tf('tkCountsHint', { n: (window.TELLING_INTERVAL_MAANDEN || 3) }) + '</p>';
  if (data.kastcontrole.length === 0) {
    html += '<p class="empty">' + t('tkNothingDue') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colLocation') + '</th><th>' + t('colLastCounted') + '</th><th></th></tr>';
    data.kastcontrole.forEach(function (item) {
      const last = item.laatstGeteld ? String(item.laatstGeteld).substring(0, 10) : t('neverLabel');
      html += '<tr><td>' + escapeHtml(item.locatie) + '</td><td>' + escapeHtml(last) + '</td>'
        + '<td><button class="action-btn taak-tellen-btn" data-locatie="' + escapeAttr(item.locatie) + '">' + t('tkStartCount') + '</button></td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkPastExpiry') + '</div>';
  html += '<p class="form-hint">' + t('tkPastExpiryHint') + '</p>';
  if (data.vervallen.length === 0) {
    html += '<p class="empty">' + t('tkNothingExpired') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th><th>' + t('colQty') + '</th><th>' + t('colExpiryDate') + '</th></tr>';
    data.vervallen.forEach(function (item) {
      html += '<tr class="critical"><td><a class="product-link" data-ean="' + escapeAttr(item.ean) + '">' + escapeHtml(item.productnaam || item.ean) + '</a></td><td>' + escapeHtml(item.lot || '-') + '</td>'
        + '<td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td><td>' + escapeHtml(String(item.vervaldatum)) + '</td></tr>';
    });
    html += '</table>';
    html += '<div class="edit-actions"><button class="edit-save" id="takenGaScannenBtn">' + t('tkGoWriteOff') + '</button></div>';
  }

  // : voorraad die is ontvangen zonder dat
  // ooit een batch-/vervaldatumcode is meegescand (bv. alleen de GTIN van
  // een doos gescand). "Afronden" is hier geen knop naar een ander scherm
  // (lot/vervaldatum zijn bewust niet los te bewerken, zie
  // bewerkTransactieRegel() in includes/transacties.php) -- de aanwijzing
  // staat in de hint-tekst: transactie verwijderen + opnieuw scannen.
  const ontbrekendeBatch = data.ontbrekendeBatch || [];
  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkOntbrekendeBatch') + '</div>';
  html += '<p class="form-hint">' + t('tkOntbrekendeBatchHint') + '</p>';
  if (ontbrekendeBatch.length === 0) {
    html += '<p class="empty">' + t('tkOntbrekendeBatchGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLocation') + '</th><th>' + t('colQty') + '</th></tr>';
    ontbrekendeBatch.forEach(function (item) {
      html += '<tr><td><a class="product-link" data-ean="' + escapeAttr(item.ean) + '">' + escapeHtml(item.productnaam || item.ean) + '</a></td><td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  const gaScannenBtn = document.getElementById('takenGaScannenBtn');
  if (gaScannenBtn) gaScannenBtn.addEventListener('click', showVervalAfboeken);
  document.querySelectorAll('.taak-tellen-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      showTelling(this.getAttribute('data-locatie'));
    });
  });
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
}

function showTakenBeheerder() {
  setRoute('taken-beheerder');
  currentPage = 'taken-beheerder';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderTakenBeheerder).getTakenBeheerder();
}

function renderTakenBeheerder(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('tasksAdmin') + '</h2>';

  // Barcodes die tijdens een scan niet
  // herkend werden, met de omschrijving van de scanner erbij -- afronden
  // gebeurt op het Admin-scherm "Onbekende Producten".
  html += '<div class="section-title">' + t('tkOnbekend') + '</div>';
  html += '<p class="form-hint">' + t('tkOnbekendHint') + '</p>';
  if (!data.onbekendeScans || data.onbekendeScans.length === 0) {
    html += '<p class="empty">' + t('tkOnbekendGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colEan') + '</th><th>' + t('onbOmschrijvingen') + '</th><th>' + t('colLocation') + '</th><th>' + t('tkKeerGezien') + '</th></tr>';
    data.onbekendeScans.forEach(function (item) {
      html += '<tr><td><code>' + escapeHtml(item.ean) + '</code></td><td>' + escapeHtml(item.omschrijvingen.join('; ') || '-') + '</td>'
        + '<td>' + escapeHtml(item.locaties.join(', ') || '-') + '</td><td>' + item.aantalKeerGezien + '</td></tr>';
    });
    html += '</table>';
    html += '<div class="edit-actions"><button class="edit-save" id="takenGaOnbekendBtn">' + t('tkGoOnbekend') + '</button></div>';
  }

  // : welke producten missen essentiële informatie?
  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('tkOnvolledig') + '</div>';
  html += '<p class="form-hint">' + t('tkOnvolledigHint') + '</p>';
  if (!data.onvolledigeProducten || data.onvolledigeProducten.length === 0) {
    html += '<p class="empty">' + t('tkOnvolledigGeen') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('tkOntbrekendeVelden') + '</th><th></th></tr>';
    data.onvolledigeProducten.forEach(function (item) {
      const badges = item.ontbrekend.map(function (veld) {
        return '<span class="kastlocatie-badge" style="margin-right:4px;">' + escapeHtml(t('tkVeld_' + veld)) + '</span>';
      }).join('');
      html += '<tr><td>' + escapeHtml(item.naam) + '</td><td>' + badges + '</td>'
        + '<td><button class="action-btn taak-product-btn" data-ean="' + escapeAttr(item.ean) + '">' + t('pdEdit') + '</button></td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  const gaOnbekendBtn = document.getElementById('takenGaOnbekendBtn');
  if (gaOnbekendBtn) gaOnbekendBtn.addEventListener('click', showOnbekendeProducten);
  document.querySelectorAll('.taak-product-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      showProductEdit(this.getAttribute('data-ean'));
    });
  });
}

// -----------------------------------------------------------------
// Telling: kastcontrole voor één locatie -- "de hele kast langs, alles
// scannen", zelfde bedieningspatroon als Scan Usage/Receive Stock (één
// GS1-blok, één regel per fysiek exemplaar). Het verschil met het
// systeemaantal per product wordt server-side bepaald en geboekt, zie
// includes/telling.php.
// -----------------------------------------------------------------
function showTelling(locatie, kastlocatie) {
  setRoute('telling', locatie);
  currentPage = 'telling';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  renderTellingForm(locatie, kastlocatie || null);
}

function renderTellingForm(locatie, kastlocatie) {
  // Titel/subtitel tonen de gekozen kast erbij als deze
  // telling met opzet tot één kast beperkt is (kastlocatie !== null) --
  // zie verwerkTelling() in includes/telling.php voor de matching-logica.
  const titelSuffix = kastlocatie ? (escapeHtml(locatie) + ' – ' + escapeHtml(kastlocatie)) : escapeHtml(locatie);
  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('tlTitle') + titelSuffix + '</h2>';
  if (kastlocatie) {
    html += '<p class="form-hint">' + tf('tlKastHint', { kast: kastlocatie }) + '</p>';
  }
  html += '<p class="form-hint">' + t('tlHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('tlScansLabel') + '</label><textarea id="tellingGs1" rows="10" placeholder="Scan hier..."></textarea><div id="tellingGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="tellingSubmit">' + t('tlSubmit') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  initGs1ScanHint('tellingGs1', 'tellingGs1Hint');

  const btn = document.getElementById('tellingSubmit');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    apiPost('api/telling.php', {
      locatie: locatie,
      gs1_raw: document.getElementById('tellingGs1').value,
      omschrijvingen: omschrijvingen || {},
      kastlocatie: kastlocatie || '',
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      renderTellingResultaat(locatie, kastlocatie, result);
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

function renderTellingResultaat(locatie, kastlocatie, result) {
  const titelSuffix = kastlocatie ? (escapeHtml(locatie) + ' – ' + escapeHtml(kastlocatie)) : escapeHtml(locatie);
  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('tlTitle') + titelSuffix + '</h2>';
  html += '<p class="form-hint">' + tf('tlProcessed', { n: result.verwerkt }) + (result.fouten.length ? tf('tlAndErrors', { n: result.fouten.length }) : '') + '.</p>';

  const afwijkingen = (result.regels || []).filter(function (r) { return r.verschil !== 0; });
  if (afwijkingen.length > 0) {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colSystem') + '</th><th>' + t('colCounted') + '</th><th>' + t('colDifference') + '</th></tr>';
    afwijkingen.forEach(function (r) {
      const teken = r.verschil > 0 ? '+' + r.verschil : String(r.verschil);
      html += '<tr><td>' + escapeHtml(r.naam) + '</td><td>' + r.systeem + '</td><td>' + r.geteld + '</td><td>' + teken + '</td></tr>';
    });
    html += '</table>';
  } else {
    html += '<p class="empty">' + t('tlAllMatched') + '</p>';
  }

  // Gescande producten die wél bekend zijn maar bij een
  // ANDERE, expliciet toegewezen kast horen dan de gekozen kast -- deze
  // zijn met opzet niet meegeteld/geboekt (zie $verkeerdeKast in
  // verwerkTelling(), includes/telling.php), en worden hier apart getoond
  // zodat de laborant ze alsnog naar de juiste kast kan terugleggen.
  if (result.verkeerdeKast && result.verkeerdeKast.length) {
    html += '<div class="section-title" style="margin-top:1rem;">' + t('tlVerkeerdeKastTitel') + '</div>';
    html += '<p class="form-hint">' + t('tlVerkeerdeKastHint') + '</p>';
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('tlVerkeerdeKastWerkelijk') + '</th></tr>';
    result.verkeerdeKast.forEach(function (r) {
      html += '<tr><td>' + escapeHtml(r.naam || r.ean) + '</td><td>' + escapeHtml(r.werkelijkeKast || '-') + '</td></tr>';
    });
    html += '</table>';
  }

  if (result.fouten && result.fouten.length) {
    html += '<div class="section-title" style="margin-top:1rem;">' + t('tlUnreadable') + '</div><pre>' + escapeHtml(result.fouten.join('\n')) + '</pre>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
}

// -----------------------------------------------------------------
// Afboekingen: historisch overzicht van vervallen (over datum) voorraad
// en tekorten uit kastcontrole (niet teruggevonden bij tellen). De
// "afgehandeld"-kant van Tasks (showTakenLaborant() hierboven toont wat er nog
// open staat, dit toont wat er al is afgeboekt) -- zie
// includes/dashboards.php::getAfboekingenOverzicht().
// -----------------------------------------------------------------
// Onthoud de laatst gekozen periode zodat een terugkeer naar
// dit scherm (of een her-render na filterwissel) niet steeds terugvalt op
// 'totaal'. Puur in-memory (geen browser-opslag, zie artifact-regels).
let afboekingenPeriode = 'totaal';
const AFBOEKINGEN_PERIODES = ['1m', '3m', '6m', '1j', '2j', 'totaal'];

function showAfboekingen(periode) {
  if (periode) afboekingenPeriode = periode;
  setRoute('afboekingen');
  currentPage = 'afboekingen';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderAfboekingen).getAfboekingenOverzicht(afboekingenPeriode);
}

function renderAfboekingenTabel(regels, kolomExtra) {
  if (regels.length === 0) return '<p class="empty">' + t('noneRecorded') + '</p>';
  let html = '<table><tr><th>' + t('colDate') + '</th><th>' + t('colProduct') + '</th>' + (kolomExtra ? '<th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th>' : '') + '<th>' + t('colLocation') + '</th><th>' + t('colQty') + '</th><th>' + t('colValue') + '</th></tr>';
  regels.forEach(function (r) {
    html += '<tr><td>' + escapeHtml(String(r.tijdstempel).substring(0, 10)) + '</td><td><a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.productnaam || r.ean) + '</a></td>'
      + (kolomExtra ? '<td>' + escapeHtml(r.lot || '-') + '</td><td>' + escapeHtml(r.vervaldatum ? String(r.vervaldatum).substring(0, 10) : '-') + '</td>' : '')
      + '<td>' + escapeHtml(r.locatie) + '</td><td>' + r.hoeveelheid + '</td><td>€' + r.waarde.toFixed(2) + '</td></tr>';
  });
  html += '</table>';
  return html;
}

function renderAfboekingen(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('writeOffs') + '</h2>';
  html += usageTabsHtml('afboekingen');
  html += '<p class="form-hint">' + t('woHint') + '</p>';

  // Periode-knoppenrij. De actief-gemarkeerde knop is de
  // periode waar de zojuist ontvangen 'data' ook daadwerkelijk bij hoort
  // (data.periode, komt van getAfboekingenOverzicht()) i.p.v. het lokale
  // afboekingenPeriode-geheugen -- die twee horen altijd gelijk te zijn,
  // maar dit voorkomt een verkeerd-gemarkeerde knop als dat ooit uit de pas loopt.
  html += '<div class="periode-filter">';
  AFBOEKINGEN_PERIODES.forEach(function (p) {
    const actief = (data.periode || 'totaal') === p ? ' periode-actief' : '';
    html += '<button class="action-btn periode-btn' + actief + '" data-periode="' + p + '">' + t('woPeriode_' + p) + '</button> ';
  });
  html += '</div>';

  html += '<div class="section-title">' + tf('woExpiredTitle', { units: data.vervallen.totaalAantal, value: '€' + data.vervallen.totaalWaarde.toFixed(2) }) + '</div>';
  html += renderAfboekingenTabel(data.vervallen.regels, true);

  html += '<div class="section-title" style="margin-top:1.5rem;">' + tf('woShortageTitle', { units: data.tekorten.totaalAantal, value: '€' + data.tekorten.totaalWaarde.toFixed(2) }) + '</div>';
  html += renderAfboekingenTabel(data.tekorten.regels, false);

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.periode-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { showAfboekingen(this.getAttribute('data-periode')); });
  });
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
  wireUsageTabs();
}

function showProductDetail(ean) {
  setRoute('product', ean);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderProductDetail).getProductDetail(ean);
}

// Productpagina: grafiek historisch verbruik. Handgerolde SVG-
// staafdiagram, geen externe chart-library -- deze app laadt bewust geen
// CDN-scripts (zelfde reden als code128Svg() verderop in dit bestand voor
// de barcode-generator: self-hosted zonder gegarandeerde internettoegang
// op de klantserver). `data` is de output van getProductVerbruikPerMaand()
// (includes/artikelen.php): een array van {maand: 'JJJJ-MM', hoeveelheid},
// altijd 12 aaneengesloten maanden, oudste eerst.
function verbruikChartSvg(data) {
  const breedte = 700, hoogte = 180;
  const padLinks = 10, padOnder = 28, padBoven = 16, padRechts = 10;
  const plotBreedte = breedte - padLinks - padRechts;
  const plotHoogte = hoogte - padBoven - padOnder;
  const max = Math.max.apply(null, data.map(function (d) { return d.hoeveelheid; }).concat([1]));
  const stap = plotBreedte / data.length;
  const barBreedte = stap * 0.6;

  let svg = '<svg viewBox="0 0 ' + breedte + ' ' + hoogte + '" xmlns="http://www.w3.org/2000/svg" class="verbruik-chart">';
  svg += '<line x1="' + padLinks + '" y1="' + (padBoven + plotHoogte) + '" x2="' + (padLinks + plotBreedte) + '" y2="' + (padBoven + plotHoogte) + '" stroke="#ccc"/>';

  data.forEach(function (d, i) {
    const barHoogte = max > 0 ? (d.hoeveelheid / max) * (plotHoogte - 18) : 0;
    const x = padLinks + i * stap + (stap - barBreedte) / 2;
    const y = padBoven + plotHoogte - barHoogte;
    svg += '<rect x="' + x.toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + barBreedte.toFixed(1) + '" height="' + Math.max(barHoogte, 0).toFixed(1) + '" fill="#667eea" rx="2">'
      + '<title>' + escapeHtml(d.maand) + ': ' + d.hoeveelheid + '</title></rect>';
    if (d.hoeveelheid > 0) {
      svg += '<text x="' + (x + barBreedte / 2).toFixed(1) + '" y="' + (y - 3).toFixed(1) + '" font-size="9" text-anchor="middle" fill="#333">' + d.hoeveelheid + '</text>';
    }
    // Korte maandnotatie (MM/JJ) i.p.v. de volle 'JJJJ-MM' -- zelfde
    // platte-string-aanpak als de rest van deze app (geen locale-afhankelijke
    // maandnamen), en past ruim genoeg voor 12 staven naast elkaar.
    const kortLabel = d.maand.slice(5, 7) + '/' + d.maand.slice(2, 4);
    svg += '<text x="' + (x + barBreedte / 2).toFixed(1) + '" y="' + (padBoven + plotHoogte + 14).toFixed(1) + '" font-size="9" text-anchor="middle" fill="#666">' + kortLabel + '</text>';
  });

  svg += '</svg>';
  return svg;
}

function renderProductDetail(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';

  const photoHtml = data.foto
    ? '<img class="product-photo" data-fullsrc="' + escapeAttr(data.foto) + '" src="' + escapeAttr(data.foto) + '" alt="Foto ' + escapeAttr(data.naam) + '">'
    : '<div class="product-photo-placeholder">' + t('noPhoto') + '</div>';

  html += '<div class="product-header">';
  html += photoHtml;
  html += '<div class="product-header-text">';
  html += '<h1>' + escapeHtml(data.naam) + '<button class="edit-btn" id="editProductBtn">' + t('pdEdit') + '</button></h1>';
  html += '<div class="product-info">';
  html += '<div class="info-box"><div class="info-label">' + t('colEan') + '</div><div class="info-value">' + data.ean + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colCategoryLabel') + '</div><div class="info-value">' + escapeHtml(data.categorie || '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colOrderType') + '</div><div class="info-value">' + escapeHtml(data.besteltype ? orderTypeLabel(data.besteltype) : '-') + '</div></div>';
  // Als de producentnaam op deze productkaart matcht met een bekende
  // producent (includes/producenten.php::vindProducentVoorNaam(), op
  // naam, case-insensitief), toon dan een link naar diens contactkaart --
  // anders gewoon platte tekst, precies zoals voorheen.
  const producentWaarde = data.producentInfo
    ? escapeHtml(data.producent) + ' <a class="producent-link" data-producent-id="' + data.producentInfo.id + '">(' + t('pdManufacturerContact') + ')</a>'
    : escapeHtml(data.producent || '-');
  html += '<div class="info-box"><div class="info-label">' + t('colManufacturerLabel') + '</div><div class="info-value">' + producentWaarde + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colArticleNumber') + '</div><div class="info-value">' + escapeHtml(data.artikelRef || '-') + '</div></div>';
  // : GS1-code en de "Print label"-knop horen niet meer
  // bij productgegevens (EAN/doos-EAN hieronder blijven wel gewoon
  // zichtbaar -- dat is de identificatie die logistiek/laboranten nodig
  // hebben, de ruwe GS1-scanstring en het printen van een los label niet).
  if (data.eenhedenPerDoos) {
    html += '<div class="info-box"><div class="info-label">' + t('apDoosEan') + '</div><div class="info-value">' + escapeHtml(data.doosEan || '-') + '</div></div>';
    html += '<div class="info-box"><div class="info-label">' + t('apEenhedenPerDoos') + '</div><div class="info-value">' + data.eenhedenPerDoos + '</div></div>';
  }
  if (data.logistiekCode) {
    html += '<div class="info-box"><div class="info-label">' + t('apLogistiekCode') + '</div><div class="info-value">' + escapeHtml(data.logistiekCode) + '</div></div>';
  }
  html += '<div class="info-box"><div class="info-label">' + t('colPricePerUnit') + '</div><div class="info-value">€' + data.prijs.toFixed(2) + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colTotalStockValue') + '</div><div class="info-value">€' + data.totaalWaarde.toFixed(2) + '</div></div>';
  if (data.orderStatus) {
    html += '<div class="info-box"><div class="info-label">' + t('colOrder') + '</div><div class="info-value">' + statusBadgeHtml(data.orderStatus) + '</div></div>';
  }
  html += '</div></div></div>';

  // Prominente banner als dit artikel
  // niet Actief is -- 'Niet leverbaar' (tijdelijk, met optionele
  // vervanger-link) of 'Niet meer in gebruik' (blijvend). De status zelf
  // wijzig je op het bewerkscherm (renderProductEdit()), hier alleen een
  // duidelijke melding + snelle link naar dat scherm en, indien ingesteld,
  // naar het vervangende artikel.
  if (data.status && data.status !== 'Actief') {
    const isNietLeverbaar = data.status === 'Niet leverbaar';
    const bannerKleur = isNietLeverbaar ? '#fff3cd' : '#e2e3e5';
    const bannerRand = isNietLeverbaar ? '#ffca6b' : '#adb5bd';
    html += '<div style="background:' + bannerKleur + '; border:1px solid ' + bannerRand + '; border-radius:4px; padding:12px 16px; margin-bottom:16px;">';
    html += '<strong>' + (isNietLeverbaar ? t('pdNietLeverbaar') : t('pdNietMeerInGebruik')) + '</strong>';
    if (isNietLeverbaar) {
      html += '<div style="margin-top:4px;">' + (data.vervangendArtikelNaam
        ? tf('pdVervangenDoor', { naam: '<a class="product-vervanger-link" data-ean-vervanger="' + escapeAttr(data.vervangendArtikelEan) + '">' + escapeHtml(data.vervangendArtikelNaam) + '</a>' })
        : t('pdNogGeenVervanger')) + '</div>';
    }
    html += '<div style="margin-top:6px;"><a class="status-edit-link" style="cursor:pointer; text-decoration:underline;">' + t('pdStatusWijzigen') + '</a></div>';
    html += '</div>';
  }

  if (data.categorieSpecs && data.categorieSpecs.length > 0) {
    html += '<div class="section-title">' + t('pdSpecs') + '</div><div class="specs-grid">';
    data.categorieSpecs.forEach(spec => {
      html += '<div class="spec-box"><div class="spec-label">' + escapeHtml(spec.label) + '</div><div class="spec-value">' + escapeHtml(String(spec.value)) + '</div></div>';
    });
    html += '</div>';
  }

  html += '<div class="section-title">' + t('pdStockByLocation') + '</div><table><tr><th>' + t('colLocation') + '</th><th>' + t('colCabinet') + '</th><th>' + t('colCurrent') + '</th><th>' + t('colMin') + '</th><th>' + t('colMax') + '</th><th>' + t('colValue') + '</th><th>' + t('colStatus') + '</th><th>' + t('pdAdvies') + '</th></tr>';
  data.voorraad_per_locatie.forEach(item => {
    const kastlocHtml = item.kastlocatie ? '<span class="kastlocatie-badge">' + escapeHtml(item.kastlocatie) + '</span>' : '-';
    // Het aggregaat-overzicht (Min/Max Advies) verbergt no-op-
    // en genegeerde voorstellen, maar hier op de productdetailpagina laten
    // we altijd zien wat er speelt -- inclusief "al optimaal" en "te weinig
    // data", zodat hier niets gemist wordt (zie getProductDetail()).
    // Kamers hebben geen eigen minimum
    // meer -- item.isBerging (van getProductDetail()) bepaalt of de
    // advies-vergelijking en de Min-kolom het minimum meenemen (Berging)
    // of niet (kamers, waar 'min' altijd 0/n.v.t. is).
    let adviesHtml = '<span class="muted">' + t('pdAdviesGeen') + '</span>';
    if (item.minmaxAdvies) {
      if (item.minmaxAdvies.status === 'ok') {
        const gelijk = item.isBerging
          ? (item.minmaxAdvies.adviesMin === item.min && item.minmaxAdvies.adviesMax === item.max)
          : (item.minmaxAdvies.adviesMax === item.max);
        if (gelijk) {
          adviesHtml = '<span class="muted">' + t('pdAdviesOptimaal') + '</span>';
        } else if (item.isBerging) {
          adviesHtml = '<strong>' + item.minmaxAdvies.adviesMin + ' / ' + item.minmaxAdvies.adviesMax + '</strong>';
        } else {
          adviesHtml = '<strong>' + item.minmaxAdvies.adviesMax + '</strong>';
        }
      }
    }
    const minCel = item.isBerging ? item.min : '&ndash;';
    html += '<tr><td>' + escapeHtml(item.locatie) + '</td><td>' + kastlocHtml + '</td><td><strong>' + item.huidig + '</strong></td><td>' + minCel + '</td><td>' + item.max + '</td><td>€' + item.waarde.toFixed(2) + '</td><td>' + statusBadgeHtml(item.status) + '</td><td>' + adviesHtml + '</td></tr>';
  });
  html += '</table>';

  if (data.loten.length > 0) {
    // ("Loten
    // op voorraad anders indelen; ik wil dat per lot een regel wordt
    // weergegeven (met aantal, vervaldatum, barcode, etc.), deze moet
    // uitklapbaar zijn om vervolgens te zien waar hoeveel liggen"):
    // data.loten komt nog steeds ongewijzigd van getProductDetail() --
    // één entry per ean+lot+vervaldatum+locatie (zie voorraadLot() in
    // includes/voorraad.php) -- maar wordt hier in de frontend gegroepeerd
    // tot één zichtbare rij per lot (sleutel = lot+vervaldatum, want
    // status/barcode zijn binnen dat lot toch identiek, ongeacht op welke
    // locatie het ligt). De rij toont het totaalaantal over alle locaties;
    // een uitklapper eronder (zelfde .batch-lines-row-patroon als Recente
    // Transacties/Order detail) toont per locatie hoeveel er precies ligt.
    // "Bewerken" blijft werken zoals voorheen: dat corrigeert toch al het
    // hele lot over alle locaties heen (includes/lot_correcties.php), dus
    // één representatieve rij per groep is voldoende invoer daarvoor.
    const lotGroepen = [];
    const lotGroepIndex = {};
    data.loten.forEach(function (item) {
      const sleutel = (item.lot || '') + '|' + (item.vervaldatumSql || '');
      if (!(sleutel in lotGroepIndex)) {
        lotGroepIndex[sleutel] = lotGroepen.length;
        lotGroepen.push({
          lot: item.lot, vervaldatum: item.vervaldatum, vervaldatumSql: item.vervaldatumSql,
          status: item.status, gs1Raw: item.gs1Raw, huidig: 0, locaties: [],
        });
      }
      const groep = lotGroepen[lotGroepIndex[sleutel]];
      groep.huidig += item.huidig;
      if (!groep.gs1Raw && item.gs1Raw) groep.gs1Raw = item.gs1Raw;
      groep.locaties.push({ locatie: item.locatie, huidig: item.huidig });
    });

    // Barcode-kolom + "Bewerken"-actie
    // per lot, voor als de GS1-scan het lotnummer/de vervaldatum niet
    // helemaal goed heeft herkend. Bewerken zet niet alleen dit lot recht
    // (over alle locaties waar het voorkomt, ongeacht ouderdom -- zie
    // includes/lot_correcties.php), maar onthoudt de correctie ook voor
    // de exacte barcode zelf, zodat een volgende scan van hetzelfde lot
    // vanzelf de goede waarden gebruikt.
    html += '<div class="section-title">' + t('pdLotsInStock') + '</div><table><tr><th></th><th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colStatus') + '</th><th>' + t('colBarcodeCol') + '</th><th></th></tr>';
    lotGroepen.forEach((groep, idx) => {
      const barcodeCel = groep.gs1Raw
        ? '<code style="font-size:11px; word-break:break-all;">' + escapeHtml(groep.gs1Raw) + '</code>'
        : '<span class="muted">' + t('lotGeenBarcode') + '</span>';
      html += '<tr class="' + statusRowClass(groep.status) + '">'
        + '<td><button type="button" class="action-btn lot-loc-toggle" data-idx="' + idx + '">' + tf('ltBekijkLocaties', { n: groep.locaties.length }) + '</button></td>'
        + '<td>' + escapeHtml(groep.lot || '-') + '</td>'
        + '<td>' + groep.vervaldatum + '</td>'
        + '<td><strong>' + groep.huidig + '</strong></td>'
        + '<td>' + statusBadgeHtml(groep.status) + '</td>'
        + '<td>' + barcodeCel + '</td>'
        + '<td><button type="button" class="action-btn lot-edit-btn" data-idx="' + idx + '">' + t('lotBewerken') + '</button></td>'
        + '</tr>';
      html += '<tr id="lotLocRow' + idx + '" class="batch-lines-row" style="display:none;"><td colspan="7">'
        + '<table class="batch-regels"><tr><th>' + t('colLocation') + '</th><th>' + t('colQuantity') + '</th></tr>'
        + groep.locaties.map(function (l) {
          return '<tr><td>' + escapeHtml(l.locatie) + '</td><td>' + l.huidig + '</td></tr>';
        }).join('')
        + '</table></td></tr>';
      html += '<tr class="lot-edit-row" id="lotEditRow' + idx + '" style="display:none;"><td colspan="7">'
        + '<div class="form-group" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">'
        + '<div><label>' + t('colLot') + '</label><input type="text" id="lotEditLot' + idx + '" value="' + escapeAttr(groep.lot || '') + '"></div>'
        + '<div><label>' + t('colExpiryDate') + '</label><input type="date" id="lotEditDatum' + idx + '" value="' + escapeAttr(groep.vervaldatumSql || '') + '"></div>'
        + '<div class="edit-actions" style="margin:0;">'
        + '<button type="button" class="edit-save lot-edit-save" data-idx="' + idx + '">' + t('save') + '</button> '
        + '<button type="button" class="edit-cancel lot-edit-cancel" data-idx="' + idx + '">' + t('cancel') + '</button>'
        + '</div></div>'
        + '<p class="form-hint">' + (groep.gs1Raw ? t('lotBewerkHintMetBarcode') : t('lotBewerkHintZonderBarcode')) + '</p>'
        + '<div class="login-error" id="lotEditError' + idx + '" style="display:none;"></div>'
        + '</td></tr>';
    });
    html += '</table>';
    // Bewaard voor de event-handlers hieronder (data-idx verwijst nu naar
    // een positie in lotGroepen, niet meer rechtstreeks in data.loten).
    data._lotGroepen = lotGroepen;
  }

  if (data.transacties.length > 0) {
    html += '<div class="section-title">' + t('pdTransactionHistory') + '</div><table><tr><th>' + t('colDate') + '</th><th>' + t('colType') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colLocation') + '</th><th>' + t('colSpecialist') + '</th><th>' + t('colScannedBy') + '</th><th>' + t('colNote') + '</th></tr>';
    // Transactiehistorie groeperen per scanmoment:
    // getTransactieHistorieVoorEanGeclusterd()
    // (includes/transacties.php) groepeert alle transactietypes nu per
    // scanactie (batch_id) i.p.v. één rij per losse regel. Een gegroepeerde
    // rij (aantalRegels > 1) toont een "bekijk regels"-toggle i.p.v. een
    // los aantal in de Quantity-kolom -- zelfde uitklap-patroon als de
    // Order detail-pagina (renderOrderDetail()) en Recente Transacties.
    data.transacties.forEach((item, idx) => {
      if (item.aantalRegels > 1) {
        html += '<tr><td>' + item.timestamp.substring(0, 16) + '</td><td>' + transactieTypeLabel(item.type) + '</td>'
          + '<td><button type="button" class="action-btn pd-trans-toggle" data-idx="' + idx + '">' + tf('odBekijkLots', { n: item.aantalRegels }) + '</button></td>'
          + '<td>' + escapeHtml(item.locatie || '-') + '</td><td>' + (item.specialist || '-') + '</td><td>' + escapeHtml(item.gescandDoor || '-') + '</td><td>' + (item.opmerking ? escapeHtml(item.opmerking.substring(0, 30)) + '...' : '-') + '</td></tr>';
        html += '<tr id="pdTransLines' + idx + '" class="batch-lines-row" style="display:none;"><td colspan="7">'
          + '<table class="batch-regels"><tr><th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colType') + '</th><th>' + t('colLocation') + '</th></tr>'
          + item.regels.map(function (r) {
            return '<tr><td>' + escapeHtml(r.lot || '-') + '</td><td>' + escapeHtml(r.vervaldatum ? String(r.vervaldatum).substring(0, 10) : '-') + '</td><td>' + r.hoeveelheid + '</td><td>' + escapeHtml(transactieTypeLabel(r.transactietype)) + '</td><td>' + escapeHtml(r.locatie) + '</td></tr>';
          }).join('')
          + '</table></td></tr>';
        return;
      }
      html += '<tr><td>' + item.timestamp.substring(0, 16) + '</td><td>' + transactieTypeLabel(item.type) + '</td><td>' + item.hoeveelheid + '</td><td>' + escapeHtml(item.locatie || '-') + '</td><td>' + (item.specialist || '-') + '</td><td>' + escapeHtml(item.gescandDoor || '-') + '</td><td>' + (item.opmerking ? escapeHtml(item.opmerking.substring(0, 30)) + '...' : '-') + '</td></tr>';
    });
    html += '</table>';
  }

  if (data.verbruik_stats.length > 0) {
    html += '<div class="section-title">' + t('pdUsageBySpecialist') + '</div><table><tr><th>' + t('colSpecialist') + '</th><th>' + t('colTotalUsed') + '</th></tr>';
    data.verbruik_stats.forEach(item => {
      html += '<tr><td>' + escapeHtml(item.specialist) + '</td><td><strong>' + item.total + '</strong></td></tr>';
    });
    html += '</table>';
  }

  // getProductVerbruikPerMaand()
  // (includes/artikelen.php) geeft altijd de laatste 12 kalendermaanden
  // terug (ook lege maanden, hoeveelheid 0) -- alleen als het TOTAAL over
  // die 12 maanden 0 is, tonen we de lege staat i.p.v. een grafiek van
  // allemaal even hoge nul-staven.
  if (data.verbruikPerMaand && data.verbruikPerMaand.length > 0) {
    html += '<div class="section-title">' + t('pdVerbruiksgrafiekTitle') + '</div>';
    const totaalVerbruik = data.verbruikPerMaand.reduce(function (sum, d) { return sum + d.hoeveelheid; }, 0);
    if (totaalVerbruik === 0) {
      html += '<p class="empty">' + t('pdVerbruiksgrafiekEmpty') + '</p>';
    } else {
      html += '<div class="verbruik-chart-wrap">' + verbruikChartSvg(data.verbruikPerMaand) + '</div>';
    }
  }

  html += '<div class="section-title">' + t('pdOrderHistory') + '</div>';
  if (data.bestelhistorie.length > 0) {
    // : elke rij hier moet doorklikbaar zijn naar de
    // volledige orderdetailpagina (showOrderDetail(), zelfde bestemming
    // als de .bs-detail-link-rijen op het Bestellingen-scherm) -- hier
    // stond voorheen alleen platte tekst, dus geen manier om bijv. de
    // volledige (niet-afgekapte) opmerking of transacties bij deze order
    // in te zien zonder terug naar Bestellingen te navigeren.
    html += '<table><tr><th>' + t('colOrderedOn') + '</th><th>' + t('colOrdered') + '</th><th>' + t('colReceived') + '</th><th>' + t('colRemaining') + '</th><th>' + t('colStatus') + '</th><th>' + t('colOrderedBy') + '</th><th>' + t('colChannel') + '</th><th>' + t('colNote') + '</th></tr>';
    data.bestelhistorie.forEach(item => {
      html += '<tr class="pd-order-link" data-id="' + item.id + '" style="cursor:pointer;"><td>' + item.datum.substring(0, 16) + '</td><td>' + item.besteld + '</td><td>' + item.ontvangen + '</td><td>' + item.nog + '</td><td>' + statusBadgeHtml(item.status) + '</td><td>' + escapeHtml(item.besteller || '-') + '</td><td>' + escapeHtml(item.kanaal || '-') + '</td><td>' + (item.opmerking ? escapeHtml(item.opmerking.substring(0, 30)) + '...' : '-') + '</td></tr>';
    });
    html += '</table>';
  } else {
    html += '<p class="empty">' + t('pdNoOrders') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.pd-order-link').forEach(function (row) {
    row.addEventListener('click', function () { showOrderDetail(this.getAttribute('data-id')); });
  });
  document.querySelectorAll('.pd-trans-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row = document.getElementById('pdTransLines' + this.getAttribute('data-idx'));
      if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
    });
  });

  const photoEl = document.querySelector('.product-photo');
  if (photoEl) {
    photoEl.addEventListener('click', function() {
      openPhotoModal(this.getAttribute('data-fullsrc'));
    });
  }
  const editBtn = document.getElementById('editProductBtn');
  if (editBtn) {
    editBtn.addEventListener('click', function() {
      showProductEdit(data.ean);
    });
  }
  document.querySelectorAll('.producent-link').forEach(function (el) {
    el.addEventListener('click', function () { showProducentDetail(this.getAttribute('data-producent-id')); });
  });

  // Statusbanner-links -- "vervangen door" springt naar de
  // productpagina van de vervanger, "status wijzigen" naar het bewerkscherm
  // (waar de status/vervanger-controls staan, zie renderProductEdit()).
  const statusEditLink = document.querySelector('.status-edit-link');
  if (statusEditLink) statusEditLink.addEventListener('click', function () { showProductEdit(data.ean); });
  const vervangerLink = document.querySelector('.product-vervanger-link');
  if (vervangerLink) vervangerLink.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean-vervanger')); });

  // Uitklapper
  // die per lotgroep toont waar hoeveel ligt (zie de groepering hierboven
  // in de render-code). Zelfde tekst-toggle-patroon als
  // trans-view-btn/rtViewLines bij Recente Transacties.
  document.querySelectorAll('.lot-loc-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const idx = this.getAttribute('data-idx');
      const row = document.getElementById('lotLocRow' + idx);
      if (!row) return;
      const nowExpanded = row.style.display === 'none';
      row.style.display = nowExpanded ? 'table-row' : 'none';
      this.textContent = nowExpanded ? t('ltHideLocaties') : tf('ltBekijkLocaties', { n: data._lotGroepen[idx].locaties.length });
    });
  });

  // Lotnummer/vervaldatum per lot
  // corrigeren vanaf dit overzicht -- zie includes/lot_correcties.php.
  // Werkt nu op een lotgroep (data._lotGroepen) i.p.v.
  // rechtstreeks op data.loten, maar de correctie zelf blijft ongewijzigd
  // -- lot_corrigeren() in includes/lot_correcties.php paste hem toch al
  // toe op alle locaties waar dit lot voorkomt.
  document.querySelectorAll('.lot-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row = document.getElementById('lotEditRow' + this.getAttribute('data-idx'));
      if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    });
  });
  document.querySelectorAll('.lot-edit-cancel').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row = document.getElementById('lotEditRow' + this.getAttribute('data-idx'));
      if (row) row.style.display = 'none';
    });
  });
  document.querySelectorAll('.lot-edit-save').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const idx = this.getAttribute('data-idx');
      const item = data._lotGroepen[idx];
      const errEl = document.getElementById('lotEditError' + idx);
      const nieuwLot = document.getElementById('lotEditLot' + idx).value.trim();
      const nieuweVervaldatum = document.getElementById('lotEditDatum' + idx).value || null;
      errEl.style.display = 'none';
      apiPost('api/product_edit.php', {
        actie: 'lot_corrigeren', ean: data.ean,
        oudLot: item.lot, oudVervaldatum: item.vervaldatumSql,
        nieuwLot: nieuwLot, nieuweVervaldatum: nieuweVervaldatum,
      }, function () {
        showProductDetail(data.ean);
      }, function (err) {
        errEl.textContent = (err && err.error) || t('somethingWrong');
        errEl.style.display = 'block';
      });
    });
  });
}

// =================================================================
// PRODUCT BEWERKEN - edit-formulier voor de detailpagina
// -----------------------------------------------------------------
// ("ik wil daadwerkelijk alles kunnen bewerken. Ook
// moet het formulier er een beetje netjes uit zien ipv alles opsommend
// achterelkaar"): dit was één lange, platte lijst van alle velden door
// elkaar (data.commonFields simpelweg in volgorde uitgeklapt). Nu
// opgesplitst in duidelijke secties (Identificatie/Besteldata/Voorraad/
// Doosverpakking/Logistiek/Specificaties/Status), met Producent en
// Besteltype als dropdown i.p.v. vrij tekstveld -- zelfde
// dropdown+"nieuwe toevoegen"-patroon als op Artikel Toevoegen
// (renderArtikelToevoegenForm() hieronder), zodat beide formulieren
// consistent aanvoelen. Categorie is nu ook een dropdown en bewerkbaar
// (verplaatst de specificatievelden mee, zelfde live gedrag als bij
// Artikel Toevoegen).
// =================================================================
/**
 * Dropdown-met-"nieuwe toevoegen" voor
 * een kastlocatie-veld -- zelfde patroon als het Producent-veld
 * (select data-key="..." + companion tekstveld dat zichtbaar wordt bij
 * de "__nieuw__"-optie). Gedeeld tussen renderProductEdit() en
 * renderArtikelToevoegenForm() zodat beide schermen er identiek uitzien.
 * idPrefix voorkomt DOM-id-botsingen tussen de drie kamers (ik/hybok/berging)
 * op hetzelfde scherm.
 */
function kastlocatieVeldHtml(idPrefix, veldKey, huidigeWaarde, suggesties) {
  const lijst = suggesties || [];
  const isNieuw = !!huidigeWaarde && lijst.indexOf(huidigeWaarde) === -1;
  const selectId = idPrefix + '_' + veldKey;
  const nieuwId = selectId + '_nieuw';
  let html = '<div class="form-group"><label>' + t('peKastlocatie') + '</label>';
  html += '<select id="' + selectId + '" data-key="' + veldKey + '">';
  html += '<option value="">' + t('peKastlocatieNone') + '</option>';
  html += lijst.map(function (naam) {
    return '<option value="' + escapeAttr(naam) + '"' + (naam === huidigeWaarde ? ' selected' : '') + '>' + escapeHtml(naam) + '</option>';
  }).join('');
  html += '<option value="__nieuw__"' + (isNieuw ? ' selected' : '') + '>' + t('peKastlocatieNew') + '</option>';
  html += '</select>';
  html += '<input type="text" id="' + nieuwId + '" placeholder="' + escapeAttr(t('peKastlocatieNewPlaceholder')) + '" value="' + escapeAttr(isNieuw ? huidigeWaarde : '') + '" style="display:' + (isNieuw ? 'block' : 'none') + '; margin-top:6px;">';
  html += '</div>';
  return html;
}

/** Wiring voor kastlocatieVeldHtml(): change-toggle voor de "nieuw"-invoer. */
function wireKastlocatieVeld(idPrefix, veldKey) {
  const selectId = idPrefix + '_' + veldKey;
  const select = document.getElementById(selectId);
  if (!select) return;
  select.addEventListener('change', function () {
    document.getElementById(selectId + '_nieuw').style.display = this.value === '__nieuw__' ? 'block' : 'none';
  });
}

/**
 * Resolvet een "__nieuw__"-sentinel in $updates[veldKey] naar de getypte
 * waarde in de companion-tekstinvoer -- op te roepen bij het opslaan,
 * zelfde afhandeling als de Producent-dropdown in saveProductEdit().
 */
function resolveKastlocatieVeld(updates, idPrefix, veldKey) {
  if (updates[veldKey] === '__nieuw__') {
    const nieuwEl = document.getElementById(idPrefix + '_' + veldKey + '_nieuw');
    updates[veldKey] = nieuwEl ? nieuwEl.value.trim() : '';
  }
}

function showProductEdit(ean, vanuitOnbekendeProducten) {
  setRoute('product-edit', ean);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  // Zelfde sequentiële StocuroRun-opbouw als showArtikelToevoegen(): de
  // shim ondersteunt maar één actieve aanroep tegelijk, dus kastlocaties ->
  // locaties -> producenten -> categorieën -> pas dan de artikelgegevens
  // Zelf ophalen. kastlocatieSuggesties toegevoegd, met
  // dezelfde "faalt de suggestielijst? gewoon met een lege lijst verder"-
  // aanpak als producenten/categorieën hieronder -- de dropdown valt dan
  // simpelweg terug op alleen de "nieuwe toevoegen"-optie.
  // VanuitOnbekendeProducten wordt hier alleen doorgegeven
  // (niet gebruikt) -- zie renderProductEdit() hieronder voor waar dit
  // toe doet.
  StocuroRun.withSuccessHandler(function (kastlocatieSuggesties) {
    fetchLocaties(function (locRows) {
      StocuroRun.withSuccessHandler(function (producenten) {
        StocuroRun.withSuccessHandler(function (categorieen) {
          StocuroRun.withSuccessHandler(function (data) {
            renderProductEdit(data, locRows, producenten, categorieen, kastlocatieSuggesties, vanuitOnbekendeProducten);
          }).withFailureHandler(function (err) {
            document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + (err && err.message ? err.message : '') + '</h2>';
            wire('backBtn', goBack);
          }).getArtikelEditData(ean);
        }).withFailureHandler(function () {
          StocuroRun.withSuccessHandler(function (data) {
            renderProductEdit(data, locRows, producenten, [], kastlocatieSuggesties, vanuitOnbekendeProducten);
          }).getArtikelEditData(ean);
        }).getCategorieenMetSpecs();
      }).withFailureHandler(function () {
        StocuroRun.withSuccessHandler(function (data) {
          renderProductEdit(data, locRows, [], [], kastlocatieSuggesties, vanuitOnbekendeProducten);
        }).getArtikelEditData(ean);
      }).getProducenten();
    });
  }).withFailureHandler(function () {
    fetchLocaties(function (locRows) {
      StocuroRun.withSuccessHandler(function (data) {
        renderProductEdit(data, locRows, [], [], [], vanuitOnbekendeProducten);
      }).getArtikelEditData(ean);
    });
  }).getKastlocatieSuggesties();
}

function renderProductEdit(data, locRows, producenten, categorieen, kastlocatieSuggesties, vanuitOnbekendeProducten) {
  kastlocatieSuggesties = kastlocatieSuggesties || [];
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  function veld(key) {
    const f = data.commonFields.filter(function (x) { return x.key === key; })[0];
    return f ? f.value : '';
  }

  const naamVoorSlot = {};
  (locRows || []).forEach(function (r) { naamVoorSlot[r.slot] = r.naam; });
  const producentNamen = (producenten || []).map(function (p) { return p.naam; }).sort(function (a, b) { return a.localeCompare(b); });
  const producentHuidig = veld('producent');
  const producentIsNieuw = !!producentHuidig && producentNamen.indexOf(producentHuidig) === -1;

  const catActief = (categorieen || []).filter(function (c) { return c.actief; });
  const catNamen = catActief.length > 0 ? catActief.map(function (c) { return c.naam; }) : CATEGORIE_FALLBACK;
  // Bewaart de huidige categorienaam als optie, ook als die (bijv. na het
  // deactiveren van een categorie in Stamgegevens) niet meer in catNamen
  // voorkomt -- anders zou de select 'm stilzwijgend vervangen door de
  // eerste optie in de lijst zodra je dit scherm opent, zonder dat je iets
  // gewijzigd hebt.
  if (data.categorie && catNamen.indexOf(data.categorie) === -1) catNamen.push(data.categorie);
  const catOptionsHtml = catNamen.map(function (naam) {
    const labelKey = CATEGORIE_LABEL_KEYS[naam];
    const label = labelKey ? t(labelKey) : naam;
    return '<option value="' + escapeAttr(naam) + '"' + (naam === data.categorie ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
  }).join('');

  const specValueMap = {};
  (data.specFields || []).forEach(function (f) { specValueMap[f.key] = f.value; });
  function renderSpecsVeldenEdit(categorieNaam) {
    const cat = (categorieen || []).filter(function (c) { return c.naam === categorieNaam; })[0];
    const specs = (cat && cat.specs) || [];
    if (specs.length === 0) return '';
    // : zelfde dropdown-met-"nieuwe toevoegen"-patroon
    // als Artikel Toevoegen (zie specVeldHtml()) -- data-key is bewust het
    // attribuut hier (i.p.v. data-spec-key), want de generieke
    // saveProductEdit()-sweep leest alle input/select[data-key] hieronder
    // al samen met de rest van het formulier uit.
    let out = '<div class="edit-section-box"><div class="section-title">' + t('peSpecifications') + '</div><div class="edit-grid">';
    specs.forEach(function (s) {
      out += specVeldHtml(s, specValueMap[s.key] || '', 'data-key', 'data-key-nieuwvoor', 'pe');
    });
    out += '</div></div>';
    return out;
  }

  let html = '<button class="back-btn" id="cancelEditBtn">' + t('peCancelTop') + '</button>';
  html += '<h2>' + t('peEditTitle') + escapeHtml(data.naam) + '</h2>';
  html += '<p style="color:#999; font-size:13px;">' + tf('peHint', { ean: data.ean, cat: escapeHtml(data.categorie || '-') }) + '</p>';

  html += '<div class="edit-section-box"><div class="section-title">' + t('pePhoto') + '</div>';

  // Foto rechtstreeks in de database
  // i.p.v. alleen een link -- eigen upload/verwijder-knoppen, zelfde
  // clientside-aanpak (FileReader -> data-URL -> gewone JSON-POST) als
  // bij users/producent_contacten, alleen komt de foto hier niet op
  // schijf maar in artikelen.foto_data terecht (zie api/product_edit.php
  // actie foto_bijwerken/foto_verwijderen). Het aparte "Fotolink"-
  // tekstveld hierbeneden is weggehaald -- alles gaat nu via deze
  // upload-knop, een los invulbaar linkveld ernaast was verwarrend.
  // Bestaande producten die nog een foto_link hebben (van vóór deze
  // upload-functie) blijven intact en tonen die nog gewoon
  // op de productdetailpagina zelf als fallback (getProductDetail()),
  // alleen niet meer hier op het bewerkscherm.
  html += '<div class="pf-foto-row" style="display:flex; align-items:center; gap:16px; margin-bottom:12px;">';
  html += '<img class="edit-photo-preview" id="pfFotoPreview" src="' + escapeAttr(data.fotoData || '') + '" alt="" style="' + (data.fotoData ? '' : 'display:none;') + '">';
  html += '<div>';
  html += '<input type="file" accept="image/*" class="pf-foto-input" id="pfFotoInput" style="display:none;">';
  html += '<button type="button" class="action-btn" id="pfFotoBtn">' + t('fotoUpload') + '</button>';
  html += ' <button type="button" class="action-btn" id="pfFotoRemoveBtn" style="' + (data.fotoData ? '' : 'display:none;') + '">' + t('fotoRemove') + '</button>';
  html += '<p style="color:#999; font-size:13px; margin:6px 0 0;">' + t('pfPhotoUploadHint') + '</p>';
  html += '</div>';
  html += '</div></div>';

  // --- Identificatie: naam, categorie, GS1-barcode, artikelnummer, EAN. ---
  html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionIdentification') + '</div><div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apProductName') + '</label><input type="text" data-key="productnaam" value="' + escapeAttr(veld('productnaam')) + '" required></div>';
  html += '<div class="form-group"><label>' + t('apCategory') + '</label><select id="peCategorieSelect" data-key="categorie">' + catOptionsHtml + '</select></div>';
  html += '<div class="form-group"><label>' + t('colArticleNumber') + '</label><input type="text" data-key="artikelref" value="' + escapeAttr(veld('artikelref')) + '"></div>';
  html += '</div>';
  // : geen GS1-ruwe-scan-veld meer bij productgegevens --
  // EAN (hieronder) blijft de zichtbare identificatie; artikelen.gs1_raw
  // blijft in de database staan (nog steeds gezet bij het aanmaken via
  // Artikel Toevoegen) maar wordt hier niet meer getoond of bewerkbaar gemaakt.
  html += '<div class="form-group"><label>' + t('peEanCurrentLabel') + '</label><div class="ean-current-readonly">' + escapeHtml(data.ean) + '</div></div>';
  html += '<div class="section-title" style="font-size:15px; border:none; margin-top:0.75rem;">' + t('peEanChangeTitle') + '</div>';
  html += '<p class="form-hint">' + t('peEanChangeHint') + '</p>';
  html += '<div class="ean-change-row"><div class="form-group"><label>' + t('peEanChangeNewLabel') + '</label><input type="text" id="peEanNieuw" value=""></div>';
  html += '<button type="button" id="peEanWijzigBtn">' + t('peEanChangeBtn') + '</button></div>';
  html += '<div id="peEanError" class="login-error" style="display:none;"></div>';
  html += '</div>';

  // --- Besteldata: besteltype, producent (dropdown+nieuw), prijs. ---
  html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionOrderData') + '</div><div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apOrderType') + '</label><select data-key="besteltype"><option value="Automatisch"' + (veld('besteltype') === 'Automatisch' ? ' selected' : '') + '>' + t('apOrderTypeAuto') + '</option><option value="Handmatig"' + (veld('besteltype') === 'Handmatig' ? ' selected' : '') + '>' + t('apOrderTypeManual') + '</option><option value="Eenmalig"' + (veld('besteltype') === 'Eenmalig' ? ' selected' : '') + '>' + t('apOrderTypeOnce') + '</option></select></div>';
  html += '<div class="form-group"><label>' + t('apPricePerUnit') + '</label><input type="number" step="0.01" data-key="prijs" value="' + escapeAttr(veld('prijs')) + '"></div>';
  html += '</div>';
  html += '<div class="form-group"><label>' + t('apManufacturer') + '</label><select id="peProducentSelect" data-key="producent">'
    + '<option value="">' + t('apManufacturerNone') + '</option>'
    + producentNamen.map(function (naam) {
      return '<option value="' + escapeAttr(naam) + '"' + (naam === producentHuidig ? ' selected' : '') + '>' + escapeHtml(naam) + '</option>';
    }).join('')
    + '<option value="__nieuw__"' + (producentIsNieuw ? ' selected' : '') + '>' + t('apManufacturerNew') + '</option>'
    + '</select>'
    + '<input type="text" id="peProducentNieuw" placeholder="' + escapeAttr(t('apManufacturerNewPlaceholder')) + '" value="' + escapeAttr(producentIsNieuw ? producentHuidig : '') + '" style="display:' + (producentIsNieuw ? 'block' : 'none') + '; margin-top:6px;">'
    + '</div>';
  html += '</div>';

  // --- Voorraad: Min/Max + kastlocatie per kamer. ---
  // Kamers (ik/hybok/kar) hebben geen
  // minimum meer -- ze worden gegarandeerd dezelfde dag tot hun maximum
  // bijgevuld vanuit Berging, dus alleen het Max-veld wordt nog getoond.
  // Berging (de enige locatie die op een externe leverancier wacht)
  // behoudt zowel Min als Max.
  html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionStock') + '</div>';
  [['ik', 'Room A'], ['hybok', 'Room B'], ['kar', 'Cart'], ['berging', 'Storage']].forEach(function (slot) {
    const naam = escapeHtml(naamVoorSlot[slot[0]] || slot[1]);
    html += '<div class="edit-room-group"><div class="edit-room-title">' + naam + '</div><div class="edit-grid">';
    if (slot[0] === 'berging') {
      html += '<div class="form-group"><label>' + t('apMin') + naam + '</label><input type="number" data-key="min_' + slot[0] + '" value="' + escapeAttr(veld('min_' + slot[0]) || '0') + '"></div>';
    }
    html += '<div class="form-group"><label>' + t('apMax') + naam + '</label><input type="number" data-key="max_' + slot[0] + '" value="' + escapeAttr(veld('max_' + slot[0]) || '0') + '"></div>';
    if (slot[0] !== 'kar') {
      html += kastlocatieVeldHtml('pe', 'locatie_' + slot[0], veld('locatie_' + slot[0]), kastlocatieSuggesties);
    }
    html += '</div></div>';
  });
  html += '</div>';

  // --- Extra locaties, alleen als dit artikel die heeft. ---
  const extraVelden = data.commonFields.filter(function (f) { return /^extra_(min|max)_\d+$/.test(f.key); });
  if (extraVelden.length > 0) {
    html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionExtraLocations') + '</div><div class="edit-grid">';
    extraVelden.forEach(function (f) {
      html += '<div class="form-group"><label>' + escapeHtml(f.label) + '</label><input type="number" data-key="' + f.key + '" value="' + escapeAttr(f.value) + '"></div>';
    });
    html += '</div></div>';
  }

  // --- Doosverpakking. ---
  html += '<div class="edit-section-box"><div class="section-title">' + t('apDoosTitle') + '</div>';
  html += '<p class="form-hint">' + t('apDoosHint') + '</p><div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apDoosEan') + '</label><input type="text" data-key="doos_ean" value="' + escapeAttr(veld('doos_ean')) + '"></div>';
  html += '<div class="form-group"><label>' + t('apEenhedenPerDoos') + '</label><input type="number" min="2" data-key="eenheden_per_doos" value="' + escapeAttr(veld('eenheden_per_doos')) + '"></div>';
  html += '</div></div>';

  // --- Logistiek. ---
  html += '<div class="edit-section-box"><div class="section-title">' + t('apLogistiekTitle') + '</div>';
  html += '<p class="form-hint">' + t('apLogistiekHint') + '</p>';
  html += '<div class="form-group"><label>' + t('apLogistiekCode') + '</label><input type="text" data-key="logistiek_code" value="' + escapeAttr(veld('logistiek_code')) + '"></div>';
  html += '</div>';

  // --- Categorie-specifieke specificaties. ---
  html += '<div id="peSpecsContainer">' + renderSpecsVeldenEdit(data.categorie) + '</div>';

  // Status ('Actief'/'Niet leverbaar'/
  // 'Niet meer in gebruik') + optionele vervanger-koppeling. Stond
  // eerder in een eigen sectie met een EIGEN "Opslaan"-knop (status_bijwerken,
  // los van de generieke commonFields-actie hierboven) -- bugfix
  // ("Product bewerken heeft 2x opslaan button, dit kan 1 worden")
  // is dat nu samengevoegd: de velden staan hier nog wel in hun eigen
  // sectie (blijft een logisch aparte groep), maar er is nog maar ÉÉN
  // "Opslaan"-knop voor het hele formulier, zie saveProductEdit()
  // hieronder -- die stuurt bij het opslaan zowel de gewone velden
  // (updateArtikelVeld) als status/vervanger (status_bijwerken) in één
  // actie mee.
  html += '<div class="edit-section-box"><div class="section-title">' + t('peStatusTitel') + '</div>';
  html += '<p class="form-hint">' + t('peStatusHint') + '</p>';
  html += '<div class="form-group"><label>' + t('peStatus') + '</label><select id="peStatus">';
  ['Actief', 'Niet leverbaar', 'Niet meer in gebruik'].forEach(function (s) {
    const labelKey = s === 'Actief' ? 'statusActief' : (s === 'Niet leverbaar' ? 'statusNietLeverbaar' : 'statusNietMeerInGebruik');
    html += '<option value="' + s + '"' + (data.status === s ? ' selected' : '') + '>' + t(labelKey) + '</option>';
  });
  html += '</select></div>';

  html += '<div id="peVervangerSectie" style="' + (data.status === 'Niet leverbaar' ? '' : 'display:none;') + '">';
  html += '<div class="form-group"><label>' + t('peVervanger') + '</label><select id="peVervangerSelect"><option value="">' + t('peVervangerGeen') + '</option>';
  (data.mogelijkeVervangers || []).forEach(function (v) {
    html += '<option value="' + v.id + '"' + (data.vervangendArtikelId === v.id ? ' selected' : '') + '>' + escapeHtml(v.naam) + ' (' + escapeHtml(v.ean) + ')</option>';
  });
  html += '</select></div>';
  html += '<div class="form-group"><button class="action-btn" id="peVervangerNieuwBtn">' + t('peVervangerNieuwArtikel') + '</button></div>';
  html += '</div>';
  html += '</div>';

  html += '<div class="edit-actions"><button class="edit-save" id="saveEditBtn">' + t('save') + '</button><button class="edit-cancel" id="cancelEditBtn2">' + t('cancel') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  document.getElementById('cancelEditBtn').addEventListener('click', function() { showProductDetail(data.ean); });
  document.getElementById('cancelEditBtn2').addEventListener('click', function() { showProductDetail(data.ean); });
  document.getElementById('saveEditBtn').addEventListener('click', function() { saveProductEdit(data.ean, vanuitOnbekendeProducten); });
  // Eigenschapsvelden staan meteen in de initiële HTML, dus meteen
  // toggles wiren -- niet pas bij de eerste categoriewissel.
  wireSpecSelectToggles(document.getElementById('peSpecsContainer'));

  // Categorie is nu bewerkbaar (zie ARTIKEL_EDIT_FIELDS in
  // includes/artikelen.php) -- bij een wisseling de specificatievelden
  // opnieuw tekenen voor de nieuw gekozen categorie, zelfde gedrag als op
  // Artikel Toevoegen (renderArtikelToevoegenForm()).
  document.getElementById('peCategorieSelect').addEventListener('change', function () {
    const container = document.getElementById('peSpecsContainer');
    container.innerHTML = renderSpecsVeldenEdit(this.value);
    wireSpecSelectToggles(container);
  });

  // Producent-dropdown + "nieuwe producent"-tekstveld, zelfde patroon als
  // naProducentSelect/naProducentNieuw op Artikel Toevoegen.
  document.getElementById('peProducentSelect').addEventListener('change', function () {
    document.getElementById('peProducentNieuw').style.display = this.value === '__nieuw__' ? 'block' : 'none';
  });

  // Kastlocatie-dropdowns (per kamer), zelfde toggle-patroon.
  ['locatie_ik', 'locatie_hybok', 'locatie_berging'].forEach(function (veldKey) {
    wireKastlocatieVeld('pe', veldKey);
  });

  // EAN wijzigen : eigen, cascaderende actie i.p.v.
  // het generieke commonFields-opslaan-mechanisme -- zie wijzigArtikelEan()
  // in includes/artikelen.php voor waarom dit niet gewoon een tekstveld
  // met data-key kan zijn.
  const peEanWijzigBtn = document.getElementById('peEanWijzigBtn');
  if (peEanWijzigBtn) {
    peEanWijzigBtn.addEventListener('click', function () {
      const btn = this;
      const errorEl = document.getElementById('peEanError');
      errorEl.style.display = 'none';
      const nieuweEan = document.getElementById('peEanNieuw').value.trim();
      if (!nieuweEan) return;
      if (!confirm(tf('peEanChangeConfirm', { oud: data.ean, nieuw: nieuweEan }))) return;
      btn.disabled = true;
      btn.textContent = t('peEanChangeSaving');
      apiPost('api/product_edit.php', { actie: 'ean_wijzigen', ean: data.ean, nieuweEan: nieuweEan }, function (result) {
        alert(tf('peEanChangeSuccess', { ean: result.ean }));
        showProductEdit(result.ean, vanuitOnbekendeProducten);
      }, function (err) {
        btn.disabled = false;
        btn.textContent = t('peEanChangeBtn');
        errorEl.textContent = err.error || t('somethingWrong');
        errorEl.style.display = 'block';
      });
    });
  }

  // Upload/verwijder-knoppen voor de in-database
  // productfoto, zelfde aanpak als de gu-foto-*-knoppen op het
  // Gebruikers-scherm (readImageFileAsDataUrl -> apiPost), maar dan naar
  // api/product_edit.php (actie foto_bijwerken/foto_verwijderen) i.p.v.
  // api/gebruikers.php.
  const pfFotoBtn = document.getElementById('pfFotoBtn');
  const pfFotoInput = document.getElementById('pfFotoInput');
  const pfFotoRemoveBtn = document.getElementById('pfFotoRemoveBtn');
  if (pfFotoBtn && pfFotoInput) {
    pfFotoBtn.addEventListener('click', function () { pfFotoInput.click(); });
    pfFotoInput.addEventListener('change', function () {
      const file = this.files && this.files[0];
      readImageFileAsDataUrl(file, function (dataUrl) {
        pfFotoBtn.disabled = true;
        apiPost('api/product_edit.php', { actie: 'foto_bijwerken', ean: data.ean, foto: dataUrl }, function () {
          showProductEdit(data.ean, vanuitOnbekendeProducten);
        }, function (err) {
          pfFotoBtn.disabled = false;
          alert(err.error || t('somethingWrong'));
        });
      }, function (errMsg) {
        alert(errMsg);
      });
    });
  }
  if (pfFotoRemoveBtn) {
    pfFotoRemoveBtn.addEventListener('click', function () {
      if (!confirm(t('fotoRemoveConfirm'))) return;
      apiPost('api/product_edit.php', { actie: 'foto_verwijderen', ean: data.ean }, function () {
        showProductEdit(data.ean, vanuitOnbekendeProducten);
      }, function (err) {
        alert(err.error || t('somethingWrong'));
      });
    });
  }

  // Status/vervanger-sectie. De vervanger-controls tonen
  // zich alleen bij status 'Niet leverbaar' -- geen zin om een vervanger
  // te kiezen voor een Actief of blijvend Niet meer in gebruik artikel.
  const peStatus = document.getElementById('peStatus');
  const peVervangerSectie = document.getElementById('peVervangerSectie');
  if (peStatus && peVervangerSectie) {
    peStatus.addEventListener('change', function () {
      peVervangerSectie.style.display = this.value === 'Niet leverbaar' ? '' : 'none';
    });
  }

  // "Nieuw artikel aanmaken als vervanger": slaat eerst de status als
  // 'Niet leverbaar' op (ongeacht wat er nu in de dropdown staat -- je
  // klikt deze knop juist omdat dit artikel niet leverbaar is), en
  // navigeert dan naar Artikel Toevoegen met de belangrijkste
  // eigenschappen vooraf ingevuld (categorie/besteltype/min-max/
  // producent/prijs/doosverpakking) -- EAN/logistiekcode NIET, want de
  // vervanger is een ander fysiek product met zijn eigen barcode. Zodra
  // dat artikel is aangemaakt, koppelt api/artikel_toevoegen.php het
  // automatisch terug (vervangtVoorEan), zie includes/artikelen.php::
  // koppelAlsVervanger().
  const peVervangerNieuwBtn = document.getElementById('peVervangerNieuwBtn');
  if (peVervangerNieuwBtn) {
    peVervangerNieuwBtn.addEventListener('click', function () {
      const btn = this;
      btn.disabled = true;
      apiPost('api/product_edit.php', { actie: 'status_bijwerken', ean: data.ean, status: 'Niet leverbaar', vervangend_artikel_id: null }, function () {
        showArtikelToevoegen(null, {
          categorie: data.categorie,
          besteltype: veld('besteltype'),
          max_ik: veld('max_ik'),
          max_hybok: veld('max_hybok'),
          max_kar: veld('max_kar'),
          min_berging: veld('min_berging'), max_berging: veld('max_berging'),
          producent: veld('producent'), prijs: veld('prijs'),
        }, data.ean);
      }, function (err) {
        btn.disabled = false;
        alert(err.error || t('somethingWrong'));
      });
    });
  }
}

function saveProductEdit(ean, vanuitOnbekendeProducten) {
  // : Besteltype/Producent/Categorie zijn nu dropdowns
  // (<select data-key="...">) i.p.v. tekstvelden -- deze query moest
  // daarom uitgebreid worden, dat las eerder alleen input[data-key].
  const velden = document.querySelectorAll('#content input[data-key], #content select[data-key]');
  const updates = {};
  velden.forEach(function(el) {
    updates[el.getAttribute('data-key')] = el.value;
  });

  // Producent-dropdown: "__nieuw__" is geen echte producentnaam maar de
  // markering dat de gebruiker een NIEUWE naam heeft getypt in het
  // tekstveld ernaast (zelfde patroon als naProducentSelect/naProducentNieuw
  // op Artikel Toevoegen) -- die typte naam moet hier alsnog in de plaats
  // komen, anders slaat dit letterlijk "__nieuw__" op als producentnaam.
  if (updates.producent === '__nieuw__') {
    const nieuwEl = document.getElementById('peProducentNieuw');
    updates.producent = nieuwEl ? nieuwEl.value.trim() : '';
  }
  // Zelfde "__nieuw__"-afhandeling voor de drie kastlocatie-dropdowns.
  ['locatie_ik', 'locatie_hybok', 'locatie_berging'].forEach(function (veldKey) {
    resolveKastlocatieVeld(updates, 'pe', veldKey);
  });
  // Zelfde "__nieuw__"-afhandeling voor de eigenschap-dropdowns (specs).
  resolveSpecNieuwVelden(updates, document.getElementById('peSpecsContainer'), 'data-key-nieuwvoor');

  const saveBtn = document.getElementById('saveEditBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = t('peSaving');

  // Bugfix ("Product bewerken heeft 2x opslaan button, dit kan 1
  // worden"): status/vervanger had een eigen "Opslaan"-knop met een
  // eigen actie (status_bijwerken, andere kolommen/logica dan de generieke
  // commonFields-update hierboven, zie includes/artikelen.php). Onder de
  // motorkap blijven dit twee losse API-aanroepen -- ze schrijven naar
  // andere velden via andere validatie -- maar de gebruiker klikt nu nog
  // maar één keer: deze functie stuurt de status-actie gewoon vlak na de
  // geslaagde veldopslag mee, vóór er wordt doorgenavigeerd.
  function slaStatusOp(klaar) {
    const peStatusEl = document.getElementById('peStatus');
    if (!peStatusEl) { klaar(null); return; }
    const peVervangerEl = document.getElementById('peVervangerSelect');
    const vervangerWaarde = peVervangerEl ? peVervangerEl.value : '';
    apiPost('api/product_edit.php', {
      actie: 'status_bijwerken',
      ean: ean,
      status: peStatusEl.value,
      vervangend_artikel_id: vervangerWaarde ? parseInt(vervangerWaarde, 10) : null,
    }, function () { klaar(null); }, function (err) { klaar(err); });
  }

  StocuroRun.withSuccessHandler(function(result) {
    if (result.success) {
      slaStatusOp(function (statusErr) {
        if (statusErr) {
          alert(t('peErrorSaving') + (statusErr.error || t('somethingWrong')));
          saveBtn.disabled = false;
          saveBtn.textContent = t('save');
          return;
        }
        // Kwam je hier vandaan via
        // "Onbekende Producten" (een tijdelijk aangemaakt artikel afmaken),
        // ga dan terug naar die lijst i.p.v. naar de productpagina -- vaak
        // staan er meerdere te wachten, net als bij Onbekende Producten ->
        // Eenmalige Bestelling (v1.44).
        if (vanuitOnbekendeProducten) {
          showOnbekendeProducten();
          return;
        }
        showProductDetail(ean);
      });
    } else {
      alert(t('peErrorSaving') + result.error);
      saveBtn.disabled = false;
      saveBtn.textContent = t('save');
    }
  }).withFailureHandler(function(err) {
    alert(t('peErrorSaving') + err.message);
    saveBtn.disabled = false;
    saveBtn.textContent = t('save');
  }).updateArtikelVeld(ean, updates);
}

function openPhotoModal(src) {
  document.getElementById('photoModalImg').src = src;
  document.getElementById('photoModal').classList.add('show');
}

function closePhotoModal() {
  document.getElementById('photoModal').classList.remove('show');
  document.getElementById('photoModalImg').src = '';
}

// Product Zoeken en Categorieën
// samengevoegd tot "Producten" met tabbalk -- twee ingangen op dezelfde
// productenlijst (vrij zoeken vs. gegroepeerd per categorie).
const PRODUCT_TABS = [
  ['product-search', 'searchProducts', showProductSearch],
  ['categorien', 'categories', showCategorien],
];
function productTabsHtml(actief) {
  const zichtbaar = PRODUCT_TABS.filter(function (tab) { return heeftScherm(tab[0]); });
  if (zichtbaar.length <= 1) return '';
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  zichtbaar.forEach(function (tab) {
    html += '<button class="hub-tab-btn' + (tab[0] === actief ? ' active' : '') + '" data-product-tab="' + tab[0] + '">' + t(tab[1]) + '</button>';
  });
  html += '</div>';
  return html;
}
function wireProductTabs() {
  document.querySelectorAll('[data-product-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-product-tab');
      const target = PRODUCT_TABS.find(function (tab) { return tab[0] === id; });
      if (target) target[2]();
    });
  });
}
function showProductHub() {
  const eerste = PRODUCT_TABS.find(function (tab) { return heeftScherm(tab[0]); });
  if (eerste) eerste[2]();
}

// =================================================================
// BESCHIKBAARHEID: één zoekbaar overzicht van "wat is er, hoeveel, waar,
// status en verbruik" per product -- bedoeld als landingsscherm voor de artsen-
// groep, zodat een arts niet meer door Voorraad/Vervaldatums/Verbruik &
// Trends heen hoeft te klikken voor die ene vraag. Klik door naar de
// volledige productdetailpagina (showProductDetail) voor alles wat hier
// niet in past (loten, transactiehistorie, bestelhistorie, ...). Toont
// ook inkoopprijs/voorraadwaarde -- een expliciete keuze, geen
// aparte financiele afscherming binnen dit scherm.
// =================================================================
// Moet in sync blijven met BESCHIKBAARHEID_VERBRUIK_DAGEN in
// config/constants.php -- alleen gebruikt voor de kolomtekst ("verbruik
// laatste N dagen"), de daadwerkelijke berekening gebeurt server-side.
const BESCHIKBAARHEID_VERBRUIK_DAGEN_JS = 90;

function showBeschikbaarheid() {
  setRoute('beschikbaarheid');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderBeschikbaarheid).getBeschikbaarheid();
}

function beschikbaarheidLocatiesHtml(locaties) {
  if (!locaties || locaties.length === 0) return '<span class="muted">-</span>';
  return locaties.map(function (l) {
    return '<span class="kastlocatie-badge" title="' + escapeAttr(l.locatie) + ': ' + l.huidig + '">' + escapeHtml(l.locatie) + ' ' + l.huidig + '</span>';
  }).join(' ');
}

function renderBeschikbaarheid(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('beschikbaarheidTitle') + '</h2>';
  html += '<p class="form-hint">' + t('bhHint') + '</p>';
  html += '<div class="search-box"><input type="text" id="bhSearchInput" placeholder="' + escapeAttr(t('psPlaceholder')) + '"></div>';
  html += '<div id="bhResults"><table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategory') + '</th><th>' + t('colStatus') + '</th><th>' + t('bhTotalUnits') + '</th><th>' + t('colLocation') + '</th><th>' + t('colExpiryDate') + '</th><th>' + tf('bhUsageDays', { n: BESCHIKBAARHEID_VERBRUIK_DAGEN_JS }) + '</th><th>' + t('colPricePerUnit') + '</th><th>' + t('colValue') + '</th></tr>';
  data.forEach(function (item) {
    const statusBadge = item.status && item.status !== 'Actief'
      ? ' ' + statusBadgeHtml(item.status === 'Niet leverbaar' ? t('statusNietLeverbaar') : t('statusNietMeerInGebruik'))
      : '';
    const vervalHtml = item.eerstvolgendeVervaldatum
      ? item.eerstvolgendeVervaldatum + (item.loten6 > 0 ? ' ' + statusBadgeHtml('MONITOREN') : '')
      : '<span class="muted">-</span>';
    html += '<tr><td><a class="product-link" data-ean="' + escapeAttr(item.ean) + '">' + escapeHtml(item.naam) + '</a>' + statusBadge + '</td>'
      + '<td>' + escapeHtml(item.categorie || '-') + '</td>'
      + '<td>' + statusBadgeHtml(item.voorraadStatus) + '</td>'
      + '<td><strong>' + item.totaalStuks + '</strong></td>'
      + '<td>' + beschikbaarheidLocatiesHtml(item.locaties) + '</td>'
      + '<td>' + vervalHtml + '</td>'
      + '<td>' + item.verbruikRecent + '</td>'
      + '<td>€' + item.prijs.toFixed(2) + '</td>'
      + '<td>€' + item.totaalWaarde.toFixed(2) + '</td></tr>';
  });
  html += '</table></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });

  document.getElementById('bhSearchInput').addEventListener('keyup', function () {
    const query = this.value.toLowerCase();
    document.querySelectorAll('#bhResults tr').forEach(function (row, idx) {
      if (idx === 0) return;
      row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
  });
}

// =================================================================
// VOORRAAD-MEGATABEL: na de persona-navigatie bleef de
// zijbalk "geen sterke toevoeging" -- 16 losse dashboard-bestemmingen
// achter dezelfde knoppenmuur, alleen in de kantlijn. Onderliggende
// oorzaak: bijna alle voorraad-achtige schermen (Overzicht, Bijvullen,
// Berging, Voorraadwaarde, Min/Max Advies, Vervaldatums, Lot-tracking,
// Vervalfocus, Verplaatsadvies, Beschikbaarheid, Actiecentrum) zijn
// dezelfde databron (artikel x locatie, met lot-detail erbij) net anders
// gefilterd. Dit is dus geen tabbalk-hub zoals de vorige ronde (dat
// verplaatst schermen alleen achter dezelfde knop, lost de "aparte
// pagina"-vraag niet echt op) maar ÉÉN tabel met filterchips (met live
// aantallen) en aan/uit-schakelbare kolommen. Backend: zie
// includes/voorraad_tabel.php voor de volledige toelichting -- die
// combineert alleen bestaande, al geteste berekeningen, voegt zelf geen
// nieuwe voorraadlogica toe.
// =================================================================
let voorraadTabelData = [];
let voorraadTabelFilter = 'alles';
let voorraadTabelZoek = '';
const voorraadTabelKolommen = { waarde: true, advies: false, vervaldatum: true, verbruik: false, producent: false, kastlocatie: false };

const VOORRAAD_TABEL_FILTERS = [
  ['alles', 'vtFilterAlles', function (r) { return true; }],
  ['leeg', 'vtFilterLeeg', function (r) { return r.voorraadStatus === 'LEEG'; }],
  ['laag', 'vtFilterLaag', function (r) { return r.voorraadStatus === 'LAAG'; }],
  ['bijvullen', 'vtFilterBijvullen', function (r) { return r.magBijvullen; }],
  ['berging', 'vtFilterBerging', function (r) { return r.bergingKanBestellen; }],
  ['handmatig', 'vtFilterHandmatig', function (r) { return r.handmatigNodig !== null; }],
  ['vervalt', 'vtFilterVervalt', function (r) { return r.loten6 > 0; }],
  ['advies', 'vtFilterAdvies', function (r) { return r.minMaxAdvies !== null; }],
  ['verplaats', 'vtFilterVerplaats', function (r) { return r.verplaatsAdvies !== null; }],
];

function showVoorraad() {
  setRoute('voorraad');
  currentPage = 'voorraad';
  voorraadTabelFilter = 'alles';
  voorraadTabelZoek = '';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVoorraadTabel).getVoorraadTabel();
}

function renderVoorraadTabel(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  voorraadTabelData = data;

  let html = '<h2>' + t('stockHub') + '</h2>';
  html += '<p class="form-hint">' + t('vtHint') + '</p>';
  html += '<div class="search-box"><input type="text" id="vtSearchInput" placeholder="' + escapeAttr(t('psPlaceholder')) + '"></div>';
  html += '<div class="vt-filters" id="vtFilters"></div>';
  html += '<div class="vt-cols" id="vtCols">'
    + '<label><input type="checkbox" id="vtColWaarde"' + (voorraadTabelKolommen.waarde ? ' checked' : '') + '> ' + t('colValue') + '</label>'
    + '<label><input type="checkbox" id="vtColAdvies"' + (voorraadTabelKolommen.advies ? ' checked' : '') + '> ' + t('vtColAdvies') + '</label>'
    + '<label><input type="checkbox" id="vtColVervaldatum"' + (voorraadTabelKolommen.vervaldatum ? ' checked' : '') + '> ' + t('colExpiryDate') + '</label>'
    + '<label><input type="checkbox" id="vtColVerbruik"' + (voorraadTabelKolommen.verbruik ? ' checked' : '') + '> ' + t('vtColUsage') + '</label>'
    + '<label><input type="checkbox" id="vtColProducent"' + (voorraadTabelKolommen.producent ? ' checked' : '') + '> ' + t('vtColProducer') + '</label>'
    + '<label><input type="checkbox" id="vtColKastlocatie"' + (voorraadTabelKolommen.kastlocatie ? ' checked' : '') + '> ' + t('vtColKastlocatie') + '</label>'
    + '</div>';
  html += '<div id="vtBergingActions" style="display:none;">'
    + '<div class="edit-actions">'
    + '<button class="action-btn" id="vtBergingDownloadMailBtn">' + t('bpDownloadMailKnop') + '</button> '
    + '<button class="edit-save" id="vtBergingMarkeerBtn">' + t('bpMarkeerKnop') + '</button>'
    + '</div>'
    + '<p class="form-hint">' + t('bpDownloadMailHint') + '</p>'
    + '<p class="form-hint">' + t('bpMarkeerHint') + '</p>'
    + '</div>';
  html += '<div id="vtTableContainer"></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.getElementById('vtSearchInput').addEventListener('keyup', function () {
    voorraadTabelZoek = this.value.toLowerCase();
    renderVoorraadTabelInhoud();
  });
  ['waarde', 'advies', 'vervaldatum', 'verbruik', 'producent', 'kastlocatie'].forEach(function (kolom) {
    document.getElementById('vtCol' + kolom.charAt(0).toUpperCase() + kolom.slice(1)).addEventListener('change', function () {
      voorraadTabelKolommen[kolom] = this.checked;
      renderVoorraadTabelInhoud();
    });
  });

  // Bugfix: deze twee knoppen leven in #vtBergingActions,
  // dat maar één keer wordt opgebouwd (hier), terwijl renderVoorraadTabelInhoud()
  // hieronder bij ELKE filterklik/kolomtoggle/zoekopdracht opnieuw draait.
  // Stonden deze listeners daar (zoals voorheen), dan kreeg de knop bij elke
  // her-render een NIEUWE listener erbij zonder de oude te verwijderen --
  // klik je daarna op downloaden, dan vuurt de download net zo vaak als er
  // her-renders zijn geweest (vandaar de meerdere downloadvensters). Nu één
  // keer gebonden, hier, buiten renderVoorraadTabelInhoud() om.
  const downloadBtn = document.getElementById('vtBergingDownloadMailBtn');
  if (downloadBtn) {
    downloadBtn.addEventListener('click', function () {
      downloadEnMailBergingBestellijst(voorraadTabelBergingSubset());
    });
  }
  const markeerBtn = document.getElementById('vtBergingMarkeerBtn');
  if (markeerBtn) markeerBtn.addEventListener('click', markeerBergingBesteldVanuitVoorraad);

  renderVoorraadTabelInhoud();
}

function voorraadTabelGefilterd() {
  const actief = VOORRAAD_TABEL_FILTERS.find(function (f) { return f[0] === voorraadTabelFilter; });
  const pred = actief ? actief[2] : VOORRAAD_TABEL_FILTERS[0][2];
  let rijen = voorraadTabelData.filter(pred);
  if (voorraadTabelZoek) {
    rijen = rijen.filter(function (r) {
      return (r.naam + ' ' + r.ean + ' ' + r.categorie + ' ' + r.locatie + ' ' + r.producent).toLowerCase().includes(voorraadTabelZoek);
    });
  }
  return rijen;
}

function renderVoorraadTabelInhoud() {
  // Filterchips: tellingen komen altijd uit de VOLLEDIGE dataset (niet de
  // al-gefilterde subset), zodat je altijd ziet hoeveel er in elke
  // categorie zitten, ook als je een andere chip actief hebt staan.
  let chipsHtml = '';
  VOORRAAD_TABEL_FILTERS.forEach(function (f) {
    const aantal = voorraadTabelData.filter(f[2]).length;
    chipsHtml += '<button class="filter-btn vt-filter-btn' + (f[0] === voorraadTabelFilter ? ' active' : '') + '" data-filter="' + f[0] + '">' + t(f[1]) + ' (' + aantal + ')</button>';
  });
  document.getElementById('vtFilters').innerHTML = chipsHtml;
  document.querySelectorAll('.vt-filter-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      voorraadTabelFilter = this.getAttribute('data-filter');
      renderVoorraadTabelInhoud();
    });
  });

  const bergingActiesEl = document.getElementById('vtBergingActions');
  bergingActiesEl.style.display = voorraadTabelFilter === 'berging' ? '' : 'none';

  const rijen = voorraadTabelGefilterd();
  const k = voorraadTabelKolommen;

  let html = '';
  if (rijen.length === 0) {
    html = '<p class="empty">' + t('vtEmpty') + '</p>';
  } else {
    html = '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategory') + '</th><th>' + t('colLocation') + '</th>'
      + '<th>' + t('colCurrent') + '</th><th>' + t('colMin') + '</th><th>' + t('colMax') + '</th><th>' + t('colStockStatus') + '</th>'
      // "voorraad scherm moet ook laten
      // zien wanneer een product in bestelling is, dit is geen actie maar
      // een bestelstatus" -- altijd zichtbaar (geen vtCols-toggle), net als
      // de Voorraadstatus-kolom hiernaast, en niet in de contextuele
      // actiekolom verderop (die toont alleen wat je NU zou kunnen doen,
      // dit is een blijvende status ongeacht welke actie er verder is).
      + '<th>' + t('colOrderStatus') + '</th>'
      + (k.waarde ? '<th>' + t('colValue') + '</th>' : '')
      + (k.advies ? '<th>' + t('vtColAdvies') + '</th>' : '')
      + (k.vervaldatum ? '<th>' + t('colExpiryDate') + '</th>' : '')
      + (k.verbruik ? '<th>' + tf('bhUsageDays', { n: BESCHIKBAARHEID_VERBRUIK_DAGEN_JS }) + '</th>' : '')
      + (k.producent ? '<th>' + t('vtColProducer') + '</th>' : '')
      + (k.kastlocatie ? '<th>' + t('vtColKastlocatie') + '</th>' : '')
      + '<th>' + t('colAction') + '</th></tr>';

    rijen.forEach(function (r) {
      const statusBadge = r.status && r.status !== 'Actief'
        ? ' ' + statusBadgeHtml(r.status === 'Niet leverbaar' ? t('statusNietLeverbaar') : t('statusNietMeerInGebruik'))
        : '';
      // Kamers hebben geen eigen minimum
      // meer -- r.min is voor die rijen altijd 0 (geen aparte kolom, niet
      // "minimum is 0"), dus toon "n.v.t." i.p.v. een misleidende 0.
      const minCel = r.isBerging ? r.min : '<span class="muted">' + t('mmNotApplicable') + '</span>';
      html += '<tr><td><a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.naam) + '</a>' + statusBadge + '</td>'
        + '<td>' + escapeHtml(r.categorie || '-') + '</td>'
        + '<td>' + escapeHtml(r.locatie) + '</td>'
        + '<td>' + r.huidig + '</td><td>' + minCel + '</td><td>' + r.max + '</td>'
        + '<td>' + statusBadgeHtml(r.voorraadStatus) + '</td>';

      // Zelfde klikbare-badge-patroon als renderOverzichtTabellen()
      // hierboven -- alleen een link als er ook echt een openstaande order is.
      html += r.orderStatus
        ? '<td><a class="order-status-link" data-ean="' + escapeAttr(r.ean) + '">' + statusBadgeHtml(r.orderStatus) + '</a></td>'
        : '<td>' + statusBadgeHtml('-') + '</td>';

      if (k.waarde) html += '<td>€' + r.waarde.toFixed(2) + '</td>';
      if (k.advies) {
        // r.minMaxAdvies.min is null voor kamers (geen minimum-advies meer)
        // -- toon dan alleen het max-advies, en stuur data-min=0 mee (de
        // backend negeert min voor kamer-slots toch, zie applyMinMaxAdvies()).
        html += r.minMaxAdvies
          ? '<td>' + (r.isBerging ? tf('vtAdviesWaarde', { min: r.minMaxAdvies.min, max: r.minMaxAdvies.max }) : r.minMaxAdvies.max) + ' '
            + '<button class="action-btn mm-apply-btn" data-ean="' + escapeAttr(r.ean) + '" data-locatie="' + escapeAttr(r.locatie) + '" data-min="' + (r.isBerging ? r.minMaxAdvies.min : 0) + '" data-max="' + r.minMaxAdvies.max + '">' + t('mmApply') + '</button> '
            + '<button class="action-btn mm-negeer-btn" data-ean="' + escapeAttr(r.ean) + '" data-locatie="' + escapeAttr(r.locatie) + '">' + t('mmNegeer') + '</button></td>'
          : '<td class="muted">-</td>';
      }
      if (k.vervaldatum) {
        // Bugfix: niet meer blind 'MONITOREN' tonen
        // zodra er een lot < 6 maanden is (dat omvat ook VERLOPEN/
        // SPOEDGEBRUIK) -- de echte classificatie van includes/voorraad.php::
        // classifyVervalStatus() gebruiken, meegegeven als
        // eerstvolgendeVervaldatumStatus.
        const vervalHtml = r.eerstvolgendeVervaldatum
          ? r.eerstvolgendeVervaldatum + (r.eerstvolgendeVervaldatumStatus ? ' ' + statusBadgeHtml(r.eerstvolgendeVervaldatumStatus) : '')
          : '<span class="muted">-</span>';
        html += '<td>' + vervalHtml + '</td>';
      }
      if (k.verbruik) html += '<td>' + r.verbruikRecent + '</td>';
      if (k.producent) html += '<td>' + escapeHtml(r.producent || '-') + '</td>';
      // Hier staat de locatie al in
      // haar eigen kolom hiernaast, dus de kastlocatie-waarde zelf volstaat
      // (geen naamsoverlap-risico zoals op het Bijvullen-scherm, waar
      // bron- en bestemmingslocatie op dezelfde rij naast elkaar staan).
      if (k.kastlocatie) html += '<td>' + escapeHtml(r.kastlocatie || '-') + '</td>';

      // Actiekolom: contextueel, past bij het huidige filter/de rij --
      // bewust geen aparte kolom per mogelijke actie (dat zou meteen weer
      // een muur van lege cellen worden voor de rijen waar het niet van
      // toepassing is).
      let actieHtml = '<span class="muted">-</span>';
      if (r.verplaatsAdvies) {
        actieHtml = tf('vtVerplaatsNaar', { locatie: r.verplaatsAdvies.naar, dagen: r.verplaatsAdvies.daysLeft });
      } else if (r.handmatigNodig !== null) {
        actieHtml = '<button class="action-btn btn-bestellen" data-ean="' + escapeAttr(r.ean) + '" data-naam="' + escapeAttr(r.naam) + '" data-aantal="' + r.handmatigNodig + '">' + t('toOrder2') + '</button>' + dozenSuffixHtml(r.handmatigDozenNodig);
      } else if (r.magBijvullen) {
        actieHtml = statusBadgeHtml('LAAG') + ' +' + r.bijvullenHoeveel;
      } else if (r.moetBijgevuld) {
        // Bugfix: onder minimum, maar Berging heeft
        // zelf (nog) geen voorraad om vanuit bij te vullen -- dit MOET
        // zichtbaar blijven als tekort, niet als "-" (of erger, als
        // "voldoende voorraad") verdwijnen.
        actieHtml = statusBadgeHtml('LEEG') + ' ' + t('vtBergingLeeg') + ' (+' + r.bijvullenHoeveel + ')';
      }
      html += '<td>' + actieHtml + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('vtTableContainer').innerHTML = html;

  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
  document.querySelectorAll('.order-status-link').forEach(function (el) {
    el.addEventListener('click', function () { showOrderDetailVoorEan(this.getAttribute('data-ean')); });
  });
  document.querySelectorAll('.mm-apply-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { applyMinMaxRowVoorraadTabel(this); });
  });
  document.querySelectorAll('.mm-negeer-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { negeerMinMaxRow(this); });
  });
  document.querySelectorAll('.btn-bestellen').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openBestellingModal(this.getAttribute('data-ean'), this.getAttribute('data-naam'), this.getAttribute('data-aantal'));
    });
  });
  // Bugfix: de download/markeer-knoppen worden NIET
  // hier gebonden -- ze leven buiten #vtTableContainer (dat hierboven wel
  // steeds vervangen wordt) en zouden anders bij elke aanroep van deze
  // functie een extra listener erbij krijgen. Zie renderVoorraadTabel()
  // voor de eenmalige binding.
}

// Zelfde als applyMinMaxRow() (elders in dit bestand, bij het oude
// Min/Max Advies-scherm), maar herlaadt na afloop de megatabel i.p.v. het
// hele scherm opnieuw op te halen -- de rij blijft gewoon zichtbaar (met
// bijgewerkt min/max), zodat je verder kan met de volgende rij.
function applyMinMaxRowVoorraadTabel(btn) {
  const ean = btn.getAttribute('data-ean');
  const locatie = btn.getAttribute('data-locatie');
  const min = parseInt(btn.getAttribute('data-min'), 10);
  const max = parseInt(btn.getAttribute('data-max'), 10);
  btn.disabled = true;
  btn.textContent = t('mmApplying');
  StocuroRun.withSuccessHandler(function (result) {
    if (result.success) {
      showVoorraad();
    } else {
      alert(t('mmErrorApplying') + result.error);
      btn.disabled = false;
      btn.textContent = t('mmApply');
    }
  }).withFailureHandler(function (err) {
    alert(t('mmErrorApplying') + err.message);
    btn.disabled = false;
    btn.textContent = t('mmApply');
  }).applyMinMaxAdvies(ean, locatie, min, max);
}

// Vertaalt de megatabel-rijvorm naar wat downloadEnMailBergingBestellijst()/
// bergingBestellijstPdfRijen()/bergingMailtoUrl() (elders in dit bestand,
// gebouwd voor het oude losse Berging-scherm) verwachten -- zelfde
// PDF/mailto-logica, geen duplicaat.
function voorraadTabelBergingSubset() {
  return voorraadTabelData.filter(function (r) { return r.bergingKanBestellen; }).map(function (r) {
    return { naam: r.naam, artikelref: r.artikelref, dozenNodig: r.bergingDozenNodig, nodig: r.bergingNodig, logistiekCode: r.logistiekCode };
  });
}

function markeerBergingBesteldVanuitVoorraad() {
  if (!confirm(t('bpMarkeerConfirm'))) return;
  apiPost('api/berging.php', { actie: 'markeer_besteld' }, function (result) {
    alert(tf('bpMarkeerGelukt', { aantal: result.aantalGemarkeerd }));
    showVoorraad();
  }, function (err) {
    alert(err.error || t('somethingWrong'));
  });
}

// =================================================================
// BIJVULLEN : een eigen
// dashboard voor laboranten om aan het eind van (of gedurende) de dag
// fysiek bij te vullen. "magBijvullen" bestond al als filterchip in
// Voorraad, maar dat is een passieve weergave binnen een tabel met veel
// andere kolommen/filters -- dit scherm is puur actiegericht: per
// kamerlocatie die tekort heeft, welk product en hoeveel, plus een
// geïntegreerd scanveld dat de verplaatsing van Berging naar die locatie
// meteen boekt. Geen nieuwe transactielogica: hergebruikt getVoorraadTabel()
// or de leeslijst en api/verplaatsing.php ongewijzigd voor het boeken.
function showBijvullen() {
  setRoute('bijvullen');
  currentPage = 'bijvullen';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (voorraad) {
    fetchLocaties(function (locRows) { renderBijvullen(voorraad, locRows); });
  }).getVoorraadTabel();
}

function renderBijvullen(voorraad, locRows) {
  if (voorraad.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + voorraad.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const bergingRow = locRows.find(function (r) { return r.slot === 'berging'; });
  const bergingNaam = bergingRow ? bergingRow.naam : null;
  const kamerLocaties = actieveLocatieNamenBestelling(locRows);

  // Een
  // kastlocatie-waarde (bv. "Kast 1") is NOOIT uniek op zichzelf -- "Kast 1"
  // in Berging en "Kast 1" in de Interventiekamer zijn twee compleet
  // verschillende schappen die toevallig hetzelfde heten. Daarom hieronder
  // ALTIJD de locatienaam + kastlocatie samen tonen (nooit kastlocatie
  // alleen), zowel voor de bron (Berging) als de bestemming (de kamer zelf).
  // De bron-kastlocatie staat niet op de "magBijvullen"-rij zelf (die is van
  // de kamer) maar op de Berging-rij van hetzelfde artikel -- daarom hier
  // een aparte opzoeking over de volledige, ongefilterde voorraad-dataset.
  function locatieMetKastlocatieLabel(locatieNaam, kastlocatie) {
    return locatieNaam + (kastlocatie ? ': ' + kastlocatie : '');
  }
  const bergingKastlocatiePerEan = {};
  if (bergingNaam) {
    voorraad.forEach(function (r) {
      if (r.locatie === bergingNaam) bergingKastlocatiePerEan[r.ean] = r.kastlocatie;
    });
  }

  // Bugfix: dit filterde voorheen alleen op
  // "magBijvullen" (kamerlocatie, actief, onder minimum, besteltype
  // Automatisch, ÉN Berging heeft er zelf voorraad van). Stond Berging op
  // nul, dan verdween de rij hier volledig uit beeld, en een lege lijst
  // werd hieronder gelezen als "alles op peil" -- terwijl er juist een
  // fors tekort was. "moetBijgevuld" (server-side, includes/voorraad_tabel.php)
  // laat elk tekort zien, ook als Berging het nu niet kan vullen; per rij
  // wordt hieronder apart getoond of het NU al kan (magBijvullen) of niet.
  const rijen = voorraad.filter(function (r) { return r.moetBijgevuld; });
  const perLocatie = {};
  rijen.forEach(function (r) {
    perLocatie[r.locatie] = perLocatie[r.locatie] || [];
    perLocatie[r.locatie].push(r);
  });

  let html = '<h2>' + t('bvTitle') + '</h2>';
  html += '<p class="form-hint">' + (bergingNaam ? tf('bvHint', { berging: bergingNaam }) : t('bvHintGeenBerging')) + '</p>';

  const locatiesMetTekort = Object.keys(perLocatie).sort();
  if (locatiesMetTekort.length === 0) {
    html += '<p class="empty">' + t('bvAllesOpPeil') + '</p>';
  } else {
    locatiesMetTekort.forEach(function (loc) {
      html += '<div class="section-title" style="margin-top:1.5rem;">📍 ' + escapeHtml(loc) + ' <span style="font-weight:normal;color:#888;font-size:13px;">(' + tf('bvAantalProducten', { n: perLocatie[loc].length }) + ')</span></div>';
      html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('bvColHuidig') + '</th><th>' + t('bvColBijvullen') + '</th>'
        + '<th>' + t('bvColVanaf') + '</th><th>' + t('bvColNaar') + '</th></tr>';
      perLocatie[loc].forEach(function (r) {
        const naarLabel = locatieMetKastlocatieLabel(loc, r.kastlocatie);
        if (!r.magBijvullen) {
          // Tekort bevestigd, maar Berging heeft er zelf niets van --
          // duidelijk als waarschuwing tonen i.p.v. als gewone "+N"-actie,
          // zodat dit nooit met "voldoende voorraad" verward kan worden.
          html += '<tr><td>' + listThumbHtml(r.foto, r.naam) + '<a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.naam) + '</a></td>'
            + '<td>' + r.huidig + '</td>'
            + '<td colspan="3" style="color:#b45309;">' + tf('bvBergingLeeg', { n: r.bijvullenHoeveel }) + '</td></tr>';
          return;
        }
        const vanafLabel = bergingNaam ? locatieMetKastlocatieLabel(bergingNaam, bergingKastlocatiePerEan[r.ean]) : '-';
        // Duimnagelfoto ook hier
        // -- Bijvullen is een fysieke scan-to-transfer-taak, dus visueel
        // herkennen van het juiste product helpt net als bij Product Zoeken.
        html += '<tr><td>' + listThumbHtml(r.foto, r.naam) + '<a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.naam) + '</a></td>'
          + '<td>' + r.huidig + '</td>'
          + '<td><strong>+' + r.bijvullenHoeveel + '</strong></td>'
          + '<td>' + escapeHtml(vanafLabel) + '</td>'
          + '<td>' + escapeHtml(naarLabel) + '</td></tr>';
      });
      html += '</table>';
    });
  }

  html += '<div class="section-title" style="margin-top:2rem;">' + t('bvScanTitle') + '</div>';
  html += '<p class="form-hint">' + t('bvScanHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('tfFromLocation') + '</label><input type="text" value="' + escapeAttr(bergingNaam || '-') + '" disabled></div>';
  html += '<div class="form-group"><label>' + t('tfToLocation') + '</label>' + keuzeknoppenHtml('bvNaarLocatie', kamerLocaties, locatiesMetTekort[0] || kamerLocaties[0]) + '</div>';
  html += '<div class="form-group"><label>' + t('tfScans') + '</label><textarea id="bvGs1" rows="6" placeholder="Scan hier..."></textarea><div id="bvGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="bvSubmit">' + t('tfSave') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  bindKeuzeknoppen('bvNaarLocatie');
  initGs1ScanHint('bvGs1', 'bvGs1Hint');
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });

  const btn = document.getElementById('bvSubmit');
  function doSubmit(omschrijvingen) {
    if (!bergingNaam) { alert(t('bvHintGeenBerging')); return; }
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    apiPost('api/verplaatsing.php', {
      actie: 'verplaatsen',
      gs1_raw: document.getElementById('bvGs1').value,
      van_locatie: bergingNaam,
      naar_locatie: document.getElementById('bvNaarLocatie').value,
      reden: '',
      opmerking: '',
      omschrijvingen: omschrijvingen || {},
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      // Herladen i.p.v. terug naar Home: dit scherm is bedoeld om
      // meerdere producten na elkaar bij te vullen, dus na een geslaagde
      // scan gewoon de bijgewerkte tekortenlijst
      // opnieuw tonen.
      alert(tf('tfProcessed', { n: result.verwerkt }));
      showBijvullen();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

// =================================================================
// BESTELLINGEN: Te Bestellen (Handmatig), Open Orders en Mijn
// Bestellingen waren 3 tabs
// achter dezelfde knop -- nog steeds 3 aparte databronnen/weergaven. Dit
// is genoeg dezelfde onderliggende vraag ("wat moet er nog besteld
// worden, en wat is de status") om er ÉÉN tabel van te maken met een
// statusfilter, i.p.v. een tabbalk. Geen nieuwe backend-aggregatie: de
// drie bestaande endpoints worden hier client-side samengevoegd tot één
// rijvorm. Rijklik gaat naar de bestaande detailpagina's (Order-detail /
// Eenmalige-bestelling-detail); een nog-niet-bestaande "Handmatig"-rij
// (nog geen order) krijgt de bestaande bestel-modal.
// =================================================================
let bestellingenData = [];
let bestellingenFilter = 'alles';

const BESTELLINGEN_FILTERS = [
  ['alles', 'bsFilterAlles', function (r) { return true; }],
  ['teBestellen', 'bsFilterTeBestellen', function (r) { return r.type === 'handmatig'; }],
  ['inBestelling', 'bsFilterInBestelling', function (r) { return r.status === 'In bestelling'; }],
  ['gedeeltelijk', 'bsFilterGedeeltelijk', function (r) { return r.status === 'Gedeeltelijk ontvangen'; }],
  ['mijn', 'bsFilterMijn', function (r) { return r.isEigen; }],
];

// ShowBestellingen() accepteert nu een
// optioneel startfilter -- showBestellers()/showOpenOrders()/
// showMijnBestellingen() hierboven zijn dunne aliassen hiernaartoe
// geworden en willen elk hun eigen filterchip alvast aan hebben staan
// i.p.v. altijd terug te vallen op 'alles'.
function showBestellingen(startFilter) {
  setRoute('bestellingen');
  currentPage = 'bestellingen';
  bestellingenFilter = startFilter || 'alles';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';

  StocuroRun.withSuccessHandler(function (bestellers) {
    StocuroRun.withSuccessHandler(function (orders) {
      StocuroRun.withSuccessHandler(function (eenmalig) {
        renderBestellingen(bestellers, orders, eenmalig);
      }).getOpenEenmaligeBestellingen(1);
    }).getOpenOrders();
  }).getBestellersData();
}

// ("Mijn bestellingen: altijd
// bestellingen van de ingelogde gebruiker zien. Een bestelling die wordt
// geplaatst krijgt ook altijd de koppeling van degene die besteld"):
// isEigen matcht nu rechtstreeks op besteller === de ingelogde gebruiker,
// voor zowel reguliere orders als eenmalige bestellingen. Besteller is
// voor allebei altijd de plaatser (regulier: zie api/bestelling.php,
// eenmalig: zie addEenmaligeBestelling()), dus is hier geen aparte
// "welke zijn van mij"-aanroep meer nodig (voorheen
// getEigenEenmaligeBestellingen(), die op specialist -- voor wie de
// aanvraag is, niet wie hem plaatste -- matchte, en alleen voor een
// specialist/laborant-account werkte). Elke ingelogde gebruiker die zelf
// iets heeft besteld ziet dat nu terug via het 'mijn'-filter.
function bestellingenNormaliseer(bestellers, orders, eenmalig) {
  const eigenNaam = window.CURRENT_USER ? window.CURRENT_USER.naam : null;
  const isVanMij = function (besteller) { return !!eigenNaam && besteller === eigenNaam; };

  const rijen = [];
  (bestellers || []).forEach(function (r) {
    rijen.push({
      // Nog geen echte order (alleen een advies) -- dus geen datum/
      // dagenOpen, en per definitie altijd Handmatig (dit IS de "Te
      // bestellen (Handmatig)"-lijst).
      type: 'handmatig', id: null, ean: r.ean, naam: r.naam,
      aantal: r.totaalNodig, dozenNodig: r.dozenNodig, status: 'Nog te bestellen',
      besteller: '', kanaal: '', datum: '', dagenOpen: null, besteltype: 'Handmatig', isEigen: false,
    });
  });
  (orders || []).forEach(function (r) {
    rijen.push({
      // : elke geplaatste order blijft een eigen rij
      // (nooit samengevoegd per product, zie includes/orders.php::
      // upsertOrder()) -- datum/dagenOpen/besteltype (Handmatig/
      // Automatisch, zie includes/orders.php::bestelMethode()) maken twee
      // orders voor hetzelfde product nu ook in deze tabel duidelijk uit
      // elkaar te houden.
      type: 'order', id: r.id, ean: r.ean, naam: r.naam,
      aantal: r.nog, dozenNodig: null, status: r.status,
      besteller: r.besteller, kanaal: r.kanaal, datum: r.datum, dagenOpen: r.dagenOpen,
      besteltype: r.besteltype, isEigen: isVanMij(r.besteller),
    });
  });
  (eenmalig || []).forEach(function (r) {
    rijen.push({
      // Een eenmalige (patiëntgebonden) bestelling is per definitie altijd
      // handmatig aangevraagd.
      type: 'eenmalig', id: r.id, ean: r.ean, naam: r.artikelomschrijving,
      aantal: r.hoeveelheid, dozenNodig: null, status: r.status,
      besteller: r.besteller, kanaal: '', datum: r.datum, dagenOpen: r.dagenOpen,
      besteltype: 'Handmatig', isEigen: isVanMij(r.besteller),
    });
  });
  return rijen;
}

function renderBestellingen(bestellers, orders, eenmalig) {
  const fout = (bestellers && bestellers.error) || (orders && orders.error) || (eenmalig && eenmalig.error);
  if (fout) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + fout + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  bestellingenData = bestellingenNormaliseer(bestellers, orders, eenmalig);
  // bestellingenFilter === 'mijn' erbij (naast "heeft al minstens 1 eigen
  // rij"): showMijnBestellingen() zet dit filter al vóór het laden, zodat
  // de chip ook zichtbaar/actief is als iemand toevallig nog niets zelf
  // besteld heeft (dan gewoon een lege tabel, i.p.v. dat de chip verdwijnt
  // en het filter onzichtbaar "verkeerd" blijft staan).
  const heeftMijn = bestellingenData.some(function (r) { return r.isEigen; }) || bestellingenFilter === 'mijn';

  let html = '<h2>' + t('ordersHub') + '</h2>';
  html += '<p class="form-hint">' + t('bsHint') + '</p>';
  html += '<div class="vt-filters" id="bsFilters"></div>';
  html += '<div id="bsTableContainer"></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  renderBestellingenInhoud(heeftMijn);
}

function renderBestellingenInhoud(heeftMijn) {
  let chipsHtml = '';
  BESTELLINGEN_FILTERS.forEach(function (f) {
    if (f[0] === 'mijn' && !heeftMijn) return;
    const aantal = bestellingenData.filter(f[2]).length;
    chipsHtml += '<button class="filter-btn bs-filter-btn' + (f[0] === bestellingenFilter ? ' active' : '') + '" data-filter="' + f[0] + '">' + t(f[1]) + ' (' + aantal + ')</button>';
  });
  document.getElementById('bsFilters').innerHTML = chipsHtml;
  document.querySelectorAll('.bs-filter-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      bestellingenFilter = this.getAttribute('data-filter');
      renderBestellingenInhoud(heeftMijn);
    });
  });

  const actief = BESTELLINGEN_FILTERS.find(function (f) { return f[0] === bestellingenFilter; });
  const rijen = bestellingenData.filter(actief ? actief[2] : function () { return true; });

  let html = '';
  if (rijen.length === 0) {
    html = '<p class="empty">' + t('bsEmpty') + '</p>';
  } else {
    // : Datum, Dagen open en Besteltype (Handmatig/
    // Automatisch) erbij, zodat losse orders voor hetzelfde product hier
    // duidelijk individueel te onderscheiden zijn i.p.v. optisch identiek
    // (zelfde reden als bestellingenNormaliseer() hierboven).
    html = '<table><tr><th>' + t('colProduct') + '</th><th>' + t('bsColType') + '</th><th>' + t('colOrderType') + '</th><th>' + t('colOrderedOn') + '</th><th>' + t('colDaysOpen') + '</th><th>' + t('colTotalNeeded') + '</th>'
      + '<th>' + t('colStockStatus') + '</th><th>' + t('orderedBy') + '</th><th>' + t('colAction') + '</th></tr>';
    rijen.forEach(function (r) {
      const typeLabel = r.type === 'handmatig' ? t('bsTypeHandmatig') : (r.type === 'eenmalig' ? t('bsTypeEenmalig') : t('bsTypeOrder'));
      const aantalTekst = r.aantal + dozenSuffixHtml(r.dozenNodig);
      const dagenHtml = r.dagenOpen === null ? '-' : (r.dagenOpen > 14 ? '<strong style="color:#b91c1c;">' + r.dagenOpen + '</strong>' : '<strong>' + r.dagenOpen + '</strong>');
      let actieHtml;
      if (r.type === 'handmatig') {
        actieHtml = '<button class="action-btn btn-bestellen" data-ean="' + escapeAttr(r.ean) + '" data-naam="' + escapeAttr(r.naam) + '" data-aantal="' + r.aantal + '">' + t('toOrder2') + '</button>';
      } else {
        actieHtml = '<a class="product-link bs-detail-link" data-type="' + r.type + '" data-id="' + r.id + '">' + t('editLabel') + '</a>';
      }
      // : geen label meer bij eigen bestellingen -- de
      // 'Mijn'-filterchip laat dat al zien, een extra badge per rij
      // (die bovendien de MONITOREN-stijl hergebruikte, wat elders
      // "loten met 3-12 maanden houdbaarheid" betekent) was overbodig en
      // verwarrend.
      //
      // Het onderscheid hieronder (ooit terecht bedoeld) bleek te grof
      // -- een 'order'-rij
      // is in de orders-tabel altijd EEN product (die tabel kent geen
      // groepering van meerdere EAN's onder één bestelling), dus die
      // linkt net als 'handmatig' gewoon naar het PRODUCT. Alleen een
      // 'eenmalig'-rij kan écht meerdere verschillende producten bevatten
      // (via de itemized eenmalige_bestelling_items-tabel)
      // -- alleen die linkt naar de BESTELLING zelf. De actie-kolom
      // hiernaast (Bewerken) blijft voor beide naar de bestelling linken,
      // dat is een bewuste andere kolom met een andere bedoeling.
      const productCelHtml = r.type === 'eenmalig'
        ? '<a class="product-link bs-detail-link" data-type="' + r.type + '" data-id="' + r.id + '">' + escapeHtml(r.naam) + '</a>'
        : (r.ean ? '<a class="product-link" data-ean="' + escapeAttr(r.ean) + '">' + escapeHtml(r.naam) + '</a>' : escapeHtml(r.naam));
      html += '<tr><td>' + productCelHtml + '</td>'
        + '<td>' + typeLabel + '</td>'
        + '<td>' + escapeHtml(r.besteltype) + '</td>'
        + '<td>' + (r.datum ? escapeHtml(r.datum) : '-') + '</td>'
        + '<td>' + dagenHtml + '</td>'
        + '<td>' + aantalTekst + '</td>'
        + '<td>' + statusBadgeHtml(r.status) + '</td>'
        + '<td>' + escapeHtml(r.besteller || '-') + '</td>'
        + '<td>' + actieHtml + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('bsTableContainer').innerHTML = html;
  document.querySelectorAll('.product-link[data-ean]').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
  document.querySelectorAll('.btn-bestellen').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openBestellingModal(this.getAttribute('data-ean'), this.getAttribute('data-naam'), this.getAttribute('data-aantal'));
    });
  });
  document.querySelectorAll('.bs-detail-link').forEach(function (el) {
    el.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      if (this.getAttribute('data-type') === 'order') showOrderDetail(id);
      else showEenmaligeBestellingDetail(id);
    });
  });
}

// =================================================================
// RAPPORTAGE / MANAGEMENT COCKPIT: deze pagina is later volledig herzien.
// Combineert uitsluitend cijfers die al elders
// berekend worden -- expliciet geen nieuwe backend-aggregatie, zie ook
// includes/beschikbaarheid.php voor dezelfde aanpak bij de artsen-groep.
//
// Voorheen: drie vaste, altijd-zichtbare secties
// (Voorraadwaarde, laatste-maand Verbruik&Trends, top-5 Team), geen
// enkele schakelaar ondanks dat de taaknaam destijds al "dimensie-
// schakelaar" beloofde. Nu ECHT vijf dimensies via een tabbalk (zelfde
// klik-om-te-wisselen-conventie als de filterchips op de Voorraad-
// megapagina, VOORRAAD_TABS e.d. hierboven in dit bestand) -- Voorraad,
// Verbruik, Team, Acties (nieuw: Min/Max-adviezen, Verplaatsadvies,
// Afboekingen-jaartotalen in één oogopslag) en Licentie (nieuw,
// beheerder-only: zelfde gebruikers/licentie-aggregatie als de Home-
// widget, zie renderHomeWidgets() hierboven). Alle acht onderliggende
// endpoints worden PARALLEL opgehaald (zelfde nogTeLaden-tellerpatroon
// als laadHomeWidgets()) en ALTIJD allemaal gerenderd -- de tabbalk
// toont/verbergt secties client-side (geen her-fetch bij het wisselen
// van tab), exact zoals de kolomtoggles op de Voorraad-megapagina dat
// al deden.
// =================================================================
let rapportageDimensie = 'voorraad';
const RAPPORTAGE_DIMENSIES = ['voorraad', 'verbruik', 'team', 'acties', 'licentie'];

function showManagementCockpit() {
  setRoute('management-cockpit');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';

  const magLicentie = !!(window.CURRENT_USER && window.CURRENT_USER.is_beheerder);
  let voorraadwaarde = null, trends = null, leaderboard = null, picc = null;
  let minmax = null, verplaatsadvies = null, afboekingen = null, gebruikersLicentie = null;
  let nogTeLaden = 7 + (magLicentie ? 1 : 0);

  function klaarAlsAlles() {
    if (nogTeLaden > 0) return;
    renderManagementCockpit({
      voorraadwaarde: voorraadwaarde, trends: trends, leaderboard: leaderboard, picc: picc,
      minmax: minmax, verplaatsadvies: verplaatsadvies, afboekingen: afboekingen,
      magLicentie: magLicentie, gebruikersLicentie: gebruikersLicentie,
    });
  }

  StocuroRun.withSuccessHandler(function (d) { voorraadwaarde = d; nogTeLaden--; klaarAlsAlles(); }).getVoorraadWaarde();
  StocuroRun.withSuccessHandler(function (d) { trends = d; nogTeLaden--; klaarAlsAlles(); }).getVerbruiksTrends();
  StocuroRun.withSuccessHandler(function (d) { leaderboard = d; nogTeLaden--; klaarAlsAlles(); }).getLeaderboardData('maand', '');
  StocuroRun.withSuccessHandler(function (d) { picc = d; nogTeLaden--; klaarAlsAlles(); }).getPiccRotatie('picc_rotatie');
  StocuroRun.withSuccessHandler(function (d) { minmax = d; nogTeLaden--; klaarAlsAlles(); }).getMinMaxAdviesOverzicht();
  StocuroRun.withSuccessHandler(function (d) { verplaatsadvies = d; nogTeLaden--; klaarAlsAlles(); }).getVerplaatsAdviesOverzicht();
  // '1j' (laatste jaar) i.p.v. 'totaal' --
  // voor een rapportage-KPI is een jaartotaal betekenisvoller dan de hele
  // historie sinds installatie; de drill-link opent gewoon de volledige
  // Afboekingen-pagina, die zelf haar eigen periodefilter behoudt.
  StocuroRun.withSuccessHandler(function (d) { afboekingen = d; nogTeLaden--; klaarAlsAlles(); }).getAfboekingenOverzicht('1j');
  if (magLicentie) {
    // Geen StocuroRun-methode (zie
    // showGebruikers()/laadHomeWidgets() hierboven), rechtstreeks fetch().
    fetch('api/gebruikers.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { gebruikersLicentie = d; nogTeLaden--; klaarAlsAlles(); })
      .catch(function () { nogTeLaden--; klaarAlsAlles(); });
  }
}

function rapportageTabsHtml() {
  const labels = {
    voorraad: t('rpDimVoorraad'), verbruik: t('rpDimVerbruik'), team: t('rpDimTeam'),
    acties: t('rpDimActies'), licentie: t('rpDimLicentie'),
  };
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  RAPPORTAGE_DIMENSIES.forEach(function (dim) {
    if (dim === 'licentie' && !(window.CURRENT_USER && window.CURRENT_USER.is_beheerder)) return;
    html += '<button type="button" class="hub-tab-btn rp-dim-btn' + (dim === rapportageDimensie ? ' active' : '') + '" data-dim="' + dim + '">' + labels[dim] + '</button>';
  });
  html += '</div>';
  return html;
}

function renderManagementCockpit(d) {
  const fout = d.voorraadwaarde.error || d.trends.error || d.leaderboard.error || d.picc.error
    || d.minmax.error || d.verplaatsadvies.error || d.afboekingen.error || (d.gebruikersLicentie && d.gebruikersLicentie.error);
  if (fout) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + fout + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('managementCockpitTitle') + '</h2>';
  html += '<p class="form-hint">' + t('mcHint') + '</p>';
  html += rapportageTabsHtml();

  // -- Dimensie: Voorraad (Voorraadwaarde) --
  html += '<div class="rp-dim-section" data-dim="voorraad" style="display:' + (rapportageDimensie === 'voorraad' ? 'block' : 'none') + ';">';
  html += '<div class="value-tiles"><div class="value-tile"><div class="value-label">' + t('svTotalValue') + '</div><div class="value-amount">€' + d.voorraadwaarde.totaalAlles.toFixed(2) + '</div></div></div>';
  const topCategorieen = Object.keys(d.voorraadwaarde.perCategorie)
    .sort((a, b) => d.voorraadwaarde.perCategorie[b].waarde - d.voorraadwaarde.perCategorie[a].waarde)
    .slice(0, 5);
  html += '<div class="section-title">' + t('mcTopCategories') + '</div>';
  html += '<table><tr><th>' + t('colCategory') + '</th><th>' + t('colValue') + '</th></tr>';
  topCategorieen.forEach(function (cat) {
    html += '<tr><td>' + escapeHtml(cat) + '</td><td><strong>€' + d.voorraadwaarde.perCategorie[cat].waarde.toFixed(2) + '</strong></td></tr>';
  });
  html += '</table>';
  html += '<p><a class="mc-drill-link" id="mcNaarVoorraadwaarde">' + t('mcSeeFullValue') + ' →</a></p>';
  html += '</div>';

  // -- Dimensie: Verbruik & Trends (laatste beschikbare maand) --
  html += '<div class="rp-dim-section" data-dim="verbruik" style="display:' + (rapportageDimensie === 'verbruik' ? 'block' : 'none') + ';">';
  const laatsteMaand = d.trends.maanden.length ? d.trends.maanden[d.trends.maanden.length - 1] : null;
  const laatsteAfboeking = d.trends.afboekingenTrend.length ? d.trends.afboekingenTrend[d.trends.afboekingenTrend.length - 1] : null;
  const laatsteBesparing = d.trends.besparing.length ? d.trends.besparing[d.trends.besparing.length - 1] : null;
  html += '<div class="section-title">' + tf('mcLatestMonth', { maand: laatsteMaand ? laatsteMaand.maand : '-' }) + '</div>';
  html += '<div class="value-tiles">';
  html += '<div class="value-tile"><div class="value-label">' + t('mcUsageTotal') + '</div><div class="value-amount">' + (laatsteMaand ? laatsteMaand.totaal : 0) + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('trWriteOffsTitle') + '</div><div class="value-amount">€' + (laatsteAfboeking ? laatsteAfboeking.waarde.toFixed(2) : '0.00') + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('trSavingsTitle') + '</div><div class="value-amount" style="color:#059669;">€' + (laatsteBesparing ? laatsteBesparing.totaalWaarde.toFixed(2) : '0.00') + '</div></div>';
  html += '</div>';
  // Mini-trend van de laatste 3 maanden
  // (i.p.v. enkel de laatste maand) -- hergebruikt exact dezelfde
  // trends.maanden-array, alleen de laatste 3 elementen i.p.v. 1.
  const laatste3 = d.trends.maanden.slice(-3);
  if (laatste3.length > 0) {
    html += '<table><tr><th>' + t('colMonth') + '</th><th>' + t('colTotal') + '</th></tr>';
    laatste3.forEach(function (m) {
      html += '<tr><td>' + escapeHtml(m.maand) + '</td><td><strong>' + m.totaal + '</strong></td></tr>';
    });
    html += '</table>';
  }
  html += '<p><a class="mc-drill-link" id="mcNaarTrends">' + t('mcSeeFullTrends') + ' →</a></p>';
  html += '</div>';

  // -- Dimensie: Team & Prestaties (top scans deze maand + PICC-rotatie) --
  html += '<div class="rp-dim-section" data-dim="team" style="display:' + (rapportageDimensie === 'team' ? 'block' : 'none') + ';">';
  html += '<div class="section-title">' + t('mcTeamTitle') + '</div>';
  if (d.leaderboard.scans.length === 0) {
    html += '<p class="empty">' + t('lbNoScans') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colName') + '</th><th>' + t('colCount') + '</th></tr>';
    d.leaderboard.scans.slice(0, 5).forEach(function (r) {
      html += '<tr><td>' + escapeHtml(r.naam) + '</td><td><strong>' + r.aantal + '</strong></td></tr>';
    });
    html += '</table>';
  }
  // PICC "wie is aan de beurt" erbij --
  // exact dezelfde weergave als de Home-widget (hwPiccTitle/hwAanDeBeurt/
  // hwGeenPiccProcedures), nieuw voor deze pagina.
  html += '<div class="section-title" style="margin-top:1rem;">' + t('hwPiccTitle') + '</div>';
  html += piccRotatieHtml(d.picc);
  html += '<p><a class="mc-drill-link" id="mcNaarTeam">' + t('mcSeeFullTeam') + ' →</a></p>';
  html += '</div>';

  // -- Dimensie: Acties (nieuw) -- Min/Max-adviezen, Verplaatsadvies,
  // Afboekingen laatste jaar, elk met een eigen drill-link. Zelfde
  // "wat vraagt aandacht"-insteek als het vroegere Actiecentrum
  //, maar dan als apart Rapportage-dimensie i.p.v. een
  // los scherm.
  html += '<div class="rp-dim-section" data-dim="acties" style="display:' + (rapportageDimensie === 'acties' ? 'block' : 'none') + ';">';
  html += '<p class="form-hint">' + t('rpActiesHint') + '</p>';
  const minmaxActioneel = d.minmax.filter(function (r) { return r.status !== 'onvoldoende_data'; }).length;
  html += '<div class="value-tiles">';
  html += '<div class="value-tile"><div class="value-label">' + t('minmaxAdvies') + '</div><div class="value-amount">' + minmaxActioneel + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('transferAdvice') + '</div><div class="value-amount">' + d.verplaatsadvies.length + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('rpAfboekingenVervallen') + '</div><div class="value-amount">' + d.afboekingen.vervallen.totaalAantal + '</div><div class="form-hint">€' + d.afboekingen.vervallen.totaalWaarde.toFixed(2) + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('rpAfboekingenTekort') + '</div><div class="value-amount">' + d.afboekingen.tekorten.totaalAantal + '</div><div class="form-hint">€' + d.afboekingen.tekorten.totaalWaarde.toFixed(2) + '</div></div>';
  html += '</div>';
  html += '<p>'
    + '<a class="mc-drill-link" id="mcNaarMinMax">' + t('minmaxAdvies') + ' →</a> · '
    + '<a class="mc-drill-link" id="mcNaarVerplaatsadvies">' + t('transferAdvice') + ' →</a> · '
    + '<a class="mc-drill-link" id="mcNaarAfboekingen">' + t('writeOffs') + ' →</a>'
    + '</p>';
  html += '</div>';

  // -- Dimensie: Licentie (beheerder-only, nieuw) -- zelfde aggregatie als
  // de Home-widget (renderHomeWidgets(), zie de toelichting daar).
  if (d.magLicentie) {
    html += '<div class="rp-dim-section" data-dim="licentie" style="display:' + (rapportageDimensie === 'licentie' ? 'block' : 'none') + ';">';
    const gl = Array.isArray(d.gebruikersLicentie) ? d.gebruikersLicentie : [];
    const totaal = gl.length;
    const vrij = gl.filter(function (u) { return u.licentieStatus && u.licentieStatus.vrij; }).length;
    const geldig = gl.filter(function (u) { return u.licentieStatus && u.licentieStatus.ok && !u.licentieStatus.vrij; }).length;
    const geblokkeerd = gl.filter(function (u) { return !u.licentieStatus || !u.licentieStatus.ok; }).length;
    const anomalieAantal = (gl[0] && gl[0].licentieAnomalieAantal) || 0;
    html += '<div class="value-tiles">';
    html += '<div class="value-tile"><div class="value-label">' + tf('hwGebruikersTotaal', { n: totaal }) + '</div></div>';
    html += '<div class="value-tile"><div class="value-label">' + tf('hwGebruikersLicentieGeldig', { n: vrij + geldig }) + '</div></div>';
    html += '<div class="value-tile"><div class="value-label">' + tf('hwGebruikersLicentieGeblokkeerd', { n: geblokkeerd }) + '</div></div>';
    html += '</div>';
    if (anomalieAantal > 1) {
      html += '<p class="empty" style="color:#b00020;">' + tf('guLicentieAnomalie', { aantal: anomalieAantal }) + '</p>';
    }
    html += '<p><a class="mc-drill-link" id="mcNaarGebruikers">' + t('hwNaarGebruikers') + ' →</a></p>';
    html += '</div>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.getElementById('mcNaarVoorraadwaarde').addEventListener('click', showVoorraadwaarde);
  document.getElementById('mcNaarTrends').addEventListener('click', showTrends);
  document.getElementById('mcNaarTeam').addEventListener('click', showLeaderboard);
  document.getElementById('mcNaarMinMax').addEventListener('click', showMinMaxAdvies);
  document.getElementById('mcNaarVerplaatsadvies').addEventListener('click', showVerplaatsAdvies);
  document.getElementById('mcNaarAfboekingen').addEventListener('click', function () { showAfboekingen(); });
  const naarGebruikers = document.getElementById('mcNaarGebruikers');
  if (naarGebruikers) naarGebruikers.addEventListener('click', showGebruikers);

  // Tabbladen wisselen client-side (geen her-fetch) -- alle vijf secties
  // staan al in de DOM, zie de style="display:..."-toggles hierboven.
  document.querySelectorAll('.rp-dim-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      rapportageDimensie = this.getAttribute('data-dim');
      document.querySelectorAll('.rp-dim-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
      document.querySelectorAll('.rp-dim-section').forEach(function (sec) {
        sec.style.display = sec.getAttribute('data-dim') === rapportageDimensie ? 'block' : 'none';
      });
    });
  });
}

function showProductSearch() {
  setRoute('product-search');
  currentPage = 'productsearch';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  // : de gecombineerde "Voorraad per locatie"-cel (alles
  // door elkaar in één tekstregel) was onoverzichtelijk -- deze haalt nu
  // ook getLocaties() op (dezelfde bron als de locatiebeheerpagina, zie
  // includes/locaties.php::actieveLocatieNamen()) om per actieve locatie
  // een eigen kolom te kunnen bouwen, in dezelfde vaste-slots-dan-extra-
  // volgorde die overal elders in de app al wordt aangehouden.
  StocuroRun.withSuccessHandler(function (locaties) {
    StocuroRun.withSuccessHandler(function (data) {
      renderProductSearch(data, locaties);
    }).getProductList();
  }).getLocaties();
}

// : alleen actieve locaties, in dezelfde volgorde als
// alleLocatiesSamengevoegd() (api/locaties.php) ze teruggeeft -- vaste
// slots eerst (op hun eigen volgorde), dan extra locaties (op hun eigen
// volgorde). Geen aparte globale sortering over beide groepen heen, dat
// zou van de bestaande conventie afwijken (zie ook actieveLocatieNamen()
// in includes/locaties.php).
function productZoekenActieveLocatieNamen(locaties) {
  return (locaties || []).filter(function (l) { return l.actief == 1 || l.actief === true; }).map(function (l) { return l.naam; });
}

function renderProductSearch(data, locaties) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const categorieen = Array.from(new Set(data.map(function (item) { return item.categorie; }).filter(Boolean))).sort();
  const kolomLocaties = productZoekenActieveLocatieNamen(locaties);

  let html = '<h2>' + t('searchProducts') + '</h2>';
  html += productTabsHtml('product-search');
  html += '<div class="vt-filters">';
  html += '<div class="search-box" style="display:inline-block; margin-right: 0.75rem;"><input type="text" id="searchInput" placeholder="' + escapeAttr(t('psPlaceholder')) + '"></div>';
  html += '<select id="psCategorieFilter"><option value="">' + t('psAllCategories') + '</option>';
  categorieen.forEach(function (cat) {
    html += '<option value="' + escapeAttr(cat) + '">' + escapeHtml(cat) + '</option>';
  });
  html += '</select>';
  html += '</div>';
  html += '<div id="searchResults">';
  html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategory') + '</th><th>' + t('colManufacturer') + '</th>';
  kolomLocaties.forEach(function (naam) { html += '<th>' + escapeHtml(naam) + '</th>'; });
  html += '<th>' + t('colTotal') + '</th><th>' + t('colOrder') + '</th><th>' + t('colExpiry') + '</th><th>' + t('colValue') + '</th></tr>';
  data.forEach(item => {
    // Kleine badge naast de naam als dit artikel niet
    // Actief is -- puur informatief, deze lijst zelf blijft altijd alle
    // artikelen tonen (ook niet meer in gebruik), zie getProductList() in
    // includes/artikelen.php. statusBadgeHtml() hergebruikt de bestaande
    // badge-kleuren via classifyStatus() hierboven.
    const statusBadge = item.status && item.status !== 'Actief'
      ? ' ' + statusBadgeHtml(item.status === 'Niet leverbaar' ? t('statusNietLeverbaar') : t('statusNietMeerInGebruik'))
      : '';
    // Per-locatienaam opzoeken in item.locaties (alleen locaties waar dit
    // artikel daadwerkelijk een rij voor heeft, zie voorraadStatus()) --
    // '-' voor een locatie zonder rij (bijv. een extra locatie die nog
    // nooit voor dit artikel is ingesteld), niet 0, om dat onderscheid
    // zichtbaar te houden i.p.v. te suggereren dat er bewust 0 op voorraad is.
    const perLocatie = {};
    (item.locaties || []).forEach(function (l) { perLocatie[l.locatie] = l.huidig; });
    html += '<tr data-categorie="' + escapeAttr(item.categorie || '') + '">'
      // Duimnagelfoto voor de
      // productnaam, zelfde listThumbHtml()-helper als hieronder bij
      // Bijvullen -- helpt bij het herkennen van een product in de lijst
      // zonder eerst te hoeven doorklikken naar de detailpagina.
      + '<td>' + listThumbHtml(item.foto, item.naam) + '<a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a>' + statusBadge + '</td>'
      + '<td>' + escapeHtml(item.categorie || '-') + '</td>'
      + '<td>' + escapeHtml(item.producent || '-') + '</td>';
    kolomLocaties.forEach(function (naam) {
      const aantal = perLocatie[naam];
      html += '<td>' + (aantal === undefined ? '-' : aantal) + '</td>';
    });
    html += '<td><strong>' + item.totaalStuks + '</strong></td>'
      + '<td>' + statusBadgeHtml(item.orderStatus || '-') + '</td>'
      + '<td>' + escapeHtml(item.eerstvolgendeVervaldatum || '-') + '</td>'
      + '<td>€' + item.totaalWaarde.toFixed(2) + '</td>'
      + '</tr>';
  });
  html += '</table>';
  html += '</div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  wireProductTabs();

  function toepassenProductZoekenFilters() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    const categorie = document.getElementById('psCategorieFilter').value;
    document.querySelectorAll('#searchResults tr').forEach((row, idx) => {
      if (idx === 0) return;
      const text = row.textContent.toLowerCase();
      const matchTekst = !query || text.includes(query);
      const matchCategorie = !categorie || row.getAttribute('data-categorie') === categorie;
      row.style.display = (matchTekst && matchCategorie) ? '' : 'none';
    });
  }
  document.getElementById('searchInput').addEventListener('keyup', toepassenProductZoekenFilters);
  document.getElementById('psCategorieFilter').addEventListener('change', toepassenProductZoekenFilters);
}

function showBoodschappenlijst() {
  setRoute('boodschappenlijst');
  currentPage = 'boodschappenlijst';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderBoodschappenlijst).getBoodschappenData();
}

// Toont "(= X dozen)" achter een
// afgeronde bestelhoeveelheid, als de backend een dozen-waarde meestuurt
// (rondAfOpDoos() in includes/voorraad.php -- alleen gezet voor
// producten met een doosverpakking). Puur een leesbaarheidstoevoeging,
// het stuks-getal zelf is al afgerond op een heel veelvoud van de
// doosgrootte.
function dozenSuffixHtml(dozen) {
  if (dozen === null || dozen === undefined) return '';
  return ' <span style="color:#666; font-size:12px;">(' + tf('doosSuffix', { n: dozen }) + ')</span>';
}

// Dit scherm heette vroeger "Boodschappenlijst" en toonde
// zowel bijvul- als bestel-items door elkaar. Nu een eenvoudige lijst van
// alleen wat daadwerkelijk vanuit Berging kan worden bijgevuld -- de
// backend (getBoodschappenData() in includes/voorraad.php) laat items
// zonder Berging-voorraad al helemaal weg, dus hier hoeft niet meer
// gefilterd te worden op "voldoende/onvoldoende in Berging".
function renderBoodschappenlijst(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('refillList') + '</h2>';
  html += voorraadTabsHtml('boodschappenlijst');
  html += '<p class="form-hint">' + t('slRefillHint') + '</p>';

  const locaties = Object.keys(data); // dynamisch: sleutels komen al uit de instelbare locatienamen
  let hasAnyItems = false;

  locaties.forEach(loc => {
    const items = data[loc];
    if (!items || items.length === 0) return;

    hasAnyItems = true;
    html += '<div class="section-title">' + loc + '</div>';
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('slCurrentAt') + loc + '</th><th>' + t('slNeeded') + '</th><th>' + t('slInStorage') + '</th></tr>';
    items.forEach(item => {
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td><td>' + item.huidig + '</td><td><strong>+' + item.hoeveel + '</strong></td><td>' + item.bergingHuidig + '</td></tr>';
    });
    html += '</table>';
  });

  if (!hasAnyItems) {
    html += '<p class="empty">' + t('slAllSufficient') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  wireVoorraadTabs();
}

function showBerging() {
  setRoute('berging');
  currentPage = 'berging';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderBerging).getBergingData();
}

function renderBerging(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h2>' + t('stTitle') + '</h2>';
  html += voorraadTabsHtml('berging');
  html += '<p class="form-hint">' + t('stHint') + '</p>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('stAllSufficient') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colBarcode') + '</th><th>' + t('colProduct') + '</th><th>' + t('colProducerRef') + '</th><th>' + t('colCurrent') + '</th><th>' + t('colMin') + '</th><th>' + t('colMax') + '</th><th>' + t('colNeeded') + '</th><th>' + t('colStockStatus') + '</th><th>' + t('colOrderStatus') + '</th></tr>';
    data.forEach((item, idx) => {
      const refHtml = (item.producent || item.artikelref)
        ? escapeHtml(item.producent || '-') + '<br><span style="color:#666;font-size:12px;">' + escapeHtml(item.artikelref || '-') + '</span>'
        : '-';
      // Barcode van de interne
      // logistiekcode i.p.v. de GS1-EAN (leverancierscode) -- bedoeld voor
      // een eigen schaplabel. code128Svg() geeft null terug als het veld
      // leeg is of tekens bevat die niet in Subset B passen; in beide
      // gevallen tonen we een korte melding i.p.v. een lege cel.
      const barcodeSvg = item.logistiekCode ? code128Svg(item.logistiekCode, { unit: 2, height: 50 }) : null;
      const barcodeHtml = barcodeSvg
        ? barcodeSvg + '<div style="font-size: 11px; margin-top: 4px; color: #666; font-family: monospace;">' + escapeHtml(item.logistiekCode) + '</div>'
        : '<div style="font-size: 11px; color: #999;">' + t('stNoLogistiekCode') + '</div>';
      html += '<tr>';
      html += '<td style="text-align: center; padding: 10px;">' + barcodeHtml + '</td>';
      html += '<td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td>';
      html += '<td>' + refHtml + '</td>';
      html += '<td style="text-align: center;">' + item.huidig + '</td>';
      html += '<td style="text-align: center;">' + item.min + '</td>';
      html += '<td style="text-align: center;">' + item.max + '</td>';
      html += '<td style="text-align: center;"><strong>' + item.nodig + '</strong>' + dozenSuffixHtml(item.dozenNodig) + '</td>';
      html += '<td>' + statusBadgeHtml(item.voorraadStatus) + '</td>';
      html += '<td>' + statusBadgeHtml(item.orderStatus) + '</td>';
      html += '</tr>';
    });
    html += '</table>';

    // "download PDF & mail Logistiek"
    // staat BEWUST los van "Markeer als besteld" -- downloaden/mailen
    // heeft geen bijwerkingen, alleen de tweede knop zet artikelen
    // daadwerkelijk als besteld weg (upsertOrder(), zie
    // includes/voorraad.php::markeerBergingAlsBesteld()).
    html += '<div class="edit-actions">';
    html += '<button class="action-btn" id="bergingDownloadMailBtn">' + t('bpDownloadMailKnop') + '</button> ';
    html += '<button class="edit-save" id="bergingMarkeerBtn">' + t('bpMarkeerKnop') + '</button>';
    html += '</div>';
    html += '<p class="form-hint">' + t('bpDownloadMailHint') + '</p>';
    html += '<p class="form-hint">' + t('bpMarkeerHint') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function() {
      showProductDetail(this.getAttribute('data-ean'));
    });
  });

  const bergingDownloadMailBtn = document.getElementById('bergingDownloadMailBtn');
  if (bergingDownloadMailBtn) bergingDownloadMailBtn.addEventListener('click', function () { downloadEnMailBergingBestellijst(data); });
  const bergingMarkeerBtn = document.getElementById('bergingMarkeerBtn');
  if (bergingMarkeerBtn) bergingMarkeerBtn.addEventListener('click', markeerBergingBesteld);
  wireVoorraadTabs();
}

// -----------------------------------------------------------------
// Minimale, dependency-vrije PDF-generator: "Print/Exporteren" op de
// Berging-bestellijst is vervangen door "download PDF & mail
// Logistiek", omdat een mailto:-link NOOIT automatisch een bijlage kan
// meesturen (browserbeveiliging) -- daarom het bestaande client-side
// patroon (PDF downloaden + mailto met lege ontvanger openen) i.p.v. server-side
// e-mailverzending. Zelfde filosofie als code128Svg() elders in deze
// app: geen externe library/CDN, zelf een geldige platte-tekst PDF-
// bytestream opbouwen met de ingebouwde Helvetica-basisfont (een van de
// 14 standaardfonts die elke PDF-viewer kent, dus geen font-embedding
// nodig).
// -----------------------------------------------------------------
function pdfSafeText(s) {
  return String(s).split('').map(function (ch) {
    return ch.charCodeAt(0) <= 255 ? ch : '?';
  }).join('');
}

function pdfEscape(s) {
  return pdfSafeText(s).replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
}

/**
 * Tekent een Code128 (subset B) staafcode als PDF-vectorrechthoeken
 * (content-stream 're'/'f'-operators), i.p.v. de SVG-versie
 * (code128Svg() hieronder) die alleen voor het scherm is. Zelfde
 * breedtepatronentabel/encoding (code128BEncode()) als de SVG-variant --
 * dus dezelfde barcode, alleen als PDF-graphics i.p.v. HTML.
 *
 * maxWidth (optioneel): een HTML-tabelcel (de schermweergave) verbreedt
 * vanzelf mee met een lange logistiekcode, maar deze PDF plaatst alles op
 * VASTE x-posities -- zonder grens zou een ongebruikelijk lange code de
 * ernaast geplaatste producttekst kunnen overlappen. Past de staafcode op
 * de gewenste `unit`-breedte niet binnen maxWidth, dan wordt de module-
 * breedte naar beneden bijgeschaald (nooit omhoog) tot hij wel past.
 * @return {ops:string, width:number}|null null als de tekst niet in
 *   Code128 subset B past (zie code128BEncode()).
 */
function code128PdfBars(tekst, x, yBottom, unit, height, maxWidth) {
  const patterns = code128BEncode(tekst);
  if (!patterns) return null;

  let totalUnits = 0;
  patterns.forEach(function (p) { for (const d of p) totalUnits += parseInt(d, 10); });

  if (maxWidth) {
    const maxUnit = maxWidth / (totalUnits + 20);
    if (maxUnit < unit) unit = Math.max(maxUnit, 0.3);
  }
  const quiet = unit * 10;
  const width = quiet * 2 + totalUnits * unit;

  let curX = x + quiet;
  let bar = true; // Code128 begint altijd met een staaf.
  let ops = '';
  patterns.forEach(function (pattern) {
    for (const d of pattern) {
      const w = parseInt(d, 10) * unit;
      if (bar) {
        ops += round2(curX) + ' ' + round2(yBottom) + ' ' + round2(w) + ' ' + round2(height) + ' re\n';
      }
      curX += w;
      bar = !bar;
    }
  });
  ops += 'f\n';
  return { ops: ops, width: width };
}

function round2(n) {
  return Math.round(n * 100) / 100;
}

/**
 * Bouwt een minimale, geldige (mogelijk multi-pagina) PDF uit een titel,
 * subtitel en een lijst van rijen, en start meteen de download (Blob +
 * onzichtbare <a download>). Alleen tekstplaatsing/staafcodes op vaste
 * x-posities -- er bestaat geen "echte tabel" op dit basisniveau, maar
 * dat is voor een simpele bestellijst ruim voldoende.
 *
 * Elke rij: { naam, aantalTekst, code } -- code is optioneel (logistiek-
 * code van dat artikel); als die aanwezig EN encodeerbaar is (Code128
 * subset B, zie code128BEncode()) wordt er een echte scanbare staafcode
 * Bij de rij getekend (Logistiek moet dit direct
 * vanaf het uitgeprinte formulier kunnen overnemen, niet alleen vanaf het
 * scherm). Zonder code, of een code die niet encodeerbaar is, komt er in
 * plaats daarvan dezelfde "geen logistiekcode ingesteld"-tekst als op het
 * scherm (t('stNoLogistiekCode')) -- nooit een lege, verwarrende ruimte.
 */
function downloadSimplePdf(filename, title, subtitle, rows) {
  const pageWidth = 612, pageHeight = 792, margin = 50;
  const rowHeight = 56;
  const barcodeX = margin, textX = 280;
  const barcodeUnit = 1.1, barcodeHeight = 26;
  const usableTop = pageHeight - margin;
  const linesPerPage = Math.max(1, Math.floor((usableTop - margin - 60) / rowHeight));

  const chunks = [];
  for (let i = 0; i < rows.length; i += linesPerPage) chunks.push(rows.slice(i, i + linesPerPage));
  if (chunks.length === 0) chunks.push([]);
  const pageCount = chunks.length;

  let pdf = '%PDF-1.4\n';
  const offsets = [];
  function addObj(body) {
    offsets.push(pdf.length);
    pdf += body;
  }

  const pagesObjNum = 2;
  const firstPageObjNum = 3;
  const fontObjNum = 3 + pageCount * 2;

  addObj('1 0 obj\n<< /Type /Catalog /Pages ' + pagesObjNum + ' 0 R >>\nendobj\n');

  const kidRefs = [];
  for (let p = 0; p < pageCount; p++) kidRefs.push((firstPageObjNum + p * 2) + ' 0 R');
  addObj('2 0 obj\n<< /Type /Pages /Kids [' + kidRefs.join(' ') + '] /Count ' + pageCount + ' >>\nendobj\n');

  for (let p = 0; p < pageCount; p++) {
    const pageObjNum = firstPageObjNum + p * 2;
    const contentObjNum = pageObjNum + 1;

    let content = 'BT\n/F1 14 Tf\n1 0 0 1 ' + margin + ' ' + (usableTop - 10) + ' Tm\n(' + pdfEscape(title) + ') Tj\nET\n';
    const subtitelTekst = subtitle + (pageCount > 1 ? ' -- ' + (p + 1) + '/' + pageCount : '');
    content += 'BT\n/F1 10 Tf\n1 0 0 1 ' + margin + ' ' + (usableTop - 30) + ' Tm\n(' + pdfEscape(subtitelTekst) + ') Tj\nET\n';

    let y = usableTop - 60;
    chunks[p].forEach(function (row) {
      // Staafcode-tekengebied. 're'/'f' (padvulling) zijn geen tekst-
      // operators en horen dus NIET tussen BT/ET -- vandaar losse BT/ET-
      // blokjes per tekstregel hieronder, met de staafcode-ops ertussenin.
      const bars = row.code ? code128PdfBars(row.code, barcodeX, y - barcodeHeight - 8, barcodeUnit, barcodeHeight, textX - barcodeX - 20) : null;
      if (bars) {
        content += '0 g\n' + bars.ops;
        content += 'BT\n/F1 7 Tf\n1 0 0 1 ' + barcodeX + ' ' + (y - barcodeHeight - 20) + ' Tm\n(' + pdfEscape(row.code) + ') Tj\nET\n';
      } else {
        content += 'BT\n/F1 8 Tf\n1 0 0 1 ' + barcodeX + ' ' + (y - 20) + ' Tm\n(' + pdfEscape(t('stNoLogistiekCode')) + ') Tj\nET\n';
      }
      content += 'BT\n/F1 10 Tf\n1 0 0 1 ' + textX + ' ' + (y - 14) + ' Tm\n(' + pdfEscape(row.naam) + ') Tj\nET\n';
      content += 'BT\n/F1 9 Tf\n1 0 0 1 ' + textX + ' ' + (y - 30) + ' Tm\n(' + pdfEscape(row.aantalTekst) + ') Tj\nET\n';
      y -= rowHeight;
    });

    addObj(pageObjNum + ' 0 obj\n<< /Type /Page /Parent ' + pagesObjNum + ' 0 R /MediaBox [0 0 ' + pageWidth + ' ' + pageHeight + '] /Resources << /Font << /F1 ' + fontObjNum + ' 0 R >> >> /Contents ' + contentObjNum + ' 0 R >>\nendobj\n');
    addObj(contentObjNum + ' 0 obj\n<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n');
  }

  // WinAnsiEncoding expliciet zetten -- zonder dit valt Helvetica terug op
  // zijn StandardEncoding, waarin bytes 0xE9/0xEB (é/ë, gebruikelijk in
  // Nederlandse productnamen) op de VERKEERDE glyphs uitkomen. WinAnsi
  // komt voor het Latin-1-bereik dat pdfSafeText() doorlaat overeen met
  // wat er letterlijk werd getypt.
  addObj(fontObjNum + ' 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n');

  const xrefStart = pdf.length;
  const totalObjs = offsets.length;
  pdf += 'xref\n0 ' + (totalObjs + 1) + '\n0000000000 65535 f \n';
  offsets.forEach(function (off) {
    pdf += String(off).padStart(10, '0') + ' 00000 n \n';
  });
  pdf += 'trailer\n<< /Size ' + (totalObjs + 1) + ' /Root 1 0 R >>\nstartxref\n' + xrefStart + '\n%%EOF';

  const bytes = Uint8Array.from(pdf, function (ch) { return ch.charCodeAt(0); });
  const blob = new Blob([bytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}

/**
 * Rijen voor de Berging-bestellijst-PDF: naam (+ interne ref), aantal-
 * Tekst en de logistiekcode. : Logistiek moet de
 * bestellijst direct kunnen scannen, zowel vanaf het scherm als vanaf het
 * uitgeprinte/gedownloade formulier -- dus nu WEL een echte, scanbare
 * staafcode per rij in de PDF (downloadSimplePdf()/code128PdfBars()
 * hierboven), dezelfde interne logistiekcode als de barcode die al op
 * het scherm stond (renderBerging() hieronder). Ontbreekt die code voor
 * een artikel, dan toont de PDF dezelfde "geen logistiekcode
 * ingesteld"-tekst als het scherm i.p.v. een barcode.
 */
function bergingBestellijstPdfRijen(data) {
  return data.map(function (item) {
    const aantalTekst = item.dozenNodig
      ? item.dozenNodig + ' ' + t('bpDozen') + ' (' + item.nodig + ' ' + t('bpStuks') + ')'
      : item.nodig + ' ' + t('bpStuks');
    const naamTekst = item.artikelref ? item.naam + ' (' + item.artikelref + ')' : item.naam;
    return { naam: naamTekst, aantalTekst: aantalTekst, code: item.logistiekCode || null };
  });
}

function bergingBestellijstBestandsnaam() {
  return 'bestellijst-berging-' + new Date().toISOString().slice(0, 10) + '.pdf';
}

function bergingMailtoUrl(data, bestandsnaam) {
  const onderwerp = tf('bpMailSubject', { datum: new Date().toLocaleDateString() });
  const regels = [
    t('bpMailIntro'),
    tf('bpMailAttachment', { bestand: bestandsnaam }),
    '',
    t('bpMailListHeader'),
  ];
  data.forEach(function (item) {
    const aantalTekst = item.dozenNodig
      ? item.dozenNodig + ' ' + t('bpDozen') + ' (' + item.nodig + ' ' + t('bpStuks') + ')'
      : item.nodig + ' ' + t('bpStuks');
    regels.push('- ' + item.naam + ': ' + aantalTekst);
  });
  const inhoud = regels.join('\n');
  // Bewust géén 'aan'-adres (blanco To:) -- er is geen vaste
  // Logistiek-e-mail opgeslagen in Stocuro, dus de gebruiker vult die
  // zelf in, net als bij de bestaande mailto-knoppen elders in de app.
  return 'mailto:?subject=' + encodeURIComponent(onderwerp) + '&body=' + encodeURIComponent(inhoud);
}

function downloadEnMailBergingBestellijst(data) {
  const bestandsnaam = bergingBestellijstBestandsnaam();
  downloadSimplePdf(bestandsnaam, t('bpTitel'), new Date().toLocaleDateString(), bergingBestellijstPdfRijen(data));
  // Kleine vertraging zodat de downloadprompt/actie niet wordt
  // weggeconcurreerd door de daaropvolgende mailto-navigatie.
  setTimeout(function () {
    window.location.href = bergingMailtoUrl(data, bestandsnaam);
  }, 300);
}

// "Markeer als besteld": losstaande actie van het printen
// hierboven -- zet elk artikel op de huidige lijst als order weg
// (upsertOrder(), zelfde mechanisme als een handmatige bestelling), zodat
// de normale Ontvangst-scan het straks vanzelf oppikt (FIFO). Bevestiging
// vooraf, want dit heeft een echte bijwerking (in tegenstelling tot
// printen).
function markeerBergingBesteld() {
  if (!confirm(t('bpMarkeerConfirm'))) return;
  apiPost('api/berging.php', { actie: 'markeer_besteld' }, function (result) {
    alert(tf('bpMarkeerGelukt', { aantal: result.aantalGemarkeerd }));
    showBerging();
  }, function (err) {
    alert(err.error || t('somethingWrong'));
  });
}

// Zelfde tabbalk-patroon als
// VOORRAAD_TABS hierboven, nu voor de drie vervaldatum/lot-schermen
// (Vervaldatums Detail, Lot-tracking, Vervaldatum Focus) die alle drie
// dezelfde onderliggende vraag beantwoorden ("welke loten lopen af/zijn
// verlopen") vanuit een net iets ander perspectief.
const VERVAL_TABS = [
  ['vervaldatums', 'expiryDatesDetail', showVervaldatums],
  ['lot-tracking', 'lotTracking', showLotTracking],
  ['vervalfocus', 'expiryFocus', showVervalFocus],
];

function vervalTabsHtml(actief) {
  const zichtbaar = VERVAL_TABS.filter(function (tab) { return heeftScherm(tab[0]); });
  if (zichtbaar.length <= 1) return '';
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  zichtbaar.forEach(function (tab) {
    html += '<button class="hub-tab-btn' + (tab[0] === actief ? ' active' : '') + '" data-verval-tab="' + tab[0] + '">' + t(tab[1]) + '</button>';
  });
  html += '</div>';
  return html;
}

function wireVervalTabs() {
  document.querySelectorAll('[data-verval-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-verval-tab');
      const target = VERVAL_TABS.find(function (tab) { return tab[0] === id; });
      if (target) target[2]();
    });
  });
}

function showVervalHub() {
  const eerste = VERVAL_TABS.find(function (tab) { return heeftScherm(tab[0]); });
  if (eerste) eerste[2]();
}

function showVervaldatums() {
  setRoute('vervaldatums');
  currentPage = 'vervaldatums';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVervaldatums).getVervaldatumDetail();
}

function renderVervaldatums(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }
  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h2>' + t('edTitle') + '</h2>';
  html += vervalTabsHtml('vervaldatums');
  html += '<p class="form-hint">' + t('edHint') + '</p>';
  html += '<div style="background: #f0f0f0; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 14px;">';
  html += '<strong>' + t('edLegend') + '</strong> ' + statusBadgeHtml('VERLOPEN') + ' ' + t('edExpired') + ' &nbsp; ' + statusBadgeHtml('SPOEDGEBRUIK') + ' &lt; 3 mo &nbsp; ' + statusBadgeHtml('MONITOREN') + ' 3-12 mo &nbsp; ' + statusBadgeHtml('GELDIG') + ' 1-2 yr &nbsp; ' + statusBadgeHtml('LANGDURIG') + ' &gt; 2 yr';
  html += '</div>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('edNone') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th><th>' + t('colUnits') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colMonths') + '</th><th>' + t('colStatus') + '</th></tr>';
    data.forEach(item => {
      html += '<tr class="' + statusRowClass(item.status) + '"><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.product) + '</a></td><td>' + item.lot + '</td><td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td><td>' + item.vervaldatum + '</td><td><strong>' + item.monthsLeft + '</strong></td><td style="text-align: center;">' + statusBadgeHtml(item.status) + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  wireVervalTabs();
}

function showLotTracking() {
  setRoute('lot-tracking');
  currentPage = 'lotTracking';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderLotTracking).getLotTracking();
}

function renderLotTracking(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h2>' + t('ltTitle') + '</h2>';
  html += vervalTabsHtml('lot-tracking');
  html += '<p class="form-hint">' + t('ltHint') + '</p>';
  html += '<div style="background: #f0f0f0; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 14px;">';
  html += '<strong>' + t('edLegend') + '</strong> ' + statusBadgeHtml('VERLOPEN') + ' / ' + statusBadgeHtml('SPOEDGEBRUIK') + ' &nbsp; ' + statusBadgeHtml('MONITOREN') + ' &nbsp; ' + statusBadgeHtml('GELDIG') + ' &nbsp; ' + statusBadgeHtml('LANGDURIG');
  html += '</div>';

  let hasData = false;
  for (const loc in data) {
    if (data[loc].length === 0) continue;
    hasData = true;

    html += '<div class="section-title">' + loc + '</div>';
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colMonths') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colStatus') + '</th></tr>';

    data[loc].forEach(item => {
      html += '<tr class="' + statusRowClass(item.status) + '"><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.product) + '</a></td><td>' + item.lot + '</td><td>' + item.vervaldatum + '</td><td><strong>' + item.monthsLeft + '</strong></td><td><strong>' + item.huidig + '</strong></td><td style="text-align: center;">' + statusBadgeHtml(item.status) + '</td></tr>';
    });

    html += '</table>';
  }

  if (!hasData) {
    html += '<p class="empty">' + t('ltNone') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  wireVervalTabs();
}

// Verbruik per Procedure en
// Verbruikstrends samengevoegd tot "Verbruik & Trends" met tabbalk.
// Afboekingen (Afschrijvingen) staat er als derde tab bij -- was een
// losse menuknop in groupOrdering, maar hoort inhoudelijk beter hier: het
// is verbruik/afschrijving-historie, geen bestel-actie. Verbruikstrends
// linkt hier via trendDrillLink() ook al naar door (per-maand afschrijf-
// waarde), dus dit is de plek waar iemand al zit als ze doorklikken.
const USAGE_TABS = [
  ['verbruik', 'usage', showVerbruik],
  ['trends', 'usageTrends', showTrends],
  ['afboekingen', 'writeOffs', showAfboekingen],
];

function usageTabsHtml(actief) {
  const zichtbaar = USAGE_TABS.filter(function (tab) { return heeftScherm(tab[0]); });
  if (zichtbaar.length <= 1) return '';
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  zichtbaar.forEach(function (tab) {
    html += '<button class="hub-tab-btn' + (tab[0] === actief ? ' active' : '') + '" data-usage-tab="' + tab[0] + '">' + t(tab[1]) + '</button>';
  });
  html += '</div>';
  return html;
}

function wireUsageTabs() {
  document.querySelectorAll('[data-usage-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-usage-tab');
      const target = USAGE_TABS.find(function (tab) { return tab[0] === id; });
      if (target) target[2]();
    });
  });
}

function showUsageHub() {
  const eerste = USAGE_TABS.find(function (tab) { return heeftScherm(tab[0]); });
  if (eerste) eerste[2]();
}

function showVerbruik() {
  setRoute('verbruik');
  currentPage = 'verbruik';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVerbruik).getVerbruiksgebruik();
}

function renderVerbruik(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h2>' + t('usTitle') + '</h2>';
  html += usageTabsHtml('verbruik');
  html += '<p class="form-hint">' + t('usHint') + '</p>';

  if (Object.keys(data).length === 0) {
    html += '<p class="empty">' + t('usNone') + '</p>';
  } else {
    // Structuur (zie includes/dashboards.php::getVerbruiksgebruik()): één
    // niveau, per procedure_type (.section-title) -> per-productgemiddelde
    // PER PROCEDURE, over alle specialisten heen. is
    // het specialist-niveau dat hier eerder stond verwijderd (kan op
    // termijn als vervelend voor artsen aanvoelen); "wie gebruikt wat"
    // per specialist staat nu alleen nog op de productdetailpagina.
    for (const procKey in data) {
      const procEntry = data[procKey];
      const gemKosten = parseFloat(procEntry.gemiddeldeKosten || 0).toFixed(2);
      html += '<div class="section-title">' + escapeHtml(procEntry.procedure)
        + ' <span class="us-specialist-stats">(' + tf('usProcedureCount', { n: procEntry.aantalProcedures })
        + ' · ' + tf('usAvgItems', { n: procEntry.gemiddeldArtikelen })
        + ' · ' + tf('usAvgCost', { bedrag: gemKosten }) + ')</span></div>';

      html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colUsedIn') + '</th><th>' + t('colAvgPerProcedure') + '</th><th>' + t('colTotal') + '</th><th>' + t('colCostPrice') + '</th></tr>';

      Object.values(procEntry.producten).forEach(p => {
        const kosten = parseFloat(p.kosten || 0).toFixed(2);
        html += '<tr><td><a class="product-link" data-ean="' + p.ean + '">' + escapeHtml(p.product) + '</a></td>'
          + '<td>' + p.aantalKeerGebruikt + ' / ' + procEntry.aantalProcedures + '</td>'
          + '<td><strong>' + p.gemiddeldPerProcedure + '</strong></td>'
          + '<td>' + p.totaal + '</td><td>€' + kosten + '</td></tr>';
      });

      html += '</table>';
    }
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  wireUsageTabs();
}

// Te Bestellen, Open Orders en (voor
// specialist/laborant-accounts) Mijn Bestellingen delen nu één ingang
// "Bestellingen" met tabbalk. Mijn Bestellingen toont alleen de eigen
// eenmalige bestellingen van de ingelogde specialist/laborant -- dus
// bewust NIET onvoorwaardelijk in de tabset (een beheerder-account heeft
// geen eigen specialist-koppeling, die tab zou voor hen altijd leeg zijn).
// ShowBestellers()/showOpenOrders()
// (net als showMijnBestellingen() hierboven) waren pre-consolidatie
// pagina's die ROUTE_DISPATCH allang niet meer rechtstreeks aanwees --
// elk had een eigen terugknop en toonde een subset van precies de drie
// bronnen (getBestellersData/getOpenOrders/getOpenEenmaligeBestellingen)
// die showBestellingen() ook al ophaalt en samenvoegt. Nu allebei een
// dunne alias naar die ene hub, met het bijpassende filter alvast aan --
// geen aparte databron, weergave of terugknop meer om apart te houden.
function showBestellers() {
  showBestellingen('teBestellen');
}

function showOpenOrders() {
  showBestellingen('alles');
}

function showVoorraadwaarde() {
  setRoute('voorraadwaarde');
  currentPage = 'voorraadwaarde';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVoorraadwaarde).getVoorraadWaarde();
}

function renderVoorraadwaarde(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('stockValue') + '</h2>';
  html += voorraadTabsHtml('voorraadwaarde');

  html += '<div class="value-tiles">';
  html += '<div class="value-tile"><div class="value-label">' + t('svTotalValue') + '</div><div class="value-amount">€' + data.totaalAlles.toFixed(2) + '</div></div>';
  html += '</div>';

  html += '<div class="section-title">' + t('svByLocation') + '</div>';
  html += '<table><tr><th>' + t('colLocation') + '</th><th>' + t('colNumProducts') + '</th><th>' + t('colTotalUnits') + '</th><th>' + t('colValue') + '</th></tr>';
  Object.keys(data.perLocatie).sort((a, b) => data.perLocatie[b].waarde - data.perLocatie[a].waarde).forEach(loc => {
    const d = data.perLocatie[loc];
    html += '<tr><td>' + escapeHtml(loc) + '</td><td>' + d.aantalArtikelen + '</td><td>' + d.aantalStuks + '</td><td><strong>€' + d.waarde.toFixed(2) + '</strong></td></tr>';
  });
  html += '</table>';

  html += '<div class="section-title">' + t('svByCategory') + '</div>';
  html += '<table><tr><th>' + t('colCategory') + '</th><th>' + t('colNumProducts') + '</th><th>' + t('colTotalUnits') + '</th><th>' + t('colValue') + '</th></tr>';
  Object.keys(data.perCategorie).sort((a, b) => data.perCategorie[b].waarde - data.perCategorie[a].waarde).forEach(cat => {
    const d = data.perCategorie[cat];
    // De categorie-links hier bleken
    // niet meer kloppend te zijn ("via Management-cockpit -> Bekijk
    // volledige voorraadwaarde -> vanuit daar zijn de linkjes van de
    // categorieën ... niet meer kloppend / een lege pagina") -- verwijderd.
    // Categorienaam is nu platte tekst; de klikbare cat-link/
    // showCategorieDetail()-route blijft ongewijzigd bestaan en werkt nog
    // gewoon op het Categorieën-dashboard (renderCategorieen()), alleen
    // hier op Voorraadwaarde niet meer aangeboden.
    html += '<tr><td>' + escapeHtml(cat) + '</td><td>' + d.aantalArtikelen + '</td><td>' + d.aantalStuks + '</td><td><strong>€' + d.waarde.toFixed(2) + '</strong></td></tr>';
  });
  html += '</table>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  wireVoorraadTabs();
}

// =================================================================
// ACTIECENTRUM - alles wat aandacht vraagt op een pagina
// =================================================================
function showActiecentrum() {
  setRoute('actiecentrum');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderActiecentrum).getActiecentrumData();
}

// Het Actiecentrum toonde voorheen vijf
// eigen tabellen (verlopen/spoedgebruik/leeg/laag/orders) die exact
// dezelfde data nogmaals opbouwden als Vervaldatum Focus, Min/Max Advies
// en Open Orders al deden -- twee plekken die bijgehouden moesten worden
// voor dezelfde vraag. Nu een "cockpit": alleen de telling per categorie
// als klikbare tegel, die doorlinkt naar het scherm waar de bijbehorende
// lijst en acties al staan. getActiecentrumData() blijft ongewijzigd
// (levert nog steeds de volledige lijsten), hier wordt alleen nog de
// lengte gebruikt -- dat is met opzet zo gelaten (geen backend-wijziging
// nodig, en de lijsten zijn zo klein dat dit niet zwaar is).
const ACTIECENTRUM_TEGELS = [
  ['verlopen', 'acVerlopen', '#b91c1c', showVervalFocus],
  ['spoed', 'acUrgent', '#b91c1c', showVervalFocus],
  ['leeg', 'acEmpty', '#b91c1c', showMinMaxAdvies],
  ['laag', 'acLow', '#a16207', showMinMaxAdvies],
  ['orders', 'acOpenOrders', '#667eea', showOpenOrders],
];

function renderActiecentrum(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('actionCenter') + '</h2>';
  html += '<p class="form-hint">' + t('acCockpitHint') + '</p>';

  html += '<div class="value-tiles">';
  ACTIECENTRUM_TEGELS.forEach(function (tegel) {
    const aantal = data[tegel[0]].length;
    html += '<div class="value-tile value-tile-clickable" data-ac-tegel="' + tegel[0] + '">'
      + '<div class="value-label">' + t(tegel[1]) + '</div>'
      + '<div class="value-amount" style="color:' + tegel[2] + ';">' + aantal + '</div>'
      + '</div>';
  });
  html += '</div>';

  const totaal = ACTIECENTRUM_TEGELS.reduce(function (som, tegel) { return som + data[tegel[0]].length; }, 0);
  if (totaal === 0) {
    html += '<p class="empty">' + t('acNothing') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('[data-ac-tegel]').forEach(function (el) {
    const id = el.getAttribute('data-ac-tegel');
    el.addEventListener('click', function () {
      const tegel = ACTIECENTRUM_TEGELS.find(function (tg) { return tg[0] === id; });
      if (tegel) tegel[3]();
    });
  });
}

// =================================================================
// VERVALDATUM FOCUS - alleen wat spannend wordt, met euro-impact
// =================================================================
let vervalFocusData = null;
let vervalFocusFilter = 'alles';

function showVervalFocus() {
  setRoute('vervalfocus');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVervalFocus).getVervaldatumFocus();
}

function renderVervalFocus(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }
  vervalFocusData = data;
  vervalFocusFilter = 'alles';

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('expiryFocus') + '</h2>';
  html += vervalTabsHtml('vervalfocus');
  html += '<p class="form-hint">' + t('vfHint') + '</p>';

  html += '<div class="value-tiles">';
  html += '<div class="value-tile"><div class="value-label">' + t('vfExpired') + '</div><div class="value-amount" style="color:#b91c1c;">€' + data.tiles.verlopen.toFixed(2) + '</div><div class="value-sub">' + data.tiles.aantalVerlopen + ' ' + t('vfLots') + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('vfExpiresLt3') + '</div><div class="value-amount" style="color:#b91c1c;">€' + data.tiles.binnen3.toFixed(2) + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('vfExpiresLt6') + '</div><div class="value-amount" style="color:#a16207;">€' + data.tiles.binnen6.toFixed(2) + '</div></div>';
  html += '<div class="value-tile"><div class="value-label">' + t('vfExpiresLt12') + '</div><div class="value-amount">€' + data.tiles.binnen12.toFixed(2) + '</div></div>';
  html += '</div>';

  html += '<div>';
  html += '<button class="filter-btn" data-f="alles">' + t('vfFilterAll') + '</button>';
  html += '<button class="filter-btn" data-f="verlopen">' + t('vfFilterExpired') + '</button>';
  html += '<button class="filter-btn" data-f="3">' + t('vfFilterLt3') + '</button>';
  html += '<button class="filter-btn" data-f="6">' + t('vfFilterLt6') + '</button>';
  html += '</div>';
  html += '<div id="vfTable"></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.filter-btn').forEach(el => {
    el.addEventListener('click', function() {
      vervalFocusFilter = this.getAttribute('data-f');
      renderVervalFocusTable();
    });
  });
  wireVervalTabs();
  renderVervalFocusTable();
}

function renderVervalFocusTable() {
  document.querySelectorAll('.filter-btn').forEach(el => {
    el.className = 'filter-btn' + (el.getAttribute('data-f') === vervalFocusFilter ? ' active' : '');
  });

  const items = vervalFocusData.items.filter(item => {
    if (vervalFocusFilter === 'verlopen') return item.daysLeft < 0;
    if (vervalFocusFilter === '3') return item.daysLeft >= 0 && item.maanden < 3;
    if (vervalFocusFilter === '6') return item.daysLeft >= 0 && item.maanden < 6;
    return true;
  });

  let html = '';
  if (items.length === 0) {
    html = '<p class="empty">' + t('vfNoneSelected') + '</p>';
  } else {
    let totaalWaarde = 0;
    items.forEach(item => { totaalWaarde += item.waarde; });
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th><th>' + t('colUnits') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colMonths') + '</th><th>' + t('colValue') + '</th><th>' + t('colStatus') + '</th></tr>';
    items.forEach(item => {
      html += '<tr class="' + statusRowClass(item.status) + '"><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.product) + '</a></td><td>' + item.lot + '</td><td>' + escapeHtml(item.locatie) + '</td><td>' + item.huidig + '</td><td>' + item.vervaldatum + '</td><td><strong>' + item.maanden + '</strong></td><td>€' + item.waarde.toFixed(2) + '</td><td>' + statusBadgeHtml(item.status) + '</td></tr>';
    });
    html += '<tr><td colspan="6"><strong>' + tf('vfTotal', { n: items.length }) + '</strong></td><td><strong>€' + totaalWaarde.toFixed(2) + '</strong></td><td></td></tr>';
    html += '</table>';
  }

  document.getElementById('vfTable').innerHTML = html;
  document.querySelectorAll('#vfTable .product-link').forEach(el => {
    el.addEventListener('click', function() {
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
}

// =================================================================
// CATEGORIEEN - samenvatting per categorie met doorklik
// =================================================================
let catOverzichtData = null;

function showCategorien() {
  setRoute('categorien');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderCategorien).getCategorieOverzicht();
}

function renderCategorien(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }
  catOverzichtData = data;

  let html = '<h2>' + t('categories') + '</h2>';
  html += productTabsHtml('categorien');
  html += '<table><tr><th>' + t('colCategory') + '</th><th>' + t('colProducts') + '</th><th>' + t('colUnits') + '</th><th>' + t('colValue') + '</th><th>' + t('colBelowMin') + '</th><th>' + t('colLotsUnder6mo') + '</th></tr>';

  let tArt = 0, tStuks = 0, tWaarde = 0, tOnder = 0, tLoten = 0;
  Object.keys(data.summary).sort((a, b) => data.summary[b].waarde - data.summary[a].waarde).forEach(cat => {
    const d = data.summary[cat];
    tArt += d.aantalArtikelen; tStuks += d.stuks; tWaarde += d.waarde; tOnder += d.onderMin; tLoten += d.loten6;
    const onderHtml = d.onderMin > 0 ? statusBadgeHtml('LAAG') + ' ' + d.onderMin : '0';
    // Dit getal moet klikbaar zijn naar
    // precies de producten met een lot < 6 maanden -- alleen als er
    // daadwerkelijk iets is om naar door te klikken.
    const lotenHtml = d.loten6 > 0
      ? '<a class="cat-loten6-link" data-cat="' + escapeHtml(cat) + '">' + statusBadgeHtml('MONITOREN') + ' ' + d.loten6 + '</a>'
      : '0';
    html += '<tr><td><a class="cat-link" data-cat="' + escapeHtml(cat) + '"><strong>' + escapeHtml(cat) + '</strong></a></td><td>' + d.aantalArtikelen + '</td><td>' + d.stuks + '</td><td>€' + d.waarde.toFixed(2) + '</td><td>' + onderHtml + '</td><td>' + lotenHtml + '</td></tr>';
  });
  html += '<tr><td><strong>' + t('cgTotal') + '</strong></td><td><strong>' + tArt + '</strong></td><td><strong>' + tStuks + '</strong></td><td><strong>€' + tWaarde.toFixed(2) + '</strong></td><td><strong>' + tOnder + '</strong></td><td><strong>' + tLoten + '</strong></td></tr>';
  html += '</table>';
  html += '<p style="color:#999; font-size:13px;">' + t('cgClickHint') + '</p>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.cat-link').forEach(el => {
    el.addEventListener('click', function() {
      showCategorieDetail(this.getAttribute('data-cat'));
    });
  });
  // Aparte klik-afhandeling voor de "Loten < 6mnd"-cel --
  // zelfde detailpagina, maar voorgefilterd op alleen die producten.
  document.querySelectorAll('.cat-loten6-link').forEach(el => {
    el.addEventListener('click', function() {
      showCategorieDetail(this.getAttribute('data-cat'), true);
    });
  });
  wireProductTabs();
}

function showCategorieDetail(cat, filterLoten6) {
  setRoute('categorie-detail', cat);
  let artikelen = (catOverzichtData && catOverzichtData.artikelen[cat]) || [];
  if (filterLoten6) artikelen = artikelen.filter(item => item.loten6 > 0);

  let html = '<button class="back-btn" id="backBtn">' + t('cgBackToCategories') + '</button><h2>📁 ' + escapeHtml(cat) + '</h2>';
  if (filterLoten6) html += '<p class="form-hint">' + t('cgLoten6FilterHint') + '</p>';
  if (artikelen.length === 0) {
    html += '<p class="empty">' + t(filterLoten6 ? 'cgNoneLoten6' : 'cgNoneInCategory') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colManufacturer') + '</th><th>' + t('colPrice') + '</th><th>' + t('colUnits') + '</th><th>' + t('colValue') + '</th><th>' + t('colStatus') + '</th><th>' + t('colOrder') + '</th><th>' + t('colLotsUnder6mo') + '</th></tr>';
    artikelen.forEach(item => {
      const statusHtml = item.onderMin ? statusBadgeHtml('LAAG') : (item.stuks === 0 ? statusBadgeHtml('LEEG') : statusBadgeHtml('OK'));
      const lotenCelHtml = item.loten6 > 0 ? statusBadgeHtml('MONITOREN') + ' ' + item.loten6 : '0';
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td><td>' + escapeHtml(item.producent) + '</td><td>€' + item.prijs.toFixed(2) + '</td><td>' + item.stuks + '</td><td>€' + item.waarde.toFixed(2) + '</td><td>' + statusHtml + '</td><td>' + statusBadgeHtml(item.orderStatus || '-') + '</td><td>' + lotenCelHtml + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  document.getElementById('backBtn').addEventListener('click', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function() {
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
}

// =================================================================
// OPEN ORDERS - hangende bestellingen volgen (reguliere orders +
// Eenmalige/patiëntgebonden bestellingen: die laatste stonden nergens
// in een overzicht met statusbeheer, alleen in
// een platte, alleen-lezen lijst binnen het aanvraagformulier zelf).
// =================================================================
const EENMALIGE_BESTELLING_STATUSSEN = ['Aangevraagd', 'Besteld', 'Ontvangen', 'Gebruikt', 'Retourneren'];

// showOpenOrders() is hierboven al herzien tot een dunne alias naar
// ShowBestellingen() -- zie de toelichting bij
// showBestellers() hierboven. renderOpenOrders() is daarmee vervallen.

// -----------------------------------------------------------------
// Order detail/bewerking : klikbaar
// vanuit Open Orders, Actiecentrum en Overzicht (de orderstatus-badge).
// Zelfde "hele rij opnieuw versturen"-conventie als de eenmalige-
// bestelling-editpagina hieronder.
// -----------------------------------------------------------------
function showOrderDetail(id) {
  setRoute('order-detail', id);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (data) {
    StocuroRun.withSuccessHandler(function (gebruikers) {
      renderOrderDetail(data, gebruikers);
    }).getGebruikersNamen();
  }).getOrderDetail(id);
}

// Aparte ingang vanuit plekken die alleen een EAN kennen
// (bijv. de orderstatus-badge op Overzicht) -- zoekt zelf de bijbehorende
// open order op (api/order_detail.php?ean=...) en toont dan hetzelfde
// detailscherm. Geen aparte route nodig: eenmaal gevonden is het gewoon
// dezelfde weergave/edit-flow als showOrderDetail(id).
function showOrderDetailVoorEan(ean) {
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (data) {
    if (data.error) {
      alert(data.error);
      showOverzicht();
      return;
    }
    StocuroRun.withSuccessHandler(function (gebruikers) {
      setRoute('order-detail', data.id);
      renderOrderDetail(data, gebruikers);
    }).getGebruikersNamen();
  }).getOrderDetailVoorEan(ean);
}

// Gedeelde helper voor de "Besteld
// door"-dropdowns (order-detail hieronder, eenmalige-bestelling-bewerken
// verderop) -- bouwt <option>s uit de gebruikerslijst
// (getGebruikersNamen(), alleen actieve accounts) en voegt de HUIDIGE
// waarde als extra optie toe wanneer die niet in de lijst voorkomt (bijv.
// een inmiddels gedeactiveerd account, of een oudere vrije-tekst-waarde
// van vóór deze wijziging) -- zo verdwijnt een bestaande waarde niet
// stilzwijgend uit beeld zodra je het bewerkscherm opent.
function bestellerOptiesHtml(gebruikers, huidig) {
  let matchGevonden = false;
  let html = '<option value="">-</option>';
  (gebruikers || []).forEach(function (g) {
    if (g.naam === huidig) matchGevonden = true;
    html += '<option value="' + escapeAttr(g.naam) + '"' + (g.naam === huidig ? ' selected' : '') + '>' + escapeHtml(g.naam) + '</option>';
  });
  if (huidig && !matchGevonden) {
    html += '<option value="' + escapeAttr(huidig) + '" selected>' + escapeHtml(huidig) + '</option>';
  }
  return html;
}

function renderOrderDetail(data, gebruikers) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('odDetailTitle') + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  html += '<div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('colOrderedOn') + '</label><input type="text" value="' + escapeAttr(data.datum) + '" readonly></div>';
  html += '<div class="form-group"><label>' + t('colProduct') + '</label><input type="text" value="' + escapeAttr(data.naam) + '" readonly></div>';
  html += '<div class="form-group"><label>' + t('colOrdered') + '</label><input type="number" id="odBesteld" min="0" value="' + data.besteld + '"></div>';
  html += '<div class="form-group"><label>' + t('colReceived') + '</label><input type="number" id="odOntvangen" min="0" value="' + data.ontvangen + '"></div>';
  // Dropdown van bekende gebruikers
  // i.p.v. vrije tekst -- bij het plaatsen zelf is besteller altijd
  // automatisch de ingelogde gebruiker (zie api/bestelling.php), dit veld
  // is voor een beheerder om dat achteraf te corrigeren/toe te wijzen.
  html += '<div class="form-group"><label>' + t('colOrderedBy') + '</label><select id="odBesteller">' + bestellerOptiesHtml(gebruikers, data.besteller || '') + '</select></div>';
  html += '<div class="form-group"><label>' + t('colChannel') + '</label><input type="text" id="odKanaal" value="' + escapeAttr(data.kanaal || '') + '"></div>';
  // : duidelijk Handmatig/Automatisch tonen, afgeleid
  // van het kanaal (zie includes/orders.php::bestelMethode()) -- alleen-
  // lezen, want dit volgt uit HOE de order is geplaatst, niet iets om hier
  // los van te wijzigen (pas het kanaalveld hierboven aan als dat nodig is).
  html += '<div class="form-group"><label>' + t('colOrderType') + '</label><input type="text" value="' + escapeAttr(data.besteltype ? orderTypeLabel(data.besteltype) : '-') + '" readonly></div>';
  // Eerst/laatst ontvangen op tonen --
  // stond al in getOrderDetail()'s response maar werd hier nooit gerenderd.
  // Alleen tonen als er al minstens één ontvangst is geweest, anders is er
  // niks zinnigs te tonen behalve "nog niet ontvangen".
  html += '<div class="form-group"><label>' + t('odFirstReceivedOn') + '</label><input type="text" value="' + escapeAttr(data.eerstOntvangenOp || t('odNotYetReceived')) + '" readonly></div>';
  html += '<div class="form-group"><label>' + t('odLastReceivedOn') + '</label><input type="text" value="' + escapeAttr(data.laatstOntvangenOp || t('odNotYetReceived')) + '" readonly></div>';
  html += '</div>';
  html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="odOpmerking" rows="2">' + escapeHtml(data.opmerking || '') + '</textarea></div>';
  html += '<p class="form-hint">' + tf('odStatusHint', { status: statusDisplayLabel(data.status) }) + '</p>';

  html += '<div class="edit-actions"><button class="edit-save" id="odSave">' + t('save') + '</button><button class="edit-cancel" id="odProduct">' + t('odViewProduct') + '</button></div>';

  // : "alle transacties rondom deze order" terugzien op
  // de detailpagina, zie includes/orders.php::getOrderDetail()/
  // getTransactiesRondOrder() voor hoe dit venster (besteld_op t/m laatst
  // ontvangen, of tot nu als nog niet ontvangen) is bepaald. Zelfde
  // kolomopzet als de transactiehistorie op de productpagina
  // (renderProductDetail()).
  html += '<div class="section-title">' + t('odTransactionsTitle') + '</div>';
  html += '<p class="form-hint">' + t('odTransactionsHint') + '</p>';
  if (data.transacties && data.transacties.length > 0) {
    html += '<table><tr><th>' + t('colDate') + '</th><th>' + t('colType') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colLocation') + '</th><th>' + t('colSpecialist') + '</th><th>' + t('colScannedBy') + '</th><th>' + t('colNote') + '</th></tr>';
    data.transacties.forEach(function (item, idx) {
      // Ontvangst-transacties clusteren: een Ontvangst-scan die
      // meerdere lots in één actie boekte, stond hier voorheen als evenveel
      // losse rijen -- includes/orders.php::getTransactiesRondOrderGeclusterd()
      // groepeert die nu tot één samengevatte rij (geclusterd:true) met de
      // som van de hoeveelheid en een "bekijk lots"-uitklapper eronder,
      // zelfde regels-array-idee als de Recente Transacties-batches
      // (renderRecenteTransactieRow() hierboven). Ongeclusterde rijen
      // (elk ander transactietype, of een oudere Ontvangst zonder
      // batch_id) tonen gewoon als vanouds één rij.
      if (item.geclusterd && item.regels.length > 1) {
        html += '<tr><td>' + item.timestamp.substring(0, 16) + '</td><td>' + transactieTypeLabel(item.type) + '</td><td>' + item.hoeveelheid + '</td><td>' + escapeHtml(item.locatie) + '</td><td>' + (item.specialist || '-') + '</td><td>' + escapeHtml(item.gescandDoor || '-') + '</td>'
          + '<td><button type="button" class="action-btn od-lots-toggle" data-idx="' + idx + '">' + tf('odBekijkLots', { n: item.regels.length }) + '</button></td></tr>';
        html += '<tr id="odLots' + idx + '" class="batch-lines-row" style="display:none;"><td colspan="7">'
          + '<table class="batch-regels"><tr><th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colQuantity') + '</th></tr>'
          + item.regels.map(function (r) {
            return '<tr><td>' + escapeHtml(r.lot || '-') + '</td><td>' + escapeHtml(r.vervaldatum ? String(r.vervaldatum).substring(0, 10) : '-') + '</td><td>' + r.hoeveelheid + '</td></tr>';
          }).join('')
          + '</table></td></tr>';
        return;
      }
      html += '<tr><td>' + item.timestamp.substring(0, 16) + '</td><td>' + transactieTypeLabel(item.type) + '</td><td>' + item.hoeveelheid + '</td><td>' + escapeHtml(item.locatie) + '</td><td>' + (item.specialist || '-') + '</td><td>' + escapeHtml(item.gescandDoor || '-') + '</td><td>' + (item.opmerking ? escapeHtml(item.opmerking.substring(0, 30)) + '...' : '-') + '</td></tr>';
    });
    html += '</table>';
  } else {
    html += '<p class="empty">' + t('odNoTransactions') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.getElementById('odProduct').addEventListener('click', function () { showProductDetail(data.ean); });
  document.querySelectorAll('.od-lots-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row = document.getElementById('odLots' + this.getAttribute('data-idx'));
      if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
    });
  });

  document.getElementById('odSave').addEventListener('click', function () {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';

    apiPost('api/order_detail.php', {
      actie: 'bewerken', id: data.id,
      besteld: document.getElementById('odBesteld').value,
      ontvangen: document.getElementById('odOntvangen').value,
      besteller: document.getElementById('odBesteller').value,
      kanaal: document.getElementById('odKanaal').value,
      opmerking: document.getElementById('odOpmerking').value,
    }, function () { showOrderDetail(data.id); }, function (err) {
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
}

// -----------------------------------------------------------------
// Eenmalige bestelling: detail- en editpagina, bereikbaar door in Open
// Orders (of de alleen-lezen lijst op het aanvraagformulier) op een
// Regel te klikken. : niet meer een status-select
// direct in het overzicht, maar eerst de volledige inhoud zien (ook de
// opmerkingen) en van daaruit de eigenschappen bewerken.
// -----------------------------------------------------------------
function showEenmaligeBestellingDetail(id) {
  setRoute('eenmalige-bestelling-detail', id);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderEenmaligeBestellingDetail).getEenmaligeBestellingDetail(id);
}

// Dezelfde itemtabel (EAN/Lot/
// Vervaldatum/status per gescande regel) moet zowel op de detailpagina
// als op de bewerkpagina te zien zijn -- eerst stond dit alleen op
// Bewerken, en moest je daar altijd eerst naartoe klikken om te zien
// wat er al gescand was. Eén gedeelde bouwer i.p.v. tweemaal dezelfde
// HTML, zie renderEenmaligeBestellingDetail()/renderEenmaligeBestellingEdit().
const EB_ITEM_STATUS_BADGE = { ontvangen: 'neutraal', verbruikt: 'voldoende', retour: 'monitoren' };
const EB_ITEM_STATUS_LABEL = { ontvangen: 'ebStatusOntvangen', verbruikt: 'ebStatusVerbruikt', retour: 'ebStatusRetour' };
function eenmaligeBestellingItemsTabelHtml(items) {
  let html = '<div class="section-title">' + t('ebItemsTitle') + '</div>';
  if (items && items.length > 0) {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colEan') + '</th><th>' + t('colLot') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('colStatus') + '</th></tr>';
    html += items.map(function (item) {
      const naamHtml = item.onbekend
        ? '<span class="badge badge-monitoren">' + escapeHtml(t('ebItemOnbekend')) + '</span>'
        : escapeHtml(item.productnaam || '-');
      const statusKlasse = EB_ITEM_STATUS_BADGE[item.status] || 'neutraal';
      const statusLabel = t(EB_ITEM_STATUS_LABEL[item.status] || 'ebStatusOntvangen');
      const statusHtml = '<span class="badge badge-' + statusKlasse + '">' + escapeHtml(statusLabel) + '</span>';
      return '<tr><td>' + naamHtml + '</td><td>' + escapeHtml(item.ean || '-') + '</td><td>' + escapeHtml(item.lot || '-') + '</td><td>' + escapeHtml(item.vervaldatum || '-') + '</td><td>' + statusHtml + '</td></tr>';
    }).join('');
    html += '</table>';
  } else {
    html += '<p class="form-hint">' + t('ebItemsEmpty') + '</p>';
  }
  return html;
}

function renderEenmaligeBestellingDetail(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    // Terug moet naar het Eenmalige
    // Bestelling-overzicht (waar je vandaan komt), niet naar de
    // algemene Bestellingen-lijst.
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h1>' + t('ebDetailTitle') + '<button class="edit-btn" id="ebEditBtn">' + t('pdEdit') + '</button></h1>';

  html += '<div class="product-info">';
  html += '<div class="info-box"><div class="info-label">' + t('colDate') + '</div><div class="info-value">' + escapeHtml(data.tijdstempel) + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colPatientNo') + '</div><div class="info-value">' + escapeHtml(data.patientnummer) + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colDescription') + '</div><div class="info-value">' + escapeHtml(data.artikelomschrijving) + '</div></div>';
  // EAN/Lot/Vervaldatum NIET meer op
  // de detailpagina -- een eenmalige bestelling gaat bijna altijd om
  // meerdere verschillende producten tegelijk (de vrije-tekst
  // omschrijving hierboven dekt dat), dus een los EAN/lot/vervaldatum
  // (van slechts de EERSTE gescande regel, zie gs1VoorEenmaligeBestelling()
  // in includes/eenmalige_bestellingen.php) suggereert een precisie die
  // er voor deze aanvraag niet is. Het TOTAAL aantal (hoeveelheid)
  // hieronder blijft wel zinvol en dus zichtbaar.
  html += '<div class="info-box"><div class="info-label">' + t('colQuantity') + '</div><div class="info-value">' + data.hoeveelheid + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colProcedure') + '</div><div class="info-value">' + escapeHtml(data.procedure || '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colSpecialist') + '</div><div class="info-value">' + escapeHtml(data.specialist || '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colOrderedBy') + '</div><div class="info-value">' + escapeHtml(data.besteller || '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colStatus') + '</div><div class="info-value">' + statusBadgeHtml(data.status) + '</div></div>';
  html += '</div>';

  // Direct de gescande items (EAN/
  // Lot/Vervaldatum/status) tonen op de detailpagina zelf -- voorheen
  // moest je daarvoor eerst op "Bewerken" klikken, terwijl je hier vaak
  // alleen komt om te kijken wat er al binnen is.
  html += eenmaligeBestellingItemsTabelHtml(data.items);

  html += '<div class="section-title">' + t('colNote') + '</div>';
  html += '<p>' + (data.opmerking ? escapeHtml(data.opmerking) : '<span class="empty">' + t('noneRecorded') + '</span>') + '</p>';

  // Mailto-knop: "Mail specialist" en
  // "Mail planning" waren twee identieke knoppen die alleen verschilden
  // in het vooraf ingevulde "aan"-adres -- samengevoegd tot één "Stuur
  // e-mail"-knop. Die vult het specialist-e-mailadres alvast in als dat
  // bekend is (zelfde als de oude "Mail specialist"-knop deed), maar de
  // ontvanger kan in de eigen mailclient net zo makkelijk worden
  // aangepast naar planning/logistiek -- vandaar geen twee aparte
  // knoppen meer nodig. Patiëntnummer staat nu WEL bewust in de
  // mailinhoud -- let op dat e-mail geen beveiligd
  // kanaal is voor persoonsgegevens.
  html += '<div class="edit-actions">'
    + '<button class="action-btn" id="ebMailSend">' + t('ebMailSend') + '</button>'
    + '</div>';
  if (!data.specialist) {
    html += '<p class="form-hint">' + t('ebMailNoSpecialist') + '</p>';
  } else if (!data.specialistEmail) {
    html += '<p class="form-hint">' + tf('ebMailNoEmail', { naam: data.specialist }) + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.getElementById('ebEditBtn').addEventListener('click', function () { showEenmaligeBestellingEdit(data); });

  document.getElementById('ebMailSend').addEventListener('click', function () {
    window.location.href = eenmaligeBestellingMailtoUrl(data, data.specialistEmail || '');
  });
}

/**
 * Bouwt een mailto:-link met onderwerp + inhoud van een eenmalige
 * bestelling, voor de "Stuur e-mail"-knop. Bevat bewust ook het
 * patiëntnummer -- eerder weggelaten vanwege het persoonsgegeven-
 * karakter, maar de ontvanger (specialist/planning) heeft dit nodig.
 */
function eenmaligeBestellingMailtoUrl(data, aan) {
  const onderwerp = tf('ebMailSubject', { id: data.id, omschrijving: data.artikelomschrijving, status: statusDisplayLabel(data.status) });
  const regels = [
    tf('ebMailLineOrder', { id: data.id }),
    tf('ebMailLinePatient', { patientnummer: data.patientnummer || '-' }),
    tf('ebMailLineDescription', { omschrijving: data.artikelomschrijving }),
    // Geen EAN meer in de mail, zelfde reden als op de
    // detailpagina (meestal meerdere verschillende producten).
    tf('ebMailLineQuantity', { n: data.hoeveelheid }),
    tf('ebMailLineProcedure', { procedure: data.procedure || '-' }),
    tf('ebMailLineStatus', { status: statusDisplayLabel(data.status) }),
    tf('ebMailLineOrderedBy', { naam: data.besteller || '-' }),
  ];
  const inhoud = regels.join('\n');
  // 'aan' bewust NIET door encodeURIComponent (die zet '@' om naar
  // '%40'; niet elke mail-client decodeert het "to"-deel van een
  // mailto-link, alleen de query-parameters erna) -- een e-mailadres
  // bevat geen tekens die in een mailto-URI geëscaped moeten worden.
  return 'mailto:' + (aan || '') + '?subject=' + encodeURIComponent(onderwerp) + '&body=' + encodeURIComponent(inhoud);
}

function showEenmaligeBestellingEdit(data) {
  setRoute('eenmalige-bestelling-edit', data.id);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (specialisten) {
    StocuroRun.withSuccessHandler(function (procedures) {
      StocuroRun.withSuccessHandler(function (gebruikers) {
        renderEenmaligeBestellingEdit(data, specialisten, procedures, gebruikers);
      }).getGebruikersNamen();
    }).getProcedureTypen();
  }).getSpecialisten();
}

function renderEenmaligeBestellingEdit(data, specialisten, procedures, gebruikers) {
  function optiesMetSelectie(items, huidig) {
    return items.filter(function (x) { return x.actief == 1 || x.naam === huidig; }).map(function (x) {
      return '<option value="' + escapeAttr(x.naam) + '"' + (x.naam === huidig ? ' selected' : '') + '>' + escapeHtml(x.naam) + '</option>';
    }).join('');
  }

  let html = '<button class="back-btn" id="cancelEditBtn">' + t('cancel') + '</button>';
  html += '<h2>' + t('ebEditTitle') + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  // (tweede feedbackronde -- de eerdere
  // afspraak hierover was nog niet zichtbaar doorgevoerd): elke gescande
  // GS1-regel wordt voortaan een eigen itemrij (EAN/product/lot/
  // vervaldatum) i.p.v. samengevoegd tot één vlakke rij, want een
  // eenmalige bestelling kan uit meerdere VERSCHILLENDE producten bestaan
  // (bijv. meerdere stentmaten). De tabel hieronder toont wat er tot nu
  // toe al gescand/opgeslagen is (readonly, alleen-lezen); de textarea
  // erboven is voor NIEUWE regels -- die worden pas bij "Opslaan"
  // toegevoegd. Onbekende EAN's blokkeren het opslaan totdat er een
  // omschrijving is ingevuld (zelfde twee-fase-patroon als bij
  // Ontvangst/Verbruik/Verplaatsing, zie toonOnbekendeBarcodesPaneel()).
  html += '<div class="form-group"><label>' + t('oeGs1Scan') + '</label><textarea id="ebeGs1" rows="6" placeholder="' + escapeAttr(t('oeGs1Placeholder')) + '"></textarea><div id="ebeGs1Hint" class="gs1-hint" style="display:none;"></div><p class="form-hint">' + t('oeGs1Hint') + '</p></div>';
  html += '<div id="onbekendPaneel"></div>';

  // De itemtabel toont ook wat er
  // MET elk item is gebeurd nadat het is ontvangen -- gewoon verder
  // gescand via Materiaal Verbruik of Verplaatsing+Retouren zet dit
  // automatisch op 'verbruikt'/'retour', zie
  // koppelScanAanEenmaligeBestellingItems() in includes/transacties.php.
  // Gedeelde opbouw met de detailpagina, zie eenmaligeBestellingItemsTabelHtml()
  // hierboven bij renderEenmaligeBestellingDetail().
  html += eenmaligeBestellingItemsTabelHtml(data.items);

  html += '<div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('colPatientNo') + '</label><input type="text" id="ebePatient" value="' + escapeAttr(data.patientnummer) + '"></div>';
  html += '<div class="form-group"><label>' + t('colDescription') + '</label><input type="text" id="ebeOmschrijving" value="' + escapeAttr(data.artikelomschrijving) + '"></div>';
  html += '<div class="form-group"><label>' + t('colProcedure') + '</label><select id="ebeProcedure"><option value="">' + t('oeSelectProcedure') + '</option>' + optiesMetSelectie(procedures, data.procedure) + '</select></div>';
  html += '<div class="form-group"><label>' + t('colSpecialist') + '</label><select id="ebeSpecialist"><option value="">' + t('oeSelectSpecialist') + '</option>' + specialistOptionsHtml(specialisten, data.specialist) + '</select></div>';
  // Dropdown i.p.v. vrije tekst -- zie
  // bestellerOptiesHtml() hierboven.
  html += '<div class="form-group"><label>' + t('colOrderedBy') + '</label><select id="ebeBesteller">' + bestellerOptiesHtml(gebruikers, data.besteller || '') + '</select></div>';
  html += '<div class="form-group"><label>' + t('colStatus') + '</label><select id="ebeStatus">' + EENMALIGE_BESTELLING_STATUSSEN.map(function (s) {
    var labelKey = STATUS_BADGE_LABEL_KEYS[s.toUpperCase()];
    return '<option value="' + s + '"' + (s === data.status ? ' selected' : '') + '>' + escapeHtml(labelKey ? t(labelKey) : s) + '</option>';
  }).join('') + '</select></div>';
  html += '</div>';
  html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="ebeOpmerking" rows="2">' + escapeHtml(data.opmerking || '') + '</textarea></div>';

  html += '<div class="edit-actions"><button class="edit-save" id="ebeSave">' + t('save') + '</button><button class="edit-cancel" id="cancelEditBtn2">' + t('cancel') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  document.getElementById('cancelEditBtn').addEventListener('click', function () { showEenmaligeBestellingDetail(data.id); });
  document.getElementById('cancelEditBtn2').addEventListener('click', function () { showEenmaligeBestellingDetail(data.id); });
  initGs1ScanHint('ebeGs1', 'ebeGs1Hint');

  // Twee-fase opslaan, zelfde patroon als de scanformulieren
  // (zie renderScanOntvangstForm()). Een onbekende EAN in de nieuw
  // gescande regels blokkeert het opslaan (ook van de andere velden)
  // totdat er een omschrijving is ingevuld in het onbekendPaneel; dan
  // wordt doSubmit() opnieuw aangeroepen met die omschrijvingen erbij.
  const saveBtn = document.getElementById('ebeSave');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    const patientnummer = document.getElementById('ebePatient').value.trim();
    const omschrijving = document.getElementById('ebeOmschrijving').value.trim();
    if (!patientnummer || !omschrijving) {
      errorEl.textContent = t('oeRequiredFields');
      errorEl.style.display = 'block';
      return;
    }

    saveBtn.disabled = true;
    apiPost('api/eenmalige_bestelling.php', {
      actie: 'bewerken', id: data.id,
      patientnummer: patientnummer, artikelomschrijving: omschrijving,
      gs1_raw: document.getElementById('ebeGs1').value.trim() || null,
      omschrijvingen: omschrijvingen || {},
      procedure_type: document.getElementById('ebeProcedure').value,
      specialist: document.getElementById('ebeSpecialist').value,
      besteller: document.getElementById('ebeBesteller').value,
      opmerking: document.getElementById('ebeOpmerking').value,
      status: document.getElementById('ebeStatus').value,
    }, function (result) {
      if (result.onbekend) {
        saveBtn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      showEenmaligeBestellingDetail(data.id);
    }, function (err) {
      saveBtn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  saveBtn.addEventListener('click', function () { doSubmit({}); });
}

// =================================================================
// VERBRUIKSTRENDS + SLOW MOVERS
// =================================================================
// Cache van de laatst opgehaalde trends-data, zodat het
// terugknopje vanaf de product-drilldown (showVerbruiksTrendDetail())
// direct kan her-renderen i.p.v. opnieuw op te halen -- zelfde patroon als
// catOverzichtData bij Categorieën.
let trendsOverzichtData = null;

function showTrends() {
  setRoute('trends');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (data) {
    trendsOverzichtData = data;
    renderTrends(data);
  }).getVerbruiksTrends();
}

// Bouwt een cel-waarde die -- alleen als er ook echt iets
// achter zit (count > 0) -- doorklikbaar is naar de productenlijst voor
// precies dat (maand, reeks[, sub]). Bij count === 0 gewoon platte tekst,
// zodat je nooit naar een gegarandeerd lege lijst klikt.
function trendDrillLink(count, labelHtml, maand, reeks, sub, titel) {
  if (!count) return labelHtml;
  return '<a class="trend-drill-link" data-maand="' + escapeAttr(maand) + '" data-reeks="' + escapeAttr(reeks)
    + '" data-sub="' + escapeAttr(sub || '') + '" data-titel="' + escapeAttr(titel) + '">' + labelHtml + '</a>';
}

function renderTrends(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('usageTrends') + '</h2>';
  html += usageTabsHtml('trends');

  html += '<div class="section-title">' + t('trUsagePerMonth') + '</div>';
  if (data.maanden.length === 0) {
    html += '<p class="empty">' + t('trNoData') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colMonth') + '</th>';
    data.categorieen.forEach(cat => { html += '<th>' + escapeHtml(cat) + '</th>'; });
    html += '<th>' + t('colTotal') + '</th></tr>';
    data.maanden.forEach(m => {
      html += '<tr><td><strong>' + m.maand + '</strong></td>';
      data.categorieen.forEach(cat => {
        const cnt = m.perCat[cat] || 0;
        html += '<td>' + trendDrillLink(cnt, String(cnt), m.maand, 'verbruik', cat, escapeHtml(cat) + ' — ' + m.maand) + '</td>';
      });
      html += '<td><strong>' + trendDrillLink(m.totaal, String(m.totaal), m.maand, 'verbruik', '', t('colTotal') + ' — ' + m.maand) + '</strong></td></tr>';
    });
    html += '</table>';
  }

  // Aanpassingen (Telling: overschot/tekort), afboekingen (Vervallen) en
  // besparing (bijna-vervallen-toch-gebruikt) per maand -- zie
  // includes/dashboards.php::getVerbruiksTrends() voor hoe deze drie
  // reeksen zijn opgebouwd. Zelfde maandenlijst (data.maanden) als
  // hierboven, dus geen aparte "geen data"-check per sectie nodig buiten
  // de algemene lengte-check.
  html += '<div class="section-title">' + t('trAdjustmentsTitle') + '</div>';
  html += '<p class="form-hint">' + t('trAdjustmentsHint') + '</p>';
  if (data.aanpassingen.length === 0) {
    html += '<p class="empty">' + t('trNoData') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colMonth') + '</th><th>' + t('colSurplus') + '</th><th>' + t('colShortage') + '</th></tr>';
    data.aanpassingen.forEach(function (m) {
      const overschotLabel = m.overschotAantal + ' (€' + m.overschotWaarde.toFixed(2) + ')';
      const tekortLabel = m.tekortAantal + ' (€' + m.tekortWaarde.toFixed(2) + ')';
      html += '<tr><td><strong>' + m.maand + '</strong></td>'
        + '<td>' + trendDrillLink(m.overschotAantal, overschotLabel, m.maand, 'overschot', '', t('colSurplus') + ' — ' + m.maand) + '</td>'
        + '<td>' + trendDrillLink(m.tekortAantal, tekortLabel, m.maand, 'tekort', '', t('colShortage') + ' — ' + m.maand) + '</td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title">' + t('trWriteOffsTitle') + '</div>';
  html += '<p class="form-hint">' + t('trWriteOffsHint') + '</p>';
  if (data.afboekingenTrend.length === 0) {
    html += '<p class="empty">' + t('trNoData') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colMonth') + '</th><th>' + t('colQty') + '</th><th>' + t('colValue') + '</th></tr>';
    data.afboekingenTrend.forEach(function (m) {
      html += '<tr><td><strong>' + m.maand + '</strong></td><td>' + trendDrillLink(m.aantal, String(m.aantal), m.maand, 'afboekingen', '', t('trWriteOffsTitle') + ' — ' + m.maand) + '</td><td>€' + m.waarde.toFixed(2) + '</td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title">' + t('trSavingsTitle') + '</div>';
  html += '<p class="form-hint">' + t('trSavingsHint') + '</p>';
  if (data.besparing.length === 0) {
    html += '<p class="empty">' + t('trNoData') + '</p>';
  } else {
    let besparingTotaal = 0;
    data.besparing.forEach(function (m) { besparingTotaal += m.totaalWaarde; });
    html += '<div class="value-tiles"><div class="value-tile"><div class="value-label">' + t('trSavingsTotal') + '</div><div class="value-amount" style="color:#059669;">€' + besparingTotaal.toFixed(2) + '</div></div></div>';
    html += '<table><tr><th>' + t('colMonth') + '</th><th>' + t('colWithin30') + '</th><th>' + t('colWithin60') + '</th><th>' + t('colWithin90') + '</th><th>' + t('colTotal') + '</th></tr>';
    data.besparing.forEach(function (m) {
      const l30 = m.tot30Aantal + ' (€' + m.tot30Waarde.toFixed(2) + ')';
      const l60 = m.tot60Aantal + ' (€' + m.tot60Waarde.toFixed(2) + ')';
      const l90 = m.tot90Aantal + ' (€' + m.tot90Waarde.toFixed(2) + ')';
      const lTot = m.totaalAantal + ' (€' + m.totaalWaarde.toFixed(2) + ')';
      html += '<tr><td><strong>' + m.maand + '</strong></td>'
        + '<td>' + trendDrillLink(m.tot30Aantal, l30, m.maand, 'besparing', 'tot30', t('colWithin30') + ' — ' + m.maand) + '</td>'
        + '<td>' + trendDrillLink(m.tot60Aantal, l60, m.maand, 'besparing', 'tot60', t('colWithin60') + ' — ' + m.maand) + '</td>'
        + '<td>' + trendDrillLink(m.tot90Aantal, l90, m.maand, 'besparing', 'tot90', t('colWithin90') + ' — ' + m.maand) + '</td>'
        + '<td><strong>' + trendDrillLink(m.totaalAantal, lTot, m.maand, 'besparing', '', t('colTotal') + ' — ' + m.maand) + '</strong></td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title">' + t('trSlowMovers') + '</div>';
  if (data.slowMovers.length === 0) {
    html += '<p class="empty">' + t('trNoSlowMovers') + '</p>';
  } else {
    let totaalWaarde = 0;
    data.slowMovers.forEach(item => { totaalWaarde += item.waarde; });
    html += '<div class="value-tiles"><div class="value-tile"><div class="value-label">' + t('trCapitalTied') + '</div><div class="value-amount" style="color:#a16207;">€' + totaalWaarde.toFixed(2) + '</div><div class="value-sub">' + data.slowMovers.length + ' ' + t('trItems') + '</div></div></div>';
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategory') + '</th><th>' + t('colUnits') + '</th><th>' + t('colValue') + '</th><th>' + t('colLastUsage') + '</th></tr>';
    data.slowMovers.forEach(item => {
      const laatsteHtml = item.laatste === 'Nooit' ? '<em>' + t('trNever') + '</em>' : escapeHtml(item.laatste) + ' (' + item.dagenGeleden + ' ' + t('trDaysAbbrev') + ')';
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td><td>' + escapeHtml(item.categorie) + '</td><td>' + item.stuks + '</td><td>€' + item.waarde.toFixed(2) + '</td><td>' + laatsteHtml + '</td></tr>';
    });
    html += '</table>';
    html += '<p style="color:#999; font-size:13px;">' + t('trSlowMoverHint') + '</p>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function() {
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
  // Elke aangeklikte cel (zie trendDrillLink() hierboven)
  // opent de productenlijst erachter.
  document.querySelectorAll('.trend-drill-link').forEach(el => {
    el.addEventListener('click', function() {
      showVerbruiksTrendDetail(this.getAttribute('data-maand'), this.getAttribute('data-reeks'), this.getAttribute('data-sub'), this.getAttribute('data-titel'));
    });
  });
  wireUsageTabs();
}

// Product-drilldown achter één cel van het Trends-tabblad.
// Zie includes/dashboards.php::getVerbruiksTrendDetail() voor reeks/sub.
function showVerbruiksTrendDetail(maand, reeks, sub, titel) {
  setRoute('trend-detail');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (data) {
    renderVerbruiksTrendDetail(data, titel);
  }).getVerbruiksTrendDetail(maand, reeks, sub);
}

function renderVerbruiksTrendDetail(data, titel) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + escapeHtml(titel) + '</h2>';
  html += '<p class="form-hint">' + t('trDrillHint') + '</p>';
  if (data.regels.length === 0) {
    html += '<p class="empty">' + t('trDrillNone') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colQty') + '</th><th>' + t('colValue') + '</th></tr>';
    data.regels.forEach(function (r) {
      html += '<tr><td><a class="product-link" data-ean="' + r.ean + '">' + escapeHtml(r.naam) + '</a></td><td>' + r.hoeveelheid + '</td><td>€' + r.waarde.toFixed(2) + '</td></tr>';
    });
    html += '<tr><td><strong>' + t('cgTotal') + '</strong></td><td><strong>' + data.totaalAantal + '</strong></td><td><strong>€' + data.totaalWaarde.toFixed(2) + '</strong></td></tr>';
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  document.getElementById('backBtn').addEventListener('click', goBack);
  document.querySelectorAll('.product-link').forEach(el => {
    el.addEventListener('click', function() {
      showProductDetail(this.getAttribute('data-ean'));
    });
  });
}

// =================================================================
// LEADERBOARD - wie heeft de meeste scans gedaan, wie de meeste
// Procedures van een bepaald type. Twee losse tellingen,
// zie includes/leaderboard.php voor de onderbouwing van dat onderscheid.
// Periode en procedure-type-filter zijn client-side state (net als
// vervalFocusFilter bij Expiry Focus) -- opnieuw ophalen bij wijziging.
// =================================================================
let leaderboardPeriode = 'maand';
let leaderboardProcedureType = '';
// Leaderboard 'wie is aan de beurt' uitbreiden: getPiccRotatie()
// (includes/leaderboard.php) bestond al -- tot nu toe alleen gebruikt
// voor het compacte Home-widget (alleen de eerste in de rij, zie
// renderHomeWidgets()). Onafhankelijk van periode/procedure-filter
// hierboven (die gelden alleen voor de scan-/procedure-tellingen), dus
// hier los in een eigen state-variabele bijgehouden i.p.v. opnieuw
// opgehaald bij elke periode-/filterwijziging.
let leaderboardPiccData = null;

// Leaderboard en "Het Team" (voorheen een onderdeel van het
// gecombineerde Nieuws-dashboard) samengevoegd tot "Team & Prestaties"
// met tabbalk -- allebei draaien om
// wie er in het team zit, alleen de ene kant toont prestatiecijfers en de
// andere kant toont wie het is (met foto).
const TEAM_TABS = [
  ['leaderboard', 'leaderboard', showLeaderboard],
  ['team', 'newsTeamTitle', showTeamOverzicht],
];
function teamTabsHtml(actief) {
  const zichtbaar = TEAM_TABS.filter(function (tab) { return heeftScherm(tab[0]); });
  if (zichtbaar.length <= 1) return '';
  let html = '<div style="margin: 0.5rem 0 1rem;">';
  zichtbaar.forEach(function (tab) {
    html += '<button class="hub-tab-btn' + (tab[0] === actief ? ' active' : '') + '" data-team-tab="' + tab[0] + '">' + t(tab[1]) + '</button>';
  });
  html += '</div>';
  return html;
}
function wireTeamTabs() {
  document.querySelectorAll('[data-team-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-team-tab');
      const target = TEAM_TABS.find(function (tab) { return tab[0] === id; });
      if (target) target[2]();
    });
  });
}
function showTeamHub() {
  const eerste = TEAM_TABS.find(function (tab) { return heeftScherm(tab[0]); });
  if (eerste) eerste[2]();
}

function showLeaderboard() {
  setRoute('leaderboard');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  // De PICC-rotatie hoeft maar
  // eenmaal per paginabezoek opgehaald te worden (zie toelichting bij
  // leaderboardPiccData hierboven) -- gebeurt hier PARALLEL aan de
  // leaderboard-telling zelf, zelfde veilige patroon (eigen closure per
  // aanroep) als de Home-widgets.
  StocuroRun.withSuccessHandler(function (data) { leaderboardPiccData = data; renderLeaderboardPiccSection(); }).getPiccRotatie('picc_rotatie');
  fetchLeaderboardData();
}

// Volledige
// rotatie-wachtrij per PICC-procedure (niet alleen de eerste, zoals het
// Home-widget) -- rendert in zijn eigen placeholder-div (#lbPiccSection,
// zie renderLeaderboard()) zodat dit onafhankelijk kan bijwerken zodra
// getPiccRotatie() terugkomt, ongeacht of dat vóór of ná de leaderboard-
// telling zelf klaar is (twee losse, parallelle aanroepen, zie showLeaderboard()).
function renderLeaderboardPiccSection() {
  const container = document.getElementById('lbPiccSection');
  if (!container) return; // pagina nog niet (opnieuw) gerenderd, of alweer verlaten
  if (!leaderboardPiccData) {
    container.innerHTML = '<p>' + t('loading') + '</p>';
    return;
  }
  let html = '';
  if (leaderboardPiccData.length === 0) {
    html += '<p class="empty">' + t('hwGeenPiccProcedures') + '</p>';
  } else {
    leaderboardPiccData.forEach(function (r) {
      html += '<div class="section-subtitle">' + escapeHtml(r.procedureType) + '</div>';
      if (r.volgorde.length === 0) {
        html += '<p class="empty">' + t('lbPiccGeenLaboranten') + '</p>';
      } else {
        html += '<table><tr><th>#</th><th>' + t('colName') + '</th><th>' + t('lbPiccLaatstUitgevoerd') + '</th></tr>';
        r.volgorde.forEach(function (v, idx) {
          const laatstHtml = v.laatstUitgevoerd ? escapeHtml(String(v.laatstUitgevoerd).substring(0, 16)) : t('lbPiccNooitGedaan');
          html += '<tr class="' + (idx === 0 ? 'lb-picc-eerst' : '') + '"><td>' + (idx + 1) + '</td><td>' + escapeHtml(v.naam) + '</td><td>' + laatstHtml + '</td></tr>';
        });
        html += '</table>';
      }
    });
  }
  container.innerHTML = html;
}

function fetchLeaderboardData() {
  StocuroRun.withSuccessHandler(renderLeaderboard).getLeaderboardData(leaderboardPeriode, leaderboardProcedureType);
}

function renderLeaderboard(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const periodes = [
    ['week', t('lbPeriodWeek')], ['maand', t('lbPeriodMonth')], ['kwartaal', t('lbPeriodQuarter')],
    ['jaar', t('lbPeriodYear')], ['totaal', t('lbPeriodTotal')],
  ];

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('leaderboard') + '</h2>';
  html += teamTabsHtml('leaderboard');
  html += '<p class="form-hint">' + t('lbHint') + '</p>';

  html += '<div class="form-group"><label>' + t('lbPeriod') + '</label><div>';
  periodes.forEach(function (p) {
    html += '<button class="filter-btn lb-periode-btn' + (p[0] === data.periode ? ' active' : '') + '" data-periode="' + p[0] + '">' + p[1] + '</button>';
  });
  html += '</div></div>';

  // Volledige rotatie-wachtrij,
  // los van de periodefilter hierboven (zie leaderboardPiccData/
  // renderLeaderboardPiccSection() bovenin dit bestand) -- gevuld zodra
  // getPiccRotatie() terugkomt, niet noodzakelijk vóór deze render-pas klaar.
  html += '<div class="section-title">' + t('lbPiccTitle') + '</div>';
  html += '<p class="form-hint">' + t('lbPiccHint') + '</p>';
  html += '<div id="lbPiccSection"><p>' + t('loading') + '</p></div>';

  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('lbScansTitle') + '</div>';
  html += '<p class="form-hint">' + t('lbScansHint') + '</p>';
  if (data.scans.length === 0) {
    html += '<p class="empty">' + t('lbNoScans') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colName') + '</th><th>' + t('colCount') + '</th></tr>';
    data.scans.forEach(function (r) {
      html += '<tr><td>' + escapeHtml(r.naam) + '</td><td><strong>' + r.aantal + '</strong></td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title" style="margin-top:1.5rem;">' + t('lbProceduresTitle') + '</div>';
  html += '<p class="form-hint">' + t('lbProceduresHint') + '</p>';
  html += '<div class="form-group"><label>' + t('lbFilterProcedure') + '</label><select id="lbProcedureFilter">';
  html += '<option value="">' + t('lbAllProcedures') + '</option>';
  data.procedureTypen.forEach(function (p) {
    html += '<option value="' + escapeAttr(p) + '"' + (p === leaderboardProcedureType ? ' selected' : '') + '>' + escapeHtml(p) + '</option>';
  });
  html += '</select></div>';

  if (data.procedures.length === 0) {
    html += '<p class="empty">' + t('lbNoProcedures') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colSpecialist') + '</th><th>' + t('mdType') + '</th><th>' + t('colCount') + '</th></tr>';
    data.procedures.forEach(function (r) {
      html += '<tr><td>' + escapeHtml(r.specialist) + '</td><td>' + functieLabel(r.type) + '</td><td><strong>' + r.aantal + '</strong></td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  // leaderboardPiccData kan al binnen zijn (parallelle aanroep in
  // showLeaderboard(), zie hierboven) vóórdat deze render-pas draait --
  // dan meteen invullen i.p.v. op de placeholder-tekst laten staan.
  renderLeaderboardPiccSection();

  document.querySelectorAll('.lb-periode-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      leaderboardPeriode = this.getAttribute('data-periode');
      showLeaderboard();
    });
  });

  const procFilter = document.getElementById('lbProcedureFilter');
  if (procFilter) {
    procFilter.value = leaderboardProcedureType;
    procFilter.addEventListener('change', function () {
      leaderboardProcedureType = this.value;
      fetchLeaderboardData();
    });
  }
  wireTeamTabs();
}

// "Het Team"-tab van het Team & Prestaties-dashboard --
// Voorheen onderdeel van renderNieuwsDashboard(), nu een
// eigen scherm/endpoint (zie api/team_overzicht.php).
function showTeamOverzicht() {
  setRoute('team');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderTeamOverzicht).getTeamOverzicht();
}

function renderTeamOverzicht(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('newsTeamTitle') + '</h2>';
  html += teamTabsHtml('team');

  html += '<div class="section-subtitle">' + t('newsSpecialists') + '</div>';
  if (data.specialisten.length === 0) {
    html += '<p class="empty">' + t('newsNoTeamMembers') + '</p>';
  } else {
    html += '<div class="people-grid">';
    data.specialisten.forEach(function (p) { html += personCardHtml(p.foto, p.naam); });
    html += '</div>';
  }
  html += '<div class="section-subtitle" style="margin-top:1rem;">' + t('newsLabTechs') + '</div>';
  if (data.laboranten.length === 0) {
    html += '<p class="empty">' + t('newsNoTeamMembers') + '</p>';
  } else {
    html += '<div class="people-grid">';
    data.laboranten.forEach(function (p) { html += personCardHtml(p.foto, p.naam); });
    html += '</div>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  wireTeamTabs();
}

// =================================================================
// MIN/MAX ADVIES (includes/minmax.php) - geadviseerd min/max per
// artikel x locatie op basis van historisch verbruik, piekverbruik,
// houdbaarheid en levertijd. Toont alleen -- toepassen is per rij een
// losse, expliciete actie (applyMinMaxRow()), nooit automatisch.
// =================================================================
function showMinMaxAdvies() {
  setRoute('minmax-advies');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderMinMaxAdvies).getMinMaxAdviesOverzicht();
}

function renderMinMaxAdvies(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('minmaxAdvies') + '</h2>';
  html += voorraadTabsHtml('minmax-advies');
  html += '<p class="form-hint">' + t('mmHint') + '</p>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('mmEmpty') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLocation') + '</th>'
      + '<th>' + t('mmCurrentMin') + '</th><th>' + t('mmCurrentMax') + '</th>'
      + '<th>' + t('mmAdvisedMin') + '</th><th>' + t('mmAdvisedMax') + '</th>'
      + '<th>' + t('mmBasis') + '</th><th>' + t('mmShelfLife') + '</th><th></th></tr>';

    data.forEach(function (r) {
      // Productnaam is klikbaar (zelfde .product-link-conventie als alle
      // andere dashboardtabellen) -> Productdetail, met daarop een
      // Bewerken-knop naar het volledige bewerkformulier (incl. min/max per
      // locatie) -- voor als je de geadviseerde waarden liever handmatig
      // wilt aanpassen i.p.v. het advies 1-op-1 over te nemen met
      // "Toepassen" hieronder.
      const productCell = '<a class="product-link" data-ean="' + r.ean + '">' + escapeHtml(r.naam) + '</a>';

      if (r.status === 'onvoldoende_data') {
        const huidigMinCelMuted = r.isBerging ? r.huidigMin : '<span class="muted">' + t('mmNotApplicable') + '</span>';
        html += '<tr class="mm-row-muted"><td>' + productCell + '</td><td>' + escapeHtml(r.locatie) + '</td>'
          + '<td>' + huidigMinCelMuted + '</td><td>' + r.huidigMax + '</td>'
          + '<td colspan="4"><em>' + tf('mmInsufficientData', { weken: r.wekenGedekt, minimum: r.minWekenHistorie }) + '</em></td><td></td></tr>';
        return;
      }

      // Twee formules (zie includes/minmax.php voor de volledige
      // toelichting): 'levertijd' voor berging (wacht op een externe
      // leverancier), 'dagvoorraad' voor kamers (dezelfde dag intern
      // bijgevuld vanuit berging). De "Basis"-kolom toont per rij de
      // cijfers die daadwerkelijk gebruikt zijn, zodat het advies
      // navolgbaar blijft ook al verschilt de formule per rij.
      const basisLabel = r.formule === 'levertijd'
        ? tf('mmBasisLeadTime', {
            leverDagen: r.levertijdDagen,
            bron: t('mmLeadTimeSource_' + r.levertijdBron),
            gemWeek: r.gemiddeldWeekverbruik,
            piekWeek: r.piekWeekverbruik,
          })
        : tf('mmBasisDayStock', {
            dagen: r.dagvoorraadDagen,
            gemDag: r.gemiddeldDagverbruik,
            piekDag: r.piekDagverbruik,
          });
      const houdbaarheidLabel = r.houdbaarheidMaanden !== null ? r.houdbaarheidMaanden + ' ' + t('mmMonths') : t('mmUnknown');

      // Kamers hebben geen eigen minimum
      // meer -- r.huidigMin/r.adviesMin zijn dan 0 resp. null (geen kolom
      // meer, niet "advies is 0"). Toon "n.v.t." i.p.v. een misleidend getal.
      const huidigMinCel = r.isBerging ? r.huidigMin : '<span class="muted">' + t('mmNotApplicable') + '</span>';
      const adviesMinCel = r.isBerging ? '<strong>' + r.adviesMin + '</strong>' : '<span class="muted">' + t('mmNotApplicable') + '</span>';
      const dataMin = r.isBerging ? r.adviesMin : 0;
      html += '<tr><td>' + productCell + '</td><td>' + escapeHtml(r.locatie) + '</td>'
        + '<td>' + huidigMinCel + '</td><td>' + r.huidigMax + '</td>'
        + '<td>' + adviesMinCel + '</td><td><strong>' + r.adviesMax + '</strong></td>'
        + '<td class="mm-basis-cell">' + escapeHtml(basisLabel) + '</td><td>' + houdbaarheidLabel + '</td>'
        + '<td><button class="action-btn mm-apply-btn" data-ean="' + r.ean + '" data-locatie="' + escapeAttr(r.locatie) + '" data-min="' + dataMin + '" data-max="' + r.adviesMax + '">' + t('mmApply') + '</button> '
        // Elke rij die het overzicht
        // haalt, stelt (na de server-side no-op-filter) altijd een echte
        // wijziging voor -- dus hier onvoorwaardelijk ook een "Negeren"-
        // knop, geen extra conditie nodig.
        + '<button class="action-btn mm-negeer-btn" data-ean="' + r.ean + '" data-locatie="' + escapeAttr(r.locatie) + '">' + t('mmNegeer') + '</button></td></tr>';
    });

    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });

  document.querySelectorAll('.mm-apply-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { applyMinMaxRow(this); });
  });
  document.querySelectorAll('.mm-negeer-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { negeerMinMaxRow(this); });
  });
  wireVoorraadTabs();
}

function applyMinMaxRow(btn) {
  const ean = btn.getAttribute('data-ean');
  const locatie = btn.getAttribute('data-locatie');
  const min = parseInt(btn.getAttribute('data-min'), 10);
  const max = parseInt(btn.getAttribute('data-max'), 10);

  btn.disabled = true;
  btn.textContent = t('mmApplying');

  StocuroRun.withSuccessHandler(function (result) {
    if (result.success) {
      btn.textContent = t('mmApplied');
    } else {
      alert(t('mmErrorApplying') + result.error);
      btn.disabled = false;
      btn.textContent = t('mmApply');
    }
  }).withFailureHandler(function (err) {
    alert(t('mmErrorApplying') + err.message);
    btn.disabled = false;
    btn.textContent = t('mmApply');
  }).applyMinMaxAdvies(ean, locatie, min, max);
}

// "negeren" verbergt dit voorstel op
// het overzicht voor MINMAX_NEGEER_MAANDEN (config/constants.php, 3
// maanden) -- de rij wordt simpelweg uit de DOM verwijderd i.p.v. het
// hele overzicht opnieuw op te halen, dat voelt directer aan.
function negeerMinMaxRow(btn) {
  const ean = btn.getAttribute('data-ean');
  const locatie = btn.getAttribute('data-locatie');

  btn.disabled = true;
  StocuroRun.withSuccessHandler(function (result) {
    if (result.success) {
      const row = btn.closest('tr');
      if (row) row.remove();
    } else {
      alert(t('mmErrorApplying') + (result.error || ''));
      btn.disabled = false;
    }
  }).withFailureHandler(function (err) {
    alert(t('mmErrorApplying') + err.message);
    btn.disabled = false;
  }).negeerMinMaxAdvies('negeren', ean, locatie);
}

// =================================================================
// VERPLAATSADVIES -- overdatum-risico op de huidige locatie ->
// aanbevolen snellere locatie (includes/verplaatsadvies.php). Puur
// informatief, geen "toepassen"-knop: een verplaatsing gebeurt fysiek
// via het bestaande Verplaatsing-formulier (zie backend-bestandskop).
// =================================================================
function showVerplaatsAdvies() {
  setRoute('verplaatsadvies');
  currentPage = 'verplaatsadvies';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderVerplaatsAdvies).getVerplaatsAdviesOverzicht();
}

function renderVerplaatsAdvies(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('transferAdvice') + '</h2>';
  html += '<p class="form-hint">' + t('vaHint') + '</p>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('vaEmpty') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colLot') + '</th><th>' + t('colLocation') + '</th>'
      + '<th>' + t('colUnits') + '</th><th>' + t('colExpiryDate') + '</th><th>' + t('vaDaysLeft') + '</th>'
      + '<th>' + t('vaCurrentUsage') + '</th><th>' + t('vaRecommendedLocation') + '</th></tr>';

    data.forEach(function (r) {
      const productCell = '<a class="product-link" data-ean="' + r.ean + '">' + escapeHtml(r.product) + '</a>';
      const huidigUsageCell = r.huidigeSnelheidStatus === 'ok'
        ? tf('vaPerDay', { n: r.huidigDagverbruik })
        : '<em>' + t('vaInsufficientData') + '</em>';
      const aanbevolenCell = r.aanbevolenLocatie
        ? '<strong>' + escapeHtml(r.aanbevolenLocatie) + '</strong> (' + tf('vaPerDay', { n: r.aanbevolenDagverbruik }) + ')'
        : '<em>' + t('vaNoFasterLocation') + '</em>';

      html += '<tr class="' + statusRowClass('SPOEDGEBRUIK') + '"><td>' + productCell + '</td><td>' + escapeHtml(r.lot || '') + '</td>'
        + '<td>' + escapeHtml(r.huidigeLocatie) + '</td><td>' + r.huidig + '</td>'
        + '<td>' + r.vervaldatum + '</td><td><strong>' + r.daysLeft + '</strong></td>'
        + '<td>' + huidigUsageCell + '</td><td>' + aanbevolenCell + '</td></tr>';
    });

    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
}

// =================================================================
// FOUTLOG - mislukte scans zichtbaar zonder de sheet te openen
// =================================================================
function showFoutlog() {
  setRoute('foutlog');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderFoutlog).getFoutlog();
}

function renderFoutlog(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('errorLog') + '</h2>';
  if (data.length === 0) {
    html += '<p class="empty">' + t('flNone') + '</p>';
  } else {
    html += '<p style="color:#999; font-size:13px;">' + tf('flHint', { n: data.length }) + '</p>';
    html += '<table><tr><th>' + t('colDate') + '</th><th>' + t('colTime') + '</th><th>' + t('colErrorType') + '</th><th>' + t('colBarcodeCol') + '</th><th>' + t('colMessage') + '</th></tr>';
    data.forEach(item => {
      html += '<tr><td>' + escapeHtml(item.datum) + '</td><td>' + escapeHtml(item.tijd) + '</td><td>' + statusBadgeHtml(escapeHtml(item.type)) + '</td><td style="font-family:monospace;">' + escapeHtml(item.barcode) + '</td><td>' + escapeHtml(foutlogBerichtLabel(item.bericht)) + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
}

// -----------------------------------------------------------------
// "Onbekende Producten": barcodes die tijdens een scan
// niet herkend werden, met de omschrijving die de scanner ervoor heeft
// ingevuld -- zie includes/onbekende_scans.php. "Afronden" gebeurt door
// het artikel daadwerkelijk aan te maken (Artikel Toevoegen, EAN
// vooringevuld); addArtikel() meldt dat succes vanzelf terug bij
// api/onbekende_scans.php, dus hier geen aparte "markeer als
// afgehandeld"-knop nodig.
// -----------------------------------------------------------------
function showOnbekendeProducten() {
  setRoute('onbekende-producten');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderOnbekendeProducten).getOnbekendeScans();
}

function renderOnbekendeProducten(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('unknownProducts') + '</h2>';
  html += '<p class="form-hint">' + t('opHint') + '</p>';
  if (data.length === 0) {
    html += '<p class="empty">' + t('opNone') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colEan') + '</th><th>' + t('onbOmschrijvingen') + '</th><th>' + t('colLocation') + '</th><th>' + t('tkKeerGezien') + '</th><th>' + t('opLastSeen') + '</th><th></th></tr>';
    data.forEach(function (item) {
      // Sinds onbekende regels meteen
      // een echt artikel krijgen (zie maakArtikelenVoorOnbekendeRegels()
      // in includes/transacties.php), bestaat dat artikel hier vrijwel
      // altijd al -- heeftArtikel bepaalt of de knop naar BEWERKEN van dat
      // bestaande artikel gaat (het normale geval) of, als vangnet voor
      // een oudere melding van vóór deze migratie, naar het
      // aanmaakformulier.
      const knopLabel = item.heeftArtikel ? t('opAfronden') : t('opMaakArtikel');
      const knopClass = item.heeftArtikel ? 'op-bewerk-btn' : 'op-maak-artikel-btn';
      html += '<tr><td><code>' + escapeHtml(item.ean) + '</code></td><td>' + escapeHtml(item.omschrijvingen.join('; ') || '-') + '</td>'
        + '<td>' + escapeHtml(item.locaties.join(', ') || '-') + '</td><td>' + item.aantalKeerGezien + '</td>'
        + '<td>' + escapeHtml(String(item.laatstGezien).substring(0, 16)) + '</td>'
        + '<td><button class="action-btn ' + knopClass + '" data-ean="' + escapeAttr(item.ean) + '">' + knopLabel + '</button></td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.op-bewerk-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      // Dit product bestaat al (met de omschrijving als
      // tijdelijke naam) -- rechtstreeks naar het bewerkscherm, met
      // vanuitOnbekendeProducten=true zodat na opslaan weer hierheen
      // teruggekeerd wordt (er staan vaak meerdere te wachten).
      showProductEdit(this.getAttribute('data-ean'), true);
    });
  });
  document.querySelectorAll('.op-maak-artikel-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      // Vangnet voor een oudere openstaande melding waarvoor er
      // (uitzonderlijk) nog geen artikel bestaat -- zelfde
      // aanmaakformulier-route als voorheen.
      showArtikelToevoegen(this.getAttribute('data-ean'), null, null, true);
    });
  });
}

function openBestellingModal(ean, naam, aantal) {
  document.getElementById('modalEan').value = ean;
  document.getElementById('modalProduct').value = naam;
  document.getElementById('modalAantal').value = aantal;
  document.getElementById('modalKanaal').value = 'OBZ';
  document.getElementById('modalOpmerking').value = '';
  // "Besteld door" is geen vrij veld
  // meer -- de server zet dit altijd op de ingelogde gebruiker (zie
  // api/bestelling.php), dezelfde aanpak als bij de eenmalige bestelling
  // (renderEenmaligeBestellingForm()). Alleen nog een informatieve regel.
  const hintEl = document.getElementById('modalBestellerHint');
  if (hintEl) hintEl.textContent = tf('oeOrderedByHint', { naam: (window.CURRENT_USER && window.CURRENT_USER.naam) || '-' });
  document.getElementById('bestelModal').classList.add('show');
}

function submitBestelling() {
  const ean = document.getElementById('modalEan').value;
  const aantal = document.getElementById('modalAantal').value;
  const kanaal = document.getElementById('modalKanaal').value;
  const opmerking = document.getElementById('modalOpmerking').value;

  StocuroRun.withSuccessHandler(function(result) {
    if (result.success) {
      alert(result.message);
      document.getElementById('bestelModal').classList.remove('show');
      // ShowBestellers() was hier het verweesde,
      // pre-consolidatie scherm -- zie de toelichting bij showBestellers()
      // hierboven.
      showBestellingen();
    } else {
      alert(t('omErrorPrefix') + result.error);
    }
  }).submitHandmatigeBestellingen(ean, aantal, kanaal, opmerking);
}

function escapeHtml(text) {
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

function escapeAttr(text) {
  return String(text).replace(/"/g, '&quot;');
}

// Modal event listeners
document.getElementById('modalCloseBtn').addEventListener('click', function() {
  document.getElementById('bestelModal').classList.remove('show');
});
document.getElementById('modalCancelBtn').addEventListener('click', function() {
  document.getElementById('bestelModal').classList.remove('show');
});
document.getElementById('modalSaveBtn').addEventListener('click', submitBestelling);

document.getElementById('photoModalCloseBtn').addEventListener('click', closePhotoModal);
document.getElementById('photoModal').addEventListener('click', function(e) {
  if (e.target === this) closePhotoModal();
});

// =======================================================================
// NIEUW t.o.v. de oude Apps Script-versie: invoerformulieren die de vier
// Google Forms vervangen (migratieplan hoofdstuk 6), plus gebruikersbeheer
// (hoofdstuk 9). Deze roepen de nieuwe /api-endpoints rechtstreeks aan
// met fetch() -- geen StocuroRun-shim nodig, want dit zijn geen
// functies die in de oude Apps Script-versie bestonden.
// =======================================================================

// ("Alleen JPG, PNG of WEBP-afbeeldingen
// zijn toegestaan" bleef Nederlands in de foutmelding): PHP-endpoints
// hebben geen begrip van CURRENT_LANG en geven foutmeldingen dus altijd in
// het Nederlands terug (zie bijv. includes/upload.php::decodeFotoDataUrl()).
// De meeste van die meldingen zijn dynamisch (PDO/exception-tekst, met
// namen/aantallen erin) en dus niet vooraf te vertalen, maar een paar
// vaste, veelgebruikte validatiemeldingen wél -- die worden hier vertaald
// vlak voordat een apiPost()-foutcallback ze te zien krijgt, zodat alle
// bestaande onError-handlers (alert(err.error)/errorEl.textContent =
// err.error) ongewijzigd kunnen blijven. Onbekende/dynamische meldingen
// vallen gewoon terug op de ruwe (Nederlandse) tekst.
var API_ERROR_LABEL_KEYS = {
  'Alleen JPG, PNG of WEBP-afbeeldingen zijn toegestaan': 'apiErrAfbeeldingType',
  'Geen geldige afbeelding ontvangen': 'apiErrAfbeeldingOngeldig',
  'Kon de afbeelding niet lezen': 'apiErrAfbeeldingOnleesbaar',
  'Bestand is geen geldige afbeelding': 'apiErrAfbeeldingGeenAfbeelding',
};
function apiErrorLabel(raw) {
  var key = API_ERROR_LABEL_KEYS[String(raw || '')];
  return key ? t(key) : raw;
}

function apiPost(url, body, onSuccess, onError) {
  fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
    .then(function (r) {
      if (r.status === 401) { window.location.href = 'login.php'; throw new Error('Not logged in'); }
      // Bugfix: dit las voorheen altijd meteen r.json(), wat bij
      // een PHP fatal error (lege response-body, geen JSON) de rauwe, cryptische
      // browserfout ("Unexpected end of JSON input") liet doorsijpelen i.p.v.
      // een bruikbare melding -- zelfde probleem, en zelfde oplossing, als de
      // r.text()-aanpak hierboven in callApi(). Eerst de body als tekst lezen,
      // dan pas proberen te parsen als JSON, zodat we bij een lege/HTML-body
      // een duidelijke fout kunnen tonen i.p.v. de kale browsermelding.
      return r.text().then(function (bodyText) {
        let data;
        try {
          data = bodyText ? JSON.parse(bodyText) : {};
        } catch (e) {
          throw new Error('Invalid JSON response from ' + url + (bodyText ? ' -- ' + bodyText.slice(0, 300) : ' (empty response -- check the PHP error log)'));
        }
        return { ok: r.ok, data: data };
      });
    })
    .then(function (res) {
      if (res.ok) { if (onSuccess) onSuccess(res.data); }
      else {
        // Vertaal een bekende, vaste backend-foutmelding (zie
        // API_ERROR_LABEL_KEYS/apiErrorLabel() hierboven) vlak voordat de
        // ~20 bestaande onError-handlers (alert(err.error)/...) hem te zien
        // krijgen -- onbekende/dynamische meldingen blijven ongewijzigd.
        if (onError) {
          var errData = res.data;
          if (errData && typeof errData === 'object' && errData.error) {
            errData = Object.assign({}, errData, { error: apiErrorLabel(errData.error) });
          }
          onError(errData);
        }
      }
    })
    .catch(function (err) { if (onError) onError({ error: err.message }); });
}

/**
 * Als een scanformulier onbekende
 * barcodes tegenkomt, wordt er NIETS geboekt (blokkerend, alles-of-niets)
 * totdat er per onbekende regel een korte omschrijving is ingevuld -- zie
 * splitsBekendOnbekend()/ontbrekendeOmschrijvingen() in
 * includes/transacties.php. Deze functie zet, in een leeg containerdiv
 * dat elk scanformulier al in zijn HTML heeft staan (vlak boven de
 * submit-knop), één tekstveld per onbekende regel neer -- de rest van
 * het al ingevulde formulier (locatie/specialist/opmerking/...) blijft
 * gewoon staan. Bij "Opnieuw indienen" roept dit $onIndienen aan met een
 * map ruwe regel -> omschrijving, die de aanroeper samen met de rest van
 * de formuliervelden opnieuw naar de server stuurt.
 */
function toonOnbekendeBarcodesPaneel(containerId, onbekendeRegels, onIndienen) {
  const container = document.getElementById(containerId);
  if (!container) return;

  let html = '<div class="login-error" style="display:block; background:#fff8e1; border-color:#f5a623; color:#333;">';
  html += '<strong>' + t('onbTitel') + '</strong><p>' + t('onbUitleg') + '</p>';
  onbekendeRegels.forEach(function (regel, i) {
    const kort = regel.length > 60 ? regel.substring(0, 60) + '...' : regel;
    html += '<div class="form-group"><label><code>' + escapeHtml(kort) + '</code></label>';
    html += '<input type="text" class="onb-omschrijving-input" data-regel="' + escapeAttr(regel) + '" placeholder="' + escapeAttr(t('onbPlaceholder')) + '"></div>';
  });
  html += '<button type="button" class="edit-save" id="onbIndienBtn">' + t('onbIndienen') + '</button>';
  html += '</div>';
  container.innerHTML = html;

  document.getElementById('onbIndienBtn').addEventListener('click', function () {
    const omschrijvingen = {};
    document.querySelectorAll('.onb-omschrijving-input').forEach(function (inp) {
      omschrijvingen[inp.getAttribute('data-regel')] = inp.value;
    });
    onIndienen(omschrijvingen);
  });
}

// =====================================================================
// Live scanregel-compleetheidscheck (na
// een gemeld praktijkprobleem): verschillende leveranciers/verpakkings-
// niveaus in omloop betekent dat een product soms één combinatiebarcode
// heeft (GTIN + batch + vervaldatum in één) en soms twee losse (GTIN apart
// van batch/vervaldatum). Een laborant moet dat onderscheid nooit zelf
// hoeven te herkennen -- dus onderschept dit stukje JS de Enter die een
// barcodescanner na elke scan automatisch stuurt, en beslist zelf of de
// zojuist gescande regel al compleet genoeg is om als los product te
// tellen, of dat er nog een tweede fysieke scan (de batch-/
// vervaldatumcode) bij deze regel hoort.
// =====================================================================

/**
 * Lichte, client-side benadering van de AI-herkenning in parseGS1Raw()
 * (includes/gs1_parser.php) -- puur voor deze live hint, GEEN vervanging
 * van de server-side parsing die uiteindelijk bepaalt wat geboekt wordt.
 * Geeft true terug (niet blokkeren) zodra de regel geen herkenbare GTIN
 * (AI 01) bevat -- dan is dit waarschijnlijk sowieso geen GS1-code (bv.
 * een losse logistiek_code) en is er niets om op te wachten. Geeft anders
 * pas true terug zodra er ook een batch-/vervaldatumaanduiding (AI 17, 10
 * of 21) in dezelfde regel te herkennen is.
 */
function gs1RegelIsCompleet(regel) {
  const tekst = (regel || '').trim();
  if (tekst === '') return true;
  const gtinMatch = tekst.match(/01\d{14}/);
  if (!gtinMatch) return true;
  // Het GTIN-blok zelf (14 cijfers) weer uit de tekst snijden vóór er op
  // batch-/vervaldatummarkers wordt gezocht -- anders kan een toevallige
  // "10"/"17"/"21" die gewoon ONDERDEEL is van de GTIN-cijfers zelf ten
  // onrechte als aparte AI-marker worden aangezien (bv. een GTIN die met
  // "...10..." begint zou anders al als "heeft batch" tellen).
  const restant = tekst.slice(0, gtinMatch.index) + tekst.slice(gtinMatch.index + gtinMatch[0].length);
  return /17\d{6}/.test(restant) || /10[0-9A-Za-z]/.test(restant) || /21[0-9A-Za-z]/.test(restant);
}

/**
 * Koppelt het "Enter onderdrukken bij onvolledige regel"-gedrag aan een
 * multi-line GS1-scanveld. Zolang de laatst gescande regel een GTIN zonder
 * batch/vervaldatum bevat, wordt de Enter die de scanner erna stuurt
 * onderdrukt -- de cursor blijft aan het eind van de regel staan, zodat
 * een tweede fysieke barcode zich vastplakt aan dezelfde regel in plaats
 * van een nieuwe regel te beginnen. Een zichtbare hint verschijnt zolang
 * dat geldt, met een expliciete "toch doorgaan"-knop voor de uitzondering
 * dat die code echt niet beschikbaar is (beschadigd/onleesbaar label) --
 * dan wordt er alsnog een regeleinde ingevoegd, zodat niemand vastloopt.
 * Zo'n regel verdwijnt niet spoorloos: hij duikt op bij de Laborant-taken
 * als "Ontbrekende batch-/vervaldatumgegevens", zie
 * includes/taken.php::getOntbrekendeBatchTaken().
 */
function initGs1ScanHint(textareaId, hintId) {
  const el = document.getElementById(textareaId);
  const hintEl = document.getElementById(hintId);
  if (!el || !hintEl) return;

  function verbergHint() {
    hintEl.style.display = 'none';
  }

  function toonHint() {
    hintEl.innerHTML = '⚠️ ' + escapeHtml(t('gs1HintNogBatch'))
      + ' <button type="button" class="gs1-hint-override">' + escapeHtml(t('gs1HintDoorgaan')) + '</button>';
    hintEl.style.display = 'block';
    const overrideBtn = hintEl.querySelector('.gs1-hint-override');
    if (overrideBtn) {
      overrideBtn.addEventListener('click', function () {
        el.value += '\n';
        el.focus();
        verbergHint();
      });
    }
  }

  el.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    const totCursor = el.value.substring(0, el.selectionStart);
    const laatsteNewline = totCursor.lastIndexOf('\n');
    const huidigeRegel = totCursor.substring(laatsteNewline + 1);

    if (!gs1RegelIsCompleet(huidigeRegel)) {
      e.preventDefault();
      toonHint();
    } else {
      verbergHint();
    }
  });

  // De hint hoort alleen te tonen vlak na een onderdrukte Enter -- zodra de
  // gebruiker zelf weer typt/plakt/wist (bijvoorbeeld omdat de tweede scan
  // zich al aan het vastplakken is), verbergen we 'm weer.
  el.addEventListener('input', verbergHint);
}

// =====================================================================
// CODE128 (subset B) barcode-generator: een eigen, kleine implementatie
// i.p.v. een externe library --
// dit is een self-hosted app zonder build-stap of CDN-afhankelijkheid,
// dus alles zit in dit ene bestand. Dekt printbare ASCII (spatie t/m ~,
// code 32-126), ruim genoeg voor een handmatig ingevoerde interne
// logistiekcode. Code128 i.p.v. EAN-13 gekozen omdat het willekeurige
// alfanumerieke tekst aankan zonder vast-cijferformaat/checkdigit-eisen,
// en niet per ongeluk kan botsen met een echte GS1-productcode.
//
// LET OP: dit is een handmatig overgetypte, gestandaardiseerde
// breedtepatronentabel (ISO/IEC 15417) -- getest op interne consistentie
// (elk patroon telt op tot 11 modules, het stop-patroon tot 13), maar
// controleer een geprint label altijd één keer met een echte
// scanner voordat je hierop vertrouwt voor dagelijks gebruik.
// =====================================================================
const CODE128_PATTERNS = [
  '212222', '222122', '222221', '121223', '121322', '131222',
  '122213', '122312', '132212', '221213', '221312', '231212',
  '112232', '122132', '122231', '113222', '123122', '123221',
  '223211', '221132', '221231', '213212', '223112', '312131',
  '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321',
  '112313', '132113', '132311', '211313', '231113', '231311',
  '112133', '112331', '132131', '113123', '113321', '133121',
  '313121', '211331', '231131', '213113', '213311', '213131',
  '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124',
  '121421', '141122', '141221', '112214', '112412', '122114',
  '122411', '142112', '142211', '241211', '221114', '413111',
  '241112', '134111', '111242', '121142', '121241', '114212',
  '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113',
  '114311', '411113', '411311', '113141', '114131', '311141',
  '411131', '211412', '211214', '211232', '2331112',
];
const CODE128_START_B = 104;
const CODE128_STOP = 106;

/** @return string[]|null array van breedtepatronen, of null bij een niet-encodeerbaar teken. */
function code128BEncode(tekst) {
  const values = [];
  for (const ch of String(tekst)) {
    const code = ch.charCodeAt(0);
    if (code < 32 || code > 126) return null;
    values.push(code - 32);
  }
  if (values.length === 0) return null;

  let checksum = CODE128_START_B;
  values.forEach(function (v, i) { checksum += v * (i + 1); });
  checksum = checksum % 103;

  return [CODE128_START_B].concat(values, [checksum, CODE128_STOP]).map(function (v) { return CODE128_PATTERNS[v]; });
}

/** SVG-staafcode (vector, printvriendelijk), met quiet zone aan weerszijden zoals de norm aanraadt. */
function code128Svg(tekst, opts) {
  const patterns = code128BEncode(tekst);
  if (!patterns) return null;
  const unit = (opts && opts.unit) || 2;
  const height = (opts && opts.height) || 60;
  const quiet = unit * 10;

  let totalUnits = 0;
  patterns.forEach(function (p) { for (const d of p) totalUnits += parseInt(d, 10); });

  const width = quiet * 2 + totalUnits * unit;
  let x = quiet;
  let bar = true; // Code128 begint altijd met een staaf.
  let rects = '';
  patterns.forEach(function (pattern) {
    for (const d of pattern) {
      const w = parseInt(d, 10) * unit;
      if (bar) rects += '<rect x="' + x + '" y="0" width="' + w + '" height="' + height + '" fill="#000"></rect>';
      x += w;
      bar = !bar;
    }
  });

  return '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">' + rects + '</svg>';
}

// Profielfoto-uploads (users + producent_contacten): leest
// een gekozen bestand clientside in als data-URL (FileReader), zodat het
// als gewone JSON-string meekan in een apiPost()-body -- zie
// includes/upload.php voor de serverkant. Max 2MB, zelfde grens als
// server-side (client-side check is puur voor snelle feedback, de server
// controleert dit sowieso nogmaals).
const FOTO_MAX_BYTES_CLIENT = 2 * 1024 * 1024;

function readImageFileAsDataUrl(file, onLoad, onError) {
  if (!file) return;
  if (!file.type || file.type.indexOf('image/') !== 0) {
    if (onError) onError(t('fotoInvalidType'));
    return;
  }
  if (file.size > FOTO_MAX_BYTES_CLIENT) {
    if (onError) onError(t('fotoTooBig'));
    return;
  }
  const reader = new FileReader();
  reader.onload = function () { onLoad(reader.result); };
  reader.onerror = function () { if (onError) onError(t('somethingWrong')); };
  reader.readAsDataURL(file);
}

// Locatienamen zijn instelbaar (api/locaties.php) -- geen
// hardgecodeerde constante meer. fetchLocaties() haalt de actuele rijen
// op ([{slot, naam, volgorde, actief}]), de twee helpers eronder filteren
// tot de namenlijst die eerder LOCATIES/LOCATIES_BESTELLING was.
function fetchLocaties(callback) {
  StocuroRun.withSuccessHandler(callback).getLocaties();
}

function actieveLocatieNamen(locRows) {
  return locRows.filter(function (r) { return r.actief == 1; })
    .sort(function (a, b) { return a.volgorde - b.volgorde; })
    .map(function (r) { return r.naam; });
}

function actieveLocatieNamenBestelling(locRows) {
  return locRows.filter(function (r) { return r.actief == 1 && r.slot !== 'berging'; })
    .sort(function (a, b) { return a.volgorde - b.volgorde; })
    .map(function (r) { return r.naam; });
}

function locatieOptionsHtml(locaties, selected) {
  return locaties.map(function (l) {
    return '<option value="' + escapeAttr(l) + '"' + (l === selected ? ' selected' : '') + '>' + escapeHtml(l) + '</option>';
  }).join('');
}

// items: [{id, naam, actief}] uit getSpecialisten()/getProcedureTypen() --
// alleen actieve items in de keuzelijst, inactieve blijven wel selecteerbaar
// vanuit oudere/historische context maar staan niet meer als optie klaar.
function actieveNaamOptionsHtml(items) {
  return items.filter(function (x) { return x.actief == 1; }).map(function (x) {
    return '<option value="' + escapeAttr(x.naam) + '">' + escapeHtml(x.naam) + '</option>';
  }).join('');
}

// Specialist/laborant-keuzelijst (getSpecialisten()) voor de <select>-
// gebaseerde formulieren (Eenmalige Bestelling aanmaken/bewerken, en de
// inline specialist-correctie op Recente Transacties) -- vanuit het
// licentiemodel (geen naam-registratie zonder licentie) toont dit
// een naam zonder geldige licentie nog wel (transparant over wie er is),
// maar als disabled option met een duidelijke reden erbij, zodat hij niet
// gekozen kan worden. `huidig` (optioneel) houdt, net als
// actieveNaamOptionsHtml()'s inactief-uitzondering, een reeds gekozen
// naam altijd zichtbaar+selecteerbaar, ook als die naam inmiddels niet
// meer actief is of geen geldige licentie meer heeft -- alleen het
// actief KIEZEN van een andere, geblokkeerde naam wordt voorkomen. De
// echte afdwinging gebeurt hoe dan ook server-side.
function specialistOptionsHtml(items, huidig) {
  return items.filter(function (x) { return x.actief == 1 || x.naam === huidig; }).map(function (x) {
    const isHuidig = x.naam === huidig;
    const geblokkeerd = x.licentie_geblokkeerd == 1 && !isHuidig;
    const selected = isHuidig ? ' selected' : '';
    if (geblokkeerd) {
      return '<option value="' + escapeAttr(x.naam) + '" disabled>' + escapeHtml(x.naam) + ' (' + escapeHtml(t('guLicentieGeblokkeerd')) + ')</option>';
    }
    return '<option value="' + escapeAttr(x.naam) + '"' + selected + '>' + escapeHtml(x.naam) + '</option>';
  }).join('');
}

// Klikvriendelijke keuzeknoppen i.p.v. dropdowns voor de scanformulieren
// (Ontvangst/Verbruik/Vervallen Afboeken/Verplaatsing+Retournering) --
// : dit zijn de formulieren die het vaakst en vaak
// onder tijdsdruk worden ingevuld, dus één tik i.p.v. dropdown-openen
// + kiezen (twee acties) scheelt tijd en fouten. Eenmalige Bestelling
// blijft bewust bij dropdowns (komt zelden voor, buiten scope).
// Bewaart de gekozen waarde in een verborgen <input id="{id}">, zodat
// bestaand `document.getElementById(id).value`-uitlezen bij submit
// ongewijzigd werkt -- alleen rendering en klik-afhandeling zijn nieuw.
// `disabledMap` is optioneel: een object van optie-waarde naar reden-tekst
// (bv. "Geen geldige licentie") -- ("Licentiemodel:
// geen naam-registratie zonder licentie") voor de specialist-keuzeknoppen
// op Materiaal Verbruik. Zo'n knop krijgt het echte disabled-attribuut
// (de browser blokkeert clicks daarop vanzelf, geen aparte JS-check nodig
// in bindKeuzeknoppen() hieronder) plus een zichtbaar label waarom.
function keuzeknoppenHtml(id, opties, geselecteerd, disabledMap) {
  let html = '<input type="hidden" id="' + id + '" value="' + escapeAttr(geselecteerd || '') + '">';
  html += '<div class="keuzeknoppen" id="' + id + 'Knoppen">';
  opties.forEach(function (opt) {
    const actief = opt === geselecteerd ? ' active' : '';
    const reden = disabledMap && disabledMap[opt];
    if (reden) {
      html += '<button type="button" class="keuzeknop keuzeknop-disabled" disabled title="' + escapeAttr(reden) + '" data-waarde="' + escapeAttr(opt) + '">' + escapeHtml(opt) + ' <span class="keuzeknop-badge">(' + escapeHtml(reden) + ')</span></button>';
    } else {
      html += '<button type="button" class="keuzeknop' + actief + '" data-waarde="' + escapeAttr(opt) + '">' + escapeHtml(opt) + '</button>';
    }
  });
  if (opties.length === 0) {
    html += '<span class="keuzeknoppen-leeg">' + t('kkGeenOpties') + '</span>';
  }
  html += '</div>';
  return html;
}

// Koppelt de klik-handlers voor een door keuzeknoppenHtml() gerenderde
// groep. Aanroepen nadat de HTML in de DOM staat (zelfde volgorde als
// addEventListener-aanroepen elders in dit bestand).
function bindKeuzeknoppen(id, onChange) {
  const container = document.getElementById(id + 'Knoppen');
  if (!container) return;
  container.querySelectorAll('.keuzeknop').forEach(function (btn) {
    btn.addEventListener('click', function () {
      container.querySelectorAll('.keuzeknop').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      document.getElementById(id).value = this.getAttribute('data-waarde');
      if (onChange) onChange(this.getAttribute('data-waarde'));
    });
  });
}

// -----------------------------------------------------------------
// Ontvangst scannen
// -----------------------------------------------------------------
function showScanOntvangst() {
  setRoute('scan-ontvangst');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetchLocaties(renderScanOntvangstForm);
}

function renderScanOntvangstForm(locRows) {
  const locaties = actieveLocatieNamen(locRows);
  let html = '<h2>' + t('receiveStock') + '</h2>';
  html += '<p class="form-hint">' + t('roHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('roLocation') + '</label>' + keuzeknoppenHtml('ontvangstLocatie', locaties, locaties[0]) + '</div>';
  html += '<div class="form-group"><label>' + t('roScans') + '</label><textarea id="ontvangstGs1" rows="8" placeholder="Scan hier..."></textarea><div id="ontvangstGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div class="form-group"><label>' + t('roNote') + '</label><textarea id="ontvangstOpmerking" rows="2"></textarea></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="ontvangstSubmit">' + t('roSave') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  bindKeuzeknoppen('ontvangstLocatie');
  initGs1ScanHint('ontvangstGs1', 'ontvangstGs1Hint');

  const btn = document.getElementById('ontvangstSubmit');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    apiPost('api/scan_ontvangst.php', {
      locatie: document.getElementById('ontvangstLocatie').value,
      gs1_raw: document.getElementById('ontvangstGs1').value,
      opmerking: document.getElementById('ontvangstOpmerking').value,
      omschrijvingen: omschrijvingen || {},
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      alert(tf('roProcessed', { n: result.verwerkt, m: result.overgeslagen }));
      // : na een geslaagde actie op deze pagina blijven
      // i.p.v. terug naar Home te springen (dat voelde alsof je "telkens
      // naar de homepage gaat") -- het formulier wordt in plaats daarvan
      // leeggemaakt voor een volgende scan, met de gekozen locatie
      // ongewijzigd (die blijft voor opeenvolgende scans meestal hetzelfde).
      btn.disabled = false;
      document.getElementById('ontvangstGs1').value = '';
      document.getElementById('ontvangstOpmerking').value = '';
      document.getElementById('onbekendPaneel').innerHTML = '';
      document.getElementById('ontvangstGs1').focus();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

// -----------------------------------------------------------------
// Verbruik scannen
// -----------------------------------------------------------------
function showScanVerbruik() {
  setRoute('scan-verbruik');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (specialisten) {
    StocuroRun.withSuccessHandler(function (procedures) {
      fetchLocaties(function (locRows) {
        renderScanVerbruikForm(specialisten, procedures, locRows);
      });
    }).getProcedureTypen();
  }).getSpecialisten();
}

function renderScanVerbruikForm(specialisten, procedures, locRows) {
  const actieveSpecialisten = specialisten.filter(function (s) { return s.actief == 1; });
  const actieveProcedures = procedures.filter(function (p) { return p.actief == 1; });
  const locNamen = actieveLocatieNamen(locRows);
  const procedureNamen = actieveProcedures.map(function (p) { return p.naam; });

  // PICC-regel : welke namen als
  // uitvoerder ("specialist"-veld) gekozen mogen worden hangt af van de
  // GEKOZEN procedure, niet van wie er is ingelogd -- de ingelogde
  // gebruiker die dit formulier indient is niet per se degene die de
  // procedure heeft uitgevoerd. Een Laborant mag alleen als uitvoerder
  // gekozen worden bij een procedure die daarvoor is aangevinkt
  // (procedure_typen.laborant_toegestaan, in de praktijk PICC-lijn); bij
  // elke andere procedure alleen een Specialist. Dit is puur UX-filtering
  // (client-side) -- de eigenlijke afdwinging gebeurt server-side in
  // includes/transacties.php::verwerkScan().
  function specialistNamenVoorProcedure(procedureNaam) {
    const procedure = actieveProcedures.find(function (p) { return p.naam === procedureNaam; });
    const magLaborant = procedure ? !!procedure.laborant_toegestaan : true;
    return actieveSpecialisten
      .filter(function (s) { return magLaborant || s.type === 'specialist'; })
      .map(function (s) { return s.naam; });
  }

  // Licentiecontrole ("Licentiemodel: geen naam-
  // registratie zonder licentie"): naam -> reden-tekst voor elke
  // specialist/laborant zonder geldige licentie, meegegeven aan
  // keuzeknoppenHtml() zodat die naam wel zichtbaar blijft in de lijst
  // (transparant over wie er is) maar niet klikbaar is. De echte
  // afdwinging gebeurt hoe dan ook server-side in
  // includes/transacties.php::verwerkScan().
  function specialistLicentieDisabledMap() {
    const map = {};
    actieveSpecialisten.forEach(function (s) {
      if (s.licentie_geblokkeerd == 1) map[s.naam] = t('guLicentieGeblokkeerd');
    });
    return map;
  }

  let html = '<h2>' + t('scanUsage') + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  if (actieveSpecialisten.length === 0 || actieveProcedures.length === 0) {
    html += '<p class="form-hint">' + t('suNotEnough') + '</p>';
  }
  html += '<div class="form-group"><label>' + t('suLocationUsed') + '</label>' + keuzeknoppenHtml('verbruikLocatie', locNamen, locNamen[0]) + '</div>';
  html += '<div class="form-group"><label>' + t('suProcedure') + '</label>' + keuzeknoppenHtml('verbruikProcedure', procedureNamen, '') + '</div>';
  html += '<div class="form-group"><label>' + t('suSpecialist') + '</label><div id="verbruikSpecialistWrap">' + keuzeknoppenHtml('verbruikSpecialist', specialistNamenVoorProcedure(''), '', specialistLicentieDisabledMap()) + '</div><p class="form-hint">' + t('suPiccHint') + '</p></div>';
  html += '<div class="form-group"><label>' + t('suScans') + '</label><textarea id="verbruikGs1" rows="8" placeholder="Scan hier..."></textarea><div id="verbruikGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div class="form-group"><label>' + t('suNote') + '</label><textarea id="verbruikOpmerking" rows="2"></textarea></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="verbruikSubmit">' + t('suRegister') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  bindKeuzeknoppen('verbruikLocatie');
  bindKeuzeknoppen('verbruikSpecialist');
  initGs1ScanHint('verbruikGs1', 'verbruikGs1Hint');
  // Procedure staat nu vóór Specialist in het formulier: specialist is
  // afhankelijk van de gekozen procedure, dus dat
  // moet je eerst kiezen) -- deze handler filtert de uitvoerderslijst
  // opnieuw zodra de procedure verandert.
  bindKeuzeknoppen('verbruikProcedure', function (gekozenProcedure) {
    // Uitvoerder-selectie opnieuw renderen, gefilterd op de net gekozen
    // procedure -- een eerder gekozen naam die niet meer mag (bijv. een
    // Laborant nadat je terugschakelt naar een gewone procedure) wordt
    // daarbij niet automatisch overgenomen; de gebruiker moet dan
    // opnieuw kiezen.
    document.getElementById('verbruikSpecialistWrap').innerHTML = keuzeknoppenHtml('verbruikSpecialist', specialistNamenVoorProcedure(gekozenProcedure), '', specialistLicentieDisabledMap());
    bindKeuzeknoppen('verbruikSpecialist');
  });

  const btn = document.getElementById('verbruikSubmit');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    apiPost('api/scan_verbruik.php', {
      locatie: document.getElementById('verbruikLocatie').value,
      specialist: document.getElementById('verbruikSpecialist').value,
      procedure_type: document.getElementById('verbruikProcedure').value,
      gs1_raw: document.getElementById('verbruikGs1').value,
      opmerking: document.getElementById('verbruikOpmerking').value,
      omschrijvingen: omschrijvingen || {},
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      // "Slimme ruimte": als een product geen voorraad had in de
      // gescande ruimte maar wel ondubbelzinnig in precies één andere
      // ruimte, is het daar
      // automatisch afgeschreven (zie verwerkScan() in
      // includes/transacties.php). Dat melden we hier expliciet, zodat
      // het geen verrassing is bij het navragen in Recente Transacties.
      let melding = tf('roProcessed', { n: result.verwerkt, m: result.overgeslagen });
      if (result.omgeleid && result.omgeleid.length > 0) {
        melding += '\n\n' + t('suOmgeleidTitel') + '\n' + result.omgeleid.map(function (r) {
          return tf('suOmgeleidRegel', { naam: r.naam, van: r.van, naar: r.naar });
        }).join('\n');
      }
      alert(melding);
      // : op deze pagina blijven i.p.v. naar Home --
      // zie de toelichting bij renderScanOntvangstForm() hierboven.
      btn.disabled = false;
      document.getElementById('verbruikGs1').value = '';
      document.getElementById('verbruikOpmerking').value = '';
      document.getElementById('onbekendPaneel').innerHTML = '';
      document.getElementById('verbruikGs1').focus();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

// -----------------------------------------------------------------
// Vervallen afboeken: eigen, klein formulier i.p.v. een vinkje binnen
// Scan Usage. Dat vinkje toonde/verborg specialist/procedure-velden
// dynamisch en oogde rommelig voor iets dat zelden voorkomt -- een apart
// formulier (net als Transfer + Returns of One-off Order al eigen
// formulieren zijn) is duidelijker: geen twijfel over welke velden van
// toepassing zijn, en de intentie ("dit is een afboeking, geen normaal
// verbruik") staat al in de titel i.p.v. in een aan te vinken optie.
// Post't naar hetzelfde endpoint als Scan Usage, met reden altijd vast op
// 'vervaldatum_bereikt' (zie api/scan_verbruik.php).
// -----------------------------------------------------------------
function showVervalAfboeken() {
  setRoute('verval-afboeken');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetchLocaties(renderVervalAfboekenForm);
}

function renderVervalAfboekenForm(locRows) {
  let html = '<h2>' + t('writeOffExpired') + '</h2>';
  html += '<p class="form-hint">' + t('wfHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  const vervalLocaties = actieveLocatieNamen(locRows);
  html += '<div class="form-group"><label>' + t('wfLocation') + '</label>' + keuzeknoppenHtml('vervalLocatie', vervalLocaties, vervalLocaties[0]) + '</div>';
  html += '<div class="form-group"><label>' + t('wfScans') + '</label><textarea id="vervalGs1" rows="8" placeholder="Scan hier..."></textarea><div id="vervalGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div class="form-group"><label>' + t('wfNote') + '</label><textarea id="vervalOpmerking" rows="2"></textarea></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="vervalSubmit">' + t('wfSubmit') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  bindKeuzeknoppen('vervalLocatie');
  initGs1ScanHint('vervalGs1', 'vervalGs1Hint');

  const btn = document.getElementById('vervalSubmit');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    apiPost('api/scan_verbruik.php', {
      locatie: document.getElementById('vervalLocatie').value,
      gs1_raw: document.getElementById('vervalGs1').value,
      opmerking: document.getElementById('vervalOpmerking').value,
      reden: 'vervaldatum_bereikt',
      omschrijvingen: omschrijvingen || {},
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      alert(tf('wfWrittenOff', { n: result.verwerkt, m: result.overgeslagen }));
      // : op deze pagina blijven i.p.v. naar Home --
      // zie de toelichting bij renderScanOntvangstForm() hierboven.
      btn.disabled = false;
      document.getElementById('vervalGs1').value = '';
      document.getElementById('vervalOpmerking').value = '';
      document.getElementById('onbekendPaneel').innerHTML = '';
      document.getElementById('vervalGs1').focus();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

// -----------------------------------------------------------------
// Verplaatsing + Retouren
// -----------------------------------------------------------------
function showVerplaatsing() {
  setRoute('verplaatsing');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetchLocaties(renderVerplaatsingForm);
}

function renderVerplaatsingForm(locRows) {
  const locaties = actieveLocatieNamen(locRows);
  let html = '<h2>' + t('transferReturns') + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('tfWhatToDo') + '</label>';
  html += '<div class="radio-group"><label><input type="radio" name="actie" value="verplaatsen" checked> ' + t('tfTransfer') + '</label><label><input type="radio" name="actie" value="retourneren"> ' + t('tfReturn') + '</label></div></div>';
  html += '<div class="form-group"><label>' + t('tfScans') + '</label><textarea id="vpGs1" rows="6" placeholder="Scan hier..."></textarea><div id="vpGs1Hint" class="gs1-hint" style="display:none;"></div></div>';
  html += '<div class="form-group"><label>' + t('tfFromLocation') + '</label>' + keuzeknoppenHtml('vpVanLocatie', locaties, locaties[0]) + '</div>';
  html += '<div class="form-group" id="vpNaarGroup"><label>' + t('tfToLocation') + '</label>' + keuzeknoppenHtml('vpNaarLocatie', locaties, locaties[0]) + '</div>';
  html += '<div class="form-group"><label>' + t('tfReason') + '</label><input type="text" id="vpReden"></div>';
  html += '<div class="form-group"><label>' + t('tfNote') + '</label><textarea id="vpOpmerking" rows="2"></textarea></div>';
  html += '<div id="onbekendPaneel"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="vpSubmit">' + t('tfSave') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  bindKeuzeknoppen('vpVanLocatie');
  bindKeuzeknoppen('vpNaarLocatie');
  initGs1ScanHint('vpGs1', 'vpGs1Hint');

  document.querySelectorAll('input[name="actie"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.getElementById('vpNaarGroup').style.display = this.value === 'verplaatsen' ? 'block' : 'none';
    });
  });

  const btn = document.getElementById('vpSubmit');
  function doSubmit(omschrijvingen) {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    const actie = document.querySelector('input[name="actie"]:checked').value;
    apiPost('api/verplaatsing.php', {
      actie: actie,
      gs1_raw: document.getElementById('vpGs1').value,
      van_locatie: document.getElementById('vpVanLocatie').value,
      naar_locatie: document.getElementById('vpNaarLocatie').value,
      reden: document.getElementById('vpReden').value,
      opmerking: document.getElementById('vpOpmerking').value,
      omschrijvingen: omschrijvingen || {},
    }, function (result) {
      if (result.onbekend) {
        btn.disabled = false;
        toonOnbekendeBarcodesPaneel('onbekendPaneel', result.onbekendeRegels, doSubmit);
        return;
      }
      // Zelfde soort "slimme ruimte"-melding als bij Verbruik Scannen --
      // hier voor de
      // 'retourneren'-actie: staat het product niet in de gekozen
      // 'van'-locatie maar wel in Berging, dan is de retour daar
      // automatisch vandaan geboekt (zie retournering() in
      // includes/transacties.php). Bij 'verplaatsen' is result.omgeleid
      // altijd leeg, dus dit blok is dan gewoon een no-op.
      let melding = tf('tfProcessed', { n: result.verwerkt });
      if (result.omgeleid && result.omgeleid.length > 0) {
        melding += '\n\n' + t('suOmgeleidTitel') + '\n' + result.omgeleid.map(function (r) {
          return tf('suOmgeleidRegel', { naam: r.naam, van: r.van, naar: r.naar });
        }).join('\n');
      }
      alert(melding);
      // : op deze pagina blijven i.p.v. naar Home --
      // zie de toelichting bij renderScanOntvangstForm() hierboven.
      btn.disabled = false;
      document.getElementById('vpGs1').value = '';
      document.getElementById('vpReden').value = '';
      document.getElementById('vpOpmerking').value = '';
      document.getElementById('onbekendPaneel').innerHTML = '';
      document.getElementById('vpGs1').focus();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  }
  btn.addEventListener('click', function () { doSubmit({}); });
}

// -----------------------------------------------------------------
// Eenmalige bestelling (patiëntgebonden, migratieplan hoofdstuk 4.7/8)
// -----------------------------------------------------------------
function showEenmaligeBestelling() {
  setRoute('eenmalige-bestelling');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (specialisten) {
    StocuroRun.withSuccessHandler(function (procedures) {
      renderEenmaligeBestellingForm(specialisten, procedures);
    }).getProcedureTypen();
  }).getSpecialisten();
}

// Bij het AANMAKEN van een eenmalige
// bestelling is het fysieke product er nog niet -- dus geen GS1-scan,
// EAN, lot of vervaldatum meer op dit formulier, dat gebeurt allemaal
// pas op de bewerkpagina zodra de bestelling daadwerkelijk binnenkomt
// (zie renderEenmaligeBestellingEdit()). Besteller is niet langer een
// vrij in te typen naam, maar altijd de ingelogde gebruiker zelf (ook
// server-side afgedwongen, zie includes/eenmalige_bestellingen.php::
// addEenmaligeBestelling()) -- dus geen invoerveld meer, alleen een
// informatieve regel.
function renderEenmaligeBestellingForm(specialisten, procedures) {
  let html = '<h2>' + t('oneOffOrder') + '</h2>';
  html += '<p class="form-hint">' + t('oeHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('oePatientNumber') + '</label><input type="text" id="ebPatient" required></div>';
  html += '<div class="form-group"><label>' + t('oeProductDescription') + '</label><input type="text" id="ebOmschrijving" required></div>';
  // Geen "benodigd aantal"-veld meer
  // bij het aanvragen zelf -- dat is op dit moment zeer willekeurig (het
  // product is er nog niet). Het daadwerkelijke aantal wordt zoals
  // gebruikelijk pas bij ontvangst afgeleid uit het aantal gescande GS1-
  // codes (zie renderEenmaligeBestellingEdit()); addEenmaligeBestelling()
  // valt hier gewoon terug op een default van 1 totdat dat gebeurt.
  html += '<div class="form-group"><label>' + t('oeProcedureType') + '</label><select id="ebProcedure"><option value="">' + t('oeSelectProcedure') + '</option>' + actieveNaamOptionsHtml(procedures) + '</select></div>';
  html += '<div class="form-group"><label>' + t('oeSpecialist') + '</label><select id="ebSpecialist"><option value="">' + t('oeSelectSpecialist') + '</option>' + specialistOptionsHtml(specialisten, '') + '</select></div>';
  html += '<div class="form-group"><label>' + t('oeNote') + '</label><textarea id="ebOpmerking" rows="2"></textarea></div>';
  html += '<p class="form-hint">' + tf('oeOrderedByHint', { naam: (window.CURRENT_USER && window.CURRENT_USER.naam) || '-' }) + '</p>';
  html += '<div class="edit-actions"><button class="edit-save" id="ebSubmit">' + t('oeRequest') + '</button><button class="edit-cancel" id="ebOverzicht">' + t('oeViewOverview') + '</button></div>';
  html += '<div id="ebLijst"></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.getElementById('ebSubmit').addEventListener('click', function () {
    const btn = this;
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';

    const patientnummer = document.getElementById('ebPatient').value.trim();
    const omschrijving = document.getElementById('ebOmschrijving').value.trim();
    if (!patientnummer || !omschrijving) {
      errorEl.textContent = t('oeRequiredFields');
      errorEl.style.display = 'block';
      return;
    }

    btn.disabled = true;
    apiPost('api/eenmalige_bestelling.php', {
      patientnummer: patientnummer,
      artikelomschrijving: omschrijving,
      procedure_type: document.getElementById('ebProcedure').value,
      specialist: document.getElementById('ebSpecialist').value,
      opmerking: document.getElementById('ebOpmerking').value,
    }, function () {
      btn.disabled = false;
      alert(t('oeRequested'));
      // : op deze pagina blijven i.p.v. naar Home --
      // zie de toelichting bij renderScanOntvangstForm() hierboven. Dit
      // formulier is geen herhaalde-scan-flow zoals Ontvangst/Verbruik/
      // Verplaatsing, dus hier wordt het hele formulier leeggemaakt i.p.v.
      // alleen het scanveld.
      document.getElementById('ebPatient').value = '';
      document.getElementById('ebOmschrijving').value = '';
      document.getElementById('ebProcedure').value = '';
      document.getElementById('ebSpecialist').value = '';
      document.getElementById('ebOpmerking').value = '';
      document.getElementById('ebPatient').focus();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });

  document.getElementById('ebOverzicht').addEventListener('click', function () {
    fetch('api/eenmalige_bestelling.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { renderEenmaligeLijst(data); });
  });
}

function renderEenmaligeLijst(data) {
  let html = '<div class="section-title">' + t('oeRecentRequests') + '</div>';
  if (!data.length) {
    html += '<p class="empty">' + t('oeNoneYet') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colDate') + '</th><th>' + t('colPatientNo') + '</th><th>' + t('colDescription') + '</th><th>' + t('colQuantity') + '</th><th>' + t('colStatus') + '</th><th>' + t('colOrderedBy') + '</th></tr>';
    data.forEach(function (item) {
      html += '<tr><td>' + escapeHtml(item.tijdstempel) + '</td>'
        + '<td><a class="eb-link" data-id="' + item.id + '">' + escapeHtml(item.patientnummer) + '</a></td>'
        + '<td><a class="eb-link" data-id="' + item.id + '">' + escapeHtml(item.artikelomschrijving) + '</a></td>'
        + '<td>' + item.hoeveelheid + '</td><td>' + statusBadgeHtml(item.status) + '</td><td>' + escapeHtml(item.besteller || '-') + '</td></tr>';
    });
    html += '</table>';
  }
  document.getElementById('ebLijst').innerHTML = html;
  document.querySelectorAll('#ebLijst .eb-link').forEach(function (el) {
    el.addEventListener('click', function () { showEenmaligeBestellingDetail(this.getAttribute('data-id')); });
  });
}

// -----------------------------------------------------------------
// Artikel toevoegen
// -----------------------------------------------------------------
// Lichtgewicht client-side "voorbeeld"-extractie van het GTIN/EAN uit
// een gescande GS1-barcode -- puur voor
// live UI-feedback zodra er gescand wordt (Artikel Toevoegen). Dezelfde
// regel als parseGS1Raw()/normalizeEAN() in includes/gs1_parser.php en
// includes/ean.php: AI 01 gevolgd door 14 cijfers, links aangevuld met
// nullen tot 14 tekens. Bij ontbreken/ongeldige input gewoon '' terug --
// nooit een event- of ander object toewijzen aan een veldwaarde (dat gaf
// eerder de "[object PointerEvent]"-bug in het EAN-veld). De server blijft
// gezaghebbend: addArtikel() parsed gs1_raw zelf opnieuw bij opslaan.
function extractEanFromGs1(raw) {
  if (!raw) return '';
  const s = String(raw).trim();
  if (s.substr(0, 2) !== '01') return '';
  let ean = s.substr(2, 14).trim();
  if (!ean) return '';
  ean = ean.replace(/\.0$/, '').replace(/\s/g, '');
  while (ean.length < 14) ean = '0' + ean;
  return ean;
}

// PrefillData/vervangtVoorEan zijn optioneel, alleen gezet
// als je hier komt via "Nieuw artikel aanmaken als vervanger" op het
// bewerkscherm (renderProductEdit()) -- de bestaande aanroepen (Forms-
// menu, Onbekende Producten) blijven vanzelf werken met alleen prefillEan
// of helemaal geen argumenten.
function showArtikelToevoegen(prefillEan, prefillData, vervangtVoorEan, vanuitOnbekendeProducten) {
  setRoute('artikel-toevoegen');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetchLocaties(function (locRows) {
    // Producent wordt een dropdown
    // van bestaande producenten i.p.v. een vrij tekstveld. Als het
    // ophalen van de producentenlijst mislukt (bijv. een groep die wel
    // artikel-toevoegen maar niet het Producenten-scherm mag zien, zie
    // includes/groepen.php), blokkeert dat het Artikel Toevoegen-
    // formulier zelf niet -- dan valt de dropdown gewoon terug op alleen
    // "nieuwe producent invoeren".
    StocuroRun.withSuccessHandler(function (producenten) {
      // Categorielijst + hun eigenschappen, voor de
      // categorie-dropdown en de daaronder dynamisch getoonde
      // eigenschapsvelden (zie renderNaSpecsVelden() hieronder). Zelfde
      // graceful-fallback-aanpak als bij producenten hierboven -- mislukt
      // dit, dan toont het formulier gewoon geen eigenschapsvelden i.p.v.
      // helemaal te blokkeren.
      StocuroRun.withSuccessHandler(function (categorieen) {
        // Kastlocatie-suggesties erbij, zelfde graceful-
        // fallback-aanpak (mislukt de suggestielijst, dan gewoon met een
        // lege lijst verder -- de dropdown valt terug op alleen "nieuw").
        StocuroRun.withSuccessHandler(function (kastlocatieSuggesties) {
          renderArtikelToevoegenForm(locRows, prefillEan, prefillData, vervangtVoorEan, producenten, categorieen, kastlocatieSuggesties, vanuitOnbekendeProducten);
        }).withFailureHandler(function () {
          renderArtikelToevoegenForm(locRows, prefillEan, prefillData, vervangtVoorEan, producenten, categorieen, [], vanuitOnbekendeProducten);
        }).getKastlocatieSuggesties();
      }).withFailureHandler(function () {
        renderArtikelToevoegenForm(locRows, prefillEan, prefillData, vervangtVoorEan, producenten, [], [], vanuitOnbekendeProducten);
      }).getCategorieenMetSpecs();
    }).withFailureHandler(function () {
      renderArtikelToevoegenForm(locRows, prefillEan, prefillData, vervangtVoorEan, [], [], [], vanuitOnbekendeProducten);
    }).getProducenten();
  });
}

// Vertaalsleutel per oorspronkelijke (seed-data) categorienaam -- zie
// toelichting bij de categorie-<select>
// hieronder in renderArtikelToevoegenForm().
const CATEGORIE_LABEL_KEYS = {
  'Stent': 'catStent', 'Voerdraad': 'catGuidewire', 'Embolisatie': 'catEmbolisation',
  'Sheath': 'catSheath', 'Ballon': 'catBalloon', 'Catheter': 'catCatheter', 'Overig': 'catOther',
};
const CATEGORIE_FALLBACK = ['Stent', 'Voerdraad', 'Embolisatie', 'Sheath', 'Ballon', 'Catheter', 'Overig'];

// : "Bij nieuwe producten alle eigenschappen als
// dropdown aanbieden voor uniformiteit, met de optie een nieuwe aan te
// maken" -- zelfde dropdown-met-"__nieuw__"-patroon als Producent
// (naProducentSelect/peProducentSelect) en Kastlocatie
// (wireKastlocatieVeld), nu ook voor de categorie-specifieke
// eigenschapsvelden (specs, zie includes/categorieen.php::
// specWaardenPerCategorie()). Gedeeld tussen Artikel Toevoegen
// (renderNaSpecsVelden) en Product bewerken (renderSpecsVeldenEdit in
// renderProductEdit()) zodat beide schermen zich identiek gedragen.
//
// selectKeyAttr is het attribuut waarmee de bestaande generieke
// veld-sweep dit veld oppikt ('data-spec-key' op Artikel Toevoegen,
// 'data-key' op Product bewerken -- daar leest saveProductEdit() alle
// input/select[data-key] generiek uit). nieuwKeyAttr is BEWUST een ANDER
// attribuut op het companion-tekstveld, zodat diezelfde generieke sweep
// niet ook de rauwe "__nieuw__"-sentinelwaarde als eigenschapswaarde
// oppikt -- de aanroeper lost dat na het verzamelen zelf op (zie
// resolveSpecNieuwVelden() hieronder), zelfde tweetraps-aanpak als de
// bestaande producent-afhandeling.
//
// Heeft deze eigenschap nog GEEN eerder ingevulde waarden (nieuwe
// categorie/eigenschap, of het allereerste product in deze categorie),
// dan gewoon een tekstveld -- een dropdown met alleen "Nieuwe
// toevoegen" erin zou de eerste invoer alleen maar omslachtiger maken.
function specVeldHtml(spec, huidigeWaarde, selectKeyAttr, nieuwKeyAttr, idPrefix) {
  const waarden = Array.isArray(spec.values) ? spec.values : [];
  const isNieuw = !!huidigeWaarde && waarden.indexOf(huidigeWaarde) === -1;
  if (waarden.length === 0 && !isNieuw) {
    return '<div class="form-group"><label>' + escapeHtml(spec.label) + '</label>'
      + '<input type="text" ' + selectKeyAttr + '="' + escapeAttr(spec.key) + '" value="' + escapeAttr(huidigeWaarde) + '"></div>';
  }
  const id = 'specSel_' + idPrefix + '_' + spec.key.replace(/[^a-zA-Z0-9]/g, '_');
  return '<div class="form-group"><label>' + escapeHtml(spec.label) + '</label>'
    + '<select id="' + id + '" class="spec-select" ' + selectKeyAttr + '="' + escapeAttr(spec.key) + '">'
    + '<option value="">' + t('apSpecNone') + '</option>'
    + waarden.map(function (w) {
        return '<option value="' + escapeAttr(w) + '"' + (w === huidigeWaarde ? ' selected' : '') + '>' + escapeHtml(w) + '</option>';
      }).join('')
    + '<option value="__nieuw__"' + (isNieuw ? ' selected' : '') + '>' + t('apSpecNew') + '</option>'
    + '</select>'
    + '<input type="text" id="' + id + '_nieuw" class="spec-select-nieuw" ' + nieuwKeyAttr + '="' + escapeAttr(spec.key) + '" placeholder="' + escapeAttr(t('apSpecNewPlaceholder')) + '" value="' + escapeAttr(isNieuw ? huidigeWaarde : '') + '" style="display:' + (isNieuw ? 'block' : 'none') + '; margin-top:6px;">'
    + '</div>';
}

// Toont/verbergt het companion-"nieuwe waarde"-tekstveld naast elk
// eigenschap-dropdown in $containerEl, zelfde toggle als de bestaande
// producent-/kastlocatie-dropdowns.
function wireSpecSelectToggles(containerEl) {
  if (!containerEl) return;
  containerEl.querySelectorAll('.spec-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      const companion = document.getElementById(this.id + '_nieuw');
      if (companion) companion.style.display = this.value === '__nieuw__' ? 'block' : 'none';
    });
  });
}

// Vervangt in $waarden (een {key: waarde}-object, al opgebouwd uit de
// generieke [selectKeyAttr]-sweep) elke letterlijke "__nieuw__"-
// sentinelwaarde door wat de gebruiker in het bijbehorende companion-
// tekstveld heeft getypt. containerEl scopet de zoekopdracht (nodig op
// Product bewerken, waar meerdere secties door elkaar staan).
function resolveSpecNieuwVelden(waarden, containerEl, nieuwKeyAttr) {
  if (!containerEl) return;
  containerEl.querySelectorAll('.spec-select-nieuw').forEach(function (el) {
    const key = el.getAttribute(nieuwKeyAttr);
    if (key && waarden[key] === '__nieuw__') {
      waarden[key] = el.value.trim();
    }
  });
}

function renderNaSpecsVelden(categorieNaam, categorieen, prefillSpecs) {
  const cat = (categorieen || []).filter(function (c) { return c.naam === categorieNaam; })[0];
  const specs = (cat && cat.specs) || [];
  const pre = prefillSpecs || {};
  if (specs.length === 0) return '';
  let html = '<div class="section-title">' + t('peSpecifications') + '</div><div class="edit-grid">';
  specs.forEach(function (s) {
    html += specVeldHtml(s, pre[s.key] || '', 'data-spec-key', 'data-spec-key-nieuwvoor', 'na');
  });
  html += '</div>';
  return html;
}

function renderArtikelToevoegenForm(locRows, prefillEan, prefillData, vervangtVoorEan, producenten, categorieen, kastlocatieSuggesties, vanuitOnbekendeProducten) {
  kastlocatieSuggesties = kastlocatieSuggesties || [];
  const naamVoorSlot = {};
  locRows.forEach(function (r) { naamVoorSlot[r.slot] = r.naam; });
  const producentNamen = (producenten || []).map(function (p) { return p.naam; }).sort(function (a, b) { return a.localeCompare(b); });
  const pre = prefillData || {};

  let html = '<h2>' + t('addProduct') + '</h2>';
  html += '<p class="form-hint">' + t('apHint') + '</p>';
  if (vervangtVoorEan) {
    html += '<p class="form-hint" style="color:#a15c00;">' + t('apVervangerHint') + '</p>';
  }
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  // Bij het aanmaken van een vervanger-artikel worden
  // categorie/besteltype (en verderop min-max/producent/prijs)
  // vooraf ingevuld vanuit het originele artikel (pre.*) -- EAN, foto,
  // doos-verpakking en logistiekcode bewust NIET, want dat zijn
  // eigenschappen van het FYSIEKE product zelf, niet iets wat je van het
  // origineel overneemt.
  function catSel(v) { return pre.categorie === v ? ' selected' : ''; }
  // Categorielijst komt nu uit de
  // database (includes/categorieen.php, beheerbaar via Stamgegevens)
  // i.p.v. hardcoded hier. De 7 oorspronkelijke categorieën behouden hun
  // vertaalde label (CATEGORIE_LABEL_KEYS hieronder) zodat de Engelse UI
  // niet ineens Nederlandse categorienamen laat zien; een door een
  // beheerder later toegevoegde categorie heeft geen vertaling en toont
  // gewoon zijn letterlijke naam. Als het ophalen van de categorieënlijst
  // om wat voor reden dan ook niets opleverde, valt dit terug op diezelfde
  // 7 namen (CATEGORIE_FALLBACK) zodat dit formulier nooit zonder
  // categorie-opties komt te zitten.
  const catActief = (categorieen || []).filter(function (c) { return c.actief; });
  const catNamen = catActief.length > 0 ? catActief.map(function (c) { return c.naam; }) : CATEGORIE_FALLBACK;
  const catOptionsHtml = catNamen.map(function (naam) {
    const labelKey = CATEGORIE_LABEL_KEYS[naam];
    const label = labelKey ? t(labelKey) : naam;
    return '<option value="' + escapeAttr(naam) + '"' + catSel(naam) + '>' + escapeHtml(label) + '</option>';
  }).join('');
  function mm(key) { return (pre[key] !== undefined && pre[key] !== '') ? escapeAttr(String(pre[key])) : '0'; }

  // : zelfde sectie-indeling (Identificatie/Besteldata/
  // Voorraad/Doosverpakking/Logistiek) en kaartstijl (.edit-section-box)
  // als het bewerkscherm (renderProductEdit()) i.p.v. één lange, platte
  // opsomming -- beide formulieren horen consistent aan te voelen.
  html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionIdentification') + '</div>';
  html += '<div class="form-group"><label>' + t('apGs1Scan') + '</label><input type="text" id="naGs1"></div>';
  html += '<div class="form-group"><label>' + t('apEanManual') + '</label><input type="text" id="naEan" value="' + escapeAttr(prefillEan || '') + '"><p class="form-hint">' + t('apEanAutoHint') + '</p></div>';
  // Bugfix: dit veld werd voorheen NIET op dit
  // formulier getoond, waardoor het bij aanmaken alleen via GS1 AI(240)
  // gevuld kon worden (fout, zie addArtikel() in includes/artikelen.php) --
  // nu een gewoon handmatig invoerveld, net als op het bewerkscherm.
  html += '<div class="form-group"><label>' + t('colArticleNumber') + '</label><input type="text" id="naArtikelref" value="' + escapeAttr(pre.artikelref || '') + '"></div>';
  html += '<div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apProductName') + '</label><input type="text" id="naNaam" required></div>';
  html += '<div class="form-group"><label>' + t('apCategory') + '</label><select id="naCategorie">' + catOptionsHtml + '</select></div>';
  html += '</div>';
  html += '<div id="naSpecsContainer">' + renderNaSpecsVelden(pre.categorie || catNamen[0], categorieen, null) + '</div>';
  html += '</div>';

  // Dropdown van bestaande producenten + optie om een nieuwe naam in te
  // vullen. artikelen.producent
  // blijft bewust vrije tekst (geen FK, zie includes/producenten.php) --
  // een hier getypte nieuwe naam wordt gewoon als tekst opgeslagen op het
  // artikel, zonder dat er meteen een volledige producent-contactkaart
  // voor aangemaakt hoeft te worden (dat kan een beheerder later alsnog
  // doen via ⚙️ Beheer → Producenten, en dan matcht hij automatisch op
  // naam).
  const producentPrefillIsNieuw = !!pre.producent && producentNamen.indexOf(pre.producent) === -1;
  html += '<div class="edit-section-box"><div class="section-title">' + t('peSectionOrderData') + '</div><div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apOrderType') + '</label><select id="naBesteltype"><option value="Automatisch"' + (pre.besteltype === 'Automatisch' ? ' selected' : '') + '>' + t('apOrderTypeAuto') + '</option><option value="Handmatig"' + (pre.besteltype === 'Handmatig' ? ' selected' : '') + '>' + t('apOrderTypeManual') + '</option><option value="Eenmalig"' + (pre.besteltype === 'Eenmalig' ? ' selected' : '') + '>' + t('apOrderTypeOnce') + '</option></select></div>';
  html += '<div class="form-group"><label>' + t('apPricePerUnit') + '</label><input type="number" step="0.01" id="naPrijs" value="' + mm('prijs') + '"></div>';
  html += '</div>';
  html += '<div class="form-group"><label>' + t('apManufacturer') + '</label><select id="naProducentSelect">'
    + '<option value="">' + t('apManufacturerNone') + '</option>'
    + producentNamen.map(function (naam) {
      return '<option value="' + escapeAttr(naam) + '"' + (naam === pre.producent ? ' selected' : '') + '>' + escapeHtml(naam) + '</option>';
    }).join('')
    + '<option value="__nieuw__"' + (producentPrefillIsNieuw ? ' selected' : '') + '>' + t('apManufacturerNew') + '</option>'
    + '</select>'
    + '<input type="text" id="naProducentNieuw" placeholder="' + escapeAttr(t('apManufacturerNewPlaceholder')) + '" value="' + escapeAttr(producentPrefillIsNieuw ? pre.producent : '') + '" style="display:' + (producentPrefillIsNieuw ? 'block' : 'none') + '; margin-top:6px;">'
    + '</div>';
  html += '</div>';

  // --- Voorraad: Min/Max per kamer, zelfde room-group-stijl als het bewerkscherm. ---
  // Kamers (ik/hybok/kar) hebben geen
  // minimum meer -- alleen Berging (naMinBerging) behoudt het Min-veld.
  html += '<div class="edit-section-box"><div class="section-title">' + t('apMinMax') + '</div>';
  [['ik', 'Room A', 'naMinIk', 'naMaxIk'], ['hybok', 'Room B', 'naMinHybok', 'naMaxHybok'], ['kar', 'Cart', 'naMinKar', 'naMaxKar'], ['berging', 'Storage', 'naMinBerging', 'naMaxBerging']].forEach(function (slot) {
    const naam = escapeHtml(naamVoorSlot[slot[0]] || slot[1]);
    html += '<div class="edit-room-group"><div class="edit-room-title">' + naam + '</div><div class="edit-grid">';
    if (slot[0] === 'berging') {
      html += '<div class="form-group"><label>' + t('apMin') + naam + '</label><input type="number" id="' + slot[2] + '" value="' + mm('min_' + slot[0]) + '"></div>';
    }
    html += '<div class="form-group"><label>' + t('apMax') + naam + '</label><input type="number" id="' + slot[3] + '" value="' + mm('max_' + slot[0]) + '"></div>';
    // Kastlocatie-dropdown ook al bij
    // het aanmaken beschikbaar (stond eerder alleen op het bewerkscherm) --
    // de Kar heeft geen kastlocatie, zelfde uitzondering als op dat scherm.
    if (slot[0] !== 'kar') {
      html += kastlocatieVeldHtml('na', 'locatie_' + slot[0], pre['locatie_' + slot[0]] || '', kastlocatieSuggesties);
    }
    html += '</div></div>';
  });
  html += '</div>';

  // Doosverpakking (optioneel): sommige
  // producten worden per stuk verbruikt/verplaatst maar door de
  // leverancier per doos geleverd (bijv. voerdraden/PICC-lijnen in een
  // doos van 5), met een eigen doos-EAN naast het stuk-EAN hierboven.
  // Beide velden leeg laten = geen doosverpakking voor dit product.
  html += '<div class="edit-section-box"><div class="section-title">' + t('apDoosTitle') + '</div>';
  html += '<p class="form-hint">' + t('apDoosHint') + '</p>';
  html += '<div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('apDoosGs1Scan') + '</label><input type="text" id="naDoosGs1"></div>';
  html += '<div class="form-group"><label>' + t('apDoosEan') + '</label><input type="text" id="naDoosEan"><p class="form-hint">' + t('apEanAutoHint') + '</p></div>';
  html += '<div class="form-group"><label>' + t('apEenhedenPerDoos') + '</label><input type="number" id="naEenhedenPerDoos" min="2"></div>';
  html += '</div></div>';

  // Interne logistiekcode : volledig
  // handmatig, los van de doos-EAN hierboven en van artikelref (dat is
  // het nummer van de fabrikant zelf) -- zie ARTIKEL_EDIT_FIELDS in
  // includes/artikelen.php.
  html += '<div class="edit-section-box"><div class="section-title">' + t('apLogistiekTitle') + '</div>';
  html += '<p class="form-hint">' + t('apLogistiekHint') + '</p>';
  html += '<div class="form-group"><label>' + t('apLogistiekCode') + '</label><input type="text" id="naLogistiekCode"></div>';
  html += '</div>';

  html += '<div class="edit-actions"><button class="edit-save" id="naSubmit">' + t('apAdd') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  // GS1 is de voorkeur, EAN is de
  // terugval voor als er geen GS1 beschikbaar is -- zodra er een geldig
  // GTIN uit de GS1-scan te halen valt, vullen we het EAN-veld ermee
  // (overschrijfbaar, het blijft een gewoon tekstveld voor de
  // uitzondering waarin alleen een EAN bekend is). Zelfde patroon voor
  // de doosverpakking hieronder. Altijd this.value lezen (nooit het
  // event zelf) -- dat voorkomt exact de eerder gemelde bug waarbij er
  // "[object PointerEvent]" in het EAN-veld terechtkwam.
  document.getElementById('naGs1').addEventListener('input', function () {
    const ean = extractEanFromGs1(this.value);
    if (ean) document.getElementById('naEan').value = ean;
  });
  document.getElementById('naDoosGs1').addEventListener('input', function () {
    const ean = extractEanFromGs1(this.value);
    if (ean) document.getElementById('naDoosEan').value = ean;
  });
  // Na een gemelde bug: een barcodescanner
  // onderscheidt geen velden -- landt er per ongeluk een volledige
  // GS1-barcode (met AI-voorvoegsel) in het KALE-EAN-veld hierboven i.p.v.
  // in het GS1-scanveld ernaast, dan werd dat eerder ongewijzigd
  // doorgestuurd (en gaf een "Data too long"-databasefout bij het
  // opslaan, zie includes/ean.php::normalizeEAN() voor de bijbehorende
  // server-side correctie). Deze twee velden corrigeren zichzelf nu ook
  // live: zodra er een herkenbare GS1-GTIN in staat, wordt dat meteen
  // vervangen door de kale 14-cijferige EAN.
  document.getElementById('naEan').addEventListener('input', function () {
    const ean = extractEanFromGs1(this.value);
    if (ean) this.value = ean;
  });
  document.getElementById('naDoosEan').addEventListener('input', function () {
    const ean = extractEanFromGs1(this.value);
    if (ean) this.value = ean;
  });
  // Kastlocatie-dropdowns (per kamer), zelfde toggle-patroon als producent.
  ['locatie_ik', 'locatie_hybok', 'locatie_berging'].forEach(function (veldKey) {
    wireKastlocatieVeld('na', veldKey);
  });
  document.getElementById('naProducentSelect').addEventListener('change', function () {
    document.getElementById('naProducentNieuw').style.display = this.value === '__nieuw__' ? 'block' : 'none';
  });
  // Eigenschapsvelden zijn afhankelijk van de gekozen
  // categorie -- bij een wisseling de container gewoon opnieuw tekenen
  // (verliest eerder ingevulde eigenschapswaarden bij een categoriewissel,
  // wat hier terecht is: andere categorie = andere eigenschappen, een
  // "Diameter (mm)"-waarde voor een stent heeft geen betekenis meer als je
  // ineens Voerdraad kiest).
  document.getElementById('naCategorie').addEventListener('change', function () {
    const container = document.getElementById('naSpecsContainer');
    container.innerHTML = renderNaSpecsVelden(this.value, categorieen, null);
    wireSpecSelectToggles(container);
  });
  // Eigenschapsvelden staan meteen in de initiële HTML (zie hierboven),
  // dus ook meteen toggles wiren -- niet pas bij de eerste categoriewissel.
  wireSpecSelectToggles(document.getElementById('naSpecsContainer'));

  document.getElementById('naSubmit').addEventListener('click', function () {
    const btn = this;
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    // Producent komt van de dropdown, tenzij "nieuwe
    // producent" gekozen is -- dan van het losse tekstveld ernaast.
    const producentSelectie = document.getElementById('naProducentSelect').value;
    const producentWaarde = producentSelectie === '__nieuw__'
      ? document.getElementById('naProducentNieuw').value.trim()
      : producentSelectie;

    // Categorie-specifieke eigenschapsvelden (data-spec-key)
    // generiek meesturen -- addArtikel() (includes/artikelen.php) plukt
    // hier zelf de sleutels uit die bij de gekozen categorie horen, dus
    // volstaat het om gewoon alle aanwezige spec-velden mee te sturen.
    const specBody = {};
    // Vangt zowel het tekstveld-fallback-geval (nog geen bestaande
    // waarden) als de dropdown-selects af -- .spec-select heeft ZELF ook
    // data-spec-key, dus komt hier automatisch in mee (querySelectorAll
    // matcht op element-tag select of input, beide met dat attribuut).
    document.querySelectorAll('#naSpecsContainer input[data-spec-key], #naSpecsContainer select[data-spec-key]').forEach(function (el) {
      specBody[el.getAttribute('data-spec-key')] = el.value;
    });
    // "__nieuw__"-sentinelwaarden vervangen door wat er in het
    // companion-tekstveld is getypt (zie specVeldHtml()/resolveSpecNieuwVelden()).
    resolveSpecNieuwVelden(specBody, document.getElementById('naSpecsContainer'), 'data-spec-key-nieuwvoor');

    // Kastlocatie-dropdowns -- "__nieuw__" oplossen naar de
    // getypte waarde in de companion-invoer, zelfde patroon als producent.
    const kastlocatieWaardes = {};
    ['locatie_ik', 'locatie_hybok', 'locatie_berging'].forEach(function (veldKey) {
      const selectEl = document.getElementById('na_' + veldKey);
      kastlocatieWaardes[veldKey] = selectEl ? selectEl.value : '';
    });
    resolveKastlocatieVeld(kastlocatieWaardes, 'na', 'locatie_ik');
    resolveKastlocatieVeld(kastlocatieWaardes, 'na', 'locatie_hybok');
    resolveKastlocatieVeld(kastlocatieWaardes, 'na', 'locatie_berging');

    apiPost('api/artikel_toevoegen.php', Object.assign({
      gs1_raw: document.getElementById('naGs1').value,
      ean: document.getElementById('naEan').value,
      artikelref: document.getElementById('naArtikelref').value,
      productnaam: document.getElementById('naNaam').value,
      categorie: document.getElementById('naCategorie').value,
      besteltype: document.getElementById('naBesteltype').value,
      // Geen naMinIk/naMinHybok/naMinKar meer -- kamers
      // hebben geen minimum-veld meer op dit formulier.
      max_ik: document.getElementById('naMaxIk').value,
      max_hybok: document.getElementById('naMaxHybok').value,
      max_kar: document.getElementById('naMaxKar').value,
      min_berging: document.getElementById('naMinBerging').value, max_berging: document.getElementById('naMaxBerging').value,
      locatie_ik: kastlocatieWaardes.locatie_ik, locatie_hybok: kastlocatieWaardes.locatie_hybok, locatie_berging: kastlocatieWaardes.locatie_berging,
      producent: producentWaarde,
      prijs: document.getElementById('naPrijs').value,
      doos_gs1_raw: document.getElementById('naDoosGs1').value,
      doos_ean: document.getElementById('naDoosEan').value,
      eenheden_per_doos: document.getElementById('naEenhedenPerDoos').value,
      logistiek_code: document.getElementById('naLogistiekCode').value,
      // Als dit artikel wordt aangemaakt als vervanger voor
      // een ander (zie renderProductEdit()), koppelt api/artikel_toevoegen.php
      // dat na het aanmaken meteen (includes/artikelen.php::koppelAlsVervanger()).
      vervangtVoorEan: vervangtVoorEan || undefined,
    }, specBody), function (result) {
      alert(tf('apAdded', { ean: result.ean }));
      // Als dit EAN nog open meldingen had op "Onbekende
      // Producten" (bijv. omdat we hier vandaan kwamen), meteen
      // afronden -- geen no-op fout als dat niet het geval was.
      apiPost('api/onbekende_scans.php', { ean: result.ean }, function () {}, function () {});
      // Kwam je hier vanuit Onbekende
      // Producten, dan terug naar die lijst i.p.v. naar de nieuwe
      // productpagina -- vaak gaat het om meerdere eenmalig bestelde
      // producten achter elkaar, en de backend heeft intussen (zie
      // api/artikel_toevoegen.php) ook meteen eventuele openstaande
      // eenmalige-bestelling-ontvangsten voor deze EAN afgehandeld.
      if (vanuitOnbekendeProducten) {
        showOnbekendeProducten();
        return;
      }
      // Bij een vervanger-aanmaak terug naar het ORIGINELE
      // artikel (waar de "vervangen door"-koppeling nu zichtbaar is),
      // anders zoals gebruikelijk naar het nieuw aangemaakte artikel zelf.
      showProductDetail(vervangtVoorEan || result.ean);
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
}

// -----------------------------------------------------------------
// Gebruikersbeheer (alleen is_beheerder=1 -- zie
// includes/auth.php::requireBeheerder())
// -----------------------------------------------------------------
// -----------------------------------------------------------------
// Stamgegevens: alleen nog procedure-typen. De vroegere
// specialisten-lijst hier is verwijderd -- specialisten/laboranten zijn
// nu altijd gebruikersaccounts,
// beheerd via het Gebruikers-scherm (showGebruikers()), niet hier.
// Deactiveren i.p.v. verwijderen -- zie toelichting in
// includes/stamgegevens.php. Alleen beheerder.
// -----------------------------------------------------------------
function showStamgegevens() {
  setRoute('stamgegevens');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  // Naast procedure-typen ook de beheerde categorielijst +
  // hun eigenschappen ophalen (zelfde geketende-fetch-patroon als
  // showOpenOrders() voor reguliere + eenmalige bestellingen).
  StocuroRun.withSuccessHandler(function (procedures) {
    StocuroRun.withSuccessHandler(function (categorieen) {
      renderStamgegevens(procedures, categorieen);
    }).getCategorieenMetSpecs();
  }).getProcedureTypen();
}

function renderProcedureTypenTabel(procedures) {
  if (!procedures.length) return '<p class="empty">' + t('mdNothingAdded') + '</p>';
  let html = '<table><tr><th>' + t('colName') + '</th><th>' + t('colStatus') + '</th><th>' + t('mdLaborantToegestaan') + '</th><th></th></tr>';
  procedures.forEach(function (item) {
    const actief = item.actief == 1;
    html += '<tr><td>' + escapeHtml(item.naam) + '</td>';
    html += '<td>' + (actief ? t('activeLabel') : t('inactiveLabel')) + '</td>';
    // PICC-regel: welke procedures een Laborant ook zelf
    // als uitvoerder mag hebben op Scan Usage (in de praktijk nu alleen
    // PICC-lijn) -- zie renderScanVerbruikForm() en includes/
    // transacties.php::verwerkScan() voor de client- resp. server-side
    // afdwinging.
    html += '<td><input type="checkbox" class="proc-laborant-check" data-id="' + item.id + '"' + (item.laborant_toegestaan == 1 ? ' checked' : '') + '></td>';
    html += '<td><button class="action-btn stam-toggle" data-id="' + item.id + '" data-actief="' + (actief ? '1' : '0') + '">' + (actief ? t('mdDeactivate') : t('mdActivate')) + '</button></td></tr>';
  });
  html += '</table>';
  return html;
}

// één categorie-blok (naam + actief-knop + eigenschappenlijst
// + eigenschap-toevoegen-mini-formulier) in het nieuwe Stamgegevens-blok
// "Categorieën & Eigenschappen". Losse functie i.p.v. inline in
// renderStamgegevens() omdat dit per categorie herhaald wordt.
function renderCategorieAdminBlok(cat) {
  let html = '<div class="cat-admin-blok" style="border:1px solid #e5e5e5; border-radius:6px; padding:10px 14px; margin-bottom:10px;">';
  html += '<div style="display:flex; justify-content:space-between; align-items:center;">';
  html += '<strong>' + escapeHtml(cat.naam) + '</strong>';
  html += '<button class="action-btn stam-cat-toggle" data-id="' + cat.id + '" data-actief="' + (cat.actief ? '1' : '0') + '">' + (cat.actief ? t('mdDeactivate') : t('mdActivate')) + '</button>';
  html += '</div>';

  if (cat.specs.length === 0) {
    html += '<p class="form-hint" style="margin:6px 0 0;">' + t('mdNoSpecs') + '</p>';
  } else {
    html += '<ul style="margin:8px 0 0; padding-left:18px;">';
    cat.specs.forEach(function (s) {
      html += '<li>' + escapeHtml(s.label) + ' <button class="action-btn stam-spec-remove" data-id="' + s.id + '" style="padding:2px 8px; font-size:12px;">' + t('mdRemove') + '</button></li>';
    });
    html += '</ul>';
  }

  html += '<div style="display:flex; gap:8px; margin-top:8px;">';
  html += '<input type="text" class="stam-spec-nieuw" data-cat-id="' + cat.id + '" placeholder="' + escapeAttr(t('mdNewSpecPlaceholder')) + '" style="flex:1;">';
  html += '<button class="action-btn stam-spec-add" data-cat-id="' + cat.id + '">' + t('addLabel') + '</button>';
  html += '</div>';
  html += '</div>';
  return html;
}

function renderStamgegevens(procedures, categorieen) {
  let html = '<h2>' + t('masterData') + '</h2>';
  html += '<p class="form-hint">' + t('mdHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  html += '<div class="section-title">' + t('mdProcedureTypes') + '</div>';
  html += '<p class="form-hint">' + t('mdLaborantHint') + '</p>';
  html += renderProcedureTypenTabel(procedures);
  html += '<div class="form-group"><label>' + t('mdNewProcedure') + '</label><input type="text" id="nieuweProcedure"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="procedureToevoegen">' + t('addLabel') + '</button></div>';

  // Categorielijst + hun eigenschappen
  // (bijv. Ballon -> diameter/lengte/compatibel voerdraad) zijn nu hier
  // beheerbaar i.p.v. hardcoded in de code -- zie includes/categorieen.php.
  html += '<div class="section-title">' + t('mdCategorieen') + '</div>';
  html += '<p class="form-hint">' + t('mdCategorieenHint') + '</p>';
  (categorieen || []).forEach(function (cat) {
    html += renderCategorieAdminBlok(cat);
  });
  html += '<div class="form-group"><label>' + t('mdNewCategorie') + '</label><input type="text" id="nieuweCategorie"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="categorieToevoegen">' + t('addLabel') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  const errorEl = document.getElementById('formError');
  function toonFout(err) {
    errorEl.textContent = err.error || t('somethingWrong');
    errorEl.style.display = 'block';
  }

  document.querySelectorAll('.stam-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const id = this.getAttribute('data-id');
      const actief = this.getAttribute('data-actief') === '1';
      apiPost('api/procedure_typen.php', { actie: 'wisselen', id: id, actief: !actief }, showStamgegevens, toonFout);
    });
  });

  document.querySelectorAll('.proc-laborant-check').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
      errorEl.style.display = 'none';
      const id = this.getAttribute('data-id');
      const checked = this.checked;
      apiPost('api/procedure_typen.php', { actie: 'laborant_wisselen', id: id, laborant_toegestaan: checked }, function () {}, function (err) {
        checkbox.checked = !checked;
        toonFout(err);
      });
    });
  });

  document.getElementById('procedureToevoegen').addEventListener('click', function () {
    errorEl.style.display = 'none';
    const naam = document.getElementById('nieuweProcedure').value.trim();
    if (!naam) return;
    apiPost('api/procedure_typen.php', { actie: 'toevoegen', naam: naam }, showStamgegevens, toonFout);
  });

  // Categorie-beheer wiring -- elke actie herlaadt het hele
  // Stamgegevens-scherm (showStamgegevens), zelfde eenvoudige "refresh na
  // schrijven"-aanpak als bij procedure-typen hierboven.
  document.querySelectorAll('.stam-cat-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const id = this.getAttribute('data-id');
      const actief = this.getAttribute('data-actief') === '1';
      apiPost('api/categorie_eigenschappen.php', { actie: 'wisselen', id: id, actief: !actief }, showStamgegevens, toonFout);
    });
  });

  document.querySelectorAll('.stam-spec-remove').forEach(function (btn) {
    btn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const id = this.getAttribute('data-id');
      apiPost('api/categorie_eigenschappen.php', { actie: 'spec_verwijderen', id: id }, showStamgegevens, toonFout);
    });
  });

  document.querySelectorAll('.stam-spec-add').forEach(function (btn) {
    btn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const catId = this.getAttribute('data-cat-id');
      const input = document.querySelector('.stam-spec-nieuw[data-cat-id="' + catId + '"]');
      const label = input.value.trim();
      if (!label) return;
      apiPost('api/categorie_eigenschappen.php', { actie: 'spec_toevoegen', categorie_id: catId, label: label }, showStamgegevens, toonFout);
    });
  });

  document.getElementById('categorieToevoegen').addEventListener('click', function () {
    errorEl.style.display = 'none';
    const naam = document.getElementById('nieuweCategorie').value.trim();
    if (!naam) return;
    apiPost('api/categorie_eigenschappen.php', { actie: 'toevoegen', naam: naam }, showStamgegevens, toonFout);
  });
}

// -----------------------------------------------------------------
// Locations: de vier locatie-slots hernoemen/aan-of-uitzetten
//. Alleen beheerder. Zie includes/locaties.php voor de
// beperking (vier vaste slots, geen onbeperkt aantal locaties).
// -----------------------------------------------------------------
function showLocatiesBeheer() {
  setRoute('locaties');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetchLocaties(renderLocatiesBeheer);
}

function renderLocatiesBeheer(locRows) {
  const sorted = locRows.slice().sort(function (a, b) { return a.volgorde - b.volgorde; });

  let html = '<h2>' + t('locations') + '</h2>';
  html += '<p class="form-hint">' + t('lcHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<table><tr><th>' + t('colRole') + '</th><th>' + t('colName') + '</th><th>' + t('colActive') + '</th><th></th></tr>';

  const rolLabel = { ik: t('lcRoleGeneral1'), hybok: t('lcRoleGeneral2'), kar: t('lcRoleMobile'), berging: t('lcRoleCentral') };

  sorted.forEach(function (r) {
    const isExtra = String(r.slot).indexOf('extra_') === 0;
    html += '<tr>';
    html += '<td>' + escapeHtml(isExtra ? t('lcAdditional') : (rolLabel[r.slot] || r.slot)) + '</td>';
    html += '<td><input type="text" class="loc-naam" data-slot="' + r.slot + '" value="' + escapeAttr(r.naam) + '"></td>';
    html += '<td><input type="checkbox" class="loc-actief" data-slot="' + r.slot + '"' + (r.actief == 1 ? ' checked' : '') + '></td>';
    html += '<td><button class="action-btn loc-opslaan" data-slot="' + r.slot + '">' + t('save') + '</button></td>';
    html += '</tr>';
  });
  html += '</table>';

  html += '<div class="section-title">' + t('lcAddNew') + '</div>';
  html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="locNieuweNaam" placeholder="' + escapeAttr(t('lcNamePlaceholder')) + '"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="locToevoegenBtn">' + t('lcAddButton') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  const errorEl = document.getElementById('formError');

  document.querySelectorAll('.loc-opslaan').forEach(function (btn) {
    btn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const slot = this.getAttribute('data-slot');
      const naam = document.querySelector('.loc-naam[data-slot="' + slot + '"]').value.trim();
      const actief = document.querySelector('.loc-actief[data-slot="' + slot + '"]').checked;
      if (!naam) return;

      apiPost('api/locaties.php', { slot: slot, naam: naam, actief: actief }, showLocatiesBeheer, function (err) {
        errorEl.textContent = err.error || t('somethingWrong');
        errorEl.style.display = 'block';
      });
    });
  });

  document.getElementById('locToevoegenBtn').addEventListener('click', function () {
    errorEl.style.display = 'none';
    const naam = document.getElementById('locNieuweNaam').value.trim();
    if (!naam) return;

    apiPost('api/locaties.php', { actie: 'toevoegen', naam: naam }, showLocatiesBeheer, function (err) {
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
}

// Dit scherm
// is nu de ENIGE plek voor functie/beheerder-instellingen van een
// account (geen aparte Stamgegevens > Specialisten-lijst en geen
// specialist_id-koppeling meer -- zie api/gebruikers.php). Naast de
// gebruikerslijst zelf toont dit scherm ook de "Nog te migreren"-lijst:
// namen uit de oude 'specialisten'-tabel die nog geen bijbehorend
// inlogaccount hebben (api/specialisten.php?legacy=1).
// (onderdeel van
// "Licentiemodel: registratie blokkeren zonder geldige licentie +
// profielpagina"): zelf-service profielpagina, bereikbaar via de naam in
// de header (zie #profileLink hieronder in dit bestand + app.php). Toont
// eigen accountgegevens + licentiestatus (zelfde LIVE-geverifieerde
// controleerLicentieGebruiker()-uitkomst als het beheerder-only
// Gebruikers-scherm, maar dan voor het EIGEN account, zie
// api/mijn_profiel.php), en is de enige plek waar een gebruiker zijn
// EIGEN wachtwoord kan wijzigen (met het huidige wachtwoord ter
// bevestiging -- de bestaande 'wachtwoord_resetten'-actie in
// api/gebruikers.php blijft de beheerder-route voor ANDERE accounts).
function showProfiel() {
  setRoute('profiel');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetch('api/mijn_profiel.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(renderProfiel);
}

function renderProfiel(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('profTitle') + '</h2>';

  html += '<div class="section-title">' + t('profAccountTitle') + '</div>';
  html += '<table>';
  html += '<tr><td>' + t('colName') + '</td><td>' + escapeHtml(data.naam) + '</td></tr>';
  html += '<tr><td>' + t('colEmail') + '</td><td>' + escapeHtml(data.email) + '</td></tr>';
  html += '<tr><td>' + t('guFunctie') + '</td><td>' + functieLabel(data.rol) + (data.is_beheerder ? ' (' + t('guBeheerderVlag') + ')' : '') + '</td></tr>';
  html += '<tr><td>' + t('guLanguage') + '</td><td>' + (data.taal === 'nl' ? 'Nederlands' : 'English') + '</td></tr>';
  html += '<tr><td>' + t('colLastLogin') + '</td><td>' + (data.laatst_ingelogd ? escapeHtml(String(data.laatst_ingelogd).substring(0, 16)) : '-') + '</td></tr>';
  html += '</table>';
  html += '<p class="form-hint">' + t('profTaalHint') + '</p>';

  html += '<div class="section-title">' + t('profLicentieTitle') + '</div>';
  const s = data.licentieStatus || {};
  let badge;
  if (s.vrij) {
    badge = '<span class="badge badge-ok">' + t('guLicentieVrij') + '</span>';
  } else if (s.ok) {
    badge = '<span class="badge badge-ok">' + tf('guLicentieGeldigTot', { datum: s.geldigTot }) + '</span>';
  } else {
    badge = '<span class="badge badge-leeg" title="' + escapeAttr(s.reden || '') + '">' + t('guLicentieGeblokkeerd') + '</span>';
  }
  html += '<p>' + badge + '</p>';
  if (!s.ok) {
    html += '<p class="form-hint">' + t('profLicentieBlokkadeHint') + '</p>';
  }

  html += '<div class="section-title">' + t('profWachtwoordTitle') + '</div>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('profHuidigWachtwoord') + '</label><input type="password" id="profHuidigWachtwoord"></div>';
  html += '<div class="form-group"><label>' + t('profNieuwWachtwoord') + '</label><input type="password" id="profNieuwWachtwoord"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="profWachtwoordSubmit">' + t('profWachtwoordOpslaan') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.getElementById('profWachtwoordSubmit').addEventListener('click', function () {
    const btn = this;
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;
    apiPost('api/mijn_profiel.php', {
      actie: 'wachtwoord_wijzigen',
      huidig_wachtwoord: document.getElementById('profHuidigWachtwoord').value,
      nieuw_wachtwoord: document.getElementById('profNieuwWachtwoord').value,
    }, function () {
      btn.disabled = false;
      document.getElementById('profHuidigWachtwoord').value = '';
      document.getElementById('profNieuwWachtwoord').value = '';
      btn.textContent = t('mdSaved');
      setTimeout(function () { btn.textContent = t('profWachtwoordOpslaan'); }, 1500);
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
}

function showGebruikers() {
  setRoute('gebruikers');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  Promise.all([
    fetch('api/gebruikers.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
    fetch('api/specialisten.php?legacy=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
    // Groepenlijst erbij, voor de groep-checkboxes per
    // gebruiker hieronder.
    fetch('api/groepen.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
  ]).then(function (results) {
    renderGebruikers(results[0], results[1], results[2]);
  });
}

// Kleine avatar-thumbnail of, zonder foto, een cirkel met de eerste letter
// van de naam -- gebruikt voor zowel gebruikersaccounts als producent-
// Contactpersonen.
function avatarHtml(foto, naam) {
  if (foto) {
    return '<img src="' + escapeAttr(foto) + '" class="avatar-thumb" alt="">';
  }
  const letter = (naam || '?').trim().charAt(0).toUpperCase() || '?';
  return '<span class="avatar-placeholder">' + escapeHtml(letter) + '</span>';
}

// Leaderboard met wie aan de beurt is uitgebreid met foto en minimaal 3
// personen op volgorde van wie aan de beurt is: gedeelde renderer voor
// zowel de Home-widget (renderHomeWidgets()) als de Team & Prestaties-
// dimensie op Rapportage (renderRapportage()) -- beide toonden voorheen
// alleen de eerstvolgende persoon als platte "{procedure}: {naam}"-tekst
// (hwAanDeBeurt). Nu per procedure een rijtje van de eerste 3 personen in
// de rotatie-volgorde (getPiccRotatie() in includes/leaderboard.php geeft
// altijd de VOLLEDIGE volgorde terug, hier alleen begrensd tot 3 voor de
// weergave), elk met avatarHtml() (foto of letter-cirkel) zodat je in één
// oogopslag ziet wie na de eerstvolgende aan de beurt is.
function piccRotatieHtml(piccRotaties) {
  if (!piccRotaties || piccRotaties.length === 0) {
    return '<p class="empty">' + t('hwGeenPiccProcedures') + '</p>';
  }
  let html = '';
  piccRotaties.forEach(function (r) {
    if (!r.volgorde || r.volgorde.length === 0) return;
    html += '<div class="picc-procedure-rij">';
    html += '<div class="picc-procedure-naam">' + escapeHtml(r.procedureType) + '</div>';
    html += '<div class="picc-volgorde">';
    r.volgorde.slice(0, 3).forEach(function (p, idx) {
      html += '<div class="picc-persoon' + (idx === 0 ? ' picc-persoon-eerst' : '') + '">'
        + avatarHtml(p.foto, p.naam)
        + '<span class="picc-persoon-naam">' + escapeHtml(p.naam) + '</span>'
        + (idx === 0 ? '<span class="picc-persoon-badge">' + t('hwPiccUpNext') + '</span>' : '')
        + '</div>';
    });
    html += '</div></div>';
  });
  return html;
}

// ("productfoto's
// uitbreiden waar mogelijk"): zelfde duimnagel-idee als avatarHtml()
// hierboven, maar vierkant (product, geen gezicht) -- gedeeld tussen
// Product Zoeken en Bijvullen zodat een rij ook zonder naar de
// productdetailpagina te klikken al herkenbaar is.
function listThumbHtml(foto, naam) {
  if (foto) {
    return '<img src="' + escapeAttr(foto) + '" class="list-thumb" alt="">';
  }
  const letter = (naam || '?').trim().charAt(0).toUpperCase() || '?';
  return '<span class="list-thumb-placeholder">' + escapeHtml(letter) + '</span>';
}

// Weergavenaam voor een users.rol-waarde (specialist/
// laborant/bekijker) -- gedeeld door Gebruikers en Leaderboard, zie ook
// includes/leaderboard.php::getMeesteProcedures() dat sindsdien dezelfde
// rol-waarden teruggeeft (voorheen specialisten.type).
function functieLabel(rol) {
  return { specialist: t('guRoleSpecialist'), laborant: t('guRoleLaborant'), bekijker: t('guRoleBekijker') }[rol] || rol || '-';
}

// Weergavenaam voor het OUDE specialisten.type-veld (arts/laborant/
// overig) -- sindsdien alleen nog gebruikt voor de "Nog te
// migreren"-lijst op het Gebruikers-scherm (namen uit de opgeheven
// 'specialisten'-tabel zonder gekoppeld account, zie
// getNietGemigreerdeSpecialisten()). Niet te verwarren met
// functieLabel() hierboven, dat de nieuwe users.rol-waarden weergeeft.
function specialistTypeLabel(type) {
  const map = { arts: t('mdTypeArts'), laborant: t('mdTypeLaborant'), overig: t('mdTypeOverig') };
  return map[type] || t('mdTypeArts');
}

/**
 * Licentiestatus-badge + "Code
 * koppelen"-knop per gebruiker op het Gebruikers-scherm. u.licentieStatus
 * komt kant-en-klaar van de server (api/gebruikers.php, LIVE
 * geverifieerd via includes/license.php::controleerLicentieGebruiker())
 * -- dit rendert alleen, er wordt hier niets zelf beoordeeld.
 */
function licentieStatusHtml(u) {
  const s = u.licentieStatus || {};
  let badge;
  if (s.vrij) {
    badge = '<span class="badge badge-ok">' + t('guLicentieVrij') + '</span>';
  } else if (s.ok) {
    badge = '<span class="badge badge-ok">' + tf('guLicentieGeldigTot', { datum: s.geldigTot }) + '</span>';
  } else {
    badge = '<span class="badge badge-leeg" title="' + escapeAttr(s.reden || '') + '">' + t('guLicentieGeblokkeerd') + '</span>';
  }
  // Vrije (gratis) gebruiker heeft nooit een code nodig -- geen knop.
  if (s.vrij) return badge;
  return badge + ' <button class="action-btn gu-licentie-koppel-btn" data-id="' + u.id + '" data-naam="' + escapeAttr(u.naam) + '">' + t('guLicentieKoppelen') + '</button>';
}

function renderGebruikers(data, legacy, groepen) {
  // "alle knoppen op deze pagina" moeten
  // links uitgelijnde tekst hebben i.p.v. de standaard-gecentreerde
  // knoptekst van de browser (zie .gu-page button hieronder in
  // assets/css/style.css) -- .gu-page is puur een scope-klasse, geen eigen
  // opmaak, zodat dit alleen dit scherm raakt en niet elke .action-btn/
  // .edit-save-knop door de rest van de app.
  let html = '<div class="gu-page">';
  html += '<h2>' + t('users') + '</h2>';

  // Installatiebreed feit, meegestuurd
  // op elke rij (dit endpoint geeft een kale JSON-array terug, geen
  // {data:[...]}-envelop) -- de eerste rij volstaat om het te lezen.
  if (Array.isArray(data) && data[0] && data[0].licentieAnomalieAantal > 1) {
    html += '<div class="login-error" style="display:block;margin-bottom:16px;">'
      + tf('guLicentieAnomalie', { aantal: data[0].licentieAnomalieAantal }) + '</div>';
  }

  const functieOpties = ['specialist', 'laborant', 'bekijker'];
  // Groepenlijst kan leeg zijn (bv. als api/groepen.php om
  // wat voor reden dan ook geen array teruggeeft) -- val dan terug op een
  // lege lijst i.p.v. dat de rest van dit scherm crasht.
  const groepenLijst = Array.isArray(groepen) ? groepen : [];

  // Eén set groep-checkboxes, herbruikt voor zowel elke bestaande rij als
  // het "nieuwe gebruiker"-formulier onderaan. aangevinkt is een array van
  // groep-id's die al aangevinkt moeten staan (leeg voor een nieuw account).
  function groepCheckboxesHtml(aangevinkt) {
    if (groepenLijst.length === 0) return '<span class="form-hint">' + t('guGeenGroepen') + '</span>';
    return groepenLijst.map(function (g) {
      const checked = aangevinkt.indexOf(g.id) !== -1 ? ' checked' : '';
      return '<label class="gu-groep-optie"><input type="checkbox" class="gu-groep-check" value="' + g.id + '"' + checked + '> ' + escapeHtml(g.naam) + '</label>';
    }).join('');
  }

  // Compacte
  // "chips" met de namen van de al-toegekende groepen (i.p.v. de altijd
  // opengeklapte checkbox-doos die de tabelrij eerst onnodig hoog maakte).
  // Het bewerkbare checkbox-paneel zelf (groepCheckboxesHtml) klapt pas open
  // via de losse "Groepen wijzigen"-knop, zie .gu-groepen-toggle hieronder.
  function groepenChipsHtml(aangevinkt) {
    if (!aangevinkt || aangevinkt.length === 0) {
      return '<span class="gu-groepen-leeg">' + t('guGeenGroepenGekozen') + '</span>';
    }
    return aangevinkt.map(function (id) {
      const g = groepenLijst.find(function (gg) { return gg.id === id; });
      return '<span class="gu-groepen-chip">' + escapeHtml(g ? g.naam : '?') + '</span>';
    }).join('');
  }

  if (Array.isArray(data)) {
    // ("user pagina
    // blijft onoverzichtelijk. Zet de gegevens Name/Email/Function/Groups/
    // Rights/Language/Last login/License onderelkaar met daarachter de
    // Edit knoppen"): de brede 10-koloms-tabel was nog
    // steeds moeilijk leesbaar zodra het venster niet extreem breed is --
    // elke kolom moest vechten om ruimte. Vervangen door één kaart per
    // gebruiker (.gu-card): elk veld op zijn eigen rij (label links,
    // waarde rechts, zie .gu-field in style.css), en alle bewerk-/
    // actieknoppen samen in een actiebalk ONDER de velden
    // (.gu-card-actions) -- exact "de gegevens onder elkaar, met daarachter
    // de edit-knoppen". De classnamen op elk veld (.gu-naam-input,
    // .gu-rol-select, .gu-groepen-cell, .gu-rechten-cell, .gu-taal-select,
    // enz.) en dus ALLE click-/change-handlers verderop in deze functie
    // blijven ongewijzigd -- alleen de omliggende <table>/<tr>/<td>-
    // structuur is vervangen door <div>'s.
    html += '<div class="gu-cards">';
    data.forEach(function (u) {
      const rolOpties = functieOpties.map(function (r) {
        return '<option value="' + r + '"' + (u.rol === r ? ' selected' : '') + '>' + functieLabel(r) + '</option>';
      }).join('');
      const groepenVanUser = Array.isArray(u.groepen) ? u.groepen : [];
      html += '<div class="gu-card">'
        + '<div class="gu-card-header">' + avatarHtml(u.foto, u.naam)
        // Feedback-geschiedenis: "Naam, e-mail van gebruikers zijn niet
        // aan te passen. Maak dit aanpasbaar." -> "Het wijzigen van
        // gebruikers gegevens moet via een knop i.p.v. een tekstveld die
        // ik direct kan aanpassen." -> "de weergave van naam en e-mail
        // is lelijk, een soort box waar de tekst niet leesbaar in is":
        // de waarde staat standaard in een gewone <span>
        // (.gu-naam-tekst/.gu-email-tekst, breekt normaal af over meerdere
        // regels, geen enkele opmaak die op een invoerveld lijkt) en het
        // <input> ernaast is standaard `display:none` -- pas de "Naam/
        // e-mail bewerken"-knop (.gu-naam-email-toggle, in de actiebalk
        // onderaan deze kaart) wisselt tekst en invoerveld om. De
        // "Opslaan"-actie (.gu-opslaan-btn) leest de huidige waarde uit wat
        // er op dat moment zichtbaar is (het invoerveld tijdens bewerken,
        // anders de tekst zelf), dus opslaan werkt in beide standen.
        + '<span class="gu-naam-tekst gu-card-naam" data-id="' + u.id + '">' + escapeHtml(u.naam) + '</span>'
        + '<input type="text" class="gu-naam-input" data-id="' + u.id + '" value="' + escapeAttr(u.naam) + '" style="display:none;">'
        + '</div>';
      html += '<div class="gu-card-body">';
      html += '<div class="gu-field"><span class="gu-field-label">' + t('colEmail') + '</span>'
        + '<span class="gu-email-tekst" data-id="' + u.id + '">' + escapeHtml(u.email) + '</span>'
        + '<input type="email" class="gu-email-input" data-id="' + u.id + '" value="' + escapeAttr(u.email) + '" style="display:none;">'
        + '</div>';
      html += '<div class="gu-field"><span class="gu-field-label">' + t('guFunctie') + '</span>'
        + '<select class="gu-rol-select" data-id="' + u.id + '">' + rolOpties + '</select></div>';
      html += '<div class="gu-field"><span class="gu-field-label">' + t('guGroepen') + '</span>'
        + '<div class="gu-groepen-cell" data-id="' + u.id + '">'
        + '<div class="gu-groepen-chips">' + groepenChipsHtml(groepenVanUser) + '</div>'
        + '<button type="button" class="action-btn gu-groepen-toggle" data-id="' + u.id + '">' + t('guGroepenWijzigen') + '</button>'
        + '<div class="gu-groepen-panel" id="guGroepenPanel' + u.id + '" style="display:none;">' + groepCheckboxesHtml(groepenVanUser) + '</div>'
        + '</div></div>';
      // : het vinkje stond kaal, zonder enige tekst
      // ernaast -- je moest voor de betekenis terug naar het kolomlabel
      // kijken. Nu staat het vinkje VOOR een eigen label (zelfde volgorde
      // als het "nieuwe gebruiker"-formulier onderaan), zodat het meteen
      // duidelijk is wat het vinkje betekent. Beheerder+Actief zijn sinds
      // sindsdien samen onder één "Rechten"-veld gestapeld.
      html += '<div class="gu-field"><span class="gu-field-label">' + t('guRechten') + '</span>'
        + '<div class="gu-rechten-cell">'
        + '<label class="inline-check"><input type="checkbox" class="gu-beheerder-check" data-id="' + u.id + '"' + (u.is_beheerder ? ' checked' : '') + '> ' + t('guBeheerderVlag') + '</label>'
        + '<label class="inline-check"><input type="checkbox" class="gu-actief-check" data-id="' + u.id + '"' + (u.actief ? ' checked' : '') + '> ' + t('colActive') + '</label>'
        + '</div></div>';
      // Taal van een gebruiker moet vanuit dit overzicht wijzigbaar
      // zijn -- zelfde dropdown-patroon als Functie
      // hierboven, meegestuurd in dezelfde "Opslaan"-actie per kaart.
      html += '<div class="gu-field"><span class="gu-field-label">' + t('colLanguage') + '</span>'
        + '<select class="gu-taal-select" data-id="' + u.id + '">'
        + '<option value="en"' + ((u.taal || 'en') === 'en' ? ' selected' : '') + '>EN</option>'
        + '<option value="nl"' + (u.taal === 'nl' ? ' selected' : '') + '>NL</option>'
        + '</select></div>';
      html += '<div class="gu-field"><span class="gu-field-label">' + t('colLastLogin') + '</span><span>' + (u.laatst_ingelogd || '-') + '</span></div>';
      html += '<div class="gu-field"><span class="gu-field-label">' + t('guLicentie') + '</span><span>' + licentieStatusHtml(u) + '</span></div>';
      html += '</div>'; // .gu-card-body

      // Alle bewerk-/actieknoppen (Naam/e-mail bewerken, Opslaan,
      // Wachtwoord resetten, Foto) staan samen in één actiebalk ONDER de
      // velden -- exact het "gegevens onder elkaar, met daarachter de
      // edit-knoppen"-patroon dat hiervoor nodig was.
      html += '<div class="gu-card-actions">'
        + '<button type="button" class="action-btn gu-naam-email-toggle" data-id="' + u.id + '">' + t('guNaamEmailWijzigen') + '</button>'
        + '<button class="action-btn gu-opslaan-btn" data-id="' + u.id + '">' + t('save') + '</button>'
        // Wachtwoord resetten vanuit dit overzicht --
        // los van de "Opslaan"-knop hierboven (die alleen rol/beheerder/
        // actief/groepen bijwerkt), want dit is een onafhankelijke actie
        // die geen van de kaartvelden nodig heeft.
        + '<button class="action-btn gu-wachtwoord-reset-btn" data-id="' + u.id + '" data-naam="' + escapeAttr(u.naam) + '">' + t('guWachtwoordResetten') + '</button>'
        + '<input type="file" accept="image/*" class="gu-foto-input" data-id="' + u.id + '" style="display:none;">'
        + '<button class="action-btn gu-foto-btn" data-id="' + u.id + '">' + t('fotoUpload') + '</button>'
        + (u.foto ? '<button class="action-btn gu-foto-remove-btn" data-id="' + u.id + '">' + t('fotoRemove') + '</button>' : '')
        + '</div>'; // .gu-card-actions
      html += '</div>'; // .gu-card
    });
    html += '</div>'; // .gu-cards
  }
  html += '<p class="form-hint">' + t('guHint') + '</p>';
  html += '<p class="form-hint">' + t('guFunctieHint') + '</p>';
  // Directe link naar het Groepen-scherm zelf, want het
  // aanmaken/bewerken van een groep (welke schermen, schrijftoegang) hoort
  // hier niet thuis -- dat gebeurt op het aparte Groepen-scherm, dit
  // scherm koppelt alleen gebruikers aan al bestaande groepen.
  html += '<p><button class="action-btn" id="guNaarGroepen">' + t('guNaarGroepen') + '</button></p>';

  // "Nog te migreren" : namen uit de
  // oude Stamgegevens-lijst die nog geen echt inlogaccount hebben. Er
  // komen geen nieuwe rijen meer bij -- dit is puur de resterende,
  // aflopende lijst van vóór de samenvoeging van specialisten met
  // gebruikers. "Account aanmaken" vult alleen de naam vast in het
  // aanmaakformulier hieronder in; e-mail/wachtwoord/functie vult de
  // beheerder zelf nog in.
  if (Array.isArray(legacy) && legacy.length > 0) {
    html += '<div class="section-title">' + t('guLegacyTitle') + '</div>';
    html += '<p class="form-hint">' + t('guLegacyHint') + '</p>';
    html += '<table><tr><th>' + t('colName') + '</th><th>' + t('colType') + '</th><th></th></tr>';
    legacy.forEach(function (s) {
      html += '<tr><td>' + escapeHtml(s.naam) + '</td><td>' + escapeHtml(specialistTypeLabel(s.type)) + '</td>'
        + '<td><button class="action-btn gu-legacy-migrate-btn" data-naam="' + escapeAttr(s.naam) + '">' + t('guLegacyCreateAccount') + '</button></td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-title" id="guNewUserSection">' + t('guNewUser') + '</div>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="guNaam"></div>';
  html += '<div class="form-group"><label>' + t('colEmail') + '</label><input type="email" id="guEmail"></div>';
  html += '<div class="form-group"><label>' + t('guTempPassword') + '</label><input type="text" id="guWachtwoord"></div>';
  html += '<div class="form-group"><label>' + t('guFunctie') + '</label><select id="guRol">'
    + functieOpties.map(function (r) { return '<option value="' + r + '">' + functieLabel(r) + '</option>'; }).join('')
    + '</select></div>';
  html += '<div class="form-group"><label><input type="checkbox" id="guBeheerder"> ' + t('guBeheerderVlag') + '</label></div>';
  html += '<div class="form-group"><label>' + t('guLanguage') + '</label><select id="guTaal"><option value="en">English</option><option value="nl">Nederlands</option></select></div>';
  // Deze div is GEEN tabelcel (staat los onder het formulier),
  // dus krijgt de doos-stijl (.gu-groepen-panel, voorheen op .gu-groepen-cell
  // zelf) rechtstreeks en altijd zichtbaar -- hier is geen chips/toggle-
  // gedrag nodig, er valt bij een nieuw account nog niets samen te vatten.
  html += '<div class="form-group"><label>' + t('guGroepen') + '</label><div class="gu-groepen-panel" id="guGroepenNieuw">' + groepCheckboxesHtml([]) + '</div></div>';
  // ("Licentiemodel:
  // registratie blokkeren zonder geldige licentie"): een nieuw account kan
  // niet meer aangemaakt worden zonder een geldige activatiecode + het
  // bevestigde besteladres -- zie api/gebruikers.php voor de serverzijdige
  // afdwinging (includes/license.php::valideerActivatiecodeVoorRegistratie()).
  // Bewust gewone tekstvelden i.p.v. de prompt()-dialogen van "Code
  // koppelen" hierboven, want dit hoort nu bij het aanmaakformulier zelf.
  html += '<div class="section-subtitle">' + t('guLicentieRegistratieTitle') + '</div>';
  html += '<p class="form-hint">' + t('guLicentieRegistratieHint') + '</p>';
  html += '<div class="form-group"><label>' + t('guLicentieCodeLabel') + '</label><input type="text" id="guLicentieCode"></div>';
  html += '<div class="form-group"><label>' + t('guLicentieEmailLabel') + '</label><input type="email" id="guLicentieEmail"></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="guSubmit">' + t('guCreate') + '</button></div>';
  html += '</div>'; // sluit .gu-page

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  wire('guNaarGroepen', showGroepen);

  document.getElementById('guSubmit').addEventListener('click', function () {
    const btn = this;
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    const gekozenGroepen = Array.prototype.slice.call(
      document.querySelectorAll('#guGroepenNieuw .gu-groep-check:checked')
    ).map(function (el) { return parseInt(el.value, 10); });

    apiPost('api/gebruikers.php', {
      naam: document.getElementById('guNaam').value,
      email: document.getElementById('guEmail').value,
      wachtwoord: document.getElementById('guWachtwoord').value,
      rol: document.getElementById('guRol').value,
      is_beheerder: document.getElementById('guBeheerder').checked,
      taal: document.getElementById('guTaal').value,
      groepen: gekozenGroepen,
      licentie_code: document.getElementById('guLicentieCode').value,
      licentie_bevestigd_email: document.getElementById('guLicentieEmail').value,
    }, function () {
      showGebruikers();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });

  // Het
  // Groepen-checkbox-paneel staat standaard dicht (zie groepenChipsHtml()
  // hierboven) en klapt alleen open/dicht via deze knop -- zelfde
  // toggle-tekst-patroon als .lot-loc-toggle op de productdetailpagina
  //. De checkboxes binnenin blijven altijd bevraagbaar via
  // querySelectorAll, ook terwijl het paneel dicht (display:none) staat,
  // dus de bestaande .gu-groepen-cell[data-id] .gu-groep-check:checked
  // selector in de Opslaan-handler hieronder werkt ongewijzigd door.
  document.querySelectorAll('.gu-groepen-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      const panel = document.getElementById('guGroepenPanel' + id);
      const nuOpen = panel.style.display === 'none';
      panel.style.display = nuOpen ? 'block' : 'none';
      this.textContent = nuOpen ? t('guGroepenSluiten') : t('guGroepenWijzigen');
    });
  });

  // Feedback-geschiedenis (eerst "moet via een knop i.p.v. een
  // tekstveld dat ik direct kan aanpassen", later "de weergave van
  // naam/e-mail is lelijk, een soort box waar de tekst niet leesbaar in
  // is"): sindsdien staat de waarde standaard in een gewone
  // <span> (.gu-naam-tekst/.gu-email-tekst, breekt normaal af, geen
  // invoerveld-opmaak) en ligt het <input> er verborgen (`display:none`)
  // naast. Deze knop wisselt tekst <-> invoerveld om i.p.v. voorheen alleen
  // het `readonly`-attribuut te (de)activeren op een altijd-zichtbaar
  // invoerveld. Nogmaals klikken (zonder "Opslaan") verbergt het
  // invoerveld weer en toont de tekst-span ongewijzigd -- er is dus geen
  // aparte "terugzet naar vorige waarde"-stap meer nodig, de span is
  // simpelweg nooit aangepast.
  document.querySelectorAll('.gu-naam-email-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      const naamTekst = document.querySelector('.gu-naam-tekst[data-id="' + id + '"]');
      const emailTekst = document.querySelector('.gu-email-tekst[data-id="' + id + '"]');
      const naamInput = document.querySelector('.gu-naam-input[data-id="' + id + '"]');
      const emailInput = document.querySelector('.gu-email-input[data-id="' + id + '"]');
      const magNuBewerken = naamInput.style.display === 'none';
      if (magNuBewerken) {
        naamInput.value = naamTekst.textContent;
        emailInput.value = emailTekst.textContent;
        naamTekst.style.display = 'none';
        emailTekst.style.display = 'none';
        naamInput.style.display = '';
        emailInput.style.display = '';
        this.textContent = t('guNaamEmailSluiten');
        naamInput.focus();
      } else {
        naamInput.style.display = 'none';
        emailInput.style.display = 'none';
        naamTekst.style.display = '';
        emailTekst.style.display = '';
        this.textContent = t('guNaamEmailWijzigen');
      }
    });
  });

  // Eén "Opslaan" per rij i.p.v. een los koppel-veld --
  // functie, beheerder-vlag en actief-status van een bestaand account
  // wijzigen nu in één keer, zie api/gebruikers.php actie
  // 'gebruiker_bijwerken'.
  document.querySelectorAll('.gu-opslaan-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      const rol = document.querySelector('.gu-rol-select[data-id="' + id + '"]').value;
      const isBeheerder = document.querySelector('.gu-beheerder-check[data-id="' + id + '"]').checked;
      const actief = document.querySelector('.gu-actief-check[data-id="' + id + '"]').checked;
      // Taal is nu ook per rij wijzigbaar, in dezelfde "Opslaan"-actie
      // als functie/beheerder/actief/groepen.
      const taal = document.querySelector('.gu-taal-select[data-id="' + id + '"]').value;
      // Naam/e-mail zitten al in deze rij, en staan inmiddels in een
      // tekst-span die pas via de "Naam/e-mail bewerken"-knop wordt
      // vervangen door een zichtbaar <input> (zie renderGebruikers()
      // hierboven en de .gu-naam-email-toggle click-handler). Deze rij kan
      // dus in twee standen staan op het moment van Opslaan: de waarde
      // wordt daarom gelezen uit wat er op dat moment ZICHTBAAR is (het
      // invoerveld tijdens bewerken, anders de tekst-span). api/gebruikers.php
      // registreert bij een gewijzigde naam automatisch de hernoeming (zie
      // includes/hernoemingen.php) en geeft een nette foutmelding terug bij
      // een dubbel e-mailadres (users.email is UNIQUE).
      const naamTekst = document.querySelector('.gu-naam-tekst[data-id="' + id + '"]');
      const emailTekst = document.querySelector('.gu-email-tekst[data-id="' + id + '"]');
      const naamInput = document.querySelector('.gu-naam-input[data-id="' + id + '"]');
      const emailInput = document.querySelector('.gu-email-input[data-id="' + id + '"]');
      const bewerktNu = naamInput.style.display !== 'none';
      const naam = (bewerktNu ? naamInput.value : naamTekst.textContent).trim();
      const email = (bewerktNu ? emailInput.value : emailTekst.textContent).trim();
      // Groepslidmaatschap zit in dezelfde rij-checkboxes als
      // de rest van dit account, dus in dezelfde "Opslaan"-actie meesturen.
      const gekozenGroepen = Array.prototype.slice.call(
        document.querySelectorAll('.gu-groepen-cell[data-id="' + id + '"] .gu-groep-check:checked')
      ).map(function (el) { return parseInt(el.value, 10); });
      apiPost('api/gebruikers.php', { actie: 'gebruiker_bijwerken', id: id, rol: rol, is_beheerder: isBeheerder, actief: actief, taal: taal, naam: naam, email: email, groepen: gekozenGroepen }, function () {
        // Tekst-span bijwerken naar de zojuist opgeslagen waarde, en als de
        // rij nog "ontgrendeld" stond (bewerktNu), weer terug naar de
        // vergrendelde weergave -- opslaan sluit het bewerkveld dus meteen
        // mee, geen aparte klik op "Naam/e-mail bewerken" nodig om het
        // weer dicht te klikken.
        naamTekst.textContent = naam;
        emailTekst.textContent = email;
        if (bewerktNu) {
          naamInput.style.display = 'none';
          emailInput.style.display = 'none';
          naamTekst.style.display = '';
          emailTekst.style.display = '';
          const toggleBtn = document.querySelector('.gu-naam-email-toggle[data-id="' + id + '"]');
          if (toggleBtn) toggleBtn.textContent = t('guNaamEmailWijzigen');
        }
        btn.textContent = t('mdSaved');
        setTimeout(function () { btn.textContent = t('save'); }, 1500);
      }, function (err) {
        alert(err.error || t('somethingWrong'));
      });
    });
  });

  // : wachtwoord resetten vanuit het Gebruikers-
  // overzicht. Zelfde prompt()-patroon als "Code koppelen" hieronder --
  // een zeldzame beheerdersactie die maar één waarde nodig heeft, geen
  // apart modaal waard. Het nieuwe wachtwoord wordt NIET gemaild (geen
  // mailafhankelijkheid voor dit soort acties, zie webhook/licentie-mail
  // die dat wel doet maar via een aparte, optionele configuratie) -- de
  // beheerder geeft het zelf door aan de gebruiker.
  document.querySelectorAll('.gu-wachtwoord-reset-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      const naam = this.getAttribute('data-naam');
      const nieuw = prompt(tf('guWachtwoordResetPrompt', { naam: naam }));
      if (nieuw === null) return;
      if (nieuw.length < 8) {
        alert(t('guWachtwoordTeKort'));
        return;
      }
      btn.disabled = true;
      apiPost('api/gebruikers.php', { actie: 'wachtwoord_resetten', id: id, wachtwoord: nieuw }, function () {
        btn.disabled = false;
        alert(t('guWachtwoordResetGelukt'));
      }, function (err) {
        btn.disabled = false;
        alert(err.error || t('somethingWrong'));
      });
    });
  });

  // Activatiecode koppelen aan één
  // specifieke gebruiker -- een simpele prompt() volstaat hier (geen
  // apart modaal nodig, dit is een zeldzame beheerdersactie), zie
  // includes/license.php::koppelLicentieCode().
  document.querySelectorAll('.gu-licentie-koppel-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = this.getAttribute('data-id');
      const naam = this.getAttribute('data-naam');
      const code = prompt(tf('guLicentieCodePrompt', { naam: naam }));
      if (!code || !code.trim()) return;
      // Naast de code vraagt de beheerder ook een
      // bevestiging van het besteladres (niet het e-mailadres van het
      // doelaccount) -- zie includes/license.php::koppelLicentieCode().
      const bevestigdEmail = prompt(tf('guLicentieEmailPrompt'));
      if (!bevestigdEmail || !bevestigdEmail.trim()) return;
      btn.disabled = true;
      apiPost('api/gebruikers.php', { actie: 'licentie_koppelen', id: id, code: code.trim(), bevestigd_email: bevestigdEmail.trim() }, function (result) {
        // Bugfix: koppelLicentieCode() geeft bij een
        // onjuiste/ongeldige code gewoon {success:false, error:'...'} terug
        // (net als alle andere business-foutmeldingen in deze app, zie
        // includes/api_helpers.php::apiTry() -- dat blijft altijd HTTP 200,
        // de aanroeper hoort zelf op .success te controleren). Deze
        // callback deed dat niet en toonde bij een foute code dus altijd
        // de "gekoppeld"-melding i.p.v. de echte foutmelding.
        btn.disabled = false;
        if (!result.success) {
          alert(result.error || t('somethingWrong'));
          return;
        }
        alert(result.message || t('guLicentieGekoppeld'));
        showGebruikers();
      }, function (err) {
        btn.disabled = false;
        alert(err.error || t('somethingWrong'));
      });
    });
  });

  document.querySelectorAll('.gu-legacy-migrate-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('guNaam').value = this.getAttribute('data-naam');
      document.getElementById('guNewUserSection').scrollIntoView({ behavior: 'smooth' });
      document.getElementById('guNaam').focus();
    });
  });

  // Profielfoto per gebruiker: de zichtbare knop opent de
  // verborgen bestandsinput, en de eigenlijke upload gebeurt zodra daarin
  // een bestand gekozen is (change-event) -- geen aparte "bevestigen"-stap,
  // consistent met hoe een os-bestandskiezer normaal werkt.
  document.querySelectorAll('.gu-foto-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelector('.gu-foto-input[data-id="' + this.getAttribute('data-id') + '"]').click();
    });
  });
  document.querySelectorAll('.gu-foto-input').forEach(function (input) {
    input.addEventListener('change', function () {
      const id = this.getAttribute('data-id');
      const file = this.files && this.files[0];
      readImageFileAsDataUrl(file, function (dataUrl) {
        apiPost('api/gebruikers.php', { actie: 'foto_bijwerken', id: id, foto: dataUrl }, function () {
          showGebruikers();
        }, function (err) {
          alert(err.error || t('somethingWrong'));
        });
      }, function (errMsg) {
        alert(errMsg);
      });
    });
  });
  document.querySelectorAll('.gu-foto-remove-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm(t('fotoRemoveConfirm'))) return;
      apiPost('api/gebruikers.php', { actie: 'foto_verwijderen', id: this.getAttribute('data-id') }, function () {
        showGebruikers();
      }, function (err) {
        alert(err.error || t('somethingWrong'));
      });
    });
  });
}

// -----------------------------------------------------------------
// Groepen : het permissiesysteem
// bovenop rol/is_beheerder -- een groep bepaalt welke schermen zichtbaar/
// toegankelijk zijn en of leden mogen schrijven, zie includes/groepen.php
// en includes/schermen.php voor het volledige ontwerp. Alleen beheerder
// ziet dit scherm (api/groepen.php is beheerder-only), maar er is geen
// aparte frontend-check nodig -- de menu-knop zelf is al verborgen via
// heeftScherm('groepen') in showHome(), en 'groepen' is beheerderOnly in
// SCHERMEN dus heeftScherm() geeft daar alleen true voor is_beheerder.
// -----------------------------------------------------------------
function showGroepen() {
  setRoute('groepen');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetch('api/groepen.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(renderGroepenOverzicht);
}

function renderGroepenOverzicht(data) {
  let html = '<h2>' + t('groepenTitle') + '</h2>';
  html += '<p class="form-hint">' + t('groepenHint') + '</p>';

  if (!Array.isArray(data) || data.length === 0) {
    html += '<p class="empty">' + t('groepenEmpty') + '</p>';
  } else {
    html += '<table><tr><th>' + t('groepNaam') + '</th><th>' + t('groepMagSchrijven') + '</th>'
      + '<th>' + t('groepAantalLeden') + '</th><th>' + t('groepAantalSchermen') + '</th><th></th></tr>';
    data.forEach(function (g) {
      html += '<tr>'
        + '<td><a class="groep-link" data-groep-id="' + g.id + '">' + escapeHtml(g.naam) + '</a></td>'
        + '<td>' + (g.magSchrijven ? t('yesLabel') : t('noLabel')) + '</td>'
        + '<td>' + g.aantalLeden + '</td>'
        + '<td>' + g.aantalSchermen + '</td>'
        + '<td><button class="action-btn groep-link" data-groep-id="' + g.id + '">' + t('editLabel') + '</button></td>'
        + '</tr>';
    });
    html += '</table>';
  }

  html += '<div class="edit-actions"><button class="edit-save" id="groepNieuwBtn">' + t('groepNieuw') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.groep-link').forEach(function (el) {
    el.addEventListener('click', function () { showGroepDetail(this.getAttribute('data-groep-id')); });
  });
  document.getElementById('groepNieuwBtn').addEventListener('click', function () {
    fetch('api/groepen.php?nieuw=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(renderGroepEdit);
  });
}

function showGroepDetail(id) {
  setRoute('groep-detail', id);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  fetch('api/groepen.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(renderGroepEdit);
}

// Gedeeld tussen "nieuwe groep aanmaken" (data.id === null, van
// getGroepTemplate()) en "bestaande groep bewerken" (van
// getGroepDetail()) -- zelfde formulier, alleen de opslaan-actie en of er
// een ledenlijst/verwijder-knop bij staat verschillen.
function renderGroepEdit(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const isNieuw = !data.id;
  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + (isNieuw ? t('groepNieuw') : escapeHtml(data.naam)) + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  html += '<div class="form-group"><label>' + t('groepNaam') + '</label><input type="text" id="groepNaamInput" value="' + escapeAttr(data.naam || '') + '"></div>';
  html += '<div class="form-group"><label><input type="checkbox" id="groepMagSchrijvenInput"' + (data.magSchrijven ? ' checked' : '') + '> ' + t('groepMagSchrijven') + '</label></div>';

  // Schermmatrix, gegroepeerd per sectie (formulieren/dashboards/beheer --
  // zie includes/schermen.php::SCHERMEN) zodat een lange platte lijst van
  // 32 checkboxes niet één ondoorzichtig blok wordt.
  html += '<div class="section-title">' + t('groepSchermenTitel') + '</div>';
  const secties = data.schermenPerSectie || {};
  const sectieLabels = { formulieren: t('groepSectieFormulieren'), dashboards: t('groepSectieDashboards'), beheer: t('groepSectieBeheer') };
  const aangevinkt = Array.isArray(data.schermen) ? data.schermen : [];
  Object.keys(secties).forEach(function (sectie) {
    html += '<div class="form-group"><strong>' + (sectieLabels[sectie] || sectie) + '</strong><br>';
    secties[sectie].forEach(function (s) {
      const checked = aangevinkt.indexOf(s.key) !== -1 ? ' checked' : '';
      html += '<label class="gu-groep-optie"><input type="checkbox" class="groep-scherm-check" value="' + escapeAttr(s.key) + '"' + checked + '> ' + t(s.labelKey) + '</label>';
    });
    html += '</div>';
  });

  // Welk scherm
  // leden van deze groep direct na inloggen zien i.p.v. altijd Home (zie
  // gebruikerLandingScherm() in includes/groepen.php). Opties = de
  // schermen die deze groep op dit moment aangevinkt heeft -- de server
  // valideert dit sowieso opnieuw bij opslaan (valideerLandingScherm()),
  // dus geen aparte live-sync met de checkboxes hierboven nodig: een
  // inmiddels ontbrekende keuze valt vanzelf terug op "Home (menu)".
  html += '<div class="section-title">' + t('groepLandingSchermTitel') + '</div>';
  html += '<p class="form-hint">' + t('groepLandingSchermHint') + '</p>';
  const alleSchermenFlat = [];
  Object.keys(secties).forEach(function (sectie) {
    secties[sectie].forEach(function (s) { alleSchermenFlat.push(s); });
  });
  html += '<div class="form-group"><select id="groepLandingSchermInput"><option value="">' + t('groepLandingSchermHome') + '</option>';
  alleSchermenFlat.filter(function (s) { return aangevinkt.indexOf(s.key) !== -1; }).forEach(function (s) {
    const selected = data.landingScherm === s.key ? ' selected' : '';
    html += '<option value="' + escapeAttr(s.key) + '"' + selected + '>' + t(s.labelKey) + '</option>';
  });
  html += '</select></div>';

  if (!isNieuw && Array.isArray(data.leden) && data.leden.length > 0) {
    html += '<div class="section-title">' + t('groepLeden') + '</div>';
    html += '<p>' + data.leden.map(function (l) { return escapeHtml(l.naam); }).join(', ') + '</p>';
  }

  html += '<div class="edit-actions">'
    + '<button class="edit-save" id="groepOpslaanBtn">' + t('save') + '</button>'
    + (isNieuw ? '' : ' <button class="edit-delete" id="groepVerwijderenBtn">' + t('deleteLabel') + '</button>')
    + '</div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.getElementById('groepOpslaanBtn').addEventListener('click', function () {
    const btn = this;
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    btn.disabled = true;

    const gekozenSchermen = Array.prototype.slice.call(
      document.querySelectorAll('.groep-scherm-check:checked')
    ).map(function (el) { return el.value; });

    apiPost('api/groepen.php', {
      actie: isNieuw ? 'aanmaken' : 'bewerken',
      id: data.id,
      naam: document.getElementById('groepNaamInput').value,
      mag_schrijven: document.getElementById('groepMagSchrijvenInput').checked,
      schermen: gekozenSchermen,
      landing_scherm: document.getElementById('groepLandingSchermInput').value,
    }, function () {
      showGroepen();
    }, function (err) {
      btn.disabled = false;
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });

  const verwijderBtn = document.getElementById('groepVerwijderenBtn');
  if (verwijderBtn) {
    verwijderBtn.addEventListener('click', function () {
      if (!confirm(t('groepVerwijderenConfirm'))) return;
      apiPost('api/groepen.php', { actie: 'verwijderen', id: data.id }, function () {
        showGroepen();
      }, function (err) {
        alert(err.error || t('somethingWrong'));
      });
    });
  }
}

// -----------------------------------------------------------------
// Producenten: contactgegevens (website, algemeen e-mail/telefoon,
// Contactpersonen) per leverancier/fabrikant. Iedereen
// die ingelogd is mag bekijken; alleen beheerder mag toevoegen/bewerken/
// verwijderen (api/producenten.php). artikelen.producent zelf blijft
// vrije tekst -- deze tabel wordt puur op naam gekoppeld, zie
// includes/producenten.php voor de volledige toelichting.
// -----------------------------------------------------------------
function showProducenten() {
  setRoute('producenten');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderProducenten).getProducenten();
}

function renderProducenten(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const isBeheerder = window.CURRENT_USER && !!window.CURRENT_USER.is_beheerder;

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('producenten') + '</h2>';
  html += '<p class="form-hint">' + t('prHint') + '</p>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('prEmpty') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colName') + '</th><th>' + t('colWebsite') + '</th><th>' + t('colEmail') + '</th><th>' + t('colPhone') + '</th><th>' + t('colContacts') + '</th><th>' + t('colLinkedProducts') + '</th></tr>';
    data.forEach(function (p) {
      html += '<tr><td><a class="producent-link" data-producent-id="' + p.id + '">' + escapeHtml(p.naam) + '</a></td>'
        + '<td>' + (p.website ? '<a href="' + escapeAttr(p.website) + '" target="_blank" rel="noopener">' + escapeHtml(p.website) + '</a>' : '-') + '</td>'
        + '<td>' + escapeHtml(p.email || '-') + '</td><td>' + escapeHtml(p.telefoon || '-') + '</td>'
        + '<td>' + p.aantalContacten + '</td><td>' + p.aantalArtikelen + '</td></tr>';
    });
    html += '</table>';
  }

  if (isBeheerder) {
    html += '<div class="section-title">' + t('prNewManufacturer') + '</div>';
    html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="prNaam"></div>';
    html += '<div class="form-group"><label>' + t('colWebsite') + '</label><input type="text" id="prWebsite" placeholder="https://..."></div>';
    html += '<div class="form-group"><label>' + t('colEmail') + '</label><input type="email" id="prEmail"></div>';
    html += '<div class="form-group"><label>' + t('colPhone') + '</label><input type="text" id="prTelefoon"></div>';
    html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="prOpmerking" rows="2"></textarea></div>';
    html += '<div class="edit-actions"><button class="edit-save" id="prToevoegen">' + t('addLabel') + '</button></div>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.producent-link').forEach(function (el) {
    el.addEventListener('click', function () { showProducentDetail(this.getAttribute('data-producent-id')); });
  });

  const toevoegenBtn = document.getElementById('prToevoegen');
  if (toevoegenBtn) {
    toevoegenBtn.addEventListener('click', function () {
      const errorEl = document.getElementById('formError');
      errorEl.style.display = 'none';
      const naam = document.getElementById('prNaam').value.trim();
      if (!naam) return;
      apiPost('api/producenten.php', {
        actie: 'toevoegen',
        naam: naam,
        website: document.getElementById('prWebsite').value,
        email: document.getElementById('prEmail').value,
        telefoon: document.getElementById('prTelefoon').value,
        opmerking: document.getElementById('prOpmerking').value,
      }, showProducenten, function (err) {
        errorEl.textContent = err.error || t('somethingWrong');
        errorEl.style.display = 'block';
      });
    });
  }
}

// =================================================================
// NIEUWS / CONTACTPERSONEN: waren met "Het Team" samen één dashboard -- Het
// Team is verhuisd naar het Team & Prestaties-dashboard (zie
// showTeamOverzicht() hierboven, TEAM_TABS). Nieuws en Contactpersonen
// blijven losse schermen, elk met een eigen scherm-recht. Zie
// includes/nieuws_dashboard.php voor de volledige toelichting per deel.
// =================================================================
function showNieuwsDashboard() {
  setRoute('nieuws');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderNieuwsDashboard).getNieuwsDashboard();
}

// Kleine herbruikbare "persoonskaart" (foto/placeholder + naam) voor het
// Team- (showTeamOverzicht hierboven) en Contactpersonen-scherm
// hieronder -- groter dan de avatarHtml()-duimnagel elders (Gebruikers/
// Producenten-tabellen), want hier is de foto zelf het punt van dit
// dashboard i.p.v. een klein identificatie-icoontje in een tabelrij.
function personCardHtml(foto, naam, subtekst) {
  const fotoHtml = foto
    ? '<img src="' + escapeAttr(foto) + '" class="person-card-photo" alt="">'
    : '<span class="person-card-placeholder">' + escapeHtml((naam || '?').charAt(0).toUpperCase()) + '</span>';
  return '<div class="person-card">' + fotoHtml
    + '<div class="person-card-naam">' + escapeHtml(naam) + '</div>'
    + (subtekst ? '<div class="person-card-sub">' + escapeHtml(subtekst) + '</div>' : '')
    + '</div>';
}

function renderNieuwsDashboard(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('newsTitle') + '</h2>';

  html += '<div class="section-subtitle">' + t('newsNewestProducts') + '</div>';
  if (data.nieuwsteProducten.length === 0) {
    html += '<p class="empty">' + t('newsNoProducts') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategory') + '</th><th>' + t('colManufacturer') + '</th></tr>';
    data.nieuwsteProducten.forEach(function (item) {
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td><td>' + escapeHtml(item.categorie || '-') + '</td><td>' + escapeHtml(item.producent || '-') + '</td></tr>';
    });
    html += '</table>';
  }

  html += '<div class="section-subtitle" style="margin-top:1rem;">' + t('newsUnavailableReplaced') + '</div>';
  if (data.vervangers.length === 0) {
    html += '<p class="empty">' + t('newsNoReplacements') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('newsReplacedBy') + '</th></tr>';
    data.vervangers.forEach(function (item) {
      html += '<tr><td><a class="product-link" data-ean="' + item.ean + '">' + escapeHtml(item.naam) + '</a></td>'
        + '<td><a class="product-link" data-ean="' + item.vervangerEan + '">' + escapeHtml(item.vervangerNaam) + '</a></td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });
}

function showContactpersonen() {
  setRoute('contactpersonen');
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderContactpersonen).getContactpersonenOverzicht();
}

function renderContactpersonen(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('newsContactsTitle') + '</h2>';

  if (data.length === 0) {
    html += '<p class="empty">' + t('newsNoContacts') + '</p>';
  } else {
    data.forEach(function (groep) {
      html += '<div class="section-subtitle"><a class="producent-link" data-producent-id="' + escapeAttr(groep.producentId) + '">' + escapeHtml(groep.producent) + '</a></div>';
      html += '<div class="people-grid">';
      groep.contacten.forEach(function (c) { html += personCardHtml(c.foto, c.naam, c.functie); });
      html += '</div>';
    });
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);
  document.querySelectorAll('.producent-link').forEach(function (el) {
    el.addEventListener('click', function () { showProducentDetail(this.getAttribute('data-producent-id')); });
  });
}

// =================================================================
// CONTACTEN: Producenten (bedrijven) en Contactpersonen (mensen,
// cross-bedrijf) waren twee losse
// menu-ingangen op dezelfde onderliggende data (contactpersonen horen al
// bij een producent) -- nu één pagina met een weergave-schakelaar i.p.v.
// twee aparte dashboards. Beide bestaande endpoints blijven ongewijzigd,
// dit hergebruikt gewoon de al bestaande tabel-/kaartweergave, alleen
// samen op één scherm.
// =================================================================
let contactenWeergave = 'personen';

function showContacten() {
  setRoute('contacten');
  contactenWeergave = 'personen';
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(function (producenten) {
    StocuroRun.withSuccessHandler(function (contactpersonen) {
      renderContacten(producenten, contactpersonen);
    }).getContactpersonenOverzicht();
  }).getProducenten();
}

function contactenBedrijvenHtml(data) {
  const isBeheerder = window.CURRENT_USER && !!window.CURRENT_USER.is_beheerder;
  let html = '';
  if (data.length === 0) {
    html += '<p class="empty">' + t('prEmpty') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colName') + '</th><th>' + t('colWebsite') + '</th><th>' + t('colEmail') + '</th><th>' + t('colPhone') + '</th><th>' + t('colContacts') + '</th><th>' + t('colLinkedProducts') + '</th></tr>';
    data.forEach(function (p) {
      html += '<tr><td><a class="producent-link" data-producent-id="' + p.id + '">' + escapeHtml(p.naam) + '</a></td>'
        + '<td>' + (p.website ? '<a href="' + escapeAttr(p.website) + '" target="_blank" rel="noopener">' + escapeHtml(p.website) + '</a>' : '-') + '</td>'
        + '<td>' + escapeHtml(p.email || '-') + '</td><td>' + escapeHtml(p.telefoon || '-') + '</td>'
        + '<td>' + p.aantalContacten + '</td><td>' + p.aantalArtikelen + '</td></tr>';
    });
    html += '</table>';
  }
  if (isBeheerder) {
    html += '<div class="section-title">' + t('prNewManufacturer') + '</div>';
    html += '<div id="formError" class="login-error" style="display:none;"></div>';
    html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="prNaam"></div>';
    html += '<div class="form-group"><label>' + t('colWebsite') + '</label><input type="text" id="prWebsite" placeholder="https://..."></div>';
    html += '<div class="form-group"><label>' + t('colEmail') + '</label><input type="email" id="prEmail"></div>';
    html += '<div class="form-group"><label>' + t('colPhone') + '</label><input type="text" id="prTelefoon"></div>';
    html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="prOpmerking" rows="2"></textarea></div>';
    html += '<div class="edit-actions"><button class="edit-save" id="prToevoegen">' + t('addLabel') + '</button></div>';
  }
  return html;
}

// Contactpersonen
// direct klikbaar maken voor hun gegevens, i.p.v. eerst te moeten
// doorklikken naar de producent-detailpagina. Telefoon/email zijn meteen
// tel:/mailto:-links.
//
// Bugfix: "Contacten pagina naast de foto ook de
// contact gegevens direct in beeld" -- de contactgegevens stonden
// hierboven verborgen achter een klik-om-uit-te-klappen kaartje (bewust
// zo gebouwd, maar bleek in de praktijk een extra stap
// te veel: laboranten willen bij een leverancier bellen/mailen zonder
// eerst elk kaartje open te hoeven klikken). Nu staan telefoon/email/
// opmerking gewoon altijd zichtbaar naast de foto, geen los klikstaatje
// (contactenOpenIndex/ctDetail-toggle) meer nodig.
function contactPersonCardHtml(c, idx) {
  const fotoHtml = c.foto
    ? '<img src="' + escapeAttr(c.foto) + '" class="person-card-photo" alt="">'
    : '<span class="person-card-placeholder">' + escapeHtml((c.naam || '?').charAt(0).toUpperCase()) + '</span>';
  let html = '<div class="person-card-wrap">';
  html += '<div class="person-card">' + fotoHtml
    + '<div class="person-card-naam">' + escapeHtml(c.naam) + '</div>'
    + (c.functie ? '<div class="person-card-sub">' + escapeHtml(c.functie) + '</div>' : '')
    + '</div>';
  html += '<div class="person-card-detail" id="ctDetail' + idx + '">'
    + (c.telefoon ? '<div>📞 <a href="tel:' + escapeAttr(c.telefoon) + '">' + escapeHtml(c.telefoon) + '</a></div>' : '')
    + (c.email ? '<div>✉️ <a href="mailto:' + escapeAttr(c.email) + '">' + escapeHtml(c.email) + '</a></div>' : '')
    + (c.opmerking ? '<div class="form-hint">' + escapeHtml(c.opmerking) + '</div>' : '')
    + (!c.telefoon && !c.email && !c.opmerking ? '<p class="empty">' + t('ctNoDetails') + '</p>' : '')
    + '</div>';
  html += '</div>';
  return html;
}

function contactenPersonenHtml(data) {
  if (data.length === 0) return '<p class="empty">' + t('newsNoContacts') + '</p>';
  let html = '';
  let idx = 0;
  data.forEach(function (groep) {
    html += '<div class="section-subtitle"><a class="producent-link" data-producent-id="' + escapeAttr(groep.producentId) + '">' + escapeHtml(groep.producent) + '</a></div>';
    html += '<div class="people-grid">';
    groep.contacten.forEach(function (c) { html += contactPersonCardHtml(c, idx++); });
    html += '</div>';
  });
  return html;
}

// Geen klik-om-uit-te-klappen meer nodig (zie toelichting hierboven) --
// deze functie blijft bestaan als no-op, want renderContacten() roept 'm
// nog aan; simpeler dan ook die aanroep te verwijderen voor iets dat
// toch niets meer hoeft te doen.
function wireContactenPersonenKaarten() {}

function renderContacten(producenten, contactpersonen) {
  const fout = (producenten && producenten.error) || (contactpersonen && contactpersonen.error);
  if (fout) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + fout + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  let html = '<h2>' + t('ctTitle') + '</h2>';
  html += '<p class="form-hint">' + t('ctHint') + '</p>';
  html += '<div class="vt-filters">'
    + '<button class="filter-btn ct-weergave-btn' + (contactenWeergave === 'personen' ? ' active' : '') + '" data-weergave="personen">' + t('ctViewPersonen') + '</button>'
    + '<button class="filter-btn ct-weergave-btn' + (contactenWeergave === 'bedrijven' ? ' active' : '') + '" data-weergave="bedrijven">' + t('ctViewBedrijven') + '</button>'
    + '</div>';
  html += '<div id="ctPersonen"' + (contactenWeergave === 'personen' ? '' : ' style="display:none;"') + '>' + contactenPersonenHtml(contactpersonen) + '</div>';
  html += '<div id="ctBedrijven"' + (contactenWeergave === 'bedrijven' ? '' : ' style="display:none;"') + '>' + contactenBedrijvenHtml(producenten) + '</div>';

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.ct-weergave-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      contactenWeergave = this.getAttribute('data-weergave');
      document.getElementById('ctPersonen').style.display = contactenWeergave === 'personen' ? '' : 'none';
      document.getElementById('ctBedrijven').style.display = contactenWeergave === 'bedrijven' ? '' : 'none';
      document.querySelectorAll('.ct-weergave-btn').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-weergave') === contactenWeergave); });
    });
  });

  document.querySelectorAll('.producent-link').forEach(function (el) {
    el.addEventListener('click', function () { showProducentDetail(this.getAttribute('data-producent-id')); });
  });
  wireContactenPersonenKaarten();

  const toevoegenBtn = document.getElementById('prToevoegen');
  if (toevoegenBtn) {
    toevoegenBtn.addEventListener('click', function () {
      const errorEl = document.getElementById('formError');
      errorEl.style.display = 'none';
      const naam = document.getElementById('prNaam').value.trim();
      if (!naam) return;
      apiPost('api/producenten.php', {
        actie: 'toevoegen',
        naam: naam,
        website: document.getElementById('prWebsite').value,
        email: document.getElementById('prEmail').value,
        telefoon: document.getElementById('prTelefoon').value,
        opmerking: document.getElementById('prOpmerking').value,
      }, showContacten, function (err) {
        errorEl.textContent = err.error || t('somethingWrong');
        errorEl.style.display = 'block';
      });
    });
  }
}

function showProducentDetail(id) {
  setRoute('producent-detail', id);
  document.getElementById('content').innerHTML = '<p>' + t('loading') + '</p>';
  StocuroRun.withSuccessHandler(renderProducentDetail).getProducentDetail(id);
}

function renderProducentDetail(data) {
  if (data.error) {
    document.getElementById('content').innerHTML = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button><h2>' + t('errorLabel') + ': ' + data.error + '</h2>';
    wire('backBtn', goBack);
    return;
  }

  const isBeheerder = window.CURRENT_USER && !!window.CURRENT_USER.is_beheerder;

  let html = '<button class="back-btn" id="backBtn">' + t('backLabel') + '</button>';
  html += '<h1>' + escapeHtml(data.naam);
  if (isBeheerder) {
    html += '<button class="edit-btn" id="prEditBtn">' + t('pdEdit') + '</button>';
    html += '<button class="edit-btn" id="prDeleteBtn" style="background:#ef4444;">' + t('prDelete') + '</button>';
  }
  html += '</h1>';

  html += '<div class="product-info">';
  html += '<div class="info-box"><div class="info-label">' + t('colWebsite') + '</div><div class="info-value">' + (data.website ? '<a href="' + escapeAttr(data.website) + '" target="_blank" rel="noopener">' + escapeHtml(data.website) + '</a>' : '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colEmail') + '</div><div class="info-value">' + escapeHtml(data.email || '-') + '</div></div>';
  html += '<div class="info-box"><div class="info-label">' + t('colPhone') + '</div><div class="info-value">' + escapeHtml(data.telefoon || '-') + '</div></div>';
  html += '</div>';
  if (data.opmerking) {
    html += '<p class="form-hint">' + escapeHtml(data.opmerking) + '</p>';
  }

  html += '<div id="formError" class="login-error" style="display:none;"></div>';

  html += '<div class="section-title">' + t('prContactsTitle') + '</div>';
  if (data.contacten.length === 0) {
    html += '<p class="empty">' + t('prNoContacts') + '</p>';
  } else {
    // Notitie-kolom per contactpersoon
    // (vrij tekstveld, was er al op producent-niveau maar niet per persoon).
    html += '<table><tr><th></th><th>' + t('colName') + '</th><th>' + t('colFunction') + '</th><th>' + t('colPhone') + '</th><th>' + t('colEmail') + '</th><th>' + t('colNote') + '</th>' + (isBeheerder ? '<th></th>' : '') + '</tr>';
    data.contacten.forEach(function (c) {
      html += '<tr><td>' + avatarHtml(c.foto, c.naam) + '</td><td>' + escapeHtml(c.naam) + '</td><td>' + escapeHtml(c.functie || '-') + '</td><td>' + escapeHtml(c.telefoon || '-') + '</td><td>' + escapeHtml(c.email || '-') + '</td><td>' + escapeHtml(c.opmerking || '-') + '</td>';
      if (isBeheerder) {
        html += '<td>'
          + '<input type="file" accept="image/*" class="pc-foto-input" data-id="' + c.id + '" style="display:none;">'
          + '<button class="action-btn pc-foto-btn" data-id="' + c.id + '">' + t('fotoUpload') + '</button> '
          + (c.foto ? '<button class="action-btn pc-foto-remove-btn" data-id="' + c.id + '">' + t('fotoRemove') + '</button> ' : '')
          + '<button class="action-btn contact-delete-btn" data-id="' + c.id + '">' + t('deleteLabel') + '</button>'
          + '</td>';
      }
      html += '</tr>';
    });
    html += '</table>';
  }

  if (isBeheerder) {
    html += '<div class="section-title">' + t('prNewContact') + '</div>';
    html += '<div class="edit-grid">';
    html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="pcNaam"></div>';
    html += '<div class="form-group"><label>' + t('colFunction') + '</label><input type="text" id="pcFunctie"></div>';
    html += '<div class="form-group"><label>' + t('colPhone') + '</label><input type="text" id="pcTelefoon"></div>';
    html += '<div class="form-group"><label>' + t('colEmail') + '</label><input type="email" id="pcEmail"></div>';
    html += '<div class="form-group"><label>' + t('colPhoto') + '</label><input type="file" accept="image/*" id="pcFoto"></div>';
    html += '</div>';
    html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="pcOpmerking" rows="2"></textarea></div>';
    html += '<div class="edit-actions"><button class="edit-save" id="pcToevoegen">' + t('addLabel') + '</button></div>';
  }

  html += '<div class="section-title">' + t('prLinkedProductsTitle') + '</div>';
  if (data.artikelen.length === 0) {
    html += '<p class="empty">' + t('prNoLinkedProducts') + '</p>';
  } else {
    html += '<table><tr><th>' + t('colProduct') + '</th><th>' + t('colCategoryLabel') + '</th><th>' + t('colEan') + '</th></tr>';
    data.artikelen.forEach(function (a) {
      html += '<tr><td><a class="product-link" data-ean="' + a.ean + '">' + escapeHtml(a.productnaam) + '</a></td><td>' + escapeHtml(a.categorie || '-') + '</td><td>' + a.ean + '</td></tr>';
    });
    html += '</table>';
  }

  document.getElementById('content').innerHTML = html;
  wire('backBtn', goBack);

  document.querySelectorAll('.product-link').forEach(function (el) {
    el.addEventListener('click', function () { showProductDetail(this.getAttribute('data-ean')); });
  });

  const errorEl = document.getElementById('formError');
  function toonFout(err) {
    errorEl.textContent = err.error || t('somethingWrong');
    errorEl.style.display = 'block';
  }

  const editBtn = document.getElementById('prEditBtn');
  if (editBtn) editBtn.addEventListener('click', function () { showProducentEdit(data); });

  const deleteBtn = document.getElementById('prDeleteBtn');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', function () {
      if (!confirm(tf('prDeleteConfirm', { naam: data.naam }))) return;
      apiPost('api/producenten.php', { actie: 'verwijderen', id: data.id }, showProducenten, toonFout);
    });
  }

  document.querySelectorAll('.contact-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm(t('prDeleteContactConfirm'))) return;
      apiPost('api/producenten.php', { actie: 'contact_verwijderen', id: this.getAttribute('data-id') }, function () { showProducentDetail(data.id); }, toonFout);
    });
  });

  // Profielfoto per contactpersoon: contact_bewerken stuurt
  // alle bestaande velden opnieuw mee (zie includes/producenten.php) --
  // alleen 'foto' (nieuwe upload) of 'verwijder_foto' verandert hier.
  document.querySelectorAll('.pc-foto-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelector('.pc-foto-input[data-id="' + this.getAttribute('data-id') + '"]').click();
    });
  });
  document.querySelectorAll('.pc-foto-input').forEach(function (input) {
    input.addEventListener('change', function () {
      const id = this.getAttribute('data-id');
      const c = data.contacten.find(function (x) { return String(x.id) === String(id); });
      if (!c) return;
      readImageFileAsDataUrl(this.files && this.files[0], function (dataUrl) {
        apiPost('api/producenten.php', {
          actie: 'contact_bewerken', id: id, naam: c.naam, functie: c.functie, telefoon: c.telefoon, email: c.email,
          // Opmerking meesturen zodat een foto-upload de
          // bestaande notitie niet stilzwijgend leegmaakt (contact_bewerken()
          // zet elk veld dat hier niet meekomt op NULL, zie includes/producenten.php).
          opmerking: c.opmerking, foto: dataUrl,
        }, function () { showProducentDetail(data.id); }, toonFout);
      }, function (errMsg) { alert(errMsg); });
    });
  });
  document.querySelectorAll('.pc-foto-remove-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm(t('fotoRemoveConfirm'))) return;
      const id = this.getAttribute('data-id');
      const c = data.contacten.find(function (x) { return String(x.id) === String(id); });
      if (!c) return;
      apiPost('api/producenten.php', {
        actie: 'contact_bewerken', id: id, naam: c.naam, functie: c.functie, telefoon: c.telefoon, email: c.email,
        opmerking: c.opmerking, verwijder_foto: true,
      }, function () { showProducentDetail(data.id); }, toonFout);
    });
  });

  const pcToevoegenBtn = document.getElementById('pcToevoegen');
  if (pcToevoegenBtn) {
    pcToevoegenBtn.addEventListener('click', function () {
      errorEl.style.display = 'none';
      const naam = document.getElementById('pcNaam').value.trim();
      if (!naam) return;
      const basis = {
        actie: 'contact_toevoegen', producent_id: data.id, naam: naam,
        functie: document.getElementById('pcFunctie').value,
        telefoon: document.getElementById('pcTelefoon').value,
        email: document.getElementById('pcEmail').value,
        opmerking: document.getElementById('pcOpmerking').value,
      };
      const fotoFile = document.getElementById('pcFoto').files && document.getElementById('pcFoto').files[0];
      if (fotoFile) {
        readImageFileAsDataUrl(fotoFile, function (dataUrl) {
          basis.foto = dataUrl;
          apiPost('api/producenten.php', basis, function () { showProducentDetail(data.id); }, toonFout);
        }, function (errMsg) { toonFout({ error: errMsg }); });
      } else {
        apiPost('api/producenten.php', basis, function () { showProducentDetail(data.id); }, toonFout);
      }
    });
  }
}

function showProducentEdit(data) {
  setRoute('producent-edit', data.id);
  let html = '<button class="back-btn" id="cancelEditBtn">' + t('cancel') + '</button>';
  html += '<h2>' + t('prEditTitle') + escapeHtml(data.naam) + '</h2>';
  html += '<div id="formError" class="login-error" style="display:none;"></div>';
  html += '<div class="edit-grid">';
  html += '<div class="form-group"><label>' + t('colName') + '</label><input type="text" id="peNaam" value="' + escapeAttr(data.naam) + '"></div>';
  html += '<div class="form-group"><label>' + t('colWebsite') + '</label><input type="text" id="peWebsite" value="' + escapeAttr(data.website || '') + '"></div>';
  html += '<div class="form-group"><label>' + t('colEmail') + '</label><input type="email" id="peEmail" value="' + escapeAttr(data.email || '') + '"></div>';
  html += '<div class="form-group"><label>' + t('colPhone') + '</label><input type="text" id="peTelefoon" value="' + escapeAttr(data.telefoon || '') + '"></div>';
  html += '</div>';
  html += '<div class="form-group"><label>' + t('colNote') + '</label><textarea id="peOpmerking" rows="2">' + escapeHtml(data.opmerking || '') + '</textarea></div>';
  html += '<div class="edit-actions"><button class="edit-save" id="peSave">' + t('save') + '</button><button class="edit-cancel" id="cancelEditBtn2">' + t('cancel') + '</button></div>';

  document.getElementById('content').innerHTML = html;
  document.getElementById('cancelEditBtn').addEventListener('click', function () { showProducentDetail(data.id); });
  document.getElementById('cancelEditBtn2').addEventListener('click', function () { showProducentDetail(data.id); });

  document.getElementById('peSave').addEventListener('click', function () {
    const errorEl = document.getElementById('formError');
    errorEl.style.display = 'none';
    const naam = document.getElementById('peNaam').value.trim();
    if (!naam) return;
    apiPost('api/producenten.php', {
      actie: 'bewerken', id: data.id, naam: naam,
      website: document.getElementById('peWebsite').value,
      email: document.getElementById('peEmail').value,
      telefoon: document.getElementById('peTelefoon').value,
      opmerking: document.getElementById('peOpmerking').value,
    }, function () { showProducentDetail(data.id); }, function (err) {
      errorEl.textContent = err.error || t('somethingWrong');
      errorEl.style.display = 'block';
    });
  });
}

// -----------------------------------------------------------------
// Uitloggen
// -----------------------------------------------------------------
// ("profielpagina"):
// #profileLink bestaat alleen voor een echt ingelogde gebruiker (niet voor
// een gast, zie app.php) -- daarom de null-check, in plaats van dit element
// altijd te verwachten.
const profileLinkEl = document.getElementById('profileLink');
if (profileLinkEl) {
  profileLinkEl.addEventListener('click', function () {
    showProfiel();
  });
}

document.getElementById('logoutLink').addEventListener('click', function () {
  // Gast (gast-modus): geen echte sessie om uit te loggen, dit is
  // gewoon de link naar het inlogscherm voor wie een test-account heeft.
  if (this.getAttribute('data-gast') === '1') {
    window.location.href = 'login.php';
    return;
  }
  fetch('api/logout.php', { credentials: 'same-origin' }).then(function () {
    window.location.href = 'login.php';
  });
});

const langSelectEl = document.getElementById('langSelect');
if (langSelectEl) {
  langSelectEl.addEventListener('change', function () {
    setLanguage(this.value);
  });
}

applyStaticTranslations();

// App-schil: zijbalk precies één keer
// opbouwen, vóór de route bepaald wordt hieronder -- de eerste showXxx()
// die bootstrapRoute() aanroept, zet via setRoute() meteen de juiste
// actieve link (zie updateSidebarActive()).
renderSidebar();
wireSidebarSearch();

// Initiële route bepalen: een hash in de URL (rechtstreeks geplakte link,
// bladwijzer, of een pagina-herlaad na eerder navigeren) rendert direct
// dat scherm i.p.v. altijd bij Home te beginnen. navigatingViaHistory
// staat hier aan zodat setRoute() (binnenin de aangeroepen showXxx())
// deze allereerste entry met replaceState() zet i.p.v. pushState() --
// anders zou de browser meteen een extra, ongewenste history-entry erbij
// krijgen vóórdat de gebruiker ook maar iets heeft aangeklikt.
(function bootstrapRoute() {
  const rawHash = window.location.hash.replace(/^#/, '');
  const slashIdx = rawHash.indexOf('/');
  const key = !rawHash ? '' : (slashIdx === -1 ? rawHash : rawHash.slice(0, slashIdx));
  const param = (!rawHash || slashIdx === -1) ? null : decodeURIComponent(rawHash.slice(slashIdx + 1));

  navigatingViaHistory = true;
  try {
    if (key && ROUTE_DISPATCH[key]) {
      // Een expliciete hash (bladwijzer, geplakte link, of een herlaad
      // ná eerder navigeren) wint altijd -- ook als deze gebruiker een
      // landingsscherm heeft, zie hieronder.
      ROUTE_DISPATCH[key](param);
      return;
    }
    // Alleen als
    // er GEEN hash is (een kale paginalaad, bv. net ingelogd) kijken we
    // of deze gebruiker via zijn/haar groep(en) een landingsscherm heeft
    // (window.CURRENT_USER.landingScherm, server-side al gevalideerd door
    // gebruikerLandingScherm() in includes/groepen.php -- null voor
    // beheerder/gast of bij tegenstrijdige groepsinstellingen). Zo niet:
    // gewoon Home, zoals voorheen.
    const landingScherm = window.CURRENT_USER && window.CURRENT_USER.landingScherm;
    if (landingScherm && ROUTE_DISPATCH[landingScherm]) {
      ROUTE_DISPATCH[landingScherm](null);
    } else {
      showHome();
    }
  } finally {
    navigatingViaHistory = false;
  }
})();
