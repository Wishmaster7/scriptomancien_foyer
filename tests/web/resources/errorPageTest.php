<?php

declare(strict_types=1);

use Foyer\App\ErrorPage;

require_once __DIR__ . '/../TestBase.php';
require_once __DIR__ . '/../../../web/resources/errorPage.php';

/**
 * LES TROIS PAGES D'ERREUR, servies par Apache et non par le point d'entrée (ErrorDocument, cf.
 * web/.htaccess) : une 404 désigne précisément une URL qu'aucune action ne porte.
 *
 * Elles sont rendues dans l'habillage du site, ce qui est tout leur intérêt — une erreur qui sort
 * du site donne l'impression d'avoir quitté l'application. Le repli autonome de la 500 est
 * l'exception, et il est exercé ici aussi : c'est la seule réponse possible quand l'amorçage
 * lui-même manque.
 */
class ErrorPageTest extends TestBase
{
    protected function tearDown(): void
    {
        http_response_code(200);

        parent::tearDown();
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function pages(): array
    {
        return [
            '403' => [403, 'Accès refusé', 'code-erreur-primaire'],
            '404' => [404, 'Page non trouvée', 'code-erreur-primaire'],
            '500' => [500, 'Erreur interne du serveur', 'code-erreur-danger'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testChaquePageDErreurEstRendueDansLHabillageDuSite(
        int $code,
        string $sousTitre,
        string $classeCouleur
    ): void {
        ob_start();
        (new ErrorPage())->render($code);
        $html = (string) ob_get_clean();

        $this->assertSame($code, http_response_code());
        $this->assertStringContainsString('<div class="code-erreur ' . $classeCouleur . '">' . $code . '</div>', $html);
        $this->assertStringContainsString($sousTitre, $html);
        $this->assertStringContainsString('class="site-footer"', $html);
    }

    /**
     * LES TROIS FICHIERS D'ENTRÉE, joués tels qu'Apache les sert. Ils ne font rien d'autre que
     * nommer leur code, mais c'est justement ce qui doit être vérifié : un ErrorDocument qui
     * pointerait sur le mauvais fichier répondrait « 404 » à une permission refusée, et rien
     * ailleurs ne le dirait.
     *
     * `require` et non `require_once` : chaque fichier est joué une fois ici, et un `_once` le
     * rendrait muet si un autre test l'avait déjà chargé.
     *
     * @return array<string, array{int}>
     */
    public static function pagesDEntree(): array
    {
        return ['403' => [403], '404' => [404], '500' => [500]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pagesDEntree')]
    public function testLeFichierDEntreeRendSonPropreCode(int $code): void
    {
        ob_start();
        require __DIR__ . '/../../../web/' . $code . '.php';
        $html = (string) ob_get_clean();

        $this->assertSame($code, http_response_code());
        $this->assertStringContainsString('>' . $code . '</div>', $html);
    }

    /**
     * SANS AMORÇAGE, PLUS D'HABILLAGE : la 500 se rend alors seule, sans dépendre de la base ni
     * d'une feuille de style. C'est le cas où la panne est justement dans ce que la page voudrait
     * charger, et une page d'erreur qui échoue à s'afficher ne dit plus rien à personne.
     */
    public function testLaCinqCentsSeRendSeuleQuandLAmorcageManque(): void
    {
        ob_start();
        (new ErrorPage(__DIR__ . '/amorcage_absent.php'))->render(500);
        $html = (string) ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('<div class="code">500</div>', $html);
        $this->assertStringNotContainsString('site-footer', $html);
    }

    public function testUneExceptionPendantLHabillageRetombeSurLaPageAutonome(): void
    {
        $amorcage = tempnam(sys_get_temp_dir(), 'personnes-amorcage-');
        $this->assertNotFalse($amorcage);
        file_put_contents($amorcage, '<?php throw new RuntimeException("amorcage indisponible");');

        try {
            ob_start();
            (new ErrorPage($amorcage))->render(500);
            $html = (string) ob_get_clean();
        } finally {
            unlink($amorcage);
        }

        $this->assertStringContainsString('Erreur interne du serveur', $html);
        $this->assertStringNotContainsString('<nav', $html);
    }
}
