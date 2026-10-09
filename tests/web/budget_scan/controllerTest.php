<?php

declare(strict_types=1);

use Foyer\App\AnalyseurTicket;
use Foyer\App\BudgetScanController;
use Foyer\App\CoursDeChange;
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
        CoursDeChange::substituerTransport(null);
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
            'date_document' => '2026-10-08T14:32',
            'reference' => ' JP_T210002/B ',
            'cours_change' => ' 0,95 ',
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
    private function analyser(mixed $texte = 'MIGROS Pain 3.50', string $methode = 'POST', mixed $langue = null): array
    {
        $post = $texte === null ? [] : ['texte' => $texte, 'csrf_token' => $this->jetonCsrf()];
        if ($langue !== null) {
            $post['langue'] = $langue;
        }

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
        $this->assertStringContainsString('<input type="hidden" name="foyer_id" value="' . $foyerId . '" data-monnaie="CHF">', $html);
        $this->assertStringNotContainsString('id="foyer-depense"', $html);
        $this->assertMatchesRegularExpression('#data-role="formulaire-depense" hidden>#', $html);
        $this->assertStringContainsString('<template data-role="modele-article"><tr data-role="ligne-article">', $html);
        $this->assertStringContainsString('name="articles[__INDEX__][nom]"', $html);
        $this->assertStringContainsString('data-tesseract-core="/resources/tesseract/7.0.0/core"', $html);
        $this->assertStringContainsString('data-monnaie-defaut="' . SiteConfig::MONNAIE_DEFAUT . '"', $html);
        $this->assertStringNotContainsString('data-seconde-lecture', $html);
        // LA LIGNE DU COURS : cachée jusqu'à ce qu'un reçu étranger la montre ; la monnaie du reçu est un champ
        // sans nom, au format des articles ; l'icône ouvre la source dans une autre fenêtre.
        $this->assertStringContainsString('data-cours-url="' . ADRESSE_COURS_DE_TEST . '"', $html);
        $this->assertStringContainsString('data-role="ligne-cours" hidden>', $html);
        $this->assertStringContainsString('<input type="text" class="form-control form-control-sm text-uppercase champ-monnaie" data-role="cours-monnaie-recu" maxlength="3"', $html);
        $this->assertStringContainsString('class="form-control champ-cours" id="cours-change" name="cours_change"', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer" data-role="source-cours" aria-label="Source du cours de change">', $html);
        // L'INFOBULLE EST CELLE DE BOOTSTRAP, qui exige Popper : servi par le site, et chargé AVANT bootstrap.min.js.
        $this->assertFileExists(__DIR__ . '/../../../web/resources/js/popper.min.js');
        $this->assertLessThan((int) strpos($html, '<script src="/resources/js/bootstrap.min.js"></script>'), (int) strpos($html, '<script src="/resources/js/popper.min.js"></script>'));
        $this->assertStringContainsString('<script src="/resources/js/popper.min.js"></script>', $html);
        $this->assertStringContainsString('<i class="fas fa-circle-info" aria-hidden="true"></i>', $html);
        $this->assertStringNotContainsString('aide-cours-change', $html);
        // LES DEUX TOTAUX en pied des articles, et le message d'écart, caché, entre eux ; plus d'alerte au-dessus.
        $this->assertMatchesRegularExpression('#<tfoot>\s*<tr data-role="ligne-total-calcule">.*Total calculé.*data-role="ecart-total" hidden>.*text-danger small.*Total affiché.*</tfoot>#s', $html);
        $this->assertStringNotContainsString('alerte-total', $html);
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
        $this->assertStringContainsString('<option value="' . $maison . '" data-monnaie="CHF">Maison (CHF)</option>', $html);
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
        $this->assertSame('2026-10-08 14:32:00', $depense['DATE_DOCUMENT']);
        $this->assertSame('Migros', $depense['VENDEUR']);
        $this->assertSame('JP_T210002/B', $depense['REFERENCE']);
        // Le pain en CHF, le bon en EUR : le cours saisi convertit les euros en francs du foyer.
        $this->assertSame('0.95000000', $depense['COURS_CHANGE']);
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
            'Dépense n° : ' . $depense['ID'] . ' | Foyer : Maison | Date : 2026-10-08T14:32 | Vendeur : Migros | Articles : 2 | Cours de change : 0.95000000',
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
        $this->assertStringContainsString('<option value="' . $chalet . '" data-monnaie="CHF" selected>Chalet (CHF)</option>', $html);
        $this->assertStringContainsString('value="  Migros  "', $html);
        $this->assertStringContainsString('name="reference" maxlength="' . AnalyseurTicket::REFERENCE_MAX . '" value=" JP_T210002/B "', $html);
        $this->assertStringContainsString('name="articles[0][retenu]" value="1" checked', $html);
        $this->assertStringContainsString('name="articles[1][retenu]" value="1" aria-label', $html);
        $this->assertStringContainsString('name="articles[1][montant]" inputmode="decimal" value="n/a"', $html);
    }

    /**
     * Installe un service des cours qui rend `$cours` (null : une panne), et note chaque adresse demandée.
     *
     * @param list<string> $demandes
     */
    private function servirCours(?float $cours, ?array &$demandes = null): void
    {
        $demandes = [];
        CoursDeChange::substituerTransport(static function (string $url) use ($cours, &$demandes): array {
            $demandes[] = $url;

            return $cours === null ? ['statut' => 503, 'corps' => ''] : ['statut' => 200, 'corps' => (string) json_encode(['rates' => ['CHF' => $cours]])];
        });
    }

    /** @return array<string, mixed>|null */
    private function derniereDepense(): ?array
    {
        return self::$db->query('SELECT * FROM BUDGET_SCAN ORDER BY ID DESC LIMIT 1')->fetch_assoc();
    }

    /**
     * LE COURS DE CHANGE ENREGISTRÉ : laissé vide, celui de la BCE au jour du reçu ; saisi, celui de la
     * personne ; aucun pour une dépense dans la monnaie du foyer, même s'il est saisi.
     */
    public function testLeCoursDeChangeALEnregistrement(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        $this->servirCours(0.9412, $demandes);

        $this->enregistrer($this->saisie($foyerId, ['cours_change' => '  ']));

        $this->assertSame('/?action=budget&foyer=' . $foyerId, $this->redirection());
        $this->assertSame('0.94120000', $this->derniereDepense()['COURS_CHANGE']);
        $this->assertSame([ADRESSE_COURS_DE_TEST . '/2026-10-08?base=EUR&symbols=CHF'], $demandes);

        $this->enregistrer($this->saisie($foyerId, ['cours_change' => '1.25']));
        $this->assertSame('1.25000000', $this->derniereDepense()['COURS_CHANGE']);
        $this->assertCount(1, $demandes, 'Un cours saisi ne se redemande pas.');

        $this->enregistrer($this->saisie($foyerId, ['cours_change' => '0.5', 'articles' => [['retenu' => '1', 'nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'CHF']]]));
        $this->assertNull($this->derniereDepense()['COURS_CHANGE']);
    }

    /** UN COURS QUI MANQUE OU NE SE LIT PAS, deux monnaies étrangères : la dépense est refusée, rien n'est écrit. */
    public function testLesRefusDuCoursDeChange(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        $this->servirCours(null);

        $this->enregistrer($this->saisie($foyerId, ['cours_change' => '']));
        $this->assertSame('/?action=budget_scan', $this->redirection());
        $this->assertSame("Le cours EUR → CHF au 08.10.2026 n'a pas pu être obtenu : saisissez-le (nombre de CHF pour 1 EUR).", Flash::prendre('erreur'));

        foreach (['abc', '0', '-1', '1.123456789'] as $cours) {
            $this->enregistrer($this->saisie($foyerId, ['cours_change' => $cours]));
            $this->assertSame('Le cours de change doit être un nombre positif, par exemple 0.9412.', Flash::prendre('erreur'), $cours);
        }

        $this->enregistrer($this->saisie($foyerId, ['articles' => [
            ['retenu' => '1', 'nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'EUR'],
            ['retenu' => '1', 'nom' => 'Thé', 'montant' => '2.00', 'monnaie' => 'USD'],
        ]]));
        $this->assertSame("Une dépense ne peut porter qu'une monnaie en plus de celle du foyer (CHF).", Flash::prendre('erreur'));
        $this->assertSame(0, $this->nombreDeDepenses());

        // La saisie refusée rouvre le formulaire, cours compris.
        $this->enregistrer($this->saisie($foyerId, ['cours_change' => 'abc']));
        $this->assertStringContainsString('name="cours_change" inputmode="decimal" value="abc"', $this->requete(['action' => 'budget_scan']));
    }

    /**
     * L'ANALYSE PROPOSE LE COURS pour le foyer choisi : celui de la BCE au jour du reçu, quand ses
     * articles sont dans UNE autre monnaie que celle du foyer. Sans foyer des siens, sans date, ou
     * sans réponse du service : des champs vides.
     */
    public function testLAnalyseProposeLeCoursDeChange(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $etranger = $this->creerFoyer('Étranger', [self::$adminId]);
        $this->connecter(self::$membreId);
        $this->configurer();
        $reponseLlm = null;
        AnalyseurTicket::substituerTransport(static function () use (&$reponseLlm): array {
            return ['statut' => 200, 'corps' => (string) json_encode(['choices' => [['message' => ['content' => $reponseLlm]]]])];
        });
        $this->servirCours(0.9412, $demandes);
        $analyser = function (string $date, string $monnaie, string $foyer) use (&$reponseLlm): array {
            $reponseLlm = (string) json_encode(['date_document' => $date, 'articles' => [['nom' => 'Pain', 'montant' => 3.5, 'monnaie' => $monnaie]]]);
            $reponse = (array) json_decode($this->requete(['action' => 'budget_scan_analyser'], ['texte' => 'PAIN 3,50', 'foyer_id' => $foyer, 'csrf_token' => $this->jetonCsrf()], 'POST'), true);

            return array_intersect_key($reponse['donnees'], array_flip(['monnaie_foyer', 'monnaie_recu', 'cours_change']));
        };

        $this->assertSame(['monnaie_foyer' => 'CHF', 'monnaie_recu' => 'EUR', 'cours_change' => '0.94120000'], $analyser('2026-10-08T14:32', 'EUR', (string) $maison));
        $this->assertSame([ADRESSE_COURS_DE_TEST . '/2026-10-08?base=EUR&symbols=CHF'], $demandes);

        $this->assertSame(['monnaie_foyer' => 'CHF', 'monnaie_recu' => '', 'cours_change' => ''], $analyser('2026-10-08T14:32', 'CHF', (string) $maison));
        $this->assertSame(['monnaie_foyer' => 'CHF', 'monnaie_recu' => 'EUR', 'cours_change' => ''], $analyser('', 'EUR', (string) $maison));
        $vide = ['monnaie_foyer' => '', 'monnaie_recu' => '', 'cours_change' => ''];
        $this->assertSame($vide, $analyser('2026-10-08', 'EUR', (string) $etranger));
        $this->assertSame($vide, $analyser('2026-10-08', 'EUR', '0'));
        $this->assertCount(1, $demandes);
    }

    public function testLesRefusDeValidation(): void
    {
        $foyerId = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);

        $this->enregistrer($this->saisie($foyerId, ['date_document' => '2026-02-30']));
        $this->assertSame('La date du document est obligatoire, et doit être une date et une heure valides.', Flash::prendre('erreur'));

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

        $reponse = $this->analyser('  ' . str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE + 10) . '  ', 'POST', 'deu');

        $this->assertTrue($reponse['success']);
        // HORS MODE DEBUG, la réponse ne porte que ceci — aucun diagnostic.
        $this->assertSame(['success', 'donnees'], array_keys($reponse));
        $this->assertSame('Migros', $reponse['donnees']['vendeur']);
        $this->assertSame([['nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'CHF', 'a_verifier' => false]], $reponse['donnees']['articles']);
        // LA LANGUE DU DRAPEAU est dite au modèle, avant le texte.
        $this->assertStringStartsWith("Langue du ticket : allemand.\nTexte OCR :", $recu['messages'][1]['content']);
        $this->assertStringContainsString(str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE) . "\n\"\"\"", $recu['messages'][1]['content']);
        $this->assertStringNotContainsString(str_repeat('a', AnalyseurTicket::LONGUEUR_MAX_TEXTE + 1), $recu['messages'][1]['content']);

        $entree = $this->journal()[0];
        $this->assertSame(Journal::TYPE_ANALYSE, $entree['TYPE']);
        $this->assertSame('Reçu analysé', $entree['DESCRIPTION']);
        $this->assertSame(
            'Caractères : ' . AnalyseurTicket::LONGUEUR_MAX_TEXTE . ' | Modèle : ' . SiteConfig::INFOMANIAK_MODELE . ' | Jetons : 800 en entrée, 60 en sortie',
            $entree['INFORMATIONS']
        );

        // Une langue inconnue, ou qui n'est pas un texte, n'est pas dite.
        foreach (['klingon', ['deu']] as $langue) {
            $this->analyser('MIGROS', 'POST', $langue);
            $this->assertStringStartsWith('Texte OCR :', $recu['messages'][1]['content']);
        }
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

        $this->assertSame(['success' => false, 'message' => AnalyseurTicket::MESSAGE_INDISPONIBLE], $this->analyser());
        $entree = $this->journal()[0];
        $this->assertSame('Analyse de reçu échouée', $entree['DESCRIPTION']);
        $this->assertSame('Caractères : 16 | Modèle : ' . SiteConfig::INFOMANIAK_MODELE, $entree['INFORMATIONS']);
    }

    /** EN MODE DEBUG, le message d'échec porte le récit complet de ce qui s'est passé. */
    public function testEnModeDebugLEchecEstDetaille(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        putenv('DEBUG_MODE=true');

        try {
            $this->assertSame(
                AnalyseurTicket::MESSAGE_NON_CONFIGURE . "\n\n[DEBUG_MODE]\nVariable(s) d'environnement vide(s) ou absente(s) pour PHP : INFOMANIAK_AI_TOKEN, INFOMANIAK_AI_PRODUCT_ID.",
                $this->analyser()['message']
            );

            $this->configurer();
            AnalyseurTicket::substituerTransport(static fn (): array => ['statut' => 401, 'corps' => '{"result":"error","error":{"code":"not_authorized"}}']);
            $message = $this->analyser()['message'];

            $this->assertStringStartsWith(AnalyseurTicket::MESSAGE_INDISPONIBLE . "\n\n[DEBUG_MODE]\nAppel 1 : POST http://127.0.0.1:9/2/ai/42/", $message);
            $this->assertStringContainsString('Réponse : HTTP 401', $message);
            $this->assertStringContainsString('"code":"not_authorized"', $message);
        } finally {
            putenv('DEBUG_MODE');
        }
    }

    /**
     * EN MODE DEBUG, la réponse porte aussi le diagnostic : le texte reçu, le prompt envoyé, puis les
     * appels, la réponse brute du modèle, ce qu'il proposait avant validation, les jetons, le contrôle
     * du total et le quota.
     */
    public function testEnModeDebugLaReponsePorteLeDiagnostic(): void
    {
        $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$membreId);
        $this->configurer();
        $contenu = '{"numero_tva": "T2005040304", "total_imprime": 128, "articles": [{"nom": "Kayak", "montant": 64, "monnaie": "EUR"}, {"nom": "Canoë", "montant": 28, "monnaie": "EUR"}]}';
        AnalyseurTicket::substituerTransport(static fn (): array => ['statut' => 200, 'corps' => (string) json_encode([
            'choices' => [['message' => ['content' => $contenu]]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 70],
        ])]);
        putenv('DEBUG_MODE=true');

        try {
            $reponse = $this->analyser("KAYAK x2 32,00\n");
            $echec = $this->analyserEnEchec();
        } finally {
            putenv('DEBUG_MODE');
        }

        $this->assertSame(['success', 'donnees', 'input', 'prompt', 'debug'], array_keys($reponse));
        $this->assertSame("KAYAK x2 32,00\n", $reponse['input']);
        $this->assertSame(AnalyseurTicket::consigne(), $reponse['prompt']['system']);
        $this->assertSame("Texte OCR :\n\"\"\"\nKAYAK x2 32,00\n\"\"\"", $reponse['prompt']['user']);
        $debug = $reponse['debug'];
        $this->assertNull($debug['texte_tronque_a']);
        $this->assertSame(SiteConfig::INFOMANIAK_MODELE, $debug['parametres']['modele']);
        $this->assertSame(AnalyseurTicket::schema(), $debug['parametres']['schema_json']);
        $this->assertCount(1, $debug['appels']);
        $this->assertSame(200, $debug['appels'][0]['statut']);
        $this->assertTrue($debug['appels'][0]['sortie_structuree']);
        $this->assertIsInt($debug['appels'][0]['duree_ms']);
        $this->assertSame($contenu, $debug['reponse_llm']);
        // Ce que le modèle proposait, et ce que la validation en a fait : le n° sans forme de TVA est écarté.
        $this->assertSame('T2005040304', $debug['donnees_llm']['numero_tva']);
        $this->assertSame('', $reponse['donnees']['numero_tva']);
        $this->assertSame(['entree' => 900, 'sortie' => 70], $debug['jetons']);
        $this->assertSame(['somme_articles' => ['EUR' => '92.00'], 'total_imprime' => '128.00'], $debug['controle_total']);
        $this->assertSame('1 / ' . SiteConfig::ANALYSES_PAR_HEURE, $debug['analyses_cette_heure']);

        // UN ÉCHEC porte le même diagnostic, sans ce qui n'a pas existé.
        $this->assertSame(['success', 'message', 'input', 'prompt', 'debug'], array_keys($echec));
        $this->assertSame(str_repeat('b', AnalyseurTicket::LONGUEUR_MAX_TEXTE), mb_substr((string) $echec['input'], 0, AnalyseurTicket::LONGUEUR_MAX_TEXTE));
        $this->assertSame(AnalyseurTicket::LONGUEUR_MAX_TEXTE, $echec['debug']['texte_tronque_a']);
        $this->assertSame(503, $echec['debug']['appels'][0]['statut']);
        $this->assertNull($echec['debug']['reponse_llm']);
        $this->assertArrayNotHasKey('jetons', $echec['debug']);
        $this->assertArrayNotHasKey('controle_total', $echec['debug']);
    }

    /** Une analyse au texte trop long, que l'API refuse (503). @return array<string, mixed> */
    private function analyserEnEchec(): array
    {
        AnalyseurTicket::substituerTransport(static fn (): array => ['statut' => 503, 'corps' => 'indisponible']);

        return $this->analyser(str_repeat('b', AnalyseurTicket::LONGUEUR_MAX_TEXTE + 5));
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
