<?php

declare(strict_types=1);

use Foyer\App\Compte;
use Foyer\App\Database;
use Foyer\App\Utils;

require_once __DIR__ . '/../TestBase.php';

/**
 * TOUTE PANNE REND LA PAGE 500 DU SITE — celle qu'Apache sert (web/500.php) —, et jamais un texte
 * nu ni une page blanche.
 *
 * Chaque cas est joué à travers le point d'entrée, et l'exception qui en sort est remise au
 * gestionnaire global, exactement comme PHP le ferait : PHPUnit ne le laisse pas agir lui-même.
 */
class PannesTest extends TestBase
{
    protected function tearDown(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        putenv('DB_HOSTNAME=127.0.0.1');
        putenv('DEBUG_MODE');
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        Database::setConnection(self::$db);
        http_response_code(200);

        parent::tearDown();
    }

    /**
     * Joue une requête GET à travers le point d'entrée ; ce qui en sort passe au gestionnaire global.
     *
     * @param array<string, string> $get
     */
    private function requeteGeree(array $get = [], string $chemin = '/'): string
    {
        $_GET = $get;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $chemin;
        Utils::oublierRedirection();
        Compte::reinitialiser();

        ob_start();

        try {
            require __DIR__ . '/../../../web/index.php';
        } catch (\Throwable $e) {
            Utils::gererException($e);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** La base ne répond plus : la prochaine connexion échouera. */
    private function couperLaBase(int $modeRapport): void
    {
        mysqli_report($modeRapport);
        putenv('DB_HOSTNAME=hote.qui.nexiste.pas.invalid');
        Database::setConnection(null);
    }

    private function assertPageCinqCents(string $html): void
    {
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('<div class="code-erreur code-erreur-danger">500</div>', $html);
        $this->assertStringContainsString('Erreur interne du serveur', $html);
        $this->assertStringContainsString('class="site-footer"', $html);
        $this->assertSame(1, substr_count($html, '<!DOCTYPE html>'), 'Une seule page, entière.');
        $this->assertStringNotContainsString('Une erreur est survenue', $html);
        $this->assertStringNotContainsString('hote.qui.nexiste.pas.invalid', $html);
        $this->assertStringNotContainsString(Database::MESSAGE_INDISPONIBLE, $html);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function modesDeRapportMysqli(): array
    {
        return [
            'exceptions (production)' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT],
            'sans exception' => [MYSQLI_REPORT_OFF],
        ];
    }

    /** LE CAS CONSTATÉ EN PRODUCTION : la base est indisponible quand la page relit la personne connectée. */
    #[\PHPUnit\Framework\Attributes\DataProvider('modesDeRapportMysqli')]
    public function testUneBaseIndisponibleRendLaPageCinqCents(int $mode): void
    {
        $this->connecter(self::$membreId);
        $this->couperLaBase($mode);

        $this->assertPageCinqCents($this->requeteGeree(['action' => 'profil']));
    }

    /**
     * LA CONNEXION S'OUVRE ICI AU MILIEU D'UN GABARIT — l'en-tête des CGU relit la personne connectée.
     * Une page écrite à cet endroit partirait avec le tampon du gabarit : il en resterait une page blanche.
     */
    public function testUneBaseQuiTombePendantLeRenduDUnGabaritRendLaPageCinqCents(): void
    {
        $this->connecter(self::$membreId);
        $this->couperLaBase(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->assertPageCinqCents($this->requeteGeree([], '/cgu'));
    }

    /**
     * Le serveur SQL perdu en cours de requête : une requête échoue alors que la connexion
     * existait.
     *
     * LA PERTE PORTE SUR LES DEUX CONNEXIONS, celle du composant et celle de l'application : la
     * lecture de la personne connectée passe par le singleton Database, et le composant par sa
     * configuration. Couper la seconde seulement laisserait la page se rendre normalement.
     */
    public function testUneRequeteSqlEnEchecRendLaPageCinqCents(): void
    {
        $this->connecter(self::$membreId);

        $perdue = static fn (): \mysqli => throw new \mysqli_sql_exception('MySQL server has gone away', 2006);
        putenv('DB_HOSTNAME=hote.qui.nexiste.pas.invalid');
        Database::setConnection(null);

        try {
            $this->avecConfiguration(['connexion' => $perdue], function (): void {
                $html = $this->requeteGeree(['action' => 'profil']);

                $this->assertPageCinqCents($html);
                $this->assertStringNotContainsString('gone away', $html);
            });
        } finally {
            putenv('DB_HOSTNAME=127.0.0.1');
            Database::setConnection(self::$db);
        }
    }

    /** Le tableau de la gestion des personnes lit du JSON : il reçoit une erreur qu'il sait lire. */
    public function testUneBaseIndisponibleRepondEnJsonAuTableauDesPersonnes(): void
    {
        $this->connecter(self::$adminId);
        $this->couperLaBase(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $sortie = $this->requeteGeree(['action' => 'admin_annuaire_data']);

        $this->assertSame(500, http_response_code());
        $this->assertSame(['success' => false, 'message' => 'Erreur interne du serveur.'], json_decode($sortie, true));
    }

    /** En mode debug, un avertissement PHP arrête la page : elle devient la 500, détail en console. */
    public function testUnAvertissementEnModeDebugRendLaPageCinqCents(): void
    {
        putenv('DEBUG_MODE=true');
        $niveau = error_reporting(E_ALL);

        try {
            Utils::signalerErreurPhp(E_WARNING, 'Undefined variable $x', __FILE__, 12);
            $this->fail('Un avertissement doit arrêter la page.');
        } catch (\ErrorException $e) {
            ob_start();
            Utils::gererException($e);
            $html = (string) ob_get_clean();
        } finally {
            error_reporting($niveau);
        }

        $this->assertPageCinqCents($html);
        $this->assertStringContainsString('Undefined variable $x', $html, 'Le détail est remis à la console.');
    }

    /** Une erreur fatale, relevée en fin de script, rend elle aussi la page 500. */
    public function testUneErreurFataleRendLaPageCinqCents(): void
    {
        ob_start();
        Utils::signalerErreurFatale(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => __FILE__, 'line' => 7]);
        $html = (string) ob_get_clean();

        $this->assertPageCinqCents($html);
        $this->assertStringNotContainsString('Allowed memory size', $html);
    }

    /** Une exception quelconque d'un contrôleur : la page 500, sans son message. */
    public function testUneExceptionNonRattrapeeRendLaPageCinqCents(): void
    {
        ob_start();
        Utils::gererException(new \LogicException('Chemin interne /var/www/secret'));
        $html = (string) ob_get_clean();

        $this->assertPageCinqCents($html);
        $this->assertStringNotContainsString('/var/www/secret', $html);
    }
}
