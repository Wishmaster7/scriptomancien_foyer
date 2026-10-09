<?php

declare(strict_types=1);

use Foyer\App\Compte;
use Foyer\App\Database;
use Foyer\App\IdentitePartagee;
use Personnes\Auth\Authentification;
use Personnes\Auth\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * Base de tous les tests : connexion au schéma de test, données de départ, hygiène d'état.
 *
 * DEUX SCHÉMAS, ET LES DEUX SONT DE TEST : `3t75aa_foyer_phpunit` (celui de la connexion) et
 * `3t75aa_foyer_personnes_phpunit` (l'annuaire d'identité, que la vue et les clés étrangères
 * visent). Tous deux sont PROPRES à ce projet — un annuaire de test partagé serait détruit par la
 * réinitialisation d'un voisin.
 *
 * CHAQUE TEST EST ENVELOPPÉ DANS UNE TRANSACTION ANNULÉE À LA FIN — bien plus rapide que de
 * vider les tables une par une. Le composant en est AVERTI
 * ({@see Transaction::adopterTransactionExistante()}) : sans cela, son propre
 * `START TRANSACTION` validerait implicitement celle du harnais, et les données d'un test
 * survivraient au suivant.
 */
abstract class TestBase extends TestCase
{
    protected static \mysqli $db;

    /** Identités du jeu de départ — toutes trois admises sur ce site, sauf mention contraire. */
    protected static int $adminId = 0;
    protected static int $membreId = 0;
    protected static int $bloqueId = 0;

    /** Le schéma de test attendu : rien ne tourne ailleurs. */
    private const SCHEMA_ATTENDU = '3t75aa_foyer_phpunit';

    public static function setUpBeforeClass(): void
    {
        // Les DB_* ont été posées par tests/bootstrap.php, à partir des TEST_DB_* de l'intégration
        // continue ou des valeurs WAMP locales : la même source que celle que lit l'application.
        $db = new \mysqli(
            getenv('DB_HOSTNAME') ?: '127.0.0.1',
            getenv('DB_USERNAME') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_DATABASE') ?: self::SCHEMA_ATTENDU
        );
        if ($db->connect_error) {
            self::fail('Impossible de se connecter à ' . self::SCHEMA_ATTENDU . ' : ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        // MÊME MODE SQL QU'EN PRODUCTION : WAMP livre un `sql_mode` vide, où une écriture
        // invalide est silencieusement corrigée au lieu d'échouer.
        $db->query("SET SESSION sql_mode = '" . Database::SQL_MODE . "'");

        // Garde-fou : ne jamais tourner ailleurs que sur la base de test.
        $nom = $db->query('SELECT DATABASE()')->fetch_row()[0];
        self::assertSame(self::SCHEMA_ATTENDU, $nom, 'Les tests doivent tourner sur la base de test !');

        self::$db = $db;
        Database::setConnection($db);
    }

    protected function setUp(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        http_response_code(200);

        Database::setConnection(self::$db);
        self::$db->begin_transaction();
        Transaction::adopterTransactionExistante();

        $this->insererDonneesDeBase();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Compte::reinitialiser();
    }

    protected function tearDown(): void
    {
        self::$db->rollback();
        Transaction::reinitialiser();
        Compte::reinitialiser();
        $_SESSION = [];

        // Le point de substitution d'envoi d'email est une fonction globale : elle ne peut pas
        // être retirée, mais le tableau qu'elle remplit est vidé entre deux tests.
        $GLOBALS['emails_envoyes'] = [];
    }

    /** Le nom, en accents graves, de la table d'identité : l'annuaire de TEST de ce projet. */
    protected static function tableAnnuaire(): string
    {
        return '`' . IdentitePartagee::schema() . '`.`PERSONNE`';
    }

    /** Le nom, en accents graves, de la table anti-force-brute de l'annuaire de test. */
    protected static function tableMinuteur(): string
    {
        return '`' . IdentitePartagee::schema() . '`.`RATE_LIMIT`';
    }

    /**
     * Trois personnes : un administrateur de la plateforme, un membre ordinaire, un compte bloqué
     * globalement. Toutes trois ont une IDENTITÉ dans l'annuaire ET une ligne ACTIVE ici — sans
     * cette seconde, aucune n'entrerait ({@see \Foyer\App\AuthentificationModel::admettre()}).
     */
    protected function insererDonneesDeBase(): void
    {
        // L'ORDRE EST CELUI DES CLÉS ÉTRANGÈRES : les lignes locales d'abord, l'annuaire ensuite.
        self::$db->query('DELETE FROM BUDGET_SCAN_ARTICLE');
        self::$db->query('DELETE FROM BUDGET_SCAN');
        self::$db->query('DELETE FROM FOYER_PERSONNE');
        self::$db->query('DELETE FROM FOYER');
        self::$db->query('DELETE FROM LOGS');
        self::$db->query('DELETE FROM PERSONNE');
        self::$db->query('DELETE FROM ' . self::tableMinuteur());
        self::$db->query('DELETE FROM ' . self::tableAnnuaire());

        self::$adminId = $this->creerIdentite('admin@example.test', 'Admine', 'Martin', 'Alex', 1, 0, 1);
        self::$membreId = $this->creerIdentite('membre@example.test', 'Membrine', 'Durand', 'Camille', 0, 0, 0);
        self::$bloqueId = $this->creerIdentite('bloque@example.test', 'Bloquine', '', '', 0, 1, 0);

        $this->creerPersonne(self::$adminId);
        $this->creerPersonne(self::$membreId);
        $this->creerPersonne(self::$bloqueId);
    }

    /**
     * Crée une IDENTITÉ dans l'annuaire de test, et rend son identifiant.
     *
     * C'est l'annuaire qui engendre l'identifiant : la ligne locale le REPREND
     * ({@see self::creerPersonne()}).
     */
    protected function creerIdentite(
        string $email,
        string $pseudonyme,
        string $nom = '',
        string $prenom = '',
        int $estAdmin = 0,
        int $estBloque = 0,
        int $emailValide = 1
    ): int {
        $stmt = self::$db->prepare(
            'INSERT INTO ' . self::tableAnnuaire() . '
             (EMAIL, PSEUDONYME, NOM, PRENOM, IS_ADMIN, IS_BLOQUE, EMAIL_VALID)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssiii', $email, $pseudonyme, $nom, $prenom, $estAdmin, $estBloque, $emailValide);
        $stmt->execute();
        $id = (int) self::$db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Crée la ligne LOCALE d'une personne — son admission sur ce site. ACTIVE par défaut : la
     * quasi-totalité des tests parlent de quelqu'un qui entre.
     */
    protected function creerPersonne(int $personneId, int $actif = 1, ?int $auteur = null): void
    {
        $auteur ??= $personneId;
        $stmt = self::$db->prepare(
            'INSERT INTO PERSONNE (ID, CREATED_BY, LAST_MODIFIED_BY, IS_ACTIF) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iiii', $personneId, $auteur, $auteur, $actif);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Crée un foyer, y rattache ces personnes, et rend son identifiant.
     *
     * @param list<int> $membres
     */
    protected function creerFoyer(string $nom, array $membres = []): int
    {
        $auteur = self::$adminId;
        $stmt = self::$db->prepare('INSERT INTO FOYER (NOM, CREATED_BY, LAST_MODIFIED_BY) VALUES (?, ?, ?)');
        $stmt->bind_param('sii', $nom, $auteur, $auteur);
        $stmt->execute();
        $foyerId = (int) self::$db->insert_id;
        $stmt->close();

        foreach ($membres as $personneId) {
            self::$db->query(
                "INSERT INTO FOYER_PERSONNE (FOYER_ID, PERSONNE_ID, CREATED_BY, LAST_MODIFIED_BY)
                 VALUES ($foyerId, $personneId, $auteur, $auteur)"
            );
        }

        return $foyerId;
    }

    /**
     * Crée une dépense et ses articles, et rend son identifiant.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $articles [nom, montant, monnaie]
     */
    protected function creerDepense(
        int $foyerId,
        int $personneId,
        string $date,
        array $articles = [['Pain', '3.50']],
        string $vendeur = 'Boulangerie',
        string $statut = 'ACTIF'
    ): int {
        $stmt = self::$db->prepare(
            'INSERT INTO BUDGET_SCAN (FOYER_ID, PERSONNE_ID, DATE_DOCUMENT, VENDEUR, STATUT, CREATED_BY, LAST_MODIFIED_BY)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssii', $foyerId, $personneId, $date, $vendeur, $statut, $personneId, $personneId);
        $stmt->execute();
        $scanId = (int) self::$db->insert_id;
        $stmt->close();

        foreach ($articles as $article) {
            $monnaie = $article[2] ?? 'CHF';
            $stmt = self::$db->prepare(
                'INSERT INTO BUDGET_SCAN_ARTICLE (SCAN_ID, NOM, MONTANT, MONNAIE, CREATED_BY, LAST_MODIFIED_BY) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isssii', $scanId, $article[0], $article[1], $monnaie, $personneId, $personneId);
            $stmt->execute();
            $stmt->close();
        }

        return $scanId;
    }

    /** Ouvre une session authentifiée sur cette identité. */
    protected function connecter(int $personneId): void
    {
        $_SESSION[Authentification::CLE_ETAPE] = 'OK';
        $_SESSION[Authentification::CLE_PERSONNE] = $personneId;
        Compte::reinitialiser();
    }

    /**
     * La ligne locale d'une personne, ou null si ce site ne la connaît pas.
     *
     * @return array<string, mixed>|null
     */
    protected function personne(int $personneId): ?array
    {
        return self::$db->query('SELECT * FROM PERSONNE WHERE ID = ' . $personneId)->fetch_assoc();
    }

    /**
     * Les entrées du journal de cette application, dans l'ordre d'écriture.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function journal(): array
    {
        return self::$db->query('SELECT * FROM LOGS ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
    }

    /** Le code d'authentification actuellement posé sur une identité, ou null. */
    protected function codeAuthDe(int $personneId): ?string
    {
        $row = self::$db->query(
            'SELECT CODE_AUTH FROM ' . self::tableAnnuaire() . ' WHERE ID = ' . $personneId
        )->fetch_assoc();

        return $row['CODE_AUTH'] ?? null;
    }

    /** Le code de changement d'adresse actuellement posé sur une identité, ou null. */
    protected function codeEmailDe(int $personneId): ?string
    {
        $row = self::$db->query(
            'SELECT EMAIL_NEW_VALID_CODE FROM ' . self::tableAnnuaire() . ' WHERE ID = ' . $personneId
        )->fetch_assoc();

        return $row['EMAIL_NEW_VALID_CODE'] ?? null;
    }

    /**
     * Emails capturés par le point de substitution.
     *
     * @return array<int, array{destinataire: string, sujet: string, corps: string, html: ?string}>
     */
    protected function emailsEnvoyes(): array
    {
        return $GLOBALS['emails_envoyes'] ?? [];
    }

    /**
     * Joue une requête complète à travers le point d'entrée unique, et rend le HTML produit.
     *
     * `require` et non `require_once` : la page est rejouée à chaque test du processus, sans
     * quoi seul le premier produirait une sortie.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @param string                $chemin Chemin demandé (« /cgu », « /rgpd » : les pages servies d'après lui).
     */
    protected function requete(array $get = [], array $post = [], string $methode = 'GET', string $chemin = '/'): string
    {
        $_GET = $get;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $methode;
        $_SERVER['REQUEST_URI'] = $chemin;
        \Foyer\App\Utils::oublierRedirection();
        // La mémorisation de l'identité est de PORTÉE REQUÊTE : deux requêtes successives d'un
        // même test doivent relire la base, comme deux chargements de page le feraient.
        \Foyer\App\Compte::reinitialiser();

        ob_start();

        try {
            require __DIR__ . '/../../web/index.php';
        } catch (\Foyer\App\SortieException) {
            // Fin de requête : redirection ou page servie. Le processus de test survit.
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** Destination de la redirection produite par la dernière requête, ou null. */
    protected function redirection(): ?string
    {
        return \Foyer\App\Utils::derniereRedirection();
    }

    /** Pose le jeton CSRF dans la session et le rend, pour composer une soumission valide. */
    protected function jetonCsrf(): string
    {
        return \Foyer\App\Utils::jetonCsrf();
    }

    /**
     * Rejoue un scénario avec une configuration du composant DÉRIVÉE de la vraie, où seules les
     * valeurs nommées changent.
     *
     * La configuration est un objet en lecture seule à quinze paramètres : la recopier à la main
     * dans chaque test qui veut en changer un seul, c'est se condamner à oublier le seizième le
     * jour où il paraît — et le test tournerait alors sur une valeur par défaut, sans rien dire.
     *
     * @param array<string, mixed> $remplacements
     */
    protected function avecConfiguration(array $remplacements, callable $scenario): void
    {
        $reelle = \Personnes\Auth\Configuration::courante();

        $parametres = [];
        foreach ((new \ReflectionClass(\Personnes\Auth\Configuration::class))->getConstructor()->getParameters() as $parametre) {
            $nom = $parametre->getName();
            $parametres[$nom] = $remplacements[$nom] ?? $reelle->{$nom};
        }

        \Personnes\Auth\Configuration::definir(new \Personnes\Auth\Configuration(...$parametres));

        try {
            $scenario();
        } finally {
            \Personnes\Auth\Configuration::definir($reelle);
            Compte::reinitialiser();
        }
    }

    /**
     * Installe une connexion identique à la vraie, sauf pour la préparation des requêtes, que
     * $preparation décide.
     *
     * Tout le reste est délégué à la vraie connexion, transactions et points de reprise
     * compris : c'est une connexion normale, avec un trou à un endroit choisi.
     */
    private function avecConnexionModifiee(\Closure $preparation, callable $scenario): void
    {
        $vraieDb = self::$db;

        $mock = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare', 'query', 'begin_transaction', 'commit', 'rollback'])
            ->getMock();
        $mock->method('prepare')->willReturnCallback(
            static fn (string $sql) => $preparation($sql, $vraieDb)
        );
        $mock->method('query')->willReturnCallback(static fn (string $sql) => $vraieDb->query($sql));
        $mock->method('begin_transaction')->willReturnCallback(static fn (): bool => $vraieDb->begin_transaction());
        $mock->method('commit')->willReturnCallback(static fn (): bool => $vraieDb->commit());
        $mock->method('rollback')->willReturnCallback(static fn (): bool => $vraieDb->rollback());

        // LA CONNEXION DE L'APPLICATION AUSSI : le verdict d'accès et le journal la prennent du
        // singleton Database, et non de la configuration du composant. Un trou posé seulement sur
        // celle du composant laisserait leurs requêtes passer par la vraie connexion.
        Database::setConnection($mock);

        try {
            $this->avecConfiguration(['connexion' => static fn (): \mysqli => $mock], $scenario);
        } finally {
            Database::setConnection($vraieDb);
        }
    }

    /**
     * Rejoue un scénario avec une connexion dont UNE requête se prépare mais ne s'EXÉCUTE pas.
     *
     * Distinct de {@see self::avecPrepareEnEchec()}, et les deux branches sont distinctes dans
     * le code : une requête peut être bien formée et échouer à l'écriture — verrou, contrainte,
     * connexion coupée entre les deux. C'est le cas le plus proche d'une panne réelle.
     */
    protected function avecExecuteEnEchec(string $motif, callable $scenario): void
    {
        $stmtRate = $this->createMock(\mysqli_stmt::class);
        $stmtRate->method('bind_param')->willReturn(true);
        $stmtRate->method('execute')->willReturn(false);
        $stmtRate->method('close')->willReturn(true);

        $this->avecConnexionModifiee(
            static fn (string $sql, \mysqli $vraie) => str_contains($sql, $motif) ? $stmtRate : $vraie->prepare($sql),
            $scenario
        );
    }

    /**
     * Rejoue un scénario avec une connexion dont UNE requête précise ne se prépare pas.
     *
     * C'est le seul moyen d'atteindre les refus défensifs — « Erreur base de données » — que
     * chaque écriture oppose quand la requête ne se prépare même pas. Ces branches ne sont pas
     * décoratives : ce sont elles qui décident qu'une panne ne se traduit pas en « code
     * invalide », et une branche jamais exécutée est une branche dont on ne sait rien.
     */
    protected function avecPrepareEnEchec(string $motif, callable $scenario): void
    {
        $this->avecConnexionModifiee(
            static fn (string $sql, \mysqli $vraie) => str_contains($sql, $motif) ? false : $vraie->prepare($sql),
            $scenario
        );
    }

    /**
     * Vide le minuteur anti-force-brute.
     *
     * Le minuteur DOUBLE le délai à chaque tentative autorisée : sans cette remise à zéro, un
     * test qui enchaîne deux demandes se verrait opposer un refus qui n'a rien à voir avec ce
     * qu'il mesure.
     */
    protected function viderMinuteur(): void
    {
        self::$db->query('DELETE FROM ' . self::tableMinuteur());
    }
}
