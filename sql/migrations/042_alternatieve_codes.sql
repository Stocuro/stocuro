-- =====================================================================
-- 042_alternatieve_codes.sql
-- "ik heb ook sommige producten die hebben meerdere barcodes/qr codes.
-- Niet qua omdozen of andere voorraad, gewoon meerdere codes." --
-- sommige producten hebben naast hun hoofd-EAN nog één of meer andere,
-- losstaande barcodes/QR-codes die altijd naar hetzelfde fysieke stuk
-- verwijzen (dus hoeveelheid 1, net als het stuk-EAN zelf -- geen
-- doosvariant zoals artikelen.doos_ean, dat heeft een eigen
-- eenheden_per_doos-vermenigvuldiger).
--
-- Eigen tabel i.p.v. een los tekstveld zoals artikelen.logistiek_code,
-- want er kunnen er meerdere per artikel zijn. Net als de andere tabellen
-- die een artikel als PLATTE EAN-WAARDE bevatten (zie
-- ARTIKEL_EAN_CASCADE_TABELLEN in includes/artikelen.php) is "ean" hier
-- bewust geen FOREIGN KEY naar artikelen.ean/.id -- dezelfde reden: een
-- EAN-wijziging (wijzigArtikelEan()) cascadeert dit handmatig, binnen
-- dezelfde DB-transactie als alle andere tabellen in die lijst (deze
-- tabel is daar ook aan toegevoegd).
--
-- "code" is UNIQUE: een barcode/QR-code mag maar naar precies één
-- product resolven, anders scant hetzelfde label soms naar het ene en
-- soms naar het andere product. voegAlternatieveCodeToe() controleert dit
-- ook al expliciet (met een duidelijke foutmelding, welk product de code
-- al heeft) vóórdat de insert hier op de UNIQUE-constraint zou stuklopen.
-- =====================================================================

CREATE TABLE IF NOT EXISTS artikel_alternatieve_codes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ean CHAR(14) NOT NULL,
  code VARCHAR(100) NOT NULL UNIQUE,
  toegevoegd_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  toegevoegd_door VARCHAR(100) NULL,
  INDEX idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('042_alternatieve_codes.sql');
