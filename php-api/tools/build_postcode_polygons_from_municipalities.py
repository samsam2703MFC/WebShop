#!/usr/bin/env python3
"""Contours par code postal à la granularité COMMUNALE — variante de repli
ASSUMÉE ET ÉTIQUETÉE, à utiliser tant que les secteurs statistiques ne sont pas
disponibles (voir build_postcode_polygons.py pour le contour EXACT).

CE QUE C'EST, CE QUE CE N'EST PAS
---------------------------------
Géométrie 100 % réelle : contours communaux officiels (dérivés des secteurs
statistiques Statbel, dissous par commune), reprojetés en WGS84.

MAIS la granularité est la COMMUNE, pas le code postal : 1145 codes postaux
pour 589 communes, et 68 % des codes postaux partagent leur commune (jusqu'à 20
dans une seule). Deux codes postaux d'une même commune reçoivent donc le MÊME
contour, plus large que leur territoire réel.

C'est pourquoi chaque feature porte :
    granularite    = "commune"
    cp_partages    = nombre de codes postaux partageant ce contour (1 = exact)
et la collection porte un bloc "meta" que l'API renvoie sous la clé "_meta" :
l'interface affiche un avertissement, donc rien n'est présenté comme exact.

ENTRÉES (toutes deux publiques, licence CC BY)
    --municip  be-municip-lamberts.geo.json   (champ NIS5, Lambert 72)
    --dict     be-dictionary.csv              (colonnes PostCode et NIS5)
    https://github.com/mathiasleroy/Belgium-Geographic-Data

UTILISATION
    pip install shapely pyproj
    python3 build_postcode_polygons_from_municipalities.py \\
        --municip be-municip-lamberts.geo.json --dict be-dictionary.csv \\
        --gzip --out ../data/zipcodes_be_polygons.geojson
"""

import argparse
import csv
import gzip
import json
import os
import sys
from collections import defaultdict

LAMBERT72 = 'EPSG:31370'
WGS84 = 'EPSG:4326'


def round_coords(obj, nd):
    if isinstance(obj, (list, tuple)):
        if obj and isinstance(obj[0], (int, float)):
            return [round(float(v), nd) for v in obj]
        return [round_coords(v, nd) for v in obj]
    return obj


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--municip', required=True, help='GeoJSON des communes (champ NIS5)')
    ap.add_argument('--dict', dest='dico', required=True, help='be-dictionary.csv (PostCode, NIS5)')
    ap.add_argument('--out', required=True, help='GeoJSON de sortie')
    ap.add_argument('--simplify', type=float, default=0.0003, help='tolérance en degrés (0 = aucune)')
    ap.add_argument('--precision', type=int, default=5, help='décimales conservées (défaut 5 ≈ 1 m)')
    ap.add_argument('--gzip', action='store_true', help='écrire un .gz (lu tel quel par l’API)')
    ap.add_argument('--source-crs', default='auto', choices=['auto', 'wgs84', 'lambert72'])
    args = ap.parse_args()

    try:
        from shapely.geometry import shape, mapping
        from shapely.ops import transform as sh_transform
    except ImportError:
        print('ERREUR : shapely manquant — pip install shapely pyproj', file=sys.stderr)
        sys.exit(1)

    # 1) code postal -> commune, et commune -> codes postaux (pour cp_partages)
    pc2nis, nis2pc = {}, defaultdict(set)
    with open(args.dico, encoding='latin-1', newline='') as fh:
        for row in csv.DictReader(fh):
            pc = (row.get('PostCode') or '').strip()
            nis = (row.get('NIS5') or '').strip()
            if pc and nis:
                pc2nis[pc] = nis
                nis2pc[nis].add(pc)
    if not pc2nis:
        print('ERREUR : dictionnaire vide (colonnes PostCode / NIS5 attendues)', file=sys.stderr)
        sys.exit(1)

    # 2) géométries communales, indexées par NIS5
    with open(args.municip, encoding='utf-8-sig') as fh:
        fc = json.load(fh)
    feats = fc.get('features') or []
    if not feats:
        print('ERREUR : aucune feature dans ' + args.municip, file=sys.stderr)
        sys.exit(1)

    crs = args.source_crs
    if crs == 'auto':
        b = shape(feats[0]['geometry']).bounds
        crs = 'lambert72' if max(abs(b[0]), abs(b[1])) > 1000 else 'wgs84'
        print('système de coordonnées détecté : ' + crs)

    project = None
    if crs == 'lambert72':
        try:
            from pyproj import Transformer
        except ImportError:
            print('ERREUR : pyproj manquant — pip install pyproj', file=sys.stderr)
            sys.exit(1)
        tr = Transformer.from_crs(LAMBERT72, WGS84, always_xy=True)
        project = lambda x, y, z=None: tr.transform(x, y)  # noqa: E731

    geom_by_nis = {}
    for feat in feats:
        props = feat.get('properties') or {}
        nis = str(props.get('NIS5') or props.get('nis5') or '').strip()
        if not nis or not feat.get('geometry'):
            continue
        g = shape(feat['geometry'])
        if project is not None:
            g = sh_transform(project, g)
        if args.simplify > 0:
            s = g.simplify(args.simplify, preserve_topology=True)
            if not s.is_empty:
                g = s
        geom_by_nis[nis] = g

    # 3) une feature par code postal
    out_feats, sans_geom = [], []
    for pc in sorted(pc2nis):
        nis = pc2nis[pc]
        g = geom_by_nis.get(nis)
        if g is None:
            sans_geom.append(pc)
            continue
        geom = mapping(g)
        if args.precision >= 0:
            geom = dict(geom)
            geom['coordinates'] = round_coords(geom['coordinates'], args.precision)
        out_feats.append({'type': 'Feature',
                          'properties': {'postcode': pc, 'nis5': nis,
                                         'granularite': 'commune',
                                         'cp_partages': len(nis2pc[nis])},
                          'geometry': geom})

    exact = sum(1 for f in out_feats if f['properties']['cp_partages'] == 1)
    payload = json.dumps({
        'type': 'FeatureCollection',
        'meta': {
            'granularite': 'commune',
            'note': ('Contours à la granularité COMMUNALE : %d des %d codes postaux partagent leur '
                     'contour avec un autre code postal. Pour le contour exact du code postal, '
                     'reconstruire depuis les secteurs statistiques Statbel '
                     '(tools/build_postcode_polygons.py).' % (len(out_feats) - exact, len(out_feats))),
            'source': 'contours communaux officiels (Statbel, via mathiasleroy/Belgium-Geographic-Data, CC BY)',
            'codes_postaux': len(out_feats),
            'contours_exacts': exact,
        },
        'features': out_feats,
    }, separators=(',', ':')).encode('utf-8')

    out = args.out
    if args.gzip:
        if not out.endswith('.gz'):
            out += '.gz'
        with gzip.open(out, 'wb', compresslevel=9) as fh:
            fh.write(payload)
    else:
        with open(out, 'wb') as fh:
            fh.write(payload)

    size = os.path.getsize(out) / (1024.0 * 1024.0)
    print('%d codes postaux écrits dans %s (%.2f Mo)' % (len(out_feats), out, size))
    print('  dont %d au contour exact (seuls dans leur commune) et %d partagés'
          % (exact, len(out_feats) - exact))
    if sans_geom:
        print('  %d code(s) postal(aux) sans commune correspondante (ignorés) : %s'
              % (len(sans_geom), ', '.join(sans_geom[:10])))


if __name__ == '__main__':
    main()
