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
Dans **Ordre d'affichage**, le plus petit nombre apparaît en premier.
Cliquez sur **Save products**, puis rechargez l'accueil ou le paiement.
En cas d'égalité, l'identifiant du produit départage les positions.
L'ordre ne modifie pas les identifiants ni les liens existants.

Parcours conseillé : accueil → Select → choix BTC/ETH/XMR → Continue demo →
saisir un e-mail fictif et un TXID fictif → Simulate submission.
Le mode démo (onglet **Paiements** de l'administration) utilise des taux illustratifs
fixes et des adresses fictives. Le formulaire simule l'envoi, sans contacter Formspree.
Il n'effectue aucun paiement et ne livre aucun fichier.

Une connexion Internet reste nécessaire pour Tailwind et la bibliothèque QR code.
Voir `AUDIT-DEMO.md` pour les tests réalisés et les éléments restant à développer.
Choisir le mode manuel ne suffit pas à automatiser les paiements ou la livraison.

## Réglages de paiement

Ouvrir `http://127.0.0.1:8080/admin.php?tab=payment`.

- Autoriser ou suspendre le paiement ; choisir démonstration ou vérification manuelle.
- Activer BTC, ETH et/ou XMR et choisir une crypto active par défaut.
- Donner un nom interne à chaque portefeuille et renseigner son adresse publique.
  Les réseaux pris en charge sont Bitcoin mainnet, Ethereum mainnet et Monero mainnet.
- Régler la durée du montant affiché, les taux fixes ou les taux CoinGecko.
- Définir l'e-mail de support, l'identifiant Formspree, les instructions, le délai
  de traitement et l'obligation d'une pièce jointe en plus du TXID.

En démo, les adresses affichées sont fictives même si des adresses réelles sont
enregistrées. Aucun formulaire n'est envoyé. En mode manuel, les adresses des cryptos
actives et un identifiant Formspree sont nécessaires. Le destinataire des justificatifs
se règle dans le compte Formspree, pas dans le champ « e-mail du support ».
L'acceptation des pièces jointes dépend de la configuration de ce compte.

La configuration est enregistrée dans `config/payment.local.php`, protégé contre
l'accès HTTP et **exclu de Git**. Faire une sauvegarde privée de ce fichier et le
reconfigurer sur chaque serveur. `payment-config.php` ne publie que les paramètres
nécessaires aux visiteurs ; les noms internes de comptes ne sont pas exposés.
Ne jamais saisir de clé privée de portefeuille ou de phrase de récupération.

Les réglages sont relus à chaque chargement ; le formulaire recontrôle leur version
avant l'envoi. Une configuration modifiée ou un montant expiré oblige à revenir au
paiement. Ces contrôles dans le navigateur ne remplacent pas une facture serveur.

## Dépôt GitHub

Dépôt : https://github.com/Bouton-Prisme/Collec.git — branche `main`.
Les réglages locaux, archives `SAVES/`, projet distinct `chatteroulette/`, anciens
brouillons et fichiers PSD sont exclus du versionnement.

L'ancien endpoint `xmr-price.php`, non utilisé par le paiement actuel, lit désormais
la variable d'environnement `COINMARKETCAP_API_KEY`. Aucune clé API n'est incluse
dans le dépôt. Le paiement actuel utilise CoinGecko ou les taux saisis dans l'admin.

Voir `PAIEMENTS-ET-COMMANDES.md` pour la suite du développement.
