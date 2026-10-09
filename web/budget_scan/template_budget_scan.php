<?php
declare(strict_types=1);

use Foyer\App\AnalyseurTicket;
use Foyer\App\BudgetScanController;
use Foyer\App\SiteConfig;
use Foyer\App\Utils;

/**
 * « Scanner un reçu » : zone de dépôt, formulaire de relecture, voile de chargement.
 *
 * Le formulaire est CACHÉ tant qu'aucune analyse ne l'a rempli — sauf après un refus, où il est
 * rendu d'emblée sur la saisie postée ($memoire). resources/js/budget-scan.js le pilote ; son
 * raisonnement est dans tests/js/budget-scan.test.js.
 *
 * @var list<array{ID: int, NOM: string}> $foyers  Foyers de la personne connectée
 * @var array<string, mixed>|null         $memoire Saisie d'un enregistrement refusé
 * @var string                            $csrf_token
 */

$valeur = static fn (string $champ): string => Utils::echapper((string) ($memoire[$champ] ?? ''));
$foyerChoisi = (int) ($memoire['foyer_id'] ?? 0);

// UNE SEULE ÉCRITURE DE LA LIGNE D'ARTICLE : le serveur la rend pour une saisie refusée, et le
// script la recopie depuis le <template> (index « __INDEX__ ») pour chaque article proposé.
$ligneArticle = static function (string $index, array $article): string {
    $retenue = ($article['retenu'] ?? '1') === '1';
    $champ = static fn (string $nom): string => 'articles[' . $index . '][' . $nom . ']';

    return '<tr data-role="ligne-article">'
        . '<td class="text-center align-middle"><input type="checkbox" class="form-check-input" data-champ="retenu" name="' . $champ('retenu') . '" value="1"' . ($retenue ? ' checked' : '') . ' aria-label="Retenir cet article"></td>'
        . '<td><input type="text" class="form-control form-control-sm" data-champ="nom" name="' . $champ('nom') . '" maxlength="' . AnalyseurTicket::NOM_ARTICLE_MAX . '" value="' . Utils::echapper((string) ($article['nom'] ?? '')) . '" aria-label="Article"></td>'
        . '<td><input type="text" class="form-control form-control-sm text-end montant" data-champ="montant" name="' . $champ('montant') . '" inputmode="decimal" value="' . Utils::echapper((string) ($article['montant'] ?? '')) . '" aria-label="Montant"></td>'
        . '<td><input type="text" class="form-control form-control-sm text-uppercase" data-champ="monnaie" name="' . $champ('monnaie') . '" maxlength="3" value="' . Utils::echapper((string) ($article['monnaie'] ?? '')) . '" aria-label="Monnaie"></td>'
        . '<td class="text-end align-middle"><button type="button" class="btn btn-sm btn-outline-danger" data-action="retirer-article" aria-label="Retirer cette ligne"><i class="fas fa-xmark" aria-hidden="true"></i></button></td>'
        . '</tr>';
};
?>
<div class="container mt-4" id="page-budget-scan"
     data-url-analyse="<?php echo Utils::echapper(BudgetScanController::URL_ANALYSE); ?>"
     data-tesseract-worker="<?php echo BudgetScanController::TESSERACT; ?>/worker.min.js"
     data-tesseract-core="<?php echo BudgetScanController::TESSERACT; ?>/core"
     data-tesseract-langues="<?php echo BudgetScanController::TESSERACT; ?>/lang/4.1.0_best"
     data-monnaie-defaut="<?php echo SiteConfig::MONNAIE_DEFAUT; ?>">
    <h2><i class="fas fa-barcode-read" aria-hidden="true"></i> Scanner un reçu</h2>
    <hr>

    <?php if ($foyers === []) { ?>
    <div class="alert alert-info" data-role="sans-foyer">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        Vous n'êtes membre d'aucun foyer : un administrateur doit d'abord vous y rattacher.
    </div>
    <?php } else { ?>
    <?php // LA LANGUE DE LECTURE : son modèle n'est téléchargé qu'au premier reçu lu dans cette langue, puis gardé par le navigateur.?>
    <div class="langues-recu" role="group" aria-label="Langue du reçu" data-role="langues">
        <?php foreach (BudgetScanController::LANGUES as $code => [$libelle, $image]) { ?>
        <button type="button" class="drapeau-langue" data-langue="<?php echo $code; ?>" aria-pressed="<?php echo $code === array_key_first(BudgetScanController::LANGUES) ? 'true' : 'false'; ?>" title="<?php echo $libelle; ?>">
            <img src="/resources/images/<?php echo $image; ?>" alt="<?php echo $libelle; ?>" width="52" height="35">
        </button>
        <?php } ?>
    </div>

    <div class="zone-depot" data-role="zone-depot">
        <p class="mb-2"><i class="fas fa-file-image fa-2x" aria-hidden="true"></i></p>
        <p>Glissez-déposez ici la photo d'un reçu (JPEG, PNG ou WebP)</p>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <label class="btn btn-primary mb-0">
                <i class="fas fa-camera" aria-hidden="true"></i> Prendre une photo
                <input type="file" class="visually-hidden" data-role="fichier-photo" accept="image/jpeg,image/png,image/webp" capture="environment">
            </label>
            <label class="btn btn-primary-light mb-0">
                <i class="fas fa-folder-open" aria-hidden="true"></i> Choisir une image
                <input type="file" class="visually-hidden" data-role="fichier-image" accept="image/jpeg,image/png,image/webp">
            </label>
        </div>
    </div>

    <div class="alert alert-danger mt-3" data-role="erreur-scan" role="alert" hidden></div>

    <form method="POST" action="<?php echo Utils::echapper(BudgetScanController::URL); ?>" class="bloc-formulaire mt-4" data-role="formulaire-depense"<?php echo $memoire === null ? ' hidden' : ''; ?>>
        <input type="hidden" name="csrf_token" value="<?php echo Utils::echapper($csrf_token); ?>">

        <div class="row g-3">
            <?php if (count($foyers) === 1) { ?>
            <input type="hidden" name="foyer_id" value="<?php echo $foyers[0]['ID']; ?>">
            <?php } else { ?>
            <div class="col-12 col-md-6">
                <label for="foyer-depense" class="form-label">Foyer</label>
                <select class="form-select" id="foyer-depense" name="foyer_id" required>
                    <?php foreach ($foyers as $foyer) { ?>
                    <option value="<?php echo $foyer['ID']; ?>"<?php echo $foyer['ID'] === $foyerChoisi ? ' selected' : ''; ?>><?php echo Utils::echapper($foyer['NOM']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <div class="col-12 col-md-6">
                <label for="date-document" class="form-label">Date du document</label>
                <input type="date" class="form-control" id="date-document" name="date_document" required value="<?php echo $valeur('date_document'); ?>">
            </div>
            <div class="col-12 col-md-6">
                <label for="vendeur" class="form-label">Vendeur</label>
                <input type="text" class="form-control" id="vendeur" name="vendeur" maxlength="<?php echo AnalyseurTicket::VENDEUR_MAX; ?>" value="<?php echo $valeur('vendeur'); ?>">
            </div>
            <div class="col-12 col-md-6">
                <label for="lieu" class="form-label">Lieu</label>
                <input type="text" class="form-control" id="lieu" name="lieu" maxlength="<?php echo AnalyseurTicket::LIEU_MAX; ?>" value="<?php echo $valeur('lieu'); ?>">
            </div>
            <div class="col-12 col-md-6">
                <label for="numero-tva" class="form-label">Numéro de TVA</label>
                <input type="text" class="form-control" id="numero-tva" name="numero_tva" maxlength="<?php echo AnalyseurTicket::NUMERO_TVA_MAX; ?>" value="<?php echo $valeur('numero_tva'); ?>">
            </div>
            <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <input type="text" class="form-control" id="description" name="description" maxlength="<?php echo AnalyseurTicket::DESCRIPTION_MAX; ?>" value="<?php echo $valeur('description'); ?>">
            </div>
        </div>

        <h3 class="h5 mt-4">Articles</h3>
        <p class="mention-discrete">Seules les lignes cochées sont enregistrées.</p>
        <div class="alert alert-warning" data-role="alerte-total" role="status" hidden></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th scope="col" class="text-center">Retenir</th>
                        <th scope="col">Article</th>
                        <th scope="col" class="text-end">Montant</th>
                        <th scope="col">Monnaie</th>
                        <th scope="col"><span class="visually-hidden">Retirer</span></th>
                    </tr>
                </thead>
                <tbody data-role="lignes-articles">
                    <?php foreach (($memoire['articles'] ?? []) as $rang => $article) { ?>
                    <?php echo $ligneArticle((string) $rang, $article); ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-between">
            <button type="button" class="btn btn-outline-secondary" data-action="ajouter-article">
                <i class="fas fa-plus" aria-hidden="true"></i> Ajouter un article
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i> Enregistrer la dépense
            </button>
        </div>
    </form>

    <template data-role="modele-article"><?php echo $ligneArticle('__INDEX__', ['nom' => '', 'montant' => '', 'monnaie' => '']); ?></template>

    <?php // LE VOILE BLOQUE TOUTE LA PAGE pendant la lecture puis l'analyse : rien ne doit être cliqué sous lui.?>
    <div class="voile-chargement" data-role="voile-chargement" role="status" aria-live="polite" hidden>
        <i class="fas fa-spinner fa-spin fa-3x" aria-hidden="true"></i>
        <p class="mb-0 fs-5">Chargement en cours...</p>
        <p class="mb-0" data-role="etape-chargement"></p>
    </div>

    <script src="<?php echo BudgetScanController::TESSERACT; ?>/tesseract.min.js"></script>
    <script src="/resources/js/budget-scan.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/budget-scan.js') ?: '0')); ?>"></script>
    <?php } ?>
</div>
