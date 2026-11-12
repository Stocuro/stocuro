-- =====================================================================
-- leegmaken.sql
-- Maakt alle voorraad-/gebruiksdata leeg, zonder het schema (tabellen,
-- kolommen, migraties) aan te raken. Voor gebruikers zonder SSH-toegang:
-- plak dit in de SQL-tab van phpMyAdmin (of vergelijkbare database-
-- beheeromgeving) en voer uit. Equivalent van migratie/leegmaken.php
-- --ja, voor wie geen command line heeft.
--
-- Wat blijft staan:
--  - users, en (horen daarbij) groepen/gebruiker_groepen/groep_schermen/
--    license_activations: je zou anders mogelijk niet meer kunnen
--    inloggen, en een bewaard account zou zijn groepslidmaatschap (dus
--    schermtoegang) en licentiekoppeling kwijtraken terwijl het account
--    zelf blijft bestaan. Wil je ECHT ook alle accounts (en hun groepen/
--    licenties) kwijt (bijv. vlak vóór overdracht aan een opdrachtgever),
--    verwijder dan zelf het commentaarteken (--) voor de vijf
--    TRUNCATE TABLE-regels onderaan -- en zorg dat je daarna eerst een
--    nieuw beheerdersaccount aanmaakt (zie README) voordat je weer kunt
--    inloggen.
--  - locatie_instellingen (de 4 vaste locatie-"slots"): dat is
--    configuratie (welke namen actief zijn), geen data. Alleen
--    laatst_geteld_op wordt gereset.
--  - schema_migrations: nooit aanraken, dat is migratieboekhouding.
--
-- Dit is onomkeerbaar (geen prullenbak, geen backup) -- weet zeker dat je
-- dit wilt voordat je op "Uitvoeren"/"Go" klikt.
--
-- (bugfix, gevonden toen deze
-- lijst voor het eerst écht gecontroleerd werd tegen het volledige
-- schema -- zelfde toelichting als in migratie/leegmaken.php): deze
-- lijst was bij het schrijven compleet, maar is sindsdien niet
-- meegegroeid met latere migraties. Vooral eenmalige_bestelling_items
-- was een echt probleem, niet alleen onvolledig: die tabel heeft een
-- FOREIGN KEY naar eenmalige_bestellingen (die hieronder al wel leeg
-- werd gemaakt) -- zonder deze tabel ook leeg te maken, bleven er dus
-- wees-rijen achter die naar niet meer bestaande bestellingen verwezen.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE transactielog_wijzigingen;
TRUNCATE TABLE transactielog;
-- Migratie 035: correcties horen bij specifieke (nu verdwijnende) scans --
-- leeg meemaken voorkomt dat straks een nieuwe, toevallig identieke
-- barcode een correctie uit een heel andere periode/testset overneemt.
TRUNCATE TABLE gs1_correcties;
TRUNCATE TABLE eenmalige_bestelling_items;
TRUNCATE TABLE eenmalige_bestellingen;
TRUNCATE TABLE orders;
TRUNCATE TABLE artikel_locatie_extra;
TRUNCATE TABLE artikel_alternatieve_codes;
TRUNCATE TABLE artikelen;
TRUNCATE TABLE onbekende_scans;
TRUNCATE TABLE minmax_advies_genegeerd;
TRUNCATE TABLE naam_hernoemingen;
TRUNCATE TABLE audit_log;
TRUNCATE TABLE foutlog;
TRUNCATE TABLE specialisten;
TRUNCATE TABLE procedure_typen;
TRUNCATE TABLE locaties_extra;
TRUNCATE TABLE producent_contacten;
TRUNCATE TABLE producenten;
TRUNCATE TABLE categorie_specs;
TRUNCATE TABLE categorieen;

UPDATE locatie_instellingen SET laatst_geteld_op = NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Optioneel, standaard uitgecommentarieerd -- zie toelichting hierboven.
-- Alle vijf horen bij elkaar: alleen samen uitcommentariëren, nooit los.
-- TRUNCATE TABLE gebruiker_groepen;
-- TRUNCATE TABLE groep_schermen;
-- TRUNCATE TABLE groepen;
-- TRUNCATE TABLE license_activations;
-- TRUNCATE TABLE users;
