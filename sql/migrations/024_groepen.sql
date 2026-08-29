-- =====================================================================
-- 024_groepen.sql
-- Vrij aanmaakbare permissiegroepen, op verzoek van Harm: naast de vaste
-- Specialist/Laborant/Bekijker-indeling (die blijft bestaan -- zie
-- toelichting hieronder) wil hij zelf extra groepen kunnen aanmaken en
-- per groep instellen welke schermen (formulieren, dashboards, en een
-- deel van beheer) die groep te zien krijgt, en of de groep mag
-- schrijven (scannen/bewerken) of alleen mag lezen.
--
-- BELANGRIJK -- dit is een NIEUW, LOSSTAAND systeem naast users.rol, niet
-- een vervanging ervan. users.rol ('specialist'/'laborant'/'bekijker',
-- migratie 019) blijft ongewijzigd bestaan en blijft zijn HUIDIGE,
-- klinische betekenis houden: wie mag als uitvoerder van een procedure
-- gekozen worden (includes/transacties.php::verwerkScan(), de
-- PICC-regel via procedure_typen.laborant_toegestaan), en de
-- Leaderboard-uitsplitsing specialist/laborant (includes/leaderboard.php).
-- Die klinische regels hebben niets te maken met "welke schermen mag
-- deze gebruiker zien" -- vandaar bewust twee losse assen:
--   - users.rol           = welk soort medewerker is dit (klinisch/
--                            procedure-geschiktheid), ongewijzigd.
--   - groepen (dit bestand) = welke schermen mag deze gebruiker zien, en
--                            mag hij/zij schrijven -- vrij configureerbaar,
--                            een gebruiker kan bij meerdere groepen horen.
--
-- Schrijftoegang: includes/auth.php::requireSchrijfToegang() gebruikt
-- vanaf nu "is_beheerder OF lid van minstens één groep met
-- mag_schrijven=1" i.p.v. de oude harde koppeling aan rol='bekijker'.
-- Om het gedrag van bestaande accounts na deze migratie ongewijzigd te
-- houden, worden hieronder drie startgroepen aangemaakt die exact de
-- oude rol-gebaseerde rechten overnemen, en wordt elke bestaande
-- gebruiker gekoppeld aan de groep die bij zijn/haar huidige rol hoort.
--
-- Twee schermen (Gebruikers en dit nieuwe Groepen-scherm zelf) blijven
-- bewust ALTIJD beheerder-only, ongeacht groepslidmaatschap -- zie
-- includes/schermen.php. Zelf rechten kunnen uitdelen is te gevoelig om
-- via een willekeurig aan te maken groep te laten lopen.
-- =====================================================================

CREATE TABLE groepen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(100) NOT NULL,
  mag_schrijven TINYINT(1) NOT NULL DEFAULT 0,
  aangemaakt_op DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many: een gebruiker kan bij meerdere groepen horen.
CREATE TABLE gebruiker_groepen (
  gebruiker_id INT NOT NULL,
  groep_id INT NOT NULL,
  PRIMARY KEY (gebruiker_id, groep_id),
  FOREIGN KEY (gebruiker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (groep_id) REFERENCES groepen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Welke schermen (scherm_key, zie includes/schermen.php) een groep mag
-- zien. Aanwezig in deze tabel = toegang; scherm_key is vrije tekst
-- (geen FK naar een aparte schermen-tabel) omdat de lijst van schermen
-- in code (includes/schermen.php) leeft, niet in de database.
CREATE TABLE groep_schermen (
  groep_id INT NOT NULL,
  scherm_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (groep_id, scherm_key),
  FOREIGN KEY (groep_id) REFERENCES groepen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drie startgroepen die de bestaande rol-namen en -rechten overnemen.
-- Namen bewust in het Engels (net als de rest van de standaard-UI-tekst,
-- de app is EN-by-default met NL als optionele toggle per account) --
-- groepsnamen zijn vrije tekst en lopen NIET door het vertaalsysteem
-- (assets/js/app.js), dus wat hier staat is exact wat elke gebruiker
-- altijd ziet, ongeacht zijn eigen taalkeuze.
INSERT INTO groepen (naam, mag_schrijven) VALUES
  ('Specialists', 1),
  ('Lab Technicians', 1),
  ('Viewers', 0);

-- Bestaande gebruikers koppelen aan de groep die bij hun huidige rol hoort.
INSERT INTO gebruiker_groepen (gebruiker_id, groep_id)
SELECT u.id, g.id
FROM users u
JOIN groepen g ON
  (u.rol = 'specialist' AND g.naam = 'Specialists') OR
  (u.rol = 'laborant' AND g.naam = 'Lab Technicians') OR
  (u.rol = 'bekijker' AND g.naam = 'Viewers');

-- Schermtoegang voor de startgroepen. Specialisten/Laboranten krijgen
-- alle 32 configureerbare schermen (dit was tot nu toe voor iedereen
-- gelijk -- alleen schrijven/lezen verschilde). Bekijkers krijgt dezelfde
-- lijst MINUS de vier Beheer-schermen (stamgegevens/locaties/foutlog/
-- onbekende-producten), want dat is exact hoe canViewAdminScreens()
-- (assets/js/app.js) dat tot nu toe al voor een gewone, niet-beheerder
-- Bekijker-gebruiker regelde. (Gebruikers en Groepen zelf tellen niet mee
-- -- die blijven altijd beheerder-only, zie includes/schermen.php.)
INSERT INTO groep_schermen (groep_id, scherm_key)
SELECT g.id, s.scherm_key FROM groepen g
CROSS JOIN (
  SELECT 'scan-ontvangst' AS scherm_key UNION ALL SELECT 'scan-verbruik' UNION ALL
  SELECT 'verplaatsing' UNION ALL SELECT 'eenmalige-bestelling' UNION ALL
  SELECT 'artikel-toevoegen' UNION ALL SELECT 'verval-afboeken' UNION ALL
  SELECT 'actiecentrum' UNION ALL SELECT 'taken' UNION ALL SELECT 'recente-transacties' UNION ALL
  SELECT 'overzicht' UNION ALL SELECT 'boodschappenlijst' UNION ALL SELECT 'berging' UNION ALL
  SELECT 'minmax-advies' UNION ALL SELECT 'vervaldatums' UNION ALL SELECT 'lot-tracking' UNION ALL
  SELECT 'vervalfocus' UNION ALL SELECT 'verplaatsadvies' UNION ALL SELECT 'bestellers' UNION ALL
  SELECT 'open-orders' UNION ALL SELECT 'afboekingen' UNION ALL SELECT 'mijn-bestellingen' UNION ALL
  SELECT 'verbruik' UNION ALL SELECT 'voorraadwaarde' UNION ALL SELECT 'categorien' UNION ALL
  SELECT 'trends' UNION ALL SELECT 'leaderboard' UNION ALL SELECT 'product-search' UNION ALL
  SELECT 'producenten'
) s
WHERE g.naam IN ('Specialists', 'Lab Technicians', 'Viewers');

INSERT INTO groep_schermen (groep_id, scherm_key)
SELECT g.id, s.scherm_key FROM groepen g
CROSS JOIN (
  SELECT 'stamgegevens' AS scherm_key UNION ALL SELECT 'locaties' UNION ALL
  SELECT 'foutlog' UNION ALL SELECT 'onbekende-producten'
) s
WHERE g.naam IN ('Specialists', 'Lab Technicians');

INSERT INTO schema_migrations (migratie) VALUES ('024_groepen.sql');
