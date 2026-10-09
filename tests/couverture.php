<?php

declare(strict_types=1);

/**
 * GARDE-FOU DE COUVERTURE : refuse une couverture de lignes inférieure au seuil.
 *
 *     php tests/couverture.php [coverage/clover.xml] [seuil]
 *
 * Il existe parce que « la couverture est de 100 % » n'est une garantie que si quelque chose le
 * VÉRIFIE : le rapport Clover était produit et personne ne le lisait, si bien qu'une ligne non
 * couverte est passée inaperçue. Ce composant est recopié dans toutes les applications de la
 * plateforme et n'y est jamais retesté — c'est ici, et nulle part ailleurs, qu'il est couvert.
 *
 * Volontairement minuscule : il compte les lignes du rapport et compare. Le détail par fichier se
 * lit dans coverage/html/index.html, qui est fait pour ça.
 */

$fichier = $argv[1] ?? __DIR__ . '/../coverage/clover.xml';
$seuil = (float) ($argv[2] ?? 100);

if (!is_readable($fichier)) {
    fwrite(STDERR, "Rapport de couverture introuvable : $fichier" . PHP_EOL);

    exit(1);
}

$xml = simplexml_load_file($fichier);
if ($xml === false) {
    fwrite(STDERR, "Rapport de couverture illisible : $fichier" . PHP_EOL);

    exit(1);
}

// Les <metrics> du projet portent le total ; les lignes NON couvertes se retrouvent en parcourant
// les <line count="0">, ce qui permet de les NOMMER — un pourcentage seul n'aide personne à
// corriger.
$total = (int) $xml->project->metrics['statements'];
$couvertes = (int) $xml->project->metrics['coveredstatements'];
$pourcentage = $total === 0 ? 100.0 : round($couvertes / $total * 100, 2);

$manquantes = [];
foreach ($xml->xpath('//file') ?: [] as $fichierXml) {
    foreach ($fichierXml->line as $ligne) {
        if ((int) $ligne['count'] === 0) {
            $manquantes[] = basename((string) $fichierXml['name']) . ':' . (string) $ligne['num'];
        }
    }
}

echo "Couverture : $pourcentage % ($couvertes/$total lignes)", PHP_EOL;

if ($pourcentage + 0.0001 >= $seuil) {
    exit(0);
}

fwrite(STDERR, 'Sous le seuil de ' . $seuil . ' %. Lignes non couvertes :' . PHP_EOL);
foreach (array_slice($manquantes, 0, 40) as $ligne) {
    fwrite(STDERR, '  ' . $ligne . PHP_EOL);
}
if (count($manquantes) > 40) {
    fwrite(STDERR, '  … et ' . (count($manquantes) - 40) . ' autres.' . PHP_EOL);
}

exit(1);
