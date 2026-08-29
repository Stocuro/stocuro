-- Migratie 031, op verzoek van Harm: kamers (Room A/Room B/Cart en elke
-- toegevoegde extra locatie) worden altijd, gegarandeerd, dezelfde dag
-- vanuit Storage (berging) tot hun maximum bijgevuld -- een apart
-- "minimum"-reorderpunt heeft daar dus nooit een eigen functie gehad. Dat
-- reorderpunt is alleen zinvol op de ENE locatie die daadwerkelijk op een
-- externe leverancier wacht: Storage/berging (zie includes/minmax.php voor
-- de volledige uitleg van de twee formules).
--
-- Kamers gaan daarom over op een simpel "par"-model: alleen nog een
-- maximum (doelwaarde/capaciteit) per artikel x locatie, geen minimum meer.
-- Storage/berging behoudt zijn min/max-bestelsysteem ongewijzigd.
--
-- Dit verwijdert de drie kamer-minimum-kolommen op `artikelen` (min_berging
-- blijft staan) en het minimum-veld voor extra locaties (migratie 007),
-- die zich altijd als een kamer gedragen (nooit als aanvulbron), dus
-- hetzelfde argument geldt daar.

ALTER TABLE artikelen
  DROP COLUMN min_ik,
  DROP COLUMN min_hybok,
  DROP COLUMN min_kar;

ALTER TABLE artikel_locatie_extra
  DROP COLUMN min_aantal;

INSERT INTO schema_migrations (migratie) VALUES ('031_kamer_min_opschonen.sql');
