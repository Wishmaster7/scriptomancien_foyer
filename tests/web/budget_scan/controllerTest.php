<?php

declare(strict_types=1);

use Foyer\App\AnalyseurTicket;
use Foyer\App\BudgetScanController;
use Foyer\App\Flash;
use Foyer\App\Journal;
use Foyer\App\SiteConfig;

require_once __DIR__ . '/../TestBase.php';

/**
 * « Scanner un reçu » : la page, l'enregistrement des lignes cochées, et le point d'appel AJAX qui
 * fait structurer le texte OCR par le LLM.
 */
class BudgetScanControllerTest extends TestBase
{
    protected function tearDown(): void
    {
        putenv('INFOMANIAK_AI_TOKEN');
        putenv('INFOMANIAK_AI_PRODUCT_ID');
        AnalyseurTicket::substituerTransport(null);
        http_response_code(200);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed> $champs
     * @return array<string, mixed>
     */
    private function saisie(int $foyerId, array $champs = []): array
    {
        return $champs + [
            'csrf_token' => $this->jetonCsrf(),
            'foyer_id' => (string) $foyerId,
            'date_document' => '2026-10-08',
            'vendeur' => '  Migros  ',
            'lieu' => 'Genève',
            'numero_tva' => '',
            'description' => 'Courses',
            'articles' => [
                ['retenu' => '1', 'nom' => 'Pain', 'montant' => '3,50', 'monnaie' => 'chf'],
                ['nom' => 'Sac', 'montant' => 'n/a', 'monnaie' => ''],
                ['retenu' => '1', 'nom' => 'Bon', 'montant' => '-1', 'monnaie' => 'EUR'],
            ],
        ];
    }

    /** @param array<string, mixed> $champs */
    private function enregistrer(array $champs): void
    {
        $this->requete(['action' => 'budget_scan'], $champs, 'POST');
    }

    /** @return array<string, mixed> */
    private function analyser(mixed $texte = 'MIGROS Pain 3.50', string $methode = 'POST'): array
    {
        $post = $texte === null ? [] : ['texte' => $texte, 'csrf_token' => $this->jetonCsrf()];

        return (array) json_decode($this->requete(['action' => 'budget_scan_analyser'], $post, $methode), true);
    }

    private function configurer(): void
    {
        putenv('INFOMANIAK_AI_TOKEN=jeton');
        putenv('INFOMANIAK_AI_PRODUCT_ID=42');
    }

    private function nombreDeDepenses(): int
    {
        return (int) self::$db->query('SELECT COUNT(*) FROM BUDGET_SCAN')->fetch_row()[0];
    }

    public function testSansFoyerNiZoneDeDepotNiMoteur(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget_scan']);

        $this->assertStringContainsString('id="page-budget-scan"', $html);
        $this->assertStringContainsString('data-role="sans-foyer"', $html);
        $this->assertStringNotContainsString('data-role="zone-depot"', $html);
        $this->assertStringNotContainsString('tesseract.min.js', $html);
    }

    /** UN SEUL FOYER : un champ caché, pas de liste. Le formulaire attend l'analyse ; tout est servi par le site. */
    public function testAvecUnFoyerLaPageEstPreteAScanner(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget_scan']);

        $this->assertStringContainsString('data-role="zone-depot"', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('<input type="hidden" name="foyer_id" value="' . $foyerId . '">', $html);
        $this->assertStringNotContainsString('id="foyer-depense"', $html);
        $this->assertMatchesRegularExpression('#data-role="formulaire-depense" hidden>#', $html);
        $this->assertStringContainsString('<template data-role="modele-article"><tr data-role="ligne-article">', $html);
        $this->assertStringContainsString('name="articles[__INDEX__][nom]"', $html);
        $this->assertStringContainsString('data-tesseract-core="/resources/tesseract/7.0.0/core"', $html);
        $this->assertStringContainsString('data-monnaie-defaut="' . SiteConfig::MONNAIE_DEFAUT . '"', $html);
        $this->assertStringContainsString('<script src="/resources/tesseract/7.0.0/tesseract.min.js"></script>', $html);
        $this->assertMatchesRegularExpression('#<script src="/resources/js/budget-scan\.js\?v=\d+"></script>#', $html);
        $this->assertStringContainsString('Chargement en cours...', $html);
        $this->assertDoesNotMatchRegularExpression('#<(link|script)\b[^>]*\b(href|src)="(https?:)?//#i', $html);
        // Les drapeaux, dans l'ordre, le français seul enfoncé ; chaque langue a son modèle et son image.
        $this->assertMatchesRegularExpression('#data-langue="fra" aria-pressed="true".*data-langue="eng" aria-pressed="false".*data-langue="deu".*data-langue="spa".*data-langue="ita"#s', $html);
        $this->assertStringContainsString('<img src="/resources/images/drapeau-francais.png" alt="Français" width="52" height="35">', $html);
        foreach (BudgetScanController::LANGUES as $code => [, $image]) {
            $this->assertFileExists(__DIR__ . '/../../../web/resources/tesseract/7.0.0/lang/4.1.0_best/' . $code . '.traineddata.gz');
            $this->assertFileExists(__DIR__ . '/../../../web/resources/images/' . $image);
        }
        $this->assertStringContainsString('data-tesseract-langues="/resources/tesseract/7.0.0/lang/4.1.0_best"', $html);
        $this->assertFileExists(__DIR__ . '/../../../web/resources/tesseract/7.0.0/lang/4.1.0_best/fra.traineddata.gz');
        $this->assertFileExists(__DIR__ . '/../../../web/resources/tesseract/7.0.0/core/tesseract-core-lstm.wasm.js');
        $this->assertFileExists(__DIR__ . '/../../../web/resources/tesseract/7.0.0/core/tesseract-core-simd-lstm.wasm.js');
    }

    public function testAvecPlusieursFoyersUneListe(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $this->creerFoyer('Chalet', [self::$membreId]);
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'budget_scan']);

        $this->assertStringContainsString('id="foyer-depense"', $html);
        $this->assertStringContainsString('<option value="' . $maison . '">Maison</option>', $html);
    }

    /**
     * SEULES LES LIGNES COCHÉES SONT ENREGISTRÉES, normalisées ; un champ vide devient NULL. La dépense
     * est journalisée, et l'on rejoint son foyer dans Budget.
     */
    public function testEnregistrerLesLignesCochees(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $this->enregistrer($this->saisie($foyerId));

        $this->assertSame('/?action=budget&foyer=' . $foyerId, $this->redirection());
        $this->assertSame('Dépense enregistrée : 2 article(s).', Flash::prendre('succes'));
        $depense = self::$db->query('SELECT * FROM BUDGET_SCAN')->fetch_assoc();
        $this->assertSame('2026-10-08', $depense['DATE_DOCUMENT']);
        $this->assertSame('Migros', $depense['VENDEUR']);
        $this->assertNull($depense['NUMERO_TVA']);
        $this->assertSame('ACTIF', $depense['STATUT']);
        $this->assertSame(self::$membreId, (int) $depense['PERSONNE_ID']);
        $this->assertSame(
            [['Pain', '3.50', 'CHF'], ['Bon', '-1.00', 'EUR']],
            self::$db->query('SELECT NOM, MONTANT, MONNAIE FROM BUDGET_SCAN_ARTICLE ORDER BY ID')->fetch_all()
        );
        $entree = $this->journal()[0];
        $this->assertSame(Journal::TYPE_CREATION, $entree['TYPE']);
        $this->assertSame(
            'Dépense n° : ' . $depense['ID'] . ' | Foyer : Maison | Date : 2026-10-08 | Vendeur : Migros | Articles : 2',
            $entree['INFORMATIONS']
        );
        // La saisie réussie ne ressort pas au prochain affichage.
        $this->assertMatchesRegularExpression('#data-role="formulaire-depense" hidden>#', $this->requete(['action' => 'budget_scan']));
    }

    /** UN REFUS ROUVRE LE FORMULAIRE sur la saisie postée, lignes décochées comprises — et n'écrit rien. */
    public function testUnFoyerQuiNEstPasLeSienEstRefuseEtLaSaisieRendue(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $chalet = $this->creerFoyer('Chalet', [self::$membreId]);
        $etranger = $this->creerFoyer('Étranger', [self::$adminId]);
        $this->connecter(self::$membreId);

        foreach ([(string) $etranger, '0'] as $foyer) {
            $this->enregistrer($this->saisie(0, ['foyer_id' => $foyer]));

            $this->assertSame('/?action=budget_scan', $this->redirection());
            $this->assertSame("Ce foyer n'est pas l'un des vôtres.", Flash::prendre('erreur'));
        }
        $this->assertSame(0, $this->nombreDeDepenses());

        $this->enregistrer($this->saisie($chalet, ['date_document' => '']));
        $html = $this->requete(['action' => 'budget_scan']);

        $this->assertMatchesRegularExpression('#data-role="formulaire-depense">#', $html);
        $this->assertStringContainsString('<option value="' . $chalet . '" selected>Chalet</option>', $html);
        $this->assertStringContainsString('value="  Migros  "', $html);
        $this->assertStringContainsString('name="articles[0][retenu]" value="1" checked', $html);
        $this->assertStringContainsString('name="articles[1][retenu]" value="1" aria-label', $html);
        $this->assertStringContainsString('name="articles[1][montant]" inputmode="decimal" value="n/a"', $html);
    }

    public function testLesRefusDeValidation(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $this->enregistrer($this->saisie($foyerId, ['date_document' => '2026-02-30']));
        $this->assertSame('La date du document est obligatoire, et doit être une date valide.', Flash::prendre('erreur'));

        foreach ([
            ['nom' => '', 'montant' => '1', 'monnaie' => 'CHF'],
            ['nom' => 'X', 'montant' => 'abc', 'monnaie' => 'CHF'],
            ['nom' => 'X', 'montant' => '1', 'monnaie' => 'FRANCS'],
        ] as $ligne) {
            $this->enregistrer($this->saisie($foyerId, ['articles' => [
                ['retenu' => '1', 'nom' => 'Pain', 'montant' => '1', 'monnaie' => 'CHF'],
                ['retenu' => '1'] + $ligne,
            ]]));
            $this->assertStringStartsWith("L'article n° 2 est incomplet", Flash::prendre('erreur'));
        }

        // Aucune ligne cochée, des lignes qui ne sont pas des tableaux, des valeurs qui ne sont pas des chaînes.
        foreach ([[['nom' => 'Pain', 'montant' => '1', 'monnaie' => 'CHF']], ['pas une ligne'], 'pas un tableau'] as $articles) {
            $this->enregistrer($this->saisie($foyerId, ['articles' => $articles, 'vendeur' => ['tableau']]));
            $this->assertSame('Cochez au moins un article à enregistrer.', Flash::prendre('erreur'));
        }

        $this->assertSame(0, $this->nombreDeDepenses());
        $this->assertSame([], $this->journal());
    }

    /** UNE PANNE AU MILIEU DE L'ÉCRITURE NE LAISSE RIEN : la dépense et ses articles sont dans une transaction. */
    public function testUnePanneDeLaBaseNeLaisseAucuneDepenseOrpheline(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $this->avecExecuteEnEchec('INSERT INTO BUDGET_SCAN_ARTICLE', function () use ($foyerId): void {
            $this->enregistrer($this->saisie($foyerId));
        });

        $this->assertSame("L'enregistrement a échoué. Réessayez.", Flash::prendre('erreur'));
        $this->assertSame(0, $this->nombreDeDepenses());
    }

    public function testLAnalyseRefuseUnGetUnSansFoyerEtUnTexteVide(): void
    {
        $this->connecter(self::$membreId);

        $this->assertSame(['success' => false, 'message' => 'Méthode non autorisée.'], $this->analyser(null, 'GET'));
        $this->assertSame(405, http_response_code());

        $this->assertSame("Vous n'êtes membre d'aucun foyer.", $this->analyser()['message']);
        $this->assertSame(403, http_response_code());

        $this->creerFoyer('Maison', [self::$membreId]);
        foreach (['   ', ['tableau']] as $texte) {
            $this->assertStringStartsWith("Aucun texte n'a été reconnu", $this->analyser($texte)['message']);
            $this->assertSame(422, http_response_code());
        }
    }

    public function testLAnalyseRendLaPropositionEtLaJournaliseSansLeTexte(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        $this->configurer();
        $recu = null;
        AnalyseurTicket::substituerTransport(static function (string $url, array $entetes, string $corps) use (&$recu): array {
            $recu = json_decode($corps, true);

            return ['statut' => 200, 'corps' => (string) json_encode([
                'choices' => [['message' => ['content' => '{"vendeur": "Migros", "articles": [{"nom": "Pain", "montant": 3.5, "monnaie": "CHF"}]}']]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 60],
            ])];
        });

        $reponse = $this->analyser('  ' . str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE + 10) . '  ');

        $this->assertTrue($reponse['success']);
        $this->assertSame('Migros', $reponse['donnees']['vendeur']);
        $this->assertSame([['nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'CHF']], $reponse['donnees']['articles']);
        $this->assertStringContainsString(str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE) . "\n\"\"\"", $recu['messages'][1]['content']);
        $this->assertStringNotContainsString(str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE + 1), $recu['messages'][1]['content']);

        $entree = $this->journal()[0];
        $this->assertSame(Journal::TYPE_ANALYSE, $entree['TYPE']);
        $this->assertSame('Reçu analysé', $entree['DESCRIPTION']);
        $this->assertSame(
            'Caractères : ' . AnalyseurTicket::LONGUEUR_MAX_TEXTE . ' | Modèle : ' . SiteConfig::INFOMANIAK_MODELE . ' | Jetons : 800 en entrée, 60 en sortie',
            $entree['INFORMATIONS']
        );
    }

    /** UN ÉCHEC FACTURÉ EST JOURNALISÉ (il compte dans le quota) ; un service non configuré, non. */
    public function testLesEchecsDAnalyse(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $this->assertSame(AnalyseurTicket::MESSAGE_NON_CONFIGURE, $this->analyser()['message']);
        $this->assertSame(502, http_response_code());
        $this->assertSame([], $this->journal());

        $this->configurer();
        AnalyseurTicket::substituerTransport(static fn (): array => ['statut' => 503, 'corps' => '']);

        $this->assertSame(AnalyseurTicket::MESSAGE_INDISPONIBLE, $this->analyser()['message']);
        $entree = $this->journal()[0];
        $this->assertSame('Analyse de reçu échouée', $entree['DESCRIPTION']);
        $this->assertSame('Caractères : 16 | Modèle : ' . SiteConfig::INFOMANIAK_MODELE, $entree['INFORMATIONS']);
    }

    public function testLeQuotaHoraireArreteLesAnalyses(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        $this->configurer();
        $appels = 0;
        AnalyseurTicket::substituerTransport(static function () use (&$appels): array {
            $appels++;

            return ['statut' => 503, 'corps' => ''];
        });
        // Une analyse d'il y a plus d'une heure ne compte plus.
        Journal::ecrire(Journal::TYPE_ANALYSE, 'Reçu analysé', self::$membreId);
        self::$db->query('UPDATE LOGS SET CREATED_WHEN = NOW() - INTERVAL 61 MINUTE');
        for ($i = 1; $i < SiteConfig::ANALYSES_PAR_HEURE; $i++) {
            Journal::ecrire(Journal::TYPE_ANALYSE, 'Reçu analysé', self::$membreId);
        }

        $this->analyser();
        $this->assertSame(1, $appels);

        $reponse = $this->analyser();
        $this->assertSame(1, $appels);
        $this->assertSame(429, http_response_code());
        $this->assertSame('Vous avez atteint la limite de 30 analyses par heure. Réessayez plus tard, ou saisissez le reçu à la main.', $reponse['message']);
    }
}
