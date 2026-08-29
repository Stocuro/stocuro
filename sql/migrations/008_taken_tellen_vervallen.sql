-- =====================================================================
-- 008_taken_tellen_vervallen.sql
-- Ondersteunt de takenlijst (includes/taken.php): periodieke kastcontrole
-- per locatie ("tellen") en het afboeken van over-datum voorraad
-- ("vervallen"). Beide zijn afgeleide taken, geen aparte taken-tabel --
-- deze migratie voegt alleen toe wat daarvoor nodig is:
--   - drie nieuwe transactietypes, zodat tellingcorrecties en
--     vervalafboekingen apart herkenbaar blijven in transactielog t.o.v.
--     gewoon Ontvangst/Verbruik (nauwkeurigheids-/verval-rapportage
--     achteraf mogelijk maken);
--   - laatst_geteld_op per locatie (vaste slots + extra locaties), zodat
--     getKastcontroleTaken() kan bepalen welke locatie aan de beurt is.
-- =====================================================================

ALTER TABLE transactielog
  MODIFY COLUMN transactietype ENUM(
    'Ontvangst','Verbruik','Verplaatsing in','Verplaatsing uit','Retournering',
    'Telling: overschot','Telling: tekort','Vervallen'
  ) NOT NULL;

ALTER TABLE locatie_instellingen
  ADD COLUMN laatst_geteld_op DATETIME NULL;

ALTER TABLE locaties_extra
  ADD COLUMN laatst_geteld_op DATETIME NULL;

INSERT INTO schema_migrations (migratie) VALUES ('008_taken_tellen_vervallen.sql');
