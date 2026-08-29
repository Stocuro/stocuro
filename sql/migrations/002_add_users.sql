-- =====================================================================
-- 002_add_users.sql
-- Gebruikers en authenticatie (migratieplan hoofdstuk 4.6 / hoofdstuk 9).
-- Vanaf het begin aangelegd, ook als login pas later daadwerkelijk
-- verplicht wordt voor het grootste deel van het systeem.
-- =====================================================================

CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  wachtwoord_hash VARCHAR(255) NOT NULL,
  rol ENUM('beheerder','medewerker','alleen-lezen') NOT NULL DEFAULT 'medewerker',
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laatst_ingelogd DATETIME NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('002_add_users.sql');
