<?php
/**
 * SALW CONSULTING, conseiller Sam : réservation (ou déplacement) d'un diagnostic.
 *
 * Partagée par reserver.php (site), vapi-outils.php (appel vocal) : une seule
 * logique de verrou, d'e-mails et d'invitation agenda, quel que soit le canal.
 *
 * Entrée déjà validée par l'appelant : debut (ISO UTC), nom, email, telephone
 * (normalisé ou vide), activite, contexte, fuseau (DateTimeZone), remplace_id,
 * remplace_jeton, canal (« site » ou « appel »), appel_id.
 * Retour : ['code' => 200|409|422, 'json' => [...]].
 */

declare(strict_types=1);

function effectuer_reservation(array $d): array
{
    $deplacement = ($d['remplace_id'] ?? '') !== '' && ($d['remplace_jeton'] ?? '') !== '';
    $tzVisiteur = $d['fuseau'];

    $resultat = transaction('reservations', function (array &$reservations) use ($d, $deplacement, $tzVisiteur) {
        // Rendez-vous à remplacer : retiré des occupations le temps du calcul, pour
        // qu'un créneau voisin (ou le même jour) reste proposable.
        $ancienIndex = null;
        if ($deplacement) {
            foreach ($reservations as $i => $x) {
                if ($x['id'] === $d['remplace_id'] && hash_equals($x['jeton'], $d['remplace_jeton']) && $x['statut'] === 'confirmee') {
                    $ancienIndex = $i;
                    break;
                }
            }
            if ($ancienIndex === null) {
                return ['erreur' => 'Le rendez-vous à déplacer est introuvable ou déjà annulé.'];
            }
        }
        $calcul = $reservations;
        if ($ancienIndex !== null) {
            $calcul[$ancienIndex]['statut'] = 'en_deplacement';
        }
        $libre = null;
        foreach (creneaux_libres($calcul) as $c) {
            if ($c['debut'] === $d['debut']) {
                $libre = $c;
                break;
            }
        }
        if ($libre === null) {
            return null;
        }

        $id = bin2hex(random_bytes(5));
        $ancien = $ancienIndex !== null ? $reservations[$ancienIndex] : null;
        $r = [
            'id'        => $id,
            'uid'       => $ancien ? ($ancien['uid'] ?? $ancien['id']) : $id,
            'sequence'  => $ancien ? (int)($ancien['sequence'] ?? 0) + 1 : 0,
            'jeton'     => bin2hex(random_bytes(16)),
            'debut'     => $libre['debut'],
            'fin'       => $libre['fin'],
            'nom'       => $d['nom'],
            'email'     => $d['email'],
            'telephone' => $d['telephone'] !== '' ? $d['telephone'] : ($ancien['telephone'] ?? ''),
            'activite'  => $d['activite'] !== '' ? $d['activite'] : ($ancien['activite'] ?? ''),
            'contexte'  => $d['contexte'] !== '' ? $d['contexte'] : ($ancien['contexte'] ?? ''),
            'fuseau'    => $tzVisiteur->getName(),
            // Même salon de visio en cas de déplacement : le lien déjà noté reste bon.
            'visio'     => $ancien ? $ancien['visio'] : lien_visio($id),
            'statut'    => 'confirmee',
            'cree_le'   => gmdate('Y-m-d\TH:i:s\Z'),
            'remplace'  => $ancien ? $ancien['id'] : '',
            'canal'     => $d['canal'] ?? 'site',
            'appel_id'  => $d['appel_id'] ?? '',
            'conversation_id' => $d['conversation_id'] ?? ($ancien['conversation_id'] ?? ''),
            // Secteur et problème (identifiants) pour les statistiques de l'espace admin.
            'qualif'    => array_filter((array)($d['qualif'] ?? [])) ?: ($ancien['qualif'] ?? []),
            // Suivi commercial (espace admin) : repris d'un rendez-vous déplacé.
            'suivi'     => $ancien['suivi'] ?? null,
            'rappels_envoyes' => [],
        ];
        if ($ancienIndex !== null) {
            $reservations[$ancienIndex]['statut'] = 'remplacee';
            $reservations[$ancienIndex]['remplacee_par'] = $id;
            $reservations[$ancienIndex]['annule_le'] = gmdate('Y-m-d\TH:i:s\Z');
        }
        $reservations[] = $r;
        return ['r' => $r, 'ancien' => $ancien];
    });

    if (is_array($resultat) && isset($resultat['erreur'])) {
        return ['code' => 422, 'json' => ['ok' => false, 'error' => $resultat['erreur']]];
    }
    if ($resultat === null) {
        return ['code' => 409, 'json' => [
            'ok' => false,
            'error' => 'Ce créneau vient d\'être pris. Choisissez-en un autre.',
            'creneaux' => creneaux_libres(lire('reservations'), 120),
        ]];
    }

    $r = $resultat['r'];
    $ancien = $resultat['ancien'];
    if ($r['conversation_id'] !== '') {
        require_once __DIR__ . '/conversations.php';
        conv_lier_reservation($r['conversation_id'], $r['id']);
    }
    $prenom = explode(' ', $r['nom'])[0];
    $lienAnnulation = url_publique() . '/annuler.php?r=' . $r['id'] . '&j=' . $r['jeton'];
    $quandVisiteur = date_lisible($r['debut'], $tzVisiteur);
    $quandAlbert = date_lisible($r['debut']);
    $ics = generer_ics($r);
    $rappels = rappels_prevus($r);
    $texteRappels = '';
    foreach ($rappels as $x) {
        $texteRappels .= '· ' . ($x['quand'] === 'J-1' ? 'la veille' : '2 heures avant') . ' : rappel par ' . implode(' et ', $x['canaux']) . "\n";
    }

    // --- E-mail au visiteur : confirmation et questionnaire de préparation ---------------
    $corpsVisiteur = "Bonjour {$prenom},\n\n"
        . ($ancien
            ? "Votre diagnostic est bien déplacé. L'ancien créneau (" . date_lisible($ancien['debut'], $tzVisiteur) . ") est libéré.\n\n"
            : "Votre diagnostic gratuit de 30 minutes avec " . cfg('nom_conseiller') . ", directeur général de SALW CONSULTING, est confirmé.\n\n")
        . "Quand : {$quandVisiteur}\n"
        . "Où : en visio, depuis votre navigateur, sans rien installer\n"
        . "Lien : {$r['visio']}\n\n"
        . "L'invitation est jointe à ce message pour l'ajouter à votre agenda.\n"
        . ($texteRappels !== '' ? "\nVous recevrez aussi :\n{$texteRappels}" : '')
        . ($ancien ? '' :
            "\nQUESTIONNAIRE DE PRÉPARATION (3 minutes)\n"
            . "Répondez simplement à cet e-mail avant le rendez-vous. Des réponses approximatives suffisent :\n\n"
            . "1. Combien de demandes recevez-vous par semaine, et par quels canaux (téléphone, WhatsApp, e-mail, réseaux sociaux) ?\n"
            . "2. Qui y répond aujourd'hui, et à quelles heures ?\n"
            . "3. Quels outils utilisez-vous déjà (agenda, logiciel métier, WhatsApp Business, fichier Excel) ?\n"
            . "4. Selon vous, combien vous coûte une demande perdue ou un rendez-vous manqué ?\n"
            . "5. Que doit vous apporter ce diagnostic pour que vous le jugiez utile ?\n")
        . "\nUn empêchement ? Annulez en un clic, le créneau sera libéré :\n{$lienAnnulation}\n\n"
        . "À très bientôt,\n"
        . "SALW CONSULTING\n"
        . cfg('destinataire') . " · +47 948 36 971\n";

    $sujetVisiteur = ($ancien ? 'Votre diagnostic SALW est déplacé · ' : 'Votre diagnostic SALW est confirmé · ') . $quandVisiteur;
    $mailVisiteur = envoyer_mail($r['email'], $sujetVisiteur, $corpsVisiteur, (string)cfg('destinataire'), $ics);

    // --- E-mail à Albert : la fiche complète ---------------------------------------------------
    $corpsAlbert = ($ancien ? "Diagnostic DÉPLACÉ (ancien créneau : " . date_lisible($ancien['debut']) . ").\n\n" : "Nouveau diagnostic réservé.\n\n")
        . "Canal     : " . ($r['canal'] === 'appel' ? 'appel vocal avec Sam (adresse e-mail dictée : à vérifier)' : 'site') . "\n"
        . "Quand     : {$quandAlbert}\n"
        . "Visiteur  : {$quandVisiteur}\n"
        . "Nom       : {$r['nom']}\n"
        . "E-mail    : {$r['email']}\n"
        . "Mobile    : " . ($r['telephone'] !== '' ? $r['telephone'] : 'non communiqué') . "\n"
        . "Activité  : " . ($r['activite'] !== '' ? $r['activite'] : 'non précisée') . "\n"
        . "Visio     : {$r['visio']}\n"
        . "Annulation: {$lienAnnulation}\n\n"
        . ($r['contexte'] !== '' ? "{$r['contexte']}\n\n" : "Réservation directe, sans passer par Sam.\n\n")
        . ($mailVisiteur ? '' : "ATTENTION : la confirmation n'a pas pu être envoyée au visiteur. Écris-lui directement.\n");

    envoyer_mail((string)cfg('destinataire'), ($ancien ? 'Diagnostic déplacé · ' : 'Diagnostic réservé · ') . $r['nom'] . ' · ' . $quandAlbert, $corpsAlbert, $r['email'], $ics);

    return ['code' => 200, 'json' => [
        'ok'      => true,
        'id'      => $r['id'],
        'jeton'   => $r['jeton'],
        'debut'   => $r['debut'],
        'fin'     => $r['fin'],
        'visio'   => $r['visio'],
        'ics'     => $ics,
        'mail'    => $mailVisiteur,
        'rappels' => array_map(function ($x) { return ['quand' => $x['quand'], 'canaux' => $x['canaux']]; }, $rappels),
    ]];
}
