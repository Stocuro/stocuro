-- =====================================================================
-- 039_naam_hernoemingen.sql
-- Bugfix, op verzoek van Harm: "Wanneer ik locaties hernoem dan wordt dit
-- niet automatisch in de lots in stock en transactie historie locaties
-- aangepast."
--
-- Oorzaak: transactielog.locatie (en transactielog.specialist) slaan de
-- naam op als vrije tekst op het moment van de transactie -- niet als
-- foreign key naar locatie_instellingen/locaties_extra (resp. users).
-- Dat is BEWUST zo (zie de bestandskop van includes/locaties.php en de
-- vergelijkbare, expliciet toegelichte keuze bij includes/producenten.php):
-- het voorkomt dat het verwijderen/deactiveren van een locatie of account
-- ooit historische transacties zou orphanen. Maar niets houdt bij dat een
-- naam is VERANDERD, dus een oude transactielog-rij bleef voor altijd de
-- oude naam tonen, en voorraadberekeningen die per locatie groeperen
-- (transactieNettoPerLocatie(), voorraadLot() in includes/voorraad.php)
-- gingen de oude en nieuwe naam als twee aparte, niet-gerelateerde
-- locaties behandelen -- waardoor voorraad die onder de oude naam was
-- geboekt na een rename zelfs leek te "verdwijnen" uit de Voorraad-tabel.
--
-- Fix: één generieke hernoem-logtabel (soort = 'locatie' of 'specialist',
-- zodat dezelfde tabel/aanpak straks ook de users.naam-bewerkfunctie
-- (taak "Naam, e-mail van gebruikers zijn niet aan te passen") veilig
-- maakt -- zonder dit zou het bewerken van een gebruikersnaam exact
-- dezelfde soort bug introduceren voor Leaderboard/Verbruik per Procedure,
-- die ook op transactielog.specialist groeperen). Een rename wordt hier
-- als rij toegevoegd (nooit bewerkt/verwijderd, zodat een kort daarna
-- weer teruggedraaide naam nog steeds correct oplosbaar blijft via de
-- keten), en includes/hernoemingen.php::actueleNaam() volgt die keten van
-- oud naar nieuw op leesmoment.
-- =====================================================================

CREATE TABLE IF NOT EXISTS naam_hernoemingen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  soort VARCHAR(20) NOT NULL,
  oude_naam VARCHAR(191) NOT NULL,
  nieuwe_naam VARCHAR(191) NOT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_soort_oude_naam (soort, oude_naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('039_naam_hernoemingen.sql');
