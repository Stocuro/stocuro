-- =====================================================================
-- 038_transactielog_wijzigingen_actie_varchar.sql
-- Bugfix, op verzoek van Harm: het lotnummer/vervaldatum corrigeren van
-- een lot op voorraad (Productdetailpagina -> Loten-tabel -> bewerken,
-- includes/lot_correcties.php::corrigeerLot()) crashte met:
--   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'actie'
--   at row 1
--
-- Oorzaak: transactielog_wijzigingen.actie is een ENUM('bewerkt',
-- 'verwijderd') (migratie 006), maar corrigeerLot() (migratie 035, dus
-- LATER toegevoegd) logt met actie = 'lot_gecorrigeerd' -- een waarde
-- die niet in die ENUM voorkomt. In strict SQL-mode wordt dat geen
-- stille lege-string-truncatie maar een harde fout.
--
-- Fix: actie naar VARCHAR(30) i.p.v. de ENUM uit te breiden -- dat
-- voorkomt dat dit exact opnieuw gebeurt zodra er ooit een vierde
-- actietype bijkomt (elke keer een migratie nodig hebben om de ENUM
-- bij te werken is precies het patroon dat deze bug veroorzaakte).
-- Bestaande waarden ('bewerkt'/'verwijderd') blijven ongewijzigd
-- werken -- dit is een zuiver verbredende wijziging, geen dataverlies.
-- =====================================================================

ALTER TABLE transactielog_wijzigingen
  MODIFY COLUMN actie VARCHAR(30) NOT NULL;

INSERT INTO schema_migrations (migratie) VALUES ('038_transactielog_wijzigingen_actie_varchar.sql');
