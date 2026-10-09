<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestBase.php';

/**
 * La page d'accueil : ce qu'elle montre, et à qui.
 *
 * C'est la première page servie une fois le module d'authentification passé. Elle est donc, pour
 * cette application comme pour toute application intégratrice, l'endroit d'où l'on CONSTATE qu'une
 * connexion aboutit — le test end-to-end du parcours s'y arrête.
 */
class AccueilControllerTest extends TestBase
{
    /**
     * ELLE EST VIDE, ET C'EST CE QU'ON VÉRIFIE : son identifiant est servi, et c'est sa seule
     * marque tant qu'aucun écran de ce foyer n'est écrit.
     */
    public function testElleEstServieAUneSessionOuverte(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertStringContainsString('id="page-accueil"', $html);
        $this->assertStringNotContainsString('personnes-carte-connexion', $html);
    }

    /**
     * ELLE NE PORTE AUCUN BLOC : les écrans de ce foyer ne sont pas encore écrits, et « Mon
     * profil » est déjà dans le menu « Mon compte » que rend le composant — le seul endroit d'où
     * l'on y accède. Une carte sur l'accueil l'aurait répété sans rien ouvrir de plus.
     */
    public function testElleNeRepetePasLeProfilDuMenu(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertStringNotContainsString('Ouvrir mon profil', $html);
        // Le menu du composant, lui, le porte toujours : c'est la porte du profil.
        $this->assertStringContainsString('href="/?action=profil"', $html);
    }

    /**
     * ELLE MONTRE LA MÊME CHOSE À TOUT LE MONDE : ce site n'a aucun écran d'administration, et
     * l'accès s'y ouvre depuis l'application « personnes ». Un administrateur de la plateforme n'y
     * voit donc pas une porte de plus.
     */
    public function testElleNOffreAucunEcranDAdministration(): void
    {
        $this->connecter(self::$adminId);

        $html = $this->requete();

        $this->assertStringNotContainsString('Gérer les personnes', $html);
        $this->assertStringNotContainsString('action=admin_annuaire', $html);
    }

    public function testSansSessionLAccueilNEstPasServi(): void
    {
        $html = $this->requete();

        $this->assertStringNotContainsString('id="page-accueil"', $html);
        $this->assertStringContainsString('personnes-carte-connexion', $html);
    }
}
