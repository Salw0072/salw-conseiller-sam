<?php
/**
 * SALW CONSULTING, conseiller Sam : réservation faite pendant un appel vocal.
 *
 * GET appel.php?id=<identifiant de l'appel Vapi>. À la fin de l'appel, la page
 * demande si Sam a réservé un créneau, pour afficher l'écran « Diagnostic confirmé ».
 * L'identifiant d'appel est long et aléatoire, connu du seul navigateur qui a appelé ;
 * la réponse n'est donnée que dans les 2 heures suivant la réservation.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $id)) {
    refuser(422, 'Identifiant invalide.');
}

$trouvee = null;
foreach (array_reverse(lire('reservations')) as $r) {
    if (($r['appel_id'] ?? '') === $id && $r['statut'] === 'confirmee' && strtotime($r['cree_le']) > time() - 7200) {
        $trouvee = $r;
        break;
    }
}
if ($trouvee === null) {
    repondre_json(200, ['ok' => true, 'reservation' => null]);
}
// Rattache la conversation (transcription de l'appel) au rendez-vous pris au téléphone.
$conv = preg_replace('/[^a-f0-9]/', '', (string)($_GET['conversation'] ?? ''));
if ($conv !== '' && ($trouvee['conversation_id'] ?? '') === '') {
    require __DIR__ . '/prive/conversations.php';
    conv_lier_reservation($conv, $trouvee['id']);
    transaction('reservations', function (array &$r) use ($trouvee, $conv) {
        foreach ($r as $i => $x) {
            if ($x['id'] === $trouvee['id']) {
                $r[$i]['conversation_id'] = $conv;
            }
        }
    });
}

repondre_json(200, ['ok' => true, 'reservation' => [
    'id'      => $trouvee['id'],
    'jeton'   => $trouvee['jeton'],
    'debut'   => $trouvee['debut'],
    'fin'     => $trouvee['fin'],
    'visio'   => $trouvee['visio'],
    'ics'     => generer_ics($trouvee),
    'mail'    => true,
    'rappels' => array_map(function ($x) { return ['quand' => $x['quand'], 'canaux' => $x['canaux']]; }, rappels_prevus($trouvee)),
]]);
