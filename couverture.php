<?php
/**
 * SALW CONSULTING : couverture d'un livre publié (les fichiers sont rangés dans prive/, donc servis par ce script).
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/livres.php';

$l = livre(preg_replace('/[^a-f0-9]/', '', (string)($_GET['l'] ?? '')));
$chemin = $l && !empty($l['publie']) && $l['couverture'] !== '' ? dossier_livres() . '/' . basename($l['couverture']) : '';
if ($chemin === '' || !is_file($chemin)) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[pathinfo($chemin, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($chemin));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($chemin);
