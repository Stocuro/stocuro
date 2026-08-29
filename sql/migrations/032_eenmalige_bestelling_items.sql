-- Migratie 032, op verzoek van Harm: eenmalige_bestellingen had tot nu
-- toe vlakke ean/lot/vervaldatum-kolommen, ontworpen voor ÉÉN product per
-- aanvraag. In de praktijk kan een eenmalige (patiëntgebonden) bestelling
-- prima uit meerdere VERSCHILLENDE producten bestaan (bijv. meerdere
-- stents in verschillende maten/lots) -- gs1VoorEenmaligeBestelling()
-- (includes/eenmalige_bestellingen.php) verzamelde die tot nu toe tot ÉÉN
-- rij (eerste EAN, kommagescheiden lots, vroegste vervaldatum), wat de
-- gegevens van verschillende producten door elkaar liet lopen.
--
-- Deze tabel bewaart voortaan elke gescande GS1-regel als eigen rij (EAN,
-- product indien herkend, lot, vervaldatum) -- exact zoals transactielog
-- dat al doet voor gewone scans. 'onbekend' markeert een regel waarvan de
-- EAN geen bestaand artikel matchte op het moment van scannen (dezelfde
-- twee-fase-aanpak als Ontvangst/Verbruik/Verplaatsing/Tellen: zo'n regel
-- wordt pas opgeslagen zodra de gebruiker er een korte omschrijving bij
-- heeft gegeven, en komt ALTIJD ook in onbekende_scans terecht zodat de
-- beheerder er via het bestaande "Onbekende Producten"-scherm een nieuw
-- artikel van kan maken -- zie includes/transacties.php::
-- splitsBekendOnbekend()/loggeOnbekendeScans(), hier hergebruikt in
-- plaats van opnieuw uitgevonden).
--
-- De vlakke ean/lot/vervaldatum/hoeveelheid-kolommen op
-- eenmalige_bestellingen zelf blijven bestaan als samenvatting (eerste
-- bekende EAN, alle lots kommagescheiden, vroegste vervaldatum, aantal =
-- totaal aantal regels) -- bestaande weergaven (Bestellingen-tabel,
-- mailto-knop) blijven daardoor gewoon werken; de nieuwe itemlijst is
-- alleen op de bewerkpagina zelf te zien.
CREATE TABLE IF NOT EXISTS eenmalige_bestelling_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  eenmalige_bestelling_id INT NOT NULL,
  ean CHAR(14) NOT NULL,
  productnaam VARCHAR(255) NULL,
  lot VARCHAR(255) NULL,
  vervaldatum DATE NULL,
  onbekend TINYINT(1) NOT NULL DEFAULT 0,
  gs1_raw TEXT NULL,
  aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aangemaakt_door VARCHAR(100) NULL,
  INDEX idx_bestelling (eenmalige_bestelling_id),
  CONSTRAINT fk_ebi_bestelling FOREIGN KEY (eenmalige_bestelling_id)
    REFERENCES eenmalige_bestellingen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('032_eenmalige_bestelling_items.sql');
