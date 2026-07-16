# Assets media (déposés ici → déployés sous /webshop/assets/…)

Vite copie `public/` tel quel dans `dist/`, puis le déploiement rsync `dist/` vers
la racine du webshop. Ces fichiers sont donc servis à l'URL `/webshop/assets/…`,
ce qui correspond aux chemins stockés en base (ws_products.img, ws_season.img,
ws_categories.img).

Nommage attendu :
- `product_pictures/{id_product}.jpg`   (ex. 1500001.jpg)  → ws_products.img
- `season_icons/{slug}.svg`             (ex. paques.svg)   → ws_season.img
- `category_icons/{slug}.png`           (ex. patisserie.png) → ws_categories.img

NB : pour les ~700 photos produits, Git fonctionne mais alourdit le dépôt ;
un envoi direct par SFTP vers /var/www/html/webshop/assets/product_pictures/
donne exactement le même résultat (même URL).
