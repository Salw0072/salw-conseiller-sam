<?php
/**
 * SALW CONSULTING, conseiller Sam : politique de confidentialité.
 *
 * Page dynamique : les durées de conservation et la liste des prestataires suivent
 * les réglages réels (un prestataire n'apparaît que s'il est activé).
 * PROJET à relire et valider par Albert avant la mise en ligne (bases légales,
 * prestataires, garanties de transfert hors EEE).
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';

$jours = (int)cfg('conservation_conversations_jours');
$mois = (int)cfg('conservation_rdv_mois');
$prestataires = [['Hostinger', "hébergement du site et des données"], ['Jitsi Meet (8x8)', 'salle de visio du diagnostic, sauf lien de visio propre']];
if ((string)cfg('ia_cle') !== '') {
    $prestataires[] = ['Anthropic', "traitement des messages écrits à Sam par l'agent IA Claude"];
}
if ((string)cfg('vapi_cle_publique') !== '') {
    $prestataires[] = ['Vapi et ses fournisseurs de voix et de transcription', "appel vocal avec Sam (voix, transcription, agent IA)"];
}
if (((array)cfg('twilio'))['sid'] ?? '') {
    $prestataires[] = ['Twilio', 'rappels par SMS ou WhatsApp, si vous donnez votre numéro'];
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confidentialité · SALW CONSULTING</title>
<meta name="description" content="Comment SALW CONSULTING traite les données recueillies par Sam, son conseiller virtuel, et lors de la réservation d'un diagnostic.">
<style>
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-400-normal.woff2) format("woff2");font-weight:400}
@font-face{font-family:"DM Sans";src:url(fonts/dm-sans-latin-700-normal.woff2) format("woff2");font-weight:700}
@font-face{font-family:"Anton";src:url(fonts/anton-latin-400-normal.woff2) format("woff2")}
body{margin:0;background:#FAF7F9;color:#1E1F2B;font-family:"DM Sans",system-ui,sans-serif;line-height:1.65;font-size:16px}
header{background:#6B2150;color:#fff;padding:18px 24px}
header a{color:#fff;text-decoration:none;font-family:"Anton",Impact,sans-serif;font-size:22px;letter-spacing:.02em}
main{max-width:760px;margin:0 auto;padding:32px 20px 60px}
h1{font-family:"Anton",Impact,sans-serif;font-weight:400;font-size:40px;line-height:1.1;color:#171B34;margin:0 0 8px}
h2{font-size:19px;color:#171B34;margin:32px 0 8px}
a{color:#6B2150}
table{border-collapse:collapse;width:100%;background:#fff;border:1px solid #E6E3EA;border-radius:12px;overflow:hidden;font-size:15px}
td,th{text-align:left;padding:10px 12px;border-top:1px solid #E6E3EA;vertical-align:top}
tr:first-child td,tr:first-child th{border-top:0}
.gris{color:#6A6B78}
</style>
</head>
<body>
<header><a href="./">SALW CONSULTING</a></header>
<main>
<h1>Confidentialité</h1>
<p class="gris">Comment nous traitons les informations que vous confiez à Sam, notre conseiller virtuel, et lors de la réservation d'un diagnostic.</p>

<h2>Qui est responsable</h2>
<p>SALW CONSULTING SPRL, siège à Kinshasa (RD Congo), bureau à Drammen (Norvège). Contact : <a href="mailto:<?= h((string)cfg('destinataire')) ?>"><?= h((string)cfg('destinataire')) ?></a>, +47 948 36 971.</p>

<h2>Ce que nous recueillons</h2>
<ul>
  <li><b>Votre échange avec Sam</b> : vos messages, et ce que vous indiquez de votre activité (métier, ville, taille de l'équipe, problème rencontré). Si vous appelez Sam, la transcription de l'appel.</li>
  <li><b>Votre réservation</b> : nom, adresse e-mail, mobile si vous le donnez pour les rappels, créneau choisi et fuseau horaire.</li>
  <li><b>Des données techniques</b> : une empreinte non réversible de votre adresse IP, pour limiter les abus. Aucun cookie de suivi ni outil de mesure d'audience.</li>
</ul>
<p>Sam ne vous demande jamais de données de santé, de données bancaires ni de mot de passe. N'en communiquez pas dans la conversation.</p>

<h2>Pourquoi</h2>
<p>Pour préparer et tenir le diagnostic que vous demandez, vous envoyer la confirmation et les rappels, assurer le suivi commercial qui en découle, et produire des statistiques internes anonymes sur les besoins exprimés. Ces traitements reposent sur les mesures précontractuelles prises à votre demande et sur notre intérêt légitime à améliorer notre service et à sécuriser le site (article 6 du RGPD).</p>

<h2>Combien de temps</h2>
<table>
  <tr><th>Conversation avec Sam sans rendez-vous</th><td><?= $jours ?> jours après le dernier message, puis suppression automatique</td></tr>
  <tr><th>Demande de créneau par formulaire</th><td><?= $jours ?> jours</td></tr>
  <tr><th>Rendez-vous, fiche et suivi commercial</th><td><?= $mois ?> mois après le rendez-vous</td></tr>
</table>

<h2>Qui y a accès</h2>
<p>Albert Wutshu Shongo et les collaborateurs de SALW CONSULTING habilités, chacun selon son rôle. Nos prestataires techniques, uniquement pour le service décrit :</p>
<ul>
<?php foreach ($prestataires as $p): ?>
  <li><b><?= h($p[0]) ?></b> : <?= h($p[1]) ?>.</li>
<?php endforeach; ?>
</ul>
<p>Certains prestataires peuvent traiter des données hors de l'Espace économique européen ; ces transferts sont encadrés par les garanties qu'ils proposent, notamment les clauses contractuelles types de la Commission européenne.</p>

<h2>Sam est une intelligence artificielle</h2>
<p>Sam vous oriente et prépare le diagnostic ; il ne prend aucune décision à votre sujet. Le conseil, le chiffrage et toute proposition viennent d'Albert, pendant le diagnostic.</p>

<h2>Vos droits</h2>
<p>Vous pouvez demander l'accès à vos données, leur rectification, leur effacement, leur portabilité, ou vous opposer à leur traitement, en écrivant à <a href="mailto:<?= h((string)cfg('destinataire')) ?>"><?= h((string)cfg('destinataire')) ?></a>. Vous pouvez aussi saisir l'autorité de protection des données, en Norvège <a href="https://www.datatilsynet.no" rel="noopener">Datatilsynet</a>, ou celle de votre pays de résidence.</p>

<p class="gris"><a href="./">← Retour au site</a></p>
</main>
</body>
</html>
