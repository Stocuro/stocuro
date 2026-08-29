-- =====================================================================
-- 003_add_prijs_op_moment.sql
-- Legt bij elke transactie de op dat moment geldende artikelprijs vast,
-- los van latere prijswijzigingen in artikelen.prijs.
--
-- Waarom: artikelen.prijs is de actuele catalogusprijs en verandert
-- na verloop van tijd. Berekeningen over de HUIDIGE voorraadwaarde
-- (getVoorraadWaarde, getCategorieOverzicht, etc.) moeten die actuele
-- prijs blijven gebruiken -- dat is correct (vervangingswaarde van wat
-- nu op de plank ligt). Maar rapportages over verbruik in het verleden
-- (kostprijs per procedure/specialist) moeten de prijs gebruiken die
-- gold TOEN het materiaal gebruikt werd, anders verandert de kostprijs
-- van een oude procedure met terugwerkende kracht zodra de catalogus-
-- prijs wijzigt.
--
-- prijs_op_moment wordt gevuld bij het wegschrijven van de transactie
-- (includes/transacties.php) en blijft daarna vastliggen. NULL is
-- toegestaan voor eventuele oudere rijen zonder snapshot -- rapportages
-- vallen in dat geval terug op de actuele artikelen.prijs (COALESCE).
-- =====================================================================

ALTER TABLE transactielog
  ADD COLUMN prijs_op_moment DECIMAL(10,2) NULL AFTER hoeveelheid;

INSERT INTO schema_migrations (migratie) VALUES ('003_add_prijs_op_moment.sql');
