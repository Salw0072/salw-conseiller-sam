<?php
/**
 * MODÈLE. Sur le serveur, copiez ce fichier en prive/config.php, puis renseignez-y les clés.
 * config.php n'est jamais versionné (voir .gitignore) : aucune clé ne doit aller sur GitHub.
 *
 * SALW CONSULTING, conseiller Sam : réglages du serveur.
 *
 * C'est le seul fichier PHP à modifier. Les heures s'entendent dans le fuseau
 * indiqué (Drammen), le visiteur voit les créneaux convertis dans le sien.
 */

return [
    // --- E-mails -----------------------------------------------------------------
    // Boîte qui reçoit les fiches Sam et les réservations.
    'destinataire' => 'albert@salw-consulting.com',
    // Adresse technique d'envoi. Elle DOIT appartenir au domaine hébergé (SPF),
    // sinon les messages partent en spam ou sont rejetés.
    'expediteur'   => 'site@salw-consulting.com',
    'nom_conseiller' => 'Albert Wutshu Shongo',
    // Adresse publique du site, sans « / » final (ex. https://conseil.salw-consulting.com).
    // Sert aux liens envoyés par le cron et par l'appel vocal. Vide = déduite automatiquement.
    'url_site' => '',

    // --- Disponibilités du diagnostic -----------------------------------------------
    'fuseau'  => 'Europe/Oslo',
    // Jours ouvrés : 1 = lundi ... 7 = dimanche.
    'jours'   => [1, 2, 3, 4, 5],
    // Plages d'ouverture, dans le fuseau ci-dessus.
    'plages'  => [['09:00', '12:00'], ['13:30', '17:00']],
    // Durée d'un diagnostic, en minutes.
    'duree'   => 30,
    // Écart entre deux débuts de créneau (30 min de rendez-vous + 15 min de marge).
    'pas'     => 45,
    // Délai minimum avant un rendez-vous : pas de réservation pour dans une heure.
    'delai_min_heures' => 12,
    // Jusqu'où l'agenda est ouvert.
    'horizon_jours' => 21,
    // Nombre maximum de diagnostics par jour.
    'max_par_jour' => 4,
    // Jours fermés (congés, déplacements), au format AAAA-MM-JJ.
    'jours_fermes' => [],

    // --- Synchronisation avec ton agenda (facultatif, fortement conseillé) --------
    // « Adresse secrète au format iCal » de Google Agenda (Paramètres de l'agenda >
    // Intégrer l'agenda). Tes événements occupés bloquent alors les créneaux.
    // Limite : les événements récurrents ne bloquent que leur première occurrence.
    'ics_occupe' => '',

    // --- Visio ---------------------------------------------------------------------
    // Vide : un salon Jitsi Meet unique est créé pour chaque rendez-vous (gratuit,
    // sans compte). Sinon, ton lien fixe (Google Meet, Zoom, Teams...).
    'lien_visio' => '',

    // --- Rappels automatiques -----------------------------------------------------------
    // Envoyés par rappels.php, à lancer toutes les 15 minutes par une tâche cron
    // Hostinger (voir README). La veille (J-1) et 2 heures avant (H-2).
    'rappel_j1' => true,
    'rappel_h2' => true,
    // Clé pour lancer rappels.php par une URL (cron « URL » de certains hébergeurs).
    // Vide = lancement en ligne de commande uniquement (recommandé).
    'cron_cle' => '',

    // --- SMS et WhatsApp (facultatif, via Twilio) -----------------------------------------
    // Sans ces réglages, les rappels partent par e-mail uniquement et le site ne demande
    // pas de numéro. Avec eux : WhatsApp la veille, SMS 2 heures avant.
    // WhatsApp exige un expéditeur validé par Meta et un modèle de message approuvé.
    'twilio' => [
        'sid'         => '',   // Account SID
        'token'       => '',   // Auth Token
        'sms_de'      => '',   // numéro Twilio, ex. +4712345678
        'whatsapp_de' => '',   // numéro WhatsApp validé, ex. +4712345678
    ],

    // --- Agent IA : chat (Claude, API Anthropic) ------------------------------------------
    // Sans clé, ou si l'API ne répond pas, Sam repasse automatiquement en mode guidé (scripté).
    // Clé à créer sur console.anthropic.com > API Keys. Exige PHP 8.1 ou plus.
    'ia_cle'          => '',
    'ia_modele'       => 'claude-opus-5',
    // Effort de réflexion : « low » suffit pour une conversation courte et répond plus vite.
    'ia_effort'       => 'low',
    // Garde-fous de coût : messages IA par jour (tout le site) et par visiteur et par heure.
    'ia_max_jour'     => 400,
    'ia_max_heure_ip' => 40,

    // --- Agent IA : appel vocal (Vapi) -----------------------------------------------------
    // Compte sur vapi.ai. Clé publique : affichée dans la page (normal, c'est son rôle).
    // Clé privée : sert uniquement à créer l'assistant depuis admin.php, jamais envoyée au navigateur.
    'vapi_cle_publique' => '',
    'vapi_cle_privee'   => '',
    // Secret partagé : Vapi l'envoie à vapi-outils.php, qui refuse tout appel sans lui.
    // Au moins 24 caractères aléatoires.
    'vapi_secret'       => '',
    // Vapi ne propose pas encore Claude Opus 5 : Claude Sonnet 5 est le plus récent disponible.
    'vapi_modele'       => 'claude-sonnet-5',
    'vapi_voix'         => ['provider' => 'azure', 'voiceId' => 'fr-FR-HenriNeural'],
    'vapi_transcription' => ['provider' => 'deepgram', 'model' => 'nova-3', 'language' => 'fr'],
    'vapi_duree_max'    => 600, // secondes par appel

    // --- Connaissances de Sam ------------------------------------------------------------
    // Vide = texte d'origine (prive/sam-connaissances.php). Modifiable depuis l'espace admin.
    'sam_connaissances'    => '',
    'vapi_premier_message' => "Bonjour, ici Sam, l'assistant virtuel de SALW CONSULTING. Dites-moi votre activité, et ce qui vous fait perdre le plus de temps en ce moment.",

    // --- Objectif commercial (tableau de bord) ----------------------------------------------
    // Chiffre d'affaires signé via l'application, en euros, sur la période indiquée.
    'objectif_ca'     => 100000,
    'objectif_debut'  => '2026-07-05',
    'objectif_fin'    => '2026-10-05',

    // --- Conservation des données (RGPD) -----------------------------------------------------
    // Conversations avec Sam sans rendez-vous : supprimées après ce nombre de jours.
    'conservation_conversations_jours' => 30,
    // Rendez-vous et leurs fiches : supprimés ce nombre de mois après le rendez-vous.
    'conservation_rdv_mois' => 36,

    // --- Espace admin -------------------------------------------------------------------------
    // Le premier compte administrateur se crée sur admin/, juste après la mise en ligne.
    // Ensuite, les comptes se gèrent dans l'espace admin (page Équipe).
    // true : double authentification (application type Google Authenticator) obligatoire
    // pour les comptes administrateurs.
    'admin_2fa_obligatoire' => false,

    // --- Mode test ---------------------------------------------------------------------
    // true : aucun e-mail n'est envoyé, ils sont écrits dans prive/donnees/boite-test/.
    'mode_test' => false,
];
