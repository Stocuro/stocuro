-- =====================================================================
-- 021_logistiek_code.sql
-- Interne logistiekcode per artikel, los van drie andere identifiers die
-- al bestaan en elk hun eigen doel hebben:
--   - ean            : GS1-stuk-EAN, de enige productidentiteit die
--                       overal (transactielog, orders, min/max) gebruikt
--                       wordt.
--   - doos_ean        (migratie 020): GS1-doosbarcode, lost altijd terug
--                       op naar bovenstaande ean.
--   - artikelref      : het artikelnummer van de FABRIKANT zelf (GS1 AI
--                       240 uit de barcode) -- niet iets wat Stocuro of
--                       het ziekenhuis zelf uitgeeft.
-- logistiek_code is de vierde: een interne code die het ziekenhuis zelf
-- toekent (bijv. voor een eigen label/barcode op het schap), volledig
-- handmatig ingevuld -- geen automatische generatie, op uitdrukkelijk
-- verzoek. Optioneel (blijft NULL voor producten waar dit niet voor
-- gebruikt wordt), en uniek zodra ingevuld: MariaDB staat meerdere NULLs
-- toe in een UNIQUE-kolom, dus dat is geen probleem voor de rest.
--
-- Herkenning tijdens scannen (includes/transacties.php::lookupArtikelen())
-- is een DERDE fallback ná stuk-EAN en doos-EAN: als een gescande code
-- geen van beide is, wordt 'm ook nog als logistiek_code geprobeerd
-- (multiplier altijd 1 -- dit is een productidentiteit, geen doos met
-- meerdere stuks). Bewust GEEN normalizeEAN() op dit veld: dat pad is
-- voor numerieke, 14-cijferige GS1-codes, en een logistiek_code is vrije
-- alfanumerieke tekst (bijv. "STK-00042") die als Code128-barcode
-- geprint wordt, geen EAN-formaat.
-- =====================================================================

ALTER TABLE artikelen
  ADD COLUMN logistiek_code VARCHAR(50) NULL AFTER artikelref;

ALTER TABLE artikelen
  ADD UNIQUE KEY uniq_artikelen_logistiek_code (logistiek_code);

INSERT INTO schema_migrations (migratie) VALUES ('021_logistiek_code.sql');
