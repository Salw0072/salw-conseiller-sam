<?php
/**
 * SALW CONSULTING, site Sam : notifications Stripe des demandes de paiement.
 *
 * Utile surtout pour le prélèvement SEPA, confirmé par la banque quelques jours après le retour du client.
 * À déclarer dans Stripe › Développeurs › Webhooks, adresse https://<site>/stripe-webhook.php, événements :
 * checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed.
 * Le secret de signature (whsec_…) va dans Réglages › Paiements.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/paiements.php';
require __DIR__ . '/prive/livres.php';

$corps = (string)file_get_contents('php://input', false, null, 0, 1000000);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !stripe_signature_valide($corps, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), (string)cfg('stripe_secret_webhook'))) {
    repondre_json(400, ['ok' => false]);
}
$e = json_decode($corps, true);
$s = is_array($e) ? ($e['data']['object'] ?? []) : [];
switch ($e['type'] ?? '') {
    case 'checkout.session.completed':
    case 'checkout.session.async_payment_succeeded':
        if (!empty($s['metadata']['vente_id'])) {
            stripe_constater_vente($s);   // livre : envoyé même si l'acheteur a fermé la page avant le retour
        } else {
            stripe_constater($s);
        }
        break;
    case 'checkout.session.async_payment_failed':
        if (!empty($s['metadata']['paiement_id'])) {
            modifier_demande((string)$s['metadata']['paiement_id'], ['statut' => 'echec']);
            envoyer_mail((string)cfg('destinataire'), 'Prélèvement refusé · ' . ($s['customer_details']['email'] ?? ''), "Un prélèvement SEPA a été refusé par la banque du client. Voir Paiements dans l'espace admin.\n");
        }
        break;
}
repondre_json(200, ['ok' => true]);
