# Paiements, commandes et livraison

## Ce qui fonctionne maintenant

L'onglet **Admin → Paiements** configure le parcours existant : activation globale,
mode démo ou manuel, cryptos actives, devise par défaut, libellés internes des
portefeuilles, adresses de réception, taux, délai d'expiration, support, instructions,
formulaire Formspree et pièce jointe obligatoire ou facultative.

Chaque crypto dispose d'une adresse sur son réseau principal. Ajouter une nouvelle
crypto ou un autre réseau nécessitera une intégration spécifique (validation
d'adresse, précision, conversion, QR code et, ensuite, vérification du paiement).
Les réglages restent en démonstration par défaut. Aucun portefeuille réel n'a été
inventé, aucune transaction effectuée et aucun e-mail envoyé pendant les tests.

Les réglages privés restent dans `config/payment.local.php`, ignoré par Git ; les
noms internes des comptes sont retirés de la réponse publique. Les sauvegardes
admin sont protégées par un jeton CSRF. Le contrôle des adresses vérifie leur
format et le réseau attendu, pas leur checksum complet ni leur propriétaire.

## Ce que signifie « vérifier un paiement »

Un TXID saisi ou une capture d'écran n'est pas une confirmation. Il faut vérifier
que la transaction existe, arrive à la bonne destination, sur le bon réseau, pour
le montant attendu et avec le nombre de confirmations retenu. Il faut aussi empêcher
qu'une même transaction valide plusieurs commandes et traiter les paiements
incomplets, trop élevés ou reçus après expiration.

Deux possibilités :

- **Validation manuelle** : la commande est enregistrée « en attente » ; tu contrôles
  la réception dans ton portefeuille, puis tu la marques payée dans l'administration.
  La livraison peut ensuite être automatique. Aucun prestataire de paiement n'est
  indispensable pour ce parcours.
- **Validation automatique** : le serveur crée une facture chez un prestataire ou
  un service auto-hébergé. Une notification signée confirme le paiement ; le serveur
  contrôle cette notification et met à jour la commande. Les notifications répétées
  doivent être traitées sans envoyer plusieurs livraisons.

BTCPay Server fournit par exemple une API de facturation et des notifications
`InvoiceProcessing` / `InvoiceSettled`. Son
[guide d'intégration e-commerce](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/)
décrit ce parcours. Ce n'est pas un choix de prestataire déjà intégré au site.
Il faut vérifier la couverture des cryptos retenues : Bitcoin est central dans
BTCPay et Monero passe par un plugin ; les autres cryptos nécessitent une solution
adaptée. Voir la [documentation des cryptos prises en charge](https://docs.btcpayserver.org/FAQ/Altcoin/).

## Ce qu'il faut développer pour les commandes

Une petite base de données (SQLite pour commencer) avec :

- Numéro de commande, e-mail de livraison, produit et copie du prix au moment de l'achat.
- Devise, réseau, montant attendu, référence de facture ou TXID, dates et échéance.
- Statut : en attente, en vérification, payée, livrée, expirée, annulée ou à examiner.
- Historique des actions et des tentatives de livraison.

À la validation du panier, le serveur relit le catalogue et calcule lui-même les
prix. Un changement du stockage local du navigateur ne doit pas modifier une commande.
L'administration doit permettre de chercher une commande, la consulter, contrôler
un paiement, valider/refuser et relancer une livraison. Le client doit pouvoir
consulter son statut avec un lien privé difficile à deviner, sans compte obligatoire.

## Ce qu'il faut développer pour les fichiers

1. Associer une archive réelle à chaque produit dans l'admin.
2. Stocker les archives hors de l'accès public ; pas dans `media/`.
3. Après validation du paiement, créer un lien de téléchargement individuel,
   aléatoire, limité dans le temps et éventuellement en nombre de téléchargements.
4. Envoyer ce lien par un service d'e-mail ou SMTP configuré côté serveur.
5. Enregistrer l'envoi et les erreurs ; permettre une relance et une révocation.

Le contrôle d'accès doit porter sur chaque téléchargement : connaître le chemin
du fichier ou changer un numéro de commande ne doit pas suffire pour le récupérer.

## Première version possible

Pour commencer sans prestataire automatique : **création de commande → contrôle
manuel du paiement dans l'admin → e-mail et lien de téléchargement sécurisé**.
L'automatisation des paiements pourra ensuite remplacer la validation manuelle
sans changer les commandes ni la livraison.

Pour réaliser cette étape, il faudra disposer des archives à vendre, des adresses
publiques réelles et d'un compte d'envoi d'e-mails. Pour l'option automatique,
il faudra aussi choisir le prestataire/service et fournir ses accès côté serveur.
L'hébergement doit exécuter PHP, accéder à une base et recevoir les notifications
HTTPS ; GitHub Pages seul ne peut pas héberger ce backend PHP.

## Vérifications réalisées sur les réglages

`python tests/check_payments.py` teste sauvegarde et rechargement, activation des
cryptos, devise par défaut, QR/montants issus des adresses configurées, taux fixes,
taux en ligne simulés et panne, expiration et actualisation, formulaire périmé après
modification, pièce jointe obligatoire, pause, refus CSRF, fichier corrompu, confidentialité
des libellés et rendu à 320/375 px. Les tests restaurent la configuration de départ.
Les requêtes d'envoi Formspree sont bloquées pendant les tests ; aucune n'a été tentée.
