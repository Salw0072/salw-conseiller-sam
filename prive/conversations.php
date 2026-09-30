<?php
/**
 * SALW CONSULTING, conseiller Sam : conversations (prospects), demandes de créneau,
 * et purge automatique des données (RGPD).
 *
 * conversations.json : une entrée par conversation avec Sam (chat guidé, chat IA,
 * appel vocal), mise à jour au fil de l'échange par conversation.php. Une conversation
 * sans rendez-vous est supprimée après conservation_conversations_jours (30 par défaut).
 */

declare(strict_types=1);

const CANAUX_CONVERSATION = ['guide', 'chat-ia', 'appel'];
const SECTEURS = ['juridique', 'sante', 'immobilier', 'artisan', 'coach', 'ecole', 'garage', 'grands', 'autre'];
const PROBLEMES = ['demandes', 'rdv', 'devis', 'fidelite', 'autre'];

function libelle_secteur(string $id): string
{
    $l = ['juridique' => 'Cabinets juridiques & comptables', 'sante' => 'Cliniques & santé', 'immobilier' => 'Immobilier',
        'artisan' => 'Artisans & services à domicile', 'coach' => 'Coachs, consultants, formateurs', 'ecole' => 'Écoles & universités privées',
        'garage' => 'Garages & auto-écoles', 'grands' => 'Grands comptes & institutions', 'autre' => 'Autre activité'];
    return $l[$id] ?? 'Non précisé';
}

function libelle_probleme(string $id): string
{
    $l = ['demandes' => 'Demandes perdues', 'rdv' => 'Rendez-vous non honorés', 'devis' => 'Devis sans réponse', 'fidelite' => 'Clients qui ne reviennent pas', 'autre' => 'Autre'];
    return $l[$id] ?? 'Non précisé';
}

function libelle_canal(string $id): string
{
    $l = ['guide' => 'Sam guidé', 'chat-ia' => 'Chat IA', 'appel' => 'Appel vocal', 'direct' => 'Réservation directe'];
    return $l[$id] ?? $id;
}

/** Enregistre ou met à jour une conversation. $c déjà validée par conversation.php. */
function conv_enregistrer(array $c): void
{
    transaction('conversations', function (array &$toutes) use ($c) {
        $maintenant = gmdate('Y-m-d\TH:i:s\Z');
        $existante = $toutes[$c['id']] ?? null;
        $canaux = array_values(array_unique(array_merge((array)($existante['canaux'] ?? []), $c['canaux'])));
        $toutes[$c['id']] = [
            'id'             => $c['id'],
            'cree_le'        => $existante['cree_le'] ?? $maintenant,
            'maj_le'         => $maintenant,
            'canaux'         => $canaux,
            'messages'       => $c['messages'],
            'qualif'         => $c['qualif'],
            'fuseau'         => $c['fuseau'],
            // Le lien avec un rendez-vous n'est posé que par le serveur (effectuer_reservation).
            'reservation_id' => $existante['reservation_id'] ?? '',
        ];
    });
}

/** Rattache une conversation au rendez-vous qu'elle a produit. */
function conv_lier_reservation(string $convId, string $resaId): void
{
    if ($convId === '') {
        return;
    }
    transaction('conversations', function (array &$toutes) use ($convId, $resaId) {
        if (isset($toutes[$convId])) {
            $toutes[$convId]['reservation_id'] = $resaId;
        }
    });
}

/** Demande de créneau reçue par le formulaire de repli (contact.php). */
function demande_enregistrer(string $nom, string $email, string $type, string $message): void
{
    transaction('demandes', function (array &$d) use ($nom, $email, $type, $message) {
        $d[] = ['id' => bin2hex(random_bytes(5)), 'recu_le' => gmdate('Y-m-d\TH:i:s\Z'), 'nom' => $nom, 'email' => $email,
            'type' => $type, 'message' => $message, 'traite' => false];
    });
}

/**
 * Purge RGPD, au plus une fois par heure (appelée par le cron et à l'ouverture de l'admin) :
 * - conversations sans rendez-vous, inactives depuis conservation_conversations_jours ;
 * - demandes de créneau de plus de conservation_conversations_jours ;
 * - rendez-vous passés depuis plus de conservation_rdv_mois, avec leur conversation ;
 * - jours fermés déjà passés.
 * Renvoie le nombre d'éléments supprimés.
 */
function purger_donnees(bool $forcer = false): int
{
    $etat = lire('purge');
    if (!$forcer && isset($etat['derniere']) && time() - (int)$etat['derniere'] < 3600) {
        return 0;
    }
    $jours = max(1, (int)cfg('conservation_conversations_jours'));
    $limiteConv = time() - $jours * 86400;
    $limiteRdv = strtotime('-' . max(1, (int)cfg('conservation_rdv_mois')) . ' months');
    $supprimes = 0;

    $rdvSupprimes = transaction('reservations', function (array &$r) use ($limiteRdv) {
        $ids = [];
        foreach ($r as $i => $x) {
            if (strtotime($x['fin']) < $limiteRdv) {
                $ids[] = $x['id'];
                unset($r[$i]);
            }
        }
        $r = array_values($r);
        return $ids;
    });
    $supprimes += count($rdvSupprimes);

    $supprimes += transaction('conversations', function (array &$c) use ($limiteConv, $rdvSupprimes) {
        $n = 0;
        foreach ($c as $id => $x) {
            $sansRdv = ($x['reservation_id'] ?? '') === '';
            if (($sansRdv && strtotime($x['maj_le']) < $limiteConv) || (!$sansRdv && in_array($x['reservation_id'], $rdvSupprimes, true))) {
                unset($c[$id]);
                $n++;
            }
        }
        return $n;
    });

    $supprimes += transaction('demandes', function (array &$d) use ($limiteConv) {
        $avant = count($d);
        $d = array_values(array_filter($d, function ($x) use ($limiteConv) { return strtotime($x['recu_le']) >= $limiteConv; }));
        return $avant - count($d);
    });

    $aujourdhui = (new DateTimeImmutable('today', fuseau()))->format('Y-m-d');
    transaction('fermetures', function (array &$f) use ($aujourdhui) {
        $f = array_values(array_filter($f, function ($j) use ($aujourdhui) { return $j >= $aujourdhui; }));
    });

    transaction('purge', function (array &$e) use ($supprimes) {
        $e = ['derniere' => time(), 'supprimes' => $supprimes];
    });
    return $supprimes;
}
