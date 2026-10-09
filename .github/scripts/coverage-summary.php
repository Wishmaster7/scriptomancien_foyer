<?php

/**
 * Parse un rapport Clover XML et génère un résumé Markdown pour GitHub Actions.
 * Usage : php .github/scripts/coverage-summary.php [chemin/vers/clover.xml] [--min=100]
 *
 * `--min=<pourcentage>` transforme le résumé en GARDE-FOU : le script sort en code d'erreur si la
 * couverture globale passe sous le seuil, ce qui fait échouer le job. C'est le seul contrôle
 * automatique qui casse le build côté PHP — la mise en page, elle, ne le fait jamais.
 *
 * CE QUE LE SEUIL COUVRE RÉELLEMENT, ET CE QU'IL NE COUVRE PAS. La couverture est produite par
 * PCOV, qui ne mesure QUE les lignes exécutées : le rapport ne contient ni couverture de branches
 * ni couverture de chemins (elles demanderaient Xdebug en mode `pathCoverage`, bien plus lent). Le
 * garde-fou contrôle donc les axes réellement présents dans le Clover — les LIGNES et les MÉTHODES,
 * plus les CONDITIONS si un jour un pilote les renseigne. Les quatre axes sont vérifiés
 * intégralement côté JavaScript (Istanbul, seuils de Vitest) et sur trois axes côté PHP.
 */

/**
 * Racine du code applicatif, retirée de tête des chemins pour que le MODULE soit le répertoire
 * métier (cf. le découpage plus bas). Même valeur que le <include> de phpunit.xml.
 */
const RACINE_APPLICATIVE = 'web';

$cloverFile = 'coverage/clover.xml';
$seuil      = null;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--min=(\d+(?:\.\d+)?)$/', $argument, $m) === 1) {
        $seuil = (float) $m[1];
        continue;
    }
    $cloverFile = $argument;
}

if (!file_exists($cloverFile)) {
    fwrite(STDERR, "Fichier introuvable : $cloverFile\n");
    exit(1);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false) {
    fwrite(STDERR, "Impossible de parser : $cloverFile\n");
    exit(1);
}

// Structure : module -> [ files[], statements, covered ]
$data           = [];
$totalStatements = 0;
$totalCovered   = 0;

// Totaux par AXE, pour le garde-fou de --min. Les clés sont celles du Clover ; « statements » y
// désigne les lignes exécutables. Un axe resté à zéro n'est pas mesuré par le pilote de couverture
// (c'est le cas des conditions avec PCOV) : il ne sera pas contrôlé plutôt que d'être compté à 100 %.
$axes = [
    'lignes'     => ['mesure' => 'statements',   'couvert' => 'coveredstatements',   'total' => 0, 'ok' => 0],
    'méthodes'   => ['mesure' => 'methods',      'couvert' => 'coveredmethods',      'total' => 0, 'ok' => 0],
    'conditions' => ['mesure' => 'conditionals', 'couvert' => 'coveredconditionals', 'total' => 0, 'ok' => 0],
];

/** Fichiers dont un axe n'est pas entièrement couvert, pour nommer les responsables dans l'erreur. */
$incomplets = [];

$basePath = rtrim(str_replace('\\', '/', getcwd()), '/') . '/';

// TOUS les <file> du rapport, à quelque profondeur qu'ils soient. PHPUnit range un fichier sous un
// <package> dès que la classe qu'il contient est DANS UN ESPACE DE NOMS, et le laisse enfant direct
// de <project> sinon : parcourir `$xml->project->file` ne verrait que les gabarits et les pages
// d'entrée, et laisserait dans l'ombre tout le code sous espace de noms (Foyer\App). Un XPath
// « // » les prend tous, sans doublon (un fichier
// n'a qu'un emplacement), et sans rien supposer de la structure du rapport.
$fichiers = $xml->xpath('//file') ?: [];

foreach ($fichiers as $fileEl) {
    $path = str_replace('\\', '/', (string) $fileEl['name']);

    // Chemin relatif
    if (strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
    }

    $metrics = $fileEl->metrics ?? null;
    if ($metrics === null) {
        continue;
    }

    $statements = (int) $metrics['statements'];
    $covered    = (int) $metrics['coveredstatements'];

    foreach ($axes as $nom => $axe) {
        $mesure  = (int) $metrics[$axe['mesure']];
        $atteint = (int) $metrics[$axe['couvert']];
        $axes[$nom]['total'] += $mesure;
        $axes[$nom]['ok']    += $atteint;
        if ($mesure > 0 && $atteint < $mesure) {
            $incomplets[$path][$nom] = "$atteint/$mesure";
        }
    }

    // Décomposer le chemin : module / sous-répertoires / fichier.
    //
    // Le module est le répertoire MÉTIER, c'est-à-dire le premier niveau SOUS la racine du code
    // applicatif (`web/`) : « accueil », « profil », « resources »… Sans retirer ce segment de
    // tête, tous les chemins commençant par « web/ », le tableau n'aurait qu'UNE ligne. Les fichiers
    // posés à la racine de `web/` (index.php, les pages d'erreur) se regroupent sous « (racine) ».
    $parts = explode('/', $path);
    if (($parts[0] ?? '') === RACINE_APPLICATIVE && count($parts) > 1) {
        array_shift($parts);
    }
    $filename = array_pop($parts);

    if (count($parts) === 0) {
        $module = '(racine)';
        $subdir = '';
    } else {
        $module = $parts[0];
        $subdir = implode('/', array_slice($parts, 1));
    }

    if (!isset($data[$module])) {
        $data[$module] = ['files' => [], 'statements' => 0, 'covered' => 0];
    }

    $data[$module]['files'][] = [
        'subdir'     => $subdir,
        'name'       => $filename,
        'statements' => $statements,
        'covered'    => $covered,
    ];
    $data[$module]['statements'] += $statements;
    $data[$module]['covered']    += $covered;

    $totalStatements += $statements;
    $totalCovered    += $covered;
}

ksort($data);

// ---------------------------------------------------------------------------
// Fonctions utilitaires
// ---------------------------------------------------------------------------

function formatPct(int $covered, int $total): string
{
    if ($total === 0) {
        return '—';
    }
    return number_format($covered / $total * 100, 2) . '%';
}

function badge(int $covered, int $total): string
{
    if ($total === 0) {
        return '🟢';
    }
    // Mêmes seuils de couleur que le rapport HTML PHPUnit (cf. phpunit.xml, <html> :
    // lowUpperBound=80, highLowerBound=100) : vert à 100 % pile, jaune au-dessus de 80 %,
    // rouge à 80 % ou moins.
    $p = $covered / $total * 100;
    if ($p >= 100) {
        return '🟢';
    }
    if ($p > 80) {
        return '🟡';
    }
    return '🔴';
}

// ---------------------------------------------------------------------------
// Sortie Markdown
// ---------------------------------------------------------------------------

echo '**Total : ' . formatPct($totalCovered, $totalStatements)
    . ' (' . $totalCovered . '/' . $totalStatements . " lignes)**\n\n";

// Tableau récapitulatif par module
echo "| Module | Lignes couvertes | Couverture |\n";
echo "|:-------|----------------:|:----------:|\n";
foreach ($data as $module => $info) {
    $b    = badge($info['covered'], $info['statements']);
    $pStr = formatPct($info['covered'], $info['statements']);
    echo "| `$module/` | {$info['covered']}/{$info['statements']} | $b $pStr |\n";
}
echo "\n";

// Détail par module dans des blocs repliables
foreach ($data as $module => $info) {
    $b    = badge($info['covered'], $info['statements']);
    $pStr = formatPct($info['covered'], $info['statements']);

    echo "<details>\n";
    echo "<summary>📁 <code>$module/</code> — $b $pStr</summary>\n\n";
    echo "| Fichier | Lignes | Couverture |\n";
    echo "|:--------|-------:|:----------:|\n";

    // Trier par sous-répertoire puis par nom de fichier
    usort($info['files'], function ($a, $b) {
        $cmp = strcmp($a['subdir'], $b['subdir']);
        return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
    });

    $lastSubdir = null;
    foreach ($info['files'] as $file) {
        // Ligne d'en-tête de sous-répertoire (une seule fois par sous-répertoire)
        if ($file['subdir'] !== '' && $file['subdir'] !== $lastSubdir) {
            echo "| **`{$file['subdir']}/`** | | |\n";
            $lastSubdir = $file['subdir'];
        }
        $indent = $file['subdir'] !== '' ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
        $fb     = badge($file['covered'], $file['statements']);
        $fpStr  = formatPct($file['covered'], $file['statements']);
        echo "| {$indent}`{$file['name']}` | {$file['covered']}/{$file['statements']} | $fb $fpStr |\n";
    }

    echo "\n</details>\n\n";
}

// ---------------------------------------------------------------------------
// Garde-fou : --min fait ÉCHOUER le job sous le seuil
// ---------------------------------------------------------------------------

if ($seuil === null) {
    exit(0);
}

$manquants = [];
foreach ($axes as $nom => $axe) {
    // Un axe non mesuré par le pilote (total à zéro) n'est pas un axe à 100 % : on ne le contrôle
    // pas, et le résumé ci-dessous dit lesquels ont réellement été vérifiés.
    if ($axe['total'] === 0) {
        continue;
    }
    $pourcentage = $axe['ok'] / $axe['total'] * 100;
    if ($pourcentage < $seuil) {
        $manquants[] = sprintf('%s : %.2f %% (%d/%d)', $nom, $pourcentage, $axe['ok'], $axe['total']);
    }
}

$controles = array_keys(array_filter($axes, static fn (array $a): bool => $a['total'] > 0));
echo '> Seuil exigé : ' . $seuil . ' % sur les axes mesurés (' . implode(', ', $controles) . ").\n\n";

if ($manquants === []) {
    exit(0);
}

echo "> ⛔ **Couverture insuffisante** : " . implode(' — ', $manquants) . "\n\n";
fwrite(STDERR, "Couverture sous le seuil de $seuil % : " . implode(' — ', $manquants) . "\n");

// Nommer les fichiers fautifs : sans eux, l'échec du job n'apprend rien de ce qu'il faut tester.
$compte = 0;
foreach ($incomplets as $fichier => $details) {
    $parties = [];
    foreach ($details as $nom => $valeur) {
        $parties[] = "$nom $valeur";
    }
    fwrite(STDERR, '  - ' . $fichier . ' (' . implode(', ', $parties) . ")\n");
    if (++$compte >= 30) {
        fwrite(STDERR, '  … et ' . (count($incomplets) - $compte) . " autre(s) fichier(s).\n");
        break;
    }
}

exit(1);
