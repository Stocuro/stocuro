-- =====================================================================
-- 017_specialist_login.sql
-- Nieuwe rol 'specialist' + koppeling tussen een inlogaccount (users) en
-- een naam in Stamgegevens (specialisten) -- op verzoek van Harm: artsen/
-- laboranten moeten ook kunnen inloggen, met ruimte om dit later verder
-- uit te breiden (eigen dashboard, "mijn cijfers" op het Leaderboard,
-- notificaties i.p.v. e-mail). Voor nu (zie includes/auth.php en
-- api/eenmalige_bestelling.php) krijgt deze rol een beperkt, alleen-lezen
-- "Mijn Bestellingen"-scherm: de status van hun eigen Eenmalige
-- Bestelling-aanvragen, verder niets van de voorraadapp.
--
-- specialist_id is BEWUST nullable en los van de bestaande
-- specialisten-tabel (migratie 004 koos daar destijds expliciet voor
-- geen koppeling, "een specialist heeft niet per se een inlogaccount") --
-- dat blijft zo: de meeste specialisten-rijen hebben nooit een account,
-- alleen wie er één krijgt, wordt via deze kolom aan zijn/haar naam
-- gekoppeld. ON DELETE SET NULL: als een specialist-rij ooit verwijderd
-- zou worden (vandaag kan dat niet, alleen actief/inactief), verliest het
-- account de koppeling in plaats van dat de rij geweigerd wordt.
-- =====================================================================

ALTER TABLE users
  MODIFY COLUMN rol ENUM('beheerder','medewerker','alleen-lezen','specialist') NOT NULL DEFAULT 'medewerker';

ALTER TABLE users
  ADD COLUMN specialist_id INT NULL AFTER rol,
  ADD CONSTRAINT fk_users_specialist FOREIGN KEY (specialist_id) REFERENCES specialisten(id) ON DELETE SET NULL;

INSERT INTO schema_migrations (migratie) VALUES ('017_specialist_login.sql');
