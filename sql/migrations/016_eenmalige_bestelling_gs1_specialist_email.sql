-- =====================================================================
-- 016_eenmalige_bestelling_gs1_specialist_email.sql
-- Twee losse, kleine toevoegingen op verzoek van Harm:
--
-- 1. eenmalige_bestellingen.lot/vervaldatum: tot nu toe had een
--    eenmalige bestelling alleen een (optioneel, handmatig getypt) EAN.
--    Zodra het bestelde product daadwerkelijk binnenkomt, wil Harm de
--    GS1-barcode ervan kunnen scannen (net als bij Ontvangst/Verbruik) --
--    dat levert naast het EAN ook een lotnummer en vervaldatum op, die
--    tot nu toe nergens op deze tabel pasten. Beide nullable: bij het
--    aanmaken van de aanvraag (vóórdat het product er is) blijven ze
--    leeg, precies zoals ean dat vandaag al is.
--
-- 2. specialisten.email: nodig voor de nieuwe "Mail specialist"-knop op
--    de detailpagina van een eenmalige bestelling (mailto-link). Puur
--    een contactadres, GEEN inlogaccount -- dat blijft een apart concept
--    (zie migratie 017, users.specialist_id). Nullable: de meeste
--    bestaande specialisten hebben dit veld nog niet ingevuld, en dat is
--    geen fout -- de mailknop laat het "aan"-veld dan leeg zodat de
--    gebruiker het zelf invult in zijn eigen mailprogramma.
-- =====================================================================

ALTER TABLE eenmalige_bestellingen
  ADD COLUMN lot VARCHAR(50) NULL AFTER ean,
  ADD COLUMN vervaldatum DATE NULL AFTER lot;

ALTER TABLE specialisten
  ADD COLUMN email VARCHAR(150) NULL AFTER type;

INSERT INTO schema_migrations (migratie) VALUES ('016_eenmalige_bestelling_gs1_specialist_email.sql');
