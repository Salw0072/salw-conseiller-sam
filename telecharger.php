<?php
/**
 * SALW CONSULTING : téléchargement d'un livre acheté (lien personnel reçu par e-mail).
 * Le PDF est marqué à la volée au nom de l'acheteur ; chaque téléchargement est compté.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/livres.php';

$v = vente_par_jeton(preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? '')));
$l = $v ? livre($v['livre_id']) : null;
$refus = !$v ? 'Ce lien de téléchargement n\'est pas valide.' : (!$l || !is_file(dossier_livres() . '/' . $l['fichier']) ? 'Ce livre n\'est plus disponible.' : telechargement_refuse($v));

if ($refus === '') {
    try {
        $pdf = pdf_marque($l, $v);
    } catch (Throwable $e) {
        $pdf = '';
        $refus = 'Le livre n\'a pas pu être préparé.';
        envoyer_mail((string)cfg('destinataire'), 'Téléchargement en échec · ' . $l['titre'], "Le PDF de « {$l['titre']} » n'a pas pu être marqué pour {$v['email']} : " . $e->getMessage() . "\n");
    }
    if ($pdf !== '') {
        modifier_vente($v['id'], ['telechargements' => (int)$v['telechargements'] + 1, 'telecharge_le' => gmdate('Y-m-d\TH:i:s\Z')]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . slug_livre($l['titre']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo $pdf;
        exit;
    }
}

http_response_code($v ? 410 : 404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Téléchargement · SALW CONSULTING</title>
<style>
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"Anton";src:url(fonts/anton-latin-400-normal.woff2) format("woff2")}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#6B2150;font-family:"DM Sans",system-ui,sans-serif;padding:16px;box-sizing:border-box}
main{background:#fff;border-radius:18px;padding:32px;max-width:480px;width:100%;box-sizing:border-box}
.logo{font-family:"Anton",Impact,sans-serif;color:#6B2150;font-size:20px}
h1{font-family:"Anton",Impact,sans-serif;font-weight:400;font-size:32px;color:#171B34;margin:18px 0 10px}
p{line-height:1.55;color:#4a4b58}
a{color:#6B2150;font-weight:700}
</style>
</head>
<body>
<main>
  <div class="logo">SALW CONSULTING</div>
  <h1>Téléchargement impossible</h1>
  <p><?= h($refus) ?></p>
  <p>Écrivez-nous à <a href="mailto:<?= h((string)cfg('destinataire')) ?>"><?= h((string)cfg('destinataire')) ?></a> en indiquant l'adresse utilisée pour l'achat : nous vous envoyons un nouveau lien.</p>
</main>
</body>
</html>
