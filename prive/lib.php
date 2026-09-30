<?php
/**
 * SALW CONSULTING, conseiller Sam : fonctions communes du serveur.
 *
 * Compatible PHP 7.4 à 8.x (certaines offres Hostinger ne sont pas encore en 8).
 * Stockage dans des fichiers JSON sous prive/donnees/, protégé par verrou :
 * aucune base de données à créer.
 */

declare(strict_types=1);

/**
 * Réglages effectifs : config.php, puis ceux enregistrés depuis l'espace admin
 * (prive/donnees/reglages.json), qui priment. Seules les clés connues de config.php
 * peuvent être surchargées.
 */
function config_complete(bool $recharger = false): array
{
    static $config = null;
    if ($config === null || $recharger) {
        $config = require __DIR__ . '/config.php';
        $fichier = __DIR__ . '/donnees/reglages.json';
        if (is_file($fichier)) {
            $admin = json_decode((string)file_get_contents($fichier), true);
            if (is_array($admin)) {
                foreach ($admin as $k => $v) {
                    if (array_key_exists($k, $config)) {
                        $config[$k] = $v;
                    }
                }
            }
        }
        // Permet de tester sans modifier config.php : SALW_MODE_TEST=1.
        if (getenv('SALW_MODE_TEST') === '1') {
            $config['mode_test'] = true;
        }
    }
    return $config;
}

function cfg(string $cle)
{
    return config_complete()[$cle] ?? null;
}

function fuseau(): DateTimeZone
{
    return new DateTimeZone((string)cfg('fuseau'));
}

function utc(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

// --- Réponses et saisie ---------------------------------------------------------

function repondre_json(int $code, array $donnees)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function refuser(int $code, string $message)
{
    repondre_json($code, ['ok' => false, 'error' => $message]);
}

/** Champ sur une ligne : retours à la ligne neutralisés (injection d'en-têtes). */
function champ(string $cle, int $max): string
{
    $valeur = trim((string)($_POST[$cle] ?? ''));
    $valeur = str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], ' ', $valeur);
    return mb_substr($valeur, 0, $max);
}

/** Texte sur plusieurs lignes, jamais utilisé dans un en-tête. */
function texte(string $cle, int $max): string
{
    return mb_substr(trim(str_replace("\r", '', (string)($_POST[$cle] ?? ''))), 0, $max);
}

function exiger_post()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        refuser(405, 'Méthode non autorisée.');
    }
    // Piège à robots : champ masqué qu'un humain ne remplit jamais.
    if (trim((string)($_POST['bot-field'] ?? '')) !== '') {
        repondre_json(200, ['ok' => true]);
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// --- Fichiers de données ----------------------------------------------------------

function dossier_donnees(): string
{
    $dossier = __DIR__ . '/donnees';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0750, true);
    }
    return $dossier;
}

/**
 * Lit et modifie un fichier JSON sous verrou exclusif : deux visiteurs ne
 * peuvent jamais réserver le même créneau, même au même instant.
 * $fn reçoit le tableau par référence et renvoie le résultat de l'opération.
 */
function transaction(string $nom, callable $fn)
{
    $chemin = dossier_donnees() . '/' . $nom . '.json';
    $f = @fopen($chemin, 'c+');
    if ($f === false) {
        refuser(500, 'Stockage indisponible sur le serveur.');
    }
    flock($f, LOCK_EX);
    $brut = stream_get_contents($f);
    $donnees = $brut ? json_decode($brut, true) : [];
    if (!is_array($donnees)) {
        $donnees = [];
    }
    $avant = $donnees;
    $resultat = $fn($donnees);
    if ($donnees !== $avant) {
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($f);
    }
    flock($f, LOCK_UN);
    fclose($f);
    return $resultat;
}

function lire(string $nom): array
{
    return transaction($nom, function (array &$d) { return $d; });
}

/** Un envoi toutes les $secondes par adresse IP et par type d'action. */
function limiter_debit(string $action, int $secondes)
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $verrou = sys_get_temp_dir() . '/salw_' . $action . '_' . hash('sha256', $ip) . '.lock';
    if (is_file($verrou) && (time() - (int)@filemtime($verrou)) < $secondes) {
        refuser(429, 'Merci de patienter quelques secondes avant un nouvel envoi.');
    }
    return $verrou;
}

function url_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $hote = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dossier = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . $hote . $dossier;
}

/** Adresse publique du site : réglage url_site si renseigné (utile au cron), sinon déduite de la requête. */
function url_publique(): string
{
    $u = rtrim((string)cfg('url_site'), '/');
    return $u !== '' ? $u : url_base();
}

// --- Créneaux ------------------------------------------------------------------------

/** Occupations lues dans l'agenda iCal d'Albert, en cache 10 minutes. */
function occupations_externes(): array
{
    $url = (string)cfg('ics_occupe');
    if ($url === '') {
        return [];
    }
    $cache = dossier_donnees() . '/cache-agenda.json';
    if (is_file($cache) && time() - (int)filemtime($cache) < 600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d)) {
            return $d;
        }
    }
    $contexte = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'SALW-Sam']]);
    $ics = @file_get_contents($url, false, $contexte);
    if ($ics === false) {
        // Agenda injoignable : on garde l'ancien cache plutôt que de tout ouvrir.
        $d = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
        return is_array($d) ? $d : [];
    }
    $occupations = analyser_ics($ics);
    @file_put_contents($cache, json_encode($occupations));
    return $occupations;
}

/** Extrait les plages occupées [debut, fin] (timestamps) d'un flux iCal. */
function analyser_ics(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
    $resultat = [];
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $evenements)) {
        return [];
    }
    $min = time() - 86400;
    $max = time() + 86400 * ((int)cfg('horizon_jours') + 2);
    foreach ($evenements[1] as $ev) {
        if (preg_match('/^TRANSP:TRANSPARENT/m', $ev) || preg_match('/^STATUS:CANCELLED/m', $ev)) {
            continue;
        }
        $debut = date_ics($ev, 'DTSTART');
        if ($debut === null) {
            continue;
        }
        $fin = date_ics($ev, 'DTEND');
        if ($fin === null) {
            // Sans fin : journée entière pour une date seule, sinon une heure.
            $fin = $debut[1] ? $debut[0] + 86400 : $debut[0] + 3600;
        } else {
            $fin = $fin[0];
        }
        if ($fin < $min || $debut[0] > $max) {
            continue;
        }
        $resultat[] = [$debut[0], $fin];
    }
    return $resultat;
}

/** Renvoie [timestamp, estJourneeEntiere] ou null. */
function date_ics(string $ev, string $prop)
{
    if (!preg_match('/^' . $prop . '((?:;[^:\n]*)?):([0-9TZ]+)/m', $ev, $m)) {
        return null;
    }
    $parametres = $m[1];
    $valeur = $m[2];
    $tz = fuseau();
    if (preg_match('/TZID=([^;:]+)/', $parametres, $t)) {
        try {
            $tz = new DateTimeZone(trim($t[1], '"'));
        } catch (Exception $e) {
            $tz = fuseau();
        }
    }
    if (preg_match('/^(\d{8})$/', $valeur)) {
        $d = DateTimeImmutable::createFromFormat('!Ymd', $valeur, fuseau());
        return $d ? [$d->getTimestamp(), true] : null;
    }
    if (preg_match('/^(\d{8}T\d{6})(Z?)$/', $valeur, $v)) {
        $d = DateTimeImmutable::createFromFormat('Ymd\THis', $v[1], $v[2] === 'Z' ? utc() : $tz);
        return $d ? [$d->getTimestamp(), false] : null;
    }
    return null;
}

/** Réservations confirmées, indexées pour les contrôles de chevauchement. */
function plages_reservees(array $reservations): array
{
    $plages = [];
    foreach ($reservations as $r) {
        if (($r['statut'] ?? '') === 'confirmee') {
            $plages[] = [strtotime($r['debut']), strtotime($r['fin'])];
        }
    }
    return $plages;
}

/** Liste des créneaux libres, du plus proche au plus lointain. */
function creneaux_libres(array $reservations, int $limite = 200): array
{
    $tz = fuseau();
    $duree = (int)cfg('duree') * 60;
    $pas = max(5, (int)cfg('pas')) * 60;
    $maintenant = time();
    $premier = $maintenant + (int)cfg('delai_min_heures') * 3600;
    $occupe = array_merge(plages_reservees($reservations), occupations_externes());
    // Jours fermés : ceux de config.php et ceux fermés depuis admin.php.
    $fermes = array_merge((array)cfg('jours_fermes'), lire('fermetures'));

    $parJour = [];
    foreach ($reservations as $r) {
        if (($r['statut'] ?? '') === 'confirmee') {
            $jour = (new DateTimeImmutable($r['debut']))->setTimezone($tz)->format('Y-m-d');
            $parJour[$jour] = ($parJour[$jour] ?? 0) + 1;
        }
    }

    $libres = [];
    $jour = (new DateTimeImmutable('today', $tz));
    for ($i = 0; $i <= (int)cfg('horizon_jours'); $i++, $jour = $jour->modify('+1 day')) {
        $cle = $jour->format('Y-m-d');
        if (!in_array((int)$jour->format('N'), (array)cfg('jours'), true) || in_array($cle, $fermes, true)) {
            continue;
        }
        $restants = (int)cfg('max_par_jour') - ($parJour[$cle] ?? 0);
        foreach ((array)cfg('plages') as $plage) {
            $debut = DateTimeImmutable::createFromFormat('Y-m-d H:i', $cle . ' ' . $plage[0], $tz);
            $finPlage = DateTimeImmutable::createFromFormat('Y-m-d H:i', $cle . ' ' . $plage[1], $tz);
            if (!$debut || !$finPlage) {
                continue;
            }
            for ($t = $debut->getTimestamp(); $t + $duree <= $finPlage->getTimestamp(); $t += $pas) {
                if ($restants <= 0) {
                    break 2;
                }
                if ($t < $premier) {
                    continue;
                }
                $libre = true;
                foreach ($occupe as $o) {
                    if ($t < $o[1] && $t + $duree > $o[0]) {
                        $libre = false;
                        break;
                    }
                }
                if ($libre) {
                    $libres[] = [
                        'debut' => gmdate('Y-m-d\TH:i:s\Z', $t),
                        'fin'   => gmdate('Y-m-d\TH:i:s\Z', $t + $duree),
                    ];
                    if (count($libres) >= $limite) {
                        return $libres;
                    }
                }
            }
        }
    }
    return $libres;
}

/** Fuseau valide ou, à défaut, celui de Drammen. */
function fuseau_sur(string $nom): DateTimeZone
{
    try {
        return $nom !== '' ? new DateTimeZone($nom) : fuseau();
    } catch (Exception $e) {
        return fuseau();
    }
}

/** « mardi 30 septembre 2026 à 10:00 (heure de Kinshasa) » */
function date_lisible(string $iso, ?DateTimeZone $tz = null): string
{
    $tz = $tz ?: fuseau();
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $d = (new DateTimeImmutable($iso))->setTimezone($tz);
    $ville = str_replace('_', ' ', substr((string)strrchr($tz->getName(), '/'), 1) ?: $tz->getName());
    $noms = ['Oslo' => 'Norvège', 'Kinshasa' => 'Kinshasa', 'Lubumbashi' => 'Lubumbashi', 'Paris' => 'Paris', 'Brussels' => 'Bruxelles', 'Zurich' => 'Zurich'];
    $ville = $noms[$ville] ?? $ville;
    return $jours[(int)$d->format('N')] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n')] . ' ' . $d->format('Y')
        . ' à ' . $d->format('H:i') . ' (heure de ' . $ville . ')';
}

// --- Invitation agenda (.ics) ----------------------------------------------------------

function ics_texte(string $s): string
{
    return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
}

function ics_plier(string $ligne): string
{
    // Les lignes iCal ne dépassent pas 75 octets : on replie sans couper un caractère UTF-8.
    $sortie = '';
    while (strlen($ligne) > 75) {
        $coupe = 75;
        while ($coupe > 0 && (ord($ligne[$coupe]) & 0xC0) === 0x80) {
            $coupe--;
        }
        $sortie .= substr($ligne, 0, $coupe) . "\r\n ";
        $ligne = substr($ligne, $coupe);
    }
    return $sortie . $ligne;
}

function generer_ics(array $r, string $methode = 'REQUEST'): string
{
    $fmt = function (string $iso) { return (new DateTimeImmutable($iso))->setTimezone(utc())->format('Ymd\THis\Z'); };
    $annule = $methode === 'CANCEL';
    $lignes = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//SALW CONSULTING//Conseiller Sam//FR',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $methode,
        'BEGIN:VEVENT',
        // Un rendez-vous déplacé garde son UID : l'agenda du visiteur met l'événement à jour.
        'UID:' . ($r['uid'] ?? $r['id']) . '@salw-consulting.com',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $fmt($r['debut']),
        'DTEND:' . $fmt($r['fin']),
        'SEQUENCE:' . ((int)($r['sequence'] ?? 0) + ($annule ? 1 : 0)),
        'STATUS:' . ($annule ? 'CANCELLED' : 'CONFIRMED'),
        'SUMMARY:' . ics_texte('Diagnostic SALW CONSULTING · ' . $r['nom']),
        'DESCRIPTION:' . ics_texte("Diagnostic gratuit de 30 minutes en visio.\nLien : " . $r['visio']),
        'LOCATION:' . ics_texte($r['visio']),
        'URL:' . $r['visio'],
        'ORGANIZER;CN=' . ics_texte('SALW CONSULTING') . ':mailto:' . cfg('destinataire'),
        'ATTENDEE;CN=' . ics_texte($r['nom']) . ';ROLE=REQ-PARTICIPANT;RSVP=FALSE:mailto:' . $r['email'],
        'BEGIN:VALARM',
        'TRIGGER:-PT15M',
        'ACTION:DISPLAY',
        'DESCRIPTION:Diagnostic SALW dans 15 minutes',
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", array_map('ics_plier', $lignes)) . "\r\n";
}

// --- E-mails ----------------------------------------------------------------------------

function entete_mime(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * Envoie un e-mail texte, avec une invitation agenda en pièce jointe si fournie.
 * En mode test, le message est écrit dans prive/donnees/boite-test/ au lieu d'être envoyé.
 */
function envoyer_mail(string $a, string $sujet, string $corps, string $repondreA = '', string $ics = '', string $methodeIcs = 'REQUEST'): bool
{
    $expediteur = (string)cfg('expediteur');
    $entetes = [
        'From: ' . entete_mime('SALW CONSULTING') . ' <' . $expediteur . '>',
        'MIME-Version: 1.0',
        'X-Mailer: SALW-Sam',
    ];
    if ($repondreA !== '' && filter_var($repondreA, FILTER_VALIDATE_EMAIL)) {
        $entetes[] = 'Reply-To: ' . $repondreA;
    }
    $corps = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $corps));

    if ($ics === '') {
        $entetes[] = 'Content-Type: text/plain; charset=UTF-8';
        $entetes[] = 'Content-Transfer-Encoding: base64';
        $message = chunk_split(base64_encode($corps));
    } else {
        $frontiere = 'salw_' . bin2hex(random_bytes(12));
        $entetes[] = 'Content-Type: multipart/mixed; boundary="' . $frontiere . '"';
        $message = "--{$frontiere}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($corps))
            . "--{$frontiere}\r\n"
            . "Content-Type: text/calendar; charset=UTF-8; method={$methodeIcs}; name=\"diagnostic-salw.ics\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"diagnostic-salw.ics\"\r\n\r\n"
            . chunk_split(base64_encode($ics))
            . "--{$frontiere}--\r\n";
    }

    if (cfg('mode_test')) {
        $dossier = dossier_donnees() . '/boite-test';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0750, true);
        }
        $nom = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        $eml = 'To: ' . $a . "\r\nSubject: " . entete_mime($sujet) . "\r\n" . implode("\r\n", $entetes) . "\r\n\r\n" . $message;
        return @file_put_contents($dossier . '/' . $nom, $eml) !== false;
    }

    $entetesTexte = implode("\r\n", $entetes);
    // L'expéditeur d'enveloppe (-f) améliore la délivrabilité ; certains hébergeurs le refusent.
    $ok = @mail($a, entete_mime($sujet), $message, $entetesTexte, '-f' . $expediteur);
    if (!$ok) {
        $ok = @mail($a, entete_mime($sujet), $message, $entetesTexte);
    }
    return $ok;
}

function lien_visio(string $id): string
{
    $fixe = trim((string)cfg('lien_visio'));
    if ($fixe !== '') {
        return $fixe;
    }
    // Nom de salon long et imprévisible : personne ne tombe dessus par hasard.
    return 'https://meet.jit.si/SALW-Diagnostic-' . strtoupper($id) . '-' . bin2hex(random_bytes(4));
}

// --- Téléphone, rappels, SMS et WhatsApp -------------------------------------------------

/** Canaux Twilio réellement configurés. */
function canaux(): array
{
    $t = (array)cfg('twilio');
    $base = ($t['sid'] ?? '') !== '' && ($t['token'] ?? '') !== '';
    return [
        'sms'      => $base && ($t['sms_de'] ?? '') !== '',
        'whatsapp' => $base && ($t['whatsapp_de'] ?? '') !== '',
    ];
}

/** Numéro au format international (+4712345678), ou chaîne vide s'il est invalide. */
function telephone_valide(string $t): string
{
    $t = preg_replace('/[\s.\-()\/]/', '', $t);
    if (strpos($t, '00') === 0) {
        $t = '+' . substr($t, 2);
    }
    return preg_match('/^\+[1-9]\d{7,14}$/', $t) ? $t : '';
}

/**
 * Rappels prévus pour une réservation, tels qu'annoncés au visiteur.
 * Pas de rappel la veille si le rendez-vous est pris moins de 24 h avant.
 */
function rappels_prevus(array $r): array
{
    $c = canaux();
    $tel = (string)($r['telephone'] ?? '') !== '';
    $prevus = [];
    if (cfg('rappel_j1') && strtotime($r['debut']) - time() > 24 * 3600) {
        $prevus[] = ['cle' => 'j1', 'quand' => 'J-1', 'canaux' => ($tel && $c['whatsapp']) ? ['e-mail', 'WhatsApp'] : ['e-mail']];
    }
    if (cfg('rappel_h2')) {
        $prevus[] = ['cle' => 'h2', 'quand' => 'H-2', 'canaux' => ($tel && $c['sms']) ? ['SMS'] : ['e-mail']];
    }
    return $prevus;
}

/** Envoie un SMS ou un WhatsApp par Twilio. En mode test, écrit le message dans boite-test/. */
function envoyer_message(string $canal, string $a, string $texte): bool
{
    if (cfg('mode_test')) {
        $dossier = dossier_donnees() . '/boite-test';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0750, true);
        }
        $nom = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $canal . '.txt';
        return @file_put_contents($dossier . '/' . $nom, "To: {$a}\n\n{$texte}\n") !== false;
    }
    $t = (array)cfg('twilio');
    $de = $canal === 'whatsapp' ? 'whatsapp:' . $t['whatsapp_de'] : $t['sms_de'];
    $vers = $canal === 'whatsapp' ? 'whatsapp:' . $a : $a;
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($t['sid']) . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $de, 'To' => $vers, 'Body' => $texte]),
        CURLOPT_USERPWD        => $t['sid'] . ':' . $t['token'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
