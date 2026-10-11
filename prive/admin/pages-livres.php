<?php
/**
 * SALW CONSULTING, espace admin : livres numériques (catalogue, publication, ventes).
 * Les livres publiés apparaissent dans la section « Livres » du site ; l'achat se fait sur acheter.php.
 */

declare(strict_types=1);

function page_livres(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && peut('modifier')) {
        $action = (string)($_POST['action'] ?? '');
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        if ($action === 'enregistrer') {
            [$l, $erreur] = enregistrer_livre($_POST, $_FILES, $id);
            if ($l) {
                journaliser($id === '' ? 'livre_ajoute' : 'livre_modifie', $l['titre'], montant_lisible((float)$l['prix']));
                flash('ok', '« ' . $l['titre'] . ' » enregistré' . ($l['publie'] ? ' et visible sur le site.' : '. Il n\'est pas encore publié.'));
            } else {
                flash('erreur', $erreur);
                aller('livres', $id !== '' ? ['modifier' => $id] : []);
            }
        } elseif ($action === 'publier' || $action === 'retirer') {
            $l = modifier_livre($id, ['publie' => $action === 'publier']);
            if ($l) {
                journaliser('livre_' . ($action === 'publier' ? 'publie' : 'retire'), $l['titre']);
                flash('ok', $action === 'publier' ? '« ' . $l['titre'] . ' » est en vente sur le site.' : '« ' . $l['titre'] . ' » est retiré de la vente. Les acheteurs gardent leur lien.');
            }
        } elseif ($action === 'renvoyer') {
            $v = null;
            foreach (lire('ventes') as $x) {
                if ($x['id'] === $id && $x['statut'] === 'paye') {
                    $v = $x;
                }
            }
            if ($v) {
                envoyer_lien_livre(ouvrir_telechargement($v['id']));
                journaliser('livre_lien_renvoye', $v['email'], $v['titre']);
                flash('ok', 'Nouveau lien envoyé à ' . $v['email'] . ' (' . LIVRE_JOURS . ' jours, ' . LIVRE_TELECHARGEMENTS . ' téléchargements).');
            }
        }
        aller('livres');
    }

    $livres = livres();
    $ventes = lire('ventes');
    usort($ventes, function ($a, $b) { return strcmp($b['cree_le'], $a['cree_le']); });
    $mois = gmdate('Y-m');
    $caMois = 0.0;
    $nbMois = 0;
    $caTotal = 0.0;
    $parLivre = [];
    foreach ($ventes as $v) {
        if ($v['statut'] !== 'paye') {
            continue;
        }
        $caTotal += (float)$v['montant'];
        $parLivre[$v['livre_id']] = ($parLivre[$v['livre_id']] ?? 0) + 1;
        if (strpos((string)$v['paye_le'], $mois) === 0) {
            $caMois += (float)$v['montant'];
            $nbMois++;
        }
    }
    $edition = isset($_GET['modifier']) ? livre(preg_replace('/[^a-f0-9]/', '', (string)$_GET['modifier'])) : null;

    entete('Livres', 'livres');
    titre_page('Livres numériques', 'Tes livres en vente sur le site, section « Livres ». Paiement par carte (Stripe) ou PayPal, lien de téléchargement personnel envoyé aussitôt, PDF marqué au nom de l\'acheteur.');
    if (!stripe_actif() && !paypal_actif()) {
        echo '<div class="msg info">Paiement en ligne non configuré : les livres publiés s\'affichent, mais l\'achat reste fermé tant que les clés Stripe ou PayPal ne sont pas renseignées dans '
            . (peut('reglages') ? '<a href="' . h(url_admin('reglages', ['s' => 'paiements'])) . '">Réglages › Paiements</a>' : 'Réglages › Paiements') . '.</div>';
    }
    echo '<div class="tuiles">' . tuile('Ventes ce mois-ci', (string)$nbMois, montant_lisible($caMois)) . tuile('Total encaissé', montant_lisible($caTotal))
        . tuile('Livres en vente', (string)count(array_filter($livres, function ($l) { return !empty($l['publie']); })), count($livres) . ' au catalogue') . '</div>';

    if (peut('modifier')) {
        $e = $edition ?? ['id' => '', 'titre' => '', 'sous_titre' => '', 'description' => '', 'prix' => '', 'ordre' => 0, 'fichier' => '', 'couverture' => ''];
        $max = ini_get('upload_max_filesize');
        echo '<section class="carte" id="formulaire-livre"><h2>' . ($edition ? 'Modifier « ' . h($e['titre']) . ' »' : 'Ajouter un livre') . '</h2>'
            . '<form method="post" class="form" enctype="multipart/form-data">' . champ_csrf() . '<input type="hidden" name="action" value="enregistrer"><input type="hidden" name="id" value="' . h($e['id']) . '">'
            . '<div class="champ-duo"><label>Titre<input name="titre" required maxlength="120" value="' . h($e['titre']) . '" placeholder="Ex. La preuve avant la confiance"></label>'
            . '<label>Prix TTC (€)<input name="prix" required inputmode="decimal" value="' . h($e['prix'] !== '' ? str_replace('.', ',', (string)$e['prix']) : '') . '" placeholder="Ex. 14,90"></label></div>'
            . '<label>Sous-titre (facultatif)<input name="sous_titre" maxlength="160" value="' . h($e['sous_titre']) . '" placeholder="Ex. Oser vendre, fixer ses prix, tenir sa posture"></label>'
            . '<label>Description (ce que le lecteur va apprendre)<textarea name="description" rows="5" maxlength="1200">' . h($e['description']) . '</textarea></label>'
            . '<div class="champ-duo"><label>Fichier du livre (PDF)' . ($edition ? ' <span class="gris">: laisser vide pour garder l\'actuel</span>' : '') . '<input type="file" name="fichier" accept="application/pdf"' . ($edition ? '' : ' required') . '></label>'
            . '<label>Couverture (JPG, PNG ou WebP)' . ($edition && $e['couverture'] !== '' ? ' <span class="gris">: laisser vide pour garder l\'actuelle</span>' : '') . '<input type="file" name="couverture" accept="image/jpeg,image/png,image/webp"></label></div>'
            . '<div class="champ-duo"><label>Ordre d\'affichage (0 = en premier)<input name="ordre" type="number" min="0" max="99" value="' . (int)$e['ordre'] . '"></label>'
            . ($edition ? '<span></span>' : '<label class="case"><input type="checkbox" name="publie" value="1" checked> Mettre en vente tout de suite</label>') . '</div>'
            . '<button class="btn">' . ($edition ? 'Enregistrer les modifications' : 'Ajouter le livre') . '</button>'
            . ($edition ? ' <a class="btn contour" href="' . h(url_admin('livres')) . '">Annuler</a>' : '')
            . '</form><p class="petit">PDF jusqu\'à 80 Mo (limite du serveur : ' . h((string)$max) . '). Le PDF est vérifié à l\'envoi : s\'il ne peut pas être marqué au nom de l\'acheteur, il est refusé. '
            . 'Le prix est payé tel quel : indique le prix TTC si tu factures la TVA.</p></section>';
    }

    echo '<section class="carte"><h2>Catalogue</h2>';
    if (!$livres) {
        echo '<div class="vide">Aucun livre pour l\'instant : ajoute le premier ci-dessus.</div>';
    } else {
        echo '<div class="table"><table><thead><tr><th>Livre</th><th class="num">Prix</th><th class="num">Pages</th><th class="num">Ventes</th><th>Statut</th><th></th></tr></thead><tbody>';
        foreach ($livres as $l) {
            $actions = peut('modifier') ? '<a class="lien" href="' . h(url_admin('livres', ['modifier' => $l['id']])) . '#formulaire-livre">Modifier</a> · '
                . '<form method="post" class="inline">' . champ_csrf() . '<input type="hidden" name="action" value="' . (!empty($l['publie']) ? 'retirer' : 'publier') . '"><input type="hidden" name="id" value="' . h($l['id']) . '"><button class="lien' . (!empty($l['publie']) ? ' danger' : '') . '">' . (!empty($l['publie']) ? 'Retirer de la vente' : 'Mettre en vente') . '</button></form>' : '';
            echo '<tr><td><b>' . h($l['titre']) . '</b>' . ($l['sous_titre'] !== '' ? '<br><span class="gris">' . h($l['sous_titre']) . '</span>' : '') . ($l['couverture'] === '' ? '<br><span class="gris">sans couverture</span>' : '') . '</td>'
                . '<td class="num">' . h(montant_lisible((float)$l['prix'])) . '</td><td class="num">' . (int)$l['pages'] . '</td><td class="num">' . (int)($parLivre[$l['id']] ?? 0) . '</td>'
                . '<td>' . (!empty($l['publie']) ? badge('en vente', 'vert') . '<br><a class="gris" href="' . h(site_racine() . '/acheter.php?l=' . $l['id']) . '" target="_blank" rel="noopener">voir la page</a>' : badge('non publié', 'gris')) . '</td><td>' . $actions . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    echo '<section class="carte"><h2>Ventes</h2>';
    $ventes = array_values(array_filter($ventes, function ($v) { return $v['statut'] !== 'en_attente' || strtotime($v['cree_le']) > time() - 2 * 86400; }));
    if (!$ventes) {
        echo '<div class="vide">Aucune vente pour l\'instant.</div>';
    } else {
        echo '<div class="table"><table><thead><tr><th>Date</th><th>Acheteur</th><th>Livre</th><th class="num">Montant</th><th>Statut</th><th>Téléchargements</th><th></th></tr></thead><tbody>';
        foreach (array_slice($ventes, 0, 100) as $v) {
            $tel = $v['statut'] === 'paye' ? (int)$v['telechargements'] . ' / ' . LIVRE_TELECHARGEMENTS . '<br><span class="gris">' . (strtotime($v['expire_le']) < time() ? 'lien expiré' : 'jusqu\'au ' . h(date_fr($v['expire_le'], 'd/m'))) . '</span>' : '–';
            $act = $v['statut'] === 'paye' && peut('modifier') ? '<form method="post" class="inline" data-confirmer="Envoyer un nouveau lien à ' . h($v['email']) . ' ?">' . champ_csrf() . '<input type="hidden" name="action" value="renvoyer"><input type="hidden" name="id" value="' . h($v['id']) . '"><button class="lien">Renvoyer le lien</button></form>' : '';
            echo '<tr><td>' . h(date_fr($v['paye_le'] ?: $v['cree_le'], 'd/m/Y H:i')) . '</td><td><b>' . h($v['nom']) . '</b><br><span class="gris">' . h($v['email']) . '</span></td><td>' . h($v['titre']) . '</td>'
                . '<td class="num">' . h(montant_lisible((float)$v['montant'])) . '</td><td>' . badge(STATUTS_VENTE[$v['statut']] ?? $v['statut'], $v['statut'] === 'paye' ? 'vert' : 'orange')
                . ($v['statut'] === 'paye' ? '<br><span class="gris">' . ($v['fournisseur'] === 'paypal' ? 'PayPal' : 'Stripe') . '</span>' : '') . '</td><td>' . $tel . '</td><td>' . $act . '</td></tr>';
        }
        echo '</tbody></table></div><p class="petit">Les commandes non payées disparaissent de la liste après 2 jours.</p>';
    }
    echo '</section>';
    pied();
}
