<?php
/**
 * SALW CONSULTING, espace admin : réseaux sociaux de SALW (publications immédiates ou programmées,
 * modèles WhatsApp à faire approuver, état des connexions). Les clés sont dans Réglages › Réseaux sociaux.
 */

declare(strict_types=1);

const MODELES_WHATSAPP_SAM = [
    'salw_rappel_diagnostic' => ["Bonjour {{1}}, rappel : votre diagnostic SALW CONSULTING a lieu demain, {{2}}. Lien de la visio : {{3}}\nÀ bientôt.", 'Utilitaire', 'prénom, date et heure, lien de la visio'],
    'salw_prospect_diagnostic' => ["Bonjour {{1}}, merci pour votre demande à SALW CONSULTING. Réservez votre diagnostic gratuit de 30 minutes : {{2}}\nÀ bientôt.", 'Utilitaire', 'prénom, lien du site'],
];

function page_reseaux(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && peut('modifier')) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'publier') {
            $texte = trim(str_replace("\r", '', (string)($_POST['texte'] ?? '')));
            $reseaux = array_values(array_intersect(array_keys(RESEAUX_SAM), (array)($_POST['reseaux'] ?? [])));
            $quand = time();
            if (!empty($_POST['plus_tard']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)($_POST['quand'] ?? ''))) {
                $quand = (new DateTimeImmutable((string)$_POST['quand'], new DateTimeZone((string)cfg('fuseau'))))->getTimestamp();
            }
            if ($texte === '' || !$reseaux) {
                flash('erreur', 'Écrivez un texte et choisissez au moins un réseau.');
            } else {
                $p = sam_programmer($reseaux, mb_substr($texte, 0, 2000), !empty($_POST['avec_lien']) ? site_racine_sam() : '', $quand, (string)moi()['identifiant']);
                journaliser('reseaux_publication', implode(', ', $reseaux), $quand > time() ? 'programmée' : 'immédiate');
                flash('ok', $quand > time() ? 'Publication programmée le ' . date_fr(gmdate('Y-m-d\TH:i:s\Z', $quand)) . '.' : 'Publication envoyée : voir le résultat ci-dessous.');
            }
        } elseif ($action === 'annuler') {
            $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
            transaction('publications', function (array &$l) use ($id) {
                foreach ($l as $i => $p) {
                    if ($p['id'] === $id && $p['statut'] === 'prevue') {
                        $l[$i]['statut'] = 'annulee';
                    }
                }
            });
            flash('ok', 'Publication annulée.');
        }
        aller('reseaux');
    }
    $liste = lire('publications');
    usort($liste, function ($a, $b) { return strcmp($b['prevue_le'], $a['prevue_le']); });

    entete('Réseaux sociaux', 'reseaux');
    titre_page('Réseaux sociaux', 'Publications de SALW sur Facebook, Instagram et LinkedIn, rappels et prospects par WhatsApp. Les prospects des publicités arrivent dans Demandes.');
    if (cfg('mode_test')) {
        echo '<div class="msg info">Mode test : les publications sont enregistrées sans être envoyées.</div>';
    }
    echo '<section class="carte"><h2>Connexions</h2><div class="reseaux-etat">';
    foreach (['whatsapp' => 'WhatsApp'] + RESEAUX_SAM as $r => $nom) {
        echo '<span>' . h($nom) . ' ' . (sam_connecte($r) ? badge('connecté', 'vert') : badge('non connecté', 'gris')) . '</span>';
    }
    echo '</div><p class="petit">' . (peut('reglages') ? '<a href="' . h(url_admin('reglages', ['s' => 'reseaux'])) . '">Réglages › Réseaux sociaux</a>' : 'Réglages › Réseaux sociaux')
        . ' : identifiants et jetons. Notifications Meta (prospects des publicités) : <code>' . h(site_racine_sam() . '/meta-webhook.php') . '</code>, objet « page », champ « leadgen ».</p></section>';

    if (peut('modifier')) {
        $cases = '';
        foreach (RESEAUX_SAM as $r => $nom) {
            $cases .= '<label class="case"><input type="checkbox" name="reseaux[]" value="' . $r . '"' . (sam_connecte($r) ? ' checked' : '') . '> ' . h($nom) . '</label>';
        }
        echo '<section class="carte"><h2>Publier</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="publier">'
            . '<label>Texte<textarea name="texte" rows="5" maxlength="2000" required placeholder="Ex. Trois appels manqués par jour, c\'est un client perdu par semaine. Chiffrons ce que cela vous coûte : diagnostic gratuit de 30 minutes."></textarea></label>'
            . '<label class="case"><input type="checkbox" name="avec_lien" value="1" checked> Ajouter le lien du site</label>'
            . '<div class="cases-ligne">' . $cases . '</div>'
            . '<div class="champ-duo"><label class="case"><input type="checkbox" name="plus_tard" value="1"> Programmer</label><label>Date et heure (Drammen)<input type="datetime-local" name="quand"></label></div>'
            . '<p class="petit">Instagram publie avec l\'image JPEG des réglages (par défaut, ta photo du site). Une publication programmée part au passage suivant de la tâche cron (toutes les 15 minutes).</p>'
            . '<button class="btn">Publier</button></form></section>';
    }

    echo '<section class="carte"><h2>Publications</h2>';
    if (!$liste) {
        echo '<div class="vide">Aucune publication pour l\'instant.</div>';
    } else {
        $tons = ['prevue' => ['programmée', 'bleu'], 'en_cours' => ['en cours', 'orange'], 'publiee' => ['publiée', 'vert'], 'echec' => ['échec partiel', 'rouge'], 'annulee' => ['annulée', 'gris']];
        echo '<ul class="liste-simple">';
        foreach (array_slice($liste, 0, 30) as $p) {
            [$lib, $t] = $tons[$p['statut']] ?? [$p['statut'], 'gris'];
            $res = implode(' · ', array_map(function ($r, $v) { return (RESEAUX_SAM[$r] ?? $r) . ' : ' . ['publie' => 'publié', 'simule' => 'test', 'echec' => 'échec (' . $v[1] . ')', 'ignore' => $v[1]][$v[0]]; }, array_keys((array)$p['resultats']), (array)$p['resultats']));
            echo '<li><span class="gris">' . h(date_fr($p['prevue_le'])) . '</span> ' . badge($lib, $t) . ' ' . h(implode(', ', array_map(function ($r) { return RESEAUX_SAM[$r] ?? $r; }, $p['reseaux'])))
                . '<br>' . h(mb_strimwidth($p['texte'], 0, 160, '…')) . ($res !== '' ? '<br><span class="gris">' . h($res) . '</span>' : '')
                . ($p['statut'] === 'prevue' && peut('modifier') ? ' <form method="post" class="inline">' . champ_csrf() . '<input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="' . h($p['id']) . '"><button class="lien danger">Annuler</button></form>' : '') . '</li>';
        }
        echo '</ul>';
    }
    echo '</section>';

    echo '<section class="carte"><h2>Modèles WhatsApp à faire approuver</h2><p class="petit">À créer dans WhatsApp Manager › Modèles de message, en français, avec exactement ce nom et ce texte. Sans modèle approuvé, le rappel part par Twilio (s\'il est configuré) ou par e-mail seulement.</p>'
        . '<div class="table"><table><thead><tr><th>Nom</th><th>Catégorie</th><th>Texte</th><th>Variables</th></tr></thead><tbody>';
    foreach (MODELES_WHATSAPP_SAM as $nom => [$texte, $cat, $vars]) {
        echo '<tr><td><code>' . h($nom) . '</code></td><td>' . h($cat) . '</td><td>' . nl2br(h($texte)) . '</td><td class="gris">' . h($vars) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
    pied();
}
