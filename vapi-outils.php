<?php
/**
 * SALW CONSULTING, conseiller Sam : outils de l'appel vocal, appelés par Vapi.
 *
 * Vapi envoie {"message": {"type": "tool-calls", "toolCallList": [...], "call": {...}}}
 * avec l'en-tête secret X-Salw-Secret défini à la création de l'assistant (admin.php).
 * Réponse attendue par Vapi, toujours en HTTP 200 : {"results": [{"toolCallId", "result"|"error"}]}.
 *
 * Outils : creneaux_disponibles (fuseau), reserver_diagnostic (debut, nom, email,
 * activite, resume, fuseau). La réservation passe par la même fonction que le site.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/reservation.php';

header('Content-Type: application/json; charset=utf-8');

$secret = (string)cfg('vapi_secret');
$recu = (string)($_SERVER['HTTP_X_SALW_SECRET'] ?? '');
if (strlen($secret) < 24 || !hash_equals($secret, $recu)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Accès refusé.']));
}

$corps = json_decode((string)file_get_contents('php://input', false, null, 0, 200000), true);
$message = is_array($corps) ? ($corps['message'] ?? []) : [];
if (($message['type'] ?? '') !== 'tool-calls') {
    // Autres notifications de Vapi : rien à faire.
    exit(json_encode(['ok' => true]));
}

$appelId = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($message['call']['id'] ?? ''));
$appels = (array)($message['toolCallList'] ?? []);
$resultats = [];

foreach ($appels as $appel) {
    $id = (string)($appel['id'] ?? '');
    $nom = (string)($appel['function']['name'] ?? ($appel['name'] ?? ''));
    $args = $appel['function']['arguments'] ?? ($appel['arguments'] ?? []);
    if (is_string($args)) {
        $args = json_decode($args, true);
    }
    $args = is_array($args) ? $args : [];
    $tz = fuseau_sur(mb_substr((string)($args['fuseau'] ?? ''), 0, 60));

    if ($nom === 'creneaux_disponibles') {
        $liste = [];
        foreach (creneaux_libres(lire('reservations'), 6) as $c) {
            $liste[] = preg_replace('/ \d{4} à /', ' à ', date_lisible($c['debut'], $tz)) . ' ; début exact : ' . $c['debut'];
        }
        $resultats[] = ['toolCallId' => $id, 'result' => $liste
            ? "Créneaux libres, dans l'heure du visiteur :\n" . implode("\n", $liste)
            : "Aucun créneau libre dans les trois prochaines semaines. Propose d'écrire à albert@salw-consulting.com."];
        continue;
    }

    if ($nom === 'reserver_diagnostic') {
        $debut = (string)($args['debut'] ?? '');
        $nomVisiteur = trim(str_replace(["\r", "\n"], ' ', mb_substr((string)($args['nom'] ?? ''), 0, 120)));
        $email = strtolower(trim(str_replace(' ', '', (string)($args['email'] ?? ''))));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $debut)) {
            $resultats[] = ['toolCallId' => $id, 'error' => 'Début de créneau invalide : reprends un début exact donné par creneaux_disponibles.'];
            continue;
        }
        if ($nomVisiteur === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resultats[] = ['toolCallId' => $id, 'error' => "Nom ou adresse e-mail invalide : fais épeler de nouveau l'adresse."];
            continue;
        }
        $resume = mb_substr(trim((string)($args['resume'] ?? '')), 0, 600);
        $res = effectuer_reservation([
            'debut'          => $debut,
            'nom'            => $nomVisiteur,
            'email'          => $email,
            'telephone'      => '',
            'activite'       => mb_substr(trim((string)($args['activite'] ?? '')), 0, 160),
            'contexte'       => "Fiche de qualification Sam (appel vocal)\n\nRésumé validé : {$resume}",
            'fuseau'         => $tz,
            'remplace_id'    => '',
            'remplace_jeton' => '',
            'canal'          => 'appel',
            'appel_id'       => $appelId,
            'qualif'         => [
                'secteur'  => in_array($args['secteur'] ?? '', ['juridique', 'sante', 'immobilier', 'artisan', 'coach', 'ecole', 'garage', 'grands', 'autre'], true) ? $args['secteur'] : '',
                'probleme' => in_array($args['probleme'] ?? '', ['demandes', 'rdv', 'devis', 'fidelite', 'autre'], true) ? $args['probleme'] : '',
            ],
        ]);
        if ($res['code'] === 200) {
            $resultats[] = ['toolCallId' => $id, 'result' => 'Réservé pour le ' . preg_replace('/ \(heure de .*\)$/', '', date_lisible($res['json']['debut'], $tz))
                . ". E-mail de confirmation envoyé à {$email}" . ($res['json']['mail'] ? '' : " (l'envoi a échoué : Albert écrira directement)") . '.'];
        } elseif ($res['code'] === 409) {
            $resultats[] = ['toolCallId' => $id, 'error' => 'Ce créneau vient d\'être pris. Rappelle creneaux_disponibles et propose-en un autre.'];
        } else {
            $resultats[] = ['toolCallId' => $id, 'error' => (string)($res['json']['error'] ?? 'Réservation impossible.')];
        }
        continue;
    }

    $resultats[] = ['toolCallId' => $id, 'error' => 'Outil inconnu.'];
}

echo json_encode(['results' => $resultats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
