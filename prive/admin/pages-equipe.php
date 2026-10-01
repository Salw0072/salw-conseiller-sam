<?php
/**
 * SALW CONSULTING, espace admin : équipe (comptes et rôles) et journal des actions.
 */

declare(strict_types=1);

function mot_de_passe_temporaire(): string
{
    // 16 caractères sans ambiguïté de lecture (pas de 0/O, 1/l).
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 16; $i++) {
        $s .= $a[random_int(0, strlen($a) - 1)];
    }
    return $s;
}

function nb_admins_actifs(): int
{
    return count(array_filter(utilisateurs(), function ($u) { return $u['role'] === 'admin' && !empty($u['actif']); }));
}

function page_equipe(): void
{
    if (!peut('equipe')) {
        interdit();
    }
    $moi = moi();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        $cible = $id !== '' ? utilisateur_par_id($id) : null;

        if ($action === 'creer') {
            $mdp = mot_de_passe_temporaire();
            [$ok, $res] = creer_utilisateur((string)($_POST['identifiant'] ?? ''), (string)($_POST['nom'] ?? ''), (string)($_POST['role'] ?? ''), $mdp, true);
            if ($ok) {
                journaliser('compte_cree', $res['identifiant'], ROLES[$res['role']]);
                // Montré une seule fois, à transmettre par un canal sûr (jamais par le même e-mail que l'adresse de connexion si possible).
                $_SESSION['mdp_temporaire'] = [$res['identifiant'], $mdp];
                flash('ok', 'Compte créé.');
            } else {
                flash('erreur', $res);
            }
        } elseif ($cible && $action === 'role' && isset(ROLES[$_POST['role'] ?? ''])) {
            if ($cible['role'] === 'admin' && $_POST['role'] !== 'admin' && nb_admins_actifs() <= 1) {
                flash('erreur', "Impossible : il doit rester au moins un administrateur actif.");
            } else {
                modifier_utilisateur($id, function (array &$u) { $u['role'] = (string)$_POST['role']; });
                journaliser('role_modifie', $cible['identifiant'], ROLES[$_POST['role']]);
                flash('ok', 'Rôle modifié.');
            }
        } elseif ($cible && $action === 'activer') {
            $actif = ($_POST['actif'] ?? '') === '1';
            if (!$actif && ($cible['id'] === $moi['id'] || ($cible['role'] === 'admin' && nb_admins_actifs() <= 1))) {
                flash('erreur', "Impossible de désactiver votre propre compte ou le dernier administrateur.");
            } else {
                modifier_utilisateur($id, function (array &$u) use ($actif) { $u['actif'] = $actif; });
                journaliser($actif ? 'compte_reactive' : 'compte_desactive', $cible['identifiant']);
                flash('ok', $actif ? 'Compte réactivé.' : 'Compte désactivé : la personne est déconnectée immédiatement.');
            }
        } elseif ($cible && $action === 'reinitialiser') {
            $mdp = mot_de_passe_temporaire();
            modifier_utilisateur($id, function (array &$u) use ($mdp) {
                $u['hash'] = password_hash($mdp, PASSWORD_DEFAULT);
                $u['changer_mdp'] = true;
                $u['echecs'] = 0;
                $u['bloque_jusqua'] = 0;
            });
            journaliser('mot_de_passe_reinitialise', $cible['identifiant']);
            $_SESSION['mdp_temporaire'] = [$cible['identifiant'], $mdp];
            flash('ok', 'Mot de passe réinitialisé.');
        } elseif ($cible && $action === 'totp_retirer') {
            modifier_utilisateur($id, function (array &$u) { $u['totp'] = null; });
            journaliser('double_auth_retiree', $cible['identifiant'], 'par un administrateur');
            flash('ok', 'Double authentification retirée : la personne pourra la reconfigurer.');
        } elseif ($cible && $action === 'supprimer') {
            if ($cible['id'] === $moi['id'] || ($cible['role'] === 'admin' && nb_admins_actifs() <= 1)) {
                flash('erreur', 'Impossible de supprimer votre propre compte ou le dernier administrateur.');
            } else {
                transaction('utilisateurs', function (array &$l) use ($id) { $l = array_values(array_filter($l, function ($u) use ($id) { return $u['id'] !== $id; })); });
                journaliser('compte_supprime', $cible['identifiant']);
                flash('ok', 'Compte supprimé.');
            }
        }
        aller('equipe');
    }

    entete('Équipe', 'equipe');
    titre_page('Équipe', 'Comptes ayant accès à cet espace');
    if (!empty($_SESSION['mdp_temporaire'])) {
        [$ident, $mdp] = $_SESSION['mdp_temporaire'];
        unset($_SESSION['mdp_temporaire']);
        echo '<div class="msg info"><b>Mot de passe temporaire de ' . h($ident) . ' :</b> <code class="cle">' . h($mdp) . '</code><br>Il ne sera plus affiché. Transmettez-le par un canal sûr (SMS, message) ; il devra être changé à la première connexion.</div>';
    }
    echo '<section class="carte"><h2>Rôles</h2><ul class="roles"><li><b>Administrateur</b> : tout, dont les réglages, les clés d\'API, l\'équipe et le journal.</li>'
        . '<li><b>Commercial</b> : rendez-vous, suivi commercial, prospects, demandes, disponibilités, exports.</li><li><b>Lecture seule</b> : consultation, sans modification ni export.</li></ul></section>';

    echo '<div class="table"><table><thead><tr><th>Compte</th><th>Rôle</th><th>Sécurité</th><th>Dernière connexion</th><th>Actions</th></tr></thead><tbody>';
    foreach (utilisateurs() as $u) {
        $soi = $u['id'] === $moi['id'];
        echo '<tr' . (empty($u['actif']) ? ' class="attenuee"' : '') . '><td><b>' . h($u['nom'] !== '' ? $u['nom'] : '—') . '</b>' . ($soi ? ' ' . badge('vous', 'bleu') : '') . '<br><span class="gris">' . h($u['identifiant']) . '</span></td>'
            . '<td><form method="post" class="ligne">' . champ_csrf() . '<input type="hidden" name="action" value="role"><input type="hidden" name="id" value="' . h($u['id']) . '">'
            . '<select name="role" data-envoi-auto aria-label="Rôle de ' . h($u['identifiant']) . '">';
        foreach (ROLES as $k => $l) {
            echo '<option value="' . h($k) . '"' . ($u['role'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        }
        echo '</select></form></td><td>' . (!empty($u['totp']) ? badge('Double auth.', 'vert') : badge('Mot de passe seul', 'gris'))
            . (empty($u['actif']) ? ' ' . badge('Désactivé', 'rouge') : '') . ((int)$u['bloque_jusqua'] > time() ? ' ' . badge('Bloqué 15 min', 'orange') : '') . '</td>'
            . '<td>' . h($u['derniere_connexion'] !== '' ? date_fr($u['derniere_connexion']) : 'jamais') . '</td><td class="actions-table">';
        if (!$soi) {
            echo '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="activer"><input type="hidden" name="id" value="' . h($u['id']) . '"><input type="hidden" name="actif" value="' . (empty($u['actif']) ? '1' : '0') . '"><button class="lien">' . (empty($u['actif']) ? 'Réactiver' : 'Désactiver') . '</button></form>'
                . '<form method="post" data-confirmer="Générer un nouveau mot de passe temporaire pour ce compte ?">' . champ_csrf() . '<input type="hidden" name="action" value="reinitialiser"><input type="hidden" name="id" value="' . h($u['id']) . '"><button class="lien">Réinitialiser le mot de passe</button></form>'
                . (!empty($u['totp']) ? '<form method="post" data-confirmer="Retirer la double authentification de ce compte ?">' . champ_csrf() . '<input type="hidden" name="action" value="totp_retirer"><input type="hidden" name="id" value="' . h($u['id']) . '"><button class="lien">Retirer la double auth.</button></form>' : '')
                . '<form method="post" data-confirmer="Supprimer définitivement ce compte ?">' . champ_csrf() . '<input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="' . h($u['id']) . '"><button class="lien danger">Supprimer</button></form>';
        } else {
            echo '<a class="lien" href="' . h(url_admin('compte')) . '">Mon compte</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<section class="carte"><h2>Ajouter un collaborateur</h2><form method="post" class="form grille-form">' . champ_csrf() . '<input type="hidden" name="action" value="creer">'
        . '<label>Nom<input name="nom" required maxlength="80"></label><label>Adresse e-mail (identifiant)<input name="identifiant" type="email" required></label>'
        . '<label>Rôle<select name="role"><option value="commercial">Commercial</option><option value="lecture">Lecture seule</option><option value="admin">Administrateur</option></select></label>'
        . '<button class="btn">Créer le compte</button></form><p class="petit">Un mot de passe temporaire s\'affichera une seule fois, à transmettre à la personne : elle devra le changer à sa première connexion.</p></section>';
    pied();
}

function page_journal(): void
{
    if (!peut('journal')) {
        interdit();
    }
    $libelles = [
        'connexion' => 'Connexion', 'connexion_echec' => 'Échec de connexion', 'connexion_code_faux' => 'Code de double auth. faux', 'installation' => 'Installation',
        'mot_de_passe_change' => 'Mot de passe changé', 'mot_de_passe_reinitialise' => 'Mot de passe réinitialisé', 'mdp_oubli_envoye' => 'Lien « mot de passe oublié » envoyé', 'mdp_oubli_echec_envoi' => 'Lien « mot de passe oublié » non envoyé (e-mail)', 'mot_de_passe_reinitialise_par_lien' => 'Mot de passe réinitialisé par lien', 'paiement_demande' => 'Demande de paiement créée', 'paiement_relance' => 'Lien de paiement renvoyé', 'paiement_annule' => 'Demande de paiement annulée', 'double_auth_activee' => 'Double auth. activée',
        'double_auth_desactivee' => 'Double auth. désactivée', 'double_auth_retiree' => 'Double auth. retirée', 'compte_cree' => 'Compte créé', 'compte_supprime' => 'Compte supprimé',
        'compte_desactive' => 'Compte désactivé', 'compte_reactive' => 'Compte réactivé', 'role_modifie' => 'Rôle modifié', 'suivi_modifie' => 'Suivi commercial',
        'rdv_annule' => 'Rendez-vous annulé', 'conversation_supprimee' => 'Conversation supprimée', 'reglages_modifies' => 'Réglages', 'assistant_vocal' => 'Assistant vocal',
        'export' => 'Export', 'jours_fermes' => 'Jours fermés', 'jour_rouvert' => 'Jour rouvert', 'demande_traitee' => 'Demande traitée', 'demande_rouverte' => 'Demande rouverte',
    ];
    $j = array_reverse(lire('journal'));
    $filtre = (string)($_GET['qui'] ?? '');
    if ($filtre !== '') {
        $j = array_filter($j, function ($e) use ($filtre) { return $e['qui'] === $filtre; });
    }
    entete('Journal', 'journal');
    titre_page('Journal des actions', 'Qui a fait quoi, et quand. Les 3 000 dernières actions sont conservées.');
    echo '<form class="filtres-ligne" method="get"><input type="hidden" name="p" value="journal"><select name="qui" aria-label="Filtrer par compte" data-envoi-auto><option value="">Tous les comptes</option>';
    foreach (utilisateurs() as $u) {
        echo '<option value="' . h($u['identifiant']) . '"' . ($filtre === $u['identifiant'] ? ' selected' : '') . '>' . h($u['identifiant']) . '</option>';
    }
    echo '</select><noscript><button class="btn contour">Filtrer</button></noscript></form>';
    echo '<div class="table"><table><thead><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Sur</th><th>Détail</th></tr></thead><tbody>';
    foreach (array_slice($j, 0, 300) as $e) {
        $alerte = in_array($e['action'], ['connexion_echec', 'connexion_code_faux'], true);
        echo '<tr><td class="nowrap">' . h(date_fr($e['t'])) . '</td><td>' . h($e['qui'] ?: '—') . '</td><td>' . ($alerte ? badge($libelles[$e['action']], 'orange') : h($libelles[$e['action']] ?? $e['action'])) . '</td><td>' . h($e['cible']) . '</td><td class="gris">' . h($e['detail']) . '</td></tr>';
    }
    if (!$j) {
        echo '<tr><td colspan="5" class="vide-mini">Aucune action enregistrée.</td></tr>';
    }
    echo '</tbody></table></div>';
    pied();
}
