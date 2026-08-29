-- =====================================================================
-- 030_groep_landingsscherm.sql
-- Op verzoek van Harm: rolgebonden landingspagina's in plaats van dat
-- iedereen na inloggen op dezelfde grote menupagina (showHome()) belandt.
-- Elke groep (groepen, migratie 024) kan nu een eigen standaard-
-- landingsscherm krijgen -- bijv. de artsen-groep landt direct op het
-- nieuwe Beschikbaarheid-scherm, de management-groep op de nieuwe
-- management-cockpit, een laborant-groep gewoon op Home (NULL = ongewijzigd
-- gedrag).
--
-- Nullable, geen default-waarde: bestaande groepen blijven na deze
-- migratie gewoon op Home landen totdat een beheerder er expliciet iets
-- voor kiest in het Groepen-scherm (zie includes/groepen.php,
-- assets/js/app.js::renderGroepDetail()).
--
-- Geen FOREIGN KEY naar een schermen-tabel (die bestaat niet -- de lijst
-- van schermen leeft in code, zie includes/schermen.php, zelfde afweging
-- als groep_schermen.scherm_key in migratie 024). Validatie dat de
-- ingevulde waarde een bestaand scherm-key is gebeurt in
-- includes/groepen.php.
-- =====================================================================

ALTER TABLE groepen
  ADD COLUMN landing_scherm VARCHAR(50) NULL AFTER mag_schrijven;

INSERT INTO schema_migrations (migratie) VALUES ('030_groep_landingsscherm.sql');
