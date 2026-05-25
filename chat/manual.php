<?php

/**
 * Base de connaissances marketing pour le copilote vitrine.
 *
 * Volontairement plus courte et orientee "prospect" que le mode d'emploi
 * complet de l'app : ici on presente GuestLucky, on donne les prix, on
 * repond aux questions frequentes. Pas de detail sur les icones / chemins
 * de menu : le visiteur n'a pas encore de compte.
 */

function guestlucky_marketing_manual(): string
{
    return <<<'MANUEL'
# GUESTLUCKY — PRESENTATION GENERALE

GuestLucky est un SaaS francais qui aide les conciergeries de location courte
duree (Airbnb, Booking, Vrbo, Abritel) a gerer leur activite sans s'arracher
les cheveux : un seul outil pour tout piloter (annonces, calendriers, prix,
voyageurs, equipe ménage, facturation).

Notre mission : faire gagner du temps aux gestionnaires, augmenter leurs
revenus, et leur eviter d'avoir 10 onglets ouverts en permanence.

# A QUI CA S'ADRESSE

- Conciergeries de location courte duree (de 2 a 200+ logements)
- Proprietaires multi-logements qui veulent se professionnaliser
- Sous-locataires en gestion de mandats
- Equipes avec des prestataires (femmes de menage, techniciens, etc.)

# FONCTIONNALITES PRINCIPALES

## Channel Manager
Synchronise automatiquement les calendriers, prix et reservations entre
Airbnb, Booking.com, Vrbo, Abritel et le site direct. Plus de double-booking,
plus de mise a jour manuelle sur 5 sites differents.

## Tarification dynamique
Integration native avec PriceLabs (un des leaders du revenue management) +
nos propres regles dynamiques (last minute, early bird, comble-trou, saison,
durée de séjour, occupation...).

## Messagerie IA voyageurs
Une IA repond automatiquement aux voyageurs 24h/24 (questions check-in, wifi,
parking, depart...). Configurable par annonce. Quota gratuit Gemini ~1M
tokens/mois inclus pour les tests.

## Equipe et missions
Gestion des prestataires (femmes de menage, techniciens, managers) avec
assignation automatique des missions, suivi de paiement, application mobile
dediee aux prestataires.

## Incidents
Suivi des problemes (degradations, casses, plaintes voisinage) avec
notification automatique au proprietaire (conforme loi Hoguet).

## Stocks et inventaire
Gestion des consommables (draps, produits, articles fournis) par magasin,
avec entrees, sorties, transferts, alertes seuil bas.

## Booking Engine (site direct)
Permet de recevoir des reservations directes sans commission d'OTA, avec
moteur de reservation et paiement Stripe integre.

## LuckyCover (assurance caution)
Assurance dommages dediee a la location courte duree. 3 formules :
- Cover Dommage : 65 €/an
- Cover Dommage + Menage : 89 €/an
- Cover Serenite : 109 €/an
Plafond legal revente : 583,15 € TTC. Active par logement, gere depuis
l'interface GuestLucky.

## Lucky Copilot (IA assistant)
Un assistant IA integre a l'app qui aide a piloter la conciergerie en
langage naturel : "montre-moi les reservations de la semaine", "cree un
incident sur le logement X", "modifie le prix"...

## Facturation electronique Factur-X (Q3 2026)
Conforme aux obligations 2026 / 2027 (norme EN16931 / Factur-X). Permet
d'emettre et de recevoir des factures electroniques legales. Partenaire
agree DGFiP.

## Gouvernance
Rapports mensuels automatiques aux proprietaires, validation tacite 48h,
conforme aux exigences legales du secteur (loi Hoguet).

# TARIFS

## GRATUIT
- Jusqu'a 2 logements
- Fonctionnalites de base
- Ideal pour tester l'outil

## PREMIUM
- ERP complet sans Channel Manager
- 5,99 € HT / mois / logement (mensuel)
- 59,99 € HT / an / logement (annuel, 2 mois offerts)
- Pour les conciergeries qui n'ont pas besoin de la synchronisation OTA

## PRO
- Tout Premium + Channel Manager + IA voyageurs + integration PriceLabs
- 9,99 € HT / mois / logement (mensuel)
- 99,99 € HT / an / logement (annuel, 2 mois offerts)
- L'offre recommandee pour les conciergeries actives

## PERSONNALISE
- Pour 50+ logements
- Tarification adaptee, support dedie
- Sur devis

## OPTIONS PAYANTES (add-ons)
- Booking Engine : +5 € HT / logement / mois (Premium et Pro)
- LuckyCover : 65 € / an minimum par logement (Pro uniquement)
- Facturation electronique : disponibilite Q3 2026, tarif a venir
- PriceLabs : connexion gratuite cote GuestLucky, abonnement PriceLabs
  separe (a souscrire directement chez PriceLabs)

## ONBOARDING (frais uniques de mise en route)
- 0 a 10 logements : 47 €
- 11 a 49 logements : 97 €
- 50+ logements : 197 €
Inclut : configuration initiale, formation, accompagnement des premieres
connexions OTA. Aucun engagement, paiement unique.

# PARCOURS POUR S'INSCRIRE OU TESTER

1. Aller sur guestlucky.com
2. Cliquer sur "Inscription" / "Commencer gratuitement"
3. Creer son compte (gratuit, jusqu'a 2 logements pour tester)
4. Connecter ses annonces (Airbnb / Booking via le partenaire ChannelSync)
5. Profiter de l'app

Pour parler a un conseiller avant de se lancer ou demander une demo :
Lien d'appel decouverte (15 minutes, gratuit, sans engagement) :
https://www.guestlucky.com/rdv

# QUESTIONS FREQUENTES

## Y a-t-il un engagement ?
Non, aucun engagement. Sans engagement, sans duree minimum. Tu peux annuler
ton abonnement quand tu veux.

## Combien de temps pour mettre en place ?
- Compte cree en 5 minutes
- Premiere annonce connectee en 30 minutes
- Synchronisation complete des calendriers : 24 a 48h apres la connexion
  (delai impose par les OTA)
- Setup complet d'une conciergerie : entre 1 et 5 jours selon la taille

## Quelles plateformes sont compatibles ?
Airbnb, Booking.com, Vrbo, Abritel, et n'importe quel site qui supporte le
format iCal en lecture/ecriture. Le site direct (Booking Engine) est aussi
disponible en option.

## La connexion Booking.com est-elle compliquee ?
Elle se fait en passant par notre partenaire de connexion (ChannelSync), avec
un code de verification envoye au proprietaire. Notre support accompagne
chaque conciergerie pour le premier branchement. Une fois en place, tout est
automatique. Compter 24 a 48h pour que le calendrier Booking apparaisse.

## La data est-elle hebergee en France ?
Oui. Donnees hebergees en Europe, conformes RGPD. Nous travaillons egalement
avec des partenaires europeens (notamment pour l'IA via Vertex AI Europe).

## Y a-t-il une app mobile ?
Oui, principalement destinee aux prestataires (femmes de menage,
techniciens) : ils voient leurs missions, signalent les incidents, prennent
des photos avant/apres. Les gestionnaires utilisent surtout la version web
(plus complete).

## Quelle est la difference entre Premium et Pro ?
- Premium = ERP de gestion (logements, reservations, missions, facturation)
- Pro = Premium + le Channel Manager (synchronisation OTA), l'IA voyageurs
  et l'integration PriceLabs.
Pour la majorite des conciergeries qui ont Airbnb + Booking, c'est l'offre
Pro qui est recommandee.

## Comment fonctionne LuckyCover ?
C'est une assurance caution dediee a la location courte duree. Le
proprietaire active LuckyCover sur son logement (via son espace
proprietaire), choisit une formule, paie l'abonnement annuel par Stripe. En
cas de sinistre (degat, casse), il declare via son interface, et notre
partenaire assurance prend en charge l'indemnisation.

## Et l'IA voyageurs, c'est quoi ?
Une IA qui repond automatiquement aux messages des voyageurs 24h/24 sur
Airbnb, Booking et les autres canaux. Configurable annonce par annonce, avec
un ton personnalisable (tutoiement / vouvoiement, langue, style). La
conciergerie reste en controle : possibilite de relire avant envoi ou de
laisser en automatique.

## C'est quoi le copilote IA (Lucky Copilot) ?
Un assistant integre a l'app, accessible depuis n'importe quelle page. On
lui parle en langage naturel ("montre-moi les reservations de la semaine",
"cree un incident sur le logement X"...) et il agit a notre place. Permet
d'aller beaucoup plus vite au quotidien. Inclus avec l'abonnement Pro.

## Vous avez un programme partenaire / parrainage ?
Oui, on a un programme de recommandation. Contacte le support ou prends RDV
via le lien iClosed pour qu'on t'en parle.

# COMMENT CONTACTER GUESTLUCKY

- Site : https://guestlucky.com
- Appel decouverte (gratuit, 15 min) : https://www.guestlucky.com/rdv
- Email : via le formulaire de contact sur guestlucky.com

# CE QU'IL FAUT EVITER DE FAIRE EN TANT QUE COPILOTE VITRINE

- Ne pas pretendre avoir acces au compte du visiteur (on ne sait rien de lui)
- Ne pas inventer de chiffres / fonctionnalites / prix qui ne sont pas
  dans cette base
- Ne pas donner de conseils juridiques, comptables, fiscaux precis (rediriger
  vers un professionnel ou vers un appel decouverte)
- Si le visiteur pose une question pointue technique (par exemple "comment
  je connecte mon annonce Booking precisement"), inviter a prendre un appel
  decouverte ou a creer un compte pour acceder a l'assistance.
MANUEL;
}
