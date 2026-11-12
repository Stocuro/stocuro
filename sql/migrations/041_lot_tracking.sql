-- =====================================================================
-- 041_lot_tracking.sql
-- Gemeld praktijkprobleem tijdens de pilot: sommige producten hebben
-- naast de GS1-combinatiebarcode (GTIN+lot+vervaldatum) nog een losse,
-- "armere" barcode zonder die gegevens (bv. een kale EAN). Wordt die per
-- ongeluk gescand -- bij Ontvangst of bij Verbruik -- dan werd een scan
-- zonder lot voorheen altijd stilzwijgend geboekt, ook als dit product
-- normaal wel een lotnummer heeft. Voor een lotgebonden product (vooral
-- implantaten) is dat een traceerbaarheidsprobleem: je weet dan niet meer
-- uit welk lot een stuk kwam.
--
-- Een scan zonder lot is echter niet altijd fout: sommige producten
-- hebben simpelweg nooit een lotnummer/vervaldatum op de verpakking. Het
-- systeem moet dus per product weten of dat normaal is of niet --
-- vandaar deze kolom. Drie waarden:
--   'automatisch' (standaard) -- geen handmatige keuze; includes/
--                 voorraad.php::isLotVerwacht() leidt dit af uit de
--                 scangeschiedenis (heeft dit EAN al eens een scan MET
--                 lot opgeleverd?).
--   'met_lot'    -- handmatig bevestigd: dit product heeft altijd een
--                 lot/vervaldatum, een scan zonder lot is dus altijd
--                 verdacht, ook bij de allereerste scan.
--   'zonder_lot' -- handmatig bevestigd: dit product heeft nooit een
--                 lot/vervaldatum (geen batchcode op de verpakking),
--                 een scan zonder lot is dus nooit verdacht. Zie
--                 includes/artikelen.php::addArtikel() (het "dit is de
--                 correcte barcode, geen verdere informatie"-vinkje bij
--                 het aanmaken van een nieuw product) en
--                 includes/transacties.php::maakArtikelenVoorOnbekendeRegels()
--                 (hetzelfde vinkje bij een onbekende-scan-omschrijving).
--
-- Zie includes/transacties.php::verwerkScan() voor waar dit tegen de
-- scan bij Ontvangst/Verbruik gecontroleerd wordt.
-- =====================================================================

ALTER TABLE artikelen
  ADD COLUMN lot_tracking ENUM('automatisch','met_lot','zonder_lot') NOT NULL DEFAULT 'automatisch' AFTER status;

INSERT INTO schema_migrations (migratie) VALUES ('041_lot_tracking.sql');
