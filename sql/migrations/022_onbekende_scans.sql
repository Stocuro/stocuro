-- =====================================================================
-- 022_onbekende_scans.sql
-- Vangnet voor barcodes die tijdens een scan (Ontvangst/Verbruik,
-- Verplaatsing/Retournering, Tellen) niet herkend worden door
-- lookupArtikelen() (noch als stuk-EAN, doos-EAN, noch -- vanaf migratie
-- 021 -- als logistiek_code). Voorheen verdween zo'n regel stilzwijgend
-- in de tijdelijke $fouten-array van die ene scan-aanroep: de scanner
-- zag "regel overgeslagen" en daarna was de informatie weg, geen enkele
-- beheerder kreeg dit ooit te zien.
--
-- Nieuwe gedrag (includes/transacties.php/telling.php, blokkerend, zie
-- migratieplan-comments aldaar): een scan met onbekende regels wordt pas
-- volledig verwerkt als de gebruiker per onbekende barcode een korte
-- omschrijving heeft ingevuld ("wat voor product is dit?"). Die
-- omschrijving + context wordt hier weggeschreven zodat een beheerder
-- er later een echt artikel van kan maken (Admin-scherm "Onbekende
-- Producten"). Eén EAN kan hier meerdere keren voorkomen (elke keer dat
-- iemand 'm scant voordat een beheerder het artikel aanmaakt) -- dat is
-- bewust geen unieke sleutel op ean, puur een logboek; het admin-scherm
-- groepeert zelf op ean voor de weergave.
-- =====================================================================

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

INSERT INTO schema_migrations (migratie) VALUES ('022_onbekende_scans.sql');
