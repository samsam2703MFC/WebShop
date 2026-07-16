# Photos produits — déposez `{id_product}.jpg` ici

- Format : **JPG**, carré ~**800 × 800 px** (photo couleur, pas de filtre).
- Nom = l'id du produit. URL finale : `/webshop/assets/product_pictures/{id}.jpg`
  (= `ws_products.img`).

## Produits du jeu de test (exemples)
- 2110001.jpg  (Boulettes — menu)
- 1500001.jpg  (Gâteau anniversaire — bundle)
- 1110101.jpg  (Gâteau au miel)
- 1000001.jpg  (Hamburger)
- 3400001.jpg  (Coca-Cola)

> Pour les ~700 photos, l'envoi direct en SFTP vers
> `/var/www/html/webshop/assets/product_pictures/` évite d'alourdir le dépôt Git
> (même URL au final).
