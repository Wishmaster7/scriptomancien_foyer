<?php

declare(strict_types=1);

/**
 * Mise en page des GABARITS (`web/**\/*template*.php`) — les seuls fichiers du projet qui mêlent
 * PHP et HTML.
 *
 * Ils reçoivent les MÊMES règles que le reste du code ({@see .php-cs-fixer.php}, dont ce fichier
 * reprend la liste pour ne pas la recopier), MOINS deux que php-cs-fixer applique mal dès qu'un
 * bloc PHP s'ouvre au milieu du HTML :
 *
 *   - `statement_indentation` : les lignes d'un bloc `<?php … ?>` ouvert à l'intérieur d'une
 *     balise sont réalignées sur la COLONNE 0 au lieu d'être indentées sous l'ouverture du bloc ;
 *   - `no_trailing_whitespace_in_comment` : l'espace qui sépare la fin d'un commentaire du `?>`
 *     qui le referme est supprimé, ce qui donne « … de ligne.?> ».
 *
 * L'indentation de ces fichiers est donc DÉLIBÉRÉE. Ne la « corrigez » pas, et n'ajoutez pas ces
 * deux règles ici. Un nouveau gabarit est couvert d'office : c'est son emplacement (`web/`) et
 * son nom (qui contient `template`, en préfixe comme en suffixe) qui le rangent ici.
 */

/**
 * L'inclusion vient EN PREMIER, et l'ordre n'est pas indifférent : `require` s'exécute dans la
 * portée de l'appelant, si bien que le fichier inclus ÉCRASE les variables de même nom — il y
 * définit son propre `$finder`. Construire le nôtre avant l'inclusion revenait donc à le perdre
 * en silence : les deux configurations parcouraient alors la même liste de fichiers.
 *
 * @var PhpCsFixer\Config $code Configuration du code ordinaire, dont on reprend les règles.
 */
$code = require __DIR__ . '/.php-cs-fixer.php';
$regles = $code->getRules();

/**
 * Le tri se fait par `filter()` et NON par `name('*template*.php')` : le Finder de php-cs-fixer
 * pose déjà `name('*.php')` dans son constructeur, et les motifs de `name()` s'AJOUTENT en OU —
 * le nôtre ne restreignait donc rien, et cette configuration parcourait tout web/ en croyant ne
 * voir que les gabarits. `filter()`, lui, retire vraiment.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/web'])
    ->exclude('resources/dependencies')
    // La copie du composant : ses gabarits sont empreintés comme le reste (cf. .php-cs-fixer.php).
    ->exclude('resources/personnes')
    ->filter(static fn (SplFileInfo $fichier): bool => str_contains($fichier->getBasename(), 'template'));

return (new PhpCsFixer\Config())
    ->setRules($regles + [
        'statement_indentation' => false,
        'no_trailing_whitespace_in_comment' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer-gabarits.cache');
