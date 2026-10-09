<?php

declare(strict_types=1);

use Foyer\App\BudgetController;
use Foyer\App\Flash;
use Foyer\App\Journal;
use Foyer\App\Utils;

require_once __DIR__ . '/../TestBase.php';

/**
 * La page « Budget » : les dépenses d'un foyer de la personne connectée, filtrées, totalisées par
 * monnaie, et la suppression d'une dépense par son auteur.
 */
class BudgetControllerTest extends TestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        // LE MOIS COURANT EST OCTOBRE 2026 pour toute la classe : c'est le filtre par défaut.
        Utils::figerMaintenant(mktime(12, 0, 0, 10, 15, 2026));
    }

    protected function tearDown(): void
    {
        Utils::figerMaintenant(null);
        parent::tearDown();
    }

    private function statut(int $scanId): ?string
    {
        return self::$db->query("SELECT STATUT FROM BUDGET_SCAN WHERE ID = $scanId")->fetch_row()[0] ?? null;
    }

    private function vieillir(int $scanId, int $minutes): void
    {
        self::$db->query("UPDATE BUDGET_SCAN SET CREATED_WHEN = NOW() - INTERVAL $minutes MINUTE WHERE ID = $scanId");
    }

    public function testSansFoyerLaPageLeDit(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('id="page-budget"', $html);
        $this->assertStringContainsString('data-role="sans-foyer"', $html);
        $this->assertStringNotContainsString('data-role="filtres"', $html);
    }

    /**
     * UN SEUL FOYER, PAS D'ONGLETS, et le mois courant par défaut : la dépense de septembre et celle
     * qui a été supprimée ne s'affichent pas ; les totaux ne mêlent jamais deux monnaies.
     */
    public function testUnSeulFoyerAfficheLeMoisCourantEtSesTotauxParMonnaie(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId, self::$adminId]);
        $this->creerDepense($foyerId, self::$membreId, '2026-10-03', [['Pain', '3.50'], ['Lait', '1234.00'], ['Bon', '-1.00']], 'Migros');
        // Le soir du dernier jour du mois : la borne « au » comprend toute la journée.
        $this->creerDepense($foyerId, self::$adminId, '2026-10-31 23:15:00', [['Essence', '50.00', 'EUR']], 'Total');
        $this->creerDepense($foyerId, self::$membreId, '2026-09-30', [['Vieux', '99.00']], 'Vieux magasin');
        $this->creerDepense($foyerId, self::$membreId, '2026-10-05', [['Annulé', '7.00']], 'Supprimee', 'DELETED');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringNotContainsString('data-role="choix-foyer"', $html);
        $this->assertMatchesRegularExpression('#Budget du foyer</span>\s*<i>Maison</i>\s*</h2>#', $html);
        $this->assertStringContainsString('<input type="hidden" name="foyer" value="' . $foyerId . '">', $html);
        $this->assertStringContainsString('name="doc_du" value="2026-10-01"', $html);
        $this->assertStringContainsString('name="doc_au" value="2026-10-31"', $html);
        $this->assertStringContainsString('Migros', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringNotContainsString('Vieux magasin', $html);
        $this->assertStringNotContainsString('Supprimee', $html);
        $this->assertStringContainsString('1’236.50 CHF', $html);
        $this->assertStringContainsString('-1.00 CHF', $html);
        // L'heure n'est écrite que si elle est connue : minuit est celle d'un reçu sans heure.
        $this->assertStringContainsString('aria-haspopup="dialog">03.10.2026</button>', $html);
        $this->assertStringContainsString('aria-haspopup="dialog">31.10.2026 23:15</button>', $html);

        // UNE CARTE PAR PERSONNE : pseudonyme à gauche, total à droite.
        $this->assertMatchesRegularExpression('#<h3 class="h6 mb-0">Admine</h3>\s*<span class="montant fw-bold text-end">50.00 EUR</span>#', $html);
        $this->assertMatchesRegularExpression('#<h3 class="h6 mb-0">Membrine</h3>\s*<span class="montant fw-bold text-end">1’236.50 CHF</span>#', $html);
        $this->assertLessThan(strpos($html, 'Membrine</h3>'), strpos($html, 'Admine</h3>'));
        $this->assertStringNotContainsString('Total du foyer', $html);
    }

    /**
     * TOUT DANS LA MONNAIE DU FOYER : un reçu étranger est converti au cours de SA dépense — le montant
     * d'origine reste lisible à côté — et les totaux s'additionnent. Un reçu étranger sans cours
     * (enregistré avant les cours) reste dans sa monnaie.
     */
    public function testLesMontantsSontConvertisDansLaMonnaieDuFoyer(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        self::$db->query("UPDATE FOYER SET MONNAIE_REF = 'EUR' WHERE ID = $foyerId");
        $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Croissant', '2.00', 'EUR']], 'Boulangerie');
        $suisse = $this->creerDepense($foyerId, self::$membreId, '2026-10-03', [['Raclette', '30.00', 'CHF'], ['Vin', '15.55', 'CHF'], ['Bon', '-5.00', 'CHF']], 'Fromagerie');
        self::$db->query("UPDATE BUDGET_SCAN SET COURS_CHANGE = 1.06420000 WHERE ID = $suisse");
        $this->creerDepense($foyerId, self::$membreId, '2026-10-04', [['Souvenir', '10.00', 'USD']], 'Boutique');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        // 30.00 × 1.0642 = 31.93 ; 15.55 × 1.0642 = 16.55 ; -5.00 × 1.0642 = -5.32 : 43.16 EUR.
        $this->assertMatchesRegularExpression('#Fromagerie</td>\s*<td class="text-end montant">43.16 EUR</td>#', $html);
        $this->assertStringContainsString('<strong>Cours de change :</strong> 1 CHF = 1.0642 EUR', $html);
        // UN REÇU CONVERTI : la colonne de sa monnaie d'origine, à gauche de celle du foyer, totaux compris.
        $fromagerie = (string) strstr((string) strstr($html, 'id="detail-depense-' . $suisse . '"'), 'class="modal-footer"', true);
        $this->assertMatchesRegularExpression('#<th scope="col" class="text-end">Montant \(CHF\)</th>\s*<th scope="col" class="text-end">Montant \(EUR\)</th>#', $fromagerie);
        $this->assertMatchesRegularExpression('#<td>Raclette</td>\s*<td class="text-end montant mention-discrete">30.00 CHF</td>\s*<td class="text-end montant">31.93 EUR</td>#', $fromagerie);
        $this->assertMatchesRegularExpression('#<td>Bon</td>\s*<td class="text-end montant mention-discrete">-5.00 CHF</td>\s*<td class="text-end montant">-5.32 EUR</td>#', $fromagerie);
        $this->assertMatchesRegularExpression('#Total</th>\s*<td class="text-end montant mention-discrete fw-bold">40.55 CHF</td>\s*<td class="text-end montant fw-bold">43.16 EUR</td>#', $fromagerie);
        // Un reçu dans la monnaie du foyer — ou étranger sans cours — n'a qu'une colonne de montant.
        $this->assertMatchesRegularExpression('#<th scope="col" class="text-end">Montant</th>\s*</tr>#', $html);
        $this->assertMatchesRegularExpression('#<td>Croissant</td>\s*<td class="text-end montant">2.00 EUR</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Souvenir</td>\s*<td class="text-end montant">10.00 USD</td>#', $html);
        // Sans cours, le souvenir reste en dollars : ni converti, ni additionné aux euros.
        $this->assertMatchesRegularExpression('#Boutique</td>\s*<td class="text-end montant">10.00 USD</td>#', $html);
        $this->assertMatchesRegularExpression('#Membrine</h3>\s*<span class="montant fw-bold text-end">45.16 EUR<br>10.00 USD</span>#', $html);
        $this->assertSame(1, substr_count($html, 'Cours de change :'));
    }

    /** Un cours se lit avec quatre décimales au moins, sans les zéros inutiles au-delà. */
    public function testLEcritureDUnCours(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $inde = $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Thali', '1000.00', 'INR']], 'Inde');
        self::$db->query("UPDATE BUDGET_SCAN SET COURS_CHANGE = 0.01052300 WHERE ID = $inde");
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('1 INR = 0.010523 CHF', $html);
        $this->assertStringContainsString('10.52 CHF', $html);
    }

    /**
     * PLUSIEURS FOYERS : le titre porte une liste de choix, rattachée au formulaire des filtres — en
     * changer garde les filtres —, qui remplace le champ caché du foyer.
     */
    public function testPlusieursFoyersDonnentUneListeDeChoixEtLeFoyerDemande(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $chalet = $this->creerFoyer('Chalet', [self::$membreId]);
        $etranger = $this->creerFoyer('Étranger', [self::$adminId]);
        $this->creerDepense($chalet, self::$membreId, '2026-10-02', [['Bois', '20.00']], 'Scierie');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget', 'foyer' => (string) $chalet]);

        $titre = (string) strstr((string) strstr($html, 'id="page-budget"'), '</h2>', true);
        $this->assertStringContainsString('Budget du foyer', $titre);
        $this->assertStringContainsString('name="foyer" form="form-filtres"', $titre);
        $this->assertStringContainsString('onchange="this.form.submit()"', $titre);
        $this->assertStringContainsString('<option value="' . $chalet . '" selected>Chalet</option>', $titre);
        $this->assertStringContainsString('<option value="' . $maison . '">Maison</option>', $titre);
        $formulaire = (string) strstr((string) strstr($html, 'id="form-filtres"'), '</form>', true);
        $this->assertNotSame('', $formulaire);
        $this->assertStringNotContainsString('name="foyer"', $formulaire);
        $this->assertStringContainsString('Scierie', $html);

        // UN FOYER DONT ON N'EST PAS MEMBRE N'EST PAS SERVI : le premier des siens l'est à sa place.
        $html = $this->requete(['action' => 'budget', 'foyer' => (string) $etranger]);
        $this->assertStringNotContainsString('Étranger', $html);
        $this->assertStringContainsString('<option value="' . $chalet . '" selected>Chalet</option>', $html);
    }

    /** Les raccourcis de période : mois précédent, mois courant, année courante, « Tout afficher ». */
    public function testLesRaccourcisDePeriode(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $base = '/?action=budget&amp;foyer=' . $foyerId;
        foreach ([
            'Septembre' => '&amp;doc_du=2026-09-01&amp;doc_au=2026-09-30',
            'Octobre' => '&amp;doc_du=2026-10-01&amp;doc_au=2026-10-31',
            'Année 2026' => '&amp;doc_du=2026-01-01&amp;doc_au=2026-12-31',
            'Tout afficher' => '&amp;doc_du=&amp;doc_au=',
        ] as $libelle => $bornes) {
            $this->assertStringContainsString(
                '<a class="lien-filtre" href="' . $base . $bornes . '&amp;saisie_du=&amp;saisie_au="><i class="fas fa-calendar-days" aria-hidden="true"></i> ' . $libelle . '</a>',
                $html
            );
        }
        $this->assertStringNotContainsString('Mois courant', $html);
    }

    /** EN JANVIER, le mois précédent est le décembre de l'année d'avant. */
    public function testEnJanvierLeMoisPrecedentEstDecembreDeLAnneeDAvant(): void
    {
        Utils::figerMaintenant(mktime(12, 0, 0, 1, 10, 2027));

        $periodes = BudgetController::periodes();

        $this->assertSame(['Décembre', 'Janvier', 'Année 2027', 'Tout afficher'], array_keys($periodes));
        $this->assertSame(['doc_du' => '2026-12-01', 'doc_au' => '2026-12-31', 'saisie_du' => '', 'saisie_au' => ''], $periodes['Décembre']);
    }

    /** « Tout afficher » : des filtres PRÉSENTS mais vides ne bornent rien — une date invalide non plus. */
    public function testDesFiltresVidesOuInvalidesNeBornentRien(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->creerDepense($foyerId, self::$membreId, '2025-01-15', [['Ancien', '1.00']], 'Ancienne');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget', 'doc_du' => '', 'doc_au' => '2026-02-31', 'saisie_du' => '', 'saisie_au' => '']);

        $this->assertStringContainsString('Ancienne', $html);
        $this->assertStringContainsString('name="doc_au" value=""', $html);
    }

    public function testLeFiltreDeSaisiePorteSurLaDateDEnregistrement(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $recente = $this->creerDepense($foyerId, self::$membreId, '2026-10-01', [['A', '1.00']], 'Recente');
        $ancienne = $this->creerDepense($foyerId, self::$membreId, '2026-10-01', [['B', '1.00']], 'Ancienne');
        self::$db->query("UPDATE BUDGET_SCAN SET CREATED_WHEN = '2026-09-01 10:00:00' WHERE ID = $ancienne");
        $aujourdhui = self::$db->query('SELECT DATE(CREATED_WHEN) FROM BUDGET_SCAN WHERE ID = ' . $recente)->fetch_row()[0];
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget', 'doc_du' => '', 'doc_au' => '', 'saisie_du' => '2026-09-01', 'saisie_au' => '2026-09-01']);
        $this->assertStringContainsString('Ancienne', $html);
        $this->assertStringNotContainsString('Recente', $html);

        $html = $this->requete(['action' => 'budget', 'doc_du' => '', 'doc_au' => '', 'saisie_du' => $aujourdhui, 'saisie_au' => '']);
        $this->assertStringContainsString('Recente', $html);
        $this->assertStringNotContainsString('Ancienne', $html);
    }

    public function testLeDetailEtLaSuppressionNeSontOffertsQuASonAuteur(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId, self::$adminId]);
        $propre = $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Pain', '3.50']], 'Boulangerie');
        $autre = $this->creerDepense($foyerId, self::$adminId, '2026-10-03', [], 'Kiosque');
        self::$db->query("UPDATE BUDGET_SCAN SET DESCRIPTION = 'Courses', LIEU = 'Genève', REFERENCE = 'JP_T210002', NUMERO_TVA = 'CHE-123' WHERE ID = $propre");
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('<strong>Description :</strong> Courses', $html);
        $this->assertStringContainsString('<strong>Lieu :</strong> Genève', $html);
        $this->assertStringContainsString('<strong>Référence :</strong> JP_T210002', $html);
        $this->assertStringContainsString('<strong>N° de TVA :</strong> CHE-123', $html);
        $this->assertStringContainsString('id="confirmer-depense-' . $propre . '"', $html);
        $this->assertStringNotContainsString('id="confirmer-depense-' . $autre . '"', $html);
        $this->assertStringContainsString('name="retour[doc_du]" value="2026-10-01"', $html);
        // Une dépense sans article n'a pas de total.
        $this->assertMatchesRegularExpression('#Kiosque</td>\s*<td class="text-end montant">—</td>#', $html);
    }

    public function testAucuneDepenseDansLaPeriode(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('data-role="aucune-depense"', $html);
        $this->assertStringNotContainsString('data-role="personnes"', $html);
    }

    /**
     * CHAQUE MEMBRE A SA CARTE, même sans dépense ; un ancien membre a la sienne s'il en a dans la
     * période. Les lignes vont de la plus récente à la plus ancienne, et chacune ouvre sa modale.
     */
    public function testUneCarteParPersonneEtUneModaleParDepense(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId, self::$adminId]);
        $ancien = $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Pain', '3.50']], 'Boulangerie');
        $recent = $this->creerDepense($foyerId, self::$membreId, '2026-10-09', [['Journal', '4.00']], 'Kiosque');
        $parti = $this->creerIdentite('partie@example.com', 'Partie');
        $this->creerPersonne($parti);
        $this->creerDepense($foyerId, $parti, '2026-10-05', [['Fleurs', '12.00']], 'Fleuriste');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertSame(3, substr_count($html, 'data-role="personne"'));
        $this->assertMatchesRegularExpression('#<th scope="col">Vendeur</th>\s*<th scope="col" class="text-end">Montant</th>#', $html);
        $carteAdmine = (string) strstr((string) strstr($html, 'data-personne="' . self::$adminId . '"'), 'data-role="personne"', true);
        $this->assertStringContainsString('<span class="montant fw-bold text-end">—</span>', $carteAdmine);
        $this->assertStringContainsString('Aucune dépense pour cette période.', $carteAdmine);
        $this->assertStringContainsString('Partie</h3>', $html);
        $this->assertLessThan(strpos($html, 'data-depense="' . $ancien . '"'), strpos($html, 'data-depense="' . $recent . '"'));

        $this->assertStringContainsString('data-bs-toggle="modal" data-bs-target="#detail-depense-' . $recent . '"', $html);
        $modale = (string) strstr($html, 'id="detail-depense-' . $recent . '"');
        $this->assertStringContainsString('class="modal-dialog modal-lg modal-dialog-scrollable"', $modale);
        $this->assertMatchesRegularExpression('#<h5 class="modal-title"[^>]*>\s*Kiosque — 09.10.2026\s*</h5>#', $modale);
        $this->assertMatchesRegularExpression('#<td>Journal</td>\s*<td class="text-end montant">\s*4.00 CHF#', $modale);
        $this->assertMatchesRegularExpression('#<div class="mention-discrete">Saisie le \d{2}\.\d{2}\.\d{4}</div>#', $modale);
    }

    /** Sans vendeur, le titre de la modale n'est que la date. */
    public function testLaModaleDUneDepenseSansVendeur(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $scanId = $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Pain', '3.50']], '');
        self::$db->query("UPDATE BUDGET_SCAN SET VENDEUR = NULL WHERE ID = $scanId");
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertMatchesRegularExpression('#id="detail-depense-' . $scanId . '-titre">\s*02.10.2026\s*</h5>#', $html);
    }

    /** DANS LES CINQ MINUTES, la dépense est EFFACÉE, articles compris ; on revient sur les mêmes filtres. */
    public function testSupprimerUneDepenseRecenteLEfface(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $scanId = $this->creerDepense($foyerId, self::$membreId, '2026-10-02');
        $this->connecter(self::$membreId);

        $this->requete(['action' => 'budget'], [
            'csrf_token' => $this->jetonCsrf(),
            'scan_id' => (string) $scanId,
            'foyer' => (string) $foyerId,
            'retour' => ['doc_du' => '', 'doc_au' => '2026-10-31', 'saisie_du' => '', 'saisie_au' => ''],
        ], 'POST');

        $this->assertSame(BudgetController::url($foyerId, ['doc_du' => '', 'doc_au' => '2026-10-31', 'saisie_du' => '', 'saisie_au' => '']), $this->redirection());
        $this->assertSame('La dépense est effacée.', Flash::prendre('succes'));
        $this->assertNull($this->statut($scanId));
        $this->assertSame('0', self::$db->query("SELECT COUNT(*) FROM BUDGET_SCAN_ARTICLE WHERE SCAN_ID = $scanId")->fetch_row()[0]);
        $entree = $this->journal()[0];
        $this->assertSame(Journal::TYPE_SUPPRESSION, $entree['TYPE']);
        $this->assertSame('Dépense effacée', $entree['DESCRIPTION']);
        $this->assertSame("Dépense n° : $scanId | Date : 2026-10-02T00:00 | Vendeur : Boulangerie", $entree['INFORMATIONS']);
    }

    /** AU-DELÀ DE CINQ MINUTES, elle est seulement MARQUÉE supprimée — et disparaît de la page. */
    public function testSupprimerUneDepenseAncienneLaMarqueSupprimee(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $scanId = $this->creerDepense($foyerId, self::$membreId, '2026-10-02', [['Pain', '3.50']], 'Boulangerie');
        $this->vieillir($scanId, 6);
        $this->connecter(self::$membreId);

        $this->requete(['action' => 'budget'], ['csrf_token' => $this->jetonCsrf(), 'scan_id' => (string) $scanId, 'foyer' => (string) $foyerId, 'retour' => 'x'], 'POST');

        $this->assertSame(BudgetController::url($foyerId, BudgetController::filtres([])), $this->redirection());
        $this->assertSame('La dépense est supprimée.', Flash::prendre('succes'));
        $this->assertSame('DELETED', $this->statut($scanId));
        $this->assertSame('Dépense supprimée', $this->journal()[0]['DESCRIPTION']);
        $this->assertStringNotContainsString('Boulangerie', $this->requete(['action' => 'budget']));
    }

    public function testOnNeSupprimeNiLaDepenseDUnAutreNiCelleDUnFoyerQuittE(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId, self::$adminId]);
        $autre = $this->creerDepense($foyerId, self::$adminId, '2026-10-02');
        $quitte = $this->creerFoyer('Ancien');
        $propreAilleurs = $this->creerDepense($quitte, self::$membreId, '2026-10-02');
        $this->connecter(self::$membreId);

        foreach ([$autre, $propreAilleurs] as $scanId) {
            $this->requete(['action' => 'budget'], ['csrf_token' => $this->jetonCsrf(), 'scan_id' => (string) $scanId], 'POST');

            $this->assertSame("Cette dépense est introuvable, ou ce n'est pas la vôtre.", Flash::prendre('erreur'));
            $this->assertSame('ACTIF', $this->statut($scanId));
        }
        $this->assertSame([], $this->journal());
    }

    public function testUnePanneDeLaBaseLaisseLaDepenseIntacte(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $scanId = $this->creerDepense($foyerId, self::$membreId, '2026-10-02');
        $this->vieillir($scanId, 10);
        $this->connecter(self::$membreId);

        $this->avecPrepareEnEchec('UPDATE BUDGET_SCAN', function () use ($scanId, $foyerId): void {
            $this->requete(['action' => 'budget'], ['csrf_token' => $this->jetonCsrf(), 'scan_id' => (string) $scanId, 'foyer' => (string) $foyerId], 'POST');
        });

        $this->assertSame('La suppression a échoué. Réessayez.', Flash::prendre('erreur'));
        $this->assertSame('ACTIF', $this->statut($scanId));
    }
}
