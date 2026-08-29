-- =====================================================================
-- voorbeelddata.sql
-- Volledig fictieve demo-artikelen -- GEEN echte productdata van welke
-- organisatie dan ook. Bedoeld om Stocuro na installatie meteen te
-- kunnen uitproberen zonder eerst zelf data in te voeren.
--
-- Draai dit NA de migraties (sql/migrate.php), als alternatief voor een
-- eigen Excel-import (migratie/export_from_excel.py is er nog steeds
-- voor wie eigen data wil importeren).
-- =====================================================================

INSERT INTO artikelen
  (ean, productnaam, categorie, besteltype,
   min_ik, max_ik, min_hybok, max_hybok, min_kar, max_kar, min_berging, max_berging,
   locatie_ik, locatie_berging, locatie_hybok,
   artikelref, producent, prijs)
VALUES
  ('00000000000101', 'Example Stent 6mm',        'Stent',       'Automatisch', 2, 6, 1, 4, 1, 3, 5, 15, 'Cabinet A1', 'Shelf 3B', 'Cabinet H1', 'STT-101', 'Demo Supplier Co', 245.00),
  ('00000000000102', 'Example Guidewire 0.035"',  'Voerdraad',   'Automatisch', 3, 8, 2, 5, 2, 4, 8, 20, 'Cabinet A2', 'Shelf 3C', 'Cabinet H2', 'GWR-102', 'Demo Supplier Co', 38.50),
  ('00000000000103', 'Example Embolisation Coil', 'Embolisatie', 'Handmatig',   1, 3, 1, 2, 0, 1, 3, 8,  'Cabinet A3', 'Shelf 4A', 'Cabinet H3', 'EMB-103', 'Sample Medical BV', 189.00),
  ('00000000000104', 'Example Sheath 6F',         'Sheath',      'Automatisch', 4, 10, 2, 6, 3, 6, 10, 25, 'Cabinet B1', 'Shelf 4B', 'Cabinet H4', 'SHT-104', 'Demo Supplier Co', 22.75),
  ('00000000000105', 'Example Balloon Catheter',  'Ballon',      'Automatisch', 2, 5, 1, 3, 1, 2, 4, 10, 'Cabinet B2', 'Shelf 4C', 'Cabinet H5', 'BAL-105', 'Sample Medical BV', 156.00),
  ('00000000000106', 'Example Diagnostic Catheter','Catheter',   'Automatisch', 3, 7, 2, 4, 2, 4, 6, 15, 'Cabinet B3', 'Shelf 5A', 'Cabinet H6', 'CAT-106', 'Demo Supplier Co', 64.25),
  ('00000000000107', 'Example Closure Device',    'Catheter',    'Handmatig',   1, 2, 0, 1, 0, 1, 2, 5,  'Cabinet C1', 'Shelf 5B', 'Cabinet H7', 'CLO-107', 'Sample Medical BV', 320.00),
  ('00000000000108', 'Example Safety Gloves L',   'Overig',      'Automatisch', 5, 20, 5, 20, 5, 15, 20, 50, 'Cabinet C2', 'Shelf 5C', 'Cabinet H8', 'GLV-108', 'Generic Supplies Inc', 0.45),
  ('00000000000109', 'Example Filter Cartridge',  'Overig',      'Automatisch', 2, 6, 1, 3, 1, 3, 5, 12, 'Cabinet C3', 'Shelf 6A', 'Cabinet H9', 'FIL-109', 'Generic Supplies Inc', 18.90),
  ('00000000000110', 'Example Contrast Syringe',  'Overig',      'Automatisch', 4, 12, 3, 8, 2, 6, 10, 25, 'Cabinet D1', 'Shelf 6B', 'Cabinet H10', 'SYR-110', 'Sample Medical BV', 7.20);
