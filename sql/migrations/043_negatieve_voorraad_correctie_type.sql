-- =====================================================================
-- 043_negatieve_voorraad_correctie_type.sql
-- Gemeld praktijkprobleem: voorkomNegatieveVoorraad() (includes/
-- transacties.php) boekte zijn automatische correctie (bij een telfout/
-- gemiste scan/onbekende handmatige bijvulling, zonder lot -- er is
-- immers niet bekend WELK lot de afwijking veroorzaakte) tot nu toe als
-- 'Telling: overschot', hetzelfde transactietype als een ECHTE,
-- gebruiker-geïnitieerde kastcontrole-afwijking (includes/telling.php::
-- verwerkTelling()). Dat was bewust zo gekozen (zie de oude toelichting
-- bij voorkomNegatieveVoorraad()) om geen enkele andere plek in de app
-- te moeten aanpassen, maar bleek een echt probleem: beide typen
-- correcties kunnen allebei lot=NULL hebben (een lotloos product
-- tellen is ook een geldige, echte 'Telling: overschot' met lot=NULL),
-- dus is er GEEN manier om de twee achteraf van elkaar te onderscheiden
-- op (transactietype, lot) alleen. Resultaat: de automatische
-- correctie verscheen als een nep "lot" in Loten op Voorraad/
-- Vervaldatums & Lotbeheer/Vervaldatum Focus (voorraadLot(), includes/
-- voorraad.php) en in de "Ontbrekende Batch"-takenlijst
-- (getOntbrekendeBatchTaken(), includes/taken.php) -- een foutmelding
-- die niemand kan "oplossen" door opnieuw te scannen, want er is geen
-- fysiek lot dat ontbreekt, puur een boekhoudkundige correctie.
--
-- Eigen transactietype voor voorkomNegatieveVoorraad() lost dit op: de
-- correctie telt nog steeds mee in het EAN+locatie-totaal
-- (TRANSACTIETYPES_INKOMEND in config/constants.php), maar
-- voorraadLot()/getOntbrekendeBatchTaken() sluiten dit specifieke type
-- nu expliciet uit van hun per-lot-weergave.
-- =====================================================================

ALTER TABLE transactielog
  MODIFY COLUMN transactietype ENUM(
    'Ontvangst','Verbruik','Verplaatsing in','Verplaatsing uit','Retournering',
    'Telling: overschot','Telling: tekort','Vervallen','Correctie: negatieve voorraad'
  ) NOT NULL;

INSERT INTO schema_migrations (migratie) VALUES ('043_negatieve_voorraad_correctie_type.sql');
