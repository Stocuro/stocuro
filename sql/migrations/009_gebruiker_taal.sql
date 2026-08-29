-- =====================================================================
-- 009_gebruiker_taal.sql
-- Taalvoorkeur wordt een eigenschap van het account, niet langer een
-- los, browser-lokale (localStorage) schakelaartje op elke pagina van de
-- ingelogde app. Het inlogscherm (login.php) kent het account nog niet en
-- houdt daarom zijn eigen, kleine taalkeuze -- dat is een bewuste,
-- onvermijdelijke uitzondering (zie login.php's eigen commentaar).
-- =====================================================================

ALTER TABLE users
  ADD COLUMN taal ENUM('en','nl') NOT NULL DEFAULT 'en';

INSERT INTO schema_migrations (migratie) VALUES ('009_gebruiker_taal.sql');
