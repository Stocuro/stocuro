-- =====================================================================
-- 026_taken_split.sql
-- Op verzoek van Harm: het scherm "Tasks" (scherm_key 'taken') bevatte
-- vier soorten taken door elkaar. Onbekende scans en Onvolledige
-- productgegevens zijn databeheer-taken voor een BEHEERDER; Voorraad-
-- telling verschuldigd en Over de vervaldatum zijn vloerwerk-taken voor
-- een LABORANT. includes/schermen.php kent voortaan twee scherm-keys,
-- 'taken-beheerder' en 'taken-laborant', i.p.v. het ene 'taken'.
--
-- Bestaande groepen die toegang hadden tot 'taken' krijgen hier
-- automatisch BEIDE nieuwe schermen (zelfde effectieve toegang als
-- voorheen, geen groep verliest zicht op taken die ze al zagen). Wie de
-- schermen daarna wil scheiden (bijv. een Beheerders-groep alleen
-- 'taken-beheerder' geven), doet dat via het Groepen-scherm.
-- =====================================================================

INSERT IGNORE INTO groep_schermen (groep_id, scherm_key)
SELECT groep_id, 'taken-beheerder' FROM groep_schermen WHERE scherm_key = 'taken';

INSERT IGNORE INTO groep_schermen (groep_id, scherm_key)
SELECT groep_id, 'taken-laborant' FROM groep_schermen WHERE scherm_key = 'taken';

DELETE FROM groep_schermen WHERE scherm_key = 'taken';

INSERT INTO schema_migrations (migratie) VALUES ('026_taken_split.sql');
