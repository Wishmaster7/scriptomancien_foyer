<?php
declare(strict_types=1);

use Foyer\App\FoyersController;
use Foyer\App\FoyersModel;
use Foyer\App\Saisie;
use Foyer\App\Utils;

/**
 * « Gestion des foyers », sur le modèle de « Gestion des conventions » (projet convention) : la
 * liste, l'ajout, et deux onglets transitoires — la fiche (?foyer=…) et l'édition (?modifier=…) —
 * qui prennent la place de l'ajout tant qu'ils sont ouverts. Revenir sur la liste les retire
 * (resources/js/onglets.js).
 *
 * @var string                            $onglet         liste | ajouter | details | modifier
 * @var list<array<string, mixed>>        $foyers         ID, NOM, NB_MEMBRES, MOYENNES (centimes par monnaie)
 * @var list<array<string, mixed>>        $personnes      Personnes admises, candidates à l'appartenance
 * @var array<string, mixed>|null         $details        Foyer consulté, avec MEMBRES et NB_DEPENSES
 * @var array<string, mixed>|null         $edition        Foyer modifié, avec MEMBRES et NB_DEPENSES
 * @var array{nom: string, membres: list<int>}|null $saisie_ajout   Saisie d'un ajout refusé
 * @var array{nom: string, membres: list<int>}|null $saisie_edition Saisie d'une édition refusée
 * @var string                            $csrf_token
 */

$navActif = static fn (string $n): string => $onglet === $n ? ' active' : '';
$paneActif = static fn (string $n): string => $onglet === $n ? ' show active' : '';
$ariaSel = static fn (string $n): string => $onglet === $n ? 'true' : 'false';
$onglet_transitoire = $details !== null || $edition !== null;

$nomPersonne = static function (array $personne): string {
    $complet = trim((string) $personne['PRENOM'] . ' ' . (string) $personne['NOM']);

    return (string) $personne['PSEUDONYME'] . ($complet !== '' ? ' (' . $complet . ')' : '');
};
$dateHeure = static fn (?string $valeur): string => $valeur === null
    ? '<em class="text-muted">Jamais</em>'
    : Utils::echapper(date('d.m.Y à H:i', (int) strtotime($valeur)));

/**
 * Le formulaire d'un foyer, vierge ($foyer = null) ou pré-rempli, puis sa ZONE DES MEMBRES.
 *
 * LES MEMBRES VOYAGENT DANS LE FORMULAIRE, en champs cachés « membres[] » : la zone qui les montre
 * — liste paginée, bouton « Ajouter un membre » et sa fenêtre de recherche — n'en est que la vue,
 * tenue par resources/js/membres-foyer.js. Rien n'est enregistré avant l'envoi du formulaire.
 *
 * LES CANDIDATS sont les personnes admises, plus — en édition — les membres actuels dont l'accès a
 * été fermé depuis : leur nom doit rester lisible dans la liste, et les retirer possible. Ils sont
 * remis au script en DONNÉES (JSON), jamais en script en ligne.
 *
 * @param array{nom: string, membres: list<int>}|null $saisie
 */
$formulaire = static function (?array $foyer, ?array $saisie, string $prefixe) use ($personnes, $nomPersonne, $csrf_token): void {
    $membres = $foyer === null ? [] : $foyer['MEMBRES'];
    $candidats = $personnes;
    foreach ($membres as $membre) {
        if (!in_array($membre['ID'], array_column($candidats, 'ID'), true)) {
            $candidats[] = $membre;
        }
    }
    $nom = $saisie['nom'] ?? ($foyer['NOM'] ?? '');
    $retenus = $saisie['membres'] ?? array_column($membres, 'ID');
    $donnees = array_map(
        static fn (array $candidat): array => ['id' => (int) $candidat['ID'], 'libelle' => $nomPersonne($candidat)],
        $candidats
    );
    ?>
    <form method="POST" action="<?php echo FoyersController::URL; ?>" class="bloc-formulaire" id="<?php echo $prefixe; ?>formulaire">
        <input type="hidden" name="csrf_token" value="<?php echo Utils::echapper($csrf_token); ?>">
        <input type="hidden" name="action_foyer" value="<?php echo $foyer === null ? 'creer' : 'modifier'; ?>">
        <?php if ($foyer !== null) { ?>
        <input type="hidden" name="foyer_id" value="<?php echo $foyer['ID']; ?>">
        <?php } ?>
        <div class="row mb-2">
            <label class="col-6 form-label" for="<?php echo $prefixe; ?>nom">Nom <span class="text-danger">*</span></label>
            <div class="col-6"><input type="text" name="nom" id="<?php echo $prefixe; ?>nom" class="form-control" maxlength="<?php echo FoyersModel::NOM_LONGUEUR_MAX; ?>" value="<?php echo Utils::echapper($nom); ?>" required></div>
        </div>
        <div data-role="champs-membres">
            <?php foreach ($retenus as $personneId) { ?>
            <input type="hidden" name="membres[]" value="<?php echo (int) $personneId; ?>">
            <?php } ?>
        </div>
    </form>

    <div class="tab-pane fade show active bloc-formulaire mt-3" data-role="zone-membres"
         data-formulaire="<?php echo $prefixe; ?>formulaire" data-fenetre="<?php echo $prefixe; ?>fenetre-membres">
        <h3 class="h5">Membres</h3>
        <script type="application/json" data-role="candidats"><?php echo json_encode($donnees, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
        <p class="mention-discrete" data-role="aucun-membre" hidden>Aucun membre.</p>
        <ul class="list-group liste-membres" data-role="liste-membres"></ul>
        <nav aria-label="Pages des membres">
            <ul class="pagination pagination-sm mt-2 mb-0" data-role="pagination-membres"></ul>
        </nav>
        <button type="button" class="btn btn-outline-secondary mt-3" data-bs-toggle="modal" data-bs-target="#<?php echo $prefixe; ?>fenetre-membres">
            <i class="fas fa-user-plus"></i> Ajouter un membre
        </button>
    </div>

    <p class="mention-discrete mt-3"><span class="text-danger">*</span> champs obligatoires</p>
    <div class="d-flex gap-2">
        <?php if ($foyer !== null) { ?>
        <a href="<?php echo FoyersController::URL; ?>&amp;foyer=<?php echo $foyer['ID']; ?>" class="btn btn-secondary"><i class="fas fa-xmark"></i> Annuler</a>
        <?php } ?>
        <button type="submit" form="<?php echo $prefixe; ?>formulaire" class="btn btn-primary"><i class="fas <?php echo $foyer === null ? 'fa-plus' : 'fa-floppy-disk'; ?>"></i> <?php echo $foyer === null ? 'Créer le foyer' : 'Enregistrer'; ?></button>
    </div>
    <?php
};

/** La fenêtre de recherche d'un formulaire : rendue après les onglets, jamais dans un volet qui se masque. */
$fenetre = static function (string $prefixe): void {
    ?>
    <div class="modal fade" id="<?php echo $prefixe; ?>fenetre-membres" tabindex="-1" aria-labelledby="<?php echo $prefixe; ?>fenetre-membres-titre" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="<?php echo $prefixe; ?>fenetre-membres-titre">Ajouter un membre</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="<?php echo $prefixe; ?>recherche-membre">Rechercher une personne</label>
                    <input type="search" class="form-control mb-3" id="<?php echo $prefixe; ?>recherche-membre" data-role="recherche-membre" placeholder="Pseudonyme, prénom ou nom" autocomplete="off">
                    <p class="mention-discrete" data-role="aucun-resultat" hidden>Aucune personne ne correspond.</p>
                    <div class="list-group" data-role="resultats-membres"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    <?php
};
?>
<div class="container mt-4" id="page-foyers">
    <h2><i class="fas fa-house-user" aria-hidden="true"></i> Gestion des foyers</h2>
    <hr>

    <ul class="nav nav-tabs mb-3 onglets-list" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link<?php echo $navActif('liste'); ?>" id="tab-liste" data-bs-toggle="tab"
                    data-bs-target="#pane-liste" type="button" role="tab"
                    aria-controls="pane-liste" aria-selected="<?php echo $ariaSel('liste'); ?>"><i class="fas fa-list"></i> Liste des foyers</button>
        </li>
        <li class="nav-item<?php echo $onglet_transitoire ? ' d-none' : ''; ?>" role="presentation">
            <button class="nav-link<?php echo $navActif('ajouter'); ?>" id="tab-ajouter" data-bs-toggle="tab"
                    data-bs-target="#pane-ajouter" type="button" role="tab"
                    aria-controls="pane-ajouter" aria-selected="<?php echo $ariaSel('ajouter'); ?>"><i class="fas fa-plus"></i> Ajouter un foyer</button>
        </li>
        <?php if ($details !== null) { ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?php echo $navActif('details'); ?>" id="tab-details" data-onglet-edition="tab-ajouter" data-bs-toggle="tab"
                    data-bs-target="#pane-details" type="button" role="tab"
                    aria-controls="pane-details" aria-selected="<?php echo $ariaSel('details'); ?>"><i class="fas fa-eye"></i> Détails du foyer</button>
        </li>
        <?php } ?>
        <?php if ($edition !== null) { ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?php echo $navActif('modifier'); ?>" id="tab-modifier" data-onglet-edition="tab-ajouter" data-bs-toggle="tab"
                    data-bs-target="#pane-modifier" type="button" role="tab"
                    aria-controls="pane-modifier" aria-selected="<?php echo $ariaSel('modifier'); ?>"><i class="fas fa-pen-to-square"></i> Modifier un foyer</button>
        </li>
        <?php } ?>
    </ul>

    <div class="tab-content">
        <?php // ========================= ONGLET LISTE =========================?>
        <div class="tab-pane fade<?php echo $paneActif('liste'); ?>" id="pane-liste" role="tabpanel" aria-labelledby="tab-liste">
            <?php if ($foyers === []) { ?>
            <p class="mention-discrete" data-role="aucun-foyer">Aucun foyer n'a encore été créé.</p>
            <?php } else { ?>
            <table class="table table-striped" data-role="liste-foyers">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th class="text-end">Membres</th>
                        <th class="text-end">Dépenses mensuelles moyennes</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($foyers as $foyer) { ?>
                    <tr>
                        <td><?php echo Utils::echapper($foyer['NOM']); ?></td>
                        <td class="text-end"><?php echo (int) $foyer['NB_MEMBRES']; ?></td>
                        <td class="text-end montant"><?php echo $foyer['MOYENNES'] === [] ? '—' : implode('<br>', array_map(
                            static fn (string $monnaie, int $centimes): string => Utils::echapper(Saisie::formaterMontant($centimes, $monnaie)),
                            array_keys($foyer['MOYENNES']),
                            $foyer['MOYENNES']
                        )); ?></td>
                        <td class="text-nowrap text-end">
                            <a class="btn btn-sm btn-info" href="<?php echo FoyersController::URL; ?>&amp;foyer=<?php echo $foyer['ID']; ?>" aria-label="Consulter">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a class="btn btn-sm btn-secondary" href="<?php echo FoyersController::URL; ?>&amp;modifier=<?php echo $foyer['ID']; ?>" aria-label="Modifier">
                                <i class="fas fa-pen-to-square"></i>
                            </a>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php } ?>
        </div>

        <?php // ===================== ONGLET AJOUTER UN FOYER =====================?>
        <div class="tab-pane fade<?php echo $paneActif('ajouter'); ?>" id="pane-ajouter" role="tabpanel" aria-labelledby="tab-ajouter">
            <?php $formulaire(null, $saisie_ajout, 'ajouter_'); ?>
        </div>

        <?php if ($details !== null) { ?>
        <?php // ===================== ONGLET DÉTAILS DU FOYER =====================?>
        <div class="tab-pane fade<?php echo $paneActif('details'); ?>" id="pane-details" role="tabpanel" aria-labelledby="tab-details">
            <div class="card w-100">
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-6 libelle-ligne">Nom</div>
                        <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo Utils::echapper($details['NOM']); ?></p></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 libelle-ligne">Dépenses enregistrées</div>
                        <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo $details['NB_DEPENSES']; ?></p></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 libelle-ligne">Créé le</div>
                        <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo $dateHeure($details['CREATED_WHEN']); ?></p></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 libelle-ligne">Dernière modification</div>
                        <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo $dateHeure($details['LAST_MODIFIED_WHEN']); ?></p></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?php echo FoyersController::URL; ?>&amp;modifier=<?php echo $details['ID']; ?>" class="btn btn-primary">
                            <i class="fas fa-pen-to-square"></i> Modifier
                        </a>
                        <?php if ($details['NB_DEPENSES'] === 0) { ?>
                        <?php // LA CONFIRMATION EST UN VOLET BOOTSTRAP, sans script à nous.?>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#confirmer-suppression" aria-expanded="false">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                        <?php } ?>
                    </div>
                    <?php if ($details['NB_DEPENSES'] === 0) { ?>
                    <div class="collapse mt-2" id="confirmer-suppression">
                        <form method="POST" action="<?php echo FoyersController::URL; ?>" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo Utils::echapper($csrf_token); ?>">
                            <input type="hidden" name="action_foyer" value="supprimer">
                            <input type="hidden" name="foyer_id" value="<?php echo $details['ID']; ?>">
                            <button type="submit" class="btn btn-danger">Confirmer la suppression</button>
                        </form>
                    </div>
                    <?php } else { ?>
                    <p class="mention-discrete mt-2 mb-0">Ce foyer porte des dépenses : il ne peut pas être supprimé.</p>
                    <?php } ?>
                </div>
            </div>

            <?php // LES MEMBRES, DANS UNE SECONDE ZONE, comme dans les formulaires : la même liste paginée, en
                  // lecture seule — sans formulaire, membres-foyer.js n'y met ni bouton ni recherche. Le
                  // serveur la rend aussi, pour qu'elle se lise avant le script.?>
            <div class="tab-pane fade show active card w-100 mt-3" data-role="zone-membres">
                <div class="card-body">
                    <h3 class="h5">Membres</h3>
                    <script type="application/json" data-role="candidats"><?php echo json_encode(array_map(
                        static fn (array $membre): array => ['id' => (int) $membre['ID'], 'libelle' => $nomPersonne($membre)],
                        $details['MEMBRES']
                    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
                    <p class="mention-discrete mb-0" data-role="aucun-membre"<?php echo $details['MEMBRES'] === [] ? '' : ' hidden'; ?>>Aucun membre.</p>
                    <ul class="list-group liste-membres" data-role="liste-membres">
                        <?php foreach ($details['MEMBRES'] as $membre) { ?>
                        <li class="list-group-item"><span><?php echo Utils::echapper($nomPersonne($membre)); ?></span></li>
                        <?php } ?>
                    </ul>
                    <nav aria-label="Pages des membres">
                        <ul class="pagination pagination-sm mt-2 mb-0" data-role="pagination-membres"></ul>
                    </nav>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php if ($edition !== null) { ?>
        <?php // ===================== ONGLET MODIFIER UN FOYER =====================?>
        <div class="tab-pane fade<?php echo $paneActif('modifier'); ?>" id="pane-modifier" role="tabpanel" aria-labelledby="tab-modifier">
            <?php $formulaire($edition, $saisie_edition, 'modifier_'); ?>
        </div>
        <?php } ?>
    </div>

    <?php $fenetre('ajouter_'); ?>
    <?php if ($edition !== null) { ?>
    <?php $fenetre('modifier_'); ?>
    <?php } ?>

    <script src="/resources/js/onglets.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/onglets.js') ?: '0')); ?>"></script>
    <script src="/resources/js/membres-foyer.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/membres-foyer.js') ?: '0')); ?>"></script>
</div>
