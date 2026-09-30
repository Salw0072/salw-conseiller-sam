<?php
/**
 * SALW CONSULTING, espace admin : comptes, rôles, session, sécurité, journal.
 *
 * Comptes dans prive/donnees/utilisateurs.json (mots de passe hachés, jamais en clair).
 * Rôles :
 *   admin      tout, dont réglages, clés d'API, équipe et journal
 *   commercial rendez-vous, suivi commercial, prospects, demandes, disponibilités, exports
 *   lecture    consultation seule, sans export
 * Compatible PHP 7.4.
 */

declare(strict_types=1);

const ROLES = ['admin' => 'Administrateur', 'commercial' => 'Commercial', 'lecture' => 'Lecture seule'];

const PERMISSIONS = [
    'voir'          => ['admin', 'commercial', 'lecture'],
    'modifier'      => ['admin', 'commercial'],
    'exporter'      => ['admin', 'commercial'],
    'reglages'      => ['admin'],
    'equipe'        => ['admin'],
    'journal'       => ['admin'],
];

const INACTIVITE_MAX = 7200;    // 2 h sans action : déconnexion
const SESSION_MAX    = 43200;   // 12 h au total
const ECHECS_MAX     = 5;       // tentatives avant blocage du compte
const BLOCAGE        = 900;     // 15 min

// --- Session ---------------------------------------------------------------------------

function admin_demarrer_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_name('salw_admin');
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function csrf(): string
{
    return (string)$_SESSION['csrf'];
}

function champ_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function csrf_valide(): bool
{
    return hash_equals(csrf(), (string)($_POST['csrf'] ?? ''));
}

// --- Comptes ---------------------------------------------------------------------------

function utilisateurs(): array
{
    return lire('utilisateurs');
}

function utilisateur_par_id(string $id): ?array
{
    foreach (utilisateurs() as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

/** Modifie un compte sous verrou. $fn reçoit le compte par référence. */
function modifier_utilisateur(string $id, callable $fn): bool
{
    return transaction('utilisateurs', function (array &$liste) use ($id, $fn) {
        foreach ($liste as $i => $u) {
            if ($u['id'] === $id) {
                $fn($liste[$i]);
                return true;
            }
        }
        return false;
    });
}

function creer_utilisateur(string $identifiant, string $nom, string $role, string $mdp, bool $changer): array
{
    $identifiant = mb_strtolower(trim($identifiant));
    if (!filter_var($identifiant, FILTER_VALIDATE_EMAIL)) {
        return [false, "L'identifiant doit être une adresse e-mail."];
    }
    if (!isset(ROLES[$role])) {
        return [false, 'Rôle inconnu.'];
    }
    if (mb_strlen($mdp) < 12) {
        return [false, 'Le mot de passe doit faire au moins 12 caractères.'];
    }
    $res = transaction('utilisateurs', function (array &$liste) use ($identifiant, $nom, $role, $mdp, $changer) {
        foreach ($liste as $u) {
            if ($u['identifiant'] === $identifiant) {
                return null;
            }
        }
        $u = [
            'id' => bin2hex(random_bytes(6)), 'identifiant' => $identifiant, 'nom' => mb_substr(trim($nom), 0, 80),
            'role' => $role, 'hash' => password_hash($mdp, PASSWORD_DEFAULT), 'actif' => true,
            'changer_mdp' => $changer, 'totp' => null, 'totp_dernier' => 0, 'echecs' => 0, 'bloque_jusqua' => 0,
            'cree_le' => gmdate('Y-m-d\TH:i:s\Z'), 'derniere_connexion' => '',
        ];
        $liste[] = $u;
        return $u;
    });
    return $res === null ? [false, 'Un compte existe déjà avec cet identifiant.'] : [true, $res];
}

/** Utilisateur connecté, ou null. Applique les délais d'inactivité. */
function moi(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    $id = (string)($_SESSION['uid'] ?? '');
    if ($id === '') {
        return null;
    }
    $maintenant = time();
    if ($maintenant - (int)($_SESSION['activite'] ?? 0) > INACTIVITE_MAX || $maintenant - (int)($_SESSION['debut'] ?? 0) > SESSION_MAX) {
        admin_deconnecter();
        $_SESSION['flash'] = ['info', 'Session expirée : reconnectez-vous.'];
        return null;
    }
    $compte = utilisateur_par_id($id);
    if (!$compte || empty($compte['actif'])) {
        admin_deconnecter();
        return null;
    }
    $_SESSION['activite'] = $maintenant;
    $u = $compte;
    return $u;
}

function peut(string $permission): bool
{
    $u = moi();
    return $u !== null && in_array($u['role'], PERMISSIONS[$permission] ?? [], true);
}

function admin_deconnecter(): void
{
    $_SESSION = ['csrf' => bin2hex(random_bytes(16))];
    session_regenerate_id(true);
}

/**
 * Première étape de connexion. Renvoie [statut, message] :
 * 'ok' (connecté), 'totp' (code demandé), 'erreur'.
 */
function tenter_connexion(string $identifiant, string $mdp): array
{
    $identifiant = mb_strtolower(trim($identifiant));
    // Délai par adresse IP : ralentit les essais en série sur plusieurs comptes.
    $verrou = sys_get_temp_dir() . '/salw_admin_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.lock';
    if (is_file($verrou) && time() - (int)filemtime($verrou) < 3) {
        return ['erreur', 'Patientez quelques secondes avant de réessayer.'];
    }
    $compte = null;
    foreach (utilisateurs() as $u) {
        if ($u['identifiant'] === $identifiant) {
            $compte = $u;
        }
    }
    if ($compte && (int)$compte['bloque_jusqua'] > time()) {
        return ['erreur', 'Compte temporairement bloqué après plusieurs échecs. Réessayez dans ' . ceil(((int)$compte['bloque_jusqua'] - time()) / 60) . ' min.'];
    }
    // password_verify est appelé même sans compte, pour ne pas révéler par le temps de réponse qu'il n'existe pas.
    $ok = password_verify($mdp, $compte ? $compte['hash'] : '$2y$10$abcdefghijklmnopqrstuuO5E4I4bD3vN6k9Hs7m1jqf8wL2lYx9W');
    if (!$compte || !$ok || empty($compte['actif'])) {
        @touch($verrou);
        if ($compte) {
            modifier_utilisateur($compte['id'], function (array &$u) {
                $u['echecs'] = (int)$u['echecs'] + 1;
                if ($u['echecs'] >= ECHECS_MAX) {
                    $u['bloque_jusqua'] = time() + BLOCAGE;
                    $u['echecs'] = 0;
                }
            });
        }
        journaliser('connexion_echec', $identifiant, '', $compte['id'] ?? '');
        sleep(1);
        return ['erreur', 'Identifiant ou mot de passe incorrect.'];
    }
    if (password_needs_rehash($compte['hash'], PASSWORD_DEFAULT)) {
        modifier_utilisateur($compte['id'], function (array &$u) use ($mdp) { $u['hash'] = password_hash($mdp, PASSWORD_DEFAULT); });
    }
    if (!empty($compte['totp'])) {
        $_SESSION['en_attente'] = ['uid' => $compte['id'], 't' => time()];
        return ['totp', ''];
    }
    ouvrir_session($compte);
    return ['ok', ''];
}

function valider_code_totp(string $code): array
{
    $attente = $_SESSION['en_attente'] ?? null;
    if (!$attente || time() - (int)$attente['t'] > 300) {
        unset($_SESSION['en_attente']);
        return ['erreur', 'Délai dépassé : recommencez la connexion.'];
    }
    $compte = utilisateur_par_id((string)$attente['uid']);
    if (!$compte || empty($compte['totp'])) {
        return ['erreur', 'Compte introuvable.'];
    }
    $pas = totp_verifier((string)$compte['totp'], $code, (int)$compte['totp_dernier']);
    if ($pas === 0) {
        modifier_utilisateur($compte['id'], function (array &$u) {
            $u['echecs'] = (int)$u['echecs'] + 1;
            if ($u['echecs'] >= ECHECS_MAX) {
                $u['bloque_jusqua'] = time() + BLOCAGE;
                $u['echecs'] = 0;
            }
        });
        journaliser('connexion_code_faux', $compte['identifiant'], '', $compte['id']);
        sleep(1);
        return ['erreur', 'Code incorrect.'];
    }
    modifier_utilisateur($compte['id'], function (array &$u) use ($pas) { $u['totp_dernier'] = $pas; });
    unset($_SESSION['en_attente']);
    ouvrir_session($compte);
    return ['ok', ''];
}

function ouvrir_session(array $compte): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $compte['id'];
    $_SESSION['debut'] = time();
    $_SESSION['activite'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    modifier_utilisateur($compte['id'], function (array &$u) {
        $u['echecs'] = 0;
        $u['bloque_jusqua'] = 0;
        $u['derniere_connexion'] = gmdate('Y-m-d\TH:i:s\Z');
    });
    journaliser('connexion', $compte['identifiant'], '', $compte['id']);
}

// --- Double authentification (TOTP, RFC 6238) ------------------------------------------

function base32_encoder(string $octets): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($octets) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 5) as $morceau) {
        $sortie .= $alphabet[bindec(str_pad($morceau, 5, '0'))];
    }
    return $sortie;
}

function base32_decoder(string $texte): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $texte = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $texte));
    $bits = '';
    foreach (str_split($texte) as $c) {
        $bits .= str_pad(decbin((int)strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) {
            $sortie .= chr(bindec($octet));
        }
    }
    return $sortie;
}

function totp_code(string $secret, int $pas): string
{
    $cle = base32_decoder($secret);
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $pas), $cle, true);
    $decalage = ord($hash[19]) & 0x0F;
    $valeur = ((ord($hash[$decalage]) & 0x7F) << 24) | ((ord($hash[$decalage + 1]) & 0xFF) << 16)
        | ((ord($hash[$decalage + 2]) & 0xFF) << 8) | (ord($hash[$decalage + 3]) & 0xFF);
    return str_pad((string)($valeur % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Renvoie le pas de temps accepté (tolérance ±30 s), ou 0. Refuse un code déjà utilisé. */
function totp_verifier(string $secret, string $code, int $dernierPas): int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return 0;
    }
    $pas = (int)floor(time() / 30);
    foreach ([0, -1, 1] as $d) {
        $p = $pas + $d;
        if ($p > $dernierPas && hash_equals(totp_code($secret, $p), $code)) {
            return $p;
        }
    }
    return 0;
}

function totp_uri(string $secret, string $identifiant): string
{
    return 'otpauth://totp/' . rawurlencode('SALW Sam:' . $identifiant) . '?secret=' . $secret . '&issuer=' . rawurlencode('SALW Sam') . '&digits=6&period=30';
}

// --- Journal des actions ------------------------------------------------------------------

function journaliser(string $action, string $cible = '', string $detail = '', string $uid = ''): void
{
    $u = $uid !== '' ? utilisateur_par_id($uid) : (isset($_SESSION['uid']) ? utilisateur_par_id((string)$_SESSION['uid']) : null);
    transaction('journal', function (array &$j) use ($action, $cible, $detail, $u) {
        $j[] = ['t' => gmdate('Y-m-d\TH:i:s\Z'), 'qui' => $u['identifiant'] ?? '', 'action' => $action,
            'cible' => mb_substr($cible, 0, 160), 'detail' => mb_substr($detail, 0, 300)];
        if (count($j) > 3000) {
            $j = array_slice($j, -3000);
        }
    });
}

// --- Aides d'affichage -----------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function aller(string $page, array $params = []): void
{
    header('Location: ' . url_admin($page, $params));
    exit;
}

function url_admin(string $page, array $params = []): string
{
    return './?' . http_build_query(array_merge(['p' => $page], $params));
}

function date_fr(string $iso, string $format = 'd/m/Y H:i'): string
{
    if ($iso === '') {
        return '';
    }
    return (new DateTimeImmutable($iso))->setTimezone(fuseau())->format($format);
}

/** « mercredi 30/09 à 10:30 », heure de Drammen. */
function quand_fr(string $iso): string
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $d = (new DateTimeImmutable($iso))->setTimezone(fuseau());
    return $jours[(int)$d->format('N')] . ' ' . $d->format('d/m') . ' à ' . $d->format('H:i');
}

function euros(float $n): string
{
    return number_format($n, 0, ',', ' ') . ' €';
}

function compact_nombre(float $n): string
{
    if ($n >= 1000000) {
        return str_replace('.', ',', (string)round($n / 1000000, 1)) . ' M';
    }
    if ($n >= 10000) {
        return str_replace('.', ',', (string)round($n / 1000, 1)) . ' k';
    }
    return number_format($n, 0, ',', ' ');
}
