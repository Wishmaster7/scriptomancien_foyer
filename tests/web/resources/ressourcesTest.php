<?php

declare(strict_types=1);

use Foyer\App\Compte;
use Foyer\App\Database;
use Foyer\App\Flash;
use Foyer\App\IdentitePartagee;
use Foyer\App\SiteConfig;
use Foyer\App\Smtp;
use Foyer\App\Snippet;
use Foyer\App\SortieException;
use Foyer\App\Utils;
use Personnes\Auth\Chargeur;

require_once __DIR__ . '/../TestBase.php';

/**
 * Les services partagés de l'application : identité du site, connexion, session, rendu.
 */
class RessourcesTest extends TestBase
{
    // =========================================================
    // SiteConfig
    // =========================================================

    public function testLUrlDeLApplicationVientDeLEnvironnementOuDuDefaut(): void
    {
        $this->assertSame(SiteConfig::URL_APPLICATION, SiteConfig::urlApplication());

        putenv('APP_URL=https://personnes.localhost');

        try {
            $this->assertSame('https://personnes.localhost', SiteConfig::urlApplication());
        } finally {
            putenv('APP_URL');
        }
    }

    public function testLeModeDebugVientDeLEnvironnementEtEstEteintParDefaut(): void
    {
        putenv('DEBUG_MODE');
        $this->assertFalse(SiteConfig::debugMode());

        try {
            putenv('DEBUG_MODE=true');
            $this->assertTrue(SiteConfig::debugMode());

            putenv('DEBUG_MODE=1');
            $this->assertTrue(SiteConfig::debugMode());

            putenv('DEBUG_MODE=false');
            $this->assertFalse(SiteConfig::debugMode());

            putenv('DEBUG_MODE=n\'importe quoi');
            $this->assertFalse(SiteConfig::debugMode(), 'Une valeur illisible n\'allume pas le debug.');
        } finally {
            putenv('DEBUG_MODE');
        }
    }

    // =========================================================
    // Database
    // =========================================================

    public function testLaConnexionEstEtablieALaPremiereDemandeEtPartagee(): void
    {
        Database::setConnection(null);

        try {
            $premiere = Database::getConnection();
            $this->assertSame($premiere, Database::getConnection());
            $this->assertSame('3t75aa_foyer_phpunit', $premiere->query('SELECT DATABASE()')->fetch_row()[0]);
        } finally {
            Database::setConnection(self::$db);
        }
    }

    public function testLeSchemaVientDeLEnvironnementOuDuDefaut(): void
    {
        $this->assertSame('3t75aa_foyer_phpunit', Database::getSchema());

        putenv('DB_DATABASE');

        try {
            $this->assertSame('3t75aa_foyer', Database::getSchema());
        } finally {
            putenv('DB_DATABASE=3t75aa_foyer_phpunit');
        }
    }

    /**
     * LE SCHÉMA D'IDENTITÉ EST UN AUTRE, et il vient de sa propre variable : confondre les deux
     * ferait écrire les codes du composant dans le schéma de cette application.
     */
    public function testLeSchemaDIdentiteVientDeSonEnvironnementOuDuDefaut(): void
    {
        $this->assertSame('3t75aa_foyer_personnes_phpunit', IdentitePartagee::schema());

        putenv('DB_PERSONNES');

        try {
            $this->assertSame('3t75aa_personnes', IdentitePartagee::schema());
        } finally {
            putenv('DB_PERSONNES=3t75aa_foyer_personnes_phpunit');
        }
    }

    /** En debug, la connexion NOTE le SGBD et le mode SQL pour la console, sans rien écrire : aucun en-tête n'est parti. */
    public function testEnDebugLaConnexionNoteLeSgbdPourLaConsoleSansRienEcrire(): void
    {
        Database::setConnection(null);
        putenv('DEBUG_MODE=true');

        try {
            ob_start();
            Database::getConnection();
            $sortie = (string) ob_get_clean();
        } finally {
            putenv('DEBUG_MODE');
            Database::setConnection(self::$db);
        }

        self::assertSame('', $sortie);
        $bloc = Utils::consoleEnAttente();
        preg_match('~>(.*)</script>~', $bloc, $contenu);
        $lignes = json_decode($contenu[1], true);
        self::assertCount(3, $lignes);
        self::assertStringStartsWith('Version du SGBD : ', $lignes[0]);
        self::assertStringStartsWith('Commentaire : ', $lignes[1]);
        self::assertSame('Mode SQL : ' . Database::SQL_MODE, $lignes[2]);
    }

    /**
     * Les deux façons dont mysqli signale un échec de connexion : par exception — le défaut de PHP,
     * donc la production — ou par `connect_error`, exceptions coupées comme dans ce harnais.
     *
     * @return array<string, array{int}>
     */
    public static function modesDeRapportMysqli(): array
    {
        return [
            'exceptions (production)' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT],
            'sans exception' => [MYSQLI_REPORT_OFF],
        ];
    }

    /**
     * LA MÊME EXCEPTION DANS LES DEUX MODES, et sans rien de l'hôte, du compte ni de la base : c'est
     * elle que le gestionnaire global rend en page 500.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modesDeRapportMysqli')]
    public function testUneConnexionImpossibleLeveUneExceptionQuiNeNommeRien(int $mode): void
    {
        Database::setConnection(null);
        putenv('DB_HOSTNAME=hote.qui.nexiste.pas.invalid');
        mysqli_report($mode);

        try {
            Database::getConnection();
            $this->fail('Une connexion impossible aurait dû interrompre la requête.');
        } catch (\RuntimeException $e) {
            $this->assertSame(\RuntimeException::class, $e::class);
            $this->assertSame(Database::MESSAGE_INDISPONIBLE, $e->getMessage());
            $this->assertNull($e->getPrevious());
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF);
            putenv('DB_HOSTNAME=127.0.0.1');
            Database::setConnection(self::$db);
        }
    }

    // =========================================================
    // Utils
    // =========================================================

    public function testLeJetonCsrfEstStableSurUneMemeSession(): void
    {
        $premier = Utils::jetonCsrf();

        $this->assertSame($premier, Utils::jetonCsrf());
        $this->assertTrue(Utils::jetonCsrfValide($premier));
    }

    public function testUnJetonAbsentOuFauxEstRefuse(): void
    {
        Utils::jetonCsrf();

        $this->assertFalse(Utils::jetonCsrfValide(null));
        $this->assertFalse(Utils::jetonCsrfValide('faux'));
    }

    public function testUnJetonEstRefuseQuandLaSessionNEnPorteAucun(): void
    {
        unset($_SESSION['CSRF_TOKEN']);

        $this->assertFalse(Utils::jetonCsrfValide('quelconque'));
    }

    public function testLEchappementTraiteNullCommeUneChaineVide(): void
    {
        $this->assertSame('', Utils::echapper(null));
        $this->assertSame('&lt;b&gt;&quot;', Utils::echapper('<b>"'));
    }

    public function testLaSortieLeveUneExceptionPlutotQueDeTerminerLeProcessus(): void
    {
        $this->expectException(SortieException::class);
        Utils::quitter();
    }

    public function testLeGestionnaireGlobalLaisseUneFinDeRequetePasser(): void
    {
        Utils::installerGestionnaireDException();
        $gestionnaire = set_exception_handler(null);
        restore_exception_handler();

        try {
            ob_start();
            $gestionnaire(new SortieException());
            $this->assertSame('', ob_get_clean());
        } finally {
            restore_exception_handler();
        }
    }

    public function testLeGestionnaireGlobalRendLaPageCinqCentsSansMontrerLeMessage(): void
    {
        Utils::installerGestionnaireDException();
        $gestionnaire = set_exception_handler(null);
        restore_exception_handler();

        try {
            ob_start();
            $gestionnaire(new \RuntimeException('SELECT * FROM PERSONNE — détail interne'));
            $sortie = (string) ob_get_clean();

            $this->assertStringContainsString('<div class="code-erreur code-erreur-danger">500</div>', $sortie);
            $this->assertStringContainsString('class="site-footer"', $sortie);
            // Le message d'origine nomme volontiers des tables et des chemins : il reste au
            // journal du serveur, jamais à l'écran.
            $this->assertStringNotContainsString('PERSONNE', $sortie);
            $this->assertSame(500, http_response_code());
        } finally {
            restore_exception_handler();
            http_response_code(200);
        }
    }

    /** Le tableau DataTables lit du JSON : une panne lui répond en JSON, jamais par une page HTML. */
    public function testLeGestionnaireGlobalRepondEnJsonAUnAppelAjax(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            ob_start();
            Utils::gererException(new \RuntimeException('SELECT * FROM PERSONNE — détail interne'));
            $sortie = (string) ob_get_clean();
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        $this->assertSame(500, http_response_code());
        $this->assertSame(['success' => false, 'message' => 'Erreur interne du serveur.'], json_decode($sortie, true));
    }

    public function testSeulLEnTeteDeJQueryFaitUnAppelAjax(): void
    {
        $this->assertFalse(Utils::estAjax());

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'autre';

        try {
            $this->assertFalse(Utils::estAjax());
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }
    }

    public function testHorsDebugLeGestionnaireNInstalleQueLeGestionnaireDException(): void
    {
        putenv('DEBUG_MODE');
        $avant = set_error_handler(null);
        restore_error_handler();

        Utils::installerGestionnaireDException();
        restore_exception_handler();

        $apres = set_error_handler(null);
        restore_error_handler();
        $this->assertSame($avant, $apres);
    }

    public function testEnDebugLeGestionnaireRameneAussiLesAvertissementsPhp(): void
    {
        putenv('DEBUG_MODE=true');

        try {
            Utils::installerGestionnaireDException();
            restore_exception_handler();
            $gestionnaire = set_error_handler(null);
            restore_error_handler();
            restore_error_handler();

            $this->assertSame('signalerErreurPhp', (new \ReflectionFunction($gestionnaire))->getName());
        } finally {
            putenv('DEBUG_MODE');
        }
    }

    public function testEnDebugLaPanneSeLitDansLaConsoleSansPouvoirRefermerLeScript(): void
    {
        putenv('DEBUG_MODE=true');

        try {
            ob_start();
            Utils::gererException(new \RuntimeException('Panne </script> de test'));
            $sortie = (string) ob_get_clean();
        } finally {
            putenv('DEBUG_MODE');
            http_response_code(200);
        }

        // LA PAGE 500 D'ABORD, le détail ensuite : écrit après elle, il ne se voit qu'en console.
        $this->assertStringContainsString('<div class="code-erreur code-erreur-danger">500</div>', $sortie);
        $this->assertMatchesRegularExpression('#</html>\s*<script type="application/json" data-role="console-debug">#', $sortie);
        $this->assertStringNotContainsString('<script>', $sortie, 'Aucun script en ligne.');
        $this->assertStringNotContainsString('Panne </script>', $sortie, 'Le message ne peut pas refermer le bloc.');
        // Le repli autonome de la page n'a pas de pied : le lecteur du bloc est chargé à sa suite.
        $this->assertStringEndsWith('</script><script src="/resources/js/console-debug.js"></script>', $sortie);
        $this->assertSame(1, preg_match('#data-role="console-debug">(.*?)</script>#s', $sortie, $bloc));
        $lignes = json_decode($bloc[1], true);
        $this->assertStringContainsString('Panne </script> de test', $lignes[0]);
        $this->assertStringContainsString('ressourcesTest.php', $lignes[0], 'Le détail nomme le fichier en cause.');
    }

    /** Aucune ligne, aucun balisage : un bloc vide ne ferait qu'alourdir la page. */
    public function testConsoleDebugNeRendRienSansLigne(): void
    {
        $this->assertSame('', Utils::consoleDebug([]));
    }

    /**
     * Un bloc de DONNÉES (lu par console-debug.js), jamais un script en ligne : ses lignes, dans
     * l'ordre, et aucune ne peut refermer le bloc.
     */
    public function testConsoleDebugPoseUnBlocDeDonneesQueNulleLigneNePeutRefermer(): void
    {
        $lignes = ['Panne </script><script>alert(1)</script>', 'Accentué & « guillemets »'];
        $bloc = Utils::consoleDebug($lignes);

        $prefixe = '<script type="application/json" data-role="console-debug">';
        $this->assertStringStartsWith($prefixe, $bloc);
        $this->assertStringEndsWith('</script>', $bloc);
        $this->assertSame(1, substr_count($bloc, '</script>'));
        $this->assertSame($lignes, json_decode(substr($bloc, strlen($prefixe), -strlen('</script>')), true));
    }

    /** Les lignes notées sont rendues UNE fois : la page suivante ne les répète pas. */
    public function testConsoleEnAttenteRendLesLignesNoteesPuisLesOublie(): void
    {
        Utils::noterConsole('Première');
        Utils::noterConsole('Seconde');

        $this->assertSame(Utils::consoleDebug(['Première', 'Seconde']), Utils::consoleEnAttente());
        $this->assertSame('', Utils::consoleEnAttente());
    }

    public function testUnAvertissementPhpDevientUneException(): void
    {
        // Le processus de test exclut les avertissements de son error_reporting.
        $niveau = error_reporting(E_ALL);

        try {
            Utils::signalerErreurPhp(E_USER_WARNING, 'Avertissement de test', __FILE__, 42);
            $this->fail('Un avertissement doit arrêter la page.');
        } catch (\ErrorException $e) {
            $this->assertSame('Avertissement de test', $e->getMessage());
            $this->assertSame(E_USER_WARNING, $e->getSeverity());
            $this->assertSame(42, $e->getLine());
        } finally {
            error_reporting($niveau);
        }
    }

    public function testUnAvertissementTuParLArobaseResteTu(): void
    {
        $niveau = error_reporting(0);

        try {
            $this->assertFalse(Utils::signalerErreurPhp(E_USER_WARNING, 'Attendu', __FILE__, 1));
        } finally {
            error_reporting($niveau);
        }
    }

    public function testUneErreurFataleEstReleveeEnFinDeScript(): void
    {
        try {
            ob_start();
            Utils::signalerErreurFatale(['type' => E_ERROR, 'message' => 'Fatale', 'file' => __FILE__, 'line' => 7]);
            $sortie = (string) ob_get_clean();

            $this->assertStringContainsString('<div class="code-erreur code-erreur-danger">500</div>', $sortie);
            $this->assertSame(500, http_response_code());
        } finally {
            http_response_code(200);
        }
    }

    /** Ni l'absence d'erreur, ni une erreur non fatale — déjà passée par le gestionnaire — ne sont relevées. */
    public function testSeuleUneErreurFataleEstRelevee(): void
    {
        error_clear_last();
        ob_start();
        Utils::signalerErreurFatale();
        Utils::signalerErreurFatale(['type' => E_WARNING, 'message' => 'Déjà vue', 'file' => __FILE__, 'line' => 1]);

        $this->assertSame('', ob_get_clean());
        $this->assertSame(200, http_response_code());
    }

    public function testLaSessionEstDemarreeUneSeuleFois(): void
    {
        // Session déjà active : l'appel ne fait rien et n'échoue pas.
        Utils::demarrerSession();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());

        $memoire = $_SESSION;
        session_write_close();
        $this->assertSame(PHP_SESSION_NONE, session_status());

        Utils::demarrerSession();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION = $memoire;
    }

    /** Hors application : un cookie de session, sans durée, en « Strict ». */
    public function testLesParametresDeSessionHorsApplicationSontSansDureeEtStricts(): void
    {
        $parametres = Utils::parametresCookieSession(false, false);

        $this->assertSame(0, $parametres['lifetime']);
        $this->assertSame('Strict', $parametres['samesite']);
        $this->assertFalse($parametres['secure']);
    }

    /** Dans l'application installée : un cookie durable (un an), en « Lax ». */
    public function testLesParametresDeSessionDansLApplicationSontDurablesEtLax(): void
    {
        $parametres = Utils::parametresCookieSession(true, true);

        $this->assertSame(Utils::SESSION_APPLICATION_JOURS * 86400, $parametres['lifetime']);
        $this->assertSame('Lax', $parametres['samesite']);
        $this->assertTrue($parametres['secure']);
    }

    /**
     * LE COOKIE D'APPLICATION FAIT VIVRE LA SESSION UN AN : posé avant le redémarrage de la
     * session, il bascule son cookie en « Lax » et sans expiration proche — c'est ce qui permet à
     * l'application installée de retrouver sa session après avoir été fermée puis rouverte.
     */
    public function testLeCookieDApplicationRendLaSessionDurable(): void
    {
        $memoire = $_SESSION;
        $cheminSessionOrdinaire = session_save_path();
        session_write_close();
        $_COOKIE[Utils::COOKIE_APPLICATION] = '1';

        // LE RÉPERTOIRE DÉDIÉ EST RECRÉÉ À CHAQUE FOIS : laissé par un run précédent, il resterait
        // présent sur le disque entre deux exécutions de la suite, et la branche qui le crée
        // (Utils::preparerStockageSessionApplication()) ne serait plus jamais prise.
        $repertoireApplication = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'foyer-sessions-application';
        if (is_dir($repertoireApplication)) {
            array_map('unlink', glob($repertoireApplication . DIRECTORY_SEPARATOR . '*') ?: []);
            rmdir($repertoireApplication);
        }

        try {
            Utils::demarrerSession();
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());

            $parametres = session_get_cookie_params();
            $this->assertSame(Utils::SESSION_APPLICATION_JOURS * 86400, $parametres['lifetime']);
            $this->assertSame('Lax', $parametres['samesite']);
        } finally {
            unset($_COOKIE[Utils::COOKIE_APPLICATION]);
            session_write_close();
            session_save_path($cheminSessionOrdinaire);
            session_start();
            $_SESSION = $memoire;
        }
    }

    /** Sans le cookie d'application, la session reste celle d'un navigateur ordinaire. */
    public function testSansLeCookieDApplicationLaSessionResteOrdinaire(): void
    {
        $memoire = $_SESSION;
        session_write_close();
        unset($_COOKIE[Utils::COOKIE_APPLICATION]);

        try {
            Utils::demarrerSession();

            $parametres = session_get_cookie_params();
            $this->assertSame(0, $parametres['lifetime']);
            $this->assertSame('Strict', $parametres['samesite']);
        } finally {
            session_write_close();
            session_start();
            $_SESSION = $memoire;
        }
    }

    // =========================================================
    // Amorçage
    // =========================================================

    public function testLAmorcageInstalleLaConfigurationDuComposant(): void
    {
        // `require` et non `require_once` : on rejoue le montage réel de l'application, celui
        // que le harnais a déjà exécuté avant que la couverture ne commence à enregistrer.
        require __DIR__ . '/../../../web/resources/bootstrap.php';
        // L'amorçage installe le gestionnaire global d'exceptions ; il avalerait les erreurs
        // que PHPUnit doit voir.
        restore_exception_handler();

        $configuration = \Personnes\Auth\Configuration::courante();
        // LE SCHÉMA REMIS AU COMPOSANT EST CELUI DE L'IDENTITÉ, et non celui de la connexion.
        $this->assertSame('3t75aa_foyer_personnes_phpunit', $configuration->schema);
        $this->assertSame(SiteConfig::NOM_SITE, $configuration->nomSite);
        $this->assertSame('/cgu', $configuration->urlConditions);
        // L'adresse sous laquelle le serveur expose la COPIE du composant.
        $this->assertSame('/resources/personnes', $configuration->urlComposant);

        // Les six fermetures du contrat répondent bien.
        $this->assertSame(self::$db, ($configuration->connexion)());
        $this->assertTrue(($configuration->envoiEmail)('a@example.test', 'Sujet', 'Corps', null, null));
        $this->assertStringContainsString(SiteConfig::NOM_SITE, ($configuration->enteteEmail)(null));
        $this->assertStringContainsString('Objet', ($configuration->sujetEmail)('Objet'));
        // Le verdict d'accès : une personne admise ici entre, et le jeu de départ l'admet.
        $this->assertNull(($configuration->apresAuthentification)(self::$membreId));
        // … et sa ligne locale la rend connue dès l'étape 1.
        $this->assertTrue(($configuration->personneConnue)(self::$membreId));
    }

    // =========================================================
    // Flash
    // =========================================================

    public function testUnMessageFlashEstLuUneSeuleFois(): void
    {
        Flash::poser('succes', 'Enregistré.');

        $this->assertSame('Enregistré.', Flash::prendre('succes'));
        $this->assertSame('', Flash::prendre('succes'));
    }

    // =========================================================
    // Compte
    // =========================================================

    public function testLeCompteEstVideHorsSession(): void
    {
        $this->assertFalse(Compte::estConnecte());
        $this->assertSame(0, Compte::id());
        $this->assertSame('', Compte::pseudonyme());
        $this->assertSame('', Compte::email());
        $this->assertFalse(Compte::estAdmin());
    }

    public function testLeCompteRendLIdentiteConnectee(): void
    {
        $this->connecter(self::$adminId);

        $this->assertTrue(Compte::estConnecte());
        $this->assertSame(self::$adminId, Compte::id());
        $this->assertSame('Admine', Compte::pseudonyme());
        $this->assertSame('admin@example.test', Compte::email());
        $this->assertTrue(Compte::estAdmin());
    }

    public function testUneIdentiteDisparueRetombeDeconnectee(): void
    {
        $this->connecter(999999);

        $this->assertFalse(Compte::estConnecte());
    }

    public function testUneIdentiteBloqueeRetombeDeconnectee(): void
    {
        $this->connecter(self::$bloqueId);

        $this->assertFalse(Compte::estConnecte());
    }

    // =========================================================
    // Snippet
    // =========================================================

    public function testLEnTeteEtLePiedSeRendent(): void
    {
        $snippet = new Snippet();
        $entete = $snippet->getHeader('Titre', 'Une erreur', 'Un succès');

        $this->assertStringContainsString('<title>Titre — ', $entete);
        $this->assertStringContainsString('Une erreur', $entete);
        $this->assertStringContainsString('Un succès', $entete);
        $this->assertStringContainsString('id="contenu-page"', $entete);
        $this->assertStringContainsString('</html>', $snippet->getFooter());
    }

    /**
     * Les lignes notées pour la console arrivent en fin de pied, après le script qui les lit, et une
     * seule fois.
     */
    public function testLePiedRendLesLignesNoteesPourLaConsole(): void
    {
        Utils::noterConsole('Mode SQL : strict');

        $this->assertMatchesRegularExpression(
            '#<script src="/resources/js/console-debug\.js\?v=\d+"></script>\s*'
            . preg_quote(Utils::consoleDebug(['Mode SQL : strict']), '#') . '\s*</body>#',
            (new Snippet())->getFooter()
        );
        $this->assertStringNotContainsString('Mode SQL', (new Snippet())->getFooter());
    }

    public function testLEnTeteSansTitreNommeLeSiteSeul(): void
    {
        $this->assertStringContainsString(
            '<title>' . SiteConfig::NOM_SITE . '</title>',
            (new Snippet())->getHeader('')
        );
    }

    /**
     * LA CARTE DE CONNEXION PASSE PAR SNIPPET : c'est le composant qui la rend
     * ({@see Ecran::carteConnexion()}), et l'application n'en connaît qu'une méthode de son habillage.
     */
    public function testLaCarteDeConnexionEstRendueParLeComposant(): void
    {
        $html = (new Snippet())->getCarteConnexion(1, 'jeton');

        $this->assertStringContainsString('personnes-carte-connexion', $html);
        $this->assertStringContainsString('action="/"', $html);
    }

    public function testUneErreurDeRenduRefermeLesTamponsOuverts(): void
    {
        $gabarit = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gabarit_qui_echoue.php';
        file_put_contents($gabarit, "<?php ob_start(); throw new \\RuntimeException('boum');\n");
        $niveau = ob_get_level();

        try {
            (new Snippet())->getContenu($gabarit);
            $this->fail("L'erreur du gabarit aurait dû se propager.");
        } catch (\RuntimeException $e) {
            $this->assertSame('boum', $e->getMessage());
            $this->assertSame($niveau, ob_get_level());
        } finally {
            unlink($gabarit);
        }
    }

    // =========================================================
    // Smtp — la composition, l'expédition étant couverte à part
    // =========================================================

    public function testLaConfigurationSmtpRetombeSurMailhog(): void
    {
        $config = Smtp::configuration();

        $this->assertSame('localhost', $config['host']);
        $this->assertSame(1025, $config['port']);
        $this->assertSame('', $config['username']);
        $this->assertSame(SiteConfig::NOM_SITE, $config['nom_expediteur']);
    }

    public function testLaConfigurationSmtpSuitLEnvironnement(): void
    {
        putenv('SMTP_HOST=mail.exemple.test');
        putenv('SMTP_PORT=587');
        putenv('SMTP_USERNAME=compte');
        putenv('SMTP_PASSWORD=secret');
        putenv('MAIL_FROM=envoi@exemple.test');
        putenv('MAIL_FROM_NAME=Expediteur');

        try {
            $config = Smtp::configuration();
            $this->assertSame('mail.exemple.test', $config['host']);
            $this->assertSame(587, $config['port']);
            $this->assertSame('compte', $config['username']);
            $this->assertSame('secret', $config['password']);
            $this->assertSame('envoi@exemple.test', $config['expediteur']);
            $this->assertSame('Expediteur', $config['nom_expediteur']);
        } finally {
            foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $variable) {
                putenv($variable);
            }
        }
    }

    public function testLeBandeauNommeLeContexteQuandIlYEnAUn(): void
    {
        $this->assertStringContainsString('Hydriades', Smtp::bandeauEmail('Hydriades 2027'));
        $this->assertStringContainsString(SiteConfig::NOM_SITE, Smtp::bandeauEmail(null));
        $this->assertStringContainsString(SiteConfig::NOM_SITE, Smtp::bandeauEmail('   '));
    }

    public function testLeSujetPorteLeNomDuSite(): void
    {
        $this->assertSame(SiteConfig::NOM_SITE . ' - Connexion', Smtp::sujetEmail('Connexion'));
    }

    // =========================================================
    // Chargeur
    // =========================================================

    public function testLAutoloaderChargeUneClasseDuComposant(): void
    {
        // La classe est déjà chargée : ce qu'on vérifie est que l'autoloader la RÉSOUT, et
        // qu'un nom inconnu de son espace de noms ne le fait pas échouer.
        $this->assertTrue(class_exists(\Personnes\Auth\Code::class));
        $this->assertFalse(class_exists('Personnes\\Auth\\ClasseQuiNExistePas'));
        $this->assertFalse(class_exists('Ailleurs\\ClasseQuiNExistePas'));
    }

    public function testLaRacineDuComposantContientSesTroisRepertoires(): void
    {
        $this->assertDirectoryExists(Chargeur::racine() . '/src');
        $this->assertDirectoryExists(Chargeur::racine() . '/gabarits');
        $this->assertDirectoryExists(Chargeur::racine() . '/css');
    }
}
