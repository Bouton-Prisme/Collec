# Paiements et commandes — parcours manuel

Le parcours ne nécessite plus Formspree ni prestataire de paiement payant.
Il conserve la vérification manuelle de la réception dans le portefeuille.

## Parcours client

1. Choisir un produit, une crypto et saisir un e-mail de contact.
2. Créer la commande avant de payer. Le serveur relit le catalogue et calcule
   le prix et le montant crypto ; les montants du navigateur ne font pas foi.
3. Consulter le numéro de commande, le réseau, le montant exact, l'adresse,
   le QR code et le bouton d'ouverture d'un portefeuille compatible.
4. Sauvegarder le lien privé de suivi. Il donne accès aux détails de la commande :
   il ne doit pas être partagé publiquement. Aucun e-mail automatique n'est envoyé.
5. Après le transfert, saisir le TXID et éventuellement un message. La référence
   est enregistrée « À vérifier ». Elle ne prouve pas la réception du paiement.
6. Actualiser la page de suivi pour consulter la décision et le message de la boutique.

Le mode démo crée des commandes de test explicitement identifiées : ne pas envoyer
 de crypto. Les références fictives sont acceptées dans ce mode uniquement.

Le montant et l'adresse d'une commande sont conservés même si les réglages changent.
Après expiration, les instructions de transfert sont masquées. Le client peut encore
soumettre la référence d'un transfert déjà effectué ; il ne doit pas repayer.
Le même TXID ne peut pas être déclaré pour deux commandes du même mode et de la même
crypto. Les renvois d'une même requête de création dans la même session sont idempotents.

## Administration

Dans **Admin → Commandes**, rechercher par numéro, e-mail ou TXID et ouvrir la commande.
Contrôler le réseau, la destination, le montant réellement reçu et les confirmations
 dans le portefeuille, puis confirmer le passage à **Paiement validé**.
Après avoir effectivement livré le produit, passer à **Livrée**.
Un message visible par le client peut préciser les étapes suivantes ou la livraison.
Les modifications de statut sont conservées dans l'historique. Les statuts payée et
livrée exigent une confirmation explicite de l'administrateur.

L'onglet **Paiements** conserve les portefeuilles, cryptos actives, source des taux,
durée, instructions, support et délai annoncé. Formspree et les pièces jointes ne
font plus partie du parcours. Suspendre les nouvelles commandes ne bloque pas les
liens de suivi des commandes existantes.

## Stockage et exploitation

- PHP 64 bits, sessions et accès en écriture à `config/` sont nécessaires.
- `config/orders.local.php` contient les commandes et données de contact. Un garde
  PHP interdit sa lecture HTTP ; il est ignoré par Git. Sauvegarder ce fichier en privé.
- Les écritures utilisent un verrou séparé et un remplacement atomique. Une corruption
  bloque la lecture et les créations au lieu d'effacer les commandes.
- Les jetons privés contiennent 256 bits aléatoires ; seul leur hash est stocké.
  Conserver le lien est nécessaire : aucun renvoi par e-mail n'est implémenté.
- Une limite de 20 créations par heure et par session limite les créations accidentelles.
  Ce n'est pas une protection complète contre les abus distribués.
- Les taux en ligne sont lus par le serveur auprès de CoinGecko. PHP doit pouvoir
  accéder à HTTPS avec vérification des certificats. En cas d'échec, aucune commande
  n'est créée et aucun taux fictif n'est utilisé. Les taux fixes restent disponibles.
- La bibliothèque QR existante est fournie localement dans `vendor/`, avec sa licence.
  La page de suivi ne charge aucun script externe et n'envoie pas son URL en Referer.
- Le stockage fichier convient à une petite V1 ; prévoir une base et des limites
  serveur adaptées avant une montée en charge.

## Limites restantes

La validation blockchain, l'envoi d'e-mails et la livraison de fichiers ne sont pas
 automatisés. Il faut disposer des produits et effectuer ces opérations manuellement.
Le mot de passe administrateur existant reste celui de la démo : le remplacer et
configurer HTTPS avant exposition publique. Les conditions commerciales et la politique
 de conservation des données restent à finaliser par l'exploitant.

## Vérification

`python tests/check_orders.py` lance un serveur PHP et Chrome dans une copie temporaire :
création, prix serveur, arrondis, nouvelles tentatives, liens privés, CSRF, TXID dupliqué,
référence après expiration, validation admin, livraison, panne de stockage et affichage
à 320/375/1440 px. Aucune transaction ni notification externe n'est effectuée.
`tests/check_submission.py` est un alias de cette suite.
Les suites `check_demo.py`, `check_payments.py`, `check_ui.py` et `check_admin.py`
complètent les contrôles sur le serveur local habituel.
