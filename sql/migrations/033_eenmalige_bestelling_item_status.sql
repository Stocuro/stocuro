-- =====================================================================
-- 033_eenmalige_bestelling_item_status.sql
-- Op verzoek van Harm (tweede feedbackronde na v1.43): de registratie
-- rond een eenmalige (patiëntgebonden) bestelling moet "rond" zijn --
-- niet alleen vastleggen WAT er is binnengekomen (migratie 032), maar
-- ook wat daarna met elk los item gebeurt: blijft het liggen, is het
-- verbruikt bij de patiënt, of gaat het retour omdat het niet gebruikt
-- is?
--
-- status: het lot van dit specifieke item.
--   - 'ontvangen' : standaard bij het scannen op de bewerkpagina (zie
--                   verwerkEenmaligeBestellingGs1Regels() in
--                   includes/eenmalige_bestellingen.php) -- het ligt nu
--                   fysiek op voorraad (zie ontvangst_verwerkt
--                   hieronder), maar is nog niet verbruikt of
--                   geretourneerd.
--   - 'verbruikt'  : een latere Verbruik-scan (gewoon het bestaande
--                    "Materiaal verbruik"-scherm, GEEN apart veld nodig)
--                    matchte op EAN+lot met dit item -- zie
--                    koppelScanAanEenmaligeBestellingItems() in
--                    includes/transacties.php, aangeroepen vanuit
--                    verwerkScan().
--   - 'retour'     : idem, maar dan vanuit het bestaande "Verplaatsing +
--                    Retouren"-scherm (de 'retourneren'-actie), via
--                    dezelfde koppelfunctie, aangeroepen vanuit
--                    retournering().
-- Dit is puur een statusveld op het item zelf -- de ECHTE
-- voorraadmutatie (het daadwerkelijke op- of afboeken) loopt gewoon via
-- de bestaande, onveranderde transactielog-mechanismen van Verbruik/
-- Retournering; dit veld is alleen voor de eenmalige bestelling zelf om
-- te kunnen tonen "wat is hiervan geworden".
--
-- ontvangst_verwerkt: heeft dit item al een ECHTE Ontvangst-transactie
-- gekregen in het normale voorraadsysteem (transactielog, locatie
-- Berging)? Bij een herkend product gebeurt dat meteen bij het scannen
-- zelf. Bij een op dat moment nog ONBEKEND product (nog geen artikel in
-- de database) kan dat nog niet -- dan blijft dit 0 totdat een
-- beheerder alsnog een artikel voor die EAN aanmaakt (Onbekende
-- Producten-scherm), waarna de boeking met terugwerkende kracht gebeurt
-- en dit op 1 gezet wordt. Zie addArtikel()-aanroeppad in
-- api/artikel_toevoegen.php.
--
-- afgehandeld_op/afgehandeld_door: wanneer/door wie de status van
-- 'ontvangen' naar 'verbruikt'/'retour' veranderde (voor weergave op de
-- bewerkpagina) -- vult NIET aangemaakt_op/aangemaakt_door aan, dat
-- blijft het scanmoment van ontvangst zelf.
-- =====================================================================

ALTER TABLE eenmalige_bestelling_items
  ADD COLUMN status ENUM('ontvangen','verbruikt','retour') NOT NULL DEFAULT 'ontvangen' AFTER onbekend,
  ADD COLUMN ontvangst_verwerkt TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN afgehandeld_op DATETIME NULL AFTER ontvangst_verwerkt,
  ADD COLUMN afgehandeld_door VARCHAR(100) NULL AFTER afgehandeld_op,
  ADD INDEX idx_ean_status (ean, status);

INSERT INTO schema_migrations (migratie) VALUES ('033_eenmalige_bestelling_item_status.sql');
