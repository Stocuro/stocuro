<?php
/**
 * Lichtgewicht naamkeuzelijst van alle actieve gebruikers (elke rol) --
 * migratie 033, op verzoek van Harm: de "Besteld door"-velden (bestel-
 * modal, order-detail, eenmalige-bestelling-bewerken) worden een dropdown
 * van bekende systeemgebruikers i.p.v. vrije tekst. Niet te verwarren met
 * api/gebruikers.php (volledig gebruikersbeheer, beheerder-only) -- dit
 * endpoint is GET-only, geeft alleen id+naam terug, en is voor iedere
 * ingelogde gebruiker bruikbaar (nodig om zelf een order te kunnen
 * plaatsen/bewerken, niet iets om tot beheerders te beperken).
 *
 * GET -> [{ id, naam }, ...] van alle actieve gebruikers, op naam.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/stamgegevens.php';

apiRequireLogin();

apiTry(fn() => getActieveGebruikersNamen());
