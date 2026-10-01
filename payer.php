<?php
/**
 * SALW CONSULTING : page de paiement d'une demande (acompte, facture), ouverte depuis le lien reçu par e-mail.
 *
 * Rien ne se paie à l'ouverture : le client choisit Stripe (carte, prélèvement SEPA) ou PayPal, puis revient ici.
 * Au retour, le paiement est vérifié auprès du service avant d'être affiché comme reçu.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/paiements.php';

$jeton = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['t'] ?? ''));
$p = demande_par_jeton($jeton);
$erreur = '';

if ($p && $p['statut'] !== 'paye' && $p['statut'] !== 'annule') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $moyen = (string)($_POST['moyen'] ?? '');
            $vers = $moyen === 'stripe' && stripe_actif() ? stripe_ouvrir_demande($p) : ($moyen === 'paypal' && paypal_actif() ? paypal_ouvrir_demande($p) : '');
            if ($vers !== '') {
                header('Location: ' . $vers, true, 303);
                exit;
            }
            $erreur = 'Ce moyen de paiement n\'est pas disponible.';
        } elseif (isset($_GET['stripe']) && stripe_actif() && preg_match('/^cs_[A-Za-z0-9_]+$/', (string)$_GET['stripe'])) {
            $s = stripe('GET', '/v1/checkout/sessions/' . $_GET['stripe']);
            if ((string)($s['metadata']['paiement_id'] ?? '') === $p['id']) {
                stripe_constater($s);
            }
        } elseif (isset($_GET['paypal'], $_GET['token']) && paypal_actif()) {
            $erreur = paypal_encaisser($p, (string)$_GET['token']);
        }
    } catch (RuntimeException $e) {
        $erreur = 'Le service de paiement n\'a pas répondu : réessayez dans un instant, ou écrivez-nous.';
    }
    $p = demande_par_jeton($jeton);
}

if (!$p) {
    $titre = 'Lien introuvable';
    $texte = 'Ce lien de paiement n\'est pas valide. Écrivez-nous à ' . cfg('destinataire') . '.';
} elseif ($p['statut'] === 'paye') {
    $titre = 'Paiement reçu';
    $texte = 'Merci ! Nous avons bien reçu votre paiement de ' . montant_lisible((float)$p['montant']) . '. Un reçu vous est envoyé par e-mail.';
} elseif ($p['statut'] === 'annule') {
    $titre = 'Demande annulée';
    $texte = 'Cette demande de paiement a été annulée. Pour toute question, écrivez-nous à ' . cfg('destinataire') . '.';
} elseif ($p['statut'] === 'en_cours') {
    $titre = 'Prélèvement en cours';
    $texte = 'Votre prélèvement SEPA est enregistré : sa confirmation par la banque prend quelques jours. Vous recevrez un e-mail à ce moment-là.';
} else {
    $titre = 'Votre paiement';
    $texte = isset($_GET['annule']) ? 'Paiement interrompu : rien n\'a été prélevé. Vous pouvez reprendre ci-dessous.' : '';
}
$peutPayer = $p && in_array($p['statut'], ['en_attente', 'echec'], true) && (stripe_actif() || paypal_actif());

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titre) ?> · SALW CONSULTING</title>
<style>
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-700-normal.woff2) format("woff2");font-weight:700}
@font-face{font-family:"Anton";src:url(fonts/anton-latin-400-normal.woff2) format("woff2")}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#6B2150;font-family:"DM Sans",system-ui,sans-serif;color:#1E1F2B;padding:16px;box-sizing:border-box}
main{background:#fff;border-radius:18px;padding:32px;max-width:480px;width:100%;box-sizing:border-box;box-shadow:0 24px 60px -18px rgba(0,0,0,.4)}
.logo{font-family:"Anton",Impact,sans-serif;color:#6B2150;font-size:20px;letter-spacing:.02em}
h1{font-family:"Anton",Impact,sans-serif;font-weight:400;font-size:34px;line-height:1.05;color:#171B34;margin:18px 0 10px}
p{line-height:1.55;color:#4a4b58}
.objet{border:1.5px solid #E7D3DE;border-radius:14px;padding:16px 18px;margin:16px 0}
.objet span{display:block;font-size:13px;color:#6A6B78}
.objet b{display:block;font-size:17px;color:#171B34;margin:2px 0 8px}
.objet .montant{font-family:"Anton",Impact,sans-serif;font-size:40px;color:#6B2150;line-height:1}
.choix{display:flex;flex-direction:column;gap:10px;margin-top:6px}
button,a.btn{display:block;width:100%;box-sizing:border-box;text-align:center;border:1.5px solid #6B2150;border-radius:999px;padding:14px 22px;font:700 15px "DM Sans",system-ui,sans-serif;cursor:pointer;text-decoration:none}
.principal{background:#6B2150;color:#fff}
.second{background:#fff;color:#6B2150}
button:focus-visible,a.btn:focus-visible{outline:3px solid #E8A07A;outline-offset:3px}
.erreur{background:#FBE9E7;color:#A3261B;border-radius:10px;padding:12px 14px}
.petit{font-size:13px;color:#6A6B78}
</style>
</head>
<body>
<main>
  <div class="logo">SALW CONSULTING</div>
  <h1><?= h($titre) ?></h1>
  <?php if ($erreur !== ''): ?><p class="erreur" role="alert"><?= h($erreur) ?></p><?php endif; ?>
  <?php if ($texte !== ''): ?><p><?= h($texte) ?></p><?php endif; ?>
  <?php if ($p): ?>
  <div class="objet">
    <span>Objet</span><b><?= h($p['libelle']) ?></b>
    <span>Montant</span><div class="montant"><?= h(montant_lisible((float)$p['montant'])) ?></div>
    <?php if ($p['client_nom'] !== ''): ?><span style="margin-top:8px">Pour <?= h($p['client_nom']) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($peutPayer): ?>
  <form method="post" class="choix">
    <input type="hidden" name="t" value="<?= h($jeton) ?>">
    <?php if (stripe_actif()): ?><button class="principal" name="moyen" value="stripe">Payer par carte ou prélèvement SEPA</button><?php endif; ?>
    <?php if (paypal_actif()): ?><button class="second" name="moyen" value="paypal">Payer avec PayPal</button><?php endif; ?>
  </form>
  <p class="petit">Paiement sécurisé chez <?= stripe_actif() && paypal_actif() ? 'Stripe ou PayPal' : (stripe_actif() ? 'Stripe' : 'PayPal') ?> : SALW CONSULTING ne voit jamais vos coordonnées bancaires. Un reçu vous est envoyé par e-mail.</p>
  <?php elseif ($p && $p['statut'] === 'en_attente'): ?>
  <p class="petit">Le paiement en ligne n'est pas encore ouvert : écrivez-nous à <?= h((string)cfg('destinataire')) ?> pour régler par virement.</p>
  <?php else: ?>
  <a class="btn second" href="./">Retour au site</a>
  <?php endif; ?>
</main>
</body>
</html>
