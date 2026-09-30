<?php
/**
 * SALW CONSULTING, espace admin : point d'entrée unique (admin/?p=page).
 *
 * Toute la logique est dans prive/admin/ (inaccessible depuis le web).
 * Pages : tableau, rdv, pipeline, prospects, demandes, dispo, reglages, equipe,
 * journal, compte, export, vapi, connexion, deconnexion.
 */

declare(strict_types=1);

$prive = dirname(__DIR__) . '/prive';
require $prive . '/lib.php';
require $prive . '/conversations.php';
require $prive . '/sam-connaissances.php';
foreach (['noyau', 'vues', 'donnees', 'graphiques', 'pages-acces', 'pages-tableau', 'pages-rdv', 'pages-prospects', 'pages-reglages', 'pages-equipe'] as $f) {
    require $prive . '/admin/' . $f . '.php';
}

admin_demarrer_session();
$page = preg_replace('/[^a-z\-]/', '', (string)($_GET['p'] ?? 'tableau'));

// Tout formulaire doit porter le jeton de la session (protection contre les requêtes forgées).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valide()) {
    flash('erreur', 'Session expirée ou formulaire périmé : recommencez.');
    aller(moi() ? $page : 'connexion');
}

// Premier lancement : aucun compte.
if (!utilisateurs()) {
    page_installation();
    exit;
}

if ($page === 'deconnexion') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && moi()) {
        journaliser('deconnexion', moi()['identifiant']);
        admin_deconnecter();
        flash('info', 'Vous êtes déconnecté.');
    }
    aller('connexion');
}

// Mot de passe oublié : accessible sans être connecté.
if ($page === 'oubli') {
    page_oubli();
    exit;
}
if ($page === 'nouveau-mdp') {
    page_nouveau_mdp();
    exit;
}

$u = moi();
if ($u === null || $page === 'connexion') {
    if ($u !== null) {
        aller('tableau');
    }
    page_connexion();
    exit;
}

// Mot de passe temporaire à remplacer, ou double authentification imposée aux administrateurs.
if ($page !== 'compte' && (!empty($u['changer_mdp']) || (cfg('admin_2fa_obligatoire') && $u['role'] === 'admin' && empty($u['totp'])))) {
    aller('compte');
}

purger_donnees();

$pages = [
    'tableau' => 'page_tableau', 'rdv' => 'page_rdv', 'pipeline' => 'page_pipeline', 'prospects' => 'page_prospects',
    'demandes' => 'page_demandes', 'dispo' => 'page_dispo', 'reglages' => 'page_reglages', 'equipe' => 'page_equipe',
    'journal' => 'page_journal', 'compte' => 'page_compte', 'export' => 'page_export', 'vapi' => 'page_vapi',
];
($pages[$page] ?? 'page_tableau')();
