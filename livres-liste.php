<?php
/**
 * SALW CONSULTING : livres publiés, pour la section « Livres » de la page d'accueil (JSON).
 */

declare(strict_types=1);
require __DIR__ . '/prive/lib.php';
require __DIR__ . '/prive/livres.php';

header('Cache-Control: no-store');
repondre_json(200, ['ok' => true, 'livres' => array_map('livre_public', livres(true))]);
