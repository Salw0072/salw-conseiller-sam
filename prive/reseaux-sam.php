<?php
/**
 * SALW CONSULTING, site Sam : WhatsApp, Facebook, Instagram et LinkedIn de SALW.
 *
 * - Rappel de diagnostic la veille par WhatsApp : API de Meta avec le modèle approuvé « salw_rappel_diagnostic »
 *   quand WhatsApp est connecté (Réglages › Réseaux sociaux), sinon par Twilio comme avant.
 * - Publications : immédiates ou programmées (file prive/donnees/publications.json, publiée par rappels.php).
 * - Prospects des publicités Facebook et Instagram (meta-webhook.php) : ajoutés aux Demandes, Albert est prévenu,
 *   le prospect reçoit le lien du diagnostic par e-mail, et par WhatsApp s'il l'a accepté dans le formulaire.
 */

declare(strict_types=1);

require_once __DIR__ . '/reseaux-api.php';

const RESEAUX_SAM = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];

function sam_connecte(string $reseau): bool
{
    switch ($reseau) {
        case 'whatsapp':
            return (string)cfg('wa_numero_id') !== '' && (string)cfg('wa_jeton') !== '';
        case 'facebook':
            return (string)cfg('fb_page_id') !== '' && (string)cfg('fb_jeton') !== '';
        case 'instagram':
            return (string)cfg('ig_compte_id') !== '' && (string)cfg('fb_jeton') !== '' && sam_image_instagram() !== '';
        case 'linkedin':
            return (string)cfg('li_organisation') !== '' && (string)cfg('li_jeton') !== '';
    }
    return false;
}

/** Rappel WhatsApp par Meta (modèle approuvé) ; false si non configuré ou en échec, pour passer à Twilio. */
function sam_whatsapp_modele(string $telephone, string $modele, array $variables): bool
{
    if (!sam_connecte('whatsapp')) {
        return false;
    }
    if (cfg('mode_test')) {
        return envoyer_message('whatsapp', $telephone, '[modèle ' . $modele . '] ' . implode(' | ', $variables));
    }
    try {
        return whatsapp_modele((string)cfg('wa_numero_id'), (string)cfg('wa_jeton'), $telephone, $modele, 'fr', $variables) !== '';
    } catch (RuntimeException $e) {
        return false;
    }
}

/** Publie maintenant sur les réseaux demandés ; renvoie [réseau => [statut, détail]]. */
function sam_publier(array $reseaux, string $texte, string $lien): array
{
    $res = [];
    foreach (array_intersect(array_keys(RESEAUX_SAM), $reseaux) as $r) {
        if (!sam_connecte($r)) {
            $res[$r] = ['ignore', $r === 'instagram' ? 'Instagram non connecté (compte, jeton de la page et image JPEG nécessaires)' : 'non connecté'];
            continue;
        }
        if (cfg('mode_test')) {
            $res[$r] = ['simule', 'mode test'];
            continue;
        }
        $complet = $lien === '' ? $texte : $texte . ' ' . $lien;
        try {
            $ref = $r === 'facebook' ? facebook_publier((string)cfg('fb_page_id'), (string)cfg('fb_jeton'), $texte, $lien)
                : ($r === 'instagram' ? instagram_publier((string)cfg('ig_compte_id'), (string)cfg('fb_jeton'), sam_image_instagram(), $complet)
                : linkedin_publier((string)cfg('li_organisation'), (string)cfg('li_jeton'), $complet));
            $res[$r] = ['publie', $ref];
        } catch (RuntimeException $e) {
            $res[$r] = ['echec', $e->getMessage()];
        }
    }
    return $res;
}

/** Ajoute une publication à la file ; publiée tout de suite si l'heure est passée. */
function sam_programmer(array $reseaux, string $texte, string $lien, int $quand, string $auteur): array
{
    $p = ['id' => bin2hex(random_bytes(5)), 'texte' => $texte, 'lien' => $lien, 'reseaux' => array_values($reseaux), 'prevue_le' => gmdate('Y-m-d\TH:i:s\Z', $quand),
        'statut' => 'prevue', 'resultats' => [], 'cree_le' => gmdate('Y-m-d\TH:i:s\Z'), 'auteur' => $auteur];
    transaction('publications', function (array &$l) use ($p) { $l[] = $p; });
    if ($quand <= time()) {
        sam_publier_en_attente();
    }
    return $p;
}

/** Tâche cron (rappels.php) : publie ce qui est arrivé à échéance, une seule fois. */
function sam_publier_en_attente(): array
{
    $journal = [];
    // On réserve d'abord les publications sous verrou (statut « en_cours »), puis on publie hors verrou.
    $aFaire = transaction('publications', function (array &$l) {
        $choix = [];
        foreach ($l as $i => $p) {
            if ($p['statut'] === 'prevue' && strtotime($p['prevue_le']) <= time()) {
                $l[$i]['statut'] = 'en_cours';
                $choix[] = $p;
            }
        }
        return $choix;
    });
    foreach ($aFaire as $p) {
        $res = sam_publier($p['reseaux'], $p['texte'], $p['lien']);
        $echec = (bool)array_filter($res, function ($r) { return $r[0] === 'echec'; });
        transaction('publications', function (array &$l) use ($p, $res, $echec) {
            foreach ($l as $i => $x) {
                if ($x['id'] === $p['id']) {
                    $l[$i]['statut'] = $echec ? 'echec' : 'publiee';
                    $l[$i]['resultats'] = $res;
                    $l[$i]['publie_le'] = gmdate('Y-m-d\TH:i:s\Z');
                }
            }
        });
        $journal[] = 'Publication ' . $p['id'] . ' : ' . implode(', ', array_map(function ($r, $v) { return $r . ' ' . $v[0]; }, array_keys($res), $res));
    }
    return $journal;
}

/** Prospect d'une publicité Facebook ou Instagram de SALW : Demandes, avis à Albert, lien du diagnostic au prospect. */
function sam_prospect(string $prospectId): bool
{
    $deja = lire('prospects-pub');
    if (in_array($prospectId, $deja, true) || !sam_connecte('facebook')) {
        return false;
    }
    transaction('prospects-pub', function (array &$l) use ($prospectId) { $l[] = $prospectId; });
    $lu = meta_prospect($prospectId, (string)cfg('fb_jeton'));
    $p = prospect_normalise($lu['champs']);
    $source = $lu['plateforme'] === 'ig' ? 'Instagram' : 'Facebook';
    $nom = trim($p['prenom'] . ' ' . $p['nom']) ?: 'Prospect ' . $source;
    $details = "Publicité {$source}" . ($p['telephone'] !== '' ? "\nTéléphone : {$p['telephone']}" : '') . "\nAccord WhatsApp : " . ($p['whatsapp'] ? 'oui' : 'non');
    foreach ($lu['champs'] as $k => $v) {
        if (!in_array($k, ['full_name', 'first_name', 'last_name', 'email', 'phone_number', 'whatsapp'], true) && $v !== '') {
            $details .= "\n" . $k . ' : ' . $v;
        }
    }
    demande_enregistrer($nom, $p['email'], 'publicite', $details);
    envoyer_mail((string)cfg('destinataire'), 'Nouveau prospect ' . $source . ' · ' . $nom, "Un prospect vient de remplir le formulaire de votre publicité {$source}.\n\n{$nom}\n" . ($p['email'] !== '' ? $p['email'] . "\n" : '') . $details . "\n\nIl est dans Demandes, dans l'espace admin.\n", $p['email']);
    $lien = site_racine_sam() . '/#contact';
    if ($p['email'] !== '') {
        envoyer_mail($p['email'], 'Votre demande à SALW CONSULTING',
            "Bonjour" . ($p['prenom'] !== '' ? ' ' . $p['prenom'] : '') . ",\n\nMerci pour votre demande. Choisissez le créneau de votre diagnostic gratuit de 30 minutes ici :\n\n{$lien}\n\nÀ très vite,\n" . (string)cfg('nom_conseiller') . "\nSALW CONSULTING\n", (string)cfg('destinataire'));
    }
    if ($p['whatsapp'] && $p['telephone'] !== '') {
        sam_whatsapp_modele($p['telephone'], 'salw_prospect_diagnostic', [$p['prenom'] ?: 'Bonjour', $lien]);
    }
    return true;
}

/** Image JPEG publique pour Instagram : réglage ig_image, sinon la photo d'Albert du site (img/albert.jpg). */
function sam_image_instagram(): string
{
    $image = (string)cfg('ig_image');
    $site = rtrim((string)cfg('url_site'), '/');
    return $image !== '' ? $image : ($site !== '' ? $site . '/img/albert.jpg' : '');
}

function site_racine_sam(): string
{
    $site = rtrim((string)cfg('url_site'), '/');
    return $site !== '' ? $site : (string)preg_replace('#/admin$#', '', url_base());
}
