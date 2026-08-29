#!/usr/bin/env python3
"""
genereer_testdata.py

Genereert dezelfde soort testdata als migratie/genereer_testdata.php
(twintig fictieve artikelen, specialisten, procedure typen, en een jaar
gesimuleerde scans/orders/kastcontroles/afboekingen), maar als een
statisch .sql-bestand -- voor wie GEEN SSH/command-line-toegang tot de
server heeft en alleen bij een database-beheeromgeving (phpMyAdmin of
vergelijkbaar) kan. Draai dit lokaal op je eigen computer (net als
export_from_excel.py), upload/plak niets naar de server; het resultaat is
gewoon een .sql-bestand dat je via "Importeren" in phpMyAdmin laadt --
exact dezelfde workflow als migratie/voorbeelddata.sql en
migratie/artikelen_import.sql.

Gebruik:
    python3 migratie/genereer_testdata.py
    python3 migratie/genereer_testdata.py --dagen 180 --seed 1234
    python3 migratie/genereer_testdata.py --output migratie/testdata_gegenereerd.sql

Draai daarna eerst (optioneel maar aangeraden) migratie/leegmaken.sql via
phpMyAdmin's SQL-tab om botsingen te voorkomen, en importeer vervolgens
het hier gegenereerde .sql-bestand.

Bevat standaard ook vier demo-gebruikersaccounts (tabel `users`, wachtwoord
"Stocuro2026!" voor alle vier -- zie DEMO_GEBRUIKERS hieronder) zodat je
meteen kunt inloggen als specialist/laborant/beheerder/bekijker om de app
te laten zien. De wachtwoord-hash is met Python's bcrypt gegenereerd; PHP's
password_verify() herkent $2a$/$2b$/$2y$ door elkaar, dus dat werkt gewoon.
Heb je al eigen accounts (of botsen de demo-emailadressen met bestaande),
draai dan met --geen-gebruikers om dit blok over te slaan. De meeste
overige fictieve namen in dit bestand worden verder alleen als platte
tekst gebruikt voor "wie deed dit" (aangemaakt_door/besteller), precies
zoals de rest van deze app dat al doet (zie migratie 010: een
snapshot-naam, geen foreign key naar users.id).

Simulatiemodel: zie de uitgebreide toelichting in
migratie/genereer_testdata.php (de PHP-variant) -- dezelfde logica,
1-op-1 overgezet naar Python: FEFO-lotverbruik, 'berging' als centrale
aanvulbron die de andere locaties bijvult, orders met een realistische
levertijd (voor Min/Max Advies se levertijdgemiddelde), periodieke
kastcontrole, en vervalafboekingen.
"""

import argparse
import random
from datetime import date, timedelta

# ---------------------------------------------------------------------
# Stamdata
# ---------------------------------------------------------------------

GEBRUIKER_NAMEN = ['Anna de Vries', 'Bram Jansen', 'Chantal Bakker', 'Dennis Visser', 'Eva Smit']

SPECIALISTEN = [
    ('Dr. F. van Dijk', 'arts'),
    ('Dr. G. Mulder', 'arts'),
    ('Dr. H. de Boer', 'arts'),
    ('I. Peters (laborant)', 'laborant'),
    ('J. Hendriks (laborant)', 'laborant'),
    ('K. Willems', 'overig'),
]

PROCEDURE_TYPEN = ['PICC Line', 'Angiography', 'Embolization', 'Stent Placement', 'Biopsy']

# min/max volgorde: [ik, hybok, kar, berging]
ARTIKELEN = [
    dict(ean='00000000000201', naam='Demo Stent 6mm', cat='Stent', besteltype='Automatisch', min=[2, 1, 1, 5], max=[6, 4, 3, 15], producent='Demo Supplier Co', prijs=245.00, houdbaarheid=24, verbruik='gemiddeld'),
    dict(ean='00000000000202', naam='Demo Stent 8mm', cat='Stent', besteltype='Automatisch', min=[1, 1, 0, 4], max=[4, 3, 2, 10], producent='Demo Supplier Co', prijs=268.00, houdbaarheid=24, verbruik='laag'),
    dict(ean='00000000000203', naam='Demo Guidewire 0.035"', cat='Voerdraad', besteltype='Automatisch', min=[3, 2, 2, 8], max=[8, 5, 4, 20], producent='Demo Supplier Co', prijs=38.50, houdbaarheid=36, verbruik='hoog'),
    dict(ean='00000000000204', naam='Demo Guidewire 0.018"', cat='Voerdraad', besteltype='Automatisch', min=[2, 1, 1, 6], max=[6, 4, 3, 15], producent='Demo Supplier Co', prijs=34.00, houdbaarheid=36, verbruik='gemiddeld'),
    dict(ean='00000000000205', naam='Demo Embolisation Coil Small', cat='Embolisatie', besteltype='Handmatig', min=[1, 1, 0, 3], max=[3, 2, 0, 8], producent='Sample Medical BV', prijs=189.00, houdbaarheid=30, verbruik='laag'),
    dict(ean='00000000000206', naam='Demo Embolisation Coil Large', cat='Embolisatie', besteltype='Handmatig', min=[1, 0, 0, 2], max=[2, 1, 0, 5], producent='Sample Medical BV', prijs=210.00, houdbaarheid=30, verbruik='laag'),
    dict(ean='00000000000207', naam='Demo Sheath 6F', cat='Sheath', besteltype='Automatisch', min=[4, 2, 3, 10], max=[10, 6, 6, 25], producent='Demo Supplier Co', prijs=22.75, houdbaarheid=24, verbruik='hoog'),
    dict(ean='00000000000208', naam='Demo Sheath 8F', cat='Sheath', besteltype='Automatisch', min=[3, 2, 2, 8], max=[8, 5, 4, 18], producent='Demo Supplier Co', prijs=26.50, houdbaarheid=24, verbruik='gemiddeld'),
    dict(ean='00000000000209', naam='Demo Balloon Catheter 4mm', cat='Ballon', besteltype='Automatisch', min=[2, 1, 1, 4], max=[5, 3, 2, 10], producent='Sample Medical BV', prijs=156.00, houdbaarheid=24, verbruik='gemiddeld'),
    dict(ean='00000000000210', naam='Demo Balloon Catheter 6mm', cat='Ballon', besteltype='Automatisch', min=[2, 1, 1, 3], max=[4, 3, 2, 8], producent='Sample Medical BV', prijs=168.00, houdbaarheid=24, verbruik='laag'),
    dict(ean='00000000000211', naam='Demo Diagnostic Catheter', cat='Catheter', besteltype='Automatisch', min=[3, 2, 2, 6], max=[7, 4, 4, 15], producent='Demo Supplier Co', prijs=64.25, houdbaarheid=30, verbruik='gemiddeld'),
    dict(ean='00000000000212', naam='Demo Closure Device', cat='Catheter', besteltype='Handmatig', min=[1, 0, 0, 2], max=[2, 1, 0, 5], producent='Sample Medical BV', prijs=320.00, houdbaarheid=24, verbruik='laag'),
    dict(ean='00000000000213', naam='Demo Safety Gloves L', cat='Overig', besteltype='Automatisch', min=[5, 5, 5, 20], max=[20, 20, 15, 50], producent='Generic Supplies Inc', prijs=0.45, houdbaarheid=60, verbruik='hoog'),
    dict(ean='00000000000214', naam='Demo Filter Cartridge', cat='Overig', besteltype='Automatisch', min=[2, 1, 1, 5], max=[6, 3, 3, 12], producent='Generic Supplies Inc', prijs=18.90, houdbaarheid=18, verbruik='gemiddeld'),
    dict(ean='00000000000215', naam='Demo Contrast Syringe', cat='Overig', besteltype='Automatisch', min=[4, 3, 2, 10], max=[12, 8, 6, 25], producent='Sample Medical BV', prijs=7.20, houdbaarheid=24, verbruik='hoog'),
    dict(ean='00000000000216', naam='Demo Introducer Sheath 5F', cat='Sheath', besteltype='Automatisch', min=[3, 2, 2, 7], max=[7, 5, 4, 16], producent='Demo Supplier Co', prijs=19.90, houdbaarheid=24, verbruik='gemiddeld'),
    dict(ean='00000000000217', naam='Demo PTA Balloon 5mm', cat='Ballon', besteltype='Automatisch', min=[2, 1, 1, 3], max=[4, 3, 2, 8], producent='Sample Medical BV', prijs=172.00, houdbaarheid=24, verbruik='laag'),
    dict(ean='00000000000218', naam='Demo Micro Catheter', cat='Catheter', besteltype='Handmatig', min=[1, 0, 0, 2], max=[3, 2, 0, 6], producent='Sample Medical BV', prijs=285.00, houdbaarheid=30, verbruik='laag'),
    dict(ean='00000000000219', naam='Demo Hemostatic Valve', cat='Overig', besteltype='Automatisch', min=[2, 1, 1, 5], max=[5, 3, 3, 10], producent='Generic Supplies Inc', prijs=12.40, houdbaarheid=36, verbruik='gemiddeld'),
    dict(ean='00000000000220', naam='Demo Contrast Agent 100ml', cat='Overig', besteltype='Automatisch', min=[3, 2, 2, 8], max=[9, 6, 5, 20], producent='Generic Supplies Inc', prijs=15.60, houdbaarheid=18, verbruik='hoog'),
    # Eenmalig-besteltype demo-item: dit besteltype ontstaat normaal alleen
    # automatisch wanneer iemand via een Eenmalige Bestelling een nog
    # onbekend artikel scant/aanmaakt -- hier bewust ook in de testdata
    # opgenomen (met een bijpassende 'Gebruikt'-eenmalige_bestelling
    # hieronder) zodat dit onderdeel van de app ook iets te laten zien
    # heeft in de demo, i.p.v. dat besteltype='Eenmalig' nergens voorkomt.
    dict(ean='00000000000221', naam='Demo Custom Implant XL', cat='Overig', besteltype='Eenmalig', min=[0, 0, 0, 0], max=[0, 0, 0, 2], producent='Sample Medical BV', prijs=845.00, houdbaarheid=24, verbruik='laag'),
]

# Producenten + contactpersonen -- staan los van ARTIKELEN maar de namen
# moeten exact overeenkomen met de 'producent'-waarden hierboven, anders
# linkt includes/producenten.php de artikelen niet aan deze rijen.
PRODUCENTEN = [
    dict(naam='Demo Supplier Co', website='https://www.demosupplier.example', telefoon='+31 20 123 4567', email='orders@demosupplier.example',
         opmerking='Vaste leverancier voor stents, voerdraden en sheaths (fictief, testdata).',
         contacten=[
             ('Marieke Lammers', 'Accountmanager', '+31 6 1111 2222', 'marieke.lammers@demosupplier.example'),
             ('Tom Kramer', 'Klantenservice', '+31 20 123 4568', 'support@demosupplier.example'),
         ]),
    dict(naam='Sample Medical BV', website='https://www.samplemedical.example', telefoon='+31 30 234 5678', email='info@samplemedical.example',
         opmerking='Leverancier voor embolisatie-, ballon- en catheterproducten (fictief, testdata).',
         contacten=[
             ('Sanne de Groot', 'Accountmanager', '+31 6 2222 3333', 'sanne.degroot@samplemedical.example'),
         ]),
    dict(naam='Generic Supplies Inc', website='https://www.genericsupplies.example', telefoon='+31 10 345 6789', email='sales@genericsupplies.example',
         opmerking='Algemene verbruiksartikelen (fictief, testdata).',
         contacten=[
             ('Rob Verhoeven', 'Sales', '+31 6 3333 4444', 'rob.verhoeven@genericsupplies.example'),
         ]),
]

# Optionele demo-gebruikersaccounts (zie toelichting bovenaan dit bestand
# en --geen-gebruikers). Wachtwoord voor alle vier: Stocuro2026!
# Hashes zijn met Python's bcrypt gegenereerd (rounds=10, $2b$-prefix) --
# PHP's password_verify() herkent $2a$/$2b$/$2y$ door elkaar, dus deze
# hashes werken gewoon voor een echte login, exact als een door PHP zelf
# aangemaakte hash.
DEMO_WACHTWOORD = 'Stocuro2026!'
DEMO_GEBRUIKERS = [
    dict(naam='Anna de Vries', email='anna.devries@demo.stocuro.test', rol='laborant', is_beheerder=1, taal='nl'),
    dict(naam='Dr. F. van Dijk', email='f.vandijk@demo.stocuro.test', rol='specialist', is_beheerder=0, taal='nl'),
    dict(naam='I. Peters', email='i.peters@demo.stocuro.test', rol='laborant', is_beheerder=0, taal='nl'),
    dict(naam='Eva Smit', email='eva.smit@demo.stocuro.test', rol='bekijker', is_beheerder=0, taal='en'),
]
# Vaste, vooraf gegenereerde bcrypt-hash van DEMO_WACHTWOORD (zodat dit
# script geen afhankelijkheid heeft op het Python-pakket bcrypt om te
# draaien -- niet iedereen die dit script gebruikt heeft dat geinstalleerd).
DEMO_WACHTWOORD_HASH = '$2b$10$o7xm2tWV6.9I3HUu3IkItezTJcdq5LB/nxIHTNlMiylmh4SUysrcS'

SLOT_INDEX = {'ik': 0, 'hybok': 1, 'kar': 2, 'berging': 3}
VERBRUIK_LOCATIES = ['ik', 'hybok', 'kar']
KANALEN = ['Webshop', 'Telefonisch', 'Fax', 'E-mail leverancier']
DAGELIJKSE_KANS = {'hoog': 0.35, 'gemiddeld': 0.15, 'laag': 0.05}

# Standaard locatienamen, zoals migratie 005 (sql/migrations/005_locatie_instellingen.sql)
# ze bij een verse installatie aanmaakt. LET OP: als je de vier vaste
# slots in Admin -> Locations al hebt hernoemd, moet je ofwel --locaties
# meegeven bij het genereren, ofwel de locatienamen in het resulterende
# .sql-bestand met zoek-en-vervang aanpassen VOORDAT je het importeert --
# anders komt alle gegenereerde voorraad onder namen te staan die nergens
# mee overeenkomen en verschijnt er niets in de voorraadschermen.
#
# (Een eerdere versie van dit script gebruikte MySQL-sessievariabelen om
# de namen dynamisch op te zoeken in plaats van dit te vragen -- werkt
# prima in de SQL-tab, maar phpMyAdmin's Import-tab knipt grote bestanden
# soms op in meerdere stukken, waarbij sessievariabelen tussendoor hun
# waarde verliezen. Letterlijke tekst hieronder is trager om aan te
# passen maar kan nooit stilzwijgend leeglopen.)
STANDAARD_LOCATIENAMEN = {'ik': 'Room A', 'hybok': 'Room B', 'kar': 'Cart', 'berging': 'Storage'}


def sql_str(value):
    """None -> SQL NULL; anders een correct gequote/geescapete string-literal."""
    if value is None:
        return 'NULL'
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def sql_num(value):
    return 'NULL' if value is None else str(value)


def sql_loc(slot, locatienamen):
    """Locatie als letterlijke, gequote string -- zie STANDAARD_LOCATIENAMEN hierboven."""
    return sql_str(locatienamen[slot])


class Batch:
    __slots__ = ('lot', 'vervaldatum', 'resterend')

    def __init__(self, lot, vervaldatum, resterend):
        self.lot = lot
        self.vervaldatum = vervaldatum
        self.resterend = resterend


def voorraad(batches, ean, slot):
    return sum(b.resterend for b in batches.get((ean, slot), []))


def verbruik(batches, ean, slot, aantal):
    """Consumeert tot `aantal` stuks uit de oudste batch (FEFO). Geeft mogelijk minder terug."""
    key = (ean, slot)
    lijst = batches.get(key)
    if not lijst:
        return 0, None, None
    front = lijst[0]
    te_nemen = min(aantal, front.resterend)
    if te_nemen <= 0:
        return 0, None, None
    front.resterend -= te_nemen
    lot, vervaldatum = front.lot, front.vervaldatum
    if front.resterend <= 0:
        lijst.pop(0)
    return te_nemen, lot, vervaldatum


def levertijd_dagen(rng):
    basis = rng.randint(3, 14)
    if rng.randint(1, 100) <= 15:
        basis += rng.randint(5, 11)
    return basis


def tijd(datum, van_uur=8, tot_uur=17, rng=random):
    return '%s %02d:%02d:00' % (datum.isoformat(), rng.randint(van_uur, tot_uur), rng.randint(0, 59))


def genereer(aantal_dagen: int, seed: int | None, locatienamen: dict, met_gebruikers: bool = True) -> str:
    rng = random.Random(seed)

    out = []
    out.append('-- =====================================================================')
    out.append('-- testdata_gegenereerd.sql -- automatisch gegenereerd door')
    out.append('-- migratie/genereer_testdata.py, NIET met de hand bewerken.')
    out.append('--')
    out.append(f'-- Simuleerde periode: {aantal_dagen} dagen. Seed: {seed if seed is not None else "(willekeurig, elke run anders)"}.')
    out.append('--')
    out.append('-- BELANGRIJK: de vier locatienamen hieronder zijn als LETTERLIJKE tekst')
    out.append('-- in dit bestand gebakken (geen dynamische opzoeking meer -- een eerdere')
    out.append('-- versie gebruikte SQL-sessievariabelen, maar phpMyAdmin knipt grote')
    out.append('-- imports soms op in stukken waarbij die hun waarde verliezen, met NULL-')
    out.append('-- locaties tot gevolg -- alle voorraadschermen leeg). Als je de vier')
    out.append('-- vaste locatie-slots in Admin -> Locations al had hernoemd VOORDAT je')
    out.append('-- dit bestand liet genereren, had je genereer_testdata.py met')
    out.append('-- --locaties je-eigen-namen moeten aanroepen -- zie het script zelf.')
    out.append('-- Gebruikte namen in dit bestand:')
    for slot in ('ik', 'hybok', 'kar', 'berging'):
        out.append(f"--   {slot}: {locatienamen[slot]}")
    out.append('--')
    out.append('-- Draai dit NA de migraties, bij voorkeur op een lege database')
    out.append('-- (zie migratie/leegmaken.sql) om botsingen met de EAN-reeks')
    out.append('-- 00000000000201 t/m 221 te voorkomen.')
    if met_gebruikers:
        out.append('--')
        out.append('-- Bevat 4 demo-inlogaccounts (wachtwoord: ' + DEMO_WACHTWOORD + ' voor allemaal):')
        for g in DEMO_GEBRUIKERS:
            out.append(f"--   {g['email']}  ({g['rol']}{', beheerder' if g['is_beheerder'] else ''})")
        out.append('-- Botst een e-mailadres met een bestaand account? Genereer opnieuw met')
        out.append('-- --geen-gebruikers en log in met je eigen account.')
    out.append('-- =====================================================================')
    out.append('')
    out.append('SET FOREIGN_KEY_CHECKS = 0;')
    out.append('')

    # -----------------------------------------------------------------
    # Specialisten, procedure typen, artikelen
    # -----------------------------------------------------------------
    out.append('-- Specialisten')
    vals = ', '.join(f"({sql_str(naam)}, {sql_str(typ)}, 1)" for naam, typ in SPECIALISTEN)
    out.append(f'INSERT INTO specialisten (naam, type, actief) VALUES {vals};')
    out.append('')

    out.append('-- Procedure typen')
    vals = ', '.join(f"({sql_str(p)}, 1)" for p in PROCEDURE_TYPEN)
    out.append(f'INSERT INTO procedure_typen (naam, actief) VALUES {vals};')
    out.append('')

    out.append('-- Artikelen')
    out.append('INSERT INTO artikelen')
    out.append('    (ean, productnaam, categorie, besteltype,')
    out.append('     max_ik, max_hybok, max_kar, min_berging, max_berging,')
    out.append('     locatie_ik, locatie_berging, locatie_hybok, artikelref, producent, prijs)')
    out.append('VALUES')
    artikel_rows = []
    for i, a in enumerate(ARTIKELEN):
        loc_ik = f'Cabinet {chr(65 + (i % 6))}1'
        loc_berging = f'Shelf {3 + (i % 5)}B'
        loc_hybok = f'Cabinet H{i + 1}'
        ref = f'DEMO-{i + 1:03d}'
        artikel_rows.append(
            '    (%s, %s, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s)' % (
                sql_str(a['ean']), sql_str(a['naam']), sql_str(a['cat']), sql_str(a['besteltype']),
                a['max'][0], a['max'][1], a['max'][2], a['min'][3], a['max'][3],
                sql_str(loc_ik), sql_str(loc_berging), sql_str(loc_hybok), sql_str(ref), sql_str(a['producent']), sql_num(a['prijs']),
            )
        )
    out.append(',\n'.join(artikel_rows) + ';')
    out.append('')

    out.append('-- Producenten + contactpersonen')
    prod_rows = []
    for p in PRODUCENTEN:
        prod_rows.append(
            '(%s, %s, %s, %s, %s)' % (
                sql_str(p['naam']), sql_str(p['website']), sql_str(p['telefoon']), sql_str(p['email']), sql_str(p['opmerking']),
            )
        )
    out.append('INSERT INTO producenten (naam, website, telefoon, email, opmerking) VALUES')
    out.append(',\n'.join(prod_rows) + ';')
    out.append('')
    for p in PRODUCENTEN:
        for naam, functie, telefoon, email in p['contacten']:
            out.append(
                "INSERT INTO producent_contacten (producent_id, naam, functie, telefoon, email) "
                "SELECT id, %s, %s, %s, %s FROM producenten WHERE naam = %s;"
                % (sql_str(naam), sql_str(functie), sql_str(telefoon), sql_str(email), sql_str(p['naam']))
            )
    out.append('')

    if met_gebruikers:
        out.append('-- Demo-gebruikersaccounts (wachtwoord voor alle vier: ' + DEMO_WACHTWOORD + ')')
        out.append('-- Sla dit blok over met --geen-gebruikers als je al eigen accounts hebt')
        out.append('-- of als de e-mailadressen hieronder al in gebruik zijn (UNIQUE-constraint).')
        gebr_rows = []
        for g in DEMO_GEBRUIKERS:
            gebr_rows.append(
                '(%s, %s, %s, %s, %d, %s)' % (
                    sql_str(g['naam']), sql_str(g['email']), sql_str(DEMO_WACHTWOORD_HASH),
                    sql_str(g['rol']), g['is_beheerder'], sql_str(g['taal']),
                )
            )
        out.append('INSERT INTO users (naam, email, wachtwoord_hash, rol, is_beheerder, taal) VALUES')
        out.append(',\n'.join(gebr_rows) + ';')
        out.append('')
        out.append('-- Koppelt elke nieuwe demo-gebruiker aan de bijbehorende standaardgroep')
        out.append('-- (Specialisten/Laboranten/Bekijkers), dezelfde mapping als')
        out.append('-- sql/herstel_ontbrekende_tabellen.sql voor de allereerste seed gebruikt.')
        out.append('INSERT INTO gebruiker_groepen (gebruiker_id, groep_id)')
        out.append('SELECT u.id, g.id FROM users u JOIN groepen g ON')
        out.append("  (u.rol = 'specialist' AND g.naam = 'Specialisten') OR")
        out.append("  (u.rol = 'laborant' AND g.naam = 'Laboranten') OR")
        out.append("  (u.rol = 'bekijker' AND g.naam = 'Bekijkers')")
        out.append('WHERE u.email IN (%s);' % ', '.join(sql_str(g['email']) for g in DEMO_GEBRUIKERS))
        out.append('')

    today = date.today()
    start = today - timedelta(days=aantal_dagen)

    out.append('-- Audit log voor de zojuist aangemaakte artikelen')
    audit_rows = []
    for a in ARTIKELEN:
        audit_rows.append(
            "    (%s, %s, 'product_toegevoegd', 'artikel', %s, %s)" % (
                sql_str(f'{start.isoformat()} 09:00:00'), sql_str(GEBRUIKER_NAMEN[0]), sql_str(a['ean']), sql_str('naam=' + a['naam']),
            )
        )
    out.append('INSERT INTO audit_log (tijdstempel, gebruiker_naam, actie, entiteit_type, entiteit_id, details) VALUES')
    out.append(',\n'.join(audit_rows) + ';')
    out.append('')

    # -----------------------------------------------------------------
    # Simulatie
    # -----------------------------------------------------------------
    batches = {}  # (ean, slot) -> list[Batch], oudste eerst (FEFO)
    lot_teller = 0

    trans_rows = []       # elk element is een geformatteerde VALUES-tuple string
    order_inserts = []
    order_updates = []
    open_orders = {}      # ean -> dict(id, aantal, ontvang_op)
    order_id_teller = 0

    def log_trans(tijdstempel, ean, lot, vervaldatum, loc_slot, ttype, hoeveelheid, prijs, specialist, procedure, opmerking, naam, gebruiker):
        trans_rows.append(
            '(%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)' % (
                sql_str(tijdstempel), sql_str(ean), sql_str(lot),
                sql_str(vervaldatum.isoformat() if vervaldatum else None),
                sql_loc(loc_slot, locatienamen), sql_str(ttype), hoeveelheid, sql_num(prijs),
                sql_str(specialist), sql_str(procedure), sql_str(opmerking), sql_str(naam), sql_str(gebruiker),
            )
        )

    # Startvoorraad: elk artikel begint rond het midden van min/max op elke
    # locatie waar het hoort, zodat er vanaf dag 1 al iets te verbruiken/
    # tellen/laten vervallen is i.p.v. een jaar op de eerste order te
    # moeten wachten.
    #
    # BELANGRIJK: dit MOET ook als een 'Ontvangst'-transactieregel geboekt
    # worden, ook al is het geen echt scan-moment. De app kent geen apart
    # "openingsvoorraad"-veld -- voorraadStatus()
    # (includes/voorraad.php::transactieNettoPerLocatie()) berekent de
    # huidige voorraad puur als SUM(inkomend) - SUM(uitgaand) uit
    # transactielog, zonder enige andere bron. Zonder deze regel wordt deze
    # startvoorraad wel verbruikt/verplaatst/afgeboekt (met een
    # bijbehorende uitgaande transactie) maar nooit ontvangen -- met
    # negatieve berekende voorraad tot gevolg zodra de startvoorraad op een
    # locatie een keer helemaal weggebruikt is. (Eerder een echte bug
    # gebleken in gegenereerde testdata.)
    for a in ARTIKELEN:
        for slot, idx in SLOT_INDEX.items():
            max_ = a['max'][idx]
            if max_ <= 0:
                continue
            start_aantal = a['min'][idx] + max(0, max_ - a['min'][idx]) // 2
            if start_aantal <= 0:
                continue
            lot_teller += 1
            lot = f'STARTLOT{lot_teller:05d}'
            vervaldatum = start + timedelta(days=rng.randint(60, a['houdbaarheid'] * 30))
            batches.setdefault((a['ean'], slot), []).append(Batch(lot, vervaldatum, start_aantal))
            log_trans(
                f'{start.isoformat()} 08:00:00', a['ean'], lot, vervaldatum, slot, 'Ontvangst', start_aantal, a['prijs'],
                None, None, 'Openingsvoorraad (testdata)', a['naam'], GEBRUIKER_NAMEN[0],
            )

    for dag in range(aantal_dagen + 1):
        datum = start + timedelta(days=dag)

        for a in ARTIKELEN:
            ean = a['ean']
            kans = DAGELIJKSE_KANS[a['verbruik']]

            for slot in VERBRUIK_LOCATIES:
                idx = SLOT_INDEX[slot]
                if a['max'][idx] <= 0:
                    continue

                if rng.random() < kans:
                    gewenst = 2 if rng.randint(1, 100) <= 15 else 1
                    aantal, lot, vervaldatum = verbruik(batches, ean, slot, gewenst)
                    if aantal > 0:
                        log_trans(
                            tijd(datum, rng=rng), ean, lot, vervaldatum, slot, 'Verbruik', aantal, a['prijs'],
                            rng.choice(SPECIALISTEN)[0], rng.choice(PROCEDURE_TYPEN), None, a['naam'], rng.choice(GEBRUIKER_NAMEN),
                        )

                huidig = voorraad(batches, ean, slot)
                if huidig < a['min'][idx]:
                    benodigd = a['max'][idx] - huidig
                    beschikbaar = voorraad(batches, ean, 'berging')
                    te_verplaatsen = min(benodigd, beschikbaar)
                    if te_verplaatsen > 0:
                        aantal, lot, vervaldatum = verbruik(batches, ean, 'berging', te_verplaatsen)
                        if aantal > 0:
                            t = tijd(datum, rng=rng)
                            gebruiker = rng.choice(GEBRUIKER_NAMEN)
                            log_trans(t, ean, lot, vervaldatum, 'berging', 'Verplaatsing uit', aantal, a['prijs'],
                                      None, None, f"Bijvullen {slot}", a['naam'], gebruiker)
                            log_trans(t, ean, lot, vervaldatum, slot, 'Verplaatsing in', aantal, a['prijs'],
                                      None, None, f"Bijgevuld vanuit berging", a['naam'], gebruiker)
                            batches.setdefault((ean, slot), []).append(Batch(lot, vervaldatum, aantal))

            # Berging zelf: order plaatsen als onder minimum en nog geen order openstaat
            if a['max'][3] > 0 and ean not in open_orders:
                huidig_berging = voorraad(batches, ean, 'berging')
                if huidig_berging < a['min'][3]:
                    besteld = a['max'][3] - huidig_berging
                    order_id_teller += 1
                    order_inserts.append(
                        '(%d, %s, %s, %s, %d, 0, %s, %s, NULL)' % (
                            order_id_teller, sql_str(tijd(datum, rng=rng)), sql_str(ean), sql_str(a['naam']), besteld,
                            sql_str(rng.choice(GEBRUIKER_NAMEN)), sql_str(rng.choice(KANALEN)),
                        )
                    )
                    open_orders[ean] = dict(
                        id=order_id_teller, aantal=besteld,
                        ontvang_op=datum + timedelta(days=levertijd_dagen(rng)),
                    )

            # Openstaande order ontvangen zodra de datum is bereikt
            order = open_orders.get(ean)
            if order and order['ontvang_op'] <= datum:
                t = tijd(datum, rng=rng)
                order_updates.append(
                    "UPDATE orders SET hoeveelheid_ontvangen = hoeveelheid_besteld, laatst_ontvangen_op = %s WHERE id = %d;"
                    % (sql_str(t), order['id'])
                )
                lot_teller += 1
                laag = max(60, (a['houdbaarheid'] - 4) * 30)
                hoog = a['houdbaarheid'] * 30
                vervaldatum = datum + timedelta(days=rng.randint(min(laag, hoog), hoog))
                lot = 'LOT%s%04d' % (datum.strftime('%y%m'), lot_teller)
                batches.setdefault((ean, 'berging'), []).append(Batch(lot, vervaldatum, order['aantal']))
                log_trans(t, ean, lot, vervaldatum, 'berging', 'Ontvangst', order['aantal'], a['prijs'],
                          None, None, None, a['naam'], rng.choice(GEBRUIKER_NAMEN))
                del open_orders[ean]

            # Vervallen: loten waarvan de vervaldatum is gepasseerd, op elke locatie
            for slot in (*VERBRUIK_LOCATIES, 'berging'):
                key = (ean, slot)
                lijst = batches.get(key)
                if not lijst:
                    continue
                nog_geldig = []
                for b in lijst:
                    if b.resterend > 0 and b.vervaldatum is not None and b.vervaldatum <= datum:
                        log_trans(tijd(datum, rng=rng), ean, b.lot, b.vervaldatum, slot, 'Vervallen', b.resterend, a['prijs'],
                                  None, None, 'Automatisch afgeboekt (vervaldatum gepasseerd)', a['naam'], rng.choice(GEBRUIKER_NAMEN))
                    elif b.resterend > 0:
                        nog_geldig.append(b)
                batches[key] = nog_geldig

        # Periodieke kastcontrole: elke ~90 dagen, alle locaties, kleine afwijkingen
        if dag > 0 and dag % 90 == 0:
            for slot in SLOT_INDEX:
                for a in ARTIKELEN:
                    ean = a['ean']
                    systeem = voorraad(batches, ean, slot)
                    if systeem == 0 and rng.randint(1, 100) > 5:
                        continue
                    afwijking = rng.randint(-2, 2) if rng.randint(1, 100) <= 12 else 0
                    if afwijking == 0:
                        continue
                    geteld = max(0, systeem + afwijking)
                    diff = geteld - systeem
                    ttype = 'Telling: overschot' if diff > 0 else 'Telling: tekort'
                    log_trans(tijd(datum, 9, 16, rng), ean, None, None, slot, ttype, abs(diff), a['prijs'],
                              None, None, f"Kastcontrole: systeem {systeem}, geteld {geteld}.", a['naam'], rng.choice(GEBRUIKER_NAMEN))
                    if diff > 0:
                        batches.setdefault((ean, slot), []).append(Batch(None, None, diff))
                    else:
                        verbruik(batches, ean, slot, abs(diff))

    # -----------------------------------------------------------------
    # Een paar bewust ingevoegde scenario's, los van de dag-simulatie
    # hierboven. Reden: met FEFO-verbruik + tijdig bijvullen "werkt" het
    # gesimuleerde systeem te goed -- de oudste partij wordt vrijwel
    # altijd op tijd verbruikt, dus er komt in de gewone simulatie bijna
    # nooit iets daadwerkelijk over datum. Precies de Vervaldatum-/
    # Afboekingen-schermen zijn er juist voor bedoeld om zulke gevallen
    # te laten zien, dus forceren we er een paar (nog los van of de
    # gewone simulatie er toevallig een paar heeft opgeleverd):
    #  (a) een al verlopen partij die nog in voorraad staat, nog niet
    #      afgeboekt -- precies het gat dat de Taken/Vervallen-afboeken-
    #      workflow hoort te vangen;
    #  (b) een partij die binnenkort verloopt (Expiry Focus/Vervaldatum
    #      Detail: SPOEDGEBRUIK-status);
    #  (c) een partij die in het verleden daadwerkelijk is afgeboekt
    #      (Write-offs-rapport heeft dan echte historie i.p.v. nul).
    # -----------------------------------------------------------------
    scenario_producten = rng.sample(ARTIKELEN, min(8, len(ARTIKELEN)))

    for a in scenario_producten[0:3]:
        slot = 'hybok' if a['max'][1] > 0 else 'ik'
        dagen_geleden = a['houdbaarheid'] * 30 + rng.randint(5, 25)
        ontvangst_datum = today - timedelta(days=dagen_geleden)
        vervaldatum = ontvangst_datum + timedelta(days=a['houdbaarheid'] * 30)
        aantal = rng.randint(2, 4)
        lot_teller += 1
        log_trans(tijd(ontvangst_datum, rng=rng), a['ean'], f'VERLOPEN{lot_teller:05d}', vervaldatum, slot, 'Ontvangst', aantal, a['prijs'],
                  None, None, None, a['naam'], rng.choice(GEBRUIKER_NAMEN))

    for a in scenario_producten[3:6]:
        slot = 'ik' if a['max'][0] > 0 else 'hybok'
        vervaldatum = today + timedelta(days=rng.randint(10, 45))
        ontvangst_datum = vervaldatum - timedelta(days=a['houdbaarheid'] * 30)
        aantal = rng.randint(2, 5)
        lot_teller += 1
        log_trans(tijd(ontvangst_datum, rng=rng), a['ean'], f'SPOEDIG{lot_teller:05d}', vervaldatum, slot, 'Ontvangst', aantal, a['prijs'],
                  None, None, None, a['naam'], rng.choice(GEBRUIKER_NAMEN))

    for a in scenario_producten[6:8]:
        dagen_geleden_ontvangst = a['houdbaarheid'] * 30 + rng.randint(60, 120)
        ontvangst_datum = today - timedelta(days=dagen_geleden_ontvangst)
        vervaldatum = ontvangst_datum + timedelta(days=a['houdbaarheid'] * 30)
        afboek_datum = vervaldatum + timedelta(days=rng.randint(3, 20))
        aantal = rng.randint(1, 3)
        lot_teller += 1
        lot = f'AFGEBOEKT{lot_teller:05d}'
        log_trans(tijd(ontvangst_datum, rng=rng), a['ean'], lot, vervaldatum, 'berging', 'Ontvangst', aantal, a['prijs'],
                  None, None, None, a['naam'], rng.choice(GEBRUIKER_NAMEN))
        log_trans(tijd(afboek_datum, rng=rng), a['ean'], lot, vervaldatum, 'berging', 'Vervallen', aantal, a['prijs'],
                  None, None, 'Automatisch afgeboekt (vervaldatum gepasseerd)', a['naam'], rng.choice(GEBRUIKER_NAMEN))

    # Levenscyclus voor het ene besteltype='Eenmalig' demo-artikel hierboven
    # (00000000000221): een Eenmalige Bestelling die tot de aanmaak van dit
    # product heeft geleid, inmiddels ontvangen en verbruikt -- zodat het
    # besteltype ook daadwerkelijk als gebruikt voorbeeld in de demo
    # terugkomt i.p.v. alleen als losse artikelregel.
    eenmalig_artikel = next(a for a in ARTIKELEN if a['ean'] == '00000000000221')
    eenmalig_ontvangst_datum = today - timedelta(days=21)
    eenmalig_verbruik_datum = today - timedelta(days=14)
    eenmalig_vervaldatum = eenmalig_ontvangst_datum + timedelta(days=eenmalig_artikel['houdbaarheid'] * 30)
    log_trans(tijd(eenmalig_ontvangst_datum, rng=rng), eenmalig_artikel['ean'], 'EENMALIG00001', eenmalig_vervaldatum, 'berging', 'Ontvangst', 1,
              eenmalig_artikel['prijs'], None, None, 'Ontvangen n.a.v. eenmalige bestelling TEST-90210', eenmalig_artikel['naam'], GEBRUIKER_NAMEN[1])
    log_trans(tijd(eenmalig_verbruik_datum, rng=rng), eenmalig_artikel['ean'], 'EENMALIG00001', eenmalig_vervaldatum, 'berging', 'Verbruik', 1,
              eenmalig_artikel['prijs'], SPECIALISTEN[0][0], PROCEDURE_TYPEN[0], None, eenmalig_artikel['naam'], GEBRUIKER_NAMEN[1])
    eenmalig_extra_row = (
        '(%s, %s, %s, %s, 1, %s, %s, %s, NULL, %s)' % (
            sql_str(tijd(eenmalig_ontvangst_datum - timedelta(days=3), rng=rng)), sql_str('TEST-90210'),
            sql_str(eenmalig_artikel['naam']), sql_str(eenmalig_artikel['ean']), sql_str(PROCEDURE_TYPEN[0]),
            sql_str(SPECIALISTEN[0][0]), sql_str(GEBRUIKER_NAMEN[1]), sql_str('Gebruikt'),
        )
    )

    # laatst_geteld_op per slot -- alle vier op de datum van de laatste kastcontrolecyclus in deze run.
    laatste_telling_dag = (aantal_dagen // 90) * 90
    if laatste_telling_dag > 0:
        laatste_telling_datum = start + timedelta(days=laatste_telling_dag)
        out.append('-- Kastcontrole-datum bijwerken (laatste kastcontrolecyclus in deze simulatie)')
        for slot in SLOT_INDEX:
            out.append(
                f"UPDATE locatie_instellingen SET laatst_geteld_op = {sql_str(laatste_telling_datum.isoformat() + ' 10:00:00')} WHERE slot = '{slot}';"
            )
        out.append('')

    # -----------------------------------------------------------------
    # Orders wegschrijven (insert, gevolgd door de bijbehorende updates)
    # -----------------------------------------------------------------
    if order_inserts:
        out.append('-- Orders')
        out.append('INSERT INTO orders (id, tijdstempel, ean, productnaam, hoeveelheid_besteld, hoeveelheid_ontvangen, besteller, kanaal, opmerking) VALUES')
        out.append(',\n'.join(order_inserts) + ';')
        out.append('')
    if order_updates:
        out.append('-- Orders die binnen de gesimuleerde periode zijn ontvangen (laatst_ontvangen_op gezet, zie includes/orders.php::updateOrdersWithFIFO())')
        out.extend(order_updates)
        out.append('')

    # -----------------------------------------------------------------
    # Transactielog wegschrijven, in blokken van 500 rijen per INSERT
    # -----------------------------------------------------------------
    out.append('-- Transactielog (%d regels)' % len(trans_rows))
    kolommen = ('tijdstempel, ean, lot, vervaldatum, locatie, transactietype, hoeveelheid, prijs_op_moment, '
                'specialist, procedure_type, opmerking, productnaam, aangemaakt_door')
    CHUNK = 500
    for i in range(0, len(trans_rows), CHUNK):
        chunk = trans_rows[i:i + CHUNK]
        out.append(f'INSERT INTO transactielog ({kolommen}) VALUES')
        out.append(',\n'.join(chunk) + ';')
        out.append('')

    # -----------------------------------------------------------------
    # Eenmalige bestellingen (fictief, patiëntgebonden -- duidelijk
    # herkenbare testpatiëntnummers) en foutlog
    # -----------------------------------------------------------------
    statussen = ['Aangevraagd', 'Besteld', 'Ontvangen', 'Gebruikt']
    eenmalig_rows = []
    for _ in range(18):
        a = rng.choice(ARTIKELEN)
        dag_offset = rng.randint(0, aantal_dagen)
        t = tijd(start + timedelta(days=dag_offset), rng=rng)
        status = rng.choice(statussen) if dag_offset < aantal_dagen - 21 else rng.choice(statussen[:2])
        eenmalig_rows.append(
            '(%s, %s, %s, %s, %d, %s, %s, %s, NULL, %s)' % (
                sql_str(t), sql_str('TEST-%05d' % rng.randint(1, 99999)), sql_str(a['naam']), sql_str(a['ean']),
                rng.randint(1, 2), sql_str(rng.choice(PROCEDURE_TYPEN)), sql_str(rng.choice(SPECIALISTEN)[0]),
                sql_str(rng.choice(GEBRUIKER_NAMEN)), sql_str(status),
            )
        )
    eenmalig_rows.append(eenmalig_extra_row)
    out.append('-- Eenmalige bestellingen (fictief, patiëntgebonden)')
    out.append('INSERT INTO eenmalige_bestellingen (tijdstempel, patientnummer, artikelomschrijving, ean, hoeveelheid, procedure_type, specialist, besteller, opmerking, status) VALUES')
    out.append(',\n'.join(eenmalig_rows) + ';')
    out.append('')

    fouttypen = ['GS1_PARSE_ERROR', 'ONBEKEND_ARTIKEL', 'FOTO_LAAD_ERROR']
    fout_rows = []
    for _ in range(6):
        dag_offset = rng.randint(0, aantal_dagen)
        t = tijd(start + timedelta(days=dag_offset), rng=rng)
        fout_rows.append(
            '(%s, %s, %s, %s)' % (
                sql_str(t), sql_str(rng.choice(fouttypen)), sql_str('(01)0999999%06d' % rng.randint(100000, 999999)),
                sql_str('Fictieve testmelding, gegenereerd door migratie/genereer_testdata.py.'),
            )
        )
    out.append('-- Foutlog (fictief)')
    out.append('INSERT INTO foutlog (tijdstip, fouttype, barcode, bericht) VALUES')
    out.append(',\n'.join(fout_rows) + ';')
    out.append('')

    out.append('SET FOREIGN_KEY_CHECKS = 1;')
    out.append('')
    out.append('-- Klaar. %d transactielog-regels, %d orders (%d nog open aan het eind van de gesimuleerde periode).'
                % (len(trans_rows), order_id_teller, order_id_teller - len(order_updates)))

    return '\n'.join(out) + '\n'


def main():
    parser = argparse.ArgumentParser(description='Genereert een .sql-bestand met een jaar fictieve Stocuro-testdata.')
    parser.add_argument('--dagen', type=int, default=365, help='Aantal dagen historie (standaard 365).')
    parser.add_argument('--seed', type=int, default=None, help='Vaste random seed, voor reproduceerbare output.')
    parser.add_argument('--output', type=str, default='migratie/testdata_gegenereerd.sql', help='Pad van het te schrijven .sql-bestand.')
    parser.add_argument(
        '--geen-gebruikers', action='store_true',
        help='Sla het blok met 4 demo-inlogaccounts (tabel users) over -- gebruik dit als je al eigen accounts hebt.',
    )
    parser.add_argument(
        '--locaties', type=str, default=None,
        help=(
            "Eigen locatienamen als je de vier vaste slots al hernoemd hebt in Admin -> Locations "
            "(anders worden de standaardnamen 'Room A,Room B,Cart,Storage' gebruikt). "
            "Vorm: 'ik-naam,hybok-naam,kar-naam,berging-naam' -- exact in die volgorde, "
            "bijv. --locaties \"Interventiekamer,Hybride OK,Kar,Berging\"."
        ),
    )
    args = parser.parse_args()

    locatienamen = dict(STANDAARD_LOCATIENAMEN)
    if args.locaties:
        delen = [d.strip() for d in args.locaties.split(',')]
        if len(delen) != 4:
            raise SystemExit("--locaties verwacht precies 4, kommagescheiden namen (ik,hybok,kar,berging volgorde).")
        locatienamen = dict(zip(('ik', 'hybok', 'kar', 'berging'), delen))

    sql = genereer(max(30, args.dagen), args.seed, locatienamen, met_gebruikers=not args.geen_gebruikers)
    with open(args.output, 'w', encoding='utf-8') as f:
        f.write(sql)
    print(f'Geschreven: {args.output} ({len(sql):,} bytes)')
    print('Gebruikte locatienamen:', locatienamen)
    print('Importeer dit bestand via phpMyAdmin (Importeren-tab), na eventueel migratie/leegmaken.sql.')
    if not args.geen_gebruikers:
        print(f'Demo-inlogaccounts aangemaakt, wachtwoord voor alle vier: {DEMO_WACHTWOORD}')
        for g in DEMO_GEBRUIKERS:
            print(f"  - {g['email']}  ({g['rol']}{', beheerder' if g['is_beheerder'] else ''})")


if __name__ == '__main__':
    main()
