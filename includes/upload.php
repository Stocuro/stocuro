<?php
/**
 * Profielfoto-uploads (gebruikers + producent-contactpersonen, migratie
 * 015). De frontend leest het gekozen bestand clientside in als data-URL
 * (FileReader.readAsDataURL(), zie assets/js/app.js) en stuurt die als
 * gewone JSON-string mee in de POST-body -- geen multipart/form-data
 * nodig, dezelfde "alles is JSON"-aanpak als de rest van deze API.
 *
 * Bestanden komen op schijf te staan onder uploads/ (web-toegankelijk,
 * zodat <img src="uploads/..."> ze rechtstreeks kan tonen), met een
 * willekeurige bestandsnaam -- nooit de originele bestandsnaam of iets
 * anders user-controlled, dus geen path traversal of overschrijven van
 * bestaande bestanden mogelijk. uploads/.htaccess schakelt PHP-uitvoering
 * in die map uit, voor het geval een kwaadwillend bestand ooit met een
 * vervalste MIME-type toch als .php op schijf zou belanden.
 */

declare(strict_types=1);

// Kleine avatarfoto's, geen documentopslag -- 2MB is ruim voldoende voor
// een pasfoto en blijft ruim onder gangbare PHP post_max_size-defaults.
const FOTO_MAX_BYTES = 2 * 1024 * 1024;

// Productfoto's mogen GEEN kunstmatige
// maximale grootte hebben (in tegenstelling tot de avatar-upload
// hierboven) -- de enige harde grens die overblijft is de databasekolom
// zelf (artikelen.foto_data is MEDIUMBLOB, max ~16MB). Dit
// is dus geen "product-uploadlimiet" in de gebruikelijke zin, maar puur
// de technische bodem om te voorkomen dat een corrupt/absurd groot
// bestand de database-insert laat stuklopen i.p.v. een nette foutmelding
// te geven.
const PRODUCT_FOTO_MAX_BYTES = 16 * 1024 * 1024;

const FOTO_TOEGESTANE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

// Voor de echte-inhoud-check hieronder: welke PHP IMAGETYPE_*-constante
// bij welke toegestane MIME hoort. getimagesizefromstring() leest dit uit
// de binaire header van het bestand zelf, dus dit is een controle op wat
// het bestand ECHT is, los van wat de (client-controleerbare) MIME-tekst
// in de data-URL beweert.
const FOTO_IMAGETYPE_MIME = [
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG => 'image/png',
    IMAGETYPE_WEBP => 'image/webp',
];

/**
 * Decodeert en valideert een data-URL ("data:image/png;base64,AAAA...")
 * naar ruwe bytes + MIME-type. Gedeelde validatiestap voor beide
 * foto-opslagpatronen in deze app: op-schijf (verwerkFotoUpload()
 * hieronder, users + producent_contacten) en in-database
 * (includes/artikelen.php::uploadArtikelFoto(), voor producten
 * uitdrukkelijk rechtstreeks in de database i.p.v. op schijf). Gooit een
 * RuntimeException met een
 * gebruikersvriendelijke melding als het geen geldige/toegestane/
 * niet-te-grote/echte afbeelding is.
 *
 * @param int $maxBytes aanroeper-specifieke bovengrens --
 *        FOTO_MAX_BYTES (2MB) voor avatars, PRODUCT_FOTO_MAX_BYTES (16MB,
 *        in de praktijk "geen limiet", zie toelichting daar) voor
 *        productfoto's. Blijft een verplichte parameter i.p.v. een
 *        default, zodat een nieuwe aanroeper hier bewust een keuze voor
 *        moet maken i.p.v. stilzwijgend de avatar-grens te erven.
 * @return array{bytes: string, mime: string}
 */
function decodeFotoDataUrl(string $dataUrl, int $maxBytes): array {
    if (!preg_match('/^data:(image\/[a-zA-Z+.\-]+);base64,(.+)$/', $dataUrl, $m)) {
        throw new RuntimeException('Geen geldige afbeelding ontvangen');
    }
    $mime = strtolower($m[1]);
    if (!isset(FOTO_TOEGESTANE_TYPES[$mime])) {
        throw new RuntimeException('Alleen JPG, PNG of WEBP-afbeeldingen zijn toegestaan');
    }

    $bytes = base64_decode($m[2], true);
    if ($bytes === false) {
        throw new RuntimeException('Kon de afbeelding niet lezen');
    }
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('Afbeelding is te groot (max ' . (int) ($maxBytes / 1024 / 1024) . 'MB)');
    }

    $info = @getimagesizefromstring($bytes);
    if ($info === false || !isset(FOTO_IMAGETYPE_MIME[$info[2]]) || FOTO_IMAGETYPE_MIME[$info[2]] !== $mime) {
        throw new RuntimeException('Bestand is geen geldige afbeelding');
    }

    return ['bytes' => $bytes, 'mime' => $mime];
}

/**
 * Decodeert een data-URL naar een bestand in uploads/<subdir>/, en geeft
 * het relatieve pad terug om in de database op te slaan (bijv.
 * "uploads/gebruikers/ab12....jpg"). Gebruikt decodeFotoDataUrl()
 * hierboven voor de validatie.
 *
 * Ruimt bewust GEEN eerdere foto op -- dat was voorheen een $oudPad-
 * parameter hier, maar die verwijderde de oude foto vóórdat de aanroeper
 * de database had bijgewerkt. Als die database-update daarna alsnog zou
 * falen, was de oude foto al weg terwijl de database er nog naar
 * verwees: een kapotte link. Aanroepers (api/gebruikers.php,
 * includes/producenten.php) ruimen de oude foto nu zelf op, pas NA een
 * geslaagde database-update.
 */
function verwerkFotoUpload(string $dataUrl, string $subdir): string {
    $decoded = decodeFotoDataUrl($dataUrl, FOTO_MAX_BYTES);
    $bytes = $decoded['bytes'];
    $mime = $decoded['mime'];

    $basisDir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($basisDir) && !mkdir($basisDir, 0755, true) && !is_dir($basisDir)) {
        throw new RuntimeException('Kon de uploadmap niet aanmaken');
    }

    $bestandsnaam = bin2hex(random_bytes(16)) . '.' . FOTO_TOEGESTANE_TYPES[$mime];
    $volledigPad = $basisDir . $bestandsnaam;
    if (file_put_contents($volledigPad, $bytes) === false) {
        throw new RuntimeException('Kon de afbeelding niet opslaan');
    }

    return 'uploads/' . $subdir . '/' . $bestandsnaam;
}

/**
 * Verwijdert een eerder geüploade foto van schijf (bij vervangen of bij
 * het verwijderen van het account/de contactpersoon zelf). Faalt stil als
 * het bestand er al niet meer is -- dit is opruimen, geen kritieke actie.
 */
function verwijderFotoBestand(?string $relatiefPad): void {
    if ($relatiefPad === null || $relatiefPad === '') return;
    // Alleen binnen uploads/ verwijderen, nooit een pad met '..' erin --
    // relatiefPad komt normaliter altijd uit onze eigen verwerkFotoUpload(),
    // maar deze check blijft als extra bodem.
    if (!str_starts_with($relatiefPad, 'uploads/') || str_contains($relatiefPad, '..')) return;
    $volledigPad = __DIR__ . '/../' . $relatiefPad;
    if (is_file($volledigPad)) {
        @unlink($volledigPad);
    }
}
