-- =====================================================================
-- Migratie 035: gs1_correcties
--
-- Op verzoek van Harm: soms herkent de GS1-parser (includes/gs1_parser.php)
-- een gescande barcode niet helemaal goed -- lotnummer of vervaldatum komt
-- er verkeerd uit (bijv. een fabrikant die zich niet strikt aan de
-- GS1-128-conventie houdt). Vanaf nu is dit op de productdetailpagina, in
-- het "Loten op Voorraad"-overzicht, per lot recht te zetten (zie
-- includes/lot_correcties.php::corrigeerLot()). Die correctie doet twee
-- dingen tegelijk:
--   1. Werkt METEEN alle bestaande transactielog-regels van dit lot bij
--      (ongeacht locatie of ouderdom -- dit is bewust losstaand van de
--      bestaande bewerkTransactieRegel()/TRANSACTIELOG_BEWERKBAAR_DAGEN
--      (7-dagen-grens voor "vergissing van vandaag ongedaan maken"): een
--      foute parse is een permanente datacorrectie, geen recente
--      vergissing, en kan dus ook een lot van maanden geleden raken).
--   2. Onthoudt de correctie hier, gekoppeld aan de EXACTE ruwe
--      GS1-scanstring (transactielog.gs1_raw, al aanwezig sinds eerdere
--      migraties): wordt dezelfde barcode in de toekomst nogmaals gescand
--      (nieuwe voorraad van hetzelfde fysieke lot), dan past
--      pasGs1CorrectieToe() de gecorrigeerde waarden automatisch toe,
--      vóórdat er iets geboekt wordt -- zie splitsBekendOnbekend()
--      (includes/transacties.php, gedeeld door verwerkScan()/
--      verplaatsing()/retournering()/verwerkTelling()) en
--      gs1VoorEenmaligeBestelling() (includes/eenmalige_bestellingen.php).
--
-- gs1_raw is VARCHAR (niet TEXT) juist om er een normale UNIQUE-index op
-- te kunnen zetten voor een snelle exacte lookup bij elke scan -- GS1-128-
-- scanstrings zijn in de praktijk altijd ruim onder de 255 tekens.
-- =====================================================================

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

INSERT INTO schema_migrations (migratie) VALUES ('035_gs1_correcties.sql');
