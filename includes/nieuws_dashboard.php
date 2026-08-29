<?php
/**
 * Migratie 026/028, op verzoek van Harm: drie losse onderdelen die verder
 * nergens anders op één plek stonden:
 *   - Nieuws: nieuwste producten + "niet leverbaar maar tijdelijk
 *     vervangen door" (dezelfde vervanger-koppeling als migratie 025,
 *     includes/artikelen.php, hier alleen samengevat als lijst).
 *   - Het Team: foto's van specialisten (alfabetisch), daaronder
 *     laboranten (ook alfabetisch) -- uit users (migratie 019
 *     rollenmodel + migratie 015 profielfoto's).
 *   - Contactpersonen: foto's van de contactpersonen per leverancier
 *     (producent_contacten, migratie 012 + 015).
 *
 * Migratie 030, op verzoek van Harm: stonden eerst met z'n drieën op één
 * dashboard/scherm-recht ('nieuws'). Nu drie eigen schermen met elk een
 * eigen scherm-recht (zie includes/schermen.php: 'nieuws', 'team',
 * 'contactpersonen') -- Het Team verhuist naar het nieuwe "Team &
 * Prestaties"-dashboard (samen met Leaderboard, zie TEAM_TABS in
 * assets/js/app.js), Nieuws en Contactpersonen worden losse schermen.
 * Groepen die eerder alleen 'nieuws' hadden aangevinkt moeten in Groepen-
 * beheer opnieuw 'team'/'contactpersonen' aanvinken als ze daar nog
 * toegang toe moeten hebben.
 * Alleen-lezen, geen eigen schrijfacties.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * De $limit meest recent aangemaakte artikelen (aangemaakt_op, migratie
 * 001). $limit is altijd een intern vastgelegd getal (nooit gebruikers-
 * invoer), maar toch expliciet ge-cast naar int voor de LIMIT-clausule --
 * PDO kan LIMIT niet altijd betrouwbaar als bind-parameter meegeven op
 * elke driverversie, dit is de gebruikelijke veilige omweg daarvoor.
 */
function getNieuwsteProducten(int $limit = 8): array {
    $limit = max(1, $limit);
    // Migratie 039_home_nieuwe_producten, op verzoek van Harm ("nieuwe rij
    // Nieuwe producten met afbeelding" op Home): foto_data/foto_mime erbij
    // gehaald (zelfde BLOB-kolommen als de productfoto op de
    // productdetailpagina, migratie 023) zodat de frontend een echte
    // fototegel kan tonen i.p.v. alleen tekst. Producten zonder foto krijgen
    // gewoon 'foto' => null; de frontend valt dan terug op een neutraal
    // productplaceholder (zelfde idee als avatarHtml() voor personen).
    $rows = db()->query(
        "SELECT ean, productnaam, categorie, producent, aangemaakt_op, foto_data, foto_mime
         FROM artikelen
         ORDER BY aangemaakt_op DESC
         LIMIT $limit"
    )->fetchAll();

    return array_map(fn($r) => [
        'ean' => $r['ean'], 'naam' => $r['productnaam'],
        'categorie' => $r['categorie'] ?: '', 'producent' => $r['producent'] ?: '',
        'aangemaaktOp' => $r['aangemaakt_op'],
        'foto' => !empty($r['foto_data'])
            ? 'data:' . ($r['foto_mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($r['foto_data'])
            : null,
    ], $rows);
}

/**
 * Artikelen die 'Niet leverbaar' zijn EN een vervanger hebben (migratie
 * 025, artikelen.vervangend_artikel_id) -- puur de tijdelijke-vervanging-
 * meldingen, geen 'Niet leverbaar' zonder vervanger en geen 'Niet meer in
 * gebruik' (dat is definitief uitgefaseerd, geen "nieuws").
 */
function getNietLeverbaarVervangers(): array {
    $rows = db()->query(
        "SELECT a.ean, a.productnaam, v.ean AS vervanger_ean, v.productnaam AS vervanger_naam
         FROM artikelen a
         JOIN artikelen v ON v.id = a.vervangend_artikel_id
         WHERE a.status = 'Niet leverbaar'
         ORDER BY a.productnaam"
    )->fetchAll();

    return array_map(fn($r) => [
        'ean' => $r['ean'], 'naam' => $r['productnaam'],
        'vervangerEan' => $r['vervanger_ean'], 'vervangerNaam' => $r['vervanger_naam'],
    ], $rows);
}

/**
 * Specialisten en laboranten (users.rol, migratie 019), elk apart
 * alfabetisch gesorteerd op naam zodat de frontend ze zonder verdere
 * bewerking als twee losse, alfabetische secties kan tonen. Alleen
 * actieve accounts -- een gedeactiveerd account op het teamoverzicht
 * tonen zou verwarrend zijn ("werkt deze persoon hier nog?").
 */
function getTeamOverzicht(): array {
    $rows = db()->query(
        "SELECT naam, rol, foto FROM users WHERE rol IN ('specialist', 'laborant') AND actief = 1 ORDER BY naam"
    )->fetchAll();

    $specialisten = [];
    $laboranten = [];
    foreach ($rows as $r) {
        $item = ['naam' => $r['naam'], 'foto' => $r['foto'] ?: null];
        if ($r['rol'] === 'specialist') {
            $specialisten[] = $item;
        } else {
            $laboranten[] = $item;
        }
    }
    return ['specialisten' => $specialisten, 'laboranten' => $laboranten];
}

/**
 * Contactpersonen per leverancier (producenten + producent_contacten,
 * migratie 012), gegroepeerd per producent en alfabetisch op
 * producentnaam -- zodat de frontend het per leverancier kan koppen i.p.v.
 * één ongesorteerde pot met namen.
 */
function getContactpersonenOverzicht(): array {
    // Migratie 031, op verzoek van Harm: telefoon/email/opmerking meesturen
    // zodat een contactpersoon-kaart op de Contacten-pagina direct de
    // contactgegevens kan tonen (uitklappen) i.p.v. eerst door te moeten
    // klikken naar de producent-detailpagina -- zelfde kolommen als
    // getProducentDetail() al ophaalt voor zijn contactentabel.
    $rows = db()->query(
        "SELECT p.id AS producent_id, p.naam AS producent, c.naam, c.functie, c.foto, c.telefoon, c.email, c.opmerking
         FROM producent_contacten c
         JOIN producenten p ON p.id = c.producent_id
         ORDER BY p.naam, c.naam"
    )->fetchAll();

    $perProducent = [];
    $producentIds = [];
    foreach ($rows as $r) {
        $perProducent[$r['producent']][] = [
            'naam' => $r['naam'], 'functie' => $r['functie'] ?: '', 'foto' => $r['foto'] ?: null,
            'telefoon' => $r['telefoon'] ?: '', 'email' => $r['email'] ?: '', 'opmerking' => $r['opmerking'] ?: '',
        ];
        $producentIds[$r['producent']] = (int) $r['producent_id'];
    }

    $result = [];
    foreach ($perProducent as $producent => $contacten) {
        $result[] = ['producent' => $producent, 'producentId' => $producentIds[$producent], 'contacten' => $contacten];
    }
    return $result;
}

/**
 * Migratie 030: was getNieuwsDashboard() (alle drie de onderdelen samen),
 * nu alleen het Nieuws-onderdeel -- Het Team en Contactpersonen hebben nu
 * hun eigen endpoint (api/team_overzicht.php, api/contactpersonen.php)
 * met een eigen scherm-recht.
 */
function getNieuwsAlleen(): array {
    return [
        'nieuwsteProducten' => getNieuwsteProducten(),
        'vervangers' => getNietLeverbaarVervangers(),
    ];
}
