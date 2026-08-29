-- =====================================================================
-- 034_besteltype_eenmalig.sql
-- Op verzoek van Harm: artikelen.besteltype ('Automatisch'/'Handmatig',
-- migratie 001) krijgt een derde waarde 'Eenmalig' -- voor producten die
-- puur bestaan omdat ze ooit gescand/aangemaakt zijn binnen een
-- Eenmalige Bestelling (patiëntgebonden materiaalaanvraag), niet omdat
-- ze een normaal, doorlopend voorraadartikel zijn.
--
-- Waarom een DERDE waarde i.p.v. gewoon 'Handmatig' hergebruiken: de
-- twee bestaande filters op besteltype (getBergingData()/
-- getBoodschappenData() in includes/voorraad.php: alleen 'Automatisch';
-- getBestellersData(): alleen 'Handmatig') zouden een Eenmalig-product
-- anders alsnog op de "Te bestellen (Handmatig)"-lijst laten verschijnen
-- -- terwijl zo'n product juist NOOIT een doorlopende bestelbehoefte
-- heeft (het was een eenmalige aanvraag voor één patiënt). Met een eigen
-- 'Eenmalig'-waarde valt zo'n product vanzelf buiten BEIDE
-- str_contains()-filters (die letterlijk op 'Automatisch' resp.
-- 'Handmatig' matchen), zonder dat voorraad.php zelf hoeft te
-- veranderen.
--
-- Wie zet 'm op 'Eenmalig': verwerkAchterstalligeEenmaligeBestellingOntvangsten()
-- in includes/eenmalige_bestellingen.php, forceert dit ALTIJD (ongeacht
-- wat er in het aanmaakformulier is gekozen) zodra blijkt dat het nieuw
-- aangemaakte artikel openstaande eenmalige_bestelling_items had wachten
-- -- d.w.z. dit artikel bestaat specifiek omdat het ooit binnen een
-- Eenmalige Bestelling is gescand. Een gewoon, gepland artikel dat
-- toevallig ook een keer voor een eenmalige bestelling gebruikt wordt
-- (het product bestond al) wordt hier NIET door geraakt -- dat blijft
-- gewoon Automatisch/Handmatig, precies zoals het al was.
-- =====================================================================

ALTER TABLE artikelen
  MODIFY COLUMN besteltype ENUM('Automatisch','Handmatig','Eenmalig') NOT NULL DEFAULT 'Automatisch';

INSERT INTO schema_migrations (migratie) VALUES ('034_besteltype_eenmalig.sql');
