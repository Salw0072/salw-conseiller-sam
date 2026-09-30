<?php
/**
 * SALW CONSULTING, espace admin : installation, connexion, compte personnel.
 */

declare(strict_types=1);

/**
 * Premier lancement : aucun compte. La première personne qui remplit ce formulaire
 * devient administrateur ; la page se ferme dès que ce compte existe.
 * À faire juste après la mise en ligne, pour que personne ne prenne la place.
 */
function page_installation(): void
{
    $erreur = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((string)($_POST['mdp'] ?? '') !== (string)($_POST['mdp2'] ?? '')) {
            $erreur = 'Les deux mots de passe ne correspondent pas.';
        } else {
            [$ok, $res] = creer_utilisateur((string)($_POST['identifiant'] ?? ''), (string)($_POST['nom'] ?? ''), 'admin', (string)($_POST['mdp'] ?? ''), false);
            if ($ok) {
                journaliser('installation', $res['identifiant'], 'Premier compte administrateur', $res['id']);
                ouvrir_session($res);
                flash('ok', 'Compte administrateur créé. Bienvenue dans votre espace.');
                aller('tableau');
            }
            $erreur = $res;
        }
    }
    entete('Installation', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>ESPACE</span></div><h1>Créer le compte administrateur</h1>';
    echo '<p>Première connexion : ce compte aura tous les droits. Vous pourrez ensuite inviter vos collaborateurs.</p>'
        . ($erreur !== '' ? '<div class="msg erreur">' . h($erreur) . '</div>' : '')
        . '<form method="post" class="form">' . champ_csrf()
        . '<label>Votre nom<input name="nom" required maxlength="80" autocomplete="name"></label>'
        . '<label>Adresse e-mail (identifiant)<input name="identifiant" type="email" required autocomplete="username"></label>'
        . '<label>Mot de passe (12 caractères minimum)<input name="mdp" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label>Confirmer le mot de passe<input name="mdp2" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<button class="btn plein">Créer le compte</button></form></div>';
    pied();
}

function page_connexion(): void
{
    $erreur = '';
    $etape = isset($_SESSION['en_attente']) ? 'totp' : 'mdp';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['code'])) {
            [$statut, $erreur] = valider_code_totp((string)$_POST['code']);
        } else {
            [$statut, $erreur] = tenter_connexion((string)($_POST['identifiant'] ?? ''), (string)($_POST['mdp'] ?? ''));
        }
        if ($statut === 'ok') {
            aller('tableau');
        }
        $etape = $statut === 'totp' || isset($_SESSION['en_attente']) ? 'totp' : 'mdp';
    }
    entete('Connexion', '');
    echo '<div class="carte-seule"><div class="marque">SALW <span>ESPACE</span></div>';
    if (!empty($_SESSION['flash'])) {
        [$t, $m] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="msg ' . h($t) . '">' . h($m) . '</div>';
    }
    if ($erreur !== '') {
        echo '<div class="msg erreur" role="alert">' . h($erreur) . '</div>';
    }
    if ($etape === 'totp') {
        echo '<h1>Code de vérification</h1><p>Saisissez le code à 6 chiffres affiché par votre application d\'authentification.</p>'
            . '<form method="post" class="form" action="' . h(url_admin('connexion')) . '">' . champ_csrf()
            . '<label>Code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code"></label>'
            . '<button class="btn plein">Valider</button></form>';
    } else {
        echo '<h1>Connexion</h1>'
            . '<form method="post" class="form" action="' . h(url_admin('connexion')) . '">' . champ_csrf()
            . '<label>Adresse e-mail<input name="identifiant" type="email" required autofocus autocomplete="username"></label>'
            . '<label>Mot de passe<input name="mdp" type="password" required autocomplete="current-password"></label>'
            . '<button class="btn plein">Se connecter</button></form>'
            . '<p class="petit">Mot de passe oublié ? Un administrateur peut le réinitialiser depuis la page Équipe.</p>';
    }
    echo '</div>';
    pied();
}

function page_compte(): void
{
    $u = moi();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'mdp') {
            $nouveau = (string)($_POST['nouveau'] ?? '');
            if (!password_verify((string)($_POST['actuel'] ?? ''), $u['hash'])) {
                flash('erreur', 'Mot de passe actuel incorrect.');
            } elseif (mb_strlen($nouveau) < 12) {
                flash('erreur', 'Le nouveau mot de passe doit faire au moins 12 caractères.');
            } elseif ($nouveau !== (string)($_POST['confirmation'] ?? '')) {
                flash('erreur', 'Les deux saisies ne correspondent pas.');
            } elseif (password_verify($nouveau, $u['hash'])) {
                flash('erreur', "Choisissez un mot de passe différent de l'actuel.");
            } else {
                modifier_utilisateur($u['id'], function (array &$x) use ($nouveau) { $x['hash'] = password_hash($nouveau, PASSWORD_DEFAULT); $x['changer_mdp'] = false; });
                journaliser('mot_de_passe_change', $u['identifiant']);
                session_regenerate_id(true);
                flash('ok', 'Mot de passe modifié.');
            }
        } elseif ($action === 'totp_preparer') {
            $_SESSION['totp_nouveau'] = base32_encoder(random_bytes(20));
        } elseif ($action === 'totp_activer') {
            $secret = (string)($_SESSION['totp_nouveau'] ?? '');
            $pas = $secret !== '' ? totp_verifier($secret, (string)($_POST['code'] ?? ''), 0) : 0;
            if ($pas === 0) {
                flash('erreur', "Code incorrect : vérifiez l'heure de votre téléphone et réessayez.");
            } else {
                modifier_utilisateur($u['id'], function (array &$x) use ($secret, $pas) { $x['totp'] = $secret; $x['totp_dernier'] = $pas; });
                unset($_SESSION['totp_nouveau']);
                journaliser('double_auth_activee', $u['identifiant']);
                flash('ok', 'Double authentification activée : un code vous sera demandé à chaque connexion.');
            }
        } elseif ($action === 'totp_desactiver') {
            if (cfg('admin_2fa_obligatoire') && $u['role'] === 'admin') {
                flash('erreur', 'La double authentification est obligatoire pour les administrateurs (réglage Sécurité).');
            } elseif (!password_verify((string)($_POST['actuel'] ?? ''), $u['hash'])) {
                flash('erreur', 'Mot de passe incorrect.');
            } else {
                modifier_utilisateur($u['id'], function (array &$x) { $x['totp'] = null; });
                journaliser('double_auth_desactivee', $u['identifiant']);
                flash('ok', 'Double authentification désactivée.');
            }
        } elseif ($action === 'nom') {
            modifier_utilisateur($u['id'], function (array &$x) { $x['nom'] = mb_substr(trim((string)($_POST['nom'] ?? '')), 0, 80); });
            flash('ok', 'Nom enregistré.');
        }
        aller('compte');
    }

    entete('Mon compte', 'compte');
    titre_page('Mon compte', $u['identifiant'] . ' · ' . ROLES[$u['role']]);
    if (!empty($u['changer_mdp'])) {
        echo '<div class="msg info">Pour continuer, choisissez votre propre mot de passe.</div>';
    }
    if (cfg('admin_2fa_obligatoire') && $u['role'] === 'admin' && empty($u['totp'])) {
        echo '<div class="msg info">La double authentification est obligatoire pour les administrateurs : activez-la ci-dessous pour accéder à l\'espace.</div>';
    }
    echo '<div class="grille-2"><section class="carte"><h2>Mot de passe</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="mdp">'
        . '<label>Mot de passe actuel<input name="actuel" type="password" required autocomplete="current-password"></label>'
        . '<label>Nouveau mot de passe (12 caractères minimum)<input name="nouveau" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<label>Confirmer<input name="confirmation" type="password" required minlength="12" autocomplete="new-password"></label>'
        . '<button class="btn">Changer le mot de passe</button></form>'
        . '<h2 class="sep">Nom affiché</h2><form method="post" class="form ligne">' . champ_csrf() . '<input type="hidden" name="action" value="nom"><input name="nom" value="' . h($u['nom']) . '" maxlength="80"><button class="btn contour">Enregistrer</button></form></section>';

    echo '<section class="carte"><h2>Double authentification</h2>';
    if (!empty($u['totp'])) {
        echo '<p>' . badge('Activée', 'vert') . ' Un code de votre application vous est demandé à chaque connexion.</p>'
            . '<form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="totp_desactiver">'
            . '<label>Mot de passe, pour confirmer<input name="actuel" type="password" required autocomplete="current-password"></label><button class="btn contour">Désactiver</button></form>';
    } elseif (!empty($_SESSION['totp_nouveau'])) {
        $secret = (string)$_SESSION['totp_nouveau'];
        echo '<ol class="etapes-liste"><li>Ouvrez Google Authenticator, Microsoft Authenticator ou 1Password et scannez ce code :</li></ol>'
            . '<div class="qr" data-qr="' . h(totp_uri($secret, $u['identifiant'])) . '"></div>'
            . '<p class="petit">Ou saisissez la clé à la main : <code class="cle">' . h(trim(chunk_split($secret, 4, ' '))) . '</code></p>'
            . '<form method="post" class="form ligne">' . champ_csrf() . '<input type="hidden" name="action" value="totp_activer">'
            . '<input name="code" inputmode="numeric" maxlength="7" placeholder="Code à 6 chiffres" required autocomplete="one-time-code"><button class="btn">Activer</button></form>';
    } else {
        echo '<p>Protège votre compte même si votre mot de passe fuite : en plus du mot de passe, un code à 6 chiffres généré par votre téléphone est demandé. <b>Recommandé pour les administrateurs</b>, qui ont accès aux clés d\'API.</p>'
            . '<form method="post">' . champ_csrf() . '<input type="hidden" name="action" value="totp_preparer"><button class="btn">Configurer</button></form>';
    }
    echo '</section></div>';
    pied();
}
