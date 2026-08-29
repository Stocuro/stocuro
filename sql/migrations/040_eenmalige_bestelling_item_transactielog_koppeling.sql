-- Migratie 040, op verzoek van Harm ("Recent Transactions... dit moet wel
-- de juiste effecten uitvoeren in bijvoorbeeld de voorraden. Gaat dit wel
-- goed?" -> "ja graag, alles moet wel kloppen natuurlijk").
--
-- Bewerken/verwijderen van een transactie via Recente Transacties liet de
-- voorraad zelf altijd correct herstellen (die wordt live herberekend uit
-- transactielog), maar niet de koppeling met een eenmalige bestelling: als
-- een Verbruik- of Retour-scan eerder automatisch een item op die
-- bestelling op 'verbruikt'/'retour' had gezet (zie
-- koppelScanAanEenmaligeBestellingItems() in includes/transacties.php),
-- bleef dat zo staan, ook nadat de onderliggende transactie verwijderd
-- was.
--
-- Deze kolom legt vast WELKE transactielog-regel die koppeling heeft
-- gemaakt, zodat verwijderTransactieRegel() (includes/transacties.php) 'm
-- kan terugdraaien. ON DELETE SET NULL is hier bewust WEL een echte FK
-- (anders dan de losse ean/naam-koppelingen elders in dit schema): dit
-- moet een exacte, niet-ambigue verwijzing naar precies één transactielog-
-- rij zijn (nooit een vrije tekstmatch), en als die rij ooit via een ander
-- pad dan verwijderTransactieRegel() verdwijnt, moet de kolom in elk geval
-- niet blijven verwijzen naar een niet-bestaande rij.
ALTER TABLE eenmalige_bestelling_items
    ADD COLUMN transactielog_id INT NULL AFTER status,
    ADD INDEX idx_ebi_transactielog (transactielog_id),
    ADD CONSTRAINT fk_ebi_transactielog FOREIGN KEY (transactielog_id)
        REFERENCES transactielog(id) ON DELETE SET NULL;

INSERT INTO schema_migrations (migratie) VALUES ('040_eenmalige_bestelling_item_transactielog_koppeling.sql');
