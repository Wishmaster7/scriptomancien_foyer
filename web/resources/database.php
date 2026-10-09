<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Connexion à la base de données MySQL (mysqli), en singleton.
 *
 * Les paramètres viennent des variables d'environnement DB_HOSTNAME / DB_USERNAME /
 * DB_PASSWORD / DB_DATABASE, avec les valeurs de développement local par défaut — et
 * `3t75aa_foyer` comme schéma par défaut : celui de CETTE application.
 *
 * LE SCHÉMA D'IDENTITÉ EST UN AUTRE, et il n'est pas celui de la connexion : le composant le
 * qualifie dans ses requêtes, et son nom est tenu par {@see IdentitePartagee::schema()}.
 *
 * Les tests injectent leur connexion par {@see self::setConnection()}.
 */
class Database
{
    /**
     * Mode SQL imposé à CHAQUE connexion — la valeur PAR DÉFAUT de MySQL 8.x, écrite ici en
     * toutes lettres pour ne dépendre d'aucune configuration de serveur.
     *
     * Sans cette ligne, l'application n'a pas le même comportement d'un poste à l'autre : le MariaDB
     * de WampServer livre un `sql_mode` permissif tant que son my.ini n'est pas corrigé, quand la CI
     * (MariaDB 10.11 lancé en mode strict) l'applique. Une écriture invalide passe alors
     * silencieusement ici et ÉCHOUE ailleurs.
     */
    public const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** Le fuseau de chaque session MySQL : celui de PHP (cf. connecter()). */
    public const FUSEAU = '+00:00';

    /** Message, pour le journal du serveur, d'une connexion impossible (cf. connecter()). */
    public const MESSAGE_INDISPONIBLE = 'Connexion à la base de données impossible.';

    private static ?\mysqli $connexion = null;

    /** Connexion mysqli partagée, établie à la première demande. */
    public static function getConnection(): \mysqli
    {
        if (self::$connexion === null) {
            self::$connexion = self::connecter();
        }

        return self::$connexion;
    }

    /**
     * Schéma de CETTE application : celui de la connexion, et celui où vivent PERSONNE, la vue
     * PERSONNE_IDENTIFIEE et LOGS. Distinct du schéma d'IDENTITÉ
     * ({@see IdentitePartagee::schema()}), que le composant qualifie dans ses propres requêtes.
     *
     * Lu dans l'environnement plutôt que demandé à MySQL, pour ne pas ouvrir la connexion à
     * l'amorçage : elle ne s'établit qu'à la première requête qui en a besoin.
     */
    public static function getSchema(): string
    {
        return getenv('DB_DATABASE') ?: '3t75aa_foyer';
    }

    /**
     * Prépare et exécute une requête paramétrée, et rend la requête exécutée.
     *
     * LÈVE quand la base refuse, que mysqli signale ses erreurs par exception (production) ou par
     * un retour faux (tests) : l'appelant n'a qu'un seul cas d'échec à traiter.
     *
     * @param list<mixed> $parametres
     */
    public static function executer(string $sql, string $types = '', array $parametres = []): \mysqli_stmt
    {
        $stmt = self::getConnection()->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Requête impossible à préparer.');
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$parametres);
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException('Requête refusée par la base.');
        }

        return $stmt;
    }

    /**
     * Les lignes d'une requête de lecture paramétrée.
     *
     * @param  list<mixed>                      $parametres
     * @return list<array<string, mixed>>
     */
    public static function lignes(string $sql, string $types = '', array $parametres = []): array
    {
        $stmt = self::executer($sql, $types, $parametres);
        $lignes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $lignes;
    }

    /** Injecte (ou réinitialise) la connexion partagée. Réservé aux tests. */
    public static function setConnection(?\mysqli $connexion): void
    {
        self::$connexion = $connexion;
    }

    /**
     * Établit la connexion, ou lève {@see self::MESSAGE_INDISPONIBLE} — que le gestionnaire global
     * rend en page 500 ({@see Utils::gererException()}).
     *
     * L'échec arrive de DEUX façons selon `mysqli_report` : par EXCEPTION — le défaut de PHP depuis
     * la 8.1, donc celui de la production — ou par un `connect_error` à tester, quand les exceptions
     * sont coupées. Les deux lèvent la même exception : poursuivre avec une connexion inutilisable
     * ne ferait que déplacer l'échec plus loin, sur un message qui ne dirait plus rien de la cause.
     *
     * LEVÉE, ET NON RENDUE ICI : la connexion ne s'ouvre qu'à la première demande, parfois au milieu
     * d'un gabarit — l'en-tête relit la personne connectée. Une page écrite là partirait dans le
     * tampon du gabarit, que son échec efface ; l'exception, elle, en sort et trouve le gestionnaire.
     *
     * Le message d'origine n'est pas repris : il contient l'hôte, le compte et le nom de la base.
     */
    private static function connecter(): \mysqli
    {
        $hostname = getenv('DB_HOSTNAME') ?: 'localhost';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        try {
            $db = @new \mysqli($hostname, $username, $password, self::getSchema());
        } catch (\mysqli_sql_exception) {
            $db = null;
        }
        if ($db === null || $db->connect_error) {
            throw new \RuntimeException(self::MESSAGE_INDISPONIBLE);
        }
        $db->set_charset('utf8mb4');
        $db->query("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        $db->query("SET SESSION time_zone = '" . self::FUSEAU . "'");

        if (SiteConfig::debugMode()) {
            $result = $db->query('SELECT VERSION() AS version, @@version_comment AS comment, @@SESSION.sql_mode AS sql_mode');
            $row = $result->fetch_assoc();
            Utils::noterConsole('Version du SGBD : ' . $row['version']);
            Utils::noterConsole('Commentaire : ' . $row['comment']);
            Utils::noterConsole('Mode SQL : ' . $row['sql_mode']);
        }

        return $db;
    }
}
