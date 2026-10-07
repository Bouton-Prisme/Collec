# Ouvrir le site en local

Double-cliquez sur **DEMARRER-SITE.cmd**, puis utilisez le bouton **Admin**.
Le lanceur utilise PHP et ouvre http://127.0.0.1:8080/home.html.
Il peut etre relance : il reutilise le serveur de ce dossier s'il tourne deja.

Administration directe : http://127.0.0.1:8080/admin.php.

Live Server (port 5500) et l'ouverture directe des fichiers HTML n'executent
pas PHP : `admin.php` peut alors etre telecharge au lieu d'afficher le formulaire.
Pour modifier les produits, utilisez l'adresse du serveur PHP ci-dessus.

PHP doit etre disponible dans le PATH ou dans `C:\php\php.exe`.
Le serveur tourne en arriere-plan, uniquement sur cet ordinateur, jusqu'a
l'arret du processus PHP ou au redemarrage de Windows.

## Démonstration

Le catalogue contient six packs d'illustrations numériques de démonstration :
Starter (5 USD), Bronze (9 USD), Silver (15 USD), Gold (25 USD),
Ruby (49 USD) et Diamond (75 USD). Leurs fichiers téléchargeables ne sont pas fournis.
Les illustrations des emballages sont dans `media/packs/`, accessibles dans la médiathèque.

Connexion admin : mot de passe local actuel `admin`.
Le menu latéral propose **Products**, **Payments**, **Commandes** et **Roulette**.
Dans **Products**, créez un produit avec le formulaire, ou cliquez sur **Éditer**
puis **Enregistrer le produit** pour le modifier. Les images peuvent être choisies
dans la médiathèque ou importées (PNG, JPG, WEBP, jusqu'à 5 Mo).
Le lien de paiement facultatif remplace le bouton de sélection de la boutique ;
laissez-le vide pour conserver le parcours de paiement habituel.

Dans **Ordre d'affichage**, le plus petit nombre apparaît en premier.
Glissez la poignée d'un produit pour changer sa position : l'ordre est enregistré
automatiquement. La poignée fonctionne aussi au toucher et avec les flèches haut/bas
du clavier. Effacez la recherche et choisissez le tri **Ordre d'affichage** pour déplacer.
Le bouton **Actif / Inactif** masque ou réaffiche immédiatement le produit lors du
prochain chargement de l'accueil ou du paiement. Recherche et tri ne modifient pas
l'ordre enregistré.
En cas d'égalité, l'identifiant du produit départage les positions.
L'ordre ne modifie pas les identifiants ni les liens existants.

Parcours conseillé : accueil → Select → choix BTC/ETH/XMR → e-mail fictif →
Create demo order → conserver le lien privé → TXID fictif → Save demo reference.
Le mode démo (onglet **Payments** de l'administration) utilise des taux illustratifs
fixes et des adresses fictives. La commande de test et sa référence sont enregistrées
sur le site, sans contacter Formspree. Aucun paiement, e-mail ou fichier n'est envoyé.
Dans **Commandes**, vérifier les informations, changer le statut et laisser un message
visible par le client sur sa page de suivi. Les paiements et livraisons restent manuels.

Une connexion Internet reste nécessaire pour Tailwind sur les pages existantes.
Le QR code de la page de suivi est fourni localement.
Voir `PAIEMENTS-ET-COMMANDES.md` pour le parcours et les tests actuels ;
`AUDIT-DEMO.md` conserve l'historique de la démonstration du 1er octobre.
Choisir le mode manuel ne suffit pas à automatiser les paiements ou la livraison.

## Réglages de paiement

Ouvrir `http://127.0.0.1:8080/admin.php?tab=payment`.

- Autoriser ou suspendre le paiement ; choisir démonstration ou vérification manuelle.
- Activer BTC, ETH et/ou XMR et choisir une crypto active par défaut.
- Donner un nom interne à chaque portefeuille et renseigner son adresse publique.
  Les réseaux pris en charge sont Bitcoin mainnet, Ethereum mainnet et Monero mainnet.
- Régler la durée du montant affiché, les taux fixes ou les taux CoinGecko.
- Définir l'e-mail de support, les instructions et le délai de traitement manuel.

En démo, les adresses affichées sont fictives même si des adresses réelles sont
enregistrées. En mode manuel, renseigner les adresses des cryptos actives ;
aucun compte Formspree n'est nécessaire. Les références restent dans les commandes.
L'e-mail de support sert au contact client, sans envoi automatique de notification.

La configuration est enregistrée dans `config/payment.local.php`, protégé contre
l'accès HTTP et **exclu de Git**. Faire une sauvegarde privée de ce fichier et le
reconfigurer sur chaque serveur. `payment-config.php` ne publie que les paramètres
nécessaires aux visiteurs ; les noms internes de comptes ne sont pas exposés.
Ne jamais saisir de clé privée de portefeuille ou de phrase de récupération.

Les commandes conservent leur prix, montant crypto, adresse, échéance et délai annoncés
au moment de leur création côté serveur. Un montant expiré ne doit plus être payé,
mais le client peut encore déclarer un transfert déjà effectué pour vérification.
Les données de commande sont dans `config/orders.local.php`, protégées par PHP et
exclues de Git. Les sauvegarder en privé avec la configuration.

## Dépôt GitHub

Dépôt : https://github.com/Bouton-Prisme/Collec.git — branche `main`.
Les réglages locaux, archives `SAVES/`, projet distinct `chatteroulette/`, anciens
brouillons et fichiers PSD sont exclus du versionnement.

L'ancien endpoint `xmr-price.php`, non utilisé par le paiement actuel, lit désormais
la variable d'environnement `COINMARKETCAP_API_KEY`. Aucune clé API n'est incluse
dans le dépôt. Le paiement actuel utilise CoinGecko ou les taux saisis dans l'admin.

Voir `PAIEMENTS-ET-COMMANDES.md` pour la suite du développement.
