-- =====================================================================
-- 019_rollenmodel_herzien.sql
-- Op verzoek van Harm: volledige herziening van het rollenmodel.
--
-- Voortaan drie basis-"functies" (users.rol): specialist, laborant,
-- bekijker. "Beheerder" is geen functie meer maar een los vinkje
-- (users.is_beheerder) dat bovenop elke functie gezet kan worden -- een
-- Bekijker kan dus ook Beheerder zijn, een Specialist ook, etc.
--
-- Specialisten/Laboranten krijgen nu VOLLEDIGE schrijfrechten (net als
-- de oude 'medewerker'-rol) -- de vroegere 'specialist'-rol was bewust
-- alleen-lezen (zelfbedieningsaccount), maar Harm wil dat iedereen die
-- in het systeem werkt ook daadwerkelijk zelf kan scannen/bestellen.
-- Alleen 'bekijker' (voorheen 'alleen-lezen') blijft alleen-lezen, tenzij
-- diegene ook is_beheerder=1 heeft (zie includes/auth.php::
-- requireSchrijfToegang()).
--
-- De aparte 'specialisten'-tabel (naamlijst los van een inlogaccount)
-- wordt volledig samengevoegd met 'users': iedereen die met het systeem
-- werkt LOGT IN, dus is een specialist/laborant voortaan altijd een
-- users-rij, niet meer (ook) een aparte specialisten-rij. Bestaande
-- specialisten-rijen die nog geen gekoppeld account hebben, blijven
-- gewoon in de 'specialisten'-tabel staan als "nog niet gemigreerd" --
-- die worden niet automatisch omgezet (er is geen e-mailadres/wachtwoord
-- om een account te verzinnen), maar wel als losse lijst getoond op het
-- Gebruikers-scherm zodat de beheerder ze eenvoudig alsnog een account
-- kan geven (zie includes/stamgegevens.php::getNietGemigreerdeSpecialisten()).
-- Er komen vanaf nu geen nieuwe rijen meer bij in 'specialisten' --
-- nieuwe specialisten/laboranten worden direct als users-rij aangemaakt.
--
-- Data-migratie volgorde is belangrijk (elke stap bouwt voort op de
-- vorige):
--   1. is_beheerder toevoegen, gevuld op basis van de HUIDIGE rol-waarde
--      ('beheerder') voordat die waarde zelf verandert.
--   2. Brede default-mapping: bestaande 'beheerder'/'medewerker'-accounts
--      (die vandaag alle twee volledige schrijfrechten hebben) worden
--      'specialist' -- de nieuwe functie met dezelfde rechten. Kan altijd
--      achteraf per account op 'laborant'/'bekijker' gezet worden via het
--      Gebruikers-scherm.
--   3. 'alleen-lezen' -> 'bekijker' (rechtstreekse naamswisseling, zelfde
--      betekenis, alleen-lezen blijft alleen-lezen).
--   4. Specifieke override: bestaande 'specialist'-rol-accounts die al
--      gekoppeld waren aan een specialisten-rij met type='laborant'
--      worden 'laborant' i.p.v. de brede 'specialist'-default uit stap 2
--      -- moet NA stap 2 draaien zodat deze specifiekere regel wint.
--   5. rol-kolom pas nu verkleinen naar de nieuwe ENUM -- alle bestaande
--      waarden zijn dan al gegarandeerd geldig.
--   6. specialist_id (FK + kolom) verwijderen -- niet meer nodig, een
--      specialist/laborant IS nu een users-rij, geen losse koppeling.
--
-- procedure_typen krijgt laborant_toegestaan: een Laborant kan volgens
-- Harm een procedure alleen zelf uitvoeren als die daarvoor is aangevinkt
-- (in de praktijk nu alleen PICC-lijn) -- instelbaar per proceduretype
-- i.p.v. hardcoded op de naam, zodat een toekomstige uitzondering geen
-- nieuwe code vergt. Bij migreren wordt elke bestaande procedure met
-- "PICC" in de naam vast op 1 gezet als praktische default; de beheerder
-- kan dit verder aanpassen via Stamgegevens > Procedure types.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN is_beheerder TINYINT(1) NOT NULL DEFAULT 0 AFTER rol;

UPDATE users SET is_beheerder = 1 WHERE rol = 'beheerder';

UPDATE users SET rol = 'specialist' WHERE rol IN ('beheerder', 'medewerker');
UPDATE users SET rol = 'bekijker' WHERE rol = 'alleen-lezen';

UPDATE users u
  JOIN specialisten s ON s.id = u.specialist_id
  SET u.rol = 'laborant'
  WHERE u.rol = 'specialist' AND s.type = 'laborant';

ALTER TABLE users
  MODIFY COLUMN rol ENUM('specialist', 'laborant', 'bekijker') NOT NULL DEFAULT 'bekijker';

ALTER TABLE users
  DROP FOREIGN KEY fk_users_specialist,
  DROP COLUMN specialist_id;

ALTER TABLE procedure_typen
  ADD COLUMN laborant_toegestaan TINYINT(1) NOT NULL DEFAULT 0;

UPDATE procedure_typen SET laborant_toegestaan = 1 WHERE naam LIKE '%PICC%';

INSERT INTO schema_migrations (migratie) VALUES ('019_rollenmodel_herzien.sql');
