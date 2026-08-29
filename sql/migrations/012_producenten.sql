-- =====================================================================
-- 012_producenten.sql
-- Contactgegevens per producent (op verzoek van Harm): website, algemeen
-- e-mailadres/telefoonnummer, en een onbeperkt aantal contactpersonen
-- per producent.
--
-- BEWUSTE KEUZE: artikelen.producent blijft een vrij tekstveld, precies
-- zoals het al was -- geen producent_id-kolom/FK op artikelen, geen
-- wijziging aan het product-toevoeg/bewerkformulier. In plaats daarvan
-- koppelt includes/producenten.php::vindProducentVoorNaam() de
-- producenten-tabel aan artikelen.producent op NAAM (case-insensitive,
-- getrimd), puur als opzoeking t.b.v. de link "contactgegevens bekijken"
-- op de productpagina. Reden: producent is, anders dan specialist/
-- procedure_type (migratie 004), niet bedoeld als gecontroleerde
-- keuzelijst waar rapportages op groeperen -- het is verrijkende
-- contactinformatie naast een bestaand vrij veld. Dit voorkomt een
-- migratie van bestaande productdata en houdt het bestel-/
-- toevoegformulier ongewijzigd.
--
-- Geen "actief"-vlag zoals specialisten/procedure_typen: producenten
-- worden nergens elders bij naam/ID gerefereerd vanuit historische data
-- (artikelen.producent is en blijft losse tekst), dus een producent of
-- contactpersoon die niet meer relevant is kan gewoon verwijderd worden
-- zonder wezen-rijen elders te veroorzaken.
-- =====================================================================

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

CREATE TABLE IF NOT EXISTS producent_contacten (
  id INT PRIMARY KEY AUTO_INCREMENT,
  producent_id INT NOT NULL,
  naam VARCHAR(150) NOT NULL,
  functie VARCHAR(150) NULL,
  telefoon VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producent_id) REFERENCES producenten(id) ON DELETE CASCADE,
  INDEX idx_producent (producent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('012_producenten.sql');
