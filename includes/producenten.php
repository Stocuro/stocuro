<?php
/**
 * Producenten: contactgegevens (website, algemeen e-mailadres/telefoon,
 * onbeperkt aantal contactpersonen) per leverancier/fabrikant -- om snel
 * een contactpersoon te kunnen vinden bij een product zonder dat los op
 * te hoeven zoeken.
 *
 * BEWUSTE KEUZE: artikelen.producent blijft een
 * vrij tekstveld, geen FK. Deze tabel wordt puur op NAAM (case-
 * insensitief, getrimd) gekoppeld aan die tekst, via
 * vindProducentVoorNaam() -- gebruikt door
 * includes/artikelen.php::getProductDetail() om op de productpagina een
 * link naar de contactkaart te tonen als de producentnaam toevallig (of
 * bewust) overeenkomt met een hier vastgelegde producent. Geen match?
 * Dan blijft het gewoon platte tekst, precies zoals nu.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/upload.php';

/**
 * Normaliseert een producentnaam voor vergelijking: trim + lagere kast.
 * Wordt zowel bij het opslaan (uniek-check) als bij het opzoeken
 * gebruikt, zodat "Demo Supplier Co", " demo supplier co " en
 * "DEMO SUPPLIER CO" allemaal als dezelfde producent herkend worden.
 */
function normaliseerProducentNaam(string $naam): string {
    return mb_strtolower(trim($naam));
}

/**
 * Alle producenten, met aantal contactpersonen en aantal gekoppelde
 * artikelen (op naam-match) erbij -- voor het overzichtsscherm. Eén
 * bulk-query per telling i.p.v. per producent, zelfde aanpak als elders
 * in deze codebase (transactieNettoPerLocatie() etc.).
 */
function getProducenten(): array {
    $producenten = db()->query('SELECT * FROM producenten ORDER BY naam')->fetchAll();

    $contactAantallen = db()->query(
        'SELECT producent_id, COUNT(*) AS aantal FROM producent_contacten GROUP BY producent_id'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $artikelAantallen = [];
    foreach (db()->query("SELECT producent, COUNT(*) AS aantal FROM artikelen WHERE producent IS NOT NULL AND producent <> '' GROUP BY producent")->fetchAll() as $r) {
        $key = normaliseerProducentNaam($r['producent']);
        $artikelAantallen[$key] = ($artikelAantallen[$key] ?? 0) + (int) $r['aantal'];
    }

    $result = [];
    foreach ($producenten as $p) {
        $result[] = [
            'id' => (int) $p['id'], 'naam' => $p['naam'], 'website' => $p['website'],
            'email' => $p['email'], 'telefoon' => $p['telefoon'], 'opmerking' => $p['opmerking'],
            'aantalContacten' => (int) ($contactAantallen[$p['id']] ?? 0),
            'aantalArtikelen' => $artikelAantallen[normaliseerProducentNaam($p['naam'])] ?? 0,
        ];
    }
    return $result;
}

/**
 * Eén producent met al zijn contactpersonen en de artikelen die op naam
 * matchen (voor de "gekoppelde producten"-lijst op de detailpagina, met
 * doorklik naar de productpagina).
 */
function getProducentDetail(int $id): array {
    $stmt = db()->prepare('SELECT * FROM producenten WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) return ['error' => 'Producent niet gevonden'];

    // Opmerking (vrij tekstveld) erbij --
    // was er al op producent-niveau, ontbrak nog per contactpersoon zelf.
    $stmt = db()->prepare('SELECT id, naam, functie, telefoon, email, foto, opmerking FROM producent_contacten WHERE producent_id = :id ORDER BY naam');
    $stmt->execute([':id' => $id]);
    $contacten = $stmt->fetchAll();

    $stmt = db()->prepare(
        "SELECT ean, productnaam, categorie FROM artikelen WHERE LOWER(TRIM(producent)) = :naam ORDER BY productnaam"
    );
    $stmt->execute([':naam' => normaliseerProducentNaam($p['naam'])]);
    $artikelen = $stmt->fetchAll();

    return [
        'id' => (int) $p['id'], 'naam' => $p['naam'], 'website' => $p['website'],
        'email' => $p['email'], 'telefoon' => $p['telefoon'], 'opmerking' => $p['opmerking'],
        'contacten' => $contacten,
        'artikelen' => $artikelen,
    ];
}

/**
 * Zoekt een producent op naam (case-insensitief, getrimd) -- gebruikt
 * door includes/artikelen.php::getProductDetail() om op de productpagina
 * een link naar de contactkaart te tonen. Geeft null als er geen
 * (exacte, genormaliseerde) match is -- géén fuzzy matching, dat zou een
 * verkeerde producent kunnen koppelen.
 */
function vindProducentVoorNaam(?string $naam): ?array {
    $naam = trim((string) $naam);
    if ($naam === '') return null;

    $stmt = db()->prepare('SELECT id, naam, website, email, telefoon FROM producenten WHERE LOWER(TRIM(naam)) = :naam LIMIT 1');
    $stmt->execute([':naam' => normaliseerProducentNaam($naam)]);
    $p = $stmt->fetch();
    return $p ? ['id' => (int) $p['id'], 'naam' => $p['naam'], 'website' => $p['website'], 'email' => $p['email'], 'telefoon' => $p['telefoon']] : null;
}

/**
 * Bugfix: "Bij het aanmaken van een nieuwe producent,
 * dan moet deze direct worden opgenomen in producenten." Vóór deze fix
 * werd een via Artikel Toevoegen/bewerken getypte "nieuwe producent"-naam
 * ALLEEN op artikelen.producent (vrije tekst) weggeschreven -- de
 * Producenten-overzichtspagina (getProducenten() hierboven) leest echter
 * uitsluitend de producenten-tabel, dus zo'n nieuwe naam verscheen daar
 * nooit, ook al stond hij al op een product. Deze functie wordt nu na
 * elke opslag van artikelen.producent aangeroepen (zie addArtikel() en
 * updateArtikelVeld() in includes/artikelen.php): bestaat er al een
 * producent met deze naam (case-insensitief, zelfde matching als
 * vindProducentVoorNaam()), dan gebeurt er niets; anders wordt er een
 * minimale rij aangemaakt (alleen de naam -- contactgegevens vult de
 * beheerder later zelf aan op de Producenten-pagina, net als bij een
 * handmatig aangemaakte producent).
 */
function vindOfMaakProducent(?string $naam, ?string $gebruikerNaam = null): void {
    $naam = trim((string) $naam);
    if ($naam === '') return;
    if (vindProducentVoorNaam($naam) !== null) return;

    try {
        db()->prepare('INSERT INTO producenten (naam) VALUES (:naam)')->execute([':naam' => $naam]);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'producent_toegevoegd', 'producent', (string) db()->lastInsertId(), "naam=$naam (automatisch, via artikel)");
        }
    } catch (Throwable $e) {
        // Race condition (twee gelijktijdige aanmaak-acties met dezelfde
        // naam) of een unieke-index-conflict -- onschadelijk te negeren,
        // de naam bestaat dan inmiddels toch al in producenten.
    }
}

function addProducent(array $data, ?string $gebruikerNaam = null): array {
    $naam = trim($data['naam'] ?? '');
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        db()->prepare(
            'INSERT INTO producenten (naam, website, email, telefoon, opmerking) VALUES (:naam, :website, :email, :telefoon, :opmerking)'
        )->execute([
            ':naam' => $naam,
            ':website' => trim($data['website'] ?? '') ?: null,
            ':email' => trim($data['email'] ?? '') ?: null,
            ':telefoon' => trim($data['telefoon'] ?? '') ?: null,
            ':opmerking' => trim($data['opmerking'] ?? '') ?: null,
        ]);
        $id = (int) db()->lastInsertId();
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'producent_toegevoegd', 'producent', (string) $id, "naam=$naam");
        }
        return ['success' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Kon niet toevoegen (bestaat deze naam al?)'];
    }
}

function updateProducent(int $id, array $data, ?string $gebruikerNaam = null): array {
    $naam = trim($data['naam'] ?? '');
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    try {
        db()->prepare(
            'UPDATE producenten SET naam = :naam, website = :website, email = :email, telefoon = :telefoon, opmerking = :opmerking WHERE id = :id'
        )->execute([
            ':naam' => $naam,
            ':website' => trim($data['website'] ?? '') ?: null,
            ':email' => trim($data['email'] ?? '') ?: null,
            ':telefoon' => trim($data['telefoon'] ?? '') ?: null,
            ':opmerking' => trim($data['opmerking'] ?? '') ?: null,
            ':id' => $id,
        ]);
        if ($gebruikerNaam !== null) {
            logAudit($gebruikerNaam, 'producent_bewerkt', 'producent', (string) $id, "naam=$naam");
        }
        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Kon niet opslaan (bestaat deze naam al bij een andere producent?)'];
    }
}

function deleteProducent(int $id, ?string $gebruikerNaam = null): array {
    // ON DELETE CASCADE ruimt producent_contacten voor deze
    // producent automatisch mee op in de database -- maar de bijbehorende
    // Foto-bestanden staan op schijf, niet in de database,
    // dus die worden hier expliciet opgeruimd vóórdat de rijen verdwijnen
    // (anders blijven het wees-bestanden onder uploads/producenten/).
    // artikelen.producent is losse tekst en wordt hier bewust niet
    // aangeraakt -- de producentnaam blijft gewoon op het product staan,
    // alleen de contactkaart-koppeling verdwijnt.
    $stmt = db()->prepare("SELECT foto FROM producent_contacten WHERE producent_id = :id AND foto IS NOT NULL AND foto <> ''");
    $stmt->execute([':id' => $id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $foto) {
        verwijderFotoBestand($foto);
    }

    db()->prepare('DELETE FROM producenten WHERE id = :id')->execute([':id' => $id]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'producent_verwijderd', 'producent', (string) $id, null);
    }
    return ['success' => true];
}

/**
 * $data['foto'] (optioneel): een data-URL uit de
 * bestandskiezer in de frontend, zie includes/upload.php. Weglaten of
 * leeg laten betekent gewoon "geen foto bij aanmaken", geen fout.
 */
function addProducentContact(int $producentId, array $data, ?string $gebruikerNaam = null): array {
    $naam = trim($data['naam'] ?? '');
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    $foto = null;
    if (!empty($data['foto'])) {
        try {
            $foto = verwerkFotoUpload($data['foto'], 'producenten');
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    db()->prepare(
        'INSERT INTO producent_contacten (producent_id, naam, functie, telefoon, email, foto, opmerking) VALUES (:pid, :naam, :functie, :telefoon, :email, :foto, :opmerking)'
    )->execute([
        ':pid' => $producentId, ':naam' => $naam,
        ':functie' => trim($data['functie'] ?? '') ?: null,
        ':telefoon' => trim($data['telefoon'] ?? '') ?: null,
        ':email' => trim($data['email'] ?? '') ?: null,
        ':foto' => $foto,
        // Vrij notitieveld per contactpersoon.
        ':opmerking' => trim($data['opmerking'] ?? '') ?: null,
    ]);
    $id = (int) db()->lastInsertId();
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'producent_contact_toegevoegd', 'producent', (string) $producentId, "contact=$naam");
    }
    return ['success' => true, 'id' => $id];
}

/**
 * $data['foto'] (optioneel): nieuwe data-URL om de foto te vervangen (de
 * oude wordt dan van schijf opgeruimd). $data['verwijder_foto'] (optioneel,
 * truthy): bestaande foto verwijderen zonder vervanging. Geen van beide
 * meegegeven? Dan blijft de bestaande foto ongewijzigd -- dit endpoint
 * stuurt bij elke bewerking altijd alle velden opnieuw mee (zie
 * assets/js/app.js), dus "niet meegegeven" betekent hier bewust "met rust
 * laten", niet "leegmaken".
 */
function updateProducentContact(int $id, array $data, ?string $gebruikerNaam = null): array {
    $naam = trim($data['naam'] ?? '');
    if ($naam === '') return ['success' => false, 'error' => 'Naam is verplicht'];

    $stmt = db()->prepare('SELECT foto FROM producent_contacten WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $bestaand = $stmt->fetch();
    if (!$bestaand) return ['success' => false, 'error' => 'Contactpersoon niet gevonden'];

    $foto = $bestaand['foto'];
    $ouderFotoOpruimen = null;
    if (!empty($data['foto'])) {
        try {
            $foto = verwerkFotoUpload($data['foto'], 'producenten');
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        $ouderFotoOpruimen = $bestaand['foto'];
    } elseif (!empty($data['verwijder_foto'])) {
        $foto = null;
        $ouderFotoOpruimen = $bestaand['foto'];
    }

    db()->prepare(
        'UPDATE producent_contacten SET naam = :naam, functie = :functie, telefoon = :telefoon, email = :email, foto = :foto, opmerking = :opmerking WHERE id = :id'
    )->execute([
        ':naam' => $naam,
        ':functie' => trim($data['functie'] ?? '') ?: null,
        ':telefoon' => trim($data['telefoon'] ?? '') ?: null,
        ':email' => trim($data['email'] ?? '') ?: null,
        ':foto' => $foto,
        ':opmerking' => trim($data['opmerking'] ?? '') ?: null,
        ':id' => $id,
    ]);
    // Pas ná de geslaagde UPDATE de oude foto opruimen -- zie
    // includes/upload.php voor waarom dit niet in verwerkFotoUpload()
    // zelf gebeurt.
    if ($ouderFotoOpruimen !== null && $ouderFotoOpruimen !== '') {
        verwijderFotoBestand($ouderFotoOpruimen);
    }
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'producent_contact_bewerkt', 'producent_contact', (string) $id, "naam=$naam");
    }
    return ['success' => true];
}

function deleteProducentContact(int $id, ?string $gebruikerNaam = null): array {
    $stmt = db()->prepare('SELECT foto FROM producent_contacten WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $bestaand = $stmt->fetch();
    if ($bestaand) verwijderFotoBestand($bestaand['foto']);

    db()->prepare('DELETE FROM producent_contacten WHERE id = :id')->execute([':id' => $id]);
    if ($gebruikerNaam !== null) {
        logAudit($gebruikerNaam, 'producent_contact_verwijderd', 'producent_contact', (string) $id, null);
    }
    return ['success' => true];
}
