# CLAUDE.md — Mémoire site vitrine GuestLucky

> Si je perds le contexte (nouvelle conversation, compaction, etc.), je lis ce fichier en premier.
> Tout ce qui est ici a déjà été décidé / validé avec Martin.

---

## 🏢 Projet

- **Site vitrine statique** `guestlucky.com` (HTML/CSS/JS pur, pas de framework)
- Hébergé sur **PlanetHoster N0C**
- Repo GitHub : `xSARRASx/guestlucky-site`
- Branche de dev : `claude/organize-project-files-oK9d6` (toujours travailler ici)
- Preview pendant le dev : `https://raw.githack.com/xSARRASx/guestlucky-site/claude/organize-project-files-oK9d6/<chemin>`
- App Laravel séparée : `app.guestlucky.io` (autre repo, autre dépôt)

## 🔁 Workflow

1. Je code sur la branche `claude/organize-project-files-oK9d6`
2. Commit + `git push -u origin claude/organize-project-files-oK9d6`
3. J'envoie un zip via SendUserFile à Martin
4. Martin upload sur PlanetHoster via le File Manager N0C
5. Pour vider le cache raw.githack pendant le dev : `Cmd+Shift+R` ou navigation privée

## 💰 Tarifs officiels (à TOUJOURS utiliser tels quels)

| Plan | Prix | Contenu |
|---|---|---|
| Gratuit | 0 € — 2 logements à vie | Pour tester sereinement |
| Premium | **5,99 € HT/mois/logement** | ERP complet **sans** Channel Manager |
| Pro | **9,99 € HT/mois/logement** | **TOUT inclus** : CM + Lucky Copilot + IA voyageurs + ERP complet |
| 50+ logements | Sur devis | Tarifs préférentiels négociés |

**0 % de commission sur tout**, **aucun frais caché**.

## 📡 Canaux connectés

Airbnb, Booking, Abritel (3 principaux). Plus d'autres canaux.

## 🤖 Distinction IA voyageurs vs Lucky Copilot (CRUCIAL)

- **IA voyageurs** : répond automatiquement aux locataires sur Airbnb / Booking. Pour les voyageurs.
- **Lucky Copilot** : assistant IA dans l'app, **pour le gestionnaire**.
  - Répond à toutes les questions sur GuestLucky (how-to)
  - Agit dans l'app : créer annonces, créer incidents, créer auto-actions, modifier prix, consulter résas / taux d'occupation, etc.

Ne JAMAIS confondre ces deux features.

## 🧩 Add-ons optionnels (rester vague dans la com)

- RDV de configuration personnalisé avec un expert (payant)
- Module d'analyse de la concurrence (~5 €/mois/logement)
- Intégration serrures connectées (Igloohome)
- Lucky Cover (assurance via Meetch)

## 📞 Support

**WhatsApp uniquement** : **07 59 94 43 05** → `https://wa.me/33759944305`
Ne JAMAIS proposer email comme canal de support. L'email `contact@guestlucky.com` existe mais c'est pour la com générale.

## 🔗 Réseaux sociaux

- Instagram : [@guestlucky.off](https://www.instagram.com/guestlucky.off/)
- WhatsApp pro : 07 59 94 43 05

## 📅 Réservation démo

URL prise de RDV : `https://www.guestlucky.com/rdv/site`
Toujours linker DIRECTEMENT vers cette URL pour les CTA "Réserver une démo" / "Réserver un appel". Exception : les CTA WhatsApp (`https://wa.me/33759944305`) restent inchangés.

## ⛔ Mots BANNIS publiquement (ne JAMAIS écrire dans le site)

- **beds24**
- **mandat de gestion**
- **garantie financière**

## 🎨 Charte graphique

- Navy : `#1a2547` / `#0F1A35`
- Primary violet : `#6b46ff` (site) / `#7B4FE0` (chat) / `#9B6FFF` (light)
- Magenta accent : `#E84A8C` / `#FFB1C9` (light)
- Background : `#f6f3ff`
- Texte principal : `#0f0f1f` (ink) / `#3a3a4a` (ink-soft)
- Police : Inter (Google Fonts)

## ✍️ Style de copywriting

- **Tutoiement** par défaut (ton décontracté pro)
- Cible : conciergeries pros / sous-loueurs / investisseurs locatifs
- Emojis : 1-2 max par bloc, jamais en excès
- Points à marteler : **0 % commission**, **tout inclus**, **transparent**, **2 logements gratuits à vie**

## 🛠️ Stack technique

- HTML/CSS/JS pur (pas de React, pas de Vue, pas de build step)
- PHP utilisé pour 2 endpoints :
  - `/register-webinar.php` — notification admin pour inscription webinaire (réelle inscription faite par redirect navigateur vers URL 1-click WebinarJam)
  - `/chat/chat.php` — backend Lucky Copilot widget (appelle Gemini)
- GTM installé (`GTM-WB4NPPJW`), prêt à recevoir GA4
- WebinarJam One-Click URL : `https://event.webinarjam.com/zg53v/register/r2wplcw1/1click`
- Modèle Gemini Copilot : `gemini-2.5-flash`
- Clé Gemini stockée dans `/chat/.env` (protégée par `/chat/.htaccess` → 403 sur accès public)

## 📁 Structure du repo

```
guestlucky-site/
├── index.html, index-en.html              # Home FR + EN
├── a-propos*.html                         # Page À propos (histoire de Sébastien Moré)
├── pour-qui*.html, fonctionnalites*.html  # Pages produit
├── tarifs*.html, faq*.html, contact*.html
├── demo*.html, webinaire*.html, merci*.html
├── style.css                              # Tout le CSS du site
├── app.js                                 # JS global (tabs, faq, cookies, share, etc.)
├── register-webinar.php                   # Endpoint inscription webinaire
├── sitemap.xml, robots.txt
├── blog/
│   ├── index.html                         # Page d'accueil blog
│   └── channel-manager-airbnb-guide-2026.html  # 1er article (template)
└── chat/                                  # Widget Lucky Copilot
    ├── chat.php, system_prompt.php, manual.php
    ├── widget-embed.html
    ├── .env.example, .htaccess
    └── assets/widget.css, assets/widget.js
```

## ✅ Ce qui est déjà construit (état actuel)

1. **Home FR + EN** avec sections : hero, Lucky Copilot promo, webinar promo, stats, intégrations, features teaser, interface tour (onglets + sous-onglets par image, 16 modules), services & partners, pricing, témoignages, FAQ
2. **Pages produit** : pour-qui, fonctionnalites, tarifs, faq, contact, demo
3. **Webinaire** : page d'inscription avec form → redirect navigateur vers URL 1-click WebinarJam (vraie inscription) + sendBeacon vers register-webinar.php (notif admin)
4. **Widget Lucky Copilot** chargé sur toutes les pages — bulle en bas à droite, chemins relatifs (`chat/` à la racine, `../chat/` depuis `blog/`)
5. **Blog** :
   - `/blog/` : page d'accueil avec 1 article publié + 3 "À venir" placeholders
   - `/blog/channel-manager-airbnb-guide-2026.html` : article SEO de ~2000 mots avec :
     - Sommaire cliquable (8 sections, numérotées 01-08)
     - Boutons de partage social (X, LinkedIn, WhatsApp, Email, Copier le lien)
     - 4 images dans le corps avec alt + figcaption
     - FAQ accordéon (7 questions)
     - Schema.org Article + BreadcrumbList + FAQPage (JSON-LD)
6. **Réseaux sociaux** : Instagram intégré sur toutes les pages (bulle flottante avec dégradé officiel + lien footer)
7. **Floating contact** : ordre bottom→top à droite : Copilot bubble (violet/magenta) → Instagram (dégradé IG) → WhatsApp (vert)
8. **Floating webinar card** : bas à gauche (à supprimer mardi quand on enlève le webinaire ou à updater les dates)

## 🚧 À retenir pour les prochaines fois

- Quand on ajoute un nouvel article : dupliquer le template `channel-manager-airbnb-guide-2026.html`, changer le contenu + meta + JSON-LD, ajouter au sitemap.xml, et lier depuis `blog/index.html`
- Images du blog : utiliser les URLs `locationcourteduree.fr/wp-content/uploads/2026/05/...` qui sont déjà en place
- Toujours vérifier les **mots bannis** (beds24, mandat de gestion, garantie financière) avant de pousser
- Toujours mettre le **WhatsApp** comme support, jamais email
- Pour les CTA "Réserver une démo", URL directe iClosed
- Pour les CTA "Essayer Lucky Copilot", `onclick="document.getElementById('glcv-bubble').click()"`

## ⚠️ LEÇON CRUCIALE : style.css pas toujours uploadé sur le serveur PlanetHoster

**Problème récurrent** : Martin upload les HTML modifiés mais OUBLIE souvent d'upload `style.css` → affichage cassé sur la prod (cartes blog stackées en 1 colonne, icônes SVG géantes, bulle Insta blanche au lieu du dégradé, boutons de partage sans icônes…).

**Solution adoptée et VALIDÉE** : pour les pages CRITIQUES où le CSS est très spécifique (blog/index.html, blog/channel-manager-airbnb-guide-2026.html), **injecter un bloc `<style>` inline dans le `<head>`** avec TOUTES les règles CSS essentielles (grid, cards, TOC, share, hero, body, figure, FAQ).

Comme ça la page s'affiche correctement même sans le style.css à jour. Le `<link rel="stylesheet" href="../style.css">` reste en place, le inline ne fait que **dupliquer défensivement** les règles critiques.

**Règles défensives** :
- Pour CHAQUE nouvel article de blog : copier le bloc `<style>` inline du template channel-manager-airbnb-guide-2026.html
- Pour les SVG icons critiques : mettre `width="X" height="X"` en attribut HTML inline en plus du CSS
- Pour les bulles flottantes (Insta, WhatsApp) : aussi `style="background: linear-gradient(...)"` en inline
- Si Martin signale un bug d'affichage sur la prod, première hypothèse = style.css pas uploadé → solution = inliner

## 📦 Quand Martin demande "envoie moi le zip"

Commande :
```bash
cd /home/user && rm -f guestlucky-site-FINAL.zip && cd guestlucky-site && \
zip -r /home/user/guestlucky-site-FINAL.zip . -x ".git/*" ".claude/*" "chat/.env" "*.DS_Store"
```

Puis `SendUserFile` avec ce path. **JAMAIS inclure `chat/.env`** (clé Gemini privée).
Toujours rappeler à Martin :
1. Uploader TOUT le contenu (HTML + style.css + app.js + chat/ + blog/)
2. NE PAS toucher `chat/.env` sur le serveur
3. Cmd+Shift+R ou navigation privée pour vider le cache
