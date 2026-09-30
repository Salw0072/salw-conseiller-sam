<?php
/**
 * SALW CONSULTING, espace admin : graphiques SVG rendus côté serveur (aucune bibliothèque).
 *
 * Couleurs validées pour la dataviz (scripts de validation : bande de luminosité,
 * saturation, séparation daltonisme ΔE 13,2, contraste) : prune #8E2F6B et vert #2A9163,
 * nuances de la charte (#6B2150 et #2F6B50 étaient trop sombre et trop grise pour des marques).
 * Barres fines à bout arrondi (4 px), base carrée, grille en filet, textes en couleurs
 * de texte, info-bulle native sur chaque barre, et tableau des données pour chaque graphique.
 */

declare(strict_types=1);

const DV_PRUNE = '#8E2F6B';
const DV_VERT = '#2A9163';
const DV_PISTE = '#DDEFE5';
const DV_GRILLE = '#E6E3EA';
const DV_TEXTE = '#1E1F2B';
const DV_TEXTE_2 = '#6A6B78';

/** Chemin d'une colonne : bout arrondi en haut, base carrée. */
function dv_colonne(float $x, float $y, float $l, float $h): string
{
    if ($h <= 0) {
        return '';
    }
    $r = min(4, $h, $l / 2);
    $b = $y + $h;
    return sprintf('M%.1f %.1fV%.1fA%.1f %.1f 0 0 1 %.1f %.1fH%.1fA%.1f %.1f 0 0 1 %.1f %.1fV%.1fZ',
        $x, $b, $y + $r, $r, $r, $x + $r, $y, $x + $l - $r, $r, $r, $x + $l, $y + $r, $b);
}

/** Barre horizontale : base carrée à gauche, bout arrondi à droite. */
function dv_barre(float $x, float $y, float $l, float $h): string
{
    if ($l <= 0) {
        return '';
    }
    $r = min(4, $l, $h / 2);
    return sprintf('M%.1f %.1fH%.1fA%.1f %.1f 0 0 1 %.1f %.1fV%.1fA%.1f %.1f 0 0 1 %.1f %.1fH%.1fZ',
        $x, $y, $x + $l - $r, $r, $r, $x + $l, $y + $r, $y + $h - $r, $r, $r, $x + $l - $r, $y + $h, $x);
}

function dv_graduations(int $max): array
{
    if ($max <= 4) {
        return range(0, max(1, $max));
    }
    $pas = $max <= 10 ? 2 : ($max <= 25 ? 5 : ($max <= 50 ? 10 : (int)pow(10, floor(log10($max)))));
    return range(0, (int)(ceil($max / $pas) * $pas), $pas);
}

/** Colonnes par jour (30 jours). Une seule série : le titre la nomme, pas de légende. */
function dv_colonnes_jours(array $serie, string $couleur, string $titre, string $unite): string
{
    $L = 720; $H = 190; $g = 34; $d = 8; $haut = 22; $bas = 24;
    $hPlot = $H - $haut - $bas;
    $n = max(1, count($serie));
    $bande = ($L - $g - $d) / $n;
    $larg = min(18, $bande - 3);
    $max = max(1, max($serie));
    $grad = dv_graduations((int)$max);
    $echelle = end($grad) ?: 1;
    $total = array_sum($serie);
    $svg = '<svg class="dv" viewBox="0 0 ' . $L . ' ' . $H . '" role="img" aria-label="' . h($titre . ' : ' . $total . ' sur 30 jours') . '">';
    foreach ($grad as $t) {
        $y = $haut + $hPlot - $t / $echelle * $hPlot;
        $svg .= '<line x1="' . $g . '" x2="' . ($L - $d) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="' . DV_GRILLE . '" stroke-width="1"/>';
        $svg .= '<text x="' . ($g - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="dv-axe">' . $t . '</text>';
    }
    $i = 0;
    $kMax = array_search($max, $serie, true);
    foreach ($serie as $jour => $v) {
        $x = $g + $i * $bande + ($bande - $larg) / 2;
        $h = $v / $echelle * $hPlot;
        $y = $haut + $hPlot - $h;
        $date = (new DateTimeImmutable($jour))->format('d/m');
        $svg .= '<g class="dv-marque" tabindex="0"><title>' . h($date . ' : ' . $v . ' ' . $unite) . '</title>'
            . '<rect x="' . round($g + $i * $bande, 1) . '" y="' . $haut . '" width="' . round($bande, 1) . '" height="' . $hPlot . '" fill="transparent"/>'
            . ($v > 0 ? '<path d="' . dv_colonne($x, $y, $larg, $h) . '" fill="' . $couleur . '"/>' : '') . '</g>';
        // Étiquette directe sur la seule valeur maximale.
        if ($jour === $kMax && $v > 0) {
            $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($y - 6, 1) . '" text-anchor="middle" class="dv-val">' . $v . '</text>';
        }
        if ($i % 7 === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($H - 6) . '" text-anchor="middle" class="dv-axe">' . $date . '</text>';
        }
        $i++;
    }
    $svg .= '</svg>';
    return $svg . dv_tableau(array_map(function ($k, $v) { return [(new DateTimeImmutable($k))->format('d/m/Y'), $v]; }, array_keys($serie), $serie), ['Jour', ucfirst($unite)]);
}

/** Barres horizontales (une série). $items : libellé => valeur, $notes : libellé => complément. */
function dv_barres(array $items, string $couleur, string $unite, array $notes = []): string
{
    if (!$items) {
        return '<p class="vide-mini">Pas encore de données sur la période.</p>';
    }
    $items = array_slice($items, 0, 8, true);
    $L = 520; $lib = 190; $valeurs = 90; $hb = 14; $ecart = 14;
    $H = count($items) * ($hb + $ecart) + 4;
    $max = max(1, max($items));
    $plot = $L - $lib - $valeurs;
    $svg = '<svg class="dv" viewBox="0 0 ' . $L . ' ' . $H . '" role="img" aria-label="' . h('Répartition, en ' . $unite) . '">';
    $i = 0;
    foreach ($items as $libelle => $v) {
        $y = 2 + $i * ($hb + $ecart);
        $l = $v / $max * $plot;
        $complement = isset($notes[$libelle]) ? ' · ' . $notes[$libelle] : '';
        $svg .= '<g class="dv-marque" tabindex="0"><title>' . h($libelle . ' : ' . $v . ' ' . $unite . $complement) . '</title>'
            . '<rect x="0" y="' . ($y - 6) . '" width="' . $L . '" height="' . ($hb + 12) . '" fill="transparent"/>'
            . '<text x="' . ($lib - 10) . '" y="' . ($y + 11) . '" text-anchor="end" class="dv-lib">' . h(mb_strimwidth((string)$libelle, 0, 30, '…')) . '</text>'
            . '<path d="' . dv_barre($lib, $y, max(2, $l), $hb) . '" fill="' . $couleur . '"/>'
            . '<text x="' . round($lib + max(2, $l) + 8, 1) . '" y="' . ($y + 11) . '" class="dv-val">' . $v . h($complement) . '</text></g>';
        $i++;
    }
    $svg .= '</svg>';
    $lignes = [];
    foreach ($items as $k => $v) {
        $lignes[] = [$k, $v . (isset($notes[$k]) ? ' (' . $notes[$k] . ')' : '')];
    }
    return $svg . dv_tableau($lignes, ['Catégorie', ucfirst($unite)]);
}

/** Jauge de progression vers l'objectif : la piste est un ton clair de la même teinte. */
function dv_jauge(float $valeur, float $objectif): string
{
    $part = $objectif > 0 ? min(1, $valeur / $objectif) : 0;
    $pct = $objectif > 0 ? round($valeur / $objectif * 100) : 0;
    return '<div class="jauge" role="meter" aria-valuemin="0" aria-valuemax="' . h((string)$objectif) . '" aria-valuenow="' . h((string)$valeur) . '" aria-label="Chiffre d\'affaires signé par rapport à l\'objectif">'
        . '<div class="jauge-piste" style="background:' . DV_PISTE . '"><div class="jauge-rempli" style="width:' . round($part * 100, 1) . '%;background:' . DV_VERT . '"></div></div>'
        . '<div class="jauge-legende"><span><b>' . euros($valeur) . '</b> signés</span><span>' . $pct . ' % de ' . euros($objectif) . '</span></div></div>';
}

/** Tableau des données d'un graphique (équivalent accessible, replié par défaut). */
function dv_tableau(array $lignes, array $entetes): string
{
    $html = '<details class="dv-donnees"><summary>Voir les données</summary><table><thead><tr>';
    foreach ($entetes as $e) {
        $html .= '<th>' . h($e) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($lignes as $l) {
        $html .= '<tr><td>' . h((string)$l[0]) . '</td><td class="num">' . h((string)$l[1]) . '</td></tr>';
    }
    return $html . '</tbody></table></details>';
}
