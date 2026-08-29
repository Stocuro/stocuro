-- Migratie 037, op verzoek van Harm: licentiebeveiliging verstevigen,
-- naar aanleiding van live testen op zijn eigen demo-omgeving --
-- users.licentie_vrij bleek met een simpele UPDATE-query op onbeperkt
-- veel accounts te zetten (geen enkele cryptografische check op die kale
-- boolean). Zie includes/license.php voor de volledige nieuwe logica.
--
-- type: welk soort token een license_activations-rij vertegenwoordigt.
--   'extra_user'  -- een echte, door de leverancier (Ed25519) ondertekende,
--                    gekochte activatiecode (ongewijzigd t.o.v. migratie 036).
--   'vrij_lokaal' -- het EEN-per-installatie gratis token, lokaal door
--                    install.php aangemaakt en HMAC-ondertekend met het
--                    per-installatie geheim (config/instance.local.php).
--                    Vervangt de kale users.licentie_vrij-boolean als bron
--                    van waarheid; die kolom blijft bestaan als
--                    leesbare cache (net als licentie_geblokkeerd altijd al
--                    was) maar wordt niet meer blind vertrouwd.
-- DEFAULT 'extra_user': alle rijen die vóór deze migratie al bestonden,
-- zijn per definitie echte gekochte codes (het lokale token-mechanisme
-- bestond nog niet), dus dat is de correcte terugwerkende waarde.
ALTER TABLE license_activations
  ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'extra_user' AFTER token_id;

-- Snelle lookup van "wie heeft momenteel een vrij_lokaal-kandidaat"
-- zonder elke rij te hoeven decoderen/verifiëren -- includes/license.php
-- gebruikt dit om alleen de rij met het laagste gebonden_gebruiker_id als
-- kandidaat te behandelen (de "maximaal 1 gratis gebruiker"-regel), en
-- verifieert dan alleen DIE ENE rij cryptografisch.
CREATE INDEX idx_license_activations_type ON license_activations (type, gebonden_gebruiker_id);

INSERT INTO schema_migrations (migratie) VALUES ('037_licentie_hardening.sql');
