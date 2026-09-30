<?php
/**
 * SALW CONSULTING, conseiller Sam : réception des fiches de qualification Sam
 * et des demandes de créneau (repli quand la réservation en ligne est indisponible).
 *
 * Répond en JSON : {"ok":true} ou {"ok":false,"error":"..."}. Si ce script
 * échoue, la page propose un lien mailto: pré-rempli : aucune demande perdue.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/conversations.php';

exiger_post();
$verrou = limiter_debit('contact', 30);

$nom     = champ('name', 120);
$email   = champ('email', 180);
$type    = champ('type', 120);
$message = texte('message', 5000);

if ($nom === '' || $email === '' || $type === '' || $message === '') {
    refuser(422, 'Tous les champs sont obligatoires.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    refuser(422, 'Adresse e-mail invalide.');
}

$origine = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'le site'));
$corps = "Nouvelle demande depuis {$origine}\n\n"
       . "Nom      : {$nom}\n"
       . "E-mail   : {$email}\n"
       . "Objet    : {$type}\n"
       . "Reçue le : " . date_lisible(gmdate('Y-m-d\TH:i:s\Z')) . "\n\n"
       . "{$message}\n";

// Gardée aussi dans l'espace admin (page Demandes), 30 jours.
demande_enregistrer($nom, $email, $type, $message);

// Répondre au visiteur en un clic : son adresse (validée) va en Reply-To.
if (!envoyer_mail((string)cfg('destinataire'), 'SALW · ' . $type . ' · ' . $nom, $corps, $email)) {
    refuser(500, "L'envoi a échoué côté serveur.");
}

@touch($verrou);
repondre_json(200, ['ok' => true]);
