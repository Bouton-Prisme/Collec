# État du site pour la démonstration — 1er octobre 2026

Le parcours de démonstration fonctionne après les corrections décrites ci-dessous.
Le site reste un prototype de boutique : il ne permet pas encore de gérer de vraies
ventes de bout en bout.

Mise à jour : un onglet **Paiements** permet désormais de configurer les portefeuilles,
cryptos actives, taux, durée, formulaire et consignes. Les formulaires admin disposent
d'une protection CSRF. Voir `PAIEMENTS-ET-COMMANDES.md` pour le périmètre et la suite.

## Catalogue configuré

| Ordre | Produit | Prix | Présentation de démonstration |
| --- | --- | --- | --- |
| 1 | Starter Pack | 5 USD | 12 illustrations, découverte |
| 2 | Bronze Pack | 9 USD | 30 illustrations, abstraits et textures |
| 3 | Silver Pack | 15 USD | 60 illustrations, ordinateur et mobile |
| 4 | Gold Pack | 25 USD | 120 illustrations, dossiers thématiques |
| 5 | Ruby Pack | 49 USD | 250 illustrations, variations de couleurs |
| 6 | Diamond Pack | 75 USD | 500 illustrations, collection complète |

Les noms, descriptifs, prix et quantités constituent un catalogue fictif crédible pour
la démo ; les archives de téléchargement ne sont pas créées. Les six visuels Pack1 à
Pack6 sont réutilisés et disponibles dans la médiathèque. Les identifiants existants
ont été conservés, notamment 7 pour Starter. La configuration de la roulette est conservée.

## Fonctions vérifiées

Tests exécutés dans un Chrome local isolé, sur le serveur PHP `127.0.0.1:8080`.

| Fonction | Résultat / limite |
| --- | --- |
| Catalogue | Six produits, prix, badges, descriptions et images chargés |
| Images | Agrandissement et fermeture par Échap ; navigation d'une galerie à deux images vérifiée avec un produit temporaire |
| Sélection et panier | Sélection, remplacement du produit, persistance entre pages et suppression vérifiés |
| Panier vide | Bloc de paiement et lien de continuation masqués |
| BTC, ETH, XMR | Changement de devise et montants illustratifs vérifiés |
| QR code | Génération vérifiée ; adresses fictives, aucune transaction effectuée |
| Copie du montant | Presse-papiers et message de succès vérifiés |
| Récapitulatif | Nom, prix, quantité, devise, montant crypto et formulaire utilisent la sélection courante |
| Formulaire | Envoi simulé testé avec des données fictives ; aucune requête POST envoyée |
| Roulette | Chargement des médias et tirage vérifiés ; six packs par défaut si la liste configurée est vide |
| Admin | Mauvais mot de passe, connexion, déconnexion et refus d'une suppression sans authentification vérifiés |
| Produits admin | Création, modification, suppression et conservation des médias vérifiées |
| Ordre d'affichage | Sauvegarde persistante, tri accueil/paiement, égalités et rejet d'un ordre négatif côté serveur vérifiés |
| Médiathèque | Recherche, sélection de deux images et sauvegarde vérifiées |
| Roulette admin | Sauvegarde sans altération des médias existants vérifiée |
| Petit écran | Accueil, paiement, récapitulatif, roulette et pages d'information testés à 320 et 375 px, sans débordement horizontal |
| Erreurs catalogue | Catalogue vide ou indisponible : message explicite, aucune commande fictive proposée |
| URL invalide | Devise inconnue ramenée à BTC ; formulaire désactivé pour un produit inconnu |

Aucune erreur JavaScript non interceptée sur les parcours testés. Syntaxe PHP et
JavaScript vérifiée. Les six images de produits existent sur disque.
Les produits temporaires et changements d'ordre des tests ont été annulés après vérification.

## Corrections apportées

- Champ **Ordre d'affichage** à la création et à la modification, validé côté serveur.
  Les nombres les plus petits passent en premier ; en cas d'égalité, tri par identifiant.
- Catalogue commun au récapitulatif : suppression des anciennes listes de produits
  codées en dur qui donnaient des noms et prix erronés après modification admin.
- Correction de l'initialisation du paiement qui pouvait utiliser les taux avant
  leur déclaration lors de l'arrivée depuis un lien Select.
- Paiement et récapitulatif adaptés aux petits écrans ; cartes suffisamment hautes
  pour contenir les descriptions ; panier fermé au chargement.
- Liens Support, Privacy et Terms corrigés dans le parcours de commande, chargement
  Tailwind corrigé sur ces pages, et lien de retour vers le catalogue ajouté.
- Accès à la roulette depuis l'accueil et redirection de `/` vers l'accueil.
- Copie : un échec du presse-papiers n'affiche plus un faux message de réussite.
- Mode démo explicite, taux fixes illustratifs, formulaire simulé sans envoi externe.

## Ce qui manque pour de vraies ventes

1. **Paiement réel et vérification serveur.** Les portefeuilles sont des exemples.
   Le QR code, le compte à rebours et le TXID saisi ne prouvent aucun paiement.
   Il faut créer des factures et contrôler montant, devise et confirmations côté serveur.
   Les prix et données stockés dans le navigateur ne doivent pas faire foi.
2. **Commandes et administration commerciale.** Aucune base de commandes, aucun numéro
   de commande fiable, historique, statut, tableau de traitement ou rapprochement paiement.
3. **Livraison.** Il manque les archives réelles, la gestion des droits d'usage et
   les liens de téléchargement. Aucun envoi de fichiers n'est actuellement intégré.
4. **Envoi réel du formulaire.** Une adresse Formspree est présente dans le code,
   mais son destinataire, sa configuration, les pièces jointes et la réception effective
   n'ont pas été vérifiés. Aucun message n'a été envoyé pendant les tests.
5. **Sécurité admin avant mise en ligne.** Mot de passe `admin` codé en dur,
   absence de limitation des tentatives. La protection CSRF est désormais en place. Prévoir des secrets
   hors du code, HTTPS, des sessions protégées et des sauvegardes du catalogue.
6. **Contenus d'information à finaliser.** Les pages actuelles sont sommaires.
   Compléter l'identité de l'exploitant, les conditions commerciales et la description
   du traitement réel des données. Certaines phrases existantes sur l'absence de données
   personnelles sont incohérentes avec un formulaire demandant une adresse e-mail.
7. **Dépendances et robustesse.** Tailwind et le générateur QR sont chargés depuis des
   CDN. La démonstration n'est pas entièrement hors ligne. Prévoir des ressources locales
   et une gestion des erreurs d'écriture/concurrence plus robuste qu'un simple fichier JSON.

Le panier contient volontairement **un seul produit à la fois** : sélectionner un autre
pack remplace le précédent. Aucun compte client, recherche, filtre, code promotionnel
ou panier multiproduit n'est présent. Ces fonctions sont optionnelles selon le besoin.

Le choix du mode manuel dans **Admin → Paiements** n'implémente aucun de ces éléments.
Il active l'envoi vers le formulaire Formspree renseigné par l'administrateur :
ce réglage seul ne doit pas être considéré comme un lancement automatisé.

## Rejouer les tests

Serveur lancé via `DEMARRER-SITE.cmd`, Python avec Playwright installé, Chrome à
`C:/Program Files/Google/Chrome/Application/chrome.exe` :

```powershell
python tests/check_demo.py
python tests/check_admin.py
python tests/check_payments.py
php -l admin.php
node --check order-summary.js
```

Ne pas éditer le catalogue pendant le test admin : il le modifie temporairement puis
restaure ses octets initiaux dans un bloc `finally`. Les captures sont enregistrées dans
`%TEMP%/bitshop-demo-checks/`.
