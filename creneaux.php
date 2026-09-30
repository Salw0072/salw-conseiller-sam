<?php
/**
 * SALW CONSULTING, conseiller Sam : créneaux libres du diagnostic de 30 minutes.
 * Répond en JSON, heures en UTC ; la page les convertit dans le fuseau du visiteur.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

$reservations = lire('reservations');
$c = canaux();
repondre_json(200, [
    'ok'        => true,
    'duree'     => (int)cfg('duree'),
    // Le site ne demande un numéro de mobile que si un rappel SMS ou WhatsApp peut partir.
    'telephone' => $c['sms'] || $c['whatsapp'],
    'creneaux' => creneaux_libres($reservations, 120),
]);
