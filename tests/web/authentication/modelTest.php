<?php

declare(strict_types=1);

use Foyer\App\AuthentificationModel;

require_once __DIR__ . '/../TestBase.php';

/**
 * LE VERDICT D'ACCÈS : qui entre sur ce site, et qui s'en fait refuser la porte.
 *
 * C'est la seule chose que l'application décide de l'authentification — le flux entier appartient
 * au composant. S'AUTHENTIFIER PROUVE UNE IDENTITÉ, JAMAIS UN DROIT : ces tests éprouvent
 * précisément l'écart entre les deux.
 */
class AuthentificationModelTest extends TestBase
{
    // =========================================================
    // estConnue() — la porte de l'étape 1
    // =========================================================

    public function testUnePersonneAdmiseEstConnueDesLEtapeUn(): void
    {
        $this->assertTrue(AuthentificationModel::estConnue(self::$membreId));
    }

    /**
     * UNE IDENTITÉ DE L'ANNUAIRE SANS LIGNE ICI N'EST PAS CONNUE : elle a été admise dans une
     * autre application de la plateforme, pas sur ce site, et n'en reçoit donc aucun code.
     */
    public function testUneIdentiteSansLigneLocaleNEstPasConnue(): void
    {
        $orpheline = $this->creerIdentite('orpheline@example.test', 'Orpheline');

        $this->assertFalse(AuthentificationModel::estConnue($orpheline));
    }

    /**
     * PRÉSENCE SEULE, JAMAIS IS_ACTIF : une ligne désactivée reçoit son code, et c'est l'étape 2
     * qui le refuse. Répondre « non » dès l'étape 1 distinguerait pour elle deux cas que le
     * message de refus prend soin de confondre.
     */
    public function testUnePersonneDesactiveeResteConnueALEtapeUn(): void
    {
        self::$db->query('UPDATE PERSONNE SET IS_ACTIF = 0 WHERE ID = ' . self::$membreId);

        $this->assertTrue(AuthentificationModel::estConnue(self::$membreId));
    }

    // =========================================================
    // admettre() — le verdict de l'étape 2
    // =========================================================

    public function testUnePersonneActiveEstAdmise(): void
    {
        $this->assertNull(AuthentificationModel::admettre(self::$membreId));
    }

    /** L'ADMISSION DATE L'ACCEPTATION DES CONDITIONS ET LA DERNIÈRE CONNEXION, par l'horloge de la BASE. */
    public function testLAdmissionDateLAcceptationEtLaDerniereConnexion(): void
    {
        $avant = $this->personne(self::$membreId);
        $this->assertNull($avant['ACCEPT_CONDITIONS_WHEN']);
        $this->assertNull($avant['DERNIERE_CONNEXION_WHEN']);

        AuthentificationModel::admettre(self::$membreId);

        $apres = $this->personne(self::$membreId);
        $this->assertNotNull($apres['ACCEPT_CONDITIONS_WHEN']);
        $this->assertNotNull($apres['DERNIERE_CONNEXION_WHEN']);
    }

    /** L'ADMISSION EST JOURNALISÉE, dans la transaction même qui consomme le code. */
    public function testLAdmissionEstJournalisee(): void
    {
        AuthentificationModel::admettre(self::$membreId);

        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame('connexion', $journal[0]['TYPE']);
        $this->assertSame(self::$membreId, (int) $journal[0]['PERSONNE_ID']);
    }

    /** UNE LIGNE DÉSACTIVÉE EST REFUSÉE : appartenir à la plateforme n'ouvre pas cette porte-ci. */
    public function testUnePersonneDesactiveeEstRefusee(): void
    {
        self::$db->query('UPDATE PERSONNE SET IS_ACTIF = 0 WHERE ID = ' . self::$membreId);

        $this->assertSame(
            AuthentificationModel::MESSAGE_ACCES_REFUSE,
            AuthentificationModel::admettre(self::$membreId)
        );
    }

    /**
     * AUCUN PROVISIONNEMENT : une identité sans ligne ici est refusée, et RIEN n'est écrit. Une
     * application qui créerait la ligne d'office ferait de l'annuaire une liste d'invités.
     */
    public function testUneIdentiteSansLigneLocaleEstRefuseeSansRienEcrire(): void
    {
        $orpheline = $this->creerIdentite('orpheline@example.test', 'Orpheline');

        $this->assertSame(
            AuthentificationModel::MESSAGE_ACCES_REFUSE,
            AuthentificationModel::admettre($orpheline)
        );
        $this->assertNull($this->personne($orpheline));
        $this->assertSame([], $this->journal());
    }

    /**
     * LE MÊME MESSAGE POUR LES DEUX REFUS : « inconnu ici » et « désactivé ici » ne se
     * distinguent pas, ou l'on apprendrait à qui possède une identité de la plateforme si telle
     * personne a un compte sur ce foyer.
     */
    public function testLesDeuxRefusSontIndiscernables(): void
    {
        $orpheline = $this->creerIdentite('orpheline@example.test', 'Orpheline');
        self::$db->query('UPDATE PERSONNE SET IS_ACTIF = 0 WHERE ID = ' . self::$membreId);

        $this->assertSame(
            AuthentificationModel::admettre($orpheline),
            AuthentificationModel::admettre(self::$membreId)
        );
    }

    /**
     * LE REFUS NE MET PAS EN CAUSE L'IDENTITÉ, qui reste valable ailleurs : le message renvoie
     * vers qui administre ce foyer, et ne parle ni du compte ni de l'adresse email.
     */
    public function testLeMessageDeRefusRenvoieALAdministrationDuFoyer(): void
    {
        $this->assertStringContainsString('administre ce foyer', AuthentificationModel::MESSAGE_ACCES_REFUSE);
    }
}
