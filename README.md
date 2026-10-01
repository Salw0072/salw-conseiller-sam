# SALW CONSULTING · Conseiller Sam (site séparé)

Page d'acquisition « Aucune demande perdue. Aucun rendez-vous oublié. », construite le 2026-09-29 à partir de la maquette `Application SALW Conseiller.pdf`, puis alignée le même jour sur trois maquettes complémentaires : la page d'accueil, la conversation mobile avec Sam et l'écran « Diagnostic confirmé ». Elle est **distincte du site officiel** (`2026-07_salw-consulting-site-web/`).

Tout fonctionne sur un hébergement Hostinger standard (PHP 7.4 à 8.x), **sans base de données ni compte tiers obligatoire**. L'**agent IA** (chat Claude et appel vocal Vapi) s'ajoute par-dessus : il demande PHP 8.1 ou plus et des clés d'API, et sans elles Sam fonctionne en mode guidé.

## Parcours du visiteur

1. **Sam** (conversation scriptée, sans IA). Sur ordinateur, le widget reprend la maquette : 4 problèmes, puis le champ « activité et ville ». Sur mobile, « Parler à Sam » ouvre une **conversation plein écran**. Le visiteur peut écrire librement ou cliquer sur les réponses proposées.
2. Sam comprend la phrase libre (« Cabinet d'avocats à Oslo, on rate des appels pendant les rendez-vous ») : il en tire le **métier**, la **ville** et le **problème**, et ne pose que les questions manquantes. Il demande la taille de l'équipe (« Trois. », « une dizaine », « 12 »), puis **résume et fait valider** : « un cabinet de trois personnes à Oslo, qui perd des demandes faute de réponse rapide. Est-ce exact ? ».
3. Il propose **deux créneaux réels** dans la conversation, ou « d'autres créneaux » jour par jour. Il demande ensuite le nom et l'e-mail, plus le mobile si les SMS sont activés, et **réserve**.
4. **Écran « Diagnostic confirmé »** : date, heure, Albert, lien visio, frise « Et ensuite », « Ajouter à mon agenda » (.ics) et « Changer de créneau ».
5. Les blocs « Prochains créneaux » (widget et bas de page) permettent aussi de réserver sans passer par Sam.

La **frise n'annonce que ce qui partira vraiment**. Pas de « J-1 » si le rendez-vous est pris moins de 24 h avant. « SMS » ou « WhatsApp » n'apparaissent que si Twilio est configuré et que le visiteur a donné son numéro.

## Contenu du dossier

| Fichier | Rôle |
|---|---|
| `index.html` | La page (CSS et JavaScript intégrés) |
| `creneaux.php` | Créneaux libres (heures converties dans le fuseau du visiteur) |
| `reserver.php` | Réservation ou déplacement, verrou anti double réservation, e-mails et invitation agenda |
| `annuler.php` | Page d'annulation du lien reçu par e-mail (confirmation par clic, jamais automatique) |
| `rappels.php` | Rappels J-1 et H-2, à lancer par une tâche cron |
| `admin/` | **Espace admin** (voir ci-dessous). `admin.php` redirige vers ce dossier |
| `conversation.php` | Enregistre chaque conversation avec Sam au fil de l'échange (prospects) |
| `confidentialite.php` | Politique de confidentialité, avec durées et prestataires tirés des réglages réels. **Projet à valider** |
| `prive/admin/` | Logique de l'espace admin : comptes et sécurité, indicateurs, graphiques, pages |
| `prive/conversations.php` | Conversations, demandes de créneau, purge RGPD automatique |
| `contact.php` | Repli : demande de créneau par e-mail si la réservation est indisponible |
| `ia.php` | Agent IA : état (chat, vocal) et réponses de Sam. Toute erreur renvoie la page au mode guidé |
| `vapi-outils.php` | Outils de l'appel vocal appelés par Vapi (créneaux, réservation), protégés par un secret |
| `appel.php` | À la fin d'un appel, indique à la page si Sam a réservé (écran de confirmation) |
| `prive/config.php` | **Le seul fichier de réglages** |
| `prive/sam-connaissances.php` | **Ce que Sam sait et comment il se conduit** (chat et voix). C'est ici qu'on fait évoluer l'agent |
| `prive/ia-moteur.php` | Moteur du chat : SDK Anthropic officiel, boucle d'outils, garde-fous |
| `prive/reservation.php` | Réservation commune au site et à l'appel vocal |
| `prive/vapi.php` | Création et mise à jour de l'assistant vocal depuis `admin.php` |
| `prive/vendor/` | SDK Anthropic officiel (v0.52) et Guzzle, installés par Composer. **À téléverser avec le reste** |
| `prive/lib.php` | Fonctions communes |
| `prive/donnees/` | Créé automatiquement : réservations, fermetures (JSON). Jamais accessible depuis le web |
| `.htaccess`, `prive/.htaccess` | HTTPS, en-têtes de sécurité, blocage du dossier `prive/` |
| `fonts/` | Anton et DM Sans, hébergées localement (RGPD) |
| `img/` | `albert.jpg` (portrait) et `albert-avatar.jpg` (visage recadré pour l'écran de confirmation) |

## Espace admin (`/admin/`)

**Première connexion.** Ouvrir `https://<site>/admin/` : la page demande votre nom, votre e-mail et un mot de passe, et crée le compte administrateur. Elle se ferme dès que ce compte existe. **À faire juste après la mise en ligne** : tant qu'aucun compte n'existe, la première personne qui ouvre cette page pourrait créer le compte à votre place. Ensuite, les comptes se gèrent dans la page Équipe.

| Page | Contenu |
|---|---|
| Tableau de bord | Chiffre d'affaires signé face à l'objectif (jauge), montants en négociation, conversations, diagnostics réservés, conversion Sam → rendez-vous, taux de présence, devis. Graphiques par jour sur 30 jours, répartitions par secteur, problème et canal, chacun avec son tableau de données. Liste « À faire » : rendez-vous du jour et du lendemain, diagnostics à qualifier, relances dues, demandes, prospects qualifiés sans rendez-vous. Usage de l'agent IA |
| Rendez-vous | Liste filtrable. Fiche complète : contact, qualification, fiche Sam, conversation liée, historique et rappels, **suivi commercial** (présence, étape, offre, montant, prochaine relance, notes datées), annulation avec message au visiteur |
| Suivi commercial | Pipeline en colonnes : à venir, à qualifier, fait, devis envoyé, négociation, signé, perdu, absent. Totaux par colonne, déplacement d'un prospect en un menu |
| Prospects | **Toutes** les conversations avec Sam (guidé, chat IA, appel), y compris sans rendez-vous, avec la transcription, la qualification, le compte à rebours avant suppression et l'effacement sur demande |
| Demandes | Demandes de créneau reçues par le formulaire de repli, à marquer comme traitées |
| Disponibilités | Horaires en vigueur, fermeture de jours ou de périodes (congés), prochains créneaux libres |
| Réglages | Tout sans toucher au code : agenda, e-mails, rappels, **clés IA et Vapi**, SMS, **connaissances de Sam**, objectifs, durées de conservation, sécurité. Les clés ne sont jamais réaffichées. L'assistant vocal se met à jour tout seul après modification |
| Équipe | Comptes et rôles, mot de passe temporaire à la création (changement obligatoire à la première connexion), réinitialisation, désactivation |
| Journal | Qui a fait quoi et quand : connexions, échecs, modifications, exports |
| Mon compte | Mot de passe, **double authentification** (Google Authenticator, Microsoft Authenticator, 1Password) |

**Rôles.** *Administrateur* : tout. *Commercial* : rendez-vous, suivi, prospects, demandes, disponibilités, exports, mais ni réglages, ni équipe, ni journal. *Lecture seule* : consultation, sans modification ni export.

**Sécurité.**
- Mots de passe hachés (bcrypt), 12 caractères minimum.
- Compte bloqué 15 minutes après 5 échecs, et délai entre deux tentatives depuis une même adresse.
- Double authentification TOTP, conforme à la RFC 6238 et vérifiée sur ses vecteurs de test, avec refus des codes déjà utilisés. Elle peut être rendue obligatoire pour les administrateurs.
- Session fermée après 2 heures d'inactivité (12 heures au plus).
- Jeton de sécurité sur chaque formulaire, en-têtes `noindex`.
- Le dernier administrateur ne peut être ni supprimé ni rétrogradé.
- Exports CSV protégés contre l'injection de formules Excel.

**Données (RGPD).**
- Conversations sans rendez-vous et demandes : **supprimées après 30 jours**.
- Rendez-vous et fiches : supprimés 36 mois après le rendez-vous. Les deux durées se règlent dans Réglages.
- La purge tourne à chaque passage du cron et à l'ouverture de l'espace admin.
- Une mention sous la conversation renvoie à `confidentialite.php`. Cette page est un **projet à relire** : bases légales, prestataires, garanties de transfert hors EEE.

**Exports.** Rendez-vous et prospects en CSV pour Excel (séparateur « ; », accents conservés).

## Agent IA : chat et appel vocal

**Chat (Claude).** Sam répond librement, en français, en norvégien ou en anglais, selon la langue du visiteur. Il ne pose que les questions utiles (activité et ville, problème, taille de l'équipe) et fait valider son résumé. Il enregistre ensuite la fiche pour Albert et affiche des créneaux réels. La réservation reste celle du site : l'IA ne réserve jamais seule dans le chat, et elle ne demande ni nom ni e-mail.
- Modèle : `claude-opus-5`, effort `low` (réponses rapides et moins chères pour une conversation courte), réglables dans `config.php`.
- Repli côté serveur activé : si le modèle refuse une demande, l'API bascule sur un modèle de repli adapté (`fallbacks: "default"`).
- Mise en cache des consignes : le texte de Sam est identique d'une requête à l'autre, donc relu depuis le cache d'Anthropic.
- Garde-fous : 400 messages IA par jour pour tout le site, 40 par visiteur et par heure, 25 messages par conversation. Au-delà, ou si l'API ne répond pas (clé, quota, réseau), Sam dit « je continue en mode guidé » et reprend là où il en était.
- Consignes : Sam se présente comme assistant IA si on le lui demande, et ne donne ni prix, ni délai, ni référence inventée. Tout est écrit dans `prive/sam-connaissances.php`.

**Appel vocal (Vapi).** Bouton « Appeler Sam » (en haut de page, dans la conversation, dans la bulle). Le visiteur parle depuis son navigateur, micro autorisé, et la conversation s'écrit dans le fil. Sam propose deux créneaux, **fait épeler puis confirmer l'adresse e-mail**, et réserve par `vapi-outils.php`. À la fin de l'appel, l'écran « Diagnostic confirmé » s'affiche. Les réservations par téléphone sont signalées dans `admin.php` (e-mail dicté, à vérifier).
- Modèle vocal : **`claude-sonnet-5`**, parce que Vapi ne propose pas encore Claude Opus 5. Voix Azure `fr-FR-HenriNeural` et transcription Deepgram `nova-3` en français, modifiables.
- Coût : facturé par Vapi à la minute, plus la voix et la transcription. Durée maximale de 10 minutes par appel.

**Bulle flottante.** Dès que le widget de la page n'est plus à l'écran, une bulle « Une question ? Parlez à Sam » apparaît en bas à droite. Elle ouvre **la même conversation** : fenêtre flottante sur ordinateur, plein écran sur mobile.

### Activer l'agent IA
1. hPanel > Avancé > **Configuration PHP** : choisir PHP 8.2 ou 8.3.
2. **Chat** : créer une clé sur console.anthropic.com et la coller dans `ia_cle`. C'est tout, le chat s'active au chargement suivant de la page.
3. **Appel vocal** : sur vapi.ai, récupérer la clé publique et la clé privée et les coller dans `vapi_cle_publique` et `vapi_cle_privee`. Inventer un secret d'au moins 24 caractères pour `vapi_secret`, et renseigner `url_site` (l'adresse en ligne, en https). Dans `admin.php`, cliquer sur « Créer l'assistant vocal ».
4. Après toute modification de `prive/sam-connaissances.php`, recliquer sur « Mettre à jour l'assistant vocal ». Le chat, lui, prend la modification immédiatement.

## Mise en service (Hostinger)

1. Choisir l'adresse, par exemple `conseil.salw-consulting.com`, et téléverser tout le dossier **sauf le README**.
2. Créer les boîtes `albert@` (destinataire) et `site@` (expéditeur technique, obligatoire pour le SPF).
3. Dans `prive/config.php` :
   - `ics_occupe` : **l'adresse secrète iCal de ton Google Agenda**, pour que tes rendez-vous personnels bloquent les créneaux (sans cela, le site ignore ton agenda) ;
   - vérifier les horaires (`plages`, `jours`, `max_par_jour`).
4. hPanel > Avancé > **Tâches cron** : toutes les 15 minutes, `php /home/<compte>/public_html/<dossier>/rappels.php`. Sans cron, aucun rappel ne part.
5. Test réel : réserver un créneau pour soi, vérifier les deux e-mails et l'invitation, annuler par le lien, puis ouvrir `admin.php`.

## SMS et WhatsApp (facultatif)

Renseigner le bloc `twilio` de `config.php`. Le site demande alors le mobile, et les rappels passent en WhatsApp (veille) et SMS (2 h avant). **WhatsApp exige un numéro validé par Meta et un modèle de message approuvé.** Twilio est payant à l'usage. Sans ces réglages, tout part par e-mail et le site ne mentionne ni SMS ni WhatsApp.

## Contrôles effectués (29/09/2026)

- PHP 8.3 **et** 7.4 : syntaxe et exécution sans aucun avertissement.
- Réservation, double réservation refusée (409), créneau retiré puis libéré à l'annulation, entrées invalides rejetées, robots écartés.
- Synchronisation iCal : un événement occupé bloque les bons créneaux, un événement « disponible » n'en bloque aucun.
- Fuseaux : 10 h 30 à Drammen affiché 9 h 30 à Kinshasa, dans les e-mails comme dans la page.
- Déplacement : l'ancien créneau est libéré, l'invitation garde le même identifiant (version 1), le lien visio est conservé.
- Rappels : SMS H-2, e-mail et WhatsApp J-1, pas de J-1 pour un rendez-vous pris moins de 24 h avant, rien en double au second passage, accès web refusé sans clé.
- Administration : connexion, protection contre les tentatives répétées, jeton de sécurité, fermeture et réouverture d'une journée, annulation avec prévenance du visiteur.
- Conversation mobile de bout en bout, dans Chrome, à 390 px, puis réservation et changement de créneau. Rendu comparé aux maquettes, à 1440 px et à 390 px.
- Détection des métiers sur 15 exemples (cabinet dentaire, auto-école, direction générale…).

- Espace admin (29/09), avec données de test :
  - installation (page fermée une fois un compte créé) ;
  - les 20 pages en HTTP 200 sans avertissement, en PHP 8.3 **et 7.4** ;
  - indicateurs recalculés à la main et exacts sur 7, 30 et 90 jours, graphiques et tableaux de données ;
  - suivi, pipeline, annulation avec e-mail, fermeture de jours, réglages (erreurs de saisie refusées, application immédiate aux créneaux), secret généré et masqué ;
  - export CSV (BOM, séparateur), purge d'une conversation de 40 jours ;
  - jeton de sécurité forgé refusé ;
  - rôles (403 sur les pages interdites, champs désactivés en lecture seule), mot de passe temporaire à changer, dernier administrateur protégé, blocage après 5 échecs ;
  - double authentification complète (code faux et code rejoué refusés) ;
  - aucun débordement horizontal à 1440 px et 390 px ;
  - parcours de bout en bout : conversation sur le site, puis réservation, puis prospect rattaché au rendez-vous avec son secteur, dans l'admin.
- Agent IA (29/09) :
  - SDK Anthropic installé et joignable : une fausse clé est refusée par l'API réelle (401), et la page passe en mode guidé en moins d'une seconde.
  - Outils du chat : libellés dans l'heure du visiteur, dates inventées écartées, fiche invalide normalisée.
  - Parcours IA complet dans Chrome, avec des réponses simulées : questions, résumé, créneaux cliquables, réservation, fiche « agent IA » transmise avec toute la conversation.
  - Outils vocaux : secret obligatoire (401 sinon), créneaux, réservation avec adresse dictée normalisée, créneau déjà pris refusé proprement, réservation retrouvée par `appel.php`.
  - Création de l'assistant : Vapi joint pour de vrai, qui refuse une fausse clé avec un message clair.
  - Bulle flottante, fenêtre flottante et plein écran mobile.

**Non vérifié** :
- les **réponses réelles de Claude** (aucune clé Anthropic sur la machine) ;
- un **appel vocal réel** (aucun compte Vapi) ;
- l'acceptation par Vapi de la configuration de l'assistant (refusée avant validation, faute de vraie clé) ;
- l'envoi réel des e-mails depuis Hostinger (vérifié en mode test uniquement) ;
- les SMS et WhatsApp réels (aucun compte Twilio) ;
- le rendu des invitations dans Outlook et Gmail.

À faire au premier test réel : une conversation écrite complète, puis un appel avec réservation, en vérifiant l'e-mail reçu.

## Points de vigilance

- **L'offre A n'existe pas encore comme produit.** La page vend une plateforme par métier sur abonnement. Avant d'envoyer du trafic, il faut pouvoir la livrer pour au moins un métier.
- L'en-tête de la maquette mobile indiquait « Conseiller virtuel · WhatsApp ». Il affiche **« en ligne »** : cette conversation se déroule sur le site, pas sur WhatsApp, et l'écrire serait trompeur.
- Le bandeau « Référence » reste masqué tant qu'aucun fait daté et vérifiable n'est renseigné (`REFERENCE` dans le script).
- Les événements récurrents de Google Agenda ne bloquent que leur première occurrence. Pour une indisponibilité régulière, ajuster `plages` ou fermer la journée dans `admin.php`.
- Sélecteur FR · NO · EN : seul le français est actif.

## Tarifs (offre A)

Section **Tarifs** (`#tarifs`, lien dans le menu, et « à partir de 149 € HT » sur la carte de l'offre A) : choix du métier, les mêmes quatre offres que la plateforme : trois formules au volume (Essentiel, Équipe, Structure) avec le prix « à partir de » du métier, la tranche d'agendas, les SMS inclus, l'engagement de 12 mois, la mise en place et le seuil de rentabilité, plus l'offre **Sur mesure** (sur devis, au-delà de 15 agendas ou plusieurs sites) (« remboursé dès N rendez-vous récupérés par mois », calculé sur un rendez-vous moyen affiché en note). Cliquer sur une carte métier plus haut dans la page cale aussi les tarifs sur ce métier. Bouton « Obtenir mon prix exact » vers le diagnostic.

Les paramètres (`TARIFS`, dans le dernier `<script>` de `index.html`) sont les mêmes que `prive/tarifs.php` de la plateforme : **à modifier ensemble**. Testé dans Chrome à 1 440 et 390 px, sans erreur JavaScript ni débordement ; prix identiques à ceux calculés par la plateforme.

## Déploiement (GitHub puis Hostinger)

Dépôt Git propre à cette application (branche `main`), à pousser dans un dépôt GitHub **privé**. Ne jamais pousser le workspace Jarvis entier.

- **Versionné** : le code, `prive/vendor/` (l'hébergement ne lance pas Composer) et le modèle `prive/config.exemple.php`.
- **Jamais versionné** (`.gitignore`) : `prive/config.php` (réglages et clés) et `prive/donnees/` (données). Un déploiement ne les touche donc pas.
- **Premier envoi** : `git remote add origin https://github.com/<compte>/salw-conseiller-sam.git` puis `git push -u origin main` (connexion GitHub dans le navigateur ; jamais de jeton dans une commande).
- **Hostinger** : hPanel → Avancé → Git, dépôt en SSH avec la clé de déploiement ajoutée dans GitHub (Settings → Deploy keys, lecture seule), webhook de déploiement automatique ajouté dans GitHub (Settings → Webhooks). Puis, une seule fois sur le serveur : copier `prive/config.exemple.php` en `prive/config.php` et le compléter, vérifier que `prive/donnees/` est accessible en écriture, programmer la tâche cron, activer le SSL, créer le compte admin aussitôt.

## Mot de passe oublié

Lien « Mot de passe oublié ? » sous le formulaire de connexion : on saisit l'adresse du compte et on reçoit par e-mail un lien **valable 1 heure et utilisable une seule fois** (seule son empreinte est conservée), pour choisir soi-même le nouveau mot de passe. La réponse affichée est la même que l'adresse existe ou non. Limite : une demande par minute et par adresse IP. Une réinitialisation remet aussi à zéro le blocage après échecs ; la double authentification, si elle est activée, reste demandée à la connexion.

L'e-mail part par `envoyer_mail()` depuis l'adresse `expediteur` : en mode test, il est déposé dans `prive/donnees/boite-test/`.
