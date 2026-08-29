-- =====================================================================
-- 025_artikel_status.sql
-- Artikel-status + vervangend artikel, op verzoek van Harm: soms is een
-- artikel tijdelijk niet leverbaar bij de leverancier en wordt er
-- ondertussen een ander artikel als vervanger gebruikt; daarnaast moet
-- een artikel ook blijvend "niet meer in gebruik" gemarkeerd kunnen
-- worden (bijv. de fabrikant is helemaal gestopt met dit product).
--
-- Drie statussen (artikelen.status hieronder):
--   - 'Actief'               : standaard, niets aan de hand.
--   - 'Niet leverbaar'       : tijdelijk niet te bestellen bij de
--                              leverancier. Optioneel gekoppeld aan een
--                              vervangend_artikel_id (zie hieronder) --
--                              optioneel, want je weet vaak nog niet
--                              meteen wat de vervanger wordt op het
--                              moment dat je iets als niet leverbaar
--                              markeert.
--   - 'Niet meer in gebruik' : blijvend gestopt, geen vervanger-koppeling
--                              relevant. Zelfde soort "deactiveren i.p.v.
--                              verwijderen"-aanpak als procedure_typen.
--                              actief (migratie 004) -- de historische
--                              transacties/orders van dit artikel blijven
--                              gewoon bestaan, alleen de actieve
--                              bestel-adviesschermen (Min/Max Advies,
--                              Boodschappenlijst, Berging-bestellijst)
--                              slaan een niet-Actief artikel over: geen
--                              zin om te adviseren iets te herbestellen
--                              wat je niet kunt of wilt bestellen.
--
-- Bewust GEEN wijziging aan scanformulieren (Ontvangst/Verbruik/
-- Verplaatsing/Vervallen Afboeken/Tellen): fysieke voorraad die nog in
-- het magazijn ligt van een niet-actief artikel moet gewoon nog
-- gescand/opgemaakt kunnen worden, alleen NIEUWE bestel-adviezen
-- stoppen. Zie includes/voorraad.php / includes/minmax.php voor de
-- exacte plekken waar de status wordt meegewogen.
--
-- vervangend_artikel_id: self-referencing FK naar artikelen.id, alleen
-- betekenisvol als status = 'Niet leverbaar'. Kan naar een BESTAAND
-- artikel wijzen (gewoon gekoppeld, geen nieuw artikel aangemaakt) OF
-- naar een NIEUW artikel dat speciaal als vervanger is aangemaakt (met
-- zijn eigen EAN/barcode -- het is immers een ander fysiek product) --
-- beide varianten ondersteund, zie includes/artikelen.php en
-- assets/js/app.js::renderProductEdit()/showArtikelToevoegen(). ON
-- DELETE SET NULL: er is bewust geen enkele plek in deze app die een
-- artikel echt verwijdert, maar mocht dat ooit handmatig in de database
-- gebeuren, dan moet dit veld niet op een dood record blijven wijzen.
-- =====================================================================

ALTER TABLE artikelen
  ADD COLUMN status ENUM('Actief','Niet leverbaar','Niet meer in gebruik') NOT NULL DEFAULT 'Actief' AFTER besteltype,
  ADD COLUMN vervangend_artikel_id INT NULL AFTER status,
  ADD INDEX idx_status (status),
  ADD CONSTRAINT fk_artikelen_vervangend FOREIGN KEY (vervangend_artikel_id) REFERENCES artikelen(id) ON DELETE SET NULL;

INSERT INTO schema_migrations (migratie) VALUES ('025_artikel_status.sql');
