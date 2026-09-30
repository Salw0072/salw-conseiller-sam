<?php
/**
 * SALW CONSULTING, conseiller Sam : rappels automatiques des diagnostics.
 *
 * À lancer toutes les 15 minutes par une tâche cron Hostinger (hPanel > Avancé > Tâches cron) :
 *     php /home/<compte>/public_html/<dossier>/rappels.php
 * ou, si l'hébergeur ne propose qu'un cron par URL, avec la clé cron_cle de config.php :
 *     https://<site>/rappels.php?cle=<cron_cle>
 *
 * J-1 : la veille (e-mail, et WhatsApp si configuré et numéro donné), seulement si le
 *       rendez-vous a été pris plus de 24 h avant.
 * H-2 : 2 heures avant (SMS si configuré et numéro donné, sinon e-mail), avec le lien visio.
 * Chaque rappel est marqué « envoyé » AVANT l'envoi : deux passages simultanés du cron
 * ne peuvent pas l'expédier deux fois.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

if (PHP_SAPI !== 'cli') {
    $cle = (string)cfg('cron_cle');
    if ($cle === '' || !hash_equals($cle, (string)($_GET['cle'] ?? ''))) {
        http_response_code(403);
        exit('Accès refusé.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$maintenant = time();

// 1. Sélection et marquage sous verrou.
$aEnvoyer = transaction('reservations', function (array &$reservations) use ($maintenant) {
    $liste = [];
    foreach ($reservations as $i => $r) {
        if ($r['statut'] !== 'confirmee') {
            continue;
        }
        $debut = strtotime($r['debut']);
        if ($debut <= $maintenant) {
            continue;
        }
        $envoyes = (array)($r['rappels_envoyes'] ?? []);
        $prisTot = $debut - strtotime($r['cree_le']) > 24 * 3600;
        if (cfg('rappel_j1') && $prisTot && !in_array('j1', $envoyes, true)
            && $maintenant >= $debut - 24 * 3600 && $debut - $maintenant > 3 * 3600) {
            $reservations[$i]['rappels_envoyes'][] = 'j1';
            $liste[] = ['j1', $r];
        }
        if (cfg('rappel_h2') && !in_array('h2', $envoyes, true) && $maintenant >= $debut - 2 * 3600) {
            $reservations[$i]['rappels_envoyes'][] = 'h2';
            $liste[] = ['h2', $r];
        }
    }
    return $liste;
});

// 2. Envois, hors verrou.
$c = canaux();
$bilan = [];
foreach ($aEnvoyer as $e) {
    list($type, $r) = $e;
    $tz = fuseau_sur((string)($r['fuseau'] ?? ''));
    $quand = date_lisible($r['debut'], $tz);
    $prenom = explode(' ', $r['nom'])[0];
    $tel = (string)($r['telephone'] ?? '');
    $lienAnnulation = '';
    if (PHP_SAPI !== 'cli' || (string)cfg('url_site') !== '') {
        $lienAnnulation = url_publique() . '/annuler.php?r=' . $r['id'] . '&j=' . $r['jeton'];
    }

    if ($type === 'j1') {
        $ok = envoyer_mail($r['email'], 'Rappel : votre diagnostic SALW demain · ' . $quand,
            "Bonjour {$prenom},\n\nPetit rappel : votre diagnostic de 30 minutes avec Albert a lieu demain, {$quand}.\n\n"
            . "Lien de la visio : {$r['visio']}\n\n"
            . "Si ce n'est pas déjà fait, répondez à l'e-mail de confirmation avec le questionnaire de préparation : nous gagnerons un temps précieux.\n"
            . ($lienAnnulation !== '' ? "\nUn empêchement ? {$lienAnnulation}\n" : "\nUn empêchement ? Utilisez le lien d'annulation de l'e-mail de confirmation.\n")
            . "\nSALW CONSULTING\n",
            (string)cfg('destinataire'), generer_ics($r));
        if ($tel !== '' && $c['whatsapp']) {
            envoyer_message('whatsapp', $tel, "Bonjour {$prenom}, rappel de votre diagnostic SALW CONSULTING demain, {$quand}. Lien visio : {$r['visio']}");
        }
    } else {
        if ($tel !== '' && $c['sms']) {
            $ok = envoyer_message('sms', $tel, "SALW : votre diagnostic commence dans 2 h ({$quand}). Visio : {$r['visio']}");
        } else {
            $ok = envoyer_mail($r['email'], 'Dans 2 heures : votre diagnostic SALW',
                "Bonjour {$prenom},\n\nVotre diagnostic avec Albert commence dans 2 heures, {$quand}.\n\n"
                . "Rejoindre la visio : {$r['visio']}\n\nÀ tout à l'heure,\nSALW CONSULTING\n",
                (string)cfg('destinataire'));
        }
    }
    $bilan[] = strtoupper($type) . ' ' . $r['id'] . ' ' . $r['email'] . ' : ' . ($ok ? 'envoyé' : 'ÉCHEC');
}

echo $bilan ? implode("\n", $bilan) . "\n" : "Aucun rappel à envoyer.\n";

// Purge RGPD (conversations sans rendez-vous, demandes, anciens rendez-vous).
require __DIR__ . '/prive/conversations.php';
$purges = purger_donnees();
if ($purges > 0) {
    echo "Purge : {$purges} élément(s) supprimé(s).\n";
}
