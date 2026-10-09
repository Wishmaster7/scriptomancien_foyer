<?php

declare(strict_types=1);

/**
 * Mise en page du CODE PHP « ordinaire » — tout sauf les gabarits.
 *
 * Les gabarits (`web/**\/*template*.php`, seuls fichiers qui mêlent PHP et HTML) ont leur propre
 * configuration, {@see .php-cs-fixer-gabarits.php}, avec deux règles en moins : php-cs-fixer
 * réaligne à la colonne 0 tout bloc PHP ouvert au milieu du HTML, et colle le `?>` au dernier
 * mot d'un commentaire. Le partage se fait dans ce sens-là : ce fichier porte les règles,
 * l'autre les reprend et en retire deux.
 *
 * LA COPIE DU COMPOSANT EST EXCLUE (`web/resources/personnes/`) : elle est déposée en bloc par
 * installer-composant.py, et son `CHECKSUM.txt` porte l'empreinte SHA-256 de chaque fichier. La
 * remettre en page, ne serait-ce que d'un espace, casserait ces empreintes — et
 * `tests/web/resources/composantPersonnesTest.php` échouerait, à juste titre. Un correctif de
 * mise en page se fait dans le projet « personnes », puis se réinstalle.
 *
 * Appliquée à l'écriture par le hook `.claude/hooks/formater-fichier.php`, qui lance les deux
 * configurations et laisse leurs finders décider : ils doivent donc être DISJOINTS.
 */

/**
 * Racine du code applicatif, comparée en PRÉFIXE et non par « contient /web/ » : le miroir des
 * tests s'appelle lui aussi `tests/web/`, et un simple `str_contains` y verrait le répertoire de
 * l'application — une classe de test nommée `templateXxxTest.php` échappait alors aux règles
 * ordinaires, qui sont pourtant les siennes.
 */
$racineWeb = str_replace('\\', '/', __DIR__) . '/web/';

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/web',
        __DIR__ . '/tests',
    ])
    // Le finder n'est pas ouvert sur la racine : il y ramasserait « vendor/ ». Rien d'autre que
    // web/ et tests/ n'est du PHP à mettre en page — le script de publication qui vivait ici est en
    // Python, hors de portée de cet outil comme de la couverture, et seuls les tests end-to-end
    // répondent de lui.
    ->name('*.php')
    // PHPMailer embarqué pour le déploiement : code tiers, jamais remis en page.
    ->exclude('resources/dependencies')
    // La copie du composant « personnes » : empreintée, jamais remise en page (cf. l'en-tête).
    ->exclude('resources/personnes')
    ->filter(static function (SplFileInfo $fichier) use ($racineWeb): bool {
        $chemin = str_replace('\\', '/', $fichier->getRealPath() ?: $fichier->getPathname());

        return !str_contains($fichier->getBasename(), 'template')
            || !str_starts_with($chemin, $racineWeb);
    });

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                         => true,
        'declare_strict_types'           => true,
        'single_quote'                   => true,
        'array_syntax'                   => ['syntax' => 'short'],
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'              => true,
        'trailing_comma_in_multiline'    => true,
        'binary_operator_spaces'         => ['default' => 'single_space'],
        'blank_line_before_statement'    => ['statements' => ['return']],
        'no_extra_blank_lines'           => true,
        'no_whitespace_in_blank_line'    => true,
        'single_blank_line_at_eof'       => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
