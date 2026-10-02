<?php
/**
 * SALW CONSULTING, espace admin : prospects (conversations avec Sam), demandes, disponibilités.
 */

declare(strict_types=1);

function jours_restants(array $c): ?int
{
    if (($c['reservation_id'] ?? '') !== '') {
        return null;
    }
    $fin = strtotime($c['maj_le']) + (int)cfg('conservation_conversations_jours') * 86400;
    return max(0, (int)ceil(($fin - time()) / 86400));
}

function page_prospects(): void
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    if ($id !== '') {
        page_prospect_detail($id);
        return;
    }
    $statut = in_array($_GET['statut'] ?? '', ['sans', 'avec', 'tous'], true) ? $_GET['statut'] : 'tous';
    $secteur = in_array($_GET['secteur'] ?? '', SECTEURS, true) ? $_GET['secteur'] : '';
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $liste = array_filter(lire('conversations'), function ($c) use ($statut, $secteur, $q) {
        $avec = ($c['reservation_id'] ?? '') !== '';
        if ($statut === 'sans' && $avec) return false;
        if ($statut === 'avec' && !$avec) return false;
        if ($secteur !== '' && ($c['qualif']['secteur'] ?? '') !== $secteur) return false;
        if ($q !== '') {
            $texte = mb_strtolower(implode(' ', $c['qualif']) . ' ' . implode(' ', array_column($c['messages'], 'texte')));
            if (mb_strpos($texte, $q) === false) return false;
        }
        return true;
    });
    uasort($liste, function ($a, $b) { return strcmp($b['maj_le'], $a['maj_le']); });

    entete('Prospects', 'prospects');
    $actions = peut('exporter') ? '<a class="btn contour" href="' . h(url_admin('export', ['type' => 'prospects'])) . '">Exporter (Excel)</a>' : '';
    titre_page('Prospects', 'Conversations avec Sam. Sans rendez-vous, elles sont supprimées après ' . (int)cfg('conservation_conversations_jours') . ' jours (RGPD).', $actions);
    echo '<form class="filtres-ligne" method="get"><input type="hidden" name="p" value="prospects"><div class="filtres" role="group" aria-label="Statut">';
    foreach (['tous' => 'Toutes', 'sans' => 'Sans rendez-vous', 'avec' => 'Avec rendez-vous'] as $k => $l) {
        echo '<a href="' . h(url_admin('prospects', ['statut' => $k, 'secteur' => $secteur, 'q' => $q])) . '"' . ($k === $statut ? ' aria-current="true"' : '') . '>' . $l . '</a>';
    }
    echo '</div><input type="hidden" name="statut" value="' . h($statut) . '"><select name="secteur" aria-label="Secteur"><option value="">Tous les secteurs</option>';
    foreach (SECTEURS as $s) {
        echo '<option value="' . h($s) . '"' . ($s === $secteur ? ' selected' : '') . '>' . h(libelle_secteur($s)) . '</option>';
    }
    echo '</select><input type="search" name="q" value="' . h($q) . '" placeholder="Mot-clé, ville…" aria-label="Rechercher"><button class="btn contour">Filtrer</button></form>';

    if (!$liste) {
        echo '<div class="vide">Aucune conversation pour ces critères.' . (lire('conversations') ? '' : ' Elles apparaîtront ici dès que des visiteurs échangeront avec Sam.') . '</div>';
        pied();
        return;
    }
    echo '<div class="table"><table><thead><tr><th>Dernier message</th><th>Prospect</th><th>Problème</th><th>Canal</th><th>Rendez-vous</th></tr></thead><tbody>';
    foreach ($liste as $c) {
        $qual = $c['qualif'];
        $nb = count(array_filter($c['messages'], function ($m) { return $m['role'] === 'visiteur'; }));
        $reste = jours_restants($c);
        $titre = $qual['activite'] !== '' ? $qual['activite'] : mb_strimwidth((string)(array_values(array_filter($c['messages'], function ($m) { return $m['role'] === 'visiteur'; }))[0]['texte'] ?? ''), 0, 60, '…');
        echo '<tr><td class="nowrap"><a href="' . h(url_admin('prospects', ['id' => $c['id']])) . '">' . h(date_fr($c['maj_le'], 'd/m H:i')) . '</a></td>'
            . '<td><a href="' . h(url_admin('prospects', ['id' => $c['id']])) . '"><b>' . h($titre) . '</b></a>' . ($qual['ville'] !== '' ? ' · ' . h($qual['ville']) : '')
            . '<br><span class="gris">' . h($qual['secteur'] !== '' ? libelle_secteur($qual['secteur']) : 'Secteur non précisé') . ' · ' . $nb . ' message(s)' . ($qual['equipe'] !== '' ? ' · ' . h($qual['equipe']) : '') . '</span></td>'
            . '<td>' . h($qual['probleme'] !== '' ? libelle_probleme($qual['probleme']) : '—') . '</td>'
            . '<td>' . h(implode(', ', array_map('libelle_canal', (array)$c['canaux']))) . '</td>'
            . '<td>' . (($c['reservation_id'] ?? '') !== '' ? '<a href="' . h(url_admin('rdv', ['id' => $c['reservation_id']])) . '">' . badge('Réservé', 'vert') . '</a>' : ($qual['resume'] !== '' ? badge('Qualifié', 'prune') : badge('Non qualifié', 'gris')) . '<br><span class="gris petit">supprimée dans ' . $reste . ' j</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    pied();
}

function page_prospect_detail(string $id): void
{
    $c = lire('conversations')[$id] ?? null;
    if ($c === null) {
        flash('erreur', 'Conversation introuvable (peut-être supprimée après ' . (int)cfg('conservation_conversations_jours') . ' jours).');
        aller('prospects');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
        if (!peut('modifier')) {
            interdit();
        }
        transaction('conversations', function (array &$t) use ($id) { unset($t[$id]); });
        journaliser('conversation_supprimee', $id, 'suppression manuelle (droit à l\'effacement)');
        flash('ok', 'Conversation supprimée.');
        aller('prospects');
    }
    $q = $c['qualif'];
    entete('Conversation', 'prospects');
    echo '<p class="fil-ariane"><a href="' . h(url_admin('prospects')) . '">Prospects</a> › Conversation</p>';
    titre_page($q['activite'] !== '' ? $q['activite'] : 'Conversation avec Sam', 'Commencée le ' . date_fr($c['cree_le']) . ' · dernier message le ' . date_fr($c['maj_le']));
    echo '<div class="grille-detail"><section class="carte"><h2>Échange</h2><div class="transcription">';
    foreach ($c['messages'] as $m) {
        echo '<div class="msg-' . ($m['role'] === 'visiteur' ? 'visiteur' : 'sam') . '"><span class="qui">' . ($m['role'] === 'visiteur' ? 'Visiteur' : 'Sam') . '</span>' . nl2br(h($m['texte'])) . '</div>';
    }
    echo '</div></section><div><section class="carte"><h2>Qualification</h2><dl class="fiche">'
        . '<div><dt>Secteur</dt><dd>' . h($q['secteur'] !== '' ? libelle_secteur($q['secteur']) : '—') . '</dd></div>'
        . '<div><dt>Problème</dt><dd>' . h($q['probleme'] !== '' ? libelle_probleme($q['probleme']) : '—') . '</dd></div>'
        . '<div><dt>Ville</dt><dd>' . h($q['ville'] ?: '—') . '</dd></div>'
        . '<div><dt>Équipe</dt><dd>' . h($q['equipe'] ?: '—') . '</dd></div>'
        . '<div><dt>Résumé validé</dt><dd>' . ($q['resume'] !== '' ? '« ' . h($q['resume']) . ' »' : '—') . '</dd></div>'
        . '<div><dt>Canaux</dt><dd>' . h(implode(', ', array_map('libelle_canal', (array)$c['canaux']))) . '</dd></div>'
        . '<div><dt>Fuseau</dt><dd>' . h($c['fuseau']) . '</dd></div></dl>';
    if (($c['reservation_id'] ?? '') !== '') {
        echo '<a class="btn" href="' . h(url_admin('rdv', ['id' => $c['reservation_id']])) . '">Voir le rendez-vous</a>';
    } else {
        echo '<p class="petit">Pas de rendez-vous. Sam ne demande pas l\'e-mail avant la réservation : ce prospect ne peut pas être relancé directement, mais sa demande vous renseigne sur le marché. Suppression automatique dans ' . jours_restants($c) . ' jour(s).</p>';
    }
    echo '</section>';
    if (peut('modifier')) {
        echo '<section class="carte"><h2>Effacement</h2><p class="petit">Si la personne demande l\'effacement de ses données, supprimez la conversation maintenant.</p>'
            . '<form method="post" data-confirmer="Supprimer définitivement cette conversation ?">' . champ_csrf() . '<input type="hidden" name="action" value="supprimer"><button class="btn danger">Supprimer la conversation</button></form></section>';
    }
    echo '</div></div>';
    pied();
}

function page_demandes(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!peut('modifier')) {
            interdit();
        }
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        $traite = ($_POST['traite'] ?? '') === '1';
        transaction('demandes', function (array &$d) use ($id, $traite) {
            foreach ($d as $i => $x) {
                if ($x['id'] === $id) {
                    $d[$i]['traite'] = $traite;
                }
            }
        });
        journaliser('demande_' . ($traite ? 'traitee' : 'rouverte'), $id);
        aller('demandes');
    }
    $liste = lire('demandes');
    usort($liste, function ($a, $b) { return strcmp($b['recu_le'], $a['recu_le']); });
    entete('Demandes', 'demandes');
    titre_page('Demandes de créneau', "Reçues par le formulaire de repli quand la réservation en ligne n'était pas disponible. Conservées " . (int)cfg('conservation_conversations_jours') . ' jours.');
    if (!$liste) {
        echo '<div class="vide">Aucune demande. Bon signe : la réservation en ligne a toujours fonctionné.</div>';
        pied();
        return;
    }
    echo '<div class="liste-cartes">';
    foreach ($liste as $d) {
        echo '<article class="carte' . (!empty($d['traite']) ? ' attenuee' : '') . '"><div class="entete-carte"><div><b>' . h($d['nom']) . '</b> · <a href="mailto:' . h($d['email']) . '?subject=' . rawurlencode('Votre diagnostic SALW CONSULTING') . '">' . h($d['email']) . '</a>'
            . '<br><span class="gris">' . h(date_fr($d['recu_le'])) . ' · ' . h(['publicite' => 'Prospect d\'une publicité Facebook ou Instagram'][$d['type']] ?? $d['type']) . '</span></div>'
            . (!empty($d['traite']) ? badge('Traitée', 'vert') : badge('À traiter', 'orange')) . '</div>'
            . '<pre class="fiche-sam">' . h($d['message']) . '</pre>';
        if (peut('modifier')) {
            echo '<form method="post">' . champ_csrf() . '<input type="hidden" name="id" value="' . h($d['id']) . '"><input type="hidden" name="traite" value="' . (empty($d['traite']) ? '1' : '0') . '">'
                . '<button class="btn contour">' . (empty($d['traite']) ? 'Marquer comme traitée' : 'Rouvrir') . '</button></form>';
        }
        echo '</article>';
    }
    echo '</div>';
    pied();
}

function page_dispo(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!peut('modifier')) {
            interdit();
        }
        $action = (string)($_POST['action'] ?? '');
        $jour = (string)($_POST['jour'] ?? '');
        $fin = (string)($_POST['fin'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
            $jours = [$jour];
            // Plage de dates (congés) : du « jour » au « fin » inclus, 60 jours au plus.
            if ($action === 'fermer' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) && $fin > $jour) {
                $d = new DateTimeImmutable($jour);
                $jours = [];
                for ($i = 0; $i < 60 && $d->format('Y-m-d') <= $fin; $i++, $d = $d->modify('+1 day')) {
                    $jours[] = $d->format('Y-m-d');
                }
            }
            transaction('fermetures', function (array &$f) use ($jours, $action) {
                $f = array_values(array_diff($f, $jours));
                if ($action === 'fermer') {
                    $f = array_merge($f, $jours);
                    sort($f);
                }
            });
            journaliser($action === 'fermer' ? 'jours_fermes' : 'jour_rouvert', implode(', ', [$jours[0], end($jours)]));
            flash('ok', $action === 'fermer' ? count($jours) . ' jour(s) fermé(s) à la réservation.' : 'Journée rouverte.');
        }
        aller('dispo');
    }
    $reservations = lire('reservations');
    $libres = creneaux_libres($reservations);
    $fermetures = lire('fermetures');
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    entete('Disponibilités', 'dispo');
    titre_page('Disponibilités', count($libres) . ' créneau(x) libre(s) sur les ' . (int)cfg('horizon_jours') . ' prochains jours');
    echo '<div class="grille-2"><section class="carte"><h2>Horaires actuels</h2><dl class="fiche">'
        . '<div><dt>Jours</dt><dd>' . h(implode(', ', array_map(function ($j) use ($jours) { return $jours[(int)$j]; }, (array)cfg('jours')))) . '</dd></div>'
        . '<div><dt>Plages</dt><dd>' . h(implode(' · ', array_map(function ($p) { return $p[0] . '–' . $p[1]; }, (array)cfg('plages')))) . ' (heure de Drammen)</dd></div>'
        . '<div><dt>Durée</dt><dd>' . (int)cfg('duree') . ' min, un créneau toutes les ' . (int)cfg('pas') . ' min</dd></div>'
        . '<div><dt>Délai minimum</dt><dd>' . (int)cfg('delai_min_heures') . ' h avant le rendez-vous</dd></div>'
        . '<div><dt>Maximum</dt><dd>' . (int)cfg('max_par_jour') . ' diagnostics par jour</dd></div>'
        . '<div><dt>Agenda Google</dt><dd>' . ((string)cfg('ics_occupe') !== '' ? badge('Synchronisé', 'vert') : badge('Non relié', 'orange') . ' vos autres rendez-vous ne bloquent pas les créneaux') . '</dd></div>'
        . '</dl>' . (peut('reglages') ? '<a class="btn contour" href="' . h(url_admin('reglages', ['s' => 'agenda'])) . '">Modifier les horaires</a>' : '') . '</section>';
    echo '<section class="carte"><h2>Fermer des jours</h2>';
    if (peut('modifier')) {
        echo '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="fermer"><div class="champ-duo">'
            . '<label>Du<input type="date" name="jour" required min="' . h(date('Y-m-d')) . '"></label><label>Au (facultatif)<input type="date" name="fin" min="' . h(date('Y-m-d')) . '"></label></div>'
            . '<button class="btn">Fermer à la réservation</button><p class="petit">Les rendez-vous déjà pris ces jours-là sont maintenus : annulez-les depuis leur fiche si besoin.</p></form>';
    }
    if ($fermetures) {
        echo '<h3>Jours fermés</h3><div class="puces">';
        foreach ($fermetures as $j) {
            echo '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="rouvrir"><input type="hidden" name="jour" value="' . h($j) . '">'
                . '<button class="puce"' . (peut('modifier') ? '' : ' disabled') . ' title="Rouvrir">' . h(date('d/m/Y', strtotime($j))) . ' ✕</button></form>';
        }
        echo '</div>';
    }
    echo '</section></div>';

    // Aperçu des prochains créneaux, par jour.
    $parJour = [];
    foreach ($libres as $c) {
        $parJour[date_fr($c['debut'], 'Y-m-d')][] = date_fr($c['debut'], 'H:i');
    }
    echo '<section class="carte"><h2>Prochains créneaux libres</h2>';
    if (!$parJour) {
        echo '<p class="vide-mini">Aucun créneau libre : élargissez les horaires ou rouvrez des jours.</p>';
    } else {
        echo '<div class="table"><table><tbody>';
        foreach (array_slice($parJour, 0, 10, true) as $j => $heures) {
            echo '<tr><th class="nowrap">' . h($jours[(int)date('N', strtotime($j))] . ' ' . date('d/m', strtotime($j))) . '</th><td>' . h(implode(' · ', $heures)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    pied();
}
