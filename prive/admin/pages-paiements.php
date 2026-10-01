<?php
/**
 * SALW CONSULTING, espace admin : demandes de paiement (acomptes, factures des offres B, C et D).
 * Le client reçoit un lien vers payer.php et paie par Stripe (carte, SEPA) ou PayPal.
 */

declare(strict_types=1);

function page_paiements(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && peut('modifier')) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'creer') {
            [$p, $erreur] = creer_demande_paiement($_POST, (string)moi()['identifiant']);
            if ($p) {
                journaliser('paiement_demande', $p['client_email'], $p['libelle'] . ' · ' . montant_lisible((float)$p['montant']));
                if (!empty($_POST['envoyer'])) {
                    envoyer_demande($p);
                }
                flash('ok', 'Demande créée' . (!empty($_POST['envoyer']) ? ' et envoyée à ' . $p['client_email'] : '') . '. Lien de paiement : ' . lien_paiement($p));
            } else {
                flash('erreur', $erreur);
            }
        } else {
            $p = null;
            foreach (lire('paiements') as $x) {
                if ($x['id'] === (string)($_POST['id'] ?? '')) {
                    $p = $x;
                }
            }
            if ($p && $action === 'envoyer' && in_array($p['statut'], ['en_attente', 'echec'], true)) {
                envoyer_demande($p);
                journaliser('paiement_relance', $p['client_email'], $p['libelle']);
                flash('ok', 'Lien envoyé à ' . $p['client_email'] . '.');
            } elseif ($p && $action === 'annuler' && in_array($p['statut'], ['en_attente', 'echec'], true)) {
                modifier_demande($p['id'], ['statut' => 'annule']);
                journaliser('paiement_annule', $p['client_email'], $p['libelle']);
                flash('ok', 'Demande annulée : le lien ne permet plus de payer.');
            }
        }
        aller('paiements');
    }

    $liste = lire('paiements');
    usort($liste, function ($a, $b) { return strcmp($b['cree_le'], $a['cree_le']); });
    $mois = gmdate('Y-m');
    $encaisse = 0.0;
    $attente = 0.0;
    foreach ($liste as $p) {
        if ($p['statut'] === 'paye' && strpos((string)$p['paye_le'], $mois) === 0) {
            $encaisse += (float)$p['montant'];
        } elseif (in_array($p['statut'], ['en_attente', 'en_cours', 'echec'], true)) {
            $attente += (float)$p['montant'];
        }
    }

    entete('Paiements', 'paiements');
    titre_page('Paiements', 'Acomptes et factures des offres B, C et D, réglés en ligne par Stripe (carte, prélèvement SEPA) ou PayPal. Le client reçoit un lien et paie sur une page sécurisée du site.');
    if (!stripe_actif() && !paypal_actif()) {
        echo '<div class="msg info">Paiement en ligne non configuré : renseignez les clés Stripe et, en option, PayPal dans '
            . (peut('reglages') ? '<a href="' . h(url_admin('reglages', ['s' => 'paiements'])) . '">Réglages › Paiements</a>' : 'Réglages › Paiements') . '. Les demandes peuvent déjà être créées.</div>';
    }
    echo '<div class="tuiles">' . tuile('Encaissé ce mois-ci', montant_lisible($encaisse)) . tuile('En attente de paiement', montant_lisible($attente)) . '</div>';

    if (peut('modifier')) {
        $offres = '';
        foreach (['B' => 'B · Intégration IA sur mesure', 'C' => 'C · Formation IA', 'D' => 'D · Sites & applications', 'A' => 'A · Plateforme par métier', '' => 'Autre'] as $k => $l) {
            $offres .= '<option value="' . h($k) . '">' . h($l) . '</option>';
        }
        echo '<section class="carte"><h2>Nouvelle demande de paiement</h2><form method="post" class="form">' . champ_csrf() . '<input type="hidden" name="action" value="creer">'
            . '<div class="champ-duo"><label>Nom du client<input name="client_nom" maxlength="80" placeholder="Ex. Clinique du Parc, Mme Diallo"></label>'
            . '<label>E-mail du client<input name="client_email" type="email" required></label></div>'
            . '<div class="champ-duo"><label>Objet<input name="libelle" required maxlength="120" placeholder="Ex. Acompte 30 % · pilote IA service client"></label>'
            . '<label>Montant (€)<input name="montant" required inputmode="decimal" placeholder="Ex. 4 500"></label></div>'
            . '<div class="champ-duo"><label>Offre<select name="offre">' . $offres . '</select></label>'
            . '<label>Note interne (facultatif)<input name="note" maxlength="500"></label></div>'
            . '<label class="case"><input type="checkbox" name="envoyer" value="1" checked> Envoyer le lien de paiement par e-mail au client</label>'
            . '<button class="btn">Créer la demande</button></form>'
            . '<p class="petit">Le montant est payé tel quel : indiquez le montant TTC si la TVA s\'applique à ce client. Stripe et PayPal envoient eux-mêmes le reçu.</p></section>';
    }

    if (!$liste) {
        echo '<div class="vide">Aucune demande de paiement pour l\'instant.</div>';
        pied();
        return;
    }
    echo '<div class="table"><table><thead><tr><th>Créée le</th><th>Client</th><th>Objet</th><th class="num">Montant</th><th>Statut</th><th>Lien</th><th></th></tr></thead><tbody>';
    $tons = ['paye' => 'vert', 'en_cours' => 'bleu', 'echec' => 'rouge', 'annule' => 'gris', 'en_attente' => 'orange'];
    foreach ($liste as $p) {
        $actions = '';
        if (peut('modifier') && in_array($p['statut'], ['en_attente', 'echec'], true)) {
            $actions = '<form method="post" class="inline">' . champ_csrf() . '<input type="hidden" name="action" value="envoyer"><input type="hidden" name="id" value="' . h($p['id']) . '"><button class="lien">Renvoyer</button></form> '
                . '<form method="post" class="inline" data-confirmer="Annuler cette demande ? Le lien ne permettra plus de payer.">' . champ_csrf() . '<input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="' . h($p['id']) . '"><button class="lien danger">Annuler</button></form>';
        }
        echo '<tr><td>' . h(date_fr($p['cree_le'], 'd/m/Y')) . '</td><td><b>' . h($p['client_nom'] ?: '–') . '</b><br><span class="gris">' . h($p['client_email']) . '</span></td>'
            . '<td>' . h($p['libelle']) . ($p['offre'] !== '' ? ' <span class="gris">· offre ' . h($p['offre']) . '</span>' : '') . '</td><td class="num">' . h(montant_lisible((float)$p['montant'])) . '</td>'
            . '<td>' . badge(STATUTS_DEMANDE[$p['statut']] ?? $p['statut'], $tons[$p['statut']] ?? 'gris') . ($p['statut'] === 'paye' ? '<br><span class="gris">' . h(ucfirst($p['fournisseur'])) . ', le ' . h(date_fr($p['paye_le'], 'd/m/Y')) . '</span>' : '') . '</td>'
            . '<td>' . (in_array($p['statut'], ['en_attente', 'echec'], true) ? '<input class="lien-copie" readonly value="' . h(lien_paiement($p)) . '" aria-label="Lien de paiement" onclick="this.select()">' : '') . '</td>'
            . '<td>' . $actions . '</td></tr>';
    }
    echo '</tbody></table></div>';
    pied();
}

function envoyer_demande(array $p): void
{
    $prenom = $p['client_nom'] !== '' ? ' ' . $p['client_nom'] : '';
    envoyer_mail($p['client_email'], 'Demande de paiement · ' . $p['libelle'],
        "Bonjour{$prenom},\n\nComme convenu, voici le lien pour régler « {$p['libelle']} » (" . montant_lisible((float)$p['montant']) . ") :\n\n" . lien_paiement($p)
        . "\n\nVous pouvez payer par carte, par prélèvement SEPA ou avec PayPal, sur une page sécurisée. Un reçu vous sera envoyé.\n\nPour toute question, répondez simplement à ce message.\n\n"
        . (string)cfg('nom_conseiller') . "\nSALW CONSULTING\n", (string)cfg('destinataire'));
}
