-- =====================================================================
-- herstel_ontbrekende_tabellen.sql
--
-- REPARATIESCRIPT, GEEN MIGRATIE. Op verzoek van Harm nadat een deel van
-- de tabellen per ongeluk van de live database was verwijderd, zonder
-- backup.
--
-- WAT DIT DOET: elke tabel die de app verwacht wordt aangemaakt met
-- CREATE TABLE IF NOT EXISTS, in haar HUIDIGE, volledig gemigreerde vorm
-- (dus alsof migraties 001 t/m 036 er al overheen zijn gegaan) --
--   - Bestaat een tabel al?  Er gebeurt NIETS. Geen kolom wordt
--     toegevoegd/gewijzigd/verwijderd, geen rij wordt aangeraakt.
--   - Bestaat een tabel niet?  Ze wordt leeg aangemaakt met de juiste
--     kolommen/indexen/foreign keys. Een handvol tabellen die zonder
--     basisgegevens niet kunnen functioneren (locatie_instellingen,
--     groepen-systeem, categorieën) krijgen ALLEEN dan ook meteen
--     standaard-startgegevens terug (zie de losse blokken hieronder) --
--     ook die stap slaat zichzelf over zodra er al rijen in staan.
--
-- WAT DIT NIET DOET (belangrijk!):
--   - Dit herstelt GEEN verloren RIJEN uit een tabel die is blijven
--     bestaan maar leeggemaakt is -- alleen tabellen die volledig
--     verdwenen waren, komen (leeg) terug. Zonder backup is data die in
--     een bestaande tabel is gewist onherstelbaar; dit script kan dat
--     niet ongedaan maken.
--   - Dit raakt schema_migrations niet aan. Losse migratiebestanden in
--     sql/migrations/ blijven precies zo geregistreerd als ze al waren --
--     dit script is bewust GEEN vervanging van sql/migrate.php en wordt
--     ook niet als migratie weggeschreven. Reden: migrate.php slaat elk
--     bestand over dat al in schema_migrations staat, ongeacht of de
--     tabel die het maakte nog echt bestaat -- dat is precies waarom
--     "gewoon opnieuw migreren" dit probleem niet oplost.
--   - Als de `users`-tabel zelf een van de verdwenen tabellen was, komt
--     hij leeg terug -- dus ZONDER accounts. Log dan in dat geval niet
--     zomaar in; zie de opmerking onderaan dit bestand over een nieuw
--     eerste beheerder-account aanmaken.
--
-- HOE TE GEBRUIKEN: open phpMyAdmin/Adminer bij je hostingpaneel (One.
-- com), kies de Stocuro-database, ga naar het "SQL"-tabblad, plak de
-- VOLLEDIGE inhoud van dit bestand, en voer uit. Veilig om vaker te
-- draaien -- elke run die niets ontbrekend vindt doet letterlijk niets.
-- =====================================================================

-- ---------------------------------------------------------------------
-- schema_migrations (001)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  migratie VARCHAR(255) NOT NULL UNIQUE,
  uitgevoerd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- artikelen (001, bijgewerkt door 020/021/023/025/031/034)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artikelen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL UNIQUE,
  doos_ean CHAR(14) NULL,
  eenheden_per_doos INT NULL,
  productnaam VARCHAR(255) NOT NULL,
  gs1_raw VARCHAR(255) NULL,
  foto_link VARCHAR(500) NULL,
  foto_data MEDIUMBLOB NULL,
  foto_mime VARCHAR(50) NULL,
  categorie VARCHAR(50) NULL,
  besteltype ENUM('Automatisch','Handmatig','Eenmalig') NOT NULL DEFAULT 'Automatisch',
  status ENUM('Actief','Niet leverbaar','Niet meer in gebruik') NOT NULL DEFAULT 'Actief',
  vervangend_artikel_id INT NULL,
  max_ik INT NOT NULL DEFAULT 0,
  max_hybok INT NOT NULL DEFAULT 0,
  max_kar INT NOT NULL DEFAULT 0,
  min_berging INT NOT NULL DEFAULT 0,
  max_berging INT NOT NULL DEFAULT 0,
  locatie_ik VARCHAR(100) NULL,
  locatie_berging VARCHAR(100) NULL,
  locatie_hybok VARCHAR(100) NULL,
  artikelref VARCHAR(100) NULL,
  logistiek_code VARCHAR(50) NULL,
  producent VARCHAR(100) NULL,
  prijs DECIMAL(10,2) NOT NULL DEFAULT 0,
  specs JSON NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_categorie (categorie),
  INDEX idx_status (status),
  UNIQUE KEY uniq_artikelen_doos_ean (doos_ean),
  UNIQUE KEY uniq_artikelen_logistiek_code (logistiek_code),
  CONSTRAINT fk_artikelen_vervangend FOREIGN KEY (vervangend_artikel_id) REFERENCES artikelen(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- transactielog (001, bijgewerkt door 003/006/008/010/014)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactielog (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL,
  batch_id CHAR(32) NULL,
  ean CHAR(14) NOT NULL,
  lot VARCHAR(50) NULL,
  vervaldatum DATE NULL,
  locatie VARCHAR(30) NOT NULL,
  transactietype ENUM(
    'Ontvangst','Verbruik','Verplaatsing in','Verplaatsing uit','Retournering',
    'Telling: overschot','Telling: tekort','Vervallen'
  ) NOT NULL,
  hoeveelheid INT NOT NULL DEFAULT 1,
  prijs_op_moment DECIMAL(10,2) NULL,
  specialist VARCHAR(100) NULL,
  procedure_type VARCHAR(100) NULL,
  aangemaakt_door VARCHAR(100) NULL,
  opmerking TEXT NULL,
  gs1_raw TEXT NULL,
  productnaam VARCHAR(255) NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bewerkt_op DATETIME NULL,
  bewerkt_door VARCHAR(100) NULL,
  INDEX idx_ean_locatie (ean, locatie),
  INDEX idx_ean_lot (ean, lot, vervaldatum, locatie),
  INDEX idx_tijdstempel (tijdstempel),
  INDEX idx_type (transactietype),
  INDEX idx_batch_id (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- orders (001, bijgewerkt door 011/029)
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
  laatst_ontvangen_op DATETIME NULL,
  eerst_ontvangen_op DATETIME NULL,
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- eenmalige_bestellingen (001, bijgewerkt door 013/016/018)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eenmalige_bestellingen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL,
  patientnummer VARCHAR(50) NOT NULL,
  artikelomschrijving VARCHAR(255) NOT NULL,
  ean CHAR(14) NULL,
  lot VARCHAR(255) NULL,
  vervaldatum DATE NULL,
  hoeveelheid INT NOT NULL DEFAULT 1,
  procedure_type VARCHAR(100) NULL,
  specialist VARCHAR(100) NULL,
  besteller VARCHAR(100) NULL,
  opmerking TEXT NULL,
  status ENUM('Aangevraagd','Besteld','Ontvangen','Gebruikt','Retourneren') NOT NULL DEFAULT 'Aangevraagd',
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_patientnummer (patientnummer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- eenmalige_bestelling_items (032, status/ontvangst_verwerkt sinds 033):
-- per gescande GS1-regel een eigen rij (EAN/lot/vervaldatum), i.p.v. de
-- vlakke, samengevoegde velden hierboven -- plus (033) een lifecycle-
-- status (ontvangen/verbruikt/retour, bijgewerkt door latere Verbruik-
-- of Retour-scans die op EAN+lot matchen) en of dit item al een echte
-- Ontvangst-boeking in het normale voorraadsysteem heeft gekregen.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eenmalige_bestelling_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  eenmalige_bestelling_id INT NOT NULL,
  ean CHAR(14) NOT NULL,
  productnaam VARCHAR(255) NULL,
  lot VARCHAR(255) NULL,
  vervaldatum DATE NULL,
  onbekend TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ontvangen','verbruikt','retour') NOT NULL DEFAULT 'ontvangen',
  -- Migratie 040: welke transactielog-regel dit item op 'verbruikt'/
  -- 'retour' heeft gezet, zodat verwijderTransactieRegel() de koppeling
  -- kan terugdraaien als die transactie via Recente Transacties verwijderd
  -- wordt. Zie sql/migrations/040_eenmalige_bestelling_item_transactielog_koppeling.sql.
  transactielog_id INT NULL,
  ontvangst_verwerkt TINYINT(1) NOT NULL DEFAULT 0,
  afgehandeld_op DATETIME NULL,
  afgehandeld_door VARCHAR(100) NULL,
  gs1_raw TEXT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aangemaakt_door VARCHAR(100) NULL,
  INDEX idx_bestelling (eenmalige_bestelling_id),
  INDEX idx_ean_status_herstel (ean, status),
  INDEX idx_ebi_transactielog (transactielog_id),
  CONSTRAINT fk_ebi_bestelling_herstel FOREIGN KEY (eenmalige_bestelling_id)
    REFERENCES eenmalige_bestellingen(id) ON DELETE CASCADE,
  CONSTRAINT fk_ebi_transactielog_herstel FOREIGN KEY (transactielog_id)
    REFERENCES transactielog(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- foutlog (001, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS foutlog (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstip DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fouttype VARCHAR(100) NOT NULL,
  barcode VARCHAR(100) NULL,
  bericht TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users (002, bijgewerkt door 009/015/017/019 -- specialist_id is in 019
-- weer VERWIJDERD, dus komt hieronder bewust niet meer voor)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  wachtwoord_hash VARCHAR(255) NOT NULL,
  rol ENUM('specialist','laborant','bekijker') NOT NULL DEFAULT 'bekijker',
  is_beheerder TINYINT(1) NOT NULL DEFAULT 0,
  -- Migratie 036 (licentiemodel): licentie_vrij hoort precies bij het
  -- account dat install.php aanmaakt (altijd toegestaan); elke andere
  -- gebruiker heeft een geldige, gekoppelde license_activations-rij
  -- nodig. Zie includes/license.php.
  licentie_vrij TINYINT(1) NOT NULL DEFAULT 0,
  licentie_geblokkeerd TINYINT(1) NOT NULL DEFAULT 0,
  foto VARCHAR(255) NULL,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laatst_ingelogd DATETIME NULL,
  taal ENUM('en','nl') NOT NULL DEFAULT 'en',
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- license_activations (036) -- zie sql/migrations/036_licentie.sql voor
-- de volledige toelichting.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS license_activations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  token TEXT NOT NULL,
  token_id VARCHAR(32) NOT NULL UNIQUE,
  gebonden_gebruiker_id INT NULL,
  geldig_vanaf DATE NOT NULL,
  geldig_tot DATE NOT NULL,
  toegevoegd_door VARCHAR(100) NULL,
  toegevoegd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_license_gebruiker FOREIGN KEY (gebonden_gebruiker_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- specialisten (004, bijgewerkt door 010/016)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS specialisten (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL UNIQUE,
  type ENUM('arts','laborant','overig') NOT NULL DEFAULT 'arts',
  email VARCHAR(150) NULL,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- procedure_typen (004, bijgewerkt door 019)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS procedure_typen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(150) NOT NULL UNIQUE,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laborant_toegestaan TINYINT(1) NOT NULL DEFAULT 0,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- locatie_instellingen (005, bijgewerkt door 008)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locatie_instellingen (
  slot ENUM('ik','hybok','kar','berging') PRIMARY KEY,
  naam VARCHAR(100) NOT NULL,
  volgorde INT NOT NULL DEFAULT 0,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laatst_geteld_op DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Startgegevens: alleen invullen als de tabel LEEG is (dus net vers
-- aangemaakt) -- bestaan er al rijen (de tabel had gewoon nooit
-- ontbroken), dan gebeurt hier niets. Pas de namen hierna gewoon aan via
-- het Locaties-scherm als "Room A"/"Room B"/"Cart"/"Storage" niet (meer)
-- de namen zijn die je gebruikt.
INSERT INTO locatie_instellingen (slot, naam, volgorde, actief)
SELECT * FROM (
  SELECT 'ik' AS slot, 'Room A' AS naam, 1 AS volgorde, 1 AS actief
  UNION ALL SELECT 'hybok', 'Room B', 2, 1
  UNION ALL SELECT 'kar', 'Cart', 3, 1
  UNION ALL SELECT 'berging', 'Storage', 4, 1
) seed
WHERE NOT EXISTS (SELECT 1 FROM locatie_instellingen LIMIT 1);

-- ---------------------------------------------------------------------
-- transactielog_wijzigingen (006, actie-kolom verbreed naar VARCHAR in 038)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactielog_wijzigingen (
  id INT PRIMARY KEY AUTO_INCREMENT,
  transactielog_id INT NOT NULL,
  actie VARCHAR(30) NOT NULL,
  gebruiker_naam VARCHAR(100) NULL,
  oude_waarden JSON NOT NULL,
  nieuwe_waarden JSON NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transactielog_id (transactielog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- locaties_extra (007, bijgewerkt door 008)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locaties_extra (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(100) NOT NULL UNIQUE,
  volgorde INT NOT NULL DEFAULT 100,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laatst_geteld_op DATETIME NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- artikel_locatie_extra (007, bijgewerkt door 031 -- min_aantal is
-- daarin VERWIJDERD, komt hieronder bewust niet meer voor)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artikel_locatie_extra (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL,
  locatie_extra_id INT NOT NULL,
  max_aantal INT NOT NULL DEFAULT 0,
  kastlocatie VARCHAR(100) NULL,
  UNIQUE KEY uniq_ean_locatie (ean, locatie_extra_id),
  FOREIGN KEY (locatie_extra_id) REFERENCES locaties_extra(id) ON DELETE CASCADE,
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- audit_log (010, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tijdstempel DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gebruiker_naam VARCHAR(100) NULL,
  actie VARCHAR(100) NOT NULL,
  entiteit_type VARCHAR(50) NULL,
  entiteit_id VARCHAR(100) NULL,
  details TEXT NULL,
  INDEX idx_tijdstempel (tijdstempel),
  INDEX idx_actie (actie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- producenten (012, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS producenten (
  id INT PRIMARY KEY AUTO_INCREMENT,
  naam VARCHAR(150) NOT NULL UNIQUE,
  website VARCHAR(255) NULL,
  email VARCHAR(150) NULL,
  telefoon VARCHAR(50) NULL,
  opmerking TEXT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_naam (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- producent_contacten (012, bijgewerkt door 015/029)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS producent_contacten (
  id INT PRIMARY KEY AUTO_INCREMENT,
  producent_id INT NOT NULL,
  naam VARCHAR(150) NOT NULL,
  functie VARCHAR(150) NULL,
  telefoon VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  foto VARCHAR(255) NULL,
  opmerking TEXT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producent_id) REFERENCES producenten(id) ON DELETE CASCADE,
  INDEX idx_producent (producent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- onbekende_scans (022, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS onbekende_scans (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL,
  gs1_raw TEXT NULL,
  omschrijving VARCHAR(255) NOT NULL,
  locatie VARCHAR(30) NULL,
  transactietype VARCHAR(30) NULL,
  gebruiker_naam VARCHAR(100) NULL,
  status ENUM('open','verwerkt') NOT NULL DEFAULT 'open',
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verwerkt_op DATETIME NULL,
  INDEX idx_ean_status (ean, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- gs1_correcties (035, op verzoek van Harm): blijvende correctie van
-- lot/vervaldatum voor een specifieke, exact herkende ruwe GS1-scanstring
-- die de parser niet goed herkende -- zie includes/lot_correcties.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gs1_correcties (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL,
  gs1_raw VARCHAR(255) NOT NULL,
  lot VARCHAR(50) NULL,
  vervaldatum DATE NULL,
  toegevoegd_door VARCHAR(100) NULL,
  toegevoegd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_gs1_raw (gs1_raw),
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- groepen (024, bijgewerkt door 030)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS groepen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(100) NOT NULL,
  mag_schrijven TINYINT(1) NOT NULL DEFAULT 0,
  landing_scherm VARCHAR(50) NULL,
  aangemaakt_op DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- gebruiker_groepen (024, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gebruiker_groepen (
  gebruiker_id INT NOT NULL,
  groep_id INT NOT NULL,
  PRIMARY KEY (gebruiker_id, groep_id),
  FOREIGN KEY (gebruiker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (groep_id) REFERENCES groepen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- groep_schermen (024, data bijgewerkt door 026)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS groep_schermen (
  groep_id INT NOT NULL,
  scherm_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (groep_id, scherm_key),
  FOREIGN KEY (groep_id) REFERENCES groepen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Startgegevens voor het groepensysteem: alleen als `groepen` LEEG is
-- (dus net vers aangemaakt). Herbouwt exact wat migratie 024 destijds
-- opzette (drie startgroepen die de oude rol-rechten overnemen), maar
-- dan met de HUIDIGE scherm-key-lijst (includes/schermen.php) i.p.v. de
-- verouderde lijst van 2 jaar geleden -- anders zouden Specialisten/
-- Laboranten/Bekijkers na herstel toegang missen tot schermen die pas
-- later zijn toegevoegd (Producenten, Beschikbaarheid, Management-
-- cockpit, de opgesplitste Nieuws/Team/Contactpersonen, etc.).
-- LET OP: dit is een REDELIJKE STANDAARD-RECONSTRUCTIE, geen garantie dat
-- dit exact overeenkomt met eventuele eigen aanpassingen die je zelf al
-- via het Groepen-scherm had gemaakt (bijv. een aangepaste groep, of
-- een groep zonder schrijfrecht op een specifiek scherm) -- loop na
-- afloop even langs Beheer > Groepen om te controleren of dit klopt.
INSERT INTO groepen (naam, mag_schrijven)
SELECT * FROM (
  SELECT 'Specialists' AS naam, 1 AS mag_schrijven
  UNION ALL SELECT 'Lab Technicians', 1
  UNION ALL SELECT 'Viewers', 0
) seed
WHERE NOT EXISTS (SELECT 1 FROM groepen LIMIT 1);

INSERT INTO gebruiker_groepen (gebruiker_id, groep_id)
SELECT u.id, g.id
FROM users u
JOIN groepen g ON
  (u.rol = 'specialist' AND g.naam = 'Specialists') OR
  (u.rol = 'laborant' AND g.naam = 'Lab Technicians') OR
  (u.rol = 'bekijker' AND g.naam = 'Viewers')
WHERE NOT EXISTS (SELECT 1 FROM gebruiker_groepen LIMIT 1);

-- Alle "configureerbare" schermen (dus alles behalve de altijd
-- beheerder-only 'gebruikers'/'groepen') voor Specialisten/Laboranten;
-- Bekijkers krijgt dezelfde lijst minus de vier Beheer-schermen.
INSERT INTO groep_schermen (groep_id, scherm_key)
SELECT g.id, s.scherm_key FROM groepen g
CROSS JOIN (
  SELECT 'scan-ontvangst' AS scherm_key UNION ALL SELECT 'scan-verbruik' UNION ALL
  SELECT 'verplaatsing' UNION ALL SELECT 'eenmalige-bestelling' UNION ALL
  SELECT 'artikel-toevoegen' UNION ALL SELECT 'verval-afboeken' UNION ALL
  SELECT 'actiecentrum' UNION ALL SELECT 'taken-beheerder' UNION ALL SELECT 'taken-laborant' UNION ALL
  SELECT 'recente-transacties' UNION ALL SELECT 'overzicht' UNION ALL
  SELECT 'boodschappenlijst' UNION ALL SELECT 'berging' UNION ALL
  SELECT 'minmax-advies' UNION ALL SELECT 'vervaldatums' UNION ALL SELECT 'lot-tracking' UNION ALL
  SELECT 'vervalfocus' UNION ALL SELECT 'verplaatsadvies' UNION ALL SELECT 'bestellers' UNION ALL
  SELECT 'open-orders' UNION ALL SELECT 'afboekingen' UNION ALL SELECT 'mijn-bestellingen' UNION ALL
  SELECT 'verbruik' UNION ALL SELECT 'voorraadwaarde' UNION ALL SELECT 'categorien' UNION ALL
  SELECT 'trends' UNION ALL SELECT 'leaderboard' UNION ALL SELECT 'product-search' UNION ALL
  SELECT 'producenten' UNION ALL SELECT 'nieuws' UNION ALL SELECT 'team' UNION ALL
  SELECT 'contactpersonen' UNION ALL SELECT 'beschikbaarheid' UNION ALL SELECT 'management-cockpit'
) s
WHERE g.naam IN ('Specialisten', 'Laboranten', 'Bekijkers')
  AND NOT EXISTS (SELECT 1 FROM groep_schermen LIMIT 1);

INSERT INTO groep_schermen (groep_id, scherm_key)
SELECT g.id, s.scherm_key FROM groepen g
CROSS JOIN (
  SELECT 'stamgegevens' AS scherm_key UNION ALL SELECT 'locaties' UNION ALL
  SELECT 'foutlog' UNION ALL SELECT 'onbekende-producten'
) s
WHERE g.naam IN ('Specialisten', 'Laboranten')
  AND NOT EXISTS (
    SELECT 1 FROM groep_schermen gs2
    WHERE gs2.scherm_key IN ('stamgegevens', 'locaties', 'foutlog', 'onbekende-producten')
    LIMIT 1
  );

-- ---------------------------------------------------------------------
-- minmax_advies_genegeerd (027, ongewijzigd) -- geen seed nodig, puur
-- transiente "3 maanden snooze"-status, leeg beginnen is prima.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS minmax_advies_genegeerd (
  ean CHAR(14) NOT NULL,
  locatie VARCHAR(100) NOT NULL,
  genegeerd_op DATETIME NOT NULL,
  PRIMARY KEY (ean, locatie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- categorieen (028, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorieen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(50) NOT NULL,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  volgorde INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_naam (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- categorie_specs (028, ongewijzigd)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorie_specs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categorie_id INT NOT NULL,
  spec_key VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  volgorde INT NOT NULL DEFAULT 0,
  FOREIGN KEY (categorie_id) REFERENCES categorieen(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_categorie_spec (categorie_id, spec_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Startgegevens: dezelfde categorieën/eigenschappen als migratie 028
-- oorspronkelijk zaaide -- alleen als `categorieen` LEEG is. Als je
-- zelf al categorieën/eigenschappen had toegevoegd of aangepast via
-- Stamgegevens > Categorieën, en specifiek DEZE tabel is verdwenen, dan
-- ben je die aanpassingen kwijt (geen backup) -- dit zet 'm terug naar
-- de fabrieksstand.
INSERT INTO categorieen (naam, volgorde)
SELECT * FROM (
  SELECT 'Stent' AS naam, 1 AS volgorde
  UNION ALL SELECT 'Voerdraad', 2
  UNION ALL SELECT 'Embolisatie', 3
  UNION ALL SELECT 'Sheath', 4
  UNION ALL SELECT 'Ballon', 5
  UNION ALL SELECT 'Catheter', 6
  UNION ALL SELECT 'Overig', 7
) seed
WHERE NOT EXISTS (SELECT 1 FROM categorieen LIMIT 1);

INSERT INTO categorie_specs (categorie_id, spec_key, label, volgorde)
SELECT c.id, v.spec_key, v.label, v.volgorde
FROM categorieen c
JOIN (
  SELECT 'Stent' AS cat, 'diameter_mm' AS spec_key, 'Diameter (mm)' AS label, 1 AS volgorde
  UNION ALL SELECT 'Stent', 'lengte_mm', 'Lengte (mm)', 2
  UNION ALL SELECT 'Stent', 'schacht_cm', 'Schacht (cm)', 3
  UNION ALL SELECT 'Stent', 'voerdraad_inch', 'Compatibel voerdraad (inch)', 4
  UNION ALL SELECT 'Stent', 'sheath_fr', 'Compatibel sheath (Fr)', 5

  UNION ALL SELECT 'Voerdraad', 'diameter_inch', 'Diameter (inch)', 1
  UNION ALL SELECT 'Voerdraad', 'lengte_cm', 'Lengte (cm)', 2
  UNION ALL SELECT 'Voerdraad', 'tip_type', 'Tip type', 3
  UNION ALL SELECT 'Voerdraad', 'coating', 'Coating', 4

  UNION ALL SELECT 'Embolisatie', 'type', 'Type', 1
  UNION ALL SELECT 'Embolisatie', 'diameter_mm', 'Diameter (mm)', 2
  UNION ALL SELECT 'Embolisatie', 'lengte_cm', 'Lengte (cm)', 3
  UNION ALL SELECT 'Embolisatie', 'toedieningsroute', 'Toedieningsroute', 4
  UNION ALL SELECT 'Embolisatie', 'catheter_inch', 'Compatibel catheter (inch)', 5

  UNION ALL SELECT 'Sheath', 'french_size', 'French Size', 1
  UNION ALL SELECT 'Sheath', 'lengte_cm', 'Lengte (cm)', 2
  UNION ALL SELECT 'Sheath', 'voerdraad_inch', 'Compatibel voerdraad (inch)', 3

  UNION ALL SELECT 'Ballon', 'diameter_mm', 'Diameter (mm)', 1
  UNION ALL SELECT 'Ballon', 'lengte_mm', 'Lengte (mm)', 2
  UNION ALL SELECT 'Ballon', 'voerdraad_inch', 'Compatibel voerdraad (inch)', 3
  UNION ALL SELECT 'Ballon', 'sheath_fr', 'Compatibel sheath (Fr)', 4

  UNION ALL SELECT 'Catheter', 'french_size_closure', 'French size closure device', 1
  UNION ALL SELECT 'Catheter', 'lengte_cm', 'Lengte (cm)', 2
  UNION ALL SELECT 'Catheter', 'voerdraad_inch', 'Compatibel voerdraad (inch)', 3
  UNION ALL SELECT 'Catheter', 'schacht_cm', 'Schacht (cm)', 4
  UNION ALL SELECT 'Catheter', 'sheath_fr', 'Compatibel sheath (Fr)', 5
) v ON v.cat = c.naam
WHERE NOT EXISTS (SELECT 1 FROM categorie_specs LIMIT 1);

-- ---------------------------------------------------------------------
-- naam_hernoemingen (039, op verzoek van Harm -- locatie-hernoemen
-- cascade-bug): zie sql/migrations/039_naam_hernoemingen.sql en
-- includes/hernoemingen.php voor de volledige toelichting.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS naam_hernoemingen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  soort VARCHAR(20) NOT NULL,
  oude_naam VARCHAR(191) NOT NULL,
  nieuwe_naam VARCHAR(191) NOT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_soort_oude_naam (soort, oude_naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Controle achteraf: dit zou nu 24 tabellen moeten tonen (23 + deze
-- schema_migrations). Vergelijk desnoods met de lijst in de commentaar
-- bovenaan sql/schema.sql.
-- ---------------------------------------------------------------------
SHOW TABLES;
