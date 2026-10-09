<?php

declare(strict_types=1);

use Foyer\App\SiteConfig;
use Foyer\App\Utils;

require_once __DIR__ . '/../TestBase.php';

/**
 * LA BARRE DE NAVIGATION : le menu « Mon compte », et ce qu'il contient selon qui regarde.
 */
class TemplateHeaderTest extends TestBase
{
    public function testLeMenuMonCompteEstPresentPourUnConnecte(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertStringContainsString('id="navCompte"', $html);
        $this->assertStringContainsString('Mon compte', $html);
        $this->assertStringContainsString('dropdown-menu', $html);
    }

    /**
     * « MON COMPTE » EST LE MÊME MENU DANS TOUTES LES APPLICATIONS : le composant appelé avec le
     * seul jeton, sans entrée ajoutée — « Mon profil » et la déconnexion, rien d'autre, même pour un
     * administrateur. L'accueil et les textes légaux restent joignables par le bandeau et le pied de page.
     */
    public function testLeMenuMonCompteNOffreQueLeProfilEtLaDeconnexion(): void
    {
        $this->connecter(self::$adminId);

        $html = $this->requete();
        $menu = (string) strstr((string) strstr($html, 'aria-labelledby="navCompte"'), '</ul>', true);

        $this->assertStringContainsString('href="/?action=profil"', $menu);
        $this->assertStringContainsString('value="deconnexion"', $menu);
        $this->assertSame(1, substr_count($menu, '<a class="dropdown-item"'));
        $this->assertStringNotContainsString('href="/?action=admin_annuaire"', $menu);
        $this->assertStringNotContainsString('href="/cgu"', $menu);
        $this->assertStringNotContainsString('href="/rgpd"', $menu);
    }

    /**
     * LA BARRE NE PORTE QUE « MON COMPTE », pour tout le monde : ce site n'a aucun écran
     * d'administration, l'accès s'y ouvre depuis l'application « personnes », et un administrateur
     * de la plateforme n'y voit donc pas un menu de plus.
     */
    public function testLaBarreNePorteQueLeMenuMonCompte(): void
    {
        foreach ([self::$adminId, self::$membreId] as $personneId) {
            $this->connecter($personneId);
            $html = $this->requete();

            $this->assertStringContainsString('id="navCompte"', $html);
            $this->assertStringNotContainsString('id="navAdmin"', $html);
            $this->assertSame(1, substr_count($html, 'class="nav-item dropdown"'));
        }
    }

    public function testLaDeconnexionResteUneSoumissionAvecSonJeton(): void
    {
        // ELLE CHANGE L'ÉTAT DE LA SESSION : un GET ne doit rien changer, donc pas de lien. Le
        // bouton est habillé en entrée de menu, ce qui lui donne l'apparence sans lui retirer sa
        // nature — et le jeton CSRF voyage avec.
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertStringContainsString('<button type="submit" class="dropdown-item">', $html);
        $this->assertStringContainsString('name="action" value="deconnexion"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
    }

    public function testLaBarreNAffichePasLeMenuSansSession(): void
    {
        $html = $this->requete();

        $this->assertStringNotContainsString('id="navCompte"', $html);
        $this->assertStringNotContainsString('deconnexion', $html);
    }

    /**
     * LE BANDEAU DE SOMMET, sous la barre : wallpaper et logo appartiennent à CETTE application
     * (resources/css/foyer.css), et le composant n'en fournit rien. Ils coiffent la page connectée
     * comme celle de connexion. Toute sa surface mène à l'accueil, que l'on soit connecté ou non.
     */
    public function testLeBandeauDeSommetPorteLeLogoEtMeneALAccueil(): void
    {
        foreach ([true, false] as $connecte) {
            if ($connecte) {
                $this->connecter(self::$membreId);
            }

            $html = $this->requete();

            $this->assertStringContainsString('id="page-header"', $html);
            $this->assertStringContainsString('<a href="/" class="stretched-link" aria-label="Accueil"></a>', $html);
        }
    }

    /** La feuille du composant est chargée avant celle de l'application, qui garde le dernier mot. */
    public function testLaFeuilleDuComposantEstChargeeAvantCelleDeLApplication(): void
    {
        $html = $this->requete();

        $this->assertStringContainsString('/resources/personnes/css/connexion.css?v=', $html);
        $this->assertLessThan(
            (int) strpos($html, '/resources/css/foyer.css'),
            (int) strpos($html, '/resources/personnes/css/connexion.css'),
            "L'application garde le dernier mot : sa feuille vient après celle du composant."
        );
    }

    /**
     * Le thème sombre de Bootstrap redéfinit ses variables : il vient juste APRÈS lui, et AVANT la
     * feuille de l'application, dont la palette sombre doit garder le dernier mot.
     */
    public function testLeThemeSombreSeChargeEntreBootstrapEtLaFeuilleDeLApplication(): void
    {
        $html = $this->requete();

        $sombre = strpos($html, '<link rel="stylesheet" href="/resources/css/bootstrap-sombre.css">');
        $this->assertNotFalse($sombre);
        $bootstrap = strpos($html, '<link rel="stylesheet" href="/resources/css/bootstrap.min.css">');
        $this->assertNotFalse($bootstrap);
        $this->assertLessThan($sombre, $bootstrap);
        $this->assertLessThan((int) strpos($html, '/resources/css/foyer.css'), $sombre);
        $this->assertFileExists(__DIR__ . '/../../../web/resources/css/bootstrap-sombre.css');
    }

    /**
     * LE PIED DE PAGE EST SERVI SUR TOUTES LES PAGES : horloge, copyright et les deux textes légaux. Il n'est
     * PAS un composant partagé.
     */
    public function testLePiedDePageEstServiSurToutesLesPages(): void
    {
        foreach ([[[], '/'], [['action' => 'profil'], '/'], [[], '/cgu'], [[], '/rgpd']] as [$get, $chemin]) {
            $this->connecter(self::$membreId);
            $html = $this->requete($get, [], 'GET', $chemin);

            $this->assertStringContainsString('class="site-footer"', $html);
            $this->assertStringContainsString('<span id="current-date" data-role="horloge"></span>', $html);
            // L'horloge est un script SERVI : aucun script en ligne.
            $this->assertMatchesRegularExpression('#<script src="/resources/js/horloge\.js\?v=\d+"></script>#', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('<a href="/" class="lien-pied" data-role="lien-accueil-pied"><i class="fal fa-copyright"></i>', $html);
            $this->assertStringContainsString(SiteConfig::NOM_SITE, $html);
            $this->assertStringContainsString('href="/cgu"', $html);
            $this->assertStringContainsString('href="/rgpd"', $html);
        }
    }

    /**
     * « MON COMPTE » S'OUVRE AU SURVOL, et c'est le COMPOSANT qui charge ce script, sous l'adresse où
     * cette application sert sa copie (/resources/personnes). L'application n'en porte plus aucune copie : deux
     * scripts sur la même barre ouvriraient et fermeraient chaque menu deux fois.
     */
    public function testLeScriptDOuvertureAuSurvolEstCeluiDuComposant(): void
    {
        $this->connecter(self::$membreId);
        $html = $this->requete();

        $this->assertMatchesRegularExpression('~<script src="/resources/personnes/js/personnes\.js\?v=\d+"></script>~', $html);
        $this->assertSame(1, substr_count($html, 'js/personnes.js'));
        $this->assertStringNotContainsString("'mouseenter'", $html);
    }

    /** Les balises PWA (manifeste, icônes, couleur de thème) sont servies sur toute page, connectée ou non. */
    public function testLesBalisesPwaSontToujoursPresentes(): void
    {
        foreach ([true, false] as $connecte) {
            if ($connecte) {
                $this->connecter(self::$membreId);
            }

            $html = $this->requete();

            $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
            $this->assertStringContainsString('<link rel="apple-touch-icon" href="/resources/images/apple-touch-icon.png">', $html);
            $this->assertStringContainsString('<meta name="theme-color" content="#7b5a39">', $html);
            $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="' . SiteConfig::NOM_APPLICATION . '">', $html);
        }
    }

    /**
     * L'ICÔNE « MOBILE APP » EXISTE DANS LE BALISAGE, cachée par défaut : c'est le script qui la
     * révèle. UNE SEULE, jamais répétée dans le menu déplié — elle ne mène à aucune page.
     */
    public function testLIconeMobileAppExistePourUnConnecteEtEstCacheeParDefaut(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertMatchesRegularExpression(
            '~<a href="#" class="lien-entete text-dark" data-role="app-mobile" data-action="installer-app" aria-label="Mobile App" hidden>~',
            $html
        );
        $this->assertSame(1, substr_count($html, 'data-role="app-mobile"'), 'Une seule icône, jamais répétée dans le menu.');
        $this->assertStringContainsString('id="modal-app-mobile"', $html);
    }

    /** Sans session, ni l'icône ni la modale : rien n'a de sens à installer depuis l'écran de connexion. */
    public function testLIconeMobileAppEstAbsenteSansSession(): void
    {
        $html = $this->requete();

        $this->assertStringNotContainsString('data-role="app-mobile"', $html);
        $this->assertStringNotContainsString('id="modal-app-mobile"', $html);
    }

    /** Le script est servi, versionné, jamais en ligne. */
    public function testLeScriptDInstallationMobileEstServi(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertMatchesRegularExpression('#<script src="/resources/js/app-mobile\.js\?v=\d+"></script>#', $html);
    }

    /**
     * LE COPYRIGHT DEVIENT UNE PLAGE passé la première année, et pas avant : une plage
     * « 2026 - 2026 » ne dirait rien. L'horloge est figée, sans quoi la seconde moitié de la règle
     * ne se vérifierait qu'au prochain 1er janvier.
     */
    public function testLeCopyrightSEtendSurUnePlageDesLAnneeSuivante(): void
    {
        $this->connecter(self::$membreId);
        $this->assertStringContainsString((string) SiteConfig::ANNEE_DEBUT, $this->requete());

        try {
            Utils::figerMaintenant(mktime(12, 0, 0, 6, 15, SiteConfig::ANNEE_DEBUT + 2));
            $this->assertStringContainsString(
                SiteConfig::ANNEE_DEBUT . ' - ' . (SiteConfig::ANNEE_DEBUT + 2),
                $this->requete()
            );
        } finally {
            Utils::figerMaintenant(null);
        }
    }
}
