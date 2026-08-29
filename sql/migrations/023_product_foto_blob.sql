-- =====================================================================
-- 023_product_foto_blob.sql
-- Productfoto rechtstreeks in de database, op uitdrukkelijk verzoek van
-- Harm ("deze foto moet gewoon in de database worden opgeslagen") --
-- bewust een ANDER patroon dan de twee bestaande foto-opslag-manieren in
-- deze app:
--   - artikelen.foto_link (bestaand): een externe URL/Google-Drive-link,
--     wordt live opgehaald via cURL bij elke paginaweergave
--     (includes/artikelen.php::getFotoDataUri()), nooit zelf opgeslagen.
--   - users.foto / producent_contacten.foto (migratie 015): bestanden op
--     schijf onder uploads/, alleen het relatieve pad in de database.
-- Voor producten specifiek wil Harm de foto-BYTES zelf in MariaDB, dus
-- een derde patroon: foto_data (de ruwe bytes) + foto_mime (voor de
-- data-URI bij weergave, zie getProductDetail()).
--
-- MEDIUMBLOB (max ~16MB) i.p.v. het kleinere BLOB (64KB, te klein voor
-- een foto) of het grotere LONGBLOB (onnodig ruim voor dit doel) -- ruim
-- voldoende voor een productfoto onder de bestaande 2MB-uploadgrens
-- (FOTO_MAX_BYTES in includes/upload.php, hergebruikt voor dit veld).
--
-- foto_link blijft bestaan als fallback: bestaande producten met alleen
-- een Drive-link blijven gewoon werken, getProductDetail() geeft voorrang
-- aan foto_data zodra die gezet is (zie includes/artikelen.php).
-- =====================================================================

ALTER TABLE artikelen
  ADD COLUMN foto_data MEDIUMBLOB NULL AFTER foto_link,
  ADD COLUMN foto_mime VARCHAR(50) NULL AFTER foto_data;

INSERT INTO schema_migrations (migratie) VALUES ('023_product_foto_blob.sql');
