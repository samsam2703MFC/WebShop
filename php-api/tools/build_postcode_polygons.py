#!/usr/bin/env python3
"""Construit les CONTOURS RÉELS des codes postaux belges (GeoJSON) pour la carte
des zones de chalandise (console marque → écran « Zones »).

POURQUOI CE SCRIPT
------------------
Un code postal n'est PAS une commune : 1145 codes postaux pour 589 communes,
et 68 % des codes postaux partagent leur commune (jusqu'à 20 CP dans une
seule). Dessiner le contour communal à la place du contour du CP surestimerait
le territoire dans deux cas sur trois — donc on ne le fait pas.

La bonne granularité est le SECTEUR STATISTIQUE (~19 800 polygones) : chaque
secteur appartient à un seul code postal. On dissout donc les secteurs par
code postal, ce qui donne le contour EXACT de chaque CP.

ENTRÉES
-------
1) Les secteurs statistiques (source officielle, gratuite) :
     https://statbel.fgov.be/fr/open-data/secteurs-statistiques
   Télécharger la version GeoJSON (ou convertir le Shapefile en GeoJSON).
2) Optionnel — le dictionnaire secteur → code postal, si le fichier des
   secteurs ne porte pas le code postal :
     https://raw.githubusercontent.com/mathiasleroy/Belgium-Geographic-Data/master/dist/metadata/be-dictionary.csv
   (colonnes utilisées : NIS9 / StatisticalSector et PostCode)

SORTIE
------
Une FeatureCollection : une feature par code postal, properties.postcode =
le code postal, géométrie Polygon/MultiPolygon en WGS84 (lon/lat). C'est le
format lu par l'API /geo/postcode-polygons.

UTILISATION
-----------
    pip install shapely pyproj
    python3 build_postcode_polygons.py \
        --sectors sh_statbel_statistical_sectors.geojson \
        --dict be-dictionary.csv \
        --out ../data/zipcodes_be_polygons.geojson

Puis déposer le fichier produit sur le serveur, dans :
    <web-root>/webshop/api/data/zipcodes_be_polygons.geojson
La carte affiche les polygones immédiatement, sans redéploiement.
"""

import argparse
import csv
import json
import sys
from collections import defaultdict

# Champs possibles portant le code postal directement dans le fichier secteurs.
POSTCODE_FIELDS = ('CD_POSTAL', 'POSTCODE', 'POSTAL_CODE', 'CD_POST', 'ZIP', 'CP')
# Champs possibles identifiant le secteur (pour la jointure avec le dictionnaire).
SECTOR_FIELDS = ('CD_SECTOR', 'CS01012020', 'CD_SECTOR_REFNIS', 'SECTOR', 'NIS9')
# Lambert 72 belge -> WGS84
LAMBERT72 = 'EPSG:31370'
WGS84 = 'EPSG:4326'


def die(msg):
    print('ERREUR : ' + msg, file=sys.stderr)
    sys.exit(1)


def load_dictionary(path):
    """secteur (NIS9 / nom) -> code postal, depuis be-dictionary.csv."""
    mapping = {}
    with open(path, encoding='latin-1', newline='') as fh:
        for row in csv.DictReader(fh):
            pc = (row.get('PostCode') or '').strip()
            if not pc:
                continue
            for key in ('NIS9', 'StatisticalSector'):
                val = (row.get(key) or '').strip()
                if val:
                    mapping[val] = pc
    if not mapping:
        die('dictionnaire vide ou colonnes PostCode/NIS9 absentes : ' + path)
    return mapping


def postcode_of(props, dico):
    """Code postal d'un secteur : champ direct, sinon jointure dictionnaire."""
    for field in POSTCODE_FIELDS:
        val = props.get(field)
        if val not in (None, ''):
            digits = ''.join(ch for ch in str(val) if ch.isdigit())
            if len(digits) == 4:
                return digits
    if dico:
        for field in SECTOR_FIELDS:
            val = props.get(field)
            if val not in (None, '') and str(val).strip() in dico:
                return dico[str(val).strip()]
    return None


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--sectors', required=True, help='GeoJSON des secteurs statistiques (Statbel)')
    ap.add_argument('--dict', dest='dico', help='be-dictionary.csv (si les secteurs ne portent pas le CP)')
    ap.add_argument('--out', required=True, help='GeoJSON de sortie (contours par code postal)')
    ap.add_argument('--simplify', type=float, default=0.0002,
                    help='tolérance de simplification en degrés (0 = aucune ; défaut 0.0002 ≈ 20 m)')
    ap.add_argument('--source-crs', default='auto', choices=['auto', 'wgs84', 'lambert72'],
                    help="système de coordonnées d'entrée (auto = détecté d'après l'étendue)")
    args = ap.parse_args()

    try:
        from shapely.geometry import shape, mapping
        from shapely.ops import unary_union, transform as sh_transform
    except ImportError:
        die('shapely manquant — installez-le : pip install shapely pyproj')

    with open(args.sectors, encoding='utf-8-sig') as fh:
        fc = json.load(fh)
    feats = fc.get('features') or []
    if not feats:
        die('aucune feature dans ' + args.sectors)

    dico = load_dictionary(args.dico) if args.dico else None

    # Détection du système de coordonnées : le Lambert 72 est en mètres
    # (centaines de milliers), le WGS84 en degrés (< 180).
    crs = args.source_crs
    if crs == 'auto':
        probe = shape(feats[0]['geometry']).bounds
        crs = 'lambert72' if max(abs(probe[0]), abs(probe[1])) > 1000 else 'wgs84'
        print('système de coordonnées détecté : ' + crs)

    project = None
    if crs == 'lambert72':
        try:
            from pyproj import Transformer
        except ImportError:
            die('pyproj manquant (nécessaire pour reprojeter le Lambert 72) — pip install pyproj')
        tr = Transformer.from_crs(LAMBERT72, WGS84, always_xy=True)
        project = lambda x, y, z=None: tr.transform(x, y)  # noqa: E731

    groups = defaultdict(list)
    sans_cp = 0
    for feat in feats:
        geom = feat.get('geometry')
        if not geom:
            continue
        pc = postcode_of(feat.get('properties') or {}, dico)
        if not pc:
            sans_cp += 1
            continue
        try:
            g = shape(geom)
            if project is not None:
                g = sh_transform(project, g)
            if not g.is_empty:
                groups[pc].append(g)
        except Exception as exc:                                  # géométrie corrompue
            print('secteur ignoré (géométrie invalide) : %s' % exc, file=sys.stderr)

    if not groups:
        die('aucun code postal résolu — fournissez --dict, ou vérifiez les champs du fichier secteurs')

    out_feats = []
    for pc in sorted(groups):
        merged = unary_union(groups[pc])
        if args.simplify > 0:
            simplified = merged.simplify(args.simplify, preserve_topology=True)
            if not simplified.is_empty:
                merged = simplified
        out_feats.append({'type': 'Feature',
                          'properties': {'postcode': pc},
                          'geometry': mapping(merged)})

    with open(args.out, 'w', encoding='utf-8') as fh:
        json.dump({'type': 'FeatureCollection', 'features': out_feats}, fh, separators=(',', ':'))

    print('%d codes postaux écrits dans %s' % (len(out_feats), args.out))
    if sans_cp:
        print('%d secteur(s) sans code postal résolu (ignorés)' % sans_cp)


if __name__ == '__main__':
    main()
