#!/usr/bin/env python3
"""
Eenmalig migratiescript: leest het Artikelen-tabblad uit de Excel-export
van Google Sheets en genereert INSERT-statements voor de artikelen-tabel
(migratieplan hoofdstuk 7). Dit is de ENIGE tabel die echte data bevat --
transactielog/orders/eenmalige_bestellingen/foutlog beginnen leeg in de
nieuwe database (zie migratieplan hoofdstuk 7, "Niet migreren").

Gebruik:
    pip install openpyxl
    python3 export_from_excel.py "Voorraadbeheer Interventiekamer.xlsx" artikelen_import.sql

Het resultaat (artikelen_import.sql) draai je op de One.com-database via
phpMyAdmin of een vergelijkbare tool, NADAT sql/migrate.php is uitgevoerd
(de artikelen-tabel moet al bestaan).
"""

import sys
import json
import re
from datetime import datetime

try:
    import openpyxl
except ImportError:
    print("openpyxl ontbreekt. Installeer met: pip install openpyxl --break-system-packages", file=sys.stderr)
    sys.exit(1)

# 0-based kolomindexen in het Artikelen-tabblad (zelfde als de oude
# DashboardAll.gs/Code.gs-scripts gebruikten).
COL_PRODUCTNAAM = 1
COL_GS1_RAW = 2
COL_EAN = 3
COL_FOTO = 6
COL_CATEGORIE = 7
COL_BESTELTYPE = 8
COL_MIN_IK = 9
COL_MAX_IK = 10
COL_MIN_HYBOK = 11
COL_MAX_HYBOK = 12
COL_MIN_KAR = 13
COL_MAX_KAR = 14
COL_MIN_BERGING = 15
COL_MAX_BERGING = 16
COL_LOC_IK = 17
COL_LOC_BERGING = 18
COL_LOC_HYBOK = 19
COL_ARTIKELREF = 20
COL_PRODUCENT = 21
COL_PRIJS = 22

# Categorie -> {json-sleutel: kolomindex}. Zelfde sleutels als
# includes/voorraad.php::specLabels() in de PHP-app -- moet exact
# overeenkomen, anders leest de app de specs niet terug.
SPEC_COLUMNS = {
    'stent': {
        'diameter_mm': 23, 'lengte_mm': 24, 'schacht_cm': 25,
        'voerdraad_inch': 26, 'sheath_fr': 27,
    },
    'voerdraad': {
        'diameter_inch': 28, 'lengte_cm': 29, 'tip_type': 30, 'coating': 31,
    },
    'embolisatie': {
        'type': 32, 'diameter_mm': 33, 'lengte_cm': 34,
        'toedieningsroute': 35, 'catheter_inch': 36,
    },
    'sheath': {
        'french_size': 37, 'lengte_cm': 38, 'voerdraad_inch': 39,
    },
    'ballon': {
        'diameter_mm': 40, 'lengte_mm': 41, 'voerdraad_inch': 42, 'sheath_fr': 43,
    },
    # catheter / closure / sluit-varianten delen dezelfde kolommen (45-49),
    # zelfde als getCategorieSpecFields() in de oude DashboardAll.gs.
    'catheter': {
        'french_size_closure': 45, 'lengte_cm': 46, 'voerdraad_inch': 47,
        'schacht_cm': 48, 'sheath_fr': 49,
    },
}


def normalize_ean(value) -> str:
    """Zelfde regel als normalizeEAN() in ean.php: trailing '.0' strippen
    (Excel slaat numerieke barcodes soms als float op) en links opvullen
    tot 14 cijfers."""
    s = str(value).strip() if value is not None else ''
    s = re.sub(r'\.0$', '', s)
    s = re.sub(r'\s', '', s)
    return s.zfill(14)


def spec_group_for_categorie(categorie: str):
    cat = (categorie or '').lower()
    if 'stent' in cat:
        return SPEC_COLUMNS['stent']
    if 'voerdraad' in cat:
        return SPEC_COLUMNS['voerdraad']
    if 'embolisatie' in cat:
        return SPEC_COLUMNS['embolisatie']
    if 'sheath' in cat:
        return SPEC_COLUMNS['sheath']
    if 'ballon' in cat:
        return SPEC_COLUMNS['ballon']
    if 'catheter' in cat or 'closure' in cat or 'sluit' in cat:
        return SPEC_COLUMNS['catheter']
    return {}


def sql_escape(value) -> str:
    """NULL of een correct ge-escapete SQL-stringliteral. Geen
    parametrized query's hier -- dit script genereert een .sql-bestand
    dat handmatig wordt uitgevoerd, geen live databaseverbinding."""
    if value is None or value == '':
        return 'NULL'
    s = str(value)
    s = s.replace('\\', '\\\\').replace("'", "\\'")
    return f"'{s}'"


def to_int(value) -> int:
    try:
        if value is None or value == '':
            return 0
        return int(float(value))
    except (ValueError, TypeError):
        return 0


def to_float(value) -> float:
    try:
        if value is None or value == '':
            return 0.0
        return float(value)
    except (ValueError, TypeError):
        return 0.0


def main():
    if len(sys.argv) < 3:
        print(f"Gebruik: {sys.argv[0]} <pad-naar-xlsx> <output.sql>", file=sys.stderr)
        sys.exit(1)

    xlsx_path = sys.argv[1]
    output_path = sys.argv[2]

    wb = openpyxl.load_workbook(xlsx_path, data_only=True)
    if 'Artikelen' not in wb.sheetnames:
        print("Tabblad 'Artikelen' niet gevonden in het Excel-bestand.", file=sys.stderr)
        sys.exit(1)

    ws = wb['Artikelen']
    rows = list(ws.iter_rows(min_row=2, values_only=True))

    inserts = []
    overgeslagen = 0
    gezien_eans = set()

    for row in rows:
        if not row or row[COL_EAN] in (None, ''):
            continue

        ean = normalize_ean(row[COL_EAN])
        if ean == '00000000000000':
            overgeslagen += 1
            continue
        if ean in gezien_eans:
            # Dubbele EAN in de bron -- laatste wint niet automatisch,
            # eerste wordt gehouden en de rest overgeslagen zodat er geen
            # UNIQUE-constraint-fout ontstaat bij het importeren.
            overgeslagen += 1
            continue
        gezien_eans.add(ean)

        productnaam = row[COL_PRODUCTNAAM] or 'Onbekend'
        categorie = row[COL_CATEGORIE] or ''
        besteltype = row[COL_BESTELTYPE] or 'Automatisch'
        if besteltype not in ('Automatisch', 'Handmatig'):
            besteltype = 'Automatisch'

        specs = {}
        for key, col_idx in spec_group_for_categorie(categorie).items():
            val = row[col_idx] if col_idx < len(row) else None
            if val is not None and val != '':
                specs[key] = val
        specs_json = json.dumps(specs, ensure_ascii=False) if specs else None

        values = [
            sql_escape(ean),
            sql_escape(productnaam),
            sql_escape(row[COL_GS1_RAW]),
            sql_escape(row[COL_FOTO]),
            sql_escape(categorie),
            sql_escape(besteltype),
            to_int(row[COL_MIN_IK]), to_int(row[COL_MAX_IK]),
            to_int(row[COL_MIN_HYBOK]), to_int(row[COL_MAX_HYBOK]),
            to_int(row[COL_MIN_KAR]), to_int(row[COL_MAX_KAR]),
            to_int(row[COL_MIN_BERGING]), to_int(row[COL_MAX_BERGING]),
            sql_escape(row[COL_LOC_IK]),
            sql_escape(row[COL_LOC_BERGING]),
            sql_escape(row[COL_LOC_HYBOK]),
            sql_escape(row[COL_ARTIKELREF]),
            sql_escape(row[COL_PRODUCENT]),
            to_float(row[COL_PRIJS]),
            sql_escape(specs_json),
        ]

        inserts.append(
            "INSERT INTO artikelen (ean, productnaam, gs1_raw, foto_link, categorie, besteltype, "
            "min_ik, max_ik, min_hybok, max_hybok, min_kar, max_kar, min_berging, max_berging, "
            "locatie_ik, locatie_berging, locatie_hybok, artikelref, producent, prijs, specs) VALUES ("
            + ", ".join(str(v) for v in values) + ");"
        )

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(f"-- Gegenereerd door export_from_excel.py op {datetime.now().isoformat(timespec='seconds')}\n")
        f.write(f"-- Bron: {xlsx_path}\n")
        f.write(f"-- {len(inserts)} artikelen, {overgeslagen} rijen overgeslagen (lege/dubbele EAN)\n\n")
        f.write("\n".join(inserts))
        f.write("\n")

    print(f"Klaar: {len(inserts)} artikelen geschreven naar {output_path} ({overgeslagen} overgeslagen).")


if __name__ == '__main__':
    main()
