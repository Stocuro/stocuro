-- =====================================================================
-- 013_eenmalige_bestelling_retourneren.sql
-- Voegt de status 'Retourneren' toe aan eenmalige_bestellingen (op
-- verzoek van Harm: eenmalige bestellingen moeten net als reguliere
-- orders zichtbaar en beheerbaar zijn vanuit Open Orders, met een manier
-- om aan te geven dat iets teruggestuurd is i.p.v. gebruikt).
--
-- 'Gebruikt' bestond al (item is in de procedure gebruikt -- afgerond,
-- succesvol); 'Retourneren' is het spiegelbeeld daarvan (item is NIET
-- gebruikt en gaat terug -- ook afgerond, maar niet succesvol
-- "verbruikt"). Beide zijn een eindstatus: zodra een eenmalige bestelling
-- Gebruikt of Retourneren is, verdwijnt hij uit het Open
-- Orders-overzicht (zie includes/eenmalige_bestellingen.php::
-- getOpenEenmaligeBestellingen()) -- exact dezelfde regel als "Volledig
-- ontvangen" die een reguliere order al uit dat overzicht laat
-- verdwijnen (includes/orders.php::openOrderStatusMap()).
-- =====================================================================

ALTER TABLE eenmalige_bestellingen
  MODIFY COLUMN status ENUM('Aangevraagd','Besteld','Ontvangen','Gebruikt','Retourneren') NOT NULL DEFAULT 'Aangevraagd';

INSERT INTO schema_migrations (migratie) VALUES ('013_eenmalige_bestelling_retourneren.sql');
