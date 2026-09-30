<?php
/**
 * SALW CONSULTING, espace admin : rendez-vous, suivi commercial (pipeline), exports.
 */

declare(strict_types=1);

function trouver_rdv(string $id): ?array
{
    foreach (lire('reservations') as $r) {
        if ($r['id'] === $id) {
            return $r;
        }
    }
    return null;
}

function statut_rdv(array $r): string
{
    if ($r['statut'] === 'annulee') {
        return badge('Annulé', 'gris');
    }
    if ($r['statut'] === 'remplacee') {
        return badge('Déplacé', 'gris');
    }
    $c = colonne_pipeline($r);
    return badge(COLONNES[$c], ton_colonne($c));
}

function page_rdv(): void
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    if ($id !== '') {
        page_rdv_detail($id);
        return;
    }
    $vue = in_array($_GET['vue'] ?? '', ['a_venir', 'passes', 'annules', 'tous'], true) ? $_GET['vue'] : 'a_venir';
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $conversations = lire('conversations');
    $liste = array_filter(lire('reservations'), function ($r) use ($vue, $q) {
        if ($vue === 'a_venir' && !($r['statut'] === 'confirmee' && !rdv_passe($r))) return false;
        if ($vue === 'passes' && !($r['statut'] === 'confirmee' && rdv_passe($r))) return false;
        if ($vue === 'annules' && $r['statut'] === 'confirmee') return false;
        if ($q !== '' && mb_strpos(mb_strtolower($r['nom'] . ' ' . $r['email'] . ' ' . ($r['activite'] ?? '')), $q) === false) return false;
        return true;
    });
    usort($liste, function ($a, $b) use ($vue) { return $vue === 'a_venir' ? strcmp($a['debut'], $b['debut']) : strcmp($b['debut'], $a['debut']); });

    entete('Rendez-vous', 'rdv');
    $actions = peut('exporter') ? '<a class="btn contour" href="' . h(url_admin('export', ['type' => 'rdv'])) . '">Exporter (Excel)</a>' : '';
    titre_page('Rendez-vous', count($liste) . ' diagnostic(s)', $actions);
    echo '<form class="filtres-ligne" method="get"><input type="hidden" name="p" value="rdv"><div class="filtres" role="group" aria-label="Vue">';
    foreach (['a_venir' => 'À venir', 'passes' => 'Passés', 'annules' => 'Annulés et déplacés', 'tous' => 'Tous'] as $k => $l) {
        echo '<a href="' . h(url_admin('rdv', ['vue' => $k, 'q' => $q])) . '"' . ($k === $vue ? ' aria-current="true"' : '') . '>' . $l . '</a>';
    }
    echo '</div><input type="hidden" name="vue" value="' . h($vue) . '"><input type="search" name="q" value="' . h($q) . '" placeholder="Nom, e-mail, activité" aria-label="Rechercher"><button class="btn contour">Rechercher</button></form>';

    if (!$liste) {
        echo '<div class="vide">Aucun rendez-vous dans cette vue.</div>';
        pied();
        return;
    }
    echo '<div class="table"><table><thead><tr><th>Quand</th><th>Visiteur</th><th>Secteur</th><th>Canal</th><th>Statut</th><th class="num">Montant</th></tr></thead><tbody>';
    foreach ($liste as $r) {
        $qual = qualif_rdv($r, $conversations);
        $s = suivi($r);
        echo '<tr><td class="nowrap"><a href="' . h(url_admin('rdv', ['id' => $r['id']])) . '"><b>' . h(date_fr($r['debut'], 'd/m/Y')) . '</b> ' . h(date_fr($r['debut'], 'H:i')) . '</a></td>'
            . '<td><a href="' . h(url_admin('rdv', ['id' => $r['id']])) . '"><b>' . h($r['nom']) . '</b></a><br><span class="gris">' . h($r['activite'] ?: $r['email']) . '</span></td>'
            . '<td>' . h($qual['secteur'] !== '' ? libelle_secteur($qual['secteur']) : '—') . '</td>'
            . '<td>' . h(libelle_canal(canal_rdv($r, $conversations))) . '</td>'
            . '<td>' . statut_rdv($r) . '</td>'
            . '<td class="num">' . ((float)$s['montant'] > 0 ? h(euros((float)$s['montant'])) : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    pied();
}

function page_rdv_detail(string $id): void
{
    $u = moi();
    $r = trouver_rdv($id);
    if ($r === null) {
        flash('erreur', 'Rendez-vous introuvable (peut-être supprimé par la purge RGPD).');
        aller('rdv');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!peut('modifier')) {
            interdit();
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'suivi') {
            $etape = array_key_exists($_POST['etape'] ?? '', ETAPES) ? (string)$_POST['etape'] : '';
            $presence = array_key_exists($_POST['presence'] ?? '', PRESENCES) ? (string)$_POST['presence'] : '';
            $offre = array_key_exists($_POST['offre'] ?? '', OFFRES) ? (string)$_POST['offre'] : '';
            $montant = max(0, min(100000000, (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0'))));
            $relance = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['relance_le'] ?? '')) ? (string)$_POST['relance_le'] : '';
            $avant = suivi($r);
            modifier_suivi($id, ['etape' => $etape, 'presence' => $presence, 'offre' => $offre, 'montant' => $montant, 'relance_le' => $relance],
                trim(str_replace("\r", '', (string)($_POST['note'] ?? ''))), $u['identifiant']);
            $detail = [];
            if ($avant['etape'] !== $etape) $detail[] = 'étape : ' . ETAPES[$etape];
            if ($avant['presence'] !== $presence) $detail[] = 'présence : ' . PRESENCES[$presence];
            if ((float)$avant['montant'] !== $montant) $detail[] = 'montant : ' . euros($montant);
            journaliser('suivi_modifie', $r['nom'] . ' (' . $id . ')', implode(', ', $detail));
            flash('ok', 'Suivi enregistré.');
        } elseif ($action === 'annuler' && $r['statut'] === 'confirmee' && !rdv_passe($r)) {
            $motif = mb_substr(trim(str_replace("\r", '', (string)($_POST['motif'] ?? ''))), 0, 1000);
            $maj = transaction('reservations', function (array &$liste) use ($id, $u) {
                foreach ($liste as $i => $x) {
                    if ($x['id'] === $id && $x['statut'] === 'confirmee') {
                        $liste[$i]['statut'] = 'annulee';
                        $liste[$i]['annule_le'] = gmdate('Y-m-d\TH:i:s\Z');
                        $liste[$i]['annule_par'] = $u['identifiant'];
                        return $liste[$i];
                    }
                }
                return null;
            });
            if ($maj) {
                $quand = date_lisible($maj['debut'], fuseau_sur((string)($maj['fuseau'] ?? '')));
                envoyer_mail($maj['email'], 'Diagnostic SALW à reprogrammer · ' . $quand,
                    "Bonjour " . explode(' ', $maj['nom'])[0] . ",\n\nNous devons malheureusement annuler notre diagnostic du {$quand}."
                    . ($motif !== '' ? "\n\n{$motif}" : '')
                    . "\n\nChoisissez un autre créneau sur le site, ou répondez à cet e-mail avec vos disponibilités.\n\n"
                    . cfg('nom_conseiller') . "\nSALW CONSULTING\n",
                    (string)cfg('destinataire'), generer_ics($maj, 'CANCEL'), 'CANCEL');
                journaliser('rdv_annule', $maj['nom'] . ' (' . $id . ')', $motif);
                flash('ok', 'Rendez-vous annulé, le visiteur a été prévenu par e-mail.');
            }
        }
        aller('rdv', ['id' => $id]);
    }

    $conversations = lire('conversations');
    $qual = qualif_rdv($r, $conversations);
    $s = suivi($r);
    $conv = $conversations[$r['conversation_id'] ?? ''] ?? null;
    $modif = peut('modifier');

    entete($r['nom'], 'rdv');
    echo '<p class="fil-ariane"><a href="' . h(url_admin('rdv')) . '">Rendez-vous</a> › ' . h($r['nom']) . '</p>';
    titre_page($r['nom'], 'Diagnostic du ' . date_lisible($r['debut']), statut_rdv($r));

    echo '<div class="grille-detail"><div>';
    // Contact et rendez-vous.
    echo '<section class="carte"><h2>Contact</h2><dl class="fiche">'
        . '<div><dt>E-mail</dt><dd><a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' . (($r['canal'] ?? '') === 'appel' ? ' ' . badge('dicté au téléphone : à vérifier', 'orange') : '') . '</dd></div>'
        . (($r['telephone'] ?? '') !== '' ? '<div><dt>Mobile</dt><dd><a href="tel:' . h($r['telephone']) . '">' . h($r['telephone']) . '</a></dd></div>' : '')
        . '<div><dt>Activité</dt><dd>' . h($qual['activite'] ?: '—') . ($qual['ville'] !== '' ? ' · ' . h($qual['ville']) : '') . '</dd></div>'
        . '<div><dt>Secteur</dt><dd>' . h($qual['secteur'] !== '' ? libelle_secteur($qual['secteur']) : '—') . '</dd></div>'
        . '<div><dt>Problème</dt><dd>' . h($qual['probleme'] !== '' ? libelle_probleme($qual['probleme']) : '—') . '</dd></div>'
        . ($qual['equipe'] !== '' ? '<div><dt>Équipe</dt><dd>' . h($qual['equipe']) . '</dd></div>' : '')
        . ($qual['resume'] !== '' ? '<div><dt>Résumé validé</dt><dd>« ' . h($qual['resume']) . ' »</dd></div>' : '')
        . '<div><dt>Canal</dt><dd>' . h(libelle_canal(canal_rdv($r, $conversations))) . '</dd></div>'
        . '<div><dt>Heure du visiteur</dt><dd>' . h(date_lisible($r['debut'], fuseau_sur((string)($r['fuseau'] ?? '')))) . '</dd></div>'
        . '</dl><div class="actions-ligne"><a class="btn" href="' . h($r['visio']) . '" target="_blank" rel="noopener">Ouvrir la visio</a>'
        . '<a class="btn contour" href="mailto:' . h($r['email']) . '?subject=' . rawurlencode('Votre diagnostic SALW CONSULTING') . '">Écrire</a>'
        . ($conv ? '<a class="btn contour" href="' . h(url_admin('prospects', ['id' => $conv['id']])) . '">Voir la conversation</a>' : '') . '</div></section>';

    // Fiche Sam.
    if (($r['contexte'] ?? '') !== '') {
        echo '<section class="carte"><h2>Fiche transmise par Sam</h2><pre class="fiche-sam">' . h($r['contexte']) . '</pre></section>';
    }

    // Notes.
    echo '<section class="carte"><h2>Notes</h2>';
    if (!$s['notes']) {
        echo '<p class="vide-mini">Aucune note pour l\'instant.</p>';
    } else {
        echo '<ol class="notes">';
        foreach (array_reverse($s['notes']) as $n) {
            echo '<li><div class="note-meta">' . h(date_fr($n['t'])) . ' · ' . h($n['auteur']) . '</div><div class="note-texte">' . nl2br(h($n['texte'])) . '</div></li>';
        }
        echo '</ol>';
    }
    echo '</section></div><div>';

    // Suivi commercial.
    $ro = $modif ? '' : ' disabled';
    echo '<section class="carte"><h2>Suivi commercial</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="suivi">'
        . '<fieldset class="choix-radio"' . $ro . '><legend>Présence au diagnostic</legend>';
    foreach (PRESENCES as $k => $l) {
        echo '<label><input type="radio" name="presence" value="' . h($k) . '"' . ($s['presence'] === $k ? ' checked' : '') . '> ' . h($l) . '</label>';
    }
    echo '</fieldset><label>Étape<select name="etape"' . $ro . '>';
    foreach (ETAPES as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($s['etape'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select></label><label>Offre<select name="offre"' . $ro . '>';
    foreach (OFFRES as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($s['offre'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select></label><div class="champ-duo"><label>Montant (€ HT)<input name="montant" inputmode="decimal" value="' . ((float)$s['montant'] > 0 ? h((string)(float)$s['montant']) : '') . '" placeholder="0"' . $ro . '></label>'
        . '<label>Prochaine relance<input type="date" name="relance_le" value="' . h($s['relance_le']) . '"' . $ro . '></label></div>'
        . ($s['signe_le'] !== '' ? '<p class="petit">Signé le ' . h(date('d/m/Y', strtotime($s['signe_le']))) . '</p>' : '')
        . '<label>Ajouter une note<textarea name="note" rows="3" maxlength="3000" placeholder="Compte rendu, besoin exprimé, objections…"' . $ro . '></textarea></label>'
        . ($modif ? '<button class="btn plein">Enregistrer</button>' : '<p class="petit">Lecture seule.</p>')
        . (!empty($s['maj_par']) ? '<p class="petit">Dernière modification : ' . h(date_fr((string)$s['maj_le'])) . ' par ' . h((string)$s['maj_par']) . '</p>' : '')
        . '</form></section>';

    // Historique et rappels.
    $rappels = (array)($r['rappels_envoyes'] ?? []);
    echo '<section class="carte"><h2>Historique</h2><ul class="historique">'
        . '<li>Réservé le ' . h(date_fr($r['cree_le'])) . '</li>'
        . (($r['remplace'] ?? '') !== '' ? '<li>Déplacé par le visiteur depuis un autre créneau</li>' : '')
        . '<li>Rappels envoyés : ' . ($rappels ? h(implode(', ', array_map(function ($x) { return $x === 'j1' ? 'veille' : '2 h avant'; }, $rappels))) : 'aucun pour l\'instant') . '</li>'
        . (($r['annule_le'] ?? '') !== '' ? '<li>Annulé le ' . h(date_fr($r['annule_le'])) . (($r['annule_par'] ?? '') !== '' ? ' par ' . h($r['annule_par']) : ' par le visiteur') . '</li>' : '')
        . '</ul></section>';

    if ($modif && $r['statut'] === 'confirmee' && !rdv_passe($r)) {
        echo '<section class="carte"><h2>Annuler ce rendez-vous</h2><form method="post" class="form" data-confirmer="Annuler ce rendez-vous et prévenir le visiteur par e-mail ?">' . champ_csrf()
            . '<input type="hidden" name="action" value="annuler"><label>Message au visiteur (facultatif)<textarea name="motif" rows="2" maxlength="1000"></textarea></label>'
            . '<button class="btn danger">Annuler et prévenir</button></form></section>';
    }
    echo '</div></div>';
    pied();
}

function page_pipeline(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!peut('modifier')) {
            interdit();
        }
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        $col = (string)($_POST['colonne'] ?? '');
        $ch = [];
        if (array_key_exists($col, ETAPES) && $col !== '') {
            $ch = ['etape' => $col];
            if (in_array($col, ['devis', 'negociation', 'signe'], true)) {
                $ch['presence'] = 'honore';
            }
        } elseif ($col === 'fait') {
            $ch = ['presence' => 'honore', 'etape' => ''];
        } elseif ($col === 'absent') {
            $ch = ['presence' => 'absent', 'etape' => ''];
        }
        if ($ch && ($r = modifier_suivi($id, $ch, '', moi()['identifiant']))) {
            journaliser('suivi_modifie', $r['nom'] . ' (' . $id . ')', 'déplacé vers « ' . COLONNES[$col] . ' »');
            flash('ok', $r['nom'] . ' : ' . COLONNES[$col] . '.');
        }
        aller('pipeline');
    }

    $conversations = lire('conversations');
    $colonnes = array_fill_keys(array_keys(COLONNES), []);
    foreach (rdv_actifs(lire('reservations')) as $r) {
        $colonnes[colonne_pipeline($r)][] = $r;
    }
    entete('Suivi commercial', 'pipeline');
    titre_page('Suivi commercial', 'Du diagnostic à la signature. Faites avancer chaque prospect avec le menu de sa carte.');
    echo '<div class="pipeline">';
    foreach (COLONNES as $k => $titre) {
        $total = array_sum(array_map(function ($r) { return (float)suivi($r)['montant']; }, $colonnes[$k]));
        usort($colonnes[$k], function ($a, $b) { return strcmp($a['debut'], $b['debut']); });
        echo '<section class="colonne"><h2>' . badge($titre, ton_colonne($k)) . ' <span class="compte">' . count($colonnes[$k]) . '</span></h2>'
            . ($total > 0 ? '<p class="total">' . h(euros($total)) . '</p>' : '');
        foreach ($colonnes[$k] as $r) {
            $s = suivi($r);
            $q = qualif_rdv($r, $conversations);
            echo '<article class="carte-pipe"><a href="' . h(url_admin('rdv', ['id' => $r['id']])) . '"><b>' . h($r['nom']) . '</b></a>'
                . '<span class="gris">' . h($q['secteur'] !== '' ? libelle_secteur($q['secteur']) : ($r['activite'] ?: '')) . '</span>'
                . '<span class="gris">' . h(date_fr($r['debut'], 'd/m/Y')) . ((float)$s['montant'] > 0 ? ' · <b>' . h(euros((float)$s['montant'])) . '</b>' : '') . '</span>';
            if (peut('modifier')) {
                echo '<form method="post" class="deplacer">' . champ_csrf() . '<input type="hidden" name="id" value="' . h($r['id']) . '">'
                    . '<label class="sr" for="c' . h($r['id']) . '">Déplacer</label><select id="c' . h($r['id']) . '" name="colonne" data-envoi-auto>';
                foreach (COLONNES as $ck => $cl) {
                    if ($ck === 'a_venir' || $ck === 'a_qualifier') {
                        continue;
                    }
                    echo '<option value="' . h($ck) . '"' . ($ck === $k ? ' selected' : '') . '>' . h($cl) . '</option>';
                }
                echo '</select><noscript><button class="btn contour">OK</button></noscript></form>';
            }
            echo '</article>';
        }
        if (!$colonnes[$k]) {
            echo '<p class="vide-mini">—</p>';
        }
        echo '</section>';
    }
    echo '</div>';
    pied();
}

/** Export CSV pour Excel (séparateur « ; », UTF-8 avec BOM). */
function page_export(): void
{
    if (!peut('exporter')) {
        interdit();
    }
    $type = ($_GET['type'] ?? '') === 'prospects' ? 'prospects' : 'rdv';
    $conversations = lire('conversations');
    $lignes = [];
    if ($type === 'rdv') {
        $lignes[] = ['Date', 'Heure (Drammen)', 'Nom', 'E-mail', 'Mobile', 'Activité', 'Ville', 'Secteur', 'Problème', 'Canal', 'Statut', 'Présence', 'Étape', 'Offre', 'Montant (€ HT)', 'Relance', 'Notes', 'Réservé le'];
        foreach (lire('reservations') as $r) {
            $q = qualif_rdv($r, $conversations);
            $s = suivi($r);
            $lignes[] = [date_fr($r['debut'], 'd/m/Y'), date_fr($r['debut'], 'H:i'), $r['nom'], $r['email'], $r['telephone'] ?? '', $q['activite'], $q['ville'],
                $q['secteur'] !== '' ? libelle_secteur($q['secteur']) : '', $q['probleme'] !== '' ? libelle_probleme($q['probleme']) : '',
                libelle_canal(canal_rdv($r, $conversations)), ['confirmee' => 'Confirmé', 'annulee' => 'Annulé', 'remplacee' => 'Déplacé'][$r['statut']] ?? $r['statut'],
                PRESENCES[$s['presence']] ?? '', ETAPES[$s['etape']] ?? '', OFFRES[$s['offre']] ?? '', number_format((float)$s['montant'], 2, ',', ''),
                $s['relance_le'], implode(' | ', array_map(function ($n) { return $n['texte']; }, $s['notes'])), date_fr($r['cree_le'])];
        }
    } else {
        $lignes[] = ['Début', 'Dernier message', 'Canaux', 'Secteur', 'Problème', 'Activité', 'Ville', 'Équipe', 'Résumé', 'Messages du visiteur', 'Rendez-vous'];
        foreach ($conversations as $c) {
            $q = $c['qualif'];
            $lignes[] = [date_fr($c['cree_le']), date_fr($c['maj_le']), implode(', ', array_map('libelle_canal', (array)$c['canaux'])),
                $q['secteur'] !== '' ? libelle_secteur($q['secteur']) : '', $q['probleme'] !== '' ? libelle_probleme($q['probleme']) : '',
                $q['activite'], $q['ville'], $q['equipe'], $q['resume'],
                count(array_filter($c['messages'], function ($m) { return $m['role'] === 'visiteur'; })), ($c['reservation_id'] ?? '') !== '' ? 'Oui' : 'Non'];
        }
    }
    journaliser('export', $type, count($lignes) - 1 . ' ligne(s)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salw-' . $type . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $sortie = fopen('php://output', 'w');
    fwrite($sortie, "\xEF\xBB\xBF");
    foreach ($lignes as $l) {
        // Neutralise les formules (=, +, -, @) : une cellule ne doit jamais s'exécuter dans Excel.
        fputcsv($sortie, array_map(function ($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }, $l), ';');
    }
    fclose($sortie);
    exit;
}
