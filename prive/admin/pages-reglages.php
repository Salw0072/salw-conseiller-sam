<?php
/**
 * SALW CONSULTING, espace admin : réglages modifiables sans toucher au code.
 *
 * Les valeurs sont enregistrées dans prive/donnees/reglages.json et priment sur
 * prive/config.php (voir config_complete()). Les clés secrètes ne sont jamais
 * réaffichées : un champ laissé vide conserve la valeur actuelle.
 */

declare(strict_types=1);

/** [section => [titre, aide, [champs]]] ; champ : [clé, libellé, type, options] */
function schema_reglages(): array
{
    return [
        'agenda' => ['Agenda', "Quand les visiteurs peuvent réserver un diagnostic. Heures de Drammen : le site les convertit dans le fuseau de chaque visiteur.", [
            ['jours', 'Jours ouvrés', 'jours', []],
            ['plages', 'Plages horaires', 'plages', ['aide' => 'Ex. 09:00-12:00, 13:30-17:00']],
            ['duree', 'Durée du diagnostic (min)', 'nombre', ['min' => 15, 'max' => 120]],
            ['pas', 'Un créneau toutes les (min)', 'nombre', ['min' => 15, 'max' => 240, 'aide' => 'Durée + marge entre deux rendez-vous.']],
            ['delai_min_heures', 'Délai minimum avant un rendez-vous (h)', 'nombre', ['min' => 0, 'max' => 168]],
            ['horizon_jours', 'Agenda ouvert sur (jours)', 'nombre', ['min' => 1, 'max' => 90]],
            ['max_par_jour', 'Diagnostics maximum par jour', 'nombre', ['min' => 1, 'max' => 20]],
            ['ics_occupe', 'Adresse secrète iCal de votre Google Agenda', 'secret', ['aide' => 'Google Agenda › Paramètres › votre agenda › « Adresse secrète au format iCal ». Vos rendez-vous occupés bloquent alors les créneaux.']],
            ['lien_visio', 'Lien de visio fixe (facultatif)', 'url', ['aide' => 'Vide : un salon Jitsi Meet unique est créé pour chaque rendez-vous.']],
        ]],
        'emails' => ['E-mails et site', 'Adresses utilisées pour les confirmations, rappels et fiches.', [
            ['nom_conseiller', 'Nom affiché dans les e-mails', 'texte', []],
            ['destinataire', 'Boîte qui reçoit les réservations et fiches', 'email', []],
            ['expediteur', "Adresse d'envoi (du domaine du site, pour la délivrabilité)", 'email', []],
            ['url_site', 'Adresse publique du site', 'url', ['aide' => 'Ex. https://conseil.salw-consulting.com, sans « / » final. Indispensable pour l\'appel vocal et les liens du cron.']],
            ['mode_test', 'Mode test : aucun e-mail ni SMS réellement envoyé', 'case', []],
        ]],
        'rappels' => ['Rappels', 'Envoyés par rappels.php, lancé toutes les 15 minutes par une tâche cron Hostinger.', [
            ['rappel_j1', 'Rappel la veille (J-1)', 'case', []],
            ['rappel_h2', 'Rappel 2 heures avant (H-2)', 'case', []],
            ['cron_cle', 'Clé pour lancer le cron par une URL (facultatif)', 'secret', ['generer' => true]],
        ]],
        'ia' => ['Agent IA : chat', "Sam répond librement grâce à Claude (Anthropic). Sans clé, ou en cas d'incident, il repasse en mode guidé.", [
            ['ia_cle', 'Clé API Anthropic', 'secret', ['aide' => 'console.anthropic.com › API Keys. Exige PHP 8.1 ou plus.']],
            ['ia_modele', 'Modèle', 'choix', ['choix' => ['claude-opus-5' => 'Claude Opus 5 (recommandé)', 'claude-sonnet-5' => 'Claude Sonnet 5 (moins cher)', 'claude-haiku-4-5' => 'Claude Haiku 4.5 (le moins cher)']]],
            ['ia_effort', 'Effort de réflexion', 'choix', ['choix' => ['low' => 'Faible : rapide, idéal pour une conversation courte', 'medium' => 'Moyen', 'high' => 'Élevé : plus lent et plus coûteux']]],
            ['ia_max_jour', 'Messages IA maximum par jour (tout le site)', 'nombre', ['min' => 0, 'max' => 100000]],
            ['ia_max_heure_ip', 'Messages IA maximum par visiteur et par heure', 'nombre', ['min' => 1, 'max' => 1000]],
        ]],
        'vocal' => ['Agent IA : appel vocal', "Appels depuis le navigateur avec Vapi. Après modification, l'assistant vocal est mis à jour automatiquement.", [
            ['vapi_cle_publique', 'Clé publique Vapi', 'texte', []],
            ['vapi_cle_privee', 'Clé privée Vapi', 'secret', []],
            ['vapi_secret', 'Secret partagé avec vapi-outils.php', 'secret', ['generer' => true, 'aide' => '24 caractères au moins. Utilisez « Générer ».']],
            ['vapi_modele', 'Modèle vocal', 'choix', ['choix' => ['claude-sonnet-5' => 'Claude Sonnet 5 (recommandé)', 'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (plus rapide)', 'claude-sonnet-4-6' => 'Claude Sonnet 4.6']]],
            ['vapi_voix.provider', 'Fournisseur de voix', 'choix', ['choix' => ['azure' => 'Azure', '11labs' => 'ElevenLabs', 'cartesia' => 'Cartesia']]],
            ['vapi_voix.voiceId', 'Voix', 'texte', ['aide' => 'Ex. fr-FR-HenriNeural ou fr-FR-DeniseNeural (Azure).']],
            ['vapi_transcription.language', 'Langue de transcription', 'choix', ['choix' => ['fr' => 'Français', 'multi' => 'Multilingue', 'en' => 'Anglais', 'no' => 'Norvégien']]],
            ['vapi_duree_max', 'Durée maximale d\'un appel (secondes)', 'nombre', ['min' => 60, 'max' => 1800]],
        ]],
        'sms' => ['SMS et WhatsApp', 'Rappels par Twilio : WhatsApp la veille, SMS 2 heures avant. Sans ces réglages, les rappels partent par e-mail.', [
            ['twilio.sid', 'Twilio Account SID', 'texte', []],
            ['twilio.token', 'Twilio Auth Token', 'secret', []],
            ['twilio.sms_de', "Numéro d'envoi SMS", 'texte', ['aide' => 'Format international, ex. +4712345678']],
            ['twilio.whatsapp_de', 'Numéro WhatsApp validé', 'texte', ['aide' => 'Exige un expéditeur validé par Meta et un modèle de message approuvé.']],
        ]],
        'sam' => ['Sam', "Ce que Sam sait et comment il se conduit, dans le chat et au téléphone. Les consignes techniques (outils, réservation) restent gérées par l'application.", [
            ['sam_connaissances', 'Connaissances et règles de Sam', 'texte_long', ['defaut' => 'sam_connaissances_defaut']],
            ['vapi_premier_message', 'Première phrase de Sam au téléphone', 'texte', []],
        ]],
        'objectifs' => ['Objectifs', 'Affichés sur le tableau de bord.', [
            ['objectif_ca', "Objectif de chiffre d'affaires signé via l'application (€ HT)", 'nombre', ['min' => 0, 'max' => 100000000]],
            ['objectif_debut', 'Début de la période', 'date', []],
            ['objectif_fin', 'Fin de la période', 'date', []],
        ]],
        'rgpd' => ['Données et sécurité', 'Durées de conservation (affichées aussi sur la page de confidentialité) et sécurité des comptes.', [
            ['conservation_conversations_jours', 'Conversations sans rendez-vous et demandes : conservées (jours)', 'nombre', ['min' => 1, 'max' => 365]],
            ['conservation_rdv_mois', 'Rendez-vous et fiches : conservés après le rendez-vous (mois)', 'nombre', ['min' => 1, 'max' => 120]],
            ['admin_2fa_obligatoire', 'Double authentification obligatoire pour les administrateurs', 'case', []],
        ]],
    ];
}

/** Valeur actuelle d'un champ, clés imbriquées « parent.enfant » comprises. */
function valeur_reglage(string $cle)
{
    if (strpos($cle, '.') !== false) {
        [$p, $e] = explode('.', $cle, 2);
        return ((array)cfg($p))[$e] ?? '';
    }
    return cfg($cle);
}

function masquer(string $s): string
{
    return $s === '' ? 'non renseignée' : '•••• ' . mb_substr($s, -4);
}

function page_reglages(): void
{
    if (!peut('reglages')) {
        interdit();
    }
    $schema = schema_reglages();
    $section = array_key_exists($_GET['s'] ?? '', $schema) ? (string)$_GET['s'] : 'agenda';
    [$titreSection, $aide, $champs] = $schema[$section];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $erreurs = [];
        $nouveaux = [];
        foreach ($champs as [$cle, $libelle, $type, $opt]) {
            $nom = str_replace('.', '__', $cle);
            $brut = $_POST[$nom] ?? null;
            if ($type === 'case') {
                $nouveaux[$cle] = $brut === '1';
            } elseif ($type === 'jours') {
                $j = array_values(array_unique(array_map('intval', array_intersect((array)($_POST[$nom] ?? []), ['1', '2', '3', '4', '5', '6', '7']))));
                sort($j);
                if (!$j) {
                    $erreurs[] = 'Choisissez au moins un jour.';
                }
                $nouveaux[$cle] = $j;
            } elseif ($type === 'plages') {
                $plages = [];
                foreach (preg_split('/[,;]+/', (string)$brut) as $morceau) {
                    if (trim($morceau) === '') continue;
                    if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/', $morceau, $m) || (int)$m[1] > 23 || (int)$m[3] > 23 || (int)$m[2] > 59 || (int)$m[4] > 59) {
                        $erreurs[] = "Plage illisible : « " . trim($morceau) . " » (format 09:00-12:00).";
                        continue;
                    }
                    $d = sprintf('%02d:%s', $m[1], $m[2]);
                    $f = sprintf('%02d:%s', $m[3], $m[4]);
                    if ($f <= $d) {
                        $erreurs[] = "Plage {$d}-{$f} : la fin doit suivre le début.";
                        continue;
                    }
                    $plages[] = [$d, $f];
                }
                if (!$plages && !$erreurs) {
                    $erreurs[] = 'Indiquez au moins une plage horaire.';
                }
                $nouveaux[$cle] = $plages;
            } elseif ($type === 'nombre') {
                $n = (int)$brut;
                if ((string)$brut === '' || $n < ($opt['min'] ?? PHP_INT_MIN) || $n > ($opt['max'] ?? PHP_INT_MAX)) {
                    $erreurs[] = "{$libelle} : valeur entre " . ($opt['min'] ?? '') . ' et ' . ($opt['max'] ?? '') . '.';
                }
                $nouveaux[$cle] = $n;
            } elseif ($type === 'secret') {
                if (!empty($_POST[$nom . '__generer'])) {
                    $nouveaux[$cle] = bin2hex(random_bytes(20));
                } elseif (!empty($_POST[$nom . '__effacer'])) {
                    $nouveaux[$cle] = '';
                } elseif (trim((string)$brut) !== '') {
                    $nouveaux[$cle] = trim((string)$brut);
                }
            } elseif ($type === 'email') {
                $v = trim((string)$brut);
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $erreurs[] = "{$libelle} : adresse invalide.";
                }
                $nouveaux[$cle] = $v;
            } elseif ($type === 'url') {
                $v = rtrim(trim((string)$brut), '/');
                if ($v !== '' && !preg_match('#^https?://[^\s]+$#', $v)) {
                    $erreurs[] = "{$libelle} : adresse web invalide.";
                }
                $nouveaux[$cle] = $v;
            } elseif ($type === 'date') {
                $v = trim((string)$brut);
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $erreurs[] = "{$libelle} : date invalide.";
                }
                $nouveaux[$cle] = $v;
            } elseif ($type === 'choix') {
                $v = (string)$brut;
                if (!array_key_exists($v, $opt['choix'])) {
                    $erreurs[] = "{$libelle} : choix inconnu.";
                }
                $nouveaux[$cle] = $v;
            } elseif ($type === 'texte_long') {
                $v = trim(str_replace("\r", '', (string)$brut));
                $defaut = isset($opt['defaut']) ? call_user_func($opt['defaut']) : '';
                // Texte identique à l'origine, ou case « rétablir » : on enregistre vide (texte d'origine suivi).
                $nouveaux[$cle] = (!empty($_POST[$nom . '__retablir']) || $v === trim($defaut)) ? '' : mb_substr($v, 0, 20000);
                if ($nouveaux[$cle] !== '' && mb_strlen($nouveaux[$cle]) < 200) {
                    $erreurs[] = "{$libelle} : texte trop court pour guider Sam (200 caractères minimum).";
                }
            } else {
                $nouveaux[$cle] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)$brut)), 0, 400);
            }
        }
        if ($section === 'agenda' && isset($nouveaux['pas'], $nouveaux['duree']) && $nouveaux['pas'] < $nouveaux['duree']) {
            $erreurs[] = "L'écart entre deux créneaux doit être au moins égal à la durée du diagnostic.";
        }
        if ($section === 'objectifs' && ($nouveaux['objectif_debut'] ?? '') !== '' && ($nouveaux['objectif_fin'] ?? '') !== '' && $nouveaux['objectif_fin'] < $nouveaux['objectif_debut']) {
            $erreurs[] = 'La fin de la période doit suivre son début.';
        }
        if ($section === 'vocal' && isset($nouveaux['vapi_secret']) && $nouveaux['vapi_secret'] !== '' && strlen($nouveaux['vapi_secret']) < 24) {
            $erreurs[] = 'Le secret partagé doit faire au moins 24 caractères (utilisez « Générer »).';
        }

        if ($erreurs) {
            flash('erreur', implode(' ', $erreurs));
            aller('reglages', ['s' => $section]);
        }

        // Fusion : clés imbriquées reconstruites à partir de la valeur actuelle complète.
        $modifiees = [];
        transaction('reglages', function (array &$r) use ($nouveaux, &$modifiees) {
            foreach ($nouveaux as $cle => $v) {
                if (strpos($cle, '.') !== false) {
                    [$p, $e] = explode('.', $cle, 2);
                    $parent = array_merge((array)cfg($p), (array)($r[$p] ?? []));
                    if (($parent[$e] ?? null) !== $v) {
                        $modifiees[] = $cle;
                    }
                    $parent[$e] = $v;
                    $r[$p] = $parent;
                } else {
                    if (cfg($cle) !== $v) {
                        $modifiees[] = $cle;
                    }
                    $r[$cle] = $v;
                }
            }
        });
        config_complete(true);
        // Les secrets n'apparaissent jamais dans le journal : seulement le nom du réglage.
        journaliser('reglages_modifies', $titreSection, $modifiees ? implode(', ', $modifiees) : 'aucun changement');

        $message = $modifiees ? 'Réglages enregistrés.' : 'Aucun changement.';
        // L'assistant vocal emporte une copie des consignes : on le met à jour s'il existe.
        $vocalConcerne = array_filter($modifiees, function ($k) { return strpos($k, 'vapi') === 0 || $k === 'sam_connaissances' || $k === 'url_site'; });
        if ($vocalConcerne && (lire('vapi')['assistant_id'] ?? '') !== '') {
            require_once dirname(__DIR__) . '/vapi.php';
            [$ok, $msg] = vapi_synchroniser();
            $message .= ' ' . ($ok ? $msg : 'Assistant vocal non mis à jour : ' . $msg);
        }
        flash($modifiees ? 'ok' : 'info', $message);
        aller('reglages', ['s' => $section]);
    }

    entete('Réglages', 'reglages');
    titre_page('Réglages', "Les modifications s'appliquent immédiatement au site.");
    echo '<div class="reglages"><nav class="onglets" aria-label="Sections des réglages">';
    foreach ($schema as $k => $s) {
        echo '<a href="' . h(url_admin('reglages', ['s' => $k])) . '"' . ($k === $section ? ' aria-current="page"' : '') . '>' . h($s[0]) . '</a>';
    }
    echo '</nav><section class="carte"><h2>' . h($titreSection) . '</h2><p class="petit">' . h($aide) . '</p><form method="post" class="form">' . champ_csrf();
    foreach ($champs as [$cle, $libelle, $type, $opt]) {
        $nom = str_replace('.', '__', $cle);
        $v = valeur_reglage($cle);
        $aideChamp = isset($opt['aide']) ? '<small>' . h($opt['aide']) . '</small>' : '';
        if ($type === 'case') {
            echo '<label class="case"><input type="checkbox" name="' . h($nom) . '" value="1"' . ($v ? ' checked' : '') . '> ' . h($libelle) . '</label>' . $aideChamp;
        } elseif ($type === 'jours') {
            echo '<fieldset class="choix-radio"><legend>' . h($libelle) . '</legend>';
            foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'] as $n => $l) {
                echo '<label><input type="checkbox" name="' . h($nom) . '[]" value="' . $n . '"' . (in_array($n, (array)$v, false) ? ' checked' : '') . '> ' . $l . '</label>';
            }
            echo '</fieldset>';
        } elseif ($type === 'plages') {
            echo '<label>' . h($libelle) . '<input name="' . h($nom) . '" value="' . h(implode(', ', array_map(function ($p) { return $p[0] . '-' . $p[1]; }, (array)$v))) . '">' . $aideChamp . '</label>';
        } elseif ($type === 'nombre') {
            echo '<label>' . h($libelle) . '<input type="number" name="' . h($nom) . '" value="' . h((string)$v) . '" min="' . h((string)($opt['min'] ?? '')) . '" max="' . h((string)($opt['max'] ?? '')) . '">' . $aideChamp . '</label>';
        } elseif ($type === 'secret') {
            echo '<label>' . h($libelle) . ' <span class="gris">(actuelle : ' . h(masquer((string)$v)) . ')</span><input name="' . h($nom) . '" type="password" autocomplete="off" placeholder="Laisser vide pour conserver">' . $aideChamp . '</label>'
                . '<div class="options-secret">' . (!empty($opt['generer']) ? '<label class="case"><input type="checkbox" name="' . h($nom) . '__generer" value="1"> Générer une nouvelle valeur</label>' : '')
                . ((string)$v !== '' ? '<label class="case"><input type="checkbox" name="' . h($nom) . '__effacer" value="1"> Effacer</label>' : '') . '</div>';
        } elseif ($type === 'choix') {
            echo '<label>' . h($libelle) . '<select name="' . h($nom) . '">';
            foreach ($opt['choix'] as $k => $l) {
                echo '<option value="' . h($k) . '"' . ((string)$v === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>';
            }
            echo '</select>' . $aideChamp . '</label>';
        } elseif ($type === 'texte_long') {
            $texte = (string)$v !== '' ? (string)$v : call_user_func($opt['defaut']);
            echo '<label>' . h($libelle) . ' ' . ((string)$v === '' ? badge("texte d'origine", 'gris') : badge('personnalisé', 'prune')) . '<textarea name="' . h($nom) . '" rows="22" class="mono">' . h($texte) . '</textarea></label>'
                . ((string)$v !== '' ? '<label class="case"><input type="checkbox" name="' . h($nom) . '__retablir" value="1"> Rétablir le texte d\'origine</label>' : '')
                . '<small>Écrivez comme à un nouveau collaborateur : qui est SALW, les offres, les métiers, ce que Sam ne doit jamais faire. Aucun prix ni référence inventée.</small>';
        } else {
            $typeHtml = ['email' => 'email', 'url' => 'url', 'date' => 'date'][$type] ?? 'text';
            echo '<label>' . h($libelle) . '<input type="' . $typeHtml . '" name="' . h($nom) . '" value="' . h((string)$v) . '">' . $aideChamp . '</label>';
        }
    }
    echo '<button class="btn plein">Enregistrer</button></form></section>';

    if ($section === 'vocal') {
        $vapi = lire('vapi');
        echo '<section class="carte"><h2>Assistant vocal</h2><p>' . (($vapi['assistant_id'] ?? '') !== '' ? badge('Créé', 'vert') . ' identifiant ' . h($vapi['assistant_id']) . ', mis à jour le ' . h(date_fr((string)$vapi['mis_a_jour'])) : badge('Non créé', 'orange')) . '</p>'
            . '<form method="post" action="' . h(url_admin('vapi')) . '">' . champ_csrf() . '<button class="btn">' . (($vapi['assistant_id'] ?? '') !== '' ? "Mettre à jour l'assistant vocal" : "Créer l'assistant vocal") . '</button></form></section>';
    }
    if ($section === 'ia') {
        $ok = PHP_VERSION_ID >= 80100 && is_file(dirname(__DIR__) . '/vendor/autoload.php');
        echo '<section class="carte"><h2>État</h2><p>PHP ' . h(PHP_VERSION) . ' ' . ($ok ? badge('compatible', 'vert') : badge('PHP 8.1+ et prive/vendor requis', 'rouge')) . ' · clé ' . ((string)cfg('ia_cle') !== '' ? badge('renseignée', 'vert') : badge('absente : Sam reste en mode guidé', 'orange')) . '</p></section>';
    }
    echo '</div>';
    pied();
}

/** Création ou mise à jour de l'assistant vocal (bouton de la section Appel vocal). */
function page_vapi(): void
{
    if (!peut('reglages') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        interdit();
    }
    require_once dirname(__DIR__) . '/vapi.php';
    [$ok, $msg] = vapi_synchroniser();
    journaliser('assistant_vocal', $ok ? 'synchronisé' : 'échec', $msg);
    flash($ok ? 'ok' : 'erreur', $msg);
    aller('reglages', ['s' => 'vocal']);
}
