-- =====================================================================
-- 027_minmax_advies_genegeerd.sql
-- Op verzoek van Harm: het Min/Max Advies-overzicht moet beheersbaar
-- blijven. Twee losse verbeteringen; deze migratie hoort bij de tweede:
--   1. Rijen waar het advies exact gelijk is aan de huidige min/max
--      (niets te wijzigen) worden niet meer getoond -- dat is puur
--      filterlogica in includes/minmax.php, geen schema-wijziging nodig.
--   2. Een voorstel kunnen NEGEREN: verdwijnt dan van dit overzicht tot
--      MINMAX_NEGEER_MAANDEN (config/constants.php, 3 maanden) later --
--      daarna wordt het, als het dan nog steeds relevant is, opnieuw
--      aangeboden. Dat vereist wel een plek om "welk (ean, locatie) is
--      wanneer genegeerd" te onthouden -- vandaar deze tabel.
--
-- Bewust GEEN nieuwe kolom op artikelen (dat zou per artikel maar één
-- negeer-moment toestaan, terwijl min/max-advies per LOCATIE verschilt
-- -- eenzelfde artikel kan op de ene locatie een genegeerd voorstel
-- hebben en op de andere niet).
--
-- De productdetailpagina (getProductDetail(), includes/artikelen.php)
-- respecteert deze tabel NIET -- daar blijft een voorstel altijd
-- zichtbaar, genegeerd of niet, zie includes/minmax.php.
-- =====================================================================

CREATE TABLE minmax_advies_genegeerd (
  ean CHAR(14) NOT NULL,
  -- Migratie 007: extra locaties zijn vrije tekst tot 100 tekens (langer
  -- dan de vaste 30-tekens-slotnamen), dus VARCHAR(100) om beide te dekken.
  locatie VARCHAR(100) NOT NULL,
  genegeerd_op DATETIME NOT NULL,
  PRIMARY KEY (ean, locatie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migratie) VALUES ('027_minmax_advies_genegeerd.sql');
