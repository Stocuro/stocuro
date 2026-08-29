-- =====================================================================
-- 014_transactielog_batch_id.sql
-- Voegt batch_id toe aan transactielog, zodat alle regels die uit ÉÉN
-- scanactie voortkomen (één ingediend GS1-blok bij Ontvangst/Verbruik/
-- Vervallen Afboeken, of één Verplaatsing-indiening die per gescand
-- artikel een uit- én een in-regel wegschrijft) achteraf herkend en als
-- geheel gecorrigeerd kunnen worden (op verzoek van Harm: het Recente
-- Transacties-scherm toonde tot nu toe elke regel los, waardoor een
-- foutieve actie met bijv. 20 gescande barcodes ook 20 losse
-- correcties vereiste, en een Verplaatsing zijn uit- en in-boeking niet
-- gezamenlijk kon terugdraaien).
--
-- Waarom een aparte kolom i.p.v. groeperen op tijdstempel: tijdstempel
-- heeft maar secondeprecisie (DATETIME) en wordt weliswaar één keer per
-- indiening bepaald (zie includes/transacties.php), maar twee losse
-- indieningen die toevallig in dezelfde seconde vallen zouden dan ten
-- onrechte samengevoegd worden. batch_id (willekeurige 16-byte hex-
-- string, per indiening gegenereerd) is ondubbelzinnig.
--
-- Nullable: bestaande/historische rijen (van vóór deze migratie) hebben
-- geen batch_id. includes/transacties.php::getRecenteTransactiesGegroepeerd()
-- behandelt zo'n rij als een eigen, losse batch van één regel (fallback
-- op transactielog.id) -- geen dataverlies, geen her-import nodig.
-- =====================================================================

ALTER TABLE transactielog
  ADD COLUMN batch_id CHAR(32) NULL AFTER tijdstempel,
  ADD INDEX idx_batch_id (batch_id);

INSERT INTO schema_migrations (migratie) VALUES ('014_transactielog_batch_id.sql');
