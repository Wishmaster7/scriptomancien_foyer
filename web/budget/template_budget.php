<?php
declare(strict_types=1);

use Foyer\App\BudgetController;
use Foyer\App\Saisie;
use Foyer\App\Utils;

/**
 * « Budget » : onglets des foyers (s'il y en a plusieurs), filtres de période, dépenses, totaux.
 *
 * Le détail d'une dépense et la confirmation de sa suppression sont des volets Bootstrap
 * (collapse) : aucun script à nous sur cette page.
 *
 * @var list<array{ID: int, NOM: string}>                                              $foyers
 * @var array{ID: int, NOM: string}|null                                               $foyer       Le foyer affiché
 * @var array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string}   $filtres
 * @var array{depenses: list<array<string, mixed>>, par_personne: array<int, array{PSEUDONYME: string, TOTAUX: array<string, int>}>, total: array<string, int>}|null $budget
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
$sansFiltre = ['doc_du' => '', 'doc_au' => '', 'saisie_du' => '', 'saisie_au' => ''];
?>
<div class="container mt-4" id="page-budget">
    <h2><i class="fas fa-coins" aria-hidden="true"></i> Budget</h2>
    <hr>

    <?php if ($foyer === null) { ?>
    <div class="alert alert-info" data-role="sans-foyer">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        Vous n'êtes membre d'aucun foyer : un administrateur doit d'abord vous y rattacher.
    </div>
    <?php } else { ?>

    <?php if (count($foyers) > 1) { ?>
    <ul class="nav nav-tabs mb-3 onglets-list" data-role="onglets-foyers">
        <?php foreach ($foyers as $onglet) { ?>
        <li class="nav-item">
            <a class="nav-link<?php echo $onglet['ID'] === $foyer['ID'] ? ' active" aria-current="page' : ''; ?>" href="<?php echo Utils::echapper(BudgetController::url($onglet['ID'], $filtres)); ?>"><?php echo Utils::echapper($onglet['NOM']); ?></a>
        </li>
        <?php } ?>
    </ul>
    <?php } else { ?>
    <h3 class="h5 mb-3"><?php echo Utils::echapper($foyer['NOM']); ?></h3>
    <?php } ?>

    <form method="GET" action="/" class="bloc-formulaire mb-4" data-role="filtres">
        <input type="hidden" name="action" value="budget">
        <input type="hidden" name="foyer" value="<?php echo $foyer['ID']; ?>">
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
            <a href="<?php echo Utils::echapper('/?action=budget&foyer=' . $foyer['ID']); ?>">Mois courant</a>
            <a href="<?php echo Utils::echapper(BudgetController::url($foyer['ID'], $sansFiltre)); ?>">Tout afficher</a>
        </div>
    </form>

    <?php if ($budget['depenses'] === []) { ?>
    <p class="mention-discrete" data-role="aucune-depense">Aucune dépense pour cette période.</p>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table align-middle" data-role="depenses">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Vendeur</th>
                    <th scope="col">Personne</th>
                    <th scope="col" class="text-end">Total</th>
                    <th scope="col"><span class="visually-hidden">Détail</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($budget['depenses'] as $depense) { ?>
                <tr data-role="depense" data-depense="<?php echo $depense['ID']; ?>">
                    <td><?php echo $dateSuisse((string) $depense['DATE_DOCUMENT']); ?></td>
                    <td><?php echo Utils::echapper((string) ($depense['VENDEUR'] ?? '')); ?></td>
                    <td><?php echo Utils::echapper((string) $depense['PSEUDONYME']); ?></td>
                    <td class="text-end montant"><?php echo $montants($depense['TOTAUX']); ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#detail-depense-<?php echo $depense['ID']; ?>" aria-expanded="false" aria-label="Voir les articles">
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
                <tr class="collapse detail-depense" id="detail-depense-<?php echo $depense['ID']; ?>">
                    <td colspan="5">
                        <?php foreach (['DESCRIPTION' => 'Description', 'LIEU' => 'Lieu', 'NUMERO_TVA' => 'N° de TVA'] as $colonne => $libelle) { ?>
                        <?php if ((string) ($depense[$colonne] ?? '') !== '') { ?>
                        <div><strong><?php echo $libelle; ?> :</strong> <?php echo Utils::echapper((string) $depense[$colonne]); ?></div>
                        <?php } ?>
                        <?php } ?>
                        <div class="mention-discrete">Saisie le <?php echo $dateSuisse((string) $depense['CREATED_WHEN']); ?></div>
                        <ul class="list-unstyled my-2">
                            <?php foreach ($depense['ARTICLES'] as $article) { ?>
                            <li class="d-flex justify-content-between gap-3">
                                <span><?php echo Utils::echapper((string) $article['NOM']); ?></span>
                                <span class="montant"><?php echo Utils::echapper(Saisie::formaterMontant(Saisie::centimes((string) $article['MONTANT']), (string) $article['MONNAIE'])); ?></span>
                            </li>
                            <?php } ?>
                        </ul>
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
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="card" data-role="totaux">
        <div class="card-header"><h3 class="h6 mb-0">Totaux de la période</h3></div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tbody>
                    <?php foreach ($budget['par_personne'] as $personne) { ?>
                    <tr>
                        <th scope="row"><?php echo Utils::echapper($personne['PSEUDONYME']); ?></th>
                        <td class="text-end montant"><?php echo $montants($personne['TOTAUX']); ?></td>
                    </tr>
                    <?php } ?>
                    <tr class="table-active" data-role="total-foyer">
                        <th scope="row">Total du foyer</th>
                        <td class="text-end montant fw-bold"><?php echo $montants($budget['total']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php } ?>
    <?php } ?>
</div>
