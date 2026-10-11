<?php
/**
 * SALW CONSULTING, espace admin : mise en page commune (navigation, en-tête, messages).
 */

declare(strict_types=1);

function nav_items(): array
{
    $demandes = count(array_filter(lire('demandes'), function ($d) { return empty($d['traite']); }));
    $items = [
        ['tableau',   'Tableau de bord',   'voir', 'M3 10.5L10 4l7 6.5V17h-5v-4.5H8V17H3z', 0],
        ['rdv',       'Rendez-vous',       'voir', 'M3.5 5h13v11.5h-13zM3.5 8.5h13M7 3v4M13 3v4', 0],
        ['pipeline',  'Suivi commercial',  'voir', 'M3 4h4v12H3zM8.5 4h4v8h-4zM14 4h3v5h-3z', 0],
        ['prospects', 'Prospects',         'voir', 'M4 15.5V17l2.5-1.6H14a3 3 0 0 0 3-3V6.5a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v6a3 3 0 0 0 1 2.2z', 0],
        ['demandes',  'Demandes',          'voir', 'M3 5.5h14v9H3zM3 5.5l7 5 7-5', $demandes],
        ['reseaux',   'Réseaux sociaux',   'voir', 'M5 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM15 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM15 13a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM6.8 9l6.4-3.2M6.8 11l6.4 3.2', 0],
        ['paiements', 'Paiements',         'voir', 'M2.5 5.5h15v9h-15zM2.5 8.5h15M5.5 12h3', 0],
        ['livres',    'Livres',            'voir', 'M4 3.5h9.5a2 2 0 0 1 2 2V17H6a2 2 0 0 1-2-2zM4 15a2 2 0 0 1 2-2h9.5M7.5 7h5', 0],
        ['dispo',     'Disponibilités',    'voir', 'M10 3.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM10 6.5V10l2.5 2', 0],
        ['reglages',  'Réglages',          'reglages', 'M10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4', 0],
        ['equipe',    'Équipe',            'equipe', 'M7.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM2.5 16c.5-3 2.5-4.5 5-4.5s4.5 1.5 5 4.5M13.5 9a2 2 0 1 0 0-4M14.5 11.8c1.6.4 2.7 1.7 3 4.2', 0],
        ['journal',   'Journal',           'journal', 'M5 3h10v14H5zM7.5 7h5M7.5 10h5M7.5 13h3', 0],
    ];
    return array_values(array_filter($items, function ($i) { return peut($i[2]); }));
}

function icone(string $chemin): string
{
    return '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $chemin . '"/></svg>';
}

function entete(string $titre, string $pageActive): void
{
    $u = moi();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($titre) . ' · Espace SALW</title>'
        . '<link rel="stylesheet" href="admin.css"></head><body>';
    if ($u === null) {
        echo '<main class="seul">';
        return;
    }
    echo '<div class="cadre"><aside class="nav" id="nav"><a class="marque" href="' . h(url_admin('tableau')) . '">SALW <span>ESPACE</span></a><nav aria-label="Espace admin"><ul>';
    foreach (nav_items() as $i) {
        $actif = $i[0] === $pageActive ? ' aria-current="page"' : '';
        $badge = $i[4] > 0 ? '<span class="pastille" aria-label="' . $i[4] . ' à traiter">' . $i[4] . '</span>' : '';
        echo '<li><a href="' . h(url_admin($i[0])) . '"' . $actif . '>' . icone($i[3]) . '<span>' . h($i[1]) . '</span>' . $badge . '</a></li>';
    }
    echo '</ul></nav><div class="nav-pied">'
        . '<a href="' . h(url_admin('compte')) . '"' . ($pageActive === 'compte' ? ' aria-current="page"' : '') . '><span class="avatar-u" aria-hidden="true">' . h(mb_strtoupper(mb_substr($u['nom'] !== '' ? $u['nom'] : $u['identifiant'], 0, 1))) . '</span><span><b>' . h($u['nom'] !== '' ? $u['nom'] : $u['identifiant']) . '</b><small>' . h(ROLES[$u['role']]) . '</small></span></a>'
        . '<form method="post" action="' . h(url_admin('deconnexion')) . '">' . champ_csrf() . '<button class="lien">Se déconnecter</button></form>'
        . '</div></aside><div class="contenu"><header class="barre"><button class="menu" id="menu" aria-expanded="false" aria-controls="nav">' . icone('M3 5h14M3 10h14M3 15h14') . '<span class="sr">Menu</span></button>'
        . '<a class="barre-site" href="../" target="_blank" rel="noopener">Voir le site ↗</a></header><main>';
    if (cfg('mode_test')) {
        echo '<div class="msg info">Mode test actif : aucun e-mail ni SMS n\'est réellement envoyé.</div>';
    }
    if (!empty($_SESSION['flash'])) {
        [$type, $texte] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="msg ' . h($type) . '" role="status">' . h($texte) . '</div>';
    }
}

function pied(): void
{
    echo moi() ? '</main></div></div>' : '</main>';
    echo '<script src="admin.js"></script></body></html>';
}

function titre_page(string $titre, string $sous = '', string $actions = ''): void
{
    echo '<div class="titre-page"><div><h1>' . h($titre) . '</h1>' . ($sous !== '' ? '<p>' . h($sous) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="actions">' . $actions . '</div>' : '') . '</div>';
}

function badge(string $texte, string $ton = ''): string
{
    return '<span class="badge ' . h($ton) . '">' . h($texte) . '</span>';
}

function ton_colonne(string $col): string
{
    $t = ['a_venir' => 'bleu', 'a_qualifier' => 'orange', 'fait' => 'prune', 'devis' => 'prune', 'negociation' => 'prune', 'signe' => 'vert', 'perdu' => 'gris', 'absent' => 'rouge'];
    return $t[$col] ?? '';
}

function interdit(): void
{
    http_response_code(403);
    entete('Accès refusé', '');
    titre_page('Accès refusé', "Votre rôle ne donne pas accès à cette page. Demandez à un administrateur.");
    pied();
    exit;
}
