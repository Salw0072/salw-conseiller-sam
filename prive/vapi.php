<?php
/**
 * SALW CONSULTING, conseiller Sam : création et mise à jour de l'assistant vocal Vapi.
 *
 * Appelé depuis admin.php (bouton « Créer / mettre à jour l'assistant vocal »), avec la
 * clé privée Vapi de config.php. L'identifiant de l'assistant est gardé dans
 * prive/donnees/vapi.json ; ia.php le transmet ensuite à la page.
 */

declare(strict_types=1);

require_once __DIR__ . '/sam-connaissances.php';

/** Configuration complète de l'assistant, telle qu'envoyée à l'API Vapi. */
function vapi_assistant(): array
{
    $serveur = [
        'url'            => url_publique() . '/vapi-outils.php',
        'headers'        => ['X-Salw-Secret' => (string)cfg('vapi_secret')],
        'timeoutSeconds' => 20,
    ];
    return [
        'name'             => 'Sam · SALW CONSULTING',
        'firstMessage'     => (string)cfg('vapi_premier_message'),
        'firstMessageMode' => 'assistant-speaks-first',
        'model' => [
            'provider'    => 'anthropic',
            'model'       => (string)cfg('vapi_modele'),
            'messages'    => [['role' => 'system', 'content' => sam_prompt('appel')]],
            'temperature' => 0.4,
            'maxTokens'   => 600,
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'creneaux_disponibles',
                        'description' => "Liste les prochains créneaux libres du diagnostic de 30 minutes, dans l'heure du visiteur.",
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['fuseau' => ['type' => 'string', 'description' => 'Fuseau horaire du visiteur, ex. Europe/Paris.']],
                            'required' => ['fuseau'],
                        ],
                    ],
                    'server' => $serveur,
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'reserver_diagnostic',
                        'description' => "Réserve le diagnostic, une fois le créneau choisi et l'adresse e-mail épelée puis confirmée.",
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'debut'    => ['type' => 'string', 'description' => 'Début exact renvoyé par creneaux_disponibles.'],
                                'nom'      => ['type' => 'string'],
                                'email'    => ['type' => 'string', 'description' => 'Adresse confirmée par le visiteur.'],
                                'activite' => ['type' => 'string'],
                                'resume'   => ['type' => 'string', 'description' => 'Le résumé validé par le visiteur.'],
                                'fuseau'   => ['type' => 'string'],
                                'secteur'  => ['type' => 'string', 'enum' => ['juridique', 'sante', 'immobilier', 'artisan', 'coach', 'ecole', 'garage', 'grands', 'autre'], 'description' => 'Secteur le plus proche.'],
                                'probleme' => ['type' => 'string', 'enum' => ['demandes', 'rdv', 'devis', 'fidelite', 'autre'], 'description' => 'Problème principal.'],
                            ],
                            'required' => ['debut', 'nom', 'email', 'activite', 'resume', 'fuseau'],
                        ],
                    ],
                    'server' => $serveur,
                ],
            ],
        ],
        'voice'              => (array)cfg('vapi_voix'),
        'transcriber'        => (array)cfg('vapi_transcription'),
        'maxDurationSeconds' => (int)cfg('vapi_duree_max'),
        'endCallMessage'     => 'Merci, et à bientôt.',
        'metadata'           => ['source' => 'salw-conseiller-sam'],
    ];
}

/** Crée l'assistant, ou le met à jour s'il existe déjà. Renvoie [ok, message]. */
function vapi_synchroniser(): array
{
    $cle = (string)cfg('vapi_cle_privee');
    if ($cle === '' || (string)cfg('vapi_cle_publique') === '') {
        return [false, 'Renseigner vapi_cle_publique et vapi_cle_privee dans prive/config.php.'];
    }
    if (strlen((string)cfg('vapi_secret')) < 24) {
        return [false, 'Renseigner vapi_secret (24 caractères aléatoires au moins) dans prive/config.php.'];
    }
    if (strpos(url_publique(), 'https://') !== 0) {
        return [false, "Le site doit être en ligne en HTTPS : renseigner url_site dans prive/config.php (Vapi doit pouvoir joindre vapi-outils.php)."];
    }
    if (!function_exists('curl_init')) {
        return [false, "L'extension PHP curl est absente sur cet hébergement."];
    }

    $existant = (string)(lire('vapi')['assistant_id'] ?? '');
    $url = 'https://api.vapi.ai/assistant' . ($existant !== '' ? '/' . rawurlencode($existant) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $existant !== '' ? 'PATCH' : 'POST',
        CURLOPT_POSTFIELDS     => json_encode(vapi_assistant(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cle, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $brut = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rep = json_decode((string)$brut, true);

    if ($code === 404 && $existant !== '') {
        // Assistant supprimé côté Vapi : on repart de zéro.
        transaction('vapi', function (array &$v) { $v = []; });
        return vapi_synchroniser();
    }
    if ($code < 200 || $code >= 300 || !isset($rep['id'])) {
        $detail = is_array($rep) ? json_encode($rep['message'] ?? $rep, JSON_UNESCAPED_UNICODE) : 'réponse illisible';
        return [false, "Vapi a refusé la configuration (HTTP {$code}) : " . mb_substr((string)$detail, 0, 400)];
    }
    $id = (string)$rep['id'];
    transaction('vapi', function (array &$v) use ($id) {
        $v = ['assistant_id' => $id, 'mis_a_jour' => gmdate('Y-m-d\TH:i:s\Z')];
    });
    return [true, ($existant !== '' ? 'Assistant vocal mis à jour' : 'Assistant vocal créé') . " (identifiant {$id})."];
}
