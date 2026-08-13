#!/usr/bin/env python3
"""Fabrique le masque 1 bit du logo pour l'affiche PDF.

POURQUOI UN MASQUE PRÉ-CALCULÉ. Le logo est un PNG blanc à canal alpha ; le
serveur n'a ni bibliothèque d'images garantie (GD peut manquer) ni décodeur PNG
maison, et une affiche qui ne s'imprime pas parce qu'une extension manque n'est
pas une affiche. On convertit donc UNE FOIS le PNG en masque 1 bit — la seule
forme d'image que php-api/pdf.php sait poser — et on la range dans un fichier
PHP versionné.

Le logo étant blanc pur sur fond transparent, le canal alpha suffit : un pixel
couvert peint, un pixel transparent laisse le bordeaux du bandeau.

Usage (à rejouer si le logo change) :
    python3 php-api/tools/logo_mask.py public/logo-white.png php-api/logo_mask.php
"""
import base64
import sys
import zlib

import cv2


def main(src, dst):
    im = cv2.imread(src, cv2.IMREAD_UNCHANGED)
    if im is None:
        sys.exit(f"logo illisible : {src}")
    if im.shape[2] < 4:
        sys.exit("le logo doit porter un canal alpha (PNG transparent)")
    alpha = im[:, :, 3]
    h, w = alpha.shape
    octets_par_ligne = (w + 7) // 8
    brut = bytearray()
    for y in range(h):
        ligne = bytearray(octets_par_ligne)
        for x in range(w):
            if alpha[y, x] >= 128:                 # couvert ⇒ bit à 1 ⇒ peint
                ligne[x >> 3] |= 0x80 >> (x & 7)
        brut += ligne
    b64 = base64.b64encode(bytes(brut)).decode()
    with open(dst, "w", encoding="utf-8") as f:
        f.write(
            "<?php\n"
            "/* Masque 1 bit du logo — GÉNÉRÉ, ne pas éditer à la main.\n"
            f"   Source : {src} ({w}×{h})\n"
            "   Régénération : python3 php-api/tools/logo_mask.py "
            f"{src} {dst}\n"
            "   Un bit à 1 peint (la couleur est choisie à la pose), un bit à 0\n"
            "   laisse le fond — d'où un logo blanc sur le bandeau bordeaux sans\n"
            "   qu'aucune couleur ne soit stockée ici. */\n"
            f"return ['w' => {w}, 'h' => {h}, 'bits' => base64_decode('{b64}')];\n"
        )
    print(f"{dst} : {w}×{h}, {len(brut)} octets bruts "
          f"({len(zlib.compress(bytes(brut), 9))} compressés)")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "public/logo-white.png",
         sys.argv[2] if len(sys.argv) > 2 else "php-api/logo_mask.php")
