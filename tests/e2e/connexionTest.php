<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;

require_once __DIR__ . '/E2ETestBase.php';

/**
 * LE PARCOURS DE CONNEXION, DE BOUT EN BOUT, par le module d'authentification partagé.
 *
 * Ce que ce fichier prouve et qu'aucun test unitaire ne peut prouver : qu'une personne qui arrive
 * sur l'application, saisit son adresse, recopie le code reçu et coche les conditions se retrouve
 * bel et bien chez elle — avec un cookie de session qui a survécu à quatre requêtes HTTP et à la
 * régénération d'identifiant que l'authentification opère.
 *
 * Il vaut aussi pour les applications intégratrices : le composant exercé ici est, au fichier près,
 * celui qu'elles installent.
 */
class ConnexionTest extends E2ETestBase
{
    #[TestDox('Une personne connue se connecte et arrive sur la page d’accueil')]
    public function testLeParcoursCompletMeneALAccueil(): void
    {
        $id = $this->creerIdentite('lea@example.test', 'Leandra');

        // Étape 1 : l'écran de connexion du composant, puis la saisie de l'adresse.
        $page = $this->get('/');
        $this->assertSame(200, $page['statut']);
        $this->assertStringContainsString('personnes-carte-connexion', $page['corps']);

        $reponse = $this->post('/', [
            'action' => 'email',
            'email' => 'lea@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $this->assertSame(302, $reponse['statut']);

        // Le code est lu en base : aucun serveur SMTP ne tourne, et un test end-to-end n'a pas de
        // boîte de réception.
        $code = $this->codeAuthDe($id);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{6}$/', $code);

        // Étape 2 : le code, et la case des conditions que le navigateur poste réellement.
        $page = $this->get('/');
        $this->assertStringContainsString('personnes-champ-code', $page['corps']);

        $reponse = $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $this->assertSame(302, $reponse['statut']);
        // LA REDIRECTION MÈNE À L'ACCUEIL, la racine nue : c'est là qu'on arrive en se connectant.
        $this->assertSame('/', $reponse['location']);

        // Arrivée : la page d'accueil, et le menu qui n'existe que pour une session ouverte.
        $accueil = $this->get('/');
        $this->assertSame(200, $accueil['statut']);
        $this->assertStringContainsString('id="page-accueil"', $accueil['corps']);
        $this->assertStringContainsString('id="navCompte"', $accueil['corps']);
    }

    #[TestDox('Le code est consommé : le rejouer ne rouvre pas de session')]
    public function testUnCodeDejaConsommeNeVautPlusRien(): void
    {
        $id = $this->creerIdentite('marc@example.test', 'Marcus');
        $page = $this->get('/');
        $this->post('/', [
            'action' => 'email',
            'email' => 'marc@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $code = $this->codeAuthDe($id);

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $this->assertSame('', $this->codeAuthDe($id), 'Le code doit être effacé par sa consommation.');

        // Une seconde session, repartie de zéro, ne peut rien faire de ce code.
        $this->deconnecter();
        $page = $this->get('/');
        $this->post('/', [
            'action' => 'email',
            'email' => 'marc@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        $apres = $this->get('/');
        $this->assertStringContainsString('personnes-carte-connexion', $apres['corps']);
    }

    #[TestDox('Sans la case des conditions, le code n’est pas consommé')]
    public function testLesConditionsRefuseesNeConsommentPasLeCode(): void
    {
        $id = $this->creerIdentite('nina@example.test', 'Ninon');
        $page = $this->get('/');
        $this->post('/', [
            'action' => 'email',
            'email' => 'nina@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $code = $this->codeAuthDe($id);

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        // LE CODE SURVIT : on recoche, on resoumet le même, et il vaut toujours. C'est ce qui rend
        // l'oubli d'une case rattrapable sans redemander un code.
        $this->assertSame($code, $this->codeAuthDe($id));

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $this->assertStringContainsString('id="page-accueil"', $this->get('/')['corps']);
    }

    #[TestDox('Une adresse inconnue se comporte exactement comme une adresse connue')]
    public function testUneAdresseInconnueNeSeDistinguePas(): void
    {
        // ANTI-ÉNUMÉRATION : si l'écran répondait autre chose, n'importe qui pourrait savoir
        // quelles adresses ont un compte sur la plateforme.
        $this->creerIdentite('connue@example.test', 'Connue');

        $page = $this->get('/');
        $connue = $this->post('/', [
            'action' => 'email',
            'email' => 'connue@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $ecranConnue = $this->get('/')['corps'];

        $this->deconnecter();

        $page = $this->get('/');
        $inconnue = $this->post('/', [
            'action' => 'email',
            'email' => 'inconnue@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);
        $ecranInconnue = $this->get('/')['corps'];

        $this->assertSame($connue['statut'], $inconnue['statut']);
        $this->assertStringContainsString('personnes-champ-code', $ecranConnue);
        $this->assertStringContainsString('personnes-champ-code', $ecranInconnue);
    }

    #[TestDox('Un compte bloqué ne franchit pas la porte, et ne le sait pas')]
    public function testUnCompteBloqueNeSeConnectePas(): void
    {
        $id = $this->creerIdentite('paul@example.test', 'Paulus');
        self::$db->query('UPDATE ' . self::tableAnnuaire('PERSONNE') . ' SET IS_BLOQUE = 1 WHERE ID = ' . $id);

        $page = $this->get('/');
        $reponse = $this->post('/', [
            'action' => 'email',
            'email' => 'paul@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        $this->assertSame(302, $reponse['statut']);
        // Aucun code n'est émis, et l'écran passe pourtant à l'étape 2 : le verrou global se
        // comporte comme une absence, le dire reviendrait à confirmer que le compte existe.
        $this->assertSame('', $this->codeAuthDe($id));
        $this->assertStringContainsString('personnes-champ-code', $this->get('/')['corps']);
    }

    #[TestDox('La déconnexion referme la session')]
    public function testLaDeconnexionRefermeLaSession(): void
    {
        $this->creerIdentite('zoe@example.test', 'Zoelie');
        $this->seConnecter('zoe@example.test');
        $this->assertStringContainsString('id="page-accueil"', $this->get('/')['corps']);

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'deconnexion',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        $this->assertStringContainsString('personnes-carte-connexion', $this->get('/')['corps']);
    }

    #[TestDox('Une soumission sans jeton CSRF est refusée')]
    public function testUneSoumissionSansJetonEstRefusee(): void
    {
        $id = $this->creerIdentite('yann@example.test', 'Yannick');

        $reponse = $this->post('/', ['action' => 'email', 'email' => 'yann@example.test']);

        $this->assertSame(302, $reponse['statut']);
        $this->assertSame('', $this->codeAuthDe($id), 'Aucun code ne doit être émis sans jeton.');
    }

    /**
     * LE VERDICT D'ACCÈS, SERVI PAR UN VRAI SERVEUR : une identité de l'annuaire dont l'accès à ce
     * site est FERMÉ reçoit son code — l'étape 1 ne distingue rien — puis se fait refuser à
     * l'étape 2, sans session ouverte. Le code n'est pas consommé : le refus défait la
     * transaction qui le consommait, de sorte que le même code servira une fois l'accès ouvert.
     */
    #[TestDox('Un accès fermé reçoit son code, puis se fait refuser à l’étape 2')]
    public function testUnAccesFermeEstRefuseALEtapeDeux(): void
    {
        $id = $this->creerIdentite('dehors@example.test', 'Dehors', false, false);

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'email',
            'email' => 'dehors@example.test',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        // L'ÉTAPE 1 NE DISTINGUE RIEN : le code est bel et bien émis.
        $code = $this->codeAuthDe($id);
        $this->assertNotSame('', $code);

        $page = $this->get('/');
        $this->post('/', [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf($page['corps']),
        ]);

        // Aucune session : la porte est restée fermée.
        $this->assertStringContainsString('personnes-carte-connexion', $this->get('/')['corps']);
        // Et le code n'a pas été consommé — le refus a défait la transaction qui le consommait.
        $this->assertSame($code, $this->codeAuthDe($id));
    }

    /** Repart d'une session vierge, comme un autre navigateur le ferait. */
    private function deconnecter(): void
    {
        $page = $this->get('/');
        $jeton = $this->jetonCsrf($page['corps']);
        $this->post('/', ['action' => 'deconnexion', 'csrf_token' => $jeton]);
    }
}
