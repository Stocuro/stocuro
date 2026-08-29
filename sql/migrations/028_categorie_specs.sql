-- =====================================================================
-- 028_categorie_specs.sql
-- Op verzoek van Harm: producten vallen in een categorie (ballon, stent,
-- voerdraad, sheath, ...) en per categorie zijn andere eigenschappen
-- relevant (diameter, lengte, French size, coating, ...). Die eigenschap-
-- definities zaten tot nu toe hardcoded in specLabels() (includes/
-- voorraad.php) en de categorielijst zelf hardcoded in de <select> op het
-- Artikel Toevoegen-formulier (assets/js/app.js) -- geen van beide was
-- beheerbaar zonder de code aan te passen. Deze migratie verplaatst beide
-- naar de database, zodat ze via het nieuwe Stamgegevens-blok "Categorieën
-- & Eigenschappen" bewerkt kunnen worden.
--
-- artikelen.categorie blijft bewust vrije tekst (geen FK) -- zelfde reden
-- als artikelen.producent (zie includes/producenten.php): dit voorkomt dat
-- een bestaand artikel "wees" wordt als een categorie ooit gedeactiveerd
-- wordt, en houdt de koppeling consistent met hoe elders in dit schema al
-- met naam-matching wordt gewerkt i.p.v. een harde foreign key.
--
-- Seed-data hieronder is EXACT de categorielijst en eigenschappen-per-
-- categorie zoals ze al hardcoded stonden -- deze migratie verandert dus
-- niets aan het gedrag voor bestaande artikelen, alleen aan waar de
-- definitie vandaan komt.
-- =====================================================================

CREATE TABLE categorieen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(50) NOT NULL,
  actief TINYINT(1) NOT NULL DEFAULT 1,
  volgorde INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_naam (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categorie_specs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categorie_id INT NOT NULL,
  spec_key VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  volgorde INT NOT NULL DEFAULT 0,
  FOREIGN KEY (categorie_id) REFERENCES categorieen(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_categorie_spec (categorie_id, spec_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categorieen (naam, volgorde) VALUES
  ('Stent', 1), ('Voerdraad', 2), ('Embolisatie', 3), ('Sheath', 4),
  ('Ballon', 5), ('Catheter', 6), ('Overig', 7);

INSERT INTO categorie_specs (categorie_id, spec_key, label, volgorde)
SELECT id, v.spec_key, v.label, v.volgorde
FROM categorieen
CROSS JOIN (
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
) v ON v.cat = categorieen.naam;

INSERT INTO schema_migrations (migratie) VALUES ('028_categorie_specs.sql');
