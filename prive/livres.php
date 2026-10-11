<?php
/**
 * SALW CONSULTING, site Sam : boutique de livres numériques d'Albert (section « Livres » du site).
 *
 * Albert ajoute ses livres dans l'espace admin (Livres) : titre, description, prix, couverture, PDF.
 * Le lecteur achète sur acheter.php (Stripe par carte, ou PayPal), reçoit un lien personnel par e-mail
 * et télécharge sur telecharger.php un PDF marqué à son nom en bas de chaque page.
 * Lien valable LIVRE_JOURS jours et LIVRE_TELECHARGEMENTS téléchargements ; Albert peut en renvoyer un neuf.
 *
 * Stockage : prive/donnees/livres.json (catalogue), ventes.json (achats), fichiers dans prive/donnees/livres/,
 * jamais accessibles directement depuis le web.
 */

declare(strict_types=1);

require_once __DIR__ . '/paiements.php';
require_once __DIR__ . '/vendor/autoload.php';

const LIVRE_JOURS = 7;
const LIVRE_TELECHARGEMENTS = 5;
const LIVRE_PDF_MAX = 80 * 1024 * 1024;
const LIVRE_IMAGE_MAX = 4 * 1024 * 1024;
const STATUTS_VENTE = ['en_attente' => 'En attente', 'paye' => 'Payé', 'rembourse' => 'Remboursé'];

function dossier_livres(): string
{
    $d = dossier_donnees() . '/livres';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

function livres(bool $publiesSeulement = false): array
{
    $l = lire('livres');
    if ($publiesSeulement) {
        $l = array_values(array_filter($l, function ($x) { return !empty($x['publie']) && is_file(dossier_livres() . '/' . $x['fichier']); }));
    }
    usort($l, function ($a, $b) { return [(int)$a['ordre'], $a['cree_le']] <=> [(int)$b['ordre'], $b['cree_le']]; });
    return $l;
}

function livre(string $id): ?array
{
    foreach (lire('livres') as $l) {
        if ($l['id'] === $id) {
            return $l;
        }
    }
    return null;
}

/** Ce que la page publique peut afficher d'un livre (jamais le nom du fichier). */
function livre_public(array $l): array
{
    return [
        'id' => $l['id'], 'titre' => $l['titre'], 'sous_titre' => $l['sous_titre'], 'description' => $l['description'],
        'prix' => (float)$l['prix'], 'prix_texte' => montant_lisible((float)$l['prix']), 'pages' => (int)$l['pages'],
        'couverture' => $l['couverture'] !== '' ? 'couverture.php?l=' . $l['id'] . '&v=' . substr(md5($l['maj_le']), 0, 8) : '',
        'achat' => 'acheter.php?l=' . $l['id'],
    ];
}

function slug_livre(string $titre): string
{
    $s = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre));
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 60) : 'livre';
}

/**
 * Vérifie et range un fichier envoyé depuis l'admin ; renvoie [nom du fichier rangé, erreur].
 * $genre : 'pdf' ou 'image'. Le contenu est contrôlé (signature du fichier), pas seulement l'extension.
 */
function ranger_fichier_livre(array $f, string $genre, string $id): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['', ''];
    }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        return ['', in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Fichier trop lourd pour le serveur (limite : ' . ini_get('upload_max_filesize') . ').' : 'Envoi du fichier interrompu : réessayez.'];
    }
    $taille = (int)$f['size'];
    $debut = (string)file_get_contents((string)$f['tmp_name'], false, null, 0, 16);
    if ($genre === 'pdf') {
        if (strpos($debut, '%PDF-') !== 0 || $taille > LIVRE_PDF_MAX) {
            return ['', 'Le livre doit être un fichier PDF de moins de 80 Mo.'];
        }
        $ext = 'pdf';
    } else {
        $types = ["\xFF\xD8\xFF" => 'jpg', "\x89PNG" => 'png', 'RIFF' => 'webp'];
        $ext = '';
        foreach ($types as $sig => $e) {
            if (strpos($debut, $sig) === 0 && ($e !== 'webp' || substr($debut, 8, 4) === 'WEBP')) {
                $ext = $e;
            }
        }
        if ($ext === '' || $taille > LIVRE_IMAGE_MAX) {
            return ['', 'La couverture doit être une image JPG, PNG ou WebP de moins de 4 Mo.'];
        }
    }
    $nom = $id . '-' . ($genre === 'pdf' ? 'livre' : 'couverture') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file((string)$f['tmp_name'], dossier_livres() . '/' . $nom)) {
        return ['', 'Impossible d\'enregistrer le fichier sur le serveur.'];
    }
    return [$nom, ''];
}

/** Nombre de pages, et vérification que le PDF pourra être marqué au nom de l'acheteur. */
function analyser_pdf(string $chemin): array
{
    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        return [$pdf->setSourceFile($chemin), ''];
    } catch (Throwable $e) {
        return [0, 'Ce PDF ne peut pas être lu pour le marquage (format trop récent ou protégé). Réexportez-le en « PDF standard » sans mot de passe.'];
    }
}

/** Crée ou modifie un livre depuis le formulaire de l'admin ; renvoie [livre, erreur]. */
function enregistrer_livre(array $post, array $fichiers, string $id = ''): array
{
    $avant = $id !== '' ? livre($id) : null;
    if ($id !== '' && !$avant) {
        return [null, 'Livre introuvable.'];
    }
    $titre = mb_substr(trim((string)($post['titre'] ?? '')), 0, 120);
    $prix = round((float)str_replace([' ', ','], ['', '.'], (string)($post['prix'] ?? '0')), 2);
    if ($titre === '' || $prix < 1 || $prix > 1000) {
        return [null, 'Titre et prix (entre 1 et 1 000 €) nécessaires.'];
    }
    $id = $avant['id'] ?? bin2hex(random_bytes(5));
    [$pdf, $err] = ranger_fichier_livre($fichiers['fichier'] ?? [], 'pdf', $id);
    if ($err !== '') {
        return [null, $err];
    }
    if (!$avant && $pdf === '') {
        return [null, 'Ajoutez le fichier PDF du livre.'];
    }
    $pages = (int)($avant['pages'] ?? 0);
    if ($pdf !== '') {
        [$pages, $err] = analyser_pdf(dossier_livres() . '/' . $pdf);
        if ($err !== '') {
            @unlink(dossier_livres() . '/' . $pdf);
            return [null, $err];
        }
    }
    [$image, $err] = ranger_fichier_livre($fichiers['couverture'] ?? [], 'image', $id);
    if ($err !== '') {
        if ($pdf !== '') {
            @unlink(dossier_livres() . '/' . $pdf);
        }
        return [null, $err];
    }
    $l = array_merge($avant ?? ['id' => $id, 'cree_le' => gmdate('Y-m-d\TH:i:s\Z'), 'fichier' => '', 'couverture' => '', 'publie' => false], [
        'titre' => $titre,
        'sous_titre' => mb_substr(trim((string)($post['sous_titre'] ?? '')), 0, 160),
        'description' => mb_substr(trim(str_replace("\r", '', (string)($post['description'] ?? ''))), 0, 1200),
        'prix' => $prix, 'pages' => $pages, 'ordre' => max(0, min(99, (int)($post['ordre'] ?? 0))),
        'maj_le' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    $anciens = [];
    if ($pdf !== '') {
        $anciens[] = $l['fichier'];
        $l['fichier'] = $pdf;
    }
    if ($image !== '') {
        $anciens[] = $l['couverture'];
        $l['couverture'] = $image;
    }
    if (!$avant) {
        $l['publie'] = !empty($post['publie']);
    }
    transaction('livres', function (array &$liste) use ($l) {
        foreach ($liste as $i => $x) {
            if ($x['id'] === $l['id']) {
                $liste[$i] = $l;
                return;
            }
        }
        $liste[] = $l;
    });
    foreach (array_filter($anciens) as $a) {
        @unlink(dossier_livres() . '/' . basename($a));
    }
    return [$l, ''];
}

function modifier_livre(string $id, array $champs): ?array
{
    return transaction('livres', function (array &$liste) use ($id, $champs) {
        foreach ($liste as $i => $l) {
            if ($l['id'] === $id) {
                $liste[$i] = array_merge($l, $champs);
                return $liste[$i];
            }
        }
        return null;
    });
}

// --- Ventes ------------------------------------------------------------------------------------

/** Commande avant paiement ; renvoie [vente, erreur]. */
function creer_vente(array $l, string $nom, string $email, bool $renonciation): array
{
    $nom = mb_substr(trim((string)preg_replace('/\s+/', ' ', $nom)), 0, 80);
    $email = mb_strtolower(trim($email));
    if (mb_strlen($nom) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [null, 'Indiquez votre nom et une adresse e-mail valide : le livre y sera envoyé.'];
    }
    if (!$renonciation) {
        return [null, 'Cochez la case sur l\'accès immédiat au livre pour continuer.'];
    }
    $v = [
        'id' => bin2hex(random_bytes(6)), 'jeton' => bin2hex(random_bytes(16)), 'livre_id' => $l['id'], 'titre' => $l['titre'], 'montant' => (float)$l['prix'],
        'nom' => $nom, 'email' => $email, 'renonciation' => gmdate('Y-m-d\TH:i:s\Z'), 'statut' => 'en_attente', 'fournisseur' => '', 'ref' => '',
        'cree_le' => gmdate('Y-m-d\TH:i:s\Z'), 'paye_le' => '', 'expire_le' => '', 'telechargements' => 0, 'telecharge_le' => '',
    ];
    transaction('ventes', function (array &$liste) use ($v) { $liste[] = $v; });
    return [$v, ''];
}

function vente_par_jeton(string $jeton): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) {
        return null;
    }
    foreach (lire('ventes') as $v) {
        if (hash_equals($v['jeton'], $jeton)) {
            return $v;
        }
    }
    return null;
}

function modifier_vente(string $id, array $champs): ?array
{
    return transaction('ventes', function (array &$liste) use ($id, $champs) {
        foreach ($liste as $i => $v) {
            if ($v['id'] === $id) {
                $liste[$i] = array_merge($v, $champs);
                return $liste[$i];
            }
        }
        return null;
    });
}

function lien_telechargement(array $v): string
{
    return site_racine() . '/telecharger.php?t=' . $v['jeton'];
}

/** Nouveau délai et compteur remis à zéro (paiement, ou renvoi par Albert). */
function ouvrir_telechargement(string $id): ?array
{
    return modifier_vente($id, ['expire_le' => gmdate('Y-m-d\TH:i:s\Z', time() + LIVRE_JOURS * 86400), 'telechargements' => 0]);
}

function envoyer_lien_livre(array $v): void
{
    $prenom = explode(' ', $v['nom'])[0];
    envoyer_mail($v['email'], 'Votre livre « ' . $v['titre'] . ' »',
        "Bonjour {$prenom},\n\nMerci pour votre achat. Voici votre lien personnel pour télécharger « {$v['titre']} » :\n\n" . lien_telechargement($v)
        . "\n\nCe lien est valable " . LIVRE_JOURS . " jours et permet " . LIVRE_TELECHARGEMENTS . " téléchargements. Le PDF porte votre nom en bas de chaque page : il est pour votre usage personnel, merci de ne pas le diffuser.\n"
        . "Conformément à votre accord lors de la commande, le livre vous est fourni immédiatement et le droit de rétractation ne s'applique pas.\n\n"
        . "Un souci de téléchargement ? Répondez simplement à ce message.\n\nBonne lecture,\n\nAlbert Wutshu Shongo\nSALW CONSULTING\n", (string)cfg('destinataire'));
}

/** Paiement confirmé : statut, lien ouvert, livre envoyé à l'acheteur, avis à Albert. Sans effet si déjà payé. */
function marquer_vente_payee(string $id, string $fournisseur, string $ref): void
{
    $avant = null;
    foreach (lire('ventes') as $v) {
        if ($v['id'] === $id) {
            $avant = $v;
        }
    }
    if (!$avant || $avant['statut'] === 'paye') {
        return;
    }
    modifier_vente($id, ['statut' => 'paye', 'fournisseur' => $fournisseur, 'ref' => $ref, 'paye_le' => gmdate('Y-m-d\TH:i:s\Z')]);
    $v = ouvrir_telechargement($id);
    envoyer_lien_livre($v);
    envoyer_mail((string)cfg('destinataire'), 'Livre vendu · ' . $v['titre'] . ' · ' . montant_lisible((float)$v['montant']),
        "Vente par " . ($fournisseur === 'stripe' ? 'Stripe' : 'PayPal') . ".\n\nLivre : {$v['titre']}\nAcheteur : {$v['nom']} <{$v['email']}>\nMontant : " . montant_lisible((float)$v['montant'])
        . "\nRéférence : {$ref}\n\nLe lien de téléchargement lui a été envoyé. Détail dans l'espace admin, page Livres.\n", $v['email']);
}

// --- Paiement ----------------------------------------------------------------------------------

function retour_achat(array $v): string
{
    return site_racine() . '/acheter.php?v=' . $v['jeton'];
}

/** Carte uniquement : le livre est livré tout de suite, un prélèvement SEPA mettrait plusieurs jours. */
function stripe_ouvrir_vente(array $v): string
{
    $s = stripe('POST', '/v1/checkout/sessions', [
        'mode' => 'payment', 'locale' => 'fr', 'customer_email' => $v['email'], 'payment_method_types' => ['card'],
        'line_items' => [['price_data' => ['currency' => 'eur', 'unit_amount' => centimes((float)$v['montant']), 'product_data' => ['name' => 'Livre numérique · ' . $v['titre']]], 'quantity' => 1]],
        'metadata' => ['vente_id' => $v['id']], 'payment_intent_data' => ['metadata' => ['vente_id' => $v['id']], 'description' => 'Livre · ' . $v['titre']],
        'invoice_creation' => ['enabled' => 'true'],
        'success_url' => retour_achat($v) . '&stripe={CHECKOUT_SESSION_ID}', 'cancel_url' => retour_achat($v) . '&annule=1',
    ], 'vente-' . $v['id'] . '-' . time());
    return (string)$s['url'];
}

function stripe_constater_vente(array $s): string
{
    $id = (string)($s['metadata']['vente_id'] ?? '');
    if ($id === '' || ($s['mode'] ?? '') !== 'payment' || ($s['payment_status'] ?? '') !== 'paid') {
        return '';
    }
    marquer_vente_payee($id, 'stripe', (string)($s['payment_intent'] ?? $s['id']));
    return 'paye';
}

function paypal_ouvrir_vente(array $v): string
{
    $o = paypal('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [['reference_id' => $v['id'], 'custom_id' => 'vente-' . $v['id'], 'description' => mb_substr('Livre numérique · ' . $v['titre'], 0, 127),
            'amount' => ['currency_code' => 'EUR', 'value' => montant_paypal((float)$v['montant'])]]],
        'payment_source' => ['paypal' => ['experience_context' => ['brand_name' => 'SALW CONSULTING', 'locale' => 'fr-FR', 'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW', 'return_url' => retour_achat($v) . '&paypal=1', 'cancel_url' => retour_achat($v) . '&annule=1']]],
    ], 'vente-' . $v['id'] . '-' . time());
    modifier_vente($v['id'], ['paypal_commande' => (string)$o['id']]);
    $lien = paypal_lien($o, 'payer-action', 'approve');
    if ($lien === '') {
        throw new RuntimeException('PayPal n\'a pas renvoyé de lien de paiement.');
    }
    return $lien;
}

function paypal_encaisser_vente(array $v, string $commande): string
{
    if (!preg_match('/^[A-Z0-9]+$/', $commande) || $commande !== (string)($v['paypal_commande'] ?? '')) {
        return 'Retour PayPal non reconnu.';
    }
    $r = paypal('POST', '/v2/checkout/orders/' . $commande . '/capture', null, 'capture-' . $commande);
    $capture = $r['purchase_units'][0]['payments']['captures'][0] ?? [];
    if (($r['status'] ?? '') === 'COMPLETED' && ($capture['status'] ?? '') === 'COMPLETED') {
        marquer_vente_payee($v['id'], 'paypal', (string)($capture['id'] ?? $commande));
        return '';
    }
    return 'PayPal n\'a pas confirmé le paiement.';
}

// --- Téléchargement ----------------------------------------------------------------------------

/** Raison pour laquelle le lien ne permet pas (ou plus) de télécharger ; '' si tout va bien. */
function telechargement_refuse(array $v): string
{
    if ($v['statut'] !== 'paye') {
        return 'Ce livre n\'est pas encore payé.';
    }
    if ($v['expire_le'] === '' || strtotime($v['expire_le']) < time()) {
        return 'Ce lien a expiré.';
    }
    if ((int)$v['telechargements'] >= LIVRE_TELECHARGEMENTS) {
        return 'Ce lien a déjà servi ' . LIVRE_TELECHARGEMENTS . ' fois.';
    }
    return '';
}

/** PDF du livre avec « Exemplaire de … » en bas de chaque page. */
function pdf_marque(array $l, array $v): string
{
    $mention = 'Exemplaire de ' . $v['nom'] . ' · ' . $v['email'] . ' · ' . date('d/m/Y', strtotime($v['paye_le'] ?: $v['cree_le'])) . ' · usage personnel';
    $mention = (string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $mention);
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle((string)iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $l['titre']));
    $pdf->SetAuthor('Albert Wutshu Shongo');
    $n = $pdf->setSourceFile(dossier_livres() . '/' . $l['fichier']);
    for ($i = 1; $i <= $n; $i++) {
        $tpl = $pdf->importPage($i);
        $t = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($t['orientation'], [$t['width'], $t['height']]);
        $pdf->useTemplate($tpl);
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetTextColor(130, 130, 130);
        $pdf->SetXY(0, $t['height'] - 6);
        $pdf->Cell($t['width'], 4, $mention, 0, 0, 'C');
    }
    return $pdf->Output('S');
}
