# Lucky Copilot — version vitrine pour guestlucky.com

Ce mini-projet est une version PUBLIQUE et SIMPLIFIEE de Lucky Copilot, destinee
au site vitrine guestlucky.com. Il repond UNIQUEMENT aux questions des
prospects (presentation, fonctionnalites, prix, FAQ). Il n'a AUCUN acces aux
donnees clients, aucun outil de modification, aucune action. C'est un
"chatbot de presentation" pur.

## Ce qu'il contient

```
guestlucky-chat-vitrine/
├── chat.php              <-- backend (recoit une question, appelle Gemini, renvoie la reponse)
├── system_prompt.php     <-- le prompt systeme du copilote vitrine
├── manual.php            <-- la base de connaissances marketing de GuestLucky
├── .env.example          <-- modele de fichier .env (cle Gemini)
├── .htaccess             <-- protege le .env et les fichiers internes
├── index.html            <-- page de demo (a ouvrir pour tester en local)
├── widget-embed.html     <-- le snippet a copier-coller dans guestlucky.com
└── assets/
    ├── widget.css        <-- styles du widget
    └── widget.js         <-- JS du widget
```

## Deploiement (etapes pour ton pere)

### Etape 1 : poser les fichiers sur le serveur

Deposer le contenu du dossier dans `guestlucky.com/chat/` (par exemple).
Resultat attendu : `https://guestlucky.com/chat/chat.php` est accessible.

> Si tu prefere un sous-domaine dedie (par exemple `chat.guestlucky.com`), pose
> les fichiers dans le repertoire racine de ce sous-domaine.

### Etape 2 : creer le .env (cle Gemini)

Sur le serveur, dans le meme dossier que `chat.php`, copier `.env.example` en
`.env` et y mettre la cle Gemini :

```
GEMINI_API_KEY=AIza...la-cle-entiere...
```

⚠️ La cle ne va JAMAIS dans le code public (ni dans le HTML, ni dans le JS,
ni dans GitHub). Uniquement dans le `.env` du serveur.

Pour la creer : aller sur https://aistudio.google.com/apikey, etre connecte
au compte Google entreprise GuestLucky, projet avec facturation Tier 1
activee, copier la cle.

### Etape 3 : verifier que .htaccess fonctionne

Tester que `https://guestlucky.com/chat/.env` renvoie bien une erreur 403
(interdit). Sinon, ton hebergeur n'applique pas le .htaccess et la cle serait
exposee.

### Etape 4 : tester en ligne

Ouvrir `https://guestlucky.com/chat/index.html` : le widget doit s'ouvrir,
poser une question test ("c'est quoi GuestLucky ?"), verifier la reponse.

### Etape 5 : integrer le widget dans guestlucky.com

Ouvrir `widget-embed.html` : on y trouve le snippet a copier-coller dans le
`<body>` de chaque page de guestlucky.com ou tu veux que le widget apparaisse.

## Securite et limites

- **Anti-spam** : 20 questions max par IP par heure (modifiable dans
  `chat.php`, variable `RATE_LIMIT_PER_HOUR`). Au-dela : reponse polie de
  refus. Empeche un robot de griller le quota Gemini.
- **Longueur des questions** : 4000 caracteres max.
- **CORS** : le backend accepte les requetes depuis guestlucky.com (et
  localhost pour les tests). A adapter dans `chat.php` si tu utilises un autre
  domaine.
- **Pas de stockage** : aucune base de donnees. Pas de log des conversations
  (RGPD-friendly). Le rate-limit utilise un petit fichier JSON.

## Couts

Cle Gemini centrale GuestLucky payante (Tier 1) : `gemini-2.5-flash` est tres
peu cher (~0,1 $ / 1M tokens en entree). Une conversation visiteur typique
coute moins de 0,001 $. Pour 1000 visiteurs / mois : moins de 5 $.

## Couleurs

Le widget reprend la charte de Lucky Copilot dans l'app (bleu nuit + violet
neon + magenta accent). Pour changer : editer `assets/widget.css`.

## En cas de probleme

- Reponse "Erreur" / vide : verifier que `.env` existe et contient une cle
  valide (commence par `AIza`).
- Reponse "Quota depasse" : la cle Gemini a atteint sa limite (cle non Tier 1
  ou plafond mensuel).
- Widget invisible : verifier que `assets/widget.css` et `assets/widget.js`
  sont bien charges (onglet Reseau du navigateur).
