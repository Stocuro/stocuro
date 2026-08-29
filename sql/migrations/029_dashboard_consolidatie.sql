-- =====================================================================
-- 029_dashboard_consolidatie.sql
-- Onderdeel van de grote dashboard-consolidatie (op verzoek van Harm):
-- twee kleine, losstaande schema-aanvullingen die daarbij nodig bleken.
-- Geen nieuwe tabel voor "kastlocatie" (schapplek binnen een kamer) --
-- die blijft bewust vrije tekst op artikelen.locatie_ik/locatie_hybok/
-- locatie_berging/artikel_locatie_extra.kastlocatie, zoals hij al was.
-- De nieuwe dropdown-met-"nieuwe toevoegen" in de UI wordt gevuld met een
-- SELECT DISTINCT over die bestaande kolommen (zie
-- includes/artikelen.php::getKastlocatieSuggesties()) -- geen schema-
-- wijziging nodig om herhaling te voorkomen, alleen een betere UI erover.
-- =====================================================================

-- 1) Vrij notitieveld per contactpersoon (was er al op producent-niveau,
--    ontbrak op het niveau van de contactpersoon zelf).
ALTER TABLE producent_contacten
  ADD COLUMN opmerking TEXT NULL AFTER email;

-- 2) Eerste ontvangstdatum naast de al bestaande laatste ontvangstdatum
--    (migratie 011: laatst_ontvangen_op). Bij een order die in meerdere
--    deelleveringen binnenkomt, werd tot nu toe alleen het laatste
--    ontvangstmoment bewaard -- het eerste moment ging verloren zodra een
--    tweede deellevering binnenkwam. Nullable en alleen éénmalig gezet
--    (zie includes/orders.php::updateOrdersWithFIFO()), zodat een order
--    die nog niks ontvangen heeft NULL blijft, net als laatst_ontvangen_op.
ALTER TABLE orders
  ADD COLUMN eerst_ontvangen_op DATETIME NULL AFTER laatst_ontvangen_op;

INSERT INTO schema_migrations (migratie) VALUES ('029_dashboard_consolidatie.sql');
