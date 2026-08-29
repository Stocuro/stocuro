-- =====================================================================
-- 005_locatie_instellingen.sql
-- Maakt de vier locatienamen instelbaar (Stocuro is generiek bedoeld,
-- niet gebonden aan interventieradiologie-terminologie). Zie toelichting
-- in includes/locaties.php over de vaste-slots-beperking.
-- =====================================================================

CREATE TABLE IF NOT EXISTS locatie_instellingen (
  slot ENUM('ik','hybok','kar','berging') PRIMARY KEY,
  naam VARCHAR(100) NOT NULL,
  volgorde INT NOT NULL DEFAULT 0,
  actief TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO locatie_instellingen (slot, naam, volgorde, actief) VALUES
  ('ik', 'Room A', 1, 1),
  ('hybok', 'Room B', 2, 1),
  ('kar', 'Cart', 3, 1),
  ('berging', 'Storage', 4, 1);

INSERT INTO schema_migrations (migratie) VALUES ('005_locatie_instellingen.sql');
