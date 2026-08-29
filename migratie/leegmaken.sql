-- =====================================================================
-- leegmaken.sql
-- Maakt alle voorraad-/gebruiksdata leeg, zonder het schema (tabellen,
-- kolommen, migraties) aan te raken. Voor gebruikers zonder SSH-toegang:
-- plak dit in de SQL-tab van phpMyAdmin (of vergelijkbare database-
-- beheeromgeving) en voer uit. Equivalent van migratie/leegmaken.php
-- --ja, voor wie geen command line heeft.
--
-- Wat blijft staan:
--  - users: je zou anders mogelijk niet meer kunnen inloggen. Wil je
--    ECHT ook alle accounts kwijt (bijv. vlak vóór overdracht aan een
--    opdrachtgever), verwijder dan zelf het commentaarteken (--) voor de
--    TRUNCATE TABLE users-regel onderaan -- en zorg dat je daarna eerst
--    een nieuw beheerdersaccount aanmaakt (zie README) voordat je weer
--    kunt inloggen.
--  - locatie_instellingen (de 4 vaste locatie-"slots"): dat is
--    configuratie (welke namen actief zijn), geen data. Alleen
--    laatst_geteld_op wordt gereset.
--  - schema_migrations: nooit aanraken, dat is migratieboekhouding.
--
-- Dit is onomkeerbaar (geen prullenbak, geen backup) -- weet zeker dat je
-- dit wilt voordat je op "Uitvoeren"/"Go" klikt.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE transactielog_wijzigingen;
TRUNCATE TABLE transactielog;
-- Migratie 035: correcties horen bij specifieke (nu verdwijnende) scans --
-- leeg meemaken voorkomt dat straks een nieuwe, toevallig identieke
-- barcode een correctie uit een heel andere periode/testset overneemt.
TRUNCATE TABLE gs1_correcties;
TRUNCATE TABLE orders;
TRUNCATE TABLE eenmalige_bestellingen;
TRUNCATE TABLE artikel_locatie_extra;
TRUNCATE TABLE artikelen;
TRUNCATE TABLE audit_log;
TRUNCATE TABLE foutlog;
TRUNCATE TABLE specialisten;
TRUNCATE TABLE procedure_typen;
TRUNCATE TABLE locaties_extra;
TRUNCATE TABLE producent_contacten;
TRUNCATE TABLE producenten;

UPDATE locatie_instellingen SET laatst_geteld_op = NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Optioneel, standaard uitgecommentarieerd -- zie toelichting hierboven:
-- TRUNCATE TABLE users;
