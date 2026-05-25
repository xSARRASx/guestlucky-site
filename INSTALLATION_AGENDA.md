# 📦 Agenda GuestLucky — Guide d'installation

Système de prise de RDV en ligne avec synchronisation Google Calendar, questions de qualification, codes promo, paiement Stripe et email de confirmation Gmail.

---

## ✅ Prérequis déjà fournis par Kévin

- ✅ Base Airtable configurée + token Airtable
- ✅ Compte Google OAuth2 configuré + refresh_token
- ✅ Liens Stripe Payment Links

**Il vous suffit de remplir le fichier `.env` et de déployer les fichiers.**

---

## 🗂 Structure à déployer

```
/public_html/
├── agenda.html                    ← Page principale (frontend React)
├── .htaccess                      ← Ajouter les règles fournies à votre .htaccess existant
└── api/
    ├── get_agenda_config.php
    ├── get_availability.php
    ├── booking_webhook.php
    ├── autosave_contact.php
    ├── check_promo.php
    ├── google_auth_start.php      ← Admin uniquement — supprimer après usage
    └── google_auth_callback.php   ← Admin uniquement — supprimer après usage

/home/VOTRE_USER/private/          ← HORS webroot, à créer
└── glAgenda.env                   ← Variables d'environnement
```

---

## ⚙️ ÉTAPE 1 — Prérequis serveur

- PHP 8.1+
- Extensions : `curl`, `openssl`, `json`
- `allow_url_fopen = On`

---

## 📝 ÉTAPE 2 — Fichier .env

1. Sur PlanetHoster, créer le dossier **hors webroot** :
```
/home/VOTRE_USER/private/
```

2. Y déposer le fichier `glAgenda.env` (renommer depuis `glAgenda.env.example`) avec toutes les valeurs remplies — Kévin vous les fournit.

3. **Important** : remplacer `VOTRE_USER` dans chaque fichier PHP par votre vrai nom d'utilisateur PlanetHoster. C'est la seule modification de code nécessaire.

Rechercher et remplacer dans tous les fichiers PHP :
```
/home/VOTRE_USER/private/glAgenda.env
```
→ Remplacer `VOTRE_USER` par votre identifiant (visible dans le chemin FTP, ex: `/home/abc123xyz/`)

---

## 🚀 ÉTAPE 3 — Déploiement

### 3.1 Déposer les fichiers via FTP/SFTP

```
/public_html/agenda.html
/public_html/api/get_agenda_config.php
/public_html/api/get_availability.php
/public_html/api/booking_webhook.php
/public_html/api/autosave_contact.php
/public_html/api/check_promo.php
/public_html/api/google_auth_start.php
/public_html/api/google_auth_callback.php
```

### 3.2 Créer les dossiers cache et logs

Via le gestionnaire de fichiers PlanetHoster ou SSH :
```
/public_html/api/cache/
/public_html/api/cache/availability/
/public_html/api/logs/
```
Permissions : **755**

### 3.3 .htaccess

Ajouter le contenu du fichier `_htaccess` fourni à votre `.htaccess` existant dans `/public_html/`.

---

## ✅ ÉTAPE 4 — Vérification

Tester dans cet ordre :

**1. Config agents/types/questions**
```
https://www.guestlucky.com/api/get_agenda_config.php?flush=1
```
→ Doit retourner un JSON avec `agents`, `meetingTypes`, `questions`

**2. Disponibilités Google Calendar**
```
https://www.guestlucky.com/api/get_availability.php?agent_id=camille&type_id=decouverte&days=7&flush=1
```
→ `"source":"google_calendar"` ✅ (si `"source":"theoretical"` → problème de refresh_token)

**3. Page agenda**
```
https://www.guestlucky.com/agenda.html
```
→ Affiche les 5 types de RDV

**4. Lien court**
```
https://www.guestlucky.com/rdv
```
→ Redirige vers la page agenda

**5. Réservation test complète**
Parcourir le tunnel jusqu'à la confirmation → vérifier :
- ✅ Record créé dans Airtable (tables Bookings + Contacts)
- ✅ Événement créé dans Google Calendar de l'agent
- ✅ Email de confirmation reçu avec lien Google Meet

---

## 📊 Logs de diagnostic

En cas de problème, consulter :
```
/public_html/api/logs/agenda_config.log     ← Chargement config
/public_html/api/logs/availability.log      ← Google Calendar FreeBusy
/public_html/api/logs/booking_webhook.log   ← Réservations
/public_html/api/logs/autosave_contact.log  ← Capture leads
/public_html/api/logs/check_promo.log       ← Codes promo
```

---

## 🔗 URLs de l'agenda

| Usage | URL |
|---|---|
| Page principale | `https://www.guestlucky.com/agenda.html` |
| Lien court | `https://www.guestlucky.com/rdv` |
| Instagram bio | `https://www.guestlucky.com/rdv/instagram` |
| YouTube description | `https://www.guestlucky.com/rdv/youtube` |
| TikTok bio | `https://www.guestlucky.com/rdv/tiktok` |
| LinkedIn | `https://www.guestlucky.com/rdv/linkedin` |
| Email/Newsletter | `https://www.guestlucky.com/rdv/email` |
| QR Code print | `https://www.guestlucky.com/rdv/qr` |

---

## 🔒 Sécurité post-installation

Une fois l'installation vérifiée et fonctionnelle :
- **Supprimer** `google_auth_start.php` et `google_auth_callback.php` du serveur
- Ces fichiers sont uniquement utiles pour le renouvellement du refresh_token Google (à refaire tous les 6 mois environ si le compte n'est pas en mode Production)

---

## 📞 Support

En cas de problème, contacter Kévin Besnard avec le contenu des fichiers de logs.