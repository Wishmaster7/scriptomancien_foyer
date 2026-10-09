<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Petits services partagés par toute l'application : échappement, jeton CSRF, sortie.
 */
class Utils
{
    /**
     * Posé par app-mobile.js quand la page s'exécute DANS l'application installée sur mobile,
     * jamais dans un onglet : c'est le seul moyen, pour le serveur, de les distinguer. Il ne porte
     * aucune donnée personnelle, et le falsifier ne fait que prolonger SA PROPRE session.
     */
    public const COOKIE_APPLICATION = 'app_installee';

    /**
     * Durée de la session de l'application installée, renouvelée à chaque requête : elle ne tombe
     * qu'à la déconnexion, ou après un an sans l'ouvrir — « tant qu'on ne se déconnecte pas », sans
     * durée infinie.
     */
    public const SESSION_APPLICATION_JOURS = 365;

    /**
     * Démarre la session si elle ne l'est pas déjà.
     *
     * Le cookie est de SESSION (aucune durée), inaccessible au JavaScript, et « secure » dès que
     * la requête est chiffrée — sauf dans l'APPLICATION INSTALLÉE sur mobile, reconnue par le
     * cookie {@see self::COOKIE_APPLICATION} posé par app-mobile.js : elle reçoit un cookie
     * durable ({@see self::SESSION_APPLICATION_JOURS}), pour rester connectée tant que la personne
     * ne choisit pas « Se déconnecter », même après avoir fermé puis rouvert l'application.
     */
    public static function demarrerSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
        $application = ($_COOKIE[self::COOKIE_APPLICATION] ?? '') === '1';

        if ($application) {
            self::preparerStockageSessionApplication();
        }

        session_set_cookie_params(self::parametresCookieSession($https, $application));
        session_start();

        if ($application) {
            // PHP NE RENVOIE PAS LE COOKIE d'une session qu'il retrouve : sans ce renvoi, l'échéance
            // posée à la connexion courrait depuis ce jour-là, et l'application se déconnecterait un
            // an après la première connexion, quelle que soit son utilisation.
            $cookie = self::parametresCookieSession($https, true);
            $cookie['expires'] = self::maintenant() + $cookie['lifetime'];
            unset($cookie['lifetime']);
            setcookie(session_name(), session_id(), $cookie);

            // LE COOKIE D'APPLICATION EST RENVOYÉ DE LA MÊME FAÇON, en « Lax » : posé une première
            // fois par le script, il doit rester joint à l'ouverture depuis l'écran d'accueil.
            setcookie(self::COOKIE_APPLICATION, '1', [
                'expires' => $cookie['expires'],
                'path' => '/',
                'secure' => $https,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Les paramètres du cookie de session. Seule l'APPLICATION INSTALLÉE le rend durable : le
     * navigateur ordinaire garde un cookie qui meurt avec lui — un poste partagé ne doit pas rester
     * connecté.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public static function parametresCookieSession(bool $https, bool $application): array
    {
        return [
            'lifetime' => $application ? self::SESSION_APPLICATION_JOURS * 86400 : 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            // « Lax » pour l'application : à l'ouverture depuis l'écran d'accueil, un cookie
            // « Strict » n'est pas toujours joint à la première requête (Safari), et la session
            // paraîtrait perdue. Le jeton CSRF reste exigé de tout POST.
            'samesite' => $application ? 'Lax' : 'Strict',
        ];
    }

    /**
     * Les sessions de l'application vivent DANS LEUR PROPRE RÉPERTOIRE, avec leur propre durée de
     * conservation. Le ramasse-miettes de PHP, déclenché par la requête de n'importe quelle
     * visiteuse, efface tout fichier plus vieux que SA durée (24 minutes par défaut) : dans le
     * répertoire commun, la session d'un téléphone resté une semaine au fond d'une poche aurait
     * disparu à la première visite d'un ordinateur. Séparées, seules les requêtes de l'application
     * les ramassent, avec la durée de l'application.
     */
    private static function preparerStockageSessionApplication(): void
    {
        $repertoire = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'foyer-sessions-application';
        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0700, true);
        }

        session_save_path($repertoire);
        ini_set('session.gc_maxlifetime', (string) (self::SESSION_APPLICATION_JOURS * 86400));
    }

    /**
     * Jeton CSRF de la session, créé à la première demande.
     *
     * Un seul jeton par session, et non un par formulaire : la personne ouvre plusieurs onglets,
     * et un jeton renouvelé à chaque rendu invaliderait tous les formulaires sauf le dernier.
     */
    public static function jetonCsrf(): string
    {
        if (!isset($_SESSION['CSRF_TOKEN'])) {
            $_SESSION['CSRF_TOKEN'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['CSRF_TOKEN'];
    }

    /** Le jeton soumis est-il celui de la session ? Comparaison en temps constant. */
    public static function jetonCsrfValide(?string $jeton): bool
    {
        return is_string($jeton)
            && isset($_SESSION['CSRF_TOKEN'])
            && hash_equals((string) $_SESSION['CSRF_TOKEN'], $jeton);
    }

    /** Échappement HTML de toute valeur insérée dans un gabarit. */
    public static function echapper(?string $valeur): string
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Termine la requête — TOUJOURS par une exception, jamais par `exit`.
     *
     * `exit` emporterait le processus de test avec la requête, et aucun chemin se terminant par
     * une redirection ne pourrait alors être couvert : la moitié des contrôleurs serait hors
     * de portée des tests. L'exception s'arrête exactement au même endroit et laisse le
     * processus vivant.
     *
     * En exécution réelle, elle est rattrapée par le gestionnaire global installé au démarrage
     * ({@see self::installerGestionnaireDException()}), qui ne fait rien d'autre que rendre la
     * main : la requête est finie, la redirection ou la page ont déjà été écrites.
     */
    public static function quitter(): never
    {
        throw new SortieException();
    }

    /**
     * Installe le gestionnaire global d'exceptions. À appeler AVANT la première requête SQL : la
     * connexion n'est établie qu'à la première demande, et son échec doit trouver le gestionnaire
     * en place.
     *
     * Il traite DEUX choses, et les distingue : une fin de requête ordinaire
     * ({@see SortieException}), qu'il laisse simplement passer — tout a déjà été écrit ; et
     * toute autre exception, qui est une panne et devient la page 500 du site sans jamais montrer
     * son message, lequel nomme volontiers des tables, des chemins ou des identifiants.
     *
     * MODE DEBUG ({@see SiteConfig::debugMode()}) : le détail de la panne est AUSSI écrit dans la
     * console JavaScript du navigateur, et deux pannes qui échappaient au gestionnaire y sont
     * ramenées — l'avertissement PHP, qui ne se lirait sinon que dans le journal du serveur, et
     * l'erreur fatale, qu'aucun gestionnaire ne voit passer. Hors debug, rien de cela n'est installé.
     */
    public static function installerGestionnaireDException(): void
    {
        set_exception_handler(self::gererException(...));

        if (SiteConfig::debugMode()) {
            set_error_handler(self::signalerErreurPhp(...));
            register_shutdown_function(self::signalerErreurFatale(...));
        }
    }

    /**
     * La requête courante est-elle un appel AJAX ? C'est ce qui décide si une panne répond en JSON
     * ou en HTML : une réponse HTML remise à un appel asynchrone n'est lisible par personne.
     */
    public static function estAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Le gestionnaire global d'exceptions (cf. {@see self::installerGestionnaireDException()}).
     *
     * Publique et non anonyme pour rester appelable directement par les tests : un gestionnaire
     * enfoui dans une closure ne s'exerce qu'en provoquant une vraie panne.
     *
     * Une page rend {@see ErrorPage} — la même 500 qu'Apache sert (web/500.php) ; un appel AJAX
     * reçoit du JSON, seule réponse que son script sache lire.
     */
    public static function gererException(\Throwable $e): void
    {
        if ($e instanceof SortieException) {
            return;
        }

        error_log((string) $e);

        if (self::estAjax()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Erreur interne du serveur.']);

            return;
        }

        (new ErrorPage())->render(500);

        if (SiteConfig::debugMode()) {
            // ÉCRIT APRÈS la page 500. Le lecteur du bloc est chargé à sa suite : le repli autonome de
            // la page n'a pas de pied de page. Il retire chaque bloc lu, si bien qu'un second
            // chargement — celui du pied, quand la page est habillée — ne répète rien.
            echo self::consoleDebug(["Message : {$e->getMessage()}\nFichier : {$e->getFile()}\nLigne : {$e->getLine()}\n\n{$e->getTraceAsString()}"])
                . '<script src="/resources/js/console-debug.js"></script>';
        }
    }

    /** Lignes notées pour la console du navigateur, en attente du pied de page ({@see self::noterConsole()}). */
    private static array $console = [];

    /**
     * Note une ligne pour la CONSOLE DU NAVIGATEUR ; le pied de page la rendra ({@see self::consoleEnAttente()}).
     *
     * DIFFÉRÉE plutôt qu'écrite sur-le-champ : une ligne sortie avant l'en-tête HTML — pendant la
     * connexion à la base, par exemple — empêcherait toute redirection (« headers already sent ») et
     * rendrait illisible une réponse JSON. Le pied de page n'étant rendu que par une page HTML, un
     * appel asynchrone ne reçoit rien. L'appelant décide seul de noter : c'est lui qui teste le
     * mode debug.
     */
    public static function noterConsole(string $ligne): void
    {
        self::$console[] = $ligne;
    }

    /** Rend les lignes notées par {@see self::noterConsole()}, et les oublie : une page ne les répète pas. */
    public static function consoleEnAttente(): string
    {
        $lignes = self::$console;
        self::$console = [];

        return self::consoleDebug($lignes);
    }

    /**
     * Balisage qui fait écrire des lignes dans la console du navigateur (resources/js/console-debug.js).
     *
     * UN BLOC DE DONNÉES, JAMAIS UN SCRIPT : aucun `<script>` en ligne — un
     * `<script type="application/json">` n'est pas
     * exécuté, et c'est le script servi par le site qui le lit. JSON_HEX_TAG code « < » et « > » :
     * aucune ligne ne peut refermer le bloc. Aucune ligne → aucun balisage.
     *
     * @param list<string> $lignes
     */
    public static function consoleDebug(array $lignes): string
    {
        if ($lignes === []) {
            return '';
        }

        return '<script type="application/json" data-role="console-debug">'
            . json_encode($lignes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . '</script>';
    }

    /**
     * En mode debug, un avertissement PHP ARRÊTE la page comme une exception. Une erreur masquée par
     * l'opérateur « @ » reste masquée : elle est attendue par le code qui l'a tue.
     */
    public static function signalerErreurPhp(int $niveau, string $message, string $fichier, int $ligne): bool
    {
        if ((error_reporting() & $niveau) === 0) {
            return false;
        }

        throw new \ErrorException($message, 0, $niveau, $fichier, $ligne);
    }

    /**
     * En mode debug, relève à la fin du script une erreur fatale — celle de `error_get_last()`, ou
     * celle que passe un test, qui ne peut pas en provoquer une vraie sans emporter le processus.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $erreur
     */
    public static function signalerErreurFatale(?array $erreur = null): void
    {
        $erreur ??= error_get_last();
        if ($erreur === null || !in_array($erreur['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::gererException(new \ErrorException($erreur['message'], 0, $erreur['type'], $erreur['file'], $erreur['line']));
    }

    /**
     * Redirige puis termine la requête (motif Post/Redirect/Get).
     *
     * Toute soumission se termine ainsi : sans redirection, un rafraîchissement de page
     * rejouerait le POST.
     *
     * La destination est MÉMORISÉE en plus d'être envoyée : en ligne de commande, `header()`
     * ne fait rien et ne rend rien, si bien qu'un test n'aurait aucun moyen de dire où la
     * requête a mené — or c'est précisément ce qu'il y a à vérifier d'une soumission.
     */
    public static function rediriger(string $destination): never
    {
        self::$derniereRedirection = $destination;
        header('Location: ' . $destination);
        self::quitter();
    }

    private static ?string $derniereRedirection = null;

    /** Destination de la dernière redirection, ou null. Lue par les tests. */
    public static function derniereRedirection(): ?string
    {
        return self::$derniereRedirection;
    }

    /** Oublie la dernière redirection (entre deux requêtes de test). */
    public static function oublierRedirection(): void
    {
        self::$derniereRedirection = null;
    }

    private static ?int $maintenantFige = null;

    /**
     * L'HEURE DU SERVEUR PHP, et le seul endroit du projet où elle se lit.
     *
     * Elle ne date AUCUNE écriture : les deux codes à usage unique sont datés par MySQL
     * (`DATE_ADD(NOW(), …)`) et comparés à `NOW()`, jamais à l'horloge de PHP — deux horloges pour
     * une seule durée, c'est une expiration fausse du décalage entre les deux serveurs. Elle sert à
     * ce qui s'AFFICHE, et à cela seulement : l'année du copyright du pied de page.
     *
     * Elle est FIGEABLE parce que sans cela une plage d'années ne se testerait qu'en attendant le
     * 1er janvier.
     */
    public static function maintenant(): int
    {
        return self::$maintenantFige ?? time();
    }

    /** Fige (ou libère, avec null) l'horloge de {@see self::maintenant()} — usage TEST uniquement. */
    public static function figerMaintenant(?int $timestamp): void
    {
        self::$maintenantFige = $timestamp;
    }
}
