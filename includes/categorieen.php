<?php
/**
 * Beheerde categorielijst +
 * categorie-specifieke eigenschappen (bijv. Ballon -> diameter/lengte/
 * compatibel voerdraad, Stent -> diameter/lengte/schacht/...). Vervangt de
 * eerder hardcoded categorielijst (assets/js/app.js, Artikel Toevoegen) en
 * de hardcoded specLabels()-switch (includes/voorraad.php) door
 * database-tabellen die via het Stamgegevens-scherm bewerkt kunnen worden.
 *
 * Zelfde "deactiveren i.p.v. verwijderen"-conventie als procedure_typen
 * (includes/stamgegevens.php): een categorie die niet meer gebruikt wordt
 * blijft bestaan (bestaande artikelen in die categorie tonen anders geen
 * naam meer), maar verdwijnt uit de keuzelijst bij Artikel Toevoegen.
 *
 * specLabels() (includes/voorraad.php) leest deze tabellen nu uit i.p.v.
 * de oude hardcoded switch -- zie de toelichting daar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Volledige lijst (ook inactieve categorieën, voor het Stamgegevens-
 * beheerscherm) inclusief de eigenschappen per categorie.
 */
function getCategorieenMetSpecs(): array {
    $categorieen = db()->query('SELECT id, naam, actief FROM categorieen ORDER BY volgorde, naam')->fetchAll();
    $specs = db()->query('SELECT id, categorie_id, spec_key, label FROM categorie_specs ORDER BY volgorde, label')->fetchAll();

    $specsPerCat = [];
    foreach ($specs as $s) {
        $specsPerCat[(int) $s['categorie_id']][] = [
            'id' => (int) $s['id'], 'key' => $s['spec_key'], 'label' => $s['label'],
        ];
    }

    // : "Bij nieuwe producten alle eigenschappen als
    // dropdown aanbieden voor uniformiteit, met de optie een nieuwe aan
    // te maken" -- elke eigenschap krijgt hier de lijst van al gebruikte
    // waarden mee (afgeleid uit artikelen.specs, zie specWaardenPerCategorie()
    // hieronder), zodat Artikel Toevoegen/bewerken die als <select> kunnen
    // tonen -- zelfde dropdown-met-"__nieuw__"-patroon als Producent en
    // Kastlocatie, i.p.v. een los tekstveld per product
    // waar iedereen zijn eigen spelling/notatie in typt (bijv. "6mm" naast
    // "6 mm" naast "6.0mm" voor wat feitelijk dezelfde diameter is).
    $waardenPerCat = specWaardenPerCategorie();

    return array_map(function ($c) use ($specsPerCat, $waardenPerCat) {
        $specsVoorCat = $specsPerCat[(int) $c['id']] ?? [];
        foreach ($specsVoorCat as &$s) {
            $s['values'] = $waardenPerCat[$c['naam']][$s['key']] ?? [];
        }
        unset($s);
        return [
            'id' => (int) $c['id'], 'naam' => $c['naam'], 'actief' => (bool) $c['actief'],
            'specs' => $specsVoorCat,
        ];
    }, $categorieen);
}

/**
 * Alle al gebruikte eigenschapswaarden, per categorienaam en spec_key,
 * afgeleid uit het bestaande artikelen.specs-JSON-veld (geen aparte
 * "toegestane waarden"-tabel nodig -- gewoon wat al eerder is ingevuld,
 * zelfde principe als de Producent-dropdown die ook gewoon
 * producenten.naam hergebruikt). Leeg/blanco waarden tellen niet mee,
 * en per (categorie, key) wordt gededupliceerd + natuurlijk gesorteerd
 * (zodat bijv. "2", "10" niet als "10", "2" naast elkaar staan).
 *
 * @return array<string, array<string, array<int, string>>>
 */
function specWaardenPerCategorie(): array {
    $rows = db()->query("SELECT categorie, specs FROM artikelen WHERE specs IS NOT NULL AND specs <> ''")->fetchAll();

    $sets = [];
    foreach ($rows as $r) {
        $cat = trim((string) ($r['categorie'] ?? ''));
        if ($cat === '') continue;
        $decoded = json_decode((string) $r['specs'], true);
        if (!is_array($decoded)) continue;
        foreach ($decoded as $key => $waarde) {
            $waarde = trim((string) $waarde);
            if ($waarde === '') continue;
            $sets[$cat][$key][$waarde] = true;
        }
    }

    $result = [];
    foreach ($sets as $cat => $perKey) {
        foreach ($perKey as $key => $waardenSet) {
            $lijst = array_keys($waardenSet);
            sort($lijst, SORT_NATURAL | SORT_FLAG_CASE);
            $result[$cat][$key] = $lijst;
        }
    }
    return $result;
}

/**
 * Alleen actieve categorieën, voor de keuzelijst bij Artikel Toevoegen/
 * bewerken -- een gedeactiveerde categorie mag je niet meer NIEUW kiezen,
 * maar bestaande artikelen erin blijven gewoon bestaan (zie hierboven).
 */
function getActieveCategorieenMetSpecs(): array {
    return array_values(array_filter(getCategorieenMetSpecs(), fn($c) => $c['actief']));
}

/**
 * Poort van specLabels() in includes/voorraad.php: spec_key -> label voor
 * één categorie, exact op naam gematcht (de categorie komt altijd uit de
 * hierboven beheerde lijst, dus een exacte match is voldoende -- geen
 * substring-matching zoals de oude hardcoded switch had).
 */
function specLabelsVoorCategorie(string $categorie): array {
    if ($categorie === '') return [];
    $stmt = db()->prepare(
        'SELECT cs.spec_key, cs.label FROM categorie_specs cs
         JOIN categorieen c ON c.id = cs.categorie_id
         WHERE c.naam = :naam ORDER BY cs.volgorde, cs.label'
    );
    $stmt->execute([':naam' => $categorie]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[$r['spec_key']] = $r['label'];
    }
    return $result;
}

function addCategorie(string $naam, ?string $gebruikerNaam = null): array {
    $naam = trim($naam);
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        $volgorde = (int) (db()->query('SELECT COALESCE(MAX(volgorde), 0) + 1 FROM categorieen')->fetchColumn());
        db()->prepare('INSERT INTO categorieen (naam, volgorde) VALUES (:naam, :volgorde)')
            ->execute([':naam' => $naam, ':volgorde' => $volgorde]);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'categorie_toegevoegd', 'categorie', $naam, null);
        }
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Kon niet toevoegen (bestaat deze naam al?)'];
    }
}

function setCategorieActief(int $id, bool $actief, ?string $gebruikerNaam = null): array {
    db()->prepare('UPDATE categorieen SET actief = :a WHERE id = :id')
        ->execute([':a' => $actief ? 1 : 0, ':id' => $id]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'categorie_status_gewijzigd', 'categorie', (string) $id, 'actief=' . ($actief ? '1' : '0'));
    }
    return ['success' => true];
}

/**
 * spec_key wordt automatisch afgeleid van het label (slug: kleine letters,
 * spaties/leestekens -> underscore) i.p.v. los in te vullen -- dat is de
 * technische JSON-sleutel (artikelen.specs) en hoeft een beheerder niet
 * zelf te bedenken. Bij een naamsbotsing binnen dezelfde categorie wordt
 * een cijfer toegevoegd (_2, _3, ...) zodat toevoegen nooit stilzwijgend
 * een bestaand veld overschrijft.
 */
function addCategorieSpec(int $categorieId, string $label, ?string $gebruikerNaam = null): array {
    $label = trim($label);
    if ($label === '') return ['success' => false, 'error' => 'Label is verplicht'];

    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
    if ($slug === '') $slug = 'veld';

    $stmt = db()->prepare('SELECT spec_key, volgorde FROM categorie_specs WHERE categorie_id = :cid');
    $stmt->execute([':cid' => $categorieId]);
    $bestaandeRijen = $stmt->fetchAll();
    $bestaandeKeys = array_column($bestaandeRijen, 'spec_key');
    $volgordes = array_column($bestaandeRijen, 'volgorde');
    $volgorde = ($volgordes ? max($volgordes) : 0) + 1;

    $key = $slug;
    $i = 2;
    while (in_array($key, $bestaandeKeys, true)) {
        $key = $slug . '_' . $i;
        $i++;
    }

    try {
        $stmt = db()->prepare('INSERT INTO categorie_specs (categorie_id, spec_key, label, volgorde) VALUES (:cid, :key, :label, :volgorde)');
        $stmt->execute([':cid' => $categorieId, ':key' => $key, ':label' => $label, ':volgorde' => $volgorde]);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'categorie_eigenschap_toegevoegd', 'categorie', (string) $categorieId, $label);
        }
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Kon eigenschap niet toevoegen'];
    }
}

/**
 * Eigenschap-DEFINITIE verwijderen (niet de al opgeslagen waarden op
 * bestaande artikelen -- die blijven gewoon in artikelen.specs staan,
 * tonen alleen niet meer mee zodra de sleutel niet meer in
 * categorie_specs voorkomt). Zelfde "niets stilzwijgend vernietigen"-
 * aanpak als elders in deze app.
 */
function removeCategorieSpec(int $specId, ?string $gebruikerNaam = null): array {
    db()->prepare('DELETE FROM categorie_specs WHERE id = :id')->execute([':id' => $specId]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'categorie_eigenschap_verwijderd', 'categorie_spec', (string) $specId, null);
    }
    return ['success' => true];
}
