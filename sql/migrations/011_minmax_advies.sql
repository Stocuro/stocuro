-- Min/Max Advies (includes/minmax.php): tijdstip waarop een order voor het
-- laatst een FIFO-ontvangst-toewijzing kreeg (includes/orders.php,
-- updateOrdersWithFIFO()). Los van het al bestaande generieke
-- bijgewerkt_op, want dat veld wordt ook bijgewerkt bij niet-ontvangst-
-- wijzigingen (bijv. meer bestellen op een al openstaande order) en is
-- daarom niet bruikbaar om levertijd (besteld -> ontvangen) uit af te
-- leiden.
ALTER TABLE orders
  ADD COLUMN laatst_ontvangen_op DATETIME NULL AFTER bijgewerkt_op;

INSERT INTO schema_migrations (migratie) VALUES ('011_minmax_advies.sql');
