<?php
/**
 * SALW CONSULTING, site Sam : notifications de Meta (prospects des publicités Facebook et Instagram de SALW).
 *
 * À déclarer dans developers.facebook.com › application de SALW › Webhooks, adresse https://<site>/meta-webhook.php,
 * objet « page », champ « leadgen », jeton de vérification = Réglages › Réseaux sociaux. Chaque notification est
 * vérifiée par sa signature (X-Hub-Signature-256, clé secrète de l'application).
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require_once __DIR__ . '/prive/conversations.php';
require_once __DIR__ . '/prive/reseaux-sam.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $jeton = (string)cfg('meta_jeton_verification');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $jeton !== '' && hash_equals($jeton, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain; charset=utf-8');
        echo preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['hub_challenge'] ?? ''));
        exit;
    }
    http_response_code(403);
    exit;
}
$corps = (string)file_get_contents('php://input', false, null, 0, 2000000);
if (!meta_signature_valide($corps, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), (string)cfg('meta_app_secret'))) {
    repondre_json(401, ['ok' => false]);
}
$n = json_decode($corps, true);
foreach ((is_array($n) && ($n['object'] ?? '') === 'page' ? $n['entry'] ?? [] : []) as $entree) {
    foreach ($entree['changes'] ?? [] as $chg) {
        $v = $chg['value'] ?? [];
        if (($chg['field'] ?? '') === 'leadgen' && !empty($v['leadgen_id']) && (string)($v['page_id'] ?? '') === (string)cfg('fb_page_id')) {
            try {
                sam_prospect((string)$v['leadgen_id']);
            } catch (RuntimeException $e) {
                envoyer_mail((string)cfg('destinataire'), 'Prospect de publicité non récupéré', "Un prospect est arrivé, mais sa fiche n'a pas pu être lue chez Meta : " . $e->getMessage() . "\nRetrouvez-le dans le Gestionnaire de publicités (prospects du formulaire).\n");
            }
        }
    }
}
repondre_json(200, ['ok' => true]);
