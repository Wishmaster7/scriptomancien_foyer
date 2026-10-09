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
 * @var list<array{ID: int, NOM: string, MONNAIE_REF: string}> $foyers  Foyers de la personne connectée
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
        . '<td class="colonne-etroite text-center"><input type="checkbox" class="form-check-input" data-champ="retenu" name="' . $champ('retenu') . '" value="1"' . ($retenue ? ' checked' : '') . ' aria-label="Retenir cet article"></td>'
        . '<td><input type="text" class="form-control form-control-sm" data-champ="nom" name="' . $champ('nom') . '" maxlength="' . AnalyseurTicket::NOM_ARTICLE_MAX . '" value="' . Utils::echapper((string) ($article['nom'] ?? '')) . '" aria-label="Article"></td>'
        . '<td class="colonne-etroite"><input type="text" class="form-control form-control-sm text-end montant champ-montant" data-champ="montant" name="' . $champ('montant') . '" inputmode="decimal" value="' . Utils::echapper((string) ($article['montant'] ?? '')) . '" aria-label="Montant"></td>'
        . '<td class="colonne-etroite"><input type="text" class="form-control form-control-sm text-uppercase champ-monnaie" data-champ="monnaie" name="' . $champ('monnaie') . '" maxlength="3" value="' . Utils::echapper((string) ($article['monnaie'] ?? '')) . '" aria-label="Monnaie"></td>'
        . '</tr>';
};
?>
<div class="container mt-4" id="page-budget-scan"
     data-url-analyse="<?php echo Utils::echapper(BudgetScanController::URL_ANALYSE); ?>"
     data-tesseract-worker="<?php echo BudgetScanController::TESSERACT; ?>/worker.min.js"
     data-tesseract-core="<?php echo BudgetScanController::TESSERACT; ?>/core"
     data-tesseract-langues="<?php echo BudgetScanController::TESSERACT; ?>/lang/4.1.0_best"
     data-monnaie-defaut="<?php echo SiteConfig::MONNAIE_DEFAUT; ?>"
     data-cours-url="<?php echo Utils::echapper(SiteConfig::coursChangeUrl()); ?>">
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

        <?php // UN CHAMP PAR LIGNE, son libellé à sa gauche : la relecture se fait de haut en bas, comme le reçu.?>
        <?php if (count($foyers) === 1) { ?>
        <input type="hidden" name="foyer_id" value="<?php echo $foyers[0]['ID']; ?>" data-monnaie="<?php echo Utils::echapper($foyers[0]['MONNAIE_REF']); ?>">
        <?php } else { ?>
        <div class="row g-2 mb-2 align-items-center">
            <label for="foyer-depense" class="col-4 col-md-3 col-lg-2 col-form-label">Foyer</label>
            <div class="col">
                <select class="form-select" id="foyer-depense" name="foyer_id" required>
                    <?php foreach ($foyers as $foyer) { ?>
                    <option value="<?php echo $foyer['ID']; ?>" data-monnaie="<?php echo Utils::echapper($foyer['MONNAIE_REF']); ?>"<?php echo $foyer['ID'] === $foyerChoisi ? ' selected' : ''; ?>><?php echo Utils::echapper($foyer['NOM'] . ' (' . $foyer['MONNAIE_REF'] . ')'); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <?php } ?>
        <div class="row g-2 mb-2 align-items-center">
            <label for="date-document" class="col-4 col-md-3 col-lg-2 col-form-label">Date</label>
            <div class="col">
                <input type="datetime-local" class="form-control" id="date-document" name="date_document" required value="<?php echo $valeur('date_document'); ?>">
            </div>
        </div>
        <div class="row g-2 mb-2 align-items-center">
            <label for="reference" class="col-4 col-md-3 col-lg-2 col-form-label">Référence</label>
            <div class="col">
                <input type="text" class="form-control" id="reference" name="reference" maxlength="<?php echo AnalyseurTicket::REFERENCE_MAX; ?>" value="<?php echo $valeur('reference'); ?>">
            </div>
        </div>
        <?php // LE COURS DE CHANGE, « 1 [EUR] = [X] CHF (i) », pour un reçu dans une autre monnaie que celle du
              // foyer : proposé avec l'analyse (cours de la BCE), modifiable, demandé à l'enregistrement s'il
              // reste vide. CACHÉE tant que le reçu est dans la monnaie du foyer : resources/js/budget-scan.js
              // la montre, y écrit les deux monnaies et l'adresse de la source. La monnaie du reçu est un champ
              // SANS NOM — il n'est pas envoyé : ce qu'on y saisit est recopié dans la monnaie de chaque article.?>
        <div class="row g-2 mb-2 align-items-center" data-role="ligne-cours" hidden>
            <label for="cours-change" class="col-4 col-md-3 col-lg-2 col-form-label">Cours de change</label>
            <div class="col d-flex flex-wrap align-items-center gap-2">
                <span>1</span>
                <input type="text" class="form-control form-control-sm text-uppercase champ-monnaie" data-role="cours-monnaie-recu" maxlength="3" aria-label="Monnaie du reçu">
                <span>=</span>
                <input type="text" class="form-control champ-cours" id="cours-change" name="cours_change" inputmode="decimal" value="<?php echo $valeur('cours_change'); ?>">
                <span data-role="cours-monnaie-foyer"></span>
                <a href="<?php echo Utils::echapper(SiteConfig::coursChangeUrl()); ?>" target="_blank" rel="noopener noreferrer" data-role="source-cours" aria-label="Source du cours de change">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                </a>
            </div>
        </div>
        <div class="row g-2 mb-2 align-items-center">
            <label for="vendeur" class="col-4 col-md-3 col-lg-2 col-form-label">Vendeur</label>
            <div class="col">
                <input type="text" class="form-control" id="vendeur" name="vendeur" maxlength="<?php echo AnalyseurTicket::VENDEUR_MAX; ?>" value="<?php echo $valeur('vendeur'); ?>">
            </div>
        </div>
        <div class="row g-2 mb-2 align-items-center">
            <label for="lieu" class="col-4 col-md-3 col-lg-2 col-form-label">Lieu</label>
            <div class="col">
                <input type="text" class="form-control" id="lieu" name="lieu" maxlength="<?php echo AnalyseurTicket::LIEU_MAX; ?>" value="<?php echo $valeur('lieu'); ?>">
            </div>
        </div>
        <div class="row g-2 mb-2 align-items-center">
            <label for="numero-tva" class="col-4 col-md-3 col-lg-2 col-form-label">N° de TVA</label>
            <div class="col">
                <input type="text" class="form-control" id="numero-tva" name="numero_tva" maxlength="<?php echo AnalyseurTicket::NUMERO_TVA_MAX; ?>" value="<?php echo $valeur('numero_tva'); ?>">
            </div>
        </div>
        <div class="row g-2 mb-2 align-items-center">
            <label for="description" class="col-4 col-md-3 col-lg-2 col-form-label">Description</label>
            <div class="col">
                <input type="text" class="form-control" id="description" name="description" maxlength="<?php echo AnalyseurTicket::DESCRIPTION_MAX; ?>" value="<?php echo $valeur('description'); ?>">
            </div>
        </div>

        <h3 class="h5 mt-4 d-flex align-items-center gap-2">
            Articles
            <button type="button" class="btn btn-link p-0 lien-ajout-article" data-action="ajouter-article" title="Ajouter un article" aria-label="Ajouter un article">
                <i class="fas fa-circle-plus" aria-hidden="true"></i>
            </button>
        </h3>
        <p class="mention-discrete">Seules les lignes cochées sont enregistrées.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle table-articles">
                <tbody data-role="lignes-articles">
                    <?php foreach (($memoire['articles'] ?? []) as $rang => $article) { ?>
                    <?php echo $ligneArticle((string) $rang, $article); ?>
                    <?php } ?>
                </tbody>
                <?php // LES DEUX TOTAUX, tenus par resources/js/budget-scan.js : la somme des lignes cochées, et le
                      // total imprimé sur le reçu. Un écart colore le total calculé, et se dit en dessous.?>
                <tfoot>
                    <tr data-role="ligne-total-calcule">
                        <td class="colonne-etroite"></td>
                        <th scope="row">Total calculé</th>
                        <td class="colonne-etroite text-end montant" data-role="total-calcule"></td>
                        <td class="colonne-etroite" data-role="monnaie-total-calcule"></td>
                    </tr>
                    <tr data-role="ecart-total" hidden>
                        <td class="colonne-etroite"></td>
                        <td colspan="3" class="text-danger small" role="status">La somme des articles ne correspond pas au total affiché sur le reçu : vérifiez les lignes.</td>
                    </tr>
                    <tr>
                        <td class="colonne-etroite"></td>
                        <th scope="row">Total affiché</th>
                        <td class="colonne-etroite text-end montant" data-role="total-affiche"></td>
                        <td class="colonne-etroite" data-role="monnaie-total-affiche"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="d-flex justify-content-center">
            <button type="submit" class="btn btn-primary bouton-enregistrer-quittance">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i> Enregistrer la quittance
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

    <?php // POPPER, pour l'infobulle Bootstrap de la source du cours : bootstrap.min.js (pied de page) le lit
          // AU MOMENT OÙ IL SE CHARGE — il doit donc venir avant lui, et seule cette page en a besoin.?>
    <script src="/resources/js/popper.min.js"></script>
    <script src="<?php echo BudgetScanController::TESSERACT; ?>/tesseract.min.js"></script>
    <script src="/resources/js/budget-scan.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/budget-scan.js') ?: '0')); ?>"></script>
    <?php } ?>
</div>
