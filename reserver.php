<?php
/**
 * SALW CONSULTING, conseiller Sam : réservation (ou déplacement) depuis le site.
 *
 * Valide la saisie, applique la limite de débit, puis délègue à
 * effectuer_reservation() (prive/reservation.php), partagée avec l'appel vocal.
 * Déplacement (« Changer de créneau ») : remplace_id + remplace_jeton.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/reservation.php';

exiger_post();

$remplaceId  = preg_replace('/[^a-f0-9]/', '', champ('remplace_id', 20));
$remplaceJet = preg_replace('/[^a-f0-9]/', '', champ('remplace_jeton', 40));
$deplacement = $remplaceId !== '' && $remplaceJet !== '';

// Un déplacement suit souvent de près la réservation : pas de limite de débit pour lui,
// il exige de toute façon le jeton secret du rendez-vous.
$verrou = $deplacement ? '' : limiter_debit('reserver', 20);

$debut    = champ('debut', 25);
$nom      = champ('name', 120);
$email    = champ('email', 180);
$telSaisi = champ('telephone', 30);

if ($nom === '' || $email === '' || $debut === '') {
    refuser(422, 'Nom, e-mail et créneau sont obligatoires.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    refuser(422, 'Adresse e-mail invalide.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $debut)) {
    refuser(422, 'Créneau invalide.');
}
$telephone = '';
if ($telSaisi !== '') {
    $telephone = telephone_valide($telSaisi);
    if ($telephone === '') {
        refuser(422, 'Numéro de mobile invalide : utilisez le format international, par exemple +47 948 36 971.');
    }
}

$res = effectuer_reservation([
    'debut'          => $debut,
    'nom'            => $nom,
    'email'          => $email,
    'telephone'      => $telephone,
    'activite'       => champ('activite', 160),
    'contexte'       => texte('contexte', 8000),
    'fuseau'         => fuseau_sur(champ('fuseau', 60)),
    'remplace_id'    => $remplaceId,
    'remplace_jeton' => $remplaceJet,
    'canal'          => 'site',
    'conversation_id' => preg_replace('/[^a-f0-9]/', '', champ('conversation_id', 40)),
    'qualif'         => [
        'secteur'  => in_array(champ('secteur', 20), ['juridique', 'sante', 'immobilier', 'artisan', 'coach', 'ecole', 'garage', 'grands', 'autre'], true) ? champ('secteur', 20) : '',
        'probleme' => in_array(champ('probleme', 20), ['demandes', 'rdv', 'devis', 'fidelite', 'autre'], true) ? champ('probleme', 20) : '',
    ],
]);

if ($res['code'] === 200 && $verrou !== '') {
    @touch($verrou);
}
repondre_json($res['code'], $res['json']);
