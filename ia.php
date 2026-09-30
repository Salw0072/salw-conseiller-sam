<?php
/**
 * SALW CONSULTING, conseiller Sam : point d'entrée de l'agent IA.
 *
 * GET  ia.php?etat : ce que la page peut activer (chat IA, appel vocal).
 * POST ia.php      : une réponse de Sam. Corps JSON : {"messages": [...], "fuseau": "..."}.
 *
 * Toute erreur renvoie {"ok": false, "repli": true} : la page reprend alors
 * avec Sam en mode guidé, sans que le visiteur perde sa conversation.
 * Ce fichier reste compatible PHP 7.4 : le moteur (PHP 8.1+) n'est chargé qu'après contrôle.
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

/** Le chat IA peut-il tourner ici ? */
function ia_disponible(): bool
{
    return PHP_VERSION_ID >= 80100
        && (string)cfg('ia_cle') !== ''
        && is_file(__DIR__ . '/prive/vendor/autoload.php');
}

/** L'appel vocal est-il prêt ? (clé publique, secret et assistant créé depuis admin.php) */
function vocal_config(): ?array
{
    $assistant = lire('vapi')['assistant_id'] ?? '';
    if ((string)cfg('vapi_cle_publique') === '' || strlen((string)cfg('vapi_secret')) < 24 || $assistant === '') {
        return null;
    }
    return ['cle' => (string)cfg('vapi_cle_publique'), 'assistant' => $assistant];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    repondre_json(200, ['ok' => true, 'ia' => ia_disponible(), 'vocal' => vocal_config()]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    refuser(405, 'Méthode non autorisée.');
}

function repli(string $raison)
{
    repondre_json(200, ['ok' => false, 'repli' => true, 'error' => $raison]);
}

if (!ia_disponible()) {
    repli('indisponible');
}

$corps = json_decode((string)file_get_contents('php://input', false, null, 0, 200000), true);
if (!is_array($corps) || !isset($corps['messages']) || !is_array($corps['messages'])) {
    refuser(422, 'Requête invalide.');
}

// --- Historique : texte seulement, alterné, borné -------------------------------------
$historique = [];
foreach (array_slice($corps['messages'], -30) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $texte = trim(mb_substr((string)($m['content'] ?? ''), 0, 1500));
    if ($texte === '') {
        continue;
    }
    // L'API exige un premier message « user » et des rôles alternés : on fusionne les doublons.
    if (!$historique && $role === 'assistant') {
        continue;
    }
    $n = count($historique);
    if ($n && $historique[$n - 1]['role'] === $role) {
        $historique[$n - 1]['content'] .= "\n\n" . $texte;
    } else {
        $historique[] = ['role' => $role, 'content' => $texte];
    }
}
if (!$historique || end($historique)['role'] !== 'user') {
    refuser(422, 'Le dernier message doit venir du visiteur.');
}
$messagesVisiteur = count(array_filter($historique, function ($m) { return $m['role'] === 'user'; }));
if ($messagesVisiteur > 25) {
    repli('conversation_longue');
}

// --- Garde-fous de coût : plafond du jour (tout le site) et par visiteur et par heure -----
$ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
$autorise = transaction('ia-compteurs', function (array &$c) use ($ip) {
    $jour = gmdate('Y-m-d');
    if (($c['jour'] ?? '') !== $jour) {
        // Historique par jour (90 jours) pour le tableau de bord de l'espace admin.
        $historique = (array)($c['historique'] ?? []);
        if (!empty($c['jour'])) {
            $historique[$c['jour']] = (int)($c['total'] ?? 0);
        }
        $historique = array_slice($historique, -90, null, true);
        $c = ['jour' => $jour, 'total' => 0, 'ip' => [], 'historique' => $historique];
    }
    $maintenant = time();
    $recents = array_values(array_filter((array)($c['ip'][$ip] ?? []), function ($t) use ($maintenant) { return $t > $maintenant - 3600; }));
    if ($c['total'] >= (int)cfg('ia_max_jour') || count($recents) >= (int)cfg('ia_max_heure_ip')) {
        return false;
    }
    $recents[] = $maintenant;
    $c['ip'][$ip] = $recents;
    $c['total']++;
    return true;
});
if (!$autorise) {
    repli('plafond');
}

require __DIR__ . '/prive/ia-moteur.php';
$reponse = ia_repondre($historique, fuseau_sur((string)($corps['fuseau'] ?? '')));
repondre_json(200, $reponse);
