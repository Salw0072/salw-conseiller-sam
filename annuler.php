<?php
/**
 * SALW CONSULTING, conseiller Sam : annulation d'un diagnostic par le visiteur.
 *
 * Le lien reçu par e-mail affiche d'abord une page de confirmation : l'annulation
 * n'a lieu qu'au clic (POST). Les antivirus de messagerie qui « visitent » les
 * liens ne peuvent donc pas annuler un rendez-vous par erreur.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

$id = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['r'] ?? ''));
$jeton = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['j'] ?? ''));

function trouver(array $reservations, string $id, string $jeton)
{
    foreach ($reservations as $i => $r) {
        if ($r['id'] === $id && hash_equals($r['jeton'], $jeton)) {
            return $i;
        }
    }
    return null;
}

$message = '';
$r = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $r = transaction('reservations', function (array &$reservations) use ($id, $jeton) {
        $i = trouver($reservations, $id, $jeton);
        if ($i === null) {
            return null;
        }
        if ($reservations[$i]['statut'] === 'confirmee') {
            $reservations[$i]['statut'] = 'annulee';
            $reservations[$i]['annule_le'] = gmdate('Y-m-d\TH:i:s\Z');
            $reservations[$i]['_vient_d_etre_annulee'] = true;
        }
        $copie = $reservations[$i];
        unset($reservations[$i]['_vient_d_etre_annulee']);
        return $copie;
    });
    if ($r !== null && !empty($r['_vient_d_etre_annulee'])) {
        $ics = generer_ics($r, 'CANCEL');
        $quand = date_lisible($r['debut'], fuseau_sur((string)($r['fuseau'] ?? '')));
        envoyer_mail($r['email'], 'Diagnostic SALW annulé · ' . $quand,
            "Bonjour " . explode(' ', $r['nom'])[0] . ",\n\nVotre diagnostic du {$quand} est bien annulé.\n\n"
            . "Pour choisir un autre créneau, il suffit de revenir sur le site.\n\nSALW CONSULTING\n",
            (string)cfg('destinataire'), $ics, 'CANCEL');
        envoyer_mail((string)cfg('destinataire'), 'Diagnostic annulé par le visiteur · ' . $r['nom'],
            "{$r['nom']} ({$r['email']}) a annulé son diagnostic du " . date_lisible($r['debut']) . ".\nLe créneau est de nouveau libre.\n",
            $r['email'], $ics, 'CANCEL');
    }
} else {
    $reservations = lire('reservations');
    $i = trouver($reservations, $id, $jeton);
    $r = $i === null ? null : $reservations[$i];
}

if ($r === null) {
    $titre = 'Lien introuvable';
    $message = 'Ce lien d\'annulation n\'est pas valide. Écrivez-nous à ' . cfg('destinataire') . '.';
} elseif ($r['statut'] === 'remplacee') {
    $titre = 'Rendez-vous déplacé';
    $message = "Ce rendez-vous a été déplacé sur un autre créneau. Utilisez le lien d'annulation du dernier e-mail de confirmation.";
} elseif ($r['statut'] === 'annulee') {
    $titre = 'Rendez-vous annulé';
    $message = 'Votre diagnostic du ' . date_lisible($r['debut'], fuseau_sur((string)($r['fuseau'] ?? ''))) . ' est annulé. Vous pouvez en réserver un autre à tout moment.';
} elseif (strtotime($r['debut']) < time()) {
    $titre = 'Rendez-vous passé';
    $message = 'Ce diagnostic a déjà eu lieu.';
} else {
    $titre = 'Annuler votre diagnostic ?';
    $message = 'Diagnostic prévu le ' . date_lisible($r['debut'], fuseau_sur((string)($r['fuseau'] ?? ''))) . '.';
}
$peutAnnuler = $r !== null && $r['statut'] === 'confirmee' && strtotime($r['debut']) >= time();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titre) ?> · SALW CONSULTING</title>
<style>
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"Anton";src:url(fonts/anton-latin-400-normal.woff2) format("woff2")}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#6B2150;font-family:"DM Sans",system-ui,sans-serif;color:#1E1F2B;padding:16px;box-sizing:border-box}
main{background:#fff;border-radius:18px;padding:32px;max-width:460px;width:100%;box-shadow:0 24px 60px -18px rgba(0,0,0,.4)}
.logo{font-family:"Anton",Impact,sans-serif;color:#6B2150;font-size:20px;letter-spacing:.02em}
h1{font-family:"Anton",Impact,sans-serif;font-weight:400;font-size:34px;line-height:1.05;color:#171B34;margin:18px 0 10px}
p{line-height:1.55;color:#4a4b58}
button,a.btn{display:inline-block;border:0;border-radius:999px;padding:13px 22px;font:700 15px "DM Sans",system-ui,sans-serif;cursor:pointer;text-decoration:none}
.annuler{background:#6B2150;color:#fff}
.retour{background:#F6EDF2;color:#6B2150;margin-left:6px}
</style>
</head>
<body>
<main>
  <div class="logo">SALW CONSULTING</div>
  <h1><?= h($titre) ?></h1>
  <p><?= h($message) ?></p>
  <?php if ($peutAnnuler): ?>
  <form method="post">
    <input type="hidden" name="r" value="<?= h($id) ?>">
    <input type="hidden" name="j" value="<?= h($jeton) ?>">
    <button class="annuler" type="submit">Oui, annuler le rendez-vous</button>
    <a class="btn retour" href="./">Garder le rendez-vous</a>
  </form>
  <?php else: ?>
  <a class="btn annuler" href="./">Retour au site</a>
  <?php endif; ?>
</main>
</body>
</html>
