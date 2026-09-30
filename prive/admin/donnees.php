<?php
/**
 * SALW CONSULTING, espace admin : suivi commercial des rendez-vous et indicateurs.
 *
 * Chaque rendez-vous porte un bloc « suivi » : présence (honoré / absent), étape
 * commerciale (devis envoyé, négociation, signé, perdu), montant, offre, date de
 * relance, notes datées. Le tableau de bord se calcule à la volée à partir des
 * rendez-vous, des conversations et des demandes : aucune donnée en double.
 */

declare(strict_types=1);

const ETAPES = ['' => 'Diagnostic', 'devis' => 'Devis envoyé', 'negociation' => 'Négociation', 'signe' => 'Signé', 'perdu' => 'Perdu'];
const OFFRES = ['' => 'Non précisée', 'A' => 'A · Plateforme par métier', 'B' => 'B · Intégration IA sur mesure', 'C' => 'C · Formation IA', 'D' => 'D · Sites & applications'];
const PRESENCES = ['' => 'À renseigner', 'honore' => 'Honoré', 'absent' => 'Absent'];

function suivi(array $r): array
{
    return array_merge(['presence' => '', 'etape' => '', 'montant' => 0.0, 'offre' => '', 'relance_le' => '', 'signe_le' => '', 'notes' => []], (array)($r['suivi'] ?? []));
}

/** Rendez-vous « actifs » : ni annulés ni remplacés par un déplacement. */
function rdv_actifs(array $reservations): array
{
    return array_values(array_filter($reservations, function ($r) { return $r['statut'] === 'confirmee'; }));
}

function rdv_passe(array $r): bool
{
    return strtotime($r['fin']) < time();
}

/** Colonne du pipeline commercial. */
function colonne_pipeline(array $r): string
{
    $s = suivi($r);
    if ($s['etape'] !== '') {
        return $s['etape'];
    }
    if (!rdv_passe($r)) {
        return 'a_venir';
    }
    if ($s['presence'] === 'absent') {
        return 'absent';
    }
    return $s['presence'] === 'honore' ? 'fait' : 'a_qualifier';
}

const COLONNES = [
    'a_venir'     => 'Diagnostic à venir',
    'a_qualifier' => 'À qualifier',
    'fait'        => 'Diagnostic fait',
    'devis'       => 'Devis envoyé',
    'negociation' => 'Négociation',
    'signe'       => 'Signé',
    'perdu'       => 'Perdu',
    'absent'      => 'Absent',
];

/** Secteur et problème d'un rendez-vous : saisis à la réservation, sinon ceux de la conversation liée. */
function qualif_rdv(array $r, array $conversations): array
{
    $q = (array)($r['qualif'] ?? []);
    $conv = $conversations[$r['conversation_id'] ?? ''] ?? null;
    if ($conv) {
        foreach ((array)$conv['qualif'] as $k => $v) {
            if (($q[$k] ?? '') === '' && $v !== '') {
                $q[$k] = $v;
            }
        }
    }
    return $q + ['secteur' => '', 'probleme' => '', 'activite' => $r['activite'] ?? '', 'ville' => '', 'equipe' => '', 'resume' => ''];
}

function canal_rdv(array $r, array $conversations): string
{
    if (($r['canal'] ?? '') === 'appel') {
        return 'appel';
    }
    $conv = $conversations[$r['conversation_id'] ?? ''] ?? null;
    if (!$conv) {
        return 'direct';
    }
    return in_array('chat-ia', (array)$conv['canaux'], true) ? 'chat-ia' : (in_array('appel', (array)$conv['canaux'], true) ? 'appel' : 'guide');
}

/** Indicateurs du tableau de bord sur les $jours derniers jours. */
function indicateurs(int $jours): array
{
    $tz = fuseau();
    $depuis = time() - $jours * 86400;
    $reservations = lire('reservations');
    $conversations = lire('conversations');
    $actifs = rdv_actifs($reservations);

    $convPeriode = array_filter($conversations, function ($c) use ($depuis) { return strtotime($c['cree_le']) >= $depuis; });
    $rdvPeriode = array_filter($actifs, function ($r) use ($depuis) { return strtotime($r['cree_le']) >= $depuis; });
    $annulesPeriode = array_filter($reservations, function ($r) use ($depuis) { return $r['statut'] === 'annulee' && strtotime($r['cree_le']) >= $depuis; });
    $qualifiees = array_filter($convPeriode, function ($c) { return ($c['qualif']['resume'] ?? '') !== ''; });
    $convAvecRdv = array_filter($convPeriode, function ($c) { return ($c['reservation_id'] ?? '') !== ''; });

    $honores = 0; $absents = 0; $devis = 0; $signes = 0; $caSigne = 0.0; $pipeline = 0.0;
    foreach ($actifs as $r) {
        $s = suivi($r);
        if (strtotime($r['cree_le']) >= $depuis && rdv_passe($r)) {
            $honores += $s['presence'] === 'honore' ? 1 : 0;
            $absents += $s['presence'] === 'absent' ? 1 : 0;
        }
        if (in_array($s['etape'], ['devis', 'negociation'], true)) {
            $pipeline += (float)$s['montant'];
        }
        if (strtotime($r['cree_le']) >= $depuis) {
            $devis += in_array($s['etape'], ['devis', 'negociation', 'signe'], true) ? 1 : 0;
            if ($s['etape'] === 'signe') {
                $signes++;
                $caSigne += (float)$s['montant'];
            }
        }
    }

    // Objectif : CA signé sur la période de l'objectif (date de signature).
    $debutObj = (string)cfg('objectif_debut');
    $finObj = (string)cfg('objectif_fin');
    $caObjectif = 0.0;
    foreach ($actifs as $r) {
        $s = suivi($r);
        if ($s['etape'] === 'signe' && $s['signe_le'] !== '' && ($debutObj === '' || $s['signe_le'] >= $debutObj) && ($finObj === '' || $s['signe_le'] <= $finObj)) {
            $caObjectif += (float)$s['montant'];
        }
    }

    // Séries par jour (30 derniers jours, heure de Drammen).
    $serieConv = []; $serieRdv = [];
    $jour = new DateTimeImmutable('today', $tz);
    for ($i = 29; $i >= 0; $i--) {
        $k = $jour->modify("-{$i} days")->format('Y-m-d');
        $serieConv[$k] = 0;
        $serieRdv[$k] = 0;
    }
    foreach ($conversations as $c) {
        $k = (new DateTimeImmutable($c['cree_le']))->setTimezone($tz)->format('Y-m-d');
        if (isset($serieConv[$k])) {
            $serieConv[$k]++;
        }
    }
    foreach ($actifs as $r) {
        $k = (new DateTimeImmutable($r['cree_le']))->setTimezone($tz)->format('Y-m-d');
        if (isset($serieRdv[$k])) {
            $serieRdv[$k]++;
        }
    }

    // Répartitions (période).
    $parSecteur = []; $parProbleme = []; $parCanal = [];
    foreach ($convPeriode as $c) {
        $s = $c['qualif']['secteur'] ?: 'non';
        $p = $c['qualif']['probleme'] ?: 'non';
        $parSecteur[$s] = ($parSecteur[$s] ?? 0) + 1;
        $parProbleme[$p] = ($parProbleme[$p] ?? 0) + 1;
    }
    foreach ($rdvPeriode as $r) {
        $k = canal_rdv($r, $conversations);
        $parCanal[$k] = ($parCanal[$k] ?? 0) + 1;
    }
    arsort($parSecteur); arsort($parProbleme); arsort($parCanal);

    // Agent IA.
    $ia = lire('ia-compteurs');
    $iaAujourdhui = ($ia['jour'] ?? '') === gmdate('Y-m-d') ? (int)$ia['total'] : 0;
    $ia30 = $iaAujourdhui;
    foreach ((array)($ia['historique'] ?? []) as $d => $n) {
        if (strtotime($d) >= time() - 30 * 86400) {
            $ia30 += (int)$n;
        }
    }
    $appels = count(array_filter($rdvPeriode, function ($r) { return ($r['canal'] ?? '') === 'appel'; }));

    return [
        'conversations' => count($convPeriode),
        'qualifiees'    => count($qualifiees),
        'rdv'           => count($rdvPeriode),
        'annules'       => count($annulesPeriode),
        'conversion'    => count($convPeriode) ? count($convAvecRdv) / count($convPeriode) : null,
        'presence'      => ($honores + $absents) ? $honores / ($honores + $absents) : null,
        'honores'       => $honores,
        'absents'       => $absents,
        'devis'         => $devis,
        'signes'        => $signes,
        'ca_signe'      => $caSigne,
        'pipeline'      => $pipeline,
        'ca_objectif'   => $caObjectif,
        'serie_conv'    => $serieConv,
        'serie_rdv'     => $serieRdv,
        'par_secteur'   => $parSecteur,
        'par_probleme'  => $parProbleme,
        'par_canal'     => $parCanal,
        'ia_jour'       => $iaAujourdhui,
        'ia_30'         => $ia30,
        'rdv_appel'     => $appels,
    ];
}

/** Liste « À faire » du tableau de bord. */
function a_faire(): array
{
    $tz = fuseau();
    $aujourdhui = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $demain = (new DateTimeImmutable('tomorrow', $tz))->format('Y-m-d');
    $actifs = rdv_actifs(lire('reservations'));
    $prochains = []; $aQualifier = []; $relances = [];
    foreach ($actifs as $r) {
        $jour = (new DateTimeImmutable($r['debut']))->setTimezone($tz)->format('Y-m-d');
        $s = suivi($r);
        if (!rdv_passe($r) && ($jour === $aujourdhui || $jour === $demain)) {
            $prochains[] = $r;
        }
        if (rdv_passe($r) && $s['presence'] === '' && $s['etape'] === '') {
            $aQualifier[] = $r;
        }
        if ($s['relance_le'] !== '' && $s['relance_le'] <= $aujourdhui && !in_array($s['etape'], ['signe', 'perdu'], true)) {
            $relances[] = $r;
        }
    }
    usort($prochains, function ($a, $b) { return strcmp($a['debut'], $b['debut']); });
    $demandes = array_filter(lire('demandes'), function ($d) { return empty($d['traite']); });
    $chauds = array_filter(lire('conversations'), function ($c) {
        return ($c['reservation_id'] ?? '') === '' && ($c['qualif']['resume'] ?? '') !== '' && strtotime($c['maj_le']) > time() - 7 * 86400;
    });
    return ['prochains' => $prochains, 'a_qualifier' => $aQualifier, 'relances' => $relances, 'demandes' => count($demandes), 'chauds' => count($chauds)];
}

/** Enregistre une modification du suivi d'un rendez-vous. Renvoie le rendez-vous modifié ou null. */
function modifier_suivi(string $id, array $changements, string $note, string $auteur): ?array
{
    return transaction('reservations', function (array &$liste) use ($id, $changements, $note, $auteur) {
        foreach ($liste as $i => $r) {
            if ($r['id'] !== $id) {
                continue;
            }
            $s = suivi($r);
            foreach ($changements as $k => $v) {
                $s[$k] = $v;
            }
            if (($changements['etape'] ?? null) === 'signe' && $s['signe_le'] === '') {
                $s['signe_le'] = (new DateTimeImmutable('today', fuseau()))->format('Y-m-d');
            }
            if (isset($changements['etape']) && $changements['etape'] !== 'signe') {
                $s['signe_le'] = '';
            }
            if ($note !== '') {
                $s['notes'][] = ['t' => gmdate('Y-m-d\TH:i:s\Z'), 'auteur' => $auteur, 'texte' => mb_substr($note, 0, 3000)];
            }
            $s['maj_le'] = gmdate('Y-m-d\TH:i:s\Z');
            $s['maj_par'] = $auteur;
            $liste[$i]['suivi'] = $s;
            return $liste[$i];
        }
        return null;
    });
}
