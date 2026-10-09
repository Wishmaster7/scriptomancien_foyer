<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base des tests end-to-end : un vrai serveur HTTP, une vraie base, aucun raccourci.
 *
 * CE QUE CES TESTS APPORTENT QUE LES AUTRES NE PEUVENT PAS. La suite unitaire appelle
 * `web/index.php` dans le processus de PHPUnit : elle prouve que le code décide juste, mais elle
 * partage sa session, ses en-têtes et ses variables globales avec le harnais. Un parcours de
 * connexion, lui, tient à des choses que seul un serveur possède — un cookie de session qui
 * survit à trois requêtes, une redirection réellement suivie, un identifiant de session régénéré
 * après authentification. C'est ce que cette base met en place.
 *
 * Le serveur intégré sert `web/`, exactement l'arborescence déployée, avec le SCHÉMA DE TEST passé
 * par l'environnement — jamais celui de production.
 */
abstract class E2ETestBase extends TestCase
{
    /** @var resource|null */
    private static $serveur;

    /** @var array<int, resource> */
    private static array $tuyaux = [];

    /**
     * Journal du serveur intégré.
     *
     * Il écrit dans un FICHIER et non dans une pipe : une pipe que personne ne lit se remplit puis
     * SATURE, et le serveur reste alors bloqué en écriture — il cesse de répondre, sans erreur, au
     * bout de quelques dizaines de requêtes.
     */
    private static string $journal = '';

    protected static string $baseUrl = '';
    protected static \mysqli $db;

    private string $cookies = '';

    public static function setUpBeforeClass(): void
    {
        $hote = '127.0.0.1';
        // Un port à nous : une autre suite en occupe un autre, et les
        // deux doivent pouvoir tourner côte à côte.
        $port = 8091;
        self::$baseUrl = "http://$hote:$port";

        $environnement = getenv();
        $environnement['DB_HOSTNAME'] = getenv('TEST_DB_HOSTNAME') ?: '127.0.0.1';
        $environnement['DB_USERNAME'] = getenv('TEST_DB_USERNAME') ?: 'root';
        $environnement['DB_PASSWORD'] = getenv('TEST_DB_PASSWORD') ?: '';
        $environnement['DB_DATABASE'] = self::schema();
        // L'ANNUAIRE D'IDENTITÉ DE TEST : la vue et les clés étrangères du schéma de test le visent
        // déjà, et le composant servi doit viser le même (cf. tests/bootstrap.php).
        $environnement['DB_PERSONNES'] = self::schemaAnnuaire();

        self::$journal = (string) tempnam(sys_get_temp_dir(), 'e2e_foyer_');
        $descripteurs = [
            1 => ['file', self::$journal, 'a'],
            2 => ['file', self::$journal, 'a'],
        ];

        self::$serveur = proc_open(
            [
                PHP_BINARY,
                '-d', 'auto_prepend_file=' . __DIR__ . '/preludeServeur.php',
                '-S', "$hote:$port",
                '-t', dirname(__DIR__, 2) . '/web',
            ],
            $descripteurs,
            self::$tuyaux,
            null,
            $environnement
        );

        if (!is_resource(self::$serveur)) {
            self::fail('Impossible de démarrer le serveur PHP intégré.');
        }
        self::attendreLeServeur();

        $db = new \mysqli(
            $environnement['DB_HOSTNAME'],
            $environnement['DB_USERNAME'],
            $environnement['DB_PASSWORD'],
            self::schema()
        );
        if ($db->connect_error) {
            self::fail('Impossible de se connecter à ' . self::schema() . ' : ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        $db->query("SET SESSION sql_mode = '" . \Foyer\App\Database::SQL_MODE . "'");

        // Garde-fou : ces tests ÉCRIVENT en base, hors transaction — le serveur a sa propre
        // connexion. Se tromper de schéma coûterait l'annuaire de développement.
        $nom = $db->query('SELECT DATABASE()')->fetch_row()[0];
        self::assertSame(self::schema(), $nom, 'Les tests doivent tourner sur la base de test !');

        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            self::viderLesTables();
        }
        if (is_resource(self::$serveur)) {
            proc_terminate(self::$serveur);
            proc_close(self::$serveur);
        }
        foreach (self::$tuyaux as $tuyau) {
            if (is_resource($tuyau)) {
                fclose($tuyau);
            }
        }
        if (self::$journal !== '' && is_file(self::$journal)) {
            unlink(self::$journal);
            self::$journal = '';
        }
    }

    protected function setUp(): void
    {
        // UN BOCAL À COOKIES PAR TEST : deux scénarios qui partageraient leur session
        // s'authentifieraient l'un pour l'autre, et le second ne prouverait plus rien.
        $this->cookies = (string) tempnam(sys_get_temp_dir(), 'e2e_cookies_');
        self::viderLesTables();
    }

    protected function tearDown(): void
    {
        if ($this->cookies !== '' && is_file($this->cookies)) {
            unlink($this->cookies);
        }
    }

    /** Schéma de test de CETTE application — jamais celui de développement. */
    protected static function schema(): string
    {
        return getenv('TEST_DB_DATABASE') ?: '3t75aa_foyer_phpunit';
    }

    /** Schéma de test de l'ANNUAIRE D'IDENTITÉ — propre à ce projet, jamais celui d'un voisin. */
    protected static function schemaAnnuaire(): string
    {
        return getenv('TEST_DB_PERSONNES') ?: '3t75aa_foyer_personnes_phpunit';
    }

    /** Le nom, en accents graves, d'une table de l'annuaire d'identité de test. */
    protected static function tableAnnuaire(string $table): string
    {
        return '`' . self::schemaAnnuaire() . '`.`' . $table . '`';
    }

    /**
     * Vide les tables.
     *
     * Les écritures des tests end-to-end sont COMMITTÉES : le serveur a sa propre connexion, hors
     * de la transaction que le harnais unitaire déroule. Sans ce nettoyage, la clé unique de
     * l'adresse email ferait échouer le semis du test suivant.
     */
    private static function viderLesTables(): void
    {
        // L'ORDRE EST CELUI DES CLÉS ÉTRANGÈRES : les tables locales d'abord, l'annuaire ensuite.
        self::$db->query('DELETE FROM LOGS');
        self::$db->query('DELETE FROM PERSONNE');
        self::$db->query('DELETE FROM ' . self::tableAnnuaire('RATE_LIMIT'));
        self::$db->query('DELETE FROM ' . self::tableAnnuaire('PERSONNE'));
    }

    /**
     * Insère une identité dans l'annuaire ET sa ligne d'admission sur ce site, et rend son
     * identifiant.
     *
     * LES DEUX, parce qu'une identité seule n'entre pas : le verdict d'accès la refuserait
     * ({@see \Foyer\App\AuthentificationModel::admettre()}). $actif ferme la porte sans retirer
     * l'identité, pour les scénarios qui éprouvent le refus.
     */
    protected function creerIdentite(string $email, string $pseudonyme, bool $admin = false, bool $actif = true): int
    {
        $stmt = self::$db->prepare(
            'INSERT INTO ' . self::tableAnnuaire('PERSONNE') . ' (EMAIL, PSEUDONYME, IS_ADMIN, IS_BLOQUE, EMAIL_VALID)
             VALUES (?, ?, ?, 0, 1)'
        );
        $estAdmin = $admin ? 1 : 0;
        $stmt->bind_param('ssi', $email, $pseudonyme, $estAdmin);
        $stmt->execute();
        $id = (int) self::$db->insert_id;
        $stmt->close();

        $stmt = self::$db->prepare(
            'INSERT INTO PERSONNE (ID, CREATED_BY, LAST_MODIFIED_BY, IS_ACTIF) VALUES (?, ?, ?, ?)'
        );
        $estActif = $actif ? 1 : 0;
        $stmt->bind_param('iiii', $id, $id, $id, $estActif);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    /**
     * Le code d'authentification posé en base pour cette identité.
     *
     * AUCUN SERVEUR SMTP NE TOURNE ICI, et un test end-to-end n'a pas de boîte de réception : cette
     * lecture remplace la consultation de l'email. Le code y est déjà écrit au moment où l'envoi
     * est tenté.
     */
    protected function codeAuthDe(int $personneId): string
    {
        $ligne = self::$db->query(
            'SELECT CODE_AUTH FROM ' . self::tableAnnuaire('PERSONNE') . ' WHERE ID = ' . $personneId
        )->fetch_assoc();

        return (string) ($ligne['CODE_AUTH'] ?? '');
    }

    /**
     * GET, cookies conservés, redirections NON suivies — c'est la redirection elle-même qu'on
     * vérifie.
     *
     * @return array{statut: int, corps: string, location: ?string}
     */
    protected function get(string $chemin): array
    {
        return $this->requete($chemin, null);
    }

    /**
     * POST en `application/x-www-form-urlencoded`, comme un navigateur.
     *
     * @param  array<string, string> $champs
     * @return array{statut: int, corps: string, location: ?string}
     */
    protected function post(string $chemin, array $champs): array
    {
        return $this->requete($chemin, $champs);
    }

    /**
     * @param  array<string, string>|null $champs
     * @return array{statut: int, corps: string, location: ?string}
     */
    private function requete(string $chemin, ?array $champs): array
    {
        $ch = curl_init(self::$baseUrl . $chemin);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($champs !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($champs));
        }

        // Pas de `curl_close()` : depuis PHP 8.0 la ressource se libère toute seule, et l'appel est
        // déprécié depuis 8.5 — PHPUnit transforme la dépréciation en défaut de suite.
        $reponse = (string) curl_exec($ch);
        $statut = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tailleEntetes = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $entetes = substr($reponse, 0, $tailleEntetes);
        preg_match('/^Location:\s*(.+)$/mi', $entetes, $trouve);

        return [
            'statut' => $statut,
            'corps' => substr($reponse, $tailleEntetes),
            'location' => isset($trouve[1]) ? trim($trouve[1]) : null,
        ];
    }

    /** Le jeton CSRF caché dans le HTML servi — une soumission sans lui est refusée. */
    protected function jetonCsrf(string $html): string
    {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $trouve);
        self::assertNotEmpty($trouve[1] ?? '', 'Aucun jeton CSRF dans la page servie.');

        return $trouve[1];
    }

    /**
     * Déroule le parcours de connexion complet et rend l'identifiant de la personne connectée.
     *
     * Il est écrit ICI plutôt que recopié : plusieurs scénarios commencent par une session ouverte
     * sans que ce soit ce qu'ils mesurent.
     */
    protected function seConnecter(string $email): void
    {
        $page = $this->get('/');
        $this->post('/', [
            'action' => 'email',
            'email' => $email,
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        $ligne = self::$db->query(
            'SELECT ID FROM ' . self::tableAnnuaire('PERSONNE')
            . " WHERE EMAIL = '" . self::$db->real_escape_string($email) . "'"
        )->fetch_assoc();

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $this->codeAuthDe((int) $ligne['ID']),
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
    }

    /** Attend que le serveur intégré réponde, ou échoue en le disant. */
    private static function attendreLeServeur(): void
    {
        for ($essai = 0; $essai < 50; $essai++) {
            $ch = curl_init(self::$baseUrl . '/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
            curl_exec($ch);
            $erreur = curl_errno($ch);
            if ($erreur === 0) {
                return;
            }
            usleep(100000);
        }

        self::fail('Le serveur PHP intégré n\'a pas répondu : ' . @file_get_contents(self::$journal));
    }
}
