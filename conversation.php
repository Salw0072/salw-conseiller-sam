<?php
/**
 * SALW CONSULTING, conseiller Sam : enregistrement d'une conversation avec Sam.
 *
 * La page envoie la conversation au fil de l'échange (chat guidé, chat IA ou appel vocal),
 * pour qu'Albert retrouve dans l'espace admin les prospects qui n'ont pas pris rendez-vous.
 * Corps JSON : {id, canal, messages: [{role: "visiteur"|"sam", texte}], qualif: {...}, fuseau}.
 * Conservée 30 jours sans rendez-vous (réglage conservation_conversations_jours).
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/conversations.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    refuser(405, 'Méthode non autorisée.');
}
$corps = json_decode((string)file_get_contents('php://input', false, null, 0, 300000), true);
if (!is_array($corps)) {
    refuser(422, 'Requête invalide.');
}
$id = (string)($corps['id'] ?? '');
if (!preg_match('/^[a-f0-9]{24,40}$/', $id)) {
    refuser(422, 'Identifiant invalide.');
}

// Messages : texte seulement, bornés.
$messages = [];
foreach (array_slice((array)($corps['messages'] ?? []), 0, 120) as $m) {
    $texte = trim(mb_substr((string)($m['texte'] ?? ''), 0, 2000));
    if ($texte !== '') {
        $messages[] = ['role' => ($m['role'] ?? '') === 'visiteur' ? 'visiteur' : 'sam', 'texte' => $texte];
    }
}
$nbVisiteur = count(array_filter($messages, function ($m) { return $m['role'] === 'visiteur'; }));
if ($nbVisiteur === 0) {
    repondre_json(200, ['ok' => true, 'ignore' => true]);
}

$q = (array)($corps['qualif'] ?? []);
$qualif = [
    'secteur'  => in_array($q['secteur'] ?? '', SECTEURS, true) ? $q['secteur'] : '',
    'probleme' => in_array($q['probleme'] ?? '', PROBLEMES, true) ? $q['probleme'] : '',
];
foreach (['activite' => 160, 'ville' => 80, 'equipe' => 60, 'resume' => 400] as $cle => $max) {
    $qualif[$cle] = trim(str_replace(["\r", "\n"], ' ', mb_substr((string)($q[$cle] ?? ''), 0, $max)));
}
$canal = in_array($corps['canal'] ?? '', CANAUX_CONVERSATION, true) ? $corps['canal'] : 'guide';

// Garde-fou : 300 enregistrements par heure et par visiteur (la page enregistre au fil de l'eau).
$ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
$ok = transaction('conversations-debit', function (array &$d) use ($ip) {
    $h = gmdate('YmdH');
    if (($d['heure'] ?? '') !== $h) {
        $d = ['heure' => $h, 'ip' => []];
    }
    $d['ip'][$ip] = ($d['ip'][$ip] ?? 0) + 1;
    return $d['ip'][$ip] <= 300;
});
if (!$ok) {
    refuser(429, 'Trop de requêtes.');
}

conv_enregistrer([
    'id'       => $id,
    'canaux'   => [$canal],
    'messages' => $messages,
    'qualif'   => $qualif,
    'fuseau'   => fuseau_sur(mb_substr((string)($corps['fuseau'] ?? ''), 0, 60))->getName(),
]);

// Rendez-vous déjà enregistré avant la conversation (ordre d'arrivée des requêtes) : on rattache.
if ((lire('conversations')[$id]['reservation_id'] ?? '') === '') {
    foreach (lire('reservations') as $r) {
        if (($r['conversation_id'] ?? '') === $id && $r['statut'] === 'confirmee') {
            conv_lier_reservation($id, $r['id']);
            break;
        }
    }
}
repondre_json(200, ['ok' => true]);
