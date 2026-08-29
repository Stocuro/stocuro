-- =====================================================================
-- 018_eenmalige_bestelling_meerdere_lots.sql
-- Op verzoek van Harm: bij een grotere hoeveelheid kan een eenmalige
-- bestelling in meerdere dozen/lots tegelijk binnenkomen, dus moet het
-- GS1-scanveld op dat formulier meerdere barcodes in één keer kunnen
-- verwerken (net als Ontvangst/Verbruik/Verplaatsing al konden) i.p.v.
-- maar één regel.
--
-- lot VARCHAR(50) is te kort voor een kommagescheiden lijst van meerdere
-- lotnummers -- verruimd naar VARCHAR(255). vervaldatum blijft een enkele
-- DATE-kolom (geen lijst): bij meerdere gescande lots wordt daar de
-- VROEGSTE vervaldatum in bewaard (het meest urgente risico is wat hier
-- relevant is om te tonen), zie includes/eenmalige_bestellingen.php::
-- gs1VoorEenmaligeBestelling().
-- =====================================================================

ALTER TABLE eenmalige_bestellingen
  MODIFY COLUMN lot VARCHAR(255) NULL;

INSERT INTO schema_migrations (migratie) VALUES ('018_eenmalige_bestelling_meerdere_lots.sql');
