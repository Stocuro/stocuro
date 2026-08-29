-- =====================================================================
-- 007_locaties_extra.sql
-- Maakt het mogelijk om, naast de vier vaste locatie-"slots" (zie
-- includes/locaties.php), extra locaties toe te voegen. De vier vaste
-- slots behouden hun eigen kolommen op artikelen (min_ik/max_ik/etc.) en
-- hun eigen gedrag (kar = mobiel, berging = aanvulbron) -- dat blijft
-- ongewijzigd. Extra locaties gedragen zich als een gewone algemene
-- locatie (eigen min/max per artikel, meegenomen in de boodschappenlijst),
-- met hun eigen min/max opgeslagen in artikel_locatie_extra i.p.v. als
-- kolom op artikelen (dat zou een aparte kolom per nieuwe locatie
-- vereisen -- niet schaalbaar voor een onbeperkt aantal).
-- =====================================================================

CREATE TABLE IF NOT EXISTS locaties_extra (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL UNIQUE,
  volgorde INT NOT NULL DEFAULT 100,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS artikel_locatie_extra (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL,
  locatie_extra_id INT NOT NULL,
  min_aantal INT NOT NULL DEFAULT 0,
  max_aantal INT NOT NULL DEFAULT 0,
  kastlocatie VARCHAR(100) NULL,
  UNIQUE KEY uniq_ean_locatie (ean, locatie_extra_id),
  FOREIGN KEY (locatie_extra_id) REFERENCES locaties_extra(id) ON DELETE CASCADE,
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('007_locaties_extra.sql');
