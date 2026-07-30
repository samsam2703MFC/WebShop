#!/usr/bin/env python3
"""Réduit un GeoJSON déjà construit — sans le reconstruire, sans rien inventer.

À QUOI ÇA SERT
--------------
Un GeoJSON produit par un SIG écrit les coordonnées en précision flottante
complète (jusqu'à 17 chiffres par nombre, soit une précision de l'ordre du
nanomètre : absurde pour une carte). Arrondir à 5 décimales (~1 m) puis
compresser divise le fichier par ~8 SANS déformation visible, et par bien plus
avec une simplification légère.

Utile pour faire passer les contours des codes postaux sous la limite de
GitHub (25 Mo via l'interface web, 100 Mo via git).

UTILISATION
-----------
    # cas courant : arrondi 5 décimales + gzip
    python3 shrink_geojson.py --in zipcodes_be_polygons.geojson

    # plus agressif (≈11 m + simplification ≈100 m ; nécessite shapely)
    python3 shrink_geojson.py --in zipcodes_be_polygons.geojson \\
        --precision 4 --simplify 0.001

Le fichier de sortie est <entrée>.gz (ou --out pour choisir le nom). L'API le
lit directement compressé (/geo/postcode-polygons décompresse à la volée).
shapely n'est requis QUE si vous utilisez --simplify.
"""

import argparse
import gzip
import json
import os
import sys


def round_coords(obj, nd):
    if isinstance(obj, (list, tuple)):
        if obj and isinstance(obj[0], (int, float)):
            return [round(float(v), nd) for v in obj]
        return [round_coords(v, nd) for v in obj]
    return obj


def human(n):
    return '%.1f Mo' % (n / (1024.0 * 1024.0)) if n >= 1024 * 1024 else '%.1f Ko' % (n / 1024.0)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--in', dest='src', required=True, help='GeoJSON d\'entrée (.geojson, .json, ou .gz)')
    ap.add_argument('--out', help='fichier de sortie (défaut : <entrée>.gz)')
    ap.add_argument('--precision', type=int, default=5,
                    help='décimales conservées (défaut 5 ≈ 1 m ; 4 ≈ 11 m)')
    ap.add_argument('--simplify', type=float, default=0.0,
                    help='tolérance de simplification en degrés (0 = aucune ; 0.001 ≈ 100 m). Nécessite shapely.')
    ap.add_argument('--no-gzip', action='store_true', help='écrire en clair au lieu de .gz')
    args = ap.parse_args()

    opener = gzip.open if args.src.endswith('.gz') else open
    with opener(args.src, 'rt', encoding='utf-8-sig') as fh:
        data = json.load(fh)

    feats = data.get('features')
    if not feats:
        print("ERREUR : aucune 'features' dans " + args.src, file=sys.stderr)
        sys.exit(1)

    simplifier = None
    if args.simplify > 0:
        try:
            from shapely.geometry import shape, mapping
        except ImportError:
            print('ERREUR : --simplify nécessite shapely (pip install shapely). '
                  'Relancez sans --simplify pour un simple arrondi + gzip.', file=sys.stderr)
            sys.exit(1)
        def simplifier(geom):                                     # noqa: E306
            g = shape(geom).simplify(args.simplify, preserve_topology=True)
            return mapping(g) if not g.is_empty else geom

    for feat in feats:
        geom = feat.get('geometry')
        if not geom or 'coordinates' not in geom:
            continue
        if simplifier is not None:
            geom = simplifier(geom)
            feat['geometry'] = geom
        geom['coordinates'] = round_coords(geom['coordinates'], args.precision)

    blob = json.dumps(data, separators=(',', ':')).encode('utf-8')

    out = args.out
    if not out:
        base = args.src[:-3] if args.src.endswith('.gz') else args.src
        out = base if args.no_gzip else base + '.gz'
    if args.no_gzip:
        with open(out, 'wb') as fh:
            fh.write(blob)
    else:
        if not out.endswith('.gz'):
            out += '.gz'
        with gzip.open(out, 'wb', compresslevel=9) as fh:
            fh.write(blob)

    before, after = os.path.getsize(args.src), os.path.getsize(out)
    print('%s : %s  →  %s : %s  (%.1f× plus petit, %d features)'
          % (os.path.basename(args.src), human(before), os.path.basename(out), human(after),
             (before / after if after else 0), len(feats)))
    if after > 25 * 1024 * 1024:
        print('⚠ encore > 25 Mo (limite de l\'upload web GitHub) : relancez avec '
              '--precision 4 --simplify 0.001', file=sys.stderr)


if __name__ == '__main__':
    main()
