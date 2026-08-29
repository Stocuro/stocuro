-- =====================================================================
-- 001_init.sql
-- Basisschema Voorraadbeheer Interventiekamer.
-- Zie migratieplan hoofdstuk 4 voor de volledige toelichting per tabel.
-- Uitgangspunt: alleen 'artikelen' bevat straks echte data (via het
-- migratiescript). transactielog/orders/eenmalige_bestellingen/foutlog
-- beginnen bewust leeg.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  migratie VARCHAR(255) NOT NULL UNIQUE,
  uitgevoerd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- artikelen: productstamdata (hoofdstuk 4.1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artikelen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL UNIQUE,
  productnaam VARCHAR(255) NOT NULL,
  gs1_raw VARCHAR(255) NULL,
  foto_link VARCHAR(500) NULL,
  categorie VARCHAR(50) NULL,
  besteltype ENUM('Automatisch','Handmatig') NOT NULL DEFAULT 'Automatisch',
  min_ik INT NOT NULL DEFAULT 0,
  max_ik INT NOT NULL DEFAULT 0,
  min_hybok INT NOT NULL DEFAULT 0,
  max_hybok INT NOT NULL DEFAULT 0,
  min_kar INT NOT NULL DEFAULT 0,
  max_kar INT NOT NULL DEFAULT 0,
  min_berging INT NOT NULL DEFAULT 0,
  max_berging INT NOT NULL DEFAULT 0,
  locatie_ik VARCHAR(100) NULL,
  locatie_berging VARCHAR(100) NULL,
  locatie_hybok VARCHAR(100) NULL,
  artikelref VARCHAR(100) NULL,
  producent VARCHAR(100) NULL,
  prijs DECIMAL(10,2) NOT NULL DEFAULT 0,
  specs JSON NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_categorie (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- transactielog: enige bron van waarheid voor voorraadaantallen
-- (hoofdstuk 4.2). Voorraad-Status/Voorraad-Lot uit de oude sheet
-- worden hieruit berekend door includes/voorraad.php, geen tabel.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactielog (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL,
  ean CHAR(14) NOT NULL,
  lot VARCHAR(50) NULL,
  vervaldatum DATE NULL,
  locatie VARCHAR(30) NOT NULL,
  transactietype ENUM('Ontvangst','Verbruik','Verplaatsing in','Verplaatsing uit','Retournering') NOT NULL,
  hoeveelheid INT NOT NULL DEFAULT 1,
  specialist VARCHAR(100) NULL,
  procedure_type VARCHAR(100) NULL,
  opmerking TEXT NULL,
  gs1_raw TEXT NULL,
  productnaam VARCHAR(255) NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ean_locatie (ean, locatie),
  INDEX idx_ean_lot (ean, lot, vervaldatum, locatie),
  INDEX idx_tijdstempel (tijdstempel),
  INDEX idx_type (transactietype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- orders: reguliere voorraadaanvulling (hoofdstuk 4.3). Status wordt
-- berekend bij het opvragen, niet opgeslagen.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL,
  ean CHAR(14) NOT NULL,
  productnaam VARCHAR(255) NULL,
  hoeveelheid_besteld INT NOT NULL DEFAULT 0,
  hoeveelheid_ontvangen INT NOT NULL DEFAULT 0,
  besteller VARCHAR(100) NULL,
  kanaal VARCHAR(100) NULL,
  opmerking TEXT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- eenmalige_bestellingen: patientgebonden materiaalaanvragen
-- (hoofdstuk 4.7). Bevat een persoonsgegeven (patientnummer) -- zie
-- migratieplan hoofdstuk 8, privacy-beslispunt. Toegang tot deze tabel
-- moet vanaf de introductie achter requireLogin() zitten.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eenmalige_bestellingen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL,
  patientnummer VARCHAR(50) NOT NULL,
  artikelomschrijving VARCHAR(255) NOT NULL,
  ean CHAR(14) NULL,
  hoeveelheid INT NOT NULL DEFAULT 1,
  procedure_type VARCHAR(100) NULL,
  specialist VARCHAR(100) NULL,
  besteller VARCHAR(100) NULL,
  opmerking TEXT NULL,
  status ENUM('Aangevraagd','Besteld','Ontvangen','Gebruikt') NOT NULL DEFAULT 'Aangevraagd',
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_patientnummer (patientnummer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- foutlog (hoofdstuk 4.4)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS foutlog (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstip DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fouttype VARCHAR(100) NOT NULL,
  barcode VARCHAR(100) NULL,
  bericht TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('001_init.sql');
