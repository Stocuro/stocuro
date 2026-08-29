-- =====================================================================
-- 010_gebruiker_tracking.sql
-- Legt vast WIE (ingelogde gebruiker) een actie heeft uitgevoerd, naast
-- de al bestaande "specialist" (voor wie een procedure is gedaan -- een
-- apart concept, zie migratie 004: specialisten heeft geen koppeling met
-- users, een specialist heeft niet per se een inlogaccount). Drie
-- onderdelen:
--
-- 1. transactielog.aangemaakt_door: naam van de ingelogde gebruiker die
--    de scan/verplaatsing/telling/afboeking deed. Zelfde stijl als het
--    al bestaande bewerkt_door (migratie 006) -- een platte naam-
--    snapshot, geen FK naar users.id. Dat is bewust consistent met hoe
--    prijs_op_moment/bewerkt_door al werken: een snapshot van "wie op
--    dat moment", niet een live join die met terugwerkende kracht
--    verandert als een gebruiker later hernoemd of verwijderd wordt.
--    Nullable, want oudere rijen hebben dit niet en dat is prima --
--    de kolom is puur nieuwe informatie, geen correctie van bestaande.
--
-- 2. audit_log: generieke tabel voor beheersacties die geen eigen
--    transactielog-achtige tabel hebben (orders, eenmalige bestellingen,
--    productbewerkingen/-toevoegingen, gebruikers-/locatie-/stamgegevens-
--    beheer). Transactielog zélf blijft ongemoeid door deze tabel: de
--    bestaande, rijkere transactielog_wijzigingen (migratie 006, met
--    oude/nieuwe waarden) is daar al de audit trail voor. audit_log is
--    bewust generiek (vrije "actie"-tekst + optioneel entiteit_type/
--    entiteit_id/details) i.p.v. een aparte tabel per actietype.
--
-- 3. specialisten.type: onderscheidt arts/laborant/overig, zodat
--    rapportages (bijv. "wie heeft de meeste PICC-lijnen geplaatst") per
--    type kunnen filteren of groeperen. Bestaande rijen krijgen 'arts'
--    als default (dat was voorheen impliciet de enige mogelijkheid).
-- =====================================================================

ALTER TABLE transactielog
  ADD COLUMN aangemaakt_door VARCHAR(100) NULL AFTER procedure_type;

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

ALTER TABLE specialisten
  ADD COLUMN type ENUM('arts','laborant','overig') NOT NULL DEFAULT 'arts' AFTER naam;

INSERT INTO schema_migrations (migratie) VALUES ('010_gebruiker_tracking.sql');
