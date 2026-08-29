-- =====================================================================
-- 020_doos_verpakking.sql
-- Op verzoek van Harm: sommige producten (bijv. voerdraden, sheaths,
-- katheters, PICC-lijnen) worden per stuk verbruikt/verplaatst, maar
-- door de leverancier geleverd in een doos van bijv. 5 of 10 stuks --
-- en zowel de doos als het losse stuk hebben allebei hun eigen,
-- afzonderlijke EAN/GS1-barcode.
--
-- Aanpak: één artikel-rij blijft de enige identiteit van het PRODUCT
-- (dus altijd via het stuk-EAN, zoals nu overal al het geval is in
-- transactielog/orders/etc. -- zie includes/transacties.php voor de
-- "snapshot, geen FK"-conventie die dit ongemoeid laat). Er komen twee
-- nieuwe, optionele kolommen bij: doos_ean (de barcode van de doos) en
-- eenheden_per_doos (hoeveel stuks daar in zitten). Beide blijven NULL
-- voor een product dat nooit in een doos wordt geleverd/gescand.
--
-- Voorraad blijft ALTIJD in stuks (geen aparte "dozen liggend"-status):
-- zodra een doos-EAN gescand wordt (bij Ontvangst, Verplaatsing,
-- Verbruik of Vervallen Afboeken -- zie includes/transacties.php::
-- lookupArtikelen()), wordt hij direct "uitgepakt" en met
-- eenheden_per_doos stuks geboekt op het stuk-EAN. Dit dekt zowel "een
-- hele ongeopende doos gaat in één keer naar een kamer" als "een doos is
-- al in de berging uitgepakt en er gaan losse, individueel gescande
-- stuks (in willekeurig aantal) naar de kamer" -- het systeem hoeft niet
-- te weten welk van de twee het is, het telt gewoon op wat er gescand
-- wordt.
--
-- Bestellen bij de leverancier blijft wél doos-gebaseerd: Min/Max
-- Advies/Boodschappenlijst/Bestellers ronden het benodigde aantal stuks
-- naar boven af op een heel veelvoud van eenheden_per_doos (zie
-- includes/voorraad.php::rondAfOpDoos()) -- puur een weergavelaag, de
-- orders-tabel zelf blijft altijd in stuks.
--
-- doos_ean is UNIQUE (net als artikelen.ean zelf) zodat een gescande
-- barcode ondubbelzinnig aan precies één artikel als doos-variant
-- gekoppeld kan worden. MariaDB staat meerdere NULL-waarden toe in een
-- UNIQUE-kolom (NULL wordt daarbij nooit als gelijk aan een andere NULL
-- beschouwd), dus dit is geen probleem voor de meerderheid van de
-- producten die geen doosverpakking hebben.
-- =====================================================================

ALTER TABLE artikelen
  ADD COLUMN doos_ean CHAR(14) NULL AFTER ean,
  ADD COLUMN eenheden_per_doos INT NULL AFTER doos_ean;

ALTER TABLE artikelen
  ADD UNIQUE KEY uniq_artikelen_doos_ean (doos_ean);

INSERT INTO schema_migrations (migratie) VALUES ('020_doos_verpakking.sql');
