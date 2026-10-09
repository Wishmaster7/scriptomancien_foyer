<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestBase.php';

/**
 * LE JOURNAL DE LA PORTE : la connexion et la déconnexion laissent chacune leur entrée.
 */
class AuthentificationControllerTest extends TestBase
{
    public function testUneConnexionReussieEstJournalisee(): void
    {
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test', 'csrf_token' => $this->jetonCsrf()], 'POST');
        $code = (string) $this->codeAuthDe(self::$membreId);
        $this->viderMinuteur();

        $this->requete([], [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf(),
        ], 'POST');

        $this->assertSame('/', $this->redirection());
        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame('connexion', $journal[0]['TYPE']);
        $this->assertSame('Connexion utilisateur', $journal[0]['DESCRIPTION']);
        $this->assertSame(self::$membreId, (int) $journal[0]['CREATED_BY']);
        $this->assertSame(self::$membreId, (int) $journal[0]['PERSONNE_ID']);
    }

    public function testUneDemandeDeCodeNEstPasUneConnexion(): void
    {
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test', 'csrf_token' => $this->jetonCsrf()], 'POST');

        $this->assertSame([], $this->journal());
    }

    public function testLaDeconnexionEstJournaliseeAvantDeFermerLaSession(): void
    {
        $this->connecter(self::$membreId);

        $this->requete([], ['action' => 'deconnexion', 'csrf_token' => $this->jetonCsrf()], 'POST');

        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame('deconnexion', $journal[0]['TYPE']);
        $this->assertSame('Déconnexion utilisateur', $journal[0]['DESCRIPTION']);
        $this->assertSame(self::$membreId, (int) $journal[0]['CREATED_BY']);
    }

    public function testUneDeconnexionSansSessionNeLaisseAucuneTrace(): void
    {
        $this->requete([], ['action' => 'deconnexion', 'csrf_token' => $this->jetonCsrf()], 'POST');

        $this->assertSame('/', $this->redirection());
        $this->assertSame([], $this->journal());
    }
}
