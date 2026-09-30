<?php
/**
 * SALW CONSULTING, espace admin : tableau de bord.
 */

declare(strict_types=1);

function tuile(string $libelle, string $valeur, string $detail = '', string $lien = ''): string
{
    $contenu = '<span class="tuile-lib">' . h($libelle) . '</span><span class="tuile-val">' . h($valeur) . '</span>' . ($detail !== '' ? '<span class="tuile-det">' . h($detail) . '</span>' : '');
    return $lien !== '' ? '<a class="tuile" href="' . h($lien) . '">' . $contenu . '</a>' : '<div class="tuile">' . $contenu . '</div>';
}

function pourcent(?float $x): string
{
    return $x === null ? '—' : round($x * 100) . ' %';
}

function page_tableau(): void
{
    $jours = (int)($_GET['j'] ?? 30);
    if (!in_array($jours, [7, 30, 90], true)) {
        $jours = 30;
    }
    $k = indicateurs($jours);
    $f = a_faire();
    $objectif = (float)cfg('objectif_ca');

    entete('Tableau de bord', 'tableau');
    $filtres = '<div class="filtres" role="group" aria-label="Période">';
    foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours'] as $n => $l) {
        $filtres .= '<a href="' . h(url_admin('tableau', ['j' => $n])) . '"' . ($n === $jours ? ' aria-current="true"' : '') . '>' . $l . '</a>';
    }
    $filtres .= '</div>';
    titre_page('Tableau de bord', 'Activité des ' . $jours . ' derniers jours', $filtres);

    // Chiffre principal : le CA signé face à l'objectif.
    $periodeObj = cfg('objectif_debut') && cfg('objectif_fin') ? 'du ' . date('d/m/Y', strtotime((string)cfg('objectif_debut'))) . ' au ' . date('d/m/Y', strtotime((string)cfg('objectif_fin'))) : '';
    echo '<section class="carte heros-kpi"><div><span class="tuile-lib">Chiffre d\'affaires signé via l\'application' . ($periodeObj !== '' ? ', ' . h($periodeObj) : '') . '</span>'
        . '<span class="chiffre-heros">' . h(euros($k['ca_objectif'])) . '</span>'
        . '<span class="tuile-det">En cours de négociation : ' . h(euros($k['pipeline'])) . ' (devis envoyés et négociations)</span></div>'
        . '<div class="heros-jauge">' . dv_jauge($k['ca_objectif'], $objectif) . '<p class="petit">Objectif réglable dans Réglages › Objectifs. Seuls les montants saisis dans le suivi commercial sont comptés.</p></div></section>';

    echo '<div class="tuiles">'
        . tuile('Conversations avec Sam', compact_nombre($k['conversations']), $k['qualifiees'] . ' qualifiées (résumé validé)', url_admin('prospects'))
        . tuile('Diagnostics réservés', compact_nombre($k['rdv']), $k['annules'] ? $k['annules'] . ' annulé(s)' : 'aucun annulé', url_admin('rdv'))
        . tuile('Conversion Sam → rendez-vous', pourcent($k['conversion']), 'conversations ayant mené à un rendez-vous')
        . tuile('Taux de présence', pourcent($k['presence']), $k['honores'] . ' honorés · ' . $k['absents'] . ' absents')
        . tuile('Devis envoyés', compact_nombre($k['devis']), $k['signes'] . ' signé(s) · ' . euros($k['ca_signe']), url_admin('pipeline'))
        . '</div>';

    // À faire.
    echo '<section class="carte"><h2>À faire</h2><ul class="a-faire">';
    $vide = true;
    foreach ($f['prochains'] as $r) {
        $vide = false;
        echo '<li>' . badge('Bientôt', 'bleu') . ' <a href="' . h(url_admin('rdv', ['id' => $r['id']])) . '"><b>' . h($r['nom']) . '</b></a> · diagnostic ' . h(quand_fr($r['debut'])) . ' <a class="lien" href="' . h($r['visio']) . '" target="_blank" rel="noopener">visio ↗</a></li>';
    }
    if ($f['a_qualifier']) {
        $vide = false;
        echo '<li>' . badge('À qualifier', 'orange') . ' ' . count($f['a_qualifier']) . ' diagnostic(s) passé(s) sans présence renseignée · <a href="' . h(url_admin('pipeline')) . '">compléter le suivi</a></li>';
    }
    foreach ($f['relances'] as $r) {
        $vide = false;
        echo '<li>' . badge('Relance', 'prune') . ' <a href="' . h(url_admin('rdv', ['id' => $r['id']])) . '"><b>' . h($r['nom']) . '</b></a> · relance prévue le ' . h(date('d/m', strtotime(suivi($r)['relance_le']))) . '</li>';
    }
    if ($f['demandes']) {
        $vide = false;
        echo '<li>' . badge('Demandes', 'orange') . ' ' . $f['demandes'] . ' demande(s) de créneau à traiter · <a href="' . h(url_admin('demandes')) . '">voir</a></li>';
    }
    if ($f['chauds']) {
        $vide = false;
        echo '<li>' . badge('Prospects', 'prune') . ' ' . $f['chauds'] . ' conversation(s) qualifiée(s) cette semaine sans rendez-vous · <a href="' . h(url_admin('prospects', ['statut' => 'sans'])) . '">voir</a></li>';
    }
    if ($vide) {
        echo '<li class="vide-mini">Rien d\'urgent.</li>';
    }
    echo '</ul></section>';

    echo '<div class="grille-2">'
        . '<section class="carte"><h2>Conversations par jour</h2><p class="petit">30 derniers jours, toutes conversations avec Sam</p>' . dv_colonnes_jours($k['serie_conv'], DV_PRUNE, 'Conversations par jour', 'conversations') . '</section>'
        . '<section class="carte"><h2>Diagnostics réservés par jour</h2><p class="petit">30 derniers jours, date de la réservation</p>' . dv_colonnes_jours($k['serie_rdv'], DV_VERT, 'Diagnostics réservés par jour', 'réservations') . '</section>'
        . '</div>';

    $secteurs = [];
    foreach ($k['par_secteur'] as $id => $n) {
        $secteurs[$id === 'non' ? 'Non précisé' : libelle_secteur($id)] = $n;
    }
    $problemes = [];
    foreach ($k['par_probleme'] as $id => $n) {
        $problemes[$id === 'non' ? 'Non précisé' : libelle_probleme($id)] = $n;
    }
    $canaux = [];
    foreach ($k['par_canal'] as $id => $n) {
        $canaux[libelle_canal($id)] = $n;
    }
    echo '<div class="grille-3">'
        . '<section class="carte"><h2>Conversations par secteur</h2>' . dv_barres($secteurs, DV_PRUNE, 'conversations') . '</section>'
        . '<section class="carte"><h2>Problème exprimé</h2>' . dv_barres($problemes, DV_PRUNE, 'conversations') . '</section>'
        . '<section class="carte"><h2>Rendez-vous par canal</h2>' . dv_barres($canaux, DV_VERT, 'rendez-vous') . '</section>'
        . '</div>';

    $plafond = (int)cfg('ia_max_jour');
    echo '<section class="carte"><h2>Agent IA</h2><div class="tuiles petites">'
        . tuile("Messages IA aujourd'hui", (string)$k['ia_jour'], 'plafond ' . $plafond . ' par jour')
        . tuile('Messages IA sur 30 jours', compact_nombre($k['ia_30']), 'coût : voir console.anthropic.com')
        . tuile('Rendez-vous pris par téléphone', (string)$k['rdv_appel'], 'sur la période, via Sam vocal')
        . '</div></section>';
    pied();
}
