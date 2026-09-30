<?php
/**
 * SALW CONSULTING, conseiller Sam : moteur du chat IA (Claude, SDK Anthropic officiel).
 *
 * Exige PHP 8.1+ (arguments nommés du SDK). N'est chargé qu'après vérification
 * de la version par ia.php : sur un PHP plus ancien, le site reste en mode guidé.
 *
 * Le navigateur envoie l'historique en texte ; le serveur mène la boucle d'outils
 * (au plus 4 tours) et renvoie : le texte de Sam, la fiche de qualification, et
 * l'ordre d'afficher des créneaux. La réservation elle-même passe par reserver.php,
 * comme sans IA : le modèle ne réserve jamais seul dans le chat.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/sam-connaissances.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;

const IA_SECTEURS = ['juridique', 'sante', 'immobilier', 'artisan', 'coach', 'ecole', 'garage', 'grands', 'autre'];
const IA_PROBLEMES = ['demandes', 'rdv', 'devis', 'fidelite', 'autre'];

/** Définition des outils : liste figée et ordonnée (condition de la mise en cache). */
function ia_outils(): array
{
    return [
        [
            'name' => 'creneaux_disponibles',
            'description' => "Liste les prochains créneaux libres du diagnostic de 30 minutes, avec un libellé dans l'heure du visiteur. À utiliser quand le visiteur évoque un jour ou une heure précis.",
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            'strict' => true,
        ],
        [
            'name' => 'enregistrer_fiche',
            'description' => "Enregistre la fiche de qualification transmise à Albert, une fois le résumé validé par le visiteur.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'secteur'  => ['type' => 'string', 'enum' => IA_SECTEURS, 'description' => 'Secteur le plus proche.'],
                    'activite' => ['type' => 'string', 'description' => "L'activité, dans les mots du visiteur."],
                    'ville'    => ['type' => 'string', 'description' => 'Ville, ou chaîne vide si inconnue.'],
                    'probleme' => ['type' => 'string', 'enum' => IA_PROBLEMES],
                    'equipe'   => ['type' => 'string', 'description' => "Taille de l'équipe telle que dite, ex. « 3 personnes »."],
                    'resume'   => ['type' => 'string', 'description' => 'Le résumé validé par le visiteur.'],
                ],
                'required' => ['secteur', 'activite', 'ville', 'probleme', 'equipe', 'resume'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
        [
            'name' => 'proposer_creneaux',
            'description' => "Affiche au visiteur des boutons de créneaux réels ; le site recueille ensuite son nom et son e-mail et confirme. Liste vide : les deux prochains créneaux.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'debuts' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Au plus trois débuts exacts renvoyés par creneaux_disponibles, ou liste vide.',
                    ],
                ],
                'required' => ['debuts'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ],
    ];
}

/** Libellé parlé d'un créneau dans le fuseau du visiteur : « jeudi 1 octobre à 10:30 ». */
function ia_libelle(string $iso, DateTimeZone $tz): string
{
    return preg_replace('/ \d{4} à /', ' à ', preg_replace('/ \(heure de .*\)$/', '', date_lisible($iso, $tz)));
}

/**
 * Exécute un outil. Met à jour $fiche et $actions par référence.
 * Renvoie [contenu du tool_result, arrêter la boucle ?].
 */
function ia_executer_outil(string $nom, array $entree, DateTimeZone $tz, ?array &$fiche, array &$actions): array
{
    if ($nom === 'creneaux_disponibles') {
        $liste = [];
        foreach (creneaux_libres(lire('reservations'), 12) as $c) {
            $liste[] = ['debut' => $c['debut'], 'libelle' => ia_libelle($c['debut'], $tz)];
        }
        return [$liste ? json_encode($liste, JSON_UNESCAPED_UNICODE) : 'Aucun créneau libre dans les trois prochaines semaines : propose au visiteur d\'écrire à albert@salw-consulting.com.', false];
    }

    if ($nom === 'enregistrer_fiche') {
        $f = [];
        foreach (['activite' => 160, 'ville' => 80, 'equipe' => 60, 'resume' => 400] as $cle => $max) {
            $f[$cle] = mb_substr(trim((string)($entree[$cle] ?? '')), 0, $max);
        }
        $f['secteur'] = in_array($entree['secteur'] ?? '', IA_SECTEURS, true) ? $entree['secteur'] : 'autre';
        $f['probleme'] = in_array($entree['probleme'] ?? '', IA_PROBLEMES, true) ? $entree['probleme'] : 'autre';
        $fiche = $f;
        return ['Fiche enregistrée.', false];
    }

    if ($nom === 'proposer_creneaux') {
        $libres = creneaux_libres(lire('reservations'), 120);
        $parDebut = [];
        foreach ($libres as $c) {
            $parDebut[$c['debut']] = $c;
        }
        $choisis = [];
        foreach (array_slice((array)($entree['debuts'] ?? []), 0, 3) as $d) {
            if (is_string($d) && isset($parDebut[$d])) {
                $choisis[] = $parDebut[$d];
            }
        }
        if (!$choisis) {
            $choisis = array_slice($libres, 0, 2);
        }
        $actions[] = ['type' => 'creneaux', 'creneaux' => $choisis];
        return ['Créneaux affichés au visiteur. Attends son choix : le site se charge de la suite.', true];
    }

    return ['Outil inconnu.', false];
}

/**
 * Une réponse de Sam. $historique : [['role' => 'user'|'assistant', 'content' => string], ...]
 * Renvoie ['ok' => true, 'texte', 'fiche', 'actions'] ou ['ok' => false, 'repli' => true, 'error'].
 */
function ia_repondre(array $historique, DateTimeZone $tz): array
{
    $client = new Client(apiKey: (string)cfg('ia_cle'));
    $messages = $historique;
    $fiche = null;
    $actions = [];
    $textes = [];

    try {
        for ($tour = 0; $tour < 4; $tour++) {
            $reponse = $client->beta->messages->create(
                model: (string)cfg('ia_modele'),
                maxTokens: 4096,
                // Texte figé + cache : le préfixe identique d'une requête à l'autre est relu depuis le cache.
                system: [['type' => 'text', 'text' => sam_prompt('chat'), 'cacheControl' => ['type' => 'ephemeral']]],
                messages: $messages,
                tools: ia_outils(),
                outputConfig: ['effort' => (string)cfg('ia_effort')],
                // En cas de refus du modèle, l'API bascule elle-même sur un modèle de repli adapté.
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
                requestOptions: ['timeout' => 30.0, 'maxRetries' => 1],
            );

            if ($reponse->stopReason === 'refusal') {
                return ['ok' => false, 'repli' => true, 'error' => 'refus'];
            }

            foreach ($reponse->content as $bloc) {
                if ($bloc->type === 'text' && trim($bloc->text) !== '') {
                    $textes[] = trim($bloc->text);
                }
            }
            if ($reponse->stopReason !== 'tool_use') {
                break;
            }

            $resultats = [];
            $arreter = false;
            foreach ($reponse->content as $bloc) {
                if ($bloc->type !== 'tool_use') {
                    continue;
                }
                $entree = is_array($bloc->input) ? $bloc->input : (array)json_decode(json_encode($bloc->input), true);
                [$contenu, $stop] = ia_executer_outil($bloc->name, $entree, $tz, $fiche, $actions);
                $resultats[] = ['type' => 'tool_result', 'toolUseID' => $bloc->id, 'content' => $contenu];
                $arreter = $arreter || $stop;
            }
            // Créneaux affichés : c'est au visiteur de jouer, inutile de relancer le modèle.
            if ($arreter) {
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $reponse->content];
            $messages[] = ['role' => 'user', 'content' => $resultats];
        }
    } catch (AuthenticationException $e) {
        return ['ok' => false, 'repli' => true, 'error' => 'cle'];
    } catch (RateLimitException $e) {
        return ['ok' => false, 'repli' => true, 'error' => 'limite'];
    } catch (BadRequestException $e) {
        return ['ok' => false, 'repli' => true, 'error' => 'requete'];
    } catch (APIStatusException $e) {
        // 529 (surcharge), 5xx...
        return ['ok' => false, 'repli' => true, 'error' => 'api'];
    } catch (APIConnectionException $e) {
        // Couvre aussi le dépassement de délai (APITimeoutException en hérite).
        return ['ok' => false, 'repli' => true, 'error' => 'connexion'];
    }

    $texte = implode("\n\n", $textes);
    if ($texte === '' && !$actions) {
        return ['ok' => false, 'repli' => true, 'error' => 'vide'];
    }
    return ['ok' => true, 'texte' => $texte, 'fiche' => $fiche, 'actions' => $actions];
}
