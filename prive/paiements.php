<?php
/**
 * SALW CONSULTING, site Sam : demandes de paiement (acomptes, factures des offres B, C et D).
 *
 * Albert crée une demande dans l'espace admin (Paiements) ; le client reçoit un lien vers payer.php,
 * paie par Stripe (carte, prélèvement SEPA) ou PayPal, et la demande passe à « payé ».
 * Stockage : prive/donnees/paiements.json. Le lien porte un jeton aléatoire, jamais l'identifiant seul.
 */

declare(strict_types=1);

require_once __DIR__ . '/paiement-api.php';

const STATUTS_DEMANDE = ['en_attente' => 'En attente', 'en_cours' => 'Prélèvement en cours', 'paye' => 'Payé', 'echec' => 'Échec', 'annule' => 'Annulé'];

/** Adresse racine du site (sans /admin), pour les liens envoyés aux clients. */
function site_racine(): string
{
    $site = rtrim((string)cfg('url_site'), '/');
    return $site !== '' ? $site : (string)preg_replace('#/admin$#', '', url_base());
}

function lien_paiement(array $p): string
{
    return site_racine() . '/payer.php?t=' . $p['jeton'];
}

function montant_lisible(float $m): string
{
    return number_format($m, 2, ',', ' ') . ' €';
}

/** Crée une demande ; renvoie [demande, erreur]. */
function creer_demande_paiement(array $d, string $auteur): array
{
    $montant = round((float)str_replace([' ', ','], ['', '.'], (string)($d['montant'] ?? '0')), 2);
    $email = mb_strtolower(trim((string)($d['client_email'] ?? '')));
    $libelle = mb_substr(trim((string)($d['libelle'] ?? '')), 0, 120);
    if ($libelle === '' || $montant < 1 || $montant > 100000) {
        return [null, 'Libellé et montant (entre 1 et 100 000 €) nécessaires.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [null, 'Adresse e-mail du client invalide.'];
    }
    $p = [
        'id' => bin2hex(random_bytes(6)), 'jeton' => bin2hex(random_bytes(16)), 'libelle' => $libelle, 'montant' => $montant,
        'client_nom' => mb_substr(trim((string)($d['client_nom'] ?? '')), 0, 80), 'client_email' => $email,
        'offre' => in_array($d['offre'] ?? '', ['A', 'B', 'C', 'D'], true) ? $d['offre'] : '', 'note' => mb_substr(trim((string)($d['note'] ?? '')), 0, 500),
        'statut' => 'en_attente', 'fournisseur' => '', 'ref' => '', 'cree_le' => gmdate('Y-m-d\TH:i:s\Z'), 'paye_le' => '', 'cree_par' => $auteur,
    ];
    transaction('paiements', function (array &$liste) use ($p) { $liste[] = $p; });
    return [$p, ''];
}

function demande_par_jeton(string $jeton): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) {
        return null;
    }
    foreach (lire('paiements') as $p) {
        if (hash_equals($p['jeton'], $jeton)) {
            return $p;
        }
    }
    return null;
}

/** Modifie une demande sous verrou ; renvoie la demande à jour. */
function modifier_demande(string $id, array $champs): ?array
{
    return transaction('paiements', function (array &$liste) use ($id, $champs) {
        foreach ($liste as $i => $p) {
            if ($p['id'] === $id) {
                $liste[$i] = array_merge($p, $champs);
                return $liste[$i];
            }
        }
        return null;
    });
}

/** Paiement confirmé : statut, avis à Albert, reçu au client. Sans effet si déjà payé. */
function marquer_paye(string $id, string $fournisseur, string $ref): void
{
    $avant = null;
    foreach (lire('paiements') as $p) {
        if ($p['id'] === $id) {
            $avant = $p;
        }
    }
    if (!$avant || $avant['statut'] === 'paye') {
        return;
    }
    $p = modifier_demande($id, ['statut' => 'paye', 'fournisseur' => $fournisseur, 'ref' => $ref, 'paye_le' => gmdate('Y-m-d\TH:i:s\Z')]);
    $quoi = $p['libelle'] . ' · ' . montant_lisible((float)$p['montant']);
    envoyer_mail((string)cfg('destinataire'), 'Paiement reçu · ' . $quoi,
        "Paiement reçu par " . ($fournisseur === 'stripe' ? 'Stripe' : 'PayPal') . ".\n\nClient : " . trim($p['client_nom'] . ' ' . $p['client_email']) . "\nObjet : {$quoi}\nRéférence : {$ref}\n", $p['client_email']);
    envoyer_mail($p['client_email'], 'Paiement bien reçu · SALW CONSULTING',
        "Bonjour" . ($p['client_nom'] !== '' ? ' ' . explode(' ', $p['client_nom'])[0] : '') . ",\n\nNous avons bien reçu votre paiement : {$quoi}.\n"
        . ($fournisseur === 'stripe' ? "Le reçu de Stripe vous parvient par un e-mail séparé.\n" : "Le reçu de PayPal vous parvient par un e-mail séparé.\n")
        . "\nMerci de votre confiance.\n\nSALW CONSULTING\n", (string)cfg('destinataire'));
}

// --- Stripe ------------------------------------------------------------------------------------

function stripe_ouvrir_demande(array $p): string
{
    $retour = site_racine() . '/payer.php?t=' . $p['jeton'];
    $s = stripe('POST', '/v1/checkout/sessions', [
        'mode' => 'payment', 'locale' => 'fr', 'customer_email' => $p['client_email'],
        'line_items' => [['price_data' => ['currency' => 'eur', 'unit_amount' => centimes((float)$p['montant']), 'product_data' => ['name' => $p['libelle']]], 'quantity' => 1]],
        'metadata' => ['paiement_id' => $p['id']], 'payment_intent_data' => ['metadata' => ['paiement_id' => $p['id']], 'description' => $p['libelle']],
        'invoice_creation' => ['enabled' => 'true'], 'billing_address_collection' => 'required', 'tax_id_collection' => ['enabled' => 'true'],
        'success_url' => $retour . '&stripe={CHECKOUT_SESSION_ID}', 'cancel_url' => $retour . '&annule=1',
    ], 'demande-' . $p['id'] . '-' . time());
    return (string)$s['url'];
}

/** Vérifie une session Stripe au retour du client ou par notification ; renvoie le statut retenu. */
function stripe_constater(array $s): string
{
    $id = (string)($s['metadata']['paiement_id'] ?? '');
    if ($id === '' || ($s['mode'] ?? '') !== 'payment') {
        return '';
    }
    if (($s['payment_status'] ?? '') === 'paid') {
        marquer_paye($id, 'stripe', (string)($s['payment_intent'] ?? $s['id']));
        return 'paye';
    }
    if (($s['status'] ?? '') === 'complete') {
        modifier_demande($id, ['statut' => 'en_cours', 'fournisseur' => 'stripe']);
        return 'en_cours';
    }
    return '';
}

// --- PayPal ------------------------------------------------------------------------------------

function paypal_ouvrir_demande(array $p): string
{
    $retour = site_racine() . '/payer.php?t=' . $p['jeton'];
    $o = paypal('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [['reference_id' => $p['id'], 'custom_id' => $p['id'], 'description' => mb_substr($p['libelle'], 0, 127),
            'amount' => ['currency_code' => 'EUR', 'value' => montant_paypal((float)$p['montant'])]]],
        'payment_source' => ['paypal' => ['experience_context' => ['brand_name' => 'SALW CONSULTING', 'locale' => 'fr-FR', 'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW', 'return_url' => $retour . '&paypal=1', 'cancel_url' => $retour . '&annule=1']]],
    ], 'demande-' . $p['id'] . '-' . time());
    modifier_demande($p['id'], ['paypal_commande' => (string)$o['id']]);
    $lien = paypal_lien($o, 'payer-action', 'approve');
    if ($lien === '') {
        throw new RuntimeException('PayPal n\'a pas renvoyé de lien de paiement.');
    }
    return $lien;
}

/** Retour du client depuis PayPal : encaisse la commande (sans cela, rien n'est prélevé). */
function paypal_encaisser(array $p, string $commande): string
{
    if (!preg_match('/^[A-Z0-9]+$/', $commande) || $commande !== (string)($p['paypal_commande'] ?? '')) {
        return 'Retour PayPal non reconnu.';
    }
    $r = paypal('POST', '/v2/checkout/orders/' . $commande . '/capture', null, 'capture-' . $commande);
    $capture = $r['purchase_units'][0]['payments']['captures'][0] ?? [];
    if (($r['status'] ?? '') === 'COMPLETED' && ($capture['status'] ?? '') === 'COMPLETED') {
        marquer_paye($p['id'], 'paypal', (string)($capture['id'] ?? $commande));
        return '';
    }
    return 'PayPal n\'a pas confirmé le paiement.';
}
