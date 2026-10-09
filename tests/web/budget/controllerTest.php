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
        $this->creerDepense($foyerId, self::$adminId, '2026-10-20', [['Essence', '50.00', 'EUR']], 'Total');
        $this->creerDepense($foyerId, self::$membreId, '2026-09-30', [['Vieux', '99.00']], 'Septembre');
        $this->creerDepense($foyerId, self::$membreId, '2026-10-05', [['Annulé', '7.00']], 'Supprimee', 'DELETED');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringNotContainsString('data-role="onglets-foyers"', $html);
        $this->assertStringContainsString('<h3 class="h5 mb-3">Maison</h3>', $html);
        $this->assertStringContainsString('name="doc_du" value="2026-10-01"', $html);
        $this->assertStringContainsString('name="doc_au" value="2026-10-31"', $html);
        $this->assertStringContainsString('Migros', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringNotContainsString('Septembre', $html);
        $this->assertStringNotContainsString('Supprimee', $html);
        $this->assertStringContainsString('1’236.50 CHF', $html);
        $this->assertStringContainsString('-1.00 CHF', $html);
        $this->assertStringContainsString('03.10.2026', $html);

        $totaux = (string) strstr($html, 'data-role="totaux"');
        $this->assertMatchesRegularExpression("#Admine</th>\s*<td[^>]*>50.00 EUR</td>#", $totaux);
        $this->assertMatchesRegularExpression("#Membrine</th>\s*<td[^>]*>1’236.50 CHF</td>#", $totaux);
        $this->assertStringContainsString('1’236.50 CHF<br>50.00 EUR', $totaux);
    }

    public function testPlusieursFoyersDonnentDesOngletsEtLeFoyerDemande(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $chalet = $this->creerFoyer('Chalet', [self::$membreId]);
        $etranger = $this->creerFoyer('Étranger', [self::$adminId]);
        $this->creerDepense($chalet, self::$membreId, '2026-10-02', [['Bois', '20.00']], 'Scierie');
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget', 'foyer' => (string) $chalet]);

        $this->assertStringContainsString('data-role="onglets-foyers"', $html);
        $this->assertMatchesRegularExpression('#class="nav-link active" aria-current="page" href="[^"]*foyer=' . $chalet . '[^"]*">Chalet</a>#', $html);
        $this->assertMatchesRegularExpression('#class="nav-link" href="[^"]*foyer=' . $maison . '&amp;doc_du=2026-10-01[^"]*">Maison</a>#', $html);
        $this->assertStringContainsString('Scierie', $html);

        // UN FOYER DONT ON N'EST PAS MEMBRE N'EST PAS SERVI : le premier des siens l'est à sa place.
        $html = $this->requete(['action' => 'budget', 'foyer' => (string) $etranger]);
        $this->assertStringNotContainsString('Étranger', $html);
        $this->assertMatchesRegularExpression('#class="nav-link active" aria-current="page" href="[^"]*">Chalet</a>#', $html);
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
        self::$db->query("UPDATE BUDGET_SCAN SET DESCRIPTION = 'Courses', LIEU = 'Genève', NUMERO_TVA = 'CHE-123' WHERE ID = $propre");
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('<strong>Description :</strong> Courses', $html);
        $this->assertStringContainsString('<strong>Lieu :</strong> Genève', $html);
        $this->assertStringContainsString('<strong>N° de TVA :</strong> CHE-123', $html);
        $this->assertStringContainsString('id="confirmer-depense-' . $propre . '"', $html);
        $this->assertStringNotContainsString('id="confirmer-depense-' . $autre . '"', $html);
        $this->assertStringContainsString('name="retour[doc_du]" value="2026-10-01"', $html);
        // Une dépense sans article n'a pas de total.
        $this->assertMatchesRegularExpression('#Kiosque</td>\s*<td>Admine</td>\s*<td class="text-end montant">—</td>#', $html);
    }

    public function testAucuneDepenseDansLaPeriode(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget']);

        $this->assertStringContainsString('data-role="aucune-depense"', $html);
        $this->assertStringNotContainsString('data-role="totaux"', $html);
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
        $this->assertSame("Dépense n° : $scanId | Date : 2026-10-02 | Vendeur : Boulangerie", $entree['INFORMATIONS']);
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
