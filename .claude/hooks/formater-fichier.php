<?php

declare(strict_types=1);

/**
 * Hook PostToolUse (Edit / Write / MultiEdit) : met en page le fichier PHP qui vient d'être écrit,
 * SILENCIEUSEMENT.
 *
 * Les projets du WAMP partagent les mêmes règles de mise en page, ils partagent donc le même hook.
 *
 * Motif : le code produit par l'assistant doit respecter PSR-12 et les règles du projet sans qu'on
 * ait à le demander à chaque fois, et sans qu'un aller-retour « écris / relis / corrige » vienne
 * encombrer la conversation. La mise en page se fait donc à l'écriture, ici, une fois pour toutes.
 *
 * POURQUOI CELA NE TOUCHE PAS AU CODE EXISTANT. php-cs-fixer traite un fichier entier, jamais un
 * diff : la seule façon de garantir qu'il ne remaniera que le code NOUVEAU est que le reste du
 * fichier soit déjà conforme. C'est le cas — le dépôt est à zéro écart sur les deux configurations
 * ({@see ../../.php-cs-fixer.php} et {@see ../../.php-cs-fixer-gabarits.php}) — et c'est ce hook qui
 * l'y maintient : chaque fichier écrit ressort conforme, donc aucun n'accumule d'écart à corriger
 * plus tard au milieu d'une modification sans rapport.
 *
 * LES DEUX CONFIGURATIONS SONT LANCÉES, en `--path-mode=intersection` : c'est alors le `Finder` de
 * chacune qui décide laquelle s'applique au fichier (code ordinaire ou gabarit), et l'autre le
 * laisse intact. Le hook n'a donc AUCUN critère à connaître ni à tenir en phase — un gabarit créé
 * demain sera rangé du bon côté sans que rien ne soit déclaré ici. C'est important : appliquer au
 * gabarit la configuration du code réalignerait sur la colonne 0 les blocs PHP ouverts dans le HTML.
 *
 * SILENCIEUX ET JAMAIS BLOQUANT : aucune sortie, et toujours un code de retour 0. Un échec de
 * php-cs-fixer (absent, dépendances non installées, fichier momentanément illisible) laisse
 * simplement le fichier tel quel — la mise en page est un confort, jamais une condition.
 *
 * Le pendant vérifiable à la main est `composer cs` (et `composer cs:fix` pour tout remettre
 * d'aplomb).
 */

require_once __DIR__ . '/commun.php';

$entree = json_decode((string) file_get_contents('php://stdin'), true);
if (!is_array($entree) || !is_array($entree['tool_input'] ?? null)) {
    exit(0);
}

$fichier = (string) ($entree['tool_input']['file_path'] ?? '');
if ($fichier === '' || !is_file($fichier)) {
    exit(0);
}

// Seuls les fichiers PHP du projet sont concernés : le reste (Markdown, JSON, JavaScript…) n'a pas
// de mise en page outillée ici, et un chemin hors de la racine ne regarde pas ce dépôt.
if (strtolower(pathinfo($fichier, PATHINFO_EXTENSION)) !== 'php') {
    exit(0);
}

$racine = (string) ($entree['cwd'] ?? getcwd() ?: '');
$cible = normaliserChemin($fichier);
$racineNormalisee = normaliserChemin($racine);
if ($racineNormalisee === '' || !str_starts_with($cible, $racineNormalisee . '/')) {
    exit(0);
}

// Les dépendances tierces ne sont pas notre code, et la COPIE DU COMPOSANT « personnes » ne l'est
// pas davantage : elle est empreintée (CHECKSUM.txt), et la remettre en page casserait ses
// empreintes. Les trois sont d'ailleurs exclues des deux finders, mais autant ne pas lancer
// l'outil pour rien.
if (
    str_contains($cible, '/resources/dependencies/')
    || str_contains($cible, '/resources/personnes/')
    || str_contains($cible, '/vendor/')
) {
    exit(0);
}

$outil = $racine . '/vendor/bin/php-cs-fixer';
if (!is_file($outil)) {
    exit(0);
}

foreach (['.php-cs-fixer.php', '.php-cs-fixer-gabarits.php'] as $configuration) {
    $chemin = $racine . '/' . $configuration;
    if (!is_file($chemin)) {
        continue;
    }

    // `--using-cache=no` : le fichier vient d'être modifié, le cache ne peut rien apprendre de lui,
    // et deux écritures rapprochées se disputeraient son verrou.
    $commande = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($outil)
        . ' fix --quiet --using-cache=no --path-mode=intersection'
        . ' --config=' . escapeshellarg($chemin)
        . ' ' . escapeshellarg($fichier);

    // La sortie est jetée des deux côtés : ce hook ne parle pas.
    exec($commande . ' > ' . escapeshellarg(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null') . ' 2>&1');
}

exit(0);
