<?php
declare(strict_types=1);

use Foyer\App\BudgetController;
use Foyer\App\Saisie;
use Foyer\App\Utils;

/**
 * « Budget » : le foyer dans le titre (une liste de choix s'il y en a plusieurs), filtres de période, puis une carte par
 * personne — son total en en-tête, ses dépenses dans le corps.
 *
 * Le détail d'une dépense est une fenêtre modale Bootstrap, la confirmation de sa suppression un
 * volet (collapse) : aucun script à nous sur cette page.
 *
 * @var list<array{ID: int, NOM: string, MONNAIE_REF: string}>                         $foyers
 * @var array{ID: int, NOM: string, MONNAIE_REF: string}|null                          $foyer       Le foyer affiché
 * @var array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string}   $filtres
 * @var array<string, array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string}> $periodes Les raccourcis, par libellé
 * @var array{depenses: list<array<string, mixed>>, par_personne: array<int, array{PSEUDONYME: string, TOTAUX: array<string, int>, DEPENSES: list<array<string, mixed>>}>}|null $budget
 * @var int                                                                            $personne_id
 * @var string                                                                         $csrf_token
 */

$montants = static function (array $totaux): string {
    if ($totaux === []) {
        return '—';
    }
    $lignes = [];
    foreach ($totaux as $monnaie => $centimes) {
        $lignes[] = Utils::echapper(Saisie::formaterMontant($centimes, (string) $monnaie));
    }

    return implode('<br>', $lignes);
};
$dateSuisse = static fn (string $date): string => date('d.m.Y', (int) strtotime($date));
// L'heure du document n'est écrite que si elle est connue : minuit est celle d'un reçu sans heure imprimée.
$dateHeureSuisse = static function (string $date): string {
    $instant = (int) strtotime($date);

    return date('d.m.Y', $instant) . (date('H:i', $instant) === '00:00' ? '' : ' ' . date('H:i', $instant));
};
// Un cours s'écrit avec quatre décimales au moins, sans les zéros inutiles au-delà : « 0.9412 », « 0.01052 ».
$cours = static fn (string $valeur): string => (string) preg_replace('/(\.\d{4}\d*?)0+$/', '$1', $valeur);
?>
<div class="container mt-4" id="page-budget">
    <?php if ($foyer === null) { ?>
    <h2><i class="fas fa-coins" aria-hidden="true"></i> Budget</h2>
    <hr>
    <div class="alert alert-info" data-role="sans-foyer">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        Vous n'êtes membre d'aucun foyer : un administrateur doit d'abord vous y rattacher.
    </div>
    <?php } else { ?>
    <h2 class="d-flex flex-wrap align-items-center gap-2">
        <span><i class="fas fa-coins" aria-hidden="true"></i> Budget du foyer</span>
        <?php if (count($foyers) > 1) { ?>
        <?php // Le choix du foyer appartient au formulaire des filtres : en changer les garde. ?>
        <select class="form-select form-select-lg w-auto fst-italic" name="foyer" form="form-filtres" aria-label="Foyer" onchange="this.form.submit()" data-role="choix-foyer">
            <?php foreach ($foyers as $choix) { ?>
            <option value="<?php echo $choix['ID']; ?>"<?php echo $choix['ID'] === $foyer['ID'] ? ' selected' : ''; ?>><?php echo Utils::echapper($choix['NOM']); ?></option>
            <?php } ?>
        </select>
        <?php } else { ?>
        <i><?php echo Utils::echapper($foyer['NOM']); ?></i>
        <?php } ?>
    </h2>
    <hr>

    <form method="GET" action="/" class="bloc-formulaire mb-4" id="form-filtres" data-role="filtres">
        <input type="hidden" name="action" value="budget">
        <?php if (count($foyers) === 1) { ?>
        <input type="hidden" name="foyer" value="<?php echo $foyer['ID']; ?>">
        <?php } ?>
        <div class="row g-3 align-items-end">
            <fieldset class="col-12 col-lg-5">
                <legend class="form-label fs-6">Date du document</legend>
                <div class="input-group">
                    <span class="input-group-text">du</span>
                    <input type="date" class="form-control" name="doc_du" value="<?php echo Utils::echapper($filtres['doc_du']); ?>" aria-label="Date du document, à partir du">
                    <span class="input-group-text">au</span>
                    <input type="date" class="form-control" name="doc_au" value="<?php echo Utils::echapper($filtres['doc_au']); ?>" aria-label="Date du document, jusqu'au">
                </div>
            </fieldset>
            <fieldset class="col-12 col-lg-5">
                <legend class="form-label fs-6">Date de saisie</legend>
                <div class="input-group">
                    <span class="input-group-text">du</span>
                    <input type="date" class="form-control" name="saisie_du" value="<?php echo Utils::echapper($filtres['saisie_du']); ?>" aria-label="Date de saisie, à partir du">
                    <span class="input-group-text">au</span>
                    <input type="date" class="form-control" name="saisie_au" value="<?php echo Utils::echapper($filtres['saisie_au']); ?>" aria-label="Date de saisie, jusqu'au">
                </div>
            </fieldset>
            <div class="col-12 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter" aria-hidden="true"></i> Filtrer</button>
            </div>
        </div>
        <div class="mt-2 d-flex flex-wrap gap-3">
            <?php foreach ($periodes as $libelle => $periode) { ?>
            <a class="lien-filtre" href="<?php echo Utils::echapper(BudgetController::url($foyer['ID'], $periode)); ?>"><i class="fas fa-calendar-days" aria-hidden="true"></i> <?php echo Utils::echapper($libelle); ?></a>
            <?php } ?>
        </div>
    </form>

    <?php if ($budget['depenses'] === []) { ?>
    <p class="mention-discrete" data-role="aucune-depense">Aucune dépense pour cette période.</p>
    <?php } else { ?>
    <?php // Sur grand écran, autant de colonnes de même largeur que de personnes ; empilées sur téléphone. ?>
    <div class="row g-3" data-role="personnes">
        <?php foreach ($budget['par_personne'] as $personneId => $personne) { ?>
        <div class="col-12 col-lg">
            <div class="card h-100" data-role="personne" data-personne="<?php echo $personneId; ?>">
                <div class="card-header d-flex justify-content-between align-items-start gap-3">
                    <h3 class="h6 mb-0"><?php echo Utils::echapper($personne['PSEUDONYME']); ?></h3>
                    <span class="montant fw-bold text-end"><?php echo $montants($personne['TOTAUX']); ?></span>
                </div>
                <?php if ($personne['DEPENSES'] === []) { ?>
                <div class="card-body mention-discrete">Aucune dépense pour cette période.</div>
                <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Vendeur</th>
                                <th scope="col" class="text-end">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personne['DEPENSES'] as $depense) { ?>
                            <?php // Toute la ligne ouvre le détail ; le bouton de la date la rend accessible au clavier. ?>
                            <tr class="ligne-depense" data-role="depense" data-depense="<?php echo $depense['ID']; ?>" data-bs-toggle="modal" data-bs-target="#detail-depense-<?php echo $depense['ID']; ?>">
                                <td>
                                    <button type="button" class="btn btn-link p-0 text-reset text-decoration-none text-nowrap" aria-haspopup="dialog"><?php echo $dateHeureSuisse((string) $depense['DATE_DOCUMENT']); ?></button>
                                </td>
                                <td><?php echo Utils::echapper((string) ($depense['VENDEUR'] ?? '')); ?></td>
                                <td class="text-end montant"><?php echo $montants($depense['TOTAUX']); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>

    <?php foreach ($budget['depenses'] as $depense) { ?>
    <div class="modal fade" id="detail-depense-<?php echo $depense['ID']; ?>" tabindex="-1" aria-labelledby="detail-depense-<?php echo $depense['ID']; ?>-titre" aria-hidden="true" data-role="detail-depense">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detail-depense-<?php echo $depense['ID']; ?>-titre">
                        <?php echo Utils::echapper(((string) ($depense['VENDEUR'] ?? '') === '' ? '' : $depense['VENDEUR'] . ' — ') . $dateHeureSuisse((string) $depense['DATE_DOCUMENT'])); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <?php foreach (['DESCRIPTION' => 'Description', 'LIEU' => 'Lieu', 'REFERENCE' => 'Référence', 'NUMERO_TVA' => 'N° de TVA'] as $colonne => $libelle) { ?>
                    <?php if ((string) ($depense[$colonne] ?? '') !== '') { ?>
                    <div><strong><?php echo $libelle; ?> :</strong> <?php echo Utils::echapper((string) $depense[$colonne]); ?></div>
                    <?php } ?>
                    <?php } ?>
                    <?php if ($depense['MONNAIE_RECU'] !== null) { ?>
                    <div><strong>Cours de change :</strong> 1 <?php echo Utils::echapper($depense['MONNAIE_RECU']); ?> = <?php echo Utils::echapper($cours((string) $depense['COURS_CHANGE']) . ' ' . $foyer['MONNAIE_REF']); ?></div>
                    <?php } ?>
                    <div class="mention-discrete"><?php echo 'Saisie le ' . $dateSuisse((string) $depense['CREATED_WHEN']); ?></div>
                    <?php
                    // UN REÇU CONVERTI A SA COLONNE D'ORIGINE, à gauche de celle du foyer, avec son propre total.
                    $etranger = $depense['MONNAIE_RECU'] !== null;
                    $totalOrigine = 0;
                    foreach ($depense['ARTICLES'] as $article) {
                        $totalOrigine += $article['CONVERTI'] ? Saisie::centimes((string) $article['MONTANT']) : 0;
                    }
                    ?>
                    <table class="table table-sm align-middle my-3" data-role="articles">
                        <thead>
                            <tr>
                                <th scope="col">Article</th>
                                <?php if ($etranger) { ?>
                                <th scope="col" class="text-end">Montant (<?php echo Utils::echapper($depense['MONNAIE_RECU']); ?>)</th>
                                <th scope="col" class="text-end">Montant (<?php echo Utils::echapper($foyer['MONNAIE_REF']); ?>)</th>
                                <?php } else { ?>
                                <th scope="col" class="text-end">Montant</th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($depense['ARTICLES'] as $article) { ?>
                            <tr>
                                <td><?php echo Utils::echapper((string) $article['NOM']); ?></td>
                                <?php if ($etranger) { ?>
                                <td class="text-end montant mention-discrete"><?php echo $article['CONVERTI'] ? Utils::echapper(Saisie::formaterMontant(Saisie::centimes((string) $article['MONTANT']), (string) $article['MONNAIE'])) : ''; ?></td>
                                <?php } ?>
                                <td class="text-end montant"><?php echo Utils::echapper(Saisie::formaterMontant($article['MONTANT_AFFICHE'], $article['MONNAIE_AFFICHEE'])); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">Total</th>
                                <?php if ($etranger) { ?>
                                <td class="text-end montant mention-discrete fw-bold"><?php echo Utils::echapper(Saisie::formaterMontant($totalOrigine, $depense['MONNAIE_RECU'])); ?></td>
                                <?php } ?>
                                <td class="text-end montant fw-bold"><?php echo $montants($depense['TOTAUX']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php if ($depense['PERSONNE_ID'] === $personne_id) { ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#confirmer-depense-<?php echo $depense['ID']; ?>" aria-expanded="false">
                        <i class="fas fa-trash" aria-hidden="true"></i> Supprimer cette dépense
                    </button>
                    <div class="collapse mt-2" id="confirmer-depense-<?php echo $depense['ID']; ?>">
                        <form method="POST" action="/?action=budget" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo Utils::echapper($csrf_token); ?>">
                            <input type="hidden" name="scan_id" value="<?php echo $depense['ID']; ?>">
                            <input type="hidden" name="foyer" value="<?php echo $foyer['ID']; ?>">
                            <?php foreach ($filtres as $filtre => $valeurFiltre) { ?>
                            <input type="hidden" name="retour[<?php echo $filtre; ?>]" value="<?php echo Utils::echapper($valeurFiltre); ?>">
                            <?php } ?>
                            <button type="submit" class="btn btn-sm btn-danger">Confirmer la suppression</button>
                        </form>
                    </div>
                    <?php } ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
    <?php } ?>
    <?php } ?>
</div>
