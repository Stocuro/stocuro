-- Migratie 036, op verzoek van Harm: licentiemodel voor zelf-hostbare
-- distributie aan andere afdelingen/ziekenhuizen. 1 gratis, vrije
-- gebruiker per installatie (het account dat de installer aanmaakt);
-- elke extra gebruiker heeft een eigen, ondertekende activatiecode nodig
-- (1 code = 1 gebruiker, 1 jaar geldig). Zie includes/license.php voor de
-- volledige (offline, Ed25519-gebaseerde) verificatielogica.
--
-- users.licentie_vrij: precies één rij hoort dit op 1 te hebben (het
-- account dat install.php aanmaakt) -- dat account is altijd toegestaan,
-- ongeacht codes. Elke andere gebruiker met licentie_vrij=0 heeft een
-- geldige, gekoppelde rij in license_activations nodig om te mogen
-- inloggen (zie controleerLicentieGebruiker() in includes/license.php).
--
-- users.licentie_geblokkeerd: bijgewerkt bij elke licentiecontrole (login
-- + periodiek tijdens gebruik) -- puur een leesbare cache voor het
-- Gebruikers-scherm (zodat een beheerder in één oogopslag ziet wie
-- geblokkeerd is), de daadwerkelijke controle bij login/periodiek
-- herverifieert altijd opnieuw en vertrouwt deze kolom niet blind.
ALTER TABLE users
  ADD COLUMN licentie_vrij TINYINT(1) NOT NULL DEFAULT 0 AFTER is_beheerder,
  ADD COLUMN licentie_geblokkeerd TINYINT(1) NOT NULL DEFAULT 0 AFTER licentie_vrij;

-- license_activations: elke rij koppelt precies één ondertekende
-- activatiecode aan precies één gebruiker. Het token zelf (niet alleen
-- een afgeleide hash) wordt bewaard, zodat een herverificatie
-- (sodium_crypto_sign_verify_detached tegen de ingebakken public key)
-- altijd tegen de ORIGINELE, ondertekende bytes gebeurt -- geen enkel
-- veld hier (geldig_tot incluis) wordt ooit vertrouwd zonder die
-- handtekeningcontrole, dit zijn puur spiegelkolommen voor snelle
-- lijstweergave/sortering.
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

INSERT INTO schema_migrations (migratie) VALUES ('036_licentie.sql');
