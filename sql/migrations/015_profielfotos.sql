-- =====================================================================
-- 015_profielfotos.sql
-- Profielfoto voor gebruikersaccounts (users) en voor producent-
-- contactpersonen (producent_contacten) -- op verzoek van Harm.
--
-- Beide kolommen slaan alleen een RELATIEF PAD op (bijv.
-- "uploads/gebruikers/ab12cd....jpg"), niet de afbeelding zelf -- het
-- bestand zelf staat op schijf onder uploads/, zie includes/upload.php.
-- Nullable: een account/contactpersoon zonder foto is de normale
-- (bestaande) situatie, geen foto is geen fout.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN foto VARCHAR(255) NULL AFTER rol;

ALTER TABLE producent_contacten
  ADD COLUMN foto VARCHAR(255) NULL AFTER email;

INSERT INTO schema_migrations (migratie) VALUES ('015_profielfotos.sql');
