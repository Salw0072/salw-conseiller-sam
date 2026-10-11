<?php
/**
 * SALW CONSULTING : achat d'un livre numérique.
 *
 * acheter.php?l=<livre> : le lecteur donne son nom et son e-mail, accepte l'accès immédiat, puis paie
 * par carte (Stripe) ou PayPal. Au retour (acheter.php?v=<jeton>), le paiement est vérifié auprès du service
 * avant que le lien de téléchargement soit envoyé par e-mail.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/livres.php';

$erreur = '';
$v = vente_par_jeton(preg_replace('/[^a-f0-9]/', '', (string)($_GET['v'] ?? '')));
$l = livre(preg_replace('/[^a-f0-9]/', '', (string)($v['livre_id'] ?? ($_REQUEST['l'] ?? ''))));
if ($l && empty($l['publie']) && !$v) {
    $l = null;
}
$ouvert = stripe_actif() || paypal_actif();

try {
    if ($l && !$v && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // Un essai toutes les 10 secondes par adresse IP ; le champ caché piège les robots.
        $verrou = sys_get_temp_dir() . '/salw_achat_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.lock';
        $moyen = (string)($_POST['moyen'] ?? '');
        if ((string)($_POST['site_web'] ?? '') !== '') {
            $erreur = 'Commande refusée.';
        } elseif (is_file($verrou) && time() - (int)@filemtime($verrou) < 10) {
            $erreur = 'Merci de patienter quelques secondes avant de recommencer.';
        } elseif (!($moyen === 'stripe' && stripe_actif()) && !($moyen === 'paypal' && paypal_actif())) {
            $erreur = 'Ce moyen de paiement n\'est pas disponible.';
        } else {
            [$nv, $erreur] = creer_vente($l, (string)($_POST['nom'] ?? ''), (string)($_POST['email'] ?? ''), !empty($_POST['renonciation']));
            if ($nv) {
                @touch($verrou);
                header('Location: ' . ($moyen === 'stripe' ? stripe_ouvrir_vente($nv) : paypal_ouvrir_vente($nv)), true, 303);
                exit;
            }
        }
    } elseif ($v && $v['statut'] === 'en_attente') {
        if (isset($_GET['stripe']) && stripe_actif() && preg_match('/^cs_[A-Za-z0-9_]+$/', (string)$_GET['stripe'])) {
            $s = stripe('GET', '/v1/checkout/sessions/' . $_GET['stripe']);
            if ((string)($s['metadata']['vente_id'] ?? '') === $v['id']) {
                stripe_constater_vente($s);
            }
        } elseif (isset($_GET['paypal'], $_GET['token']) && paypal_actif()) {
            $erreur = paypal_encaisser_vente($v, (string)$_GET['token']);
        }
        $v = vente_par_jeton($v['jeton']);
    }
} catch (RuntimeException $e) {
    $erreur = 'Le service de paiement n\'a pas répondu : réessayez dans un instant, ou écrivez-nous à ' . cfg('destinataire') . '.';
}

if (!$l) {
    $titre = 'Livre introuvable';
    $texte = 'Ce livre n\'est pas (ou plus) en vente. Retrouvez nos livres sur la page d\'accueil.';
} elseif ($v && $v['statut'] === 'paye') {
    $titre = 'Merci pour votre achat';
    $texte = 'Votre paiement est confirmé. Le lien de téléchargement vient d\'être envoyé à ' . $v['email'] . ' (pensez à regarder dans les courriers indésirables). Vous pouvez aussi télécharger le livre tout de suite :';
} elseif ($v) {
    $titre = 'Paiement non abouti';
    $texte = isset($_GET['annule']) ? 'Paiement interrompu : rien n\'a été prélevé.' : 'Nous n\'avons pas encore la confirmation du paiement. Si vous avez été débité, écrivez-nous à ' . cfg('destinataire') . '.';
} else {
    $titre = $l['titre'];
    $texte = '';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if ($v) {
    header('X-Robots-Tag: noindex');
}
$pub = $l ? livre_public($l) : null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($v): ?><meta name="robots" content="noindex"><?php endif; ?>
<title><?= h($titre) ?> · SALW CONSULTING</title>
<?php if ($pub && !$v): ?><meta name="description" content="<?= h(mb_strimwidth($pub['sous_titre'] ?: $pub['description'], 0, 155, '…')) ?>"><?php endif; ?>
<style>
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-700-normal.woff2) format("woff2");font-weight:700}
@font-face{font-family:"Anton";src:url(fonts/anton-latin-400-normal.woff2) format("woff2")}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#6B2150;font-family:"DM Sans",system-ui,sans-serif;color:#1E1F2B;padding:16px}
main{background:#fff;border-radius:18px;padding:32px;max-width:760px;width:100%;box-shadow:0 24px 60px -18px rgba(0,0,0,.4)}
.logo{font-family:"Anton",Impact,sans-serif;color:#6B2150;font-size:20px;letter-spacing:.02em;text-decoration:none}
h1{font-family:"Anton",Impact,sans-serif;font-weight:400;font-size:36px;line-height:1.05;color:#171B34;margin:18px 0 6px}
.sous{font-size:17px;color:#4a4b58;margin:0 0 14px}
p{line-height:1.6;color:#4a4b58}
.livre{display:grid;grid-template-columns:200px 1fr;gap:28px;align-items:start;margin-top:8px}
.livre img{width:100%;height:auto;border-radius:6px;box-shadow:0 14px 30px -14px rgba(23,27,52,.55)}
.sans-image{aspect-ratio:2/3;border-radius:6px;background:#171B34;color:#fff;display:grid;place-items:center;padding:16px;text-align:center;font-family:"Anton",Impact,sans-serif;font-size:22px}
.prix{font-family:"Anton",Impact,sans-serif;font-size:42px;color:#6B2150;line-height:1;margin:6px 0 4px}
.meta{font-size:13px;color:#6A6B78;margin:0 0 16px}
.desc{white-space:pre-line}
form{display:flex;flex-direction:column;gap:12px;margin-top:18px}
label{font-size:14px;font-weight:700;color:#171B34;display:flex;flex-direction:column;gap:6px}
input[type=text],input[type=email]{font:16px "DM Sans",system-ui,sans-serif;padding:12px 14px;border:1.5px solid #E7D3DE;border-radius:10px}
input:focus-visible{outline:3px solid #E8A07A;outline-offset:1px}
label.case{flex-direction:row;align-items:flex-start;gap:10px;font-weight:400;color:#4a4b58;line-height:1.45}
label.case input{margin-top:3px;width:18px;height:18px;flex:none;accent-color:#6B2150}
.piege{position:absolute;left:-9999px}
button,a.btn{display:block;width:100%;text-align:center;border:1.5px solid #6B2150;border-radius:999px;padding:14px 22px;font:700 15px "DM Sans",system-ui,sans-serif;cursor:pointer;text-decoration:none}
.principal{background:#6B2150;color:#fff}
.second{background:#fff;color:#6B2150}
button:focus-visible,a.btn:focus-visible{outline:3px solid #E8A07A;outline-offset:3px}
.erreur{background:#FBE9E7;color:#A3261B;border-radius:10px;padding:12px 14px}
.petit{font-size:13px;color:#6A6B78}
.fin{display:flex;flex-direction:column;gap:10px;margin-top:16px;max-width:420px}
@media (max-width:640px){main{padding:22px}.livre{grid-template-columns:1fr}.livre .visuel{max-width:180px}h1{font-size:30px}}
</style>
</head>
<body>
<main>
  <a class="logo" href="./">SALW CONSULTING</a>
  <?php if (!$pub || $v): ?>
  <h1><?= h($titre) ?></h1>
  <?php if ($erreur !== ''): ?><p class="erreur" role="alert"><?= h($erreur) ?></p><?php endif; ?>
  <p><?= h($texte) ?></p>
  <div class="fin">
    <?php if ($v && $v['statut'] === 'paye'): ?><a class="btn principal" href="<?= h('telecharger.php?t=' . $v['jeton']) ?>">Télécharger « <?= h($v['titre']) ?> » (PDF)</a><?php endif; ?>
    <?php if ($v && $v['statut'] !== 'paye' && $l && !empty($l['publie'])): ?><a class="btn principal" href="<?= h('acheter.php?l=' . $l['id']) ?>">Reprendre l'achat</a><?php endif; ?>
    <a class="btn second" href="./#livres">Retour au site</a>
  </div>
  <?php else: ?>
  <div class="livre">
    <div class="visuel"><?php if ($pub['couverture'] !== ''): ?><img src="<?= h($pub['couverture']) ?>" alt="Couverture de « <?= h($pub['titre']) ?> »" width="400" height="600"><?php else: ?><div class="sans-image"><?= h($pub['titre']) ?></div><?php endif; ?></div>
    <div>
      <h1><?= h($pub['titre']) ?></h1>
      <?php if ($pub['sous_titre'] !== ''): ?><p class="sous"><?= h($pub['sous_titre']) ?></p><?php endif; ?>
      <div class="prix"><?= h($pub['prix_texte']) ?></div>
      <p class="meta">Livre numérique PDF<?= $pub['pages'] ? ' · ' . (int)$pub['pages'] . ' pages' : '' ?> · Albert Wutshu Shongo</p>
      <?php if ($pub['description'] !== ''): ?><p class="desc"><?= h($pub['description']) ?></p><?php endif; ?>
      <?php if ($erreur !== ''): ?><p class="erreur" role="alert"><?= h($erreur) ?></p><?php endif; ?>
      <?php if ($ouvert): ?>
      <form method="post" action="acheter.php">
        <input type="hidden" name="l" value="<?= h($pub['id']) ?>">
        <label>Votre nom<input type="text" name="nom" required maxlength="80" autocomplete="name" value="<?= h((string)($_POST['nom'] ?? '')) ?>"></label>
        <label>Votre e-mail (le livre y sera envoyé)<input type="email" name="email" required maxlength="120" autocomplete="email" value="<?= h((string)($_POST['email'] ?? '')) ?>"></label>
        <label class="piege" aria-hidden="true">Site web<input type="text" name="site_web" tabindex="-1" autocomplete="off"></label>
        <label class="case"><input type="checkbox" name="renonciation" value="1" required> Je demande l'accès immédiat au livre après paiement et je reconnais perdre ainsi mon droit de rétractation.</label>
        <?php if (stripe_actif()): ?><button class="principal" name="moyen" value="stripe">Payer <?= h($pub['prix_texte']) ?> par carte</button><?php endif; ?>
        <?php if (paypal_actif()): ?><button class="second" name="moyen" value="paypal">Payer avec PayPal</button><?php endif; ?>
      </form>
      <p class="petit">Paiement sécurisé chez <?= stripe_actif() && paypal_actif() ? 'Stripe ou PayPal' : (stripe_actif() ? 'Stripe' : 'PayPal') ?>. Vous recevez aussitôt un lien personnel, valable <?= LIVRE_JOURS ?> jours. Le PDF porte votre nom : il est pour votre usage personnel.</p>
      <?php else: ?>
      <p class="erreur">La vente en ligne n'est pas encore ouverte. Écrivez-nous à <?= h((string)cfg('destinataire')) ?> pour commander ce livre.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
