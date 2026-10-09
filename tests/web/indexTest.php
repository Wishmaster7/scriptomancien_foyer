<?php

declare(strict_types=1);

use Foyer\App\SiteConfig;
use Foyer\App\Utils;
use Personnes\Auth\Authentification;

require_once __DIR__ . '/TestBase.php';

/**
 * Le point d'entrée unique : ses trois gardes et son aiguillage.
 */
class IndexTest extends TestBase
{
    public function testSansSessionSeuleLaPageDeConnexionEstServie(): void
    {
        $html = $this->requete(['action' => 'profil']);

        // Le profil demandé n'est pas servi : il n'y a pas d'autre porte que la connexion.
        $this->assertStringContainsString('personnes-carte-connexion', $html);
    }

    public function testLesConditionsSontLisiblesSansCompte(): void
    {
        // La case à cocher de l'étape 2 y renvoie, avant toute session.
        $html = $this->requete([], [], 'GET', '/cgu');

        $this->assertStringContainsString("Conditions générales d'utilisation", $html);
        $this->assertStringContainsString('page-texte-legal', $html);
    }

    public function testLesTextesLegauxNeSontPasServisParUneAction(): void
    {
        // Seul le CHEMIN les sert : « /?action=cgu » est la racine, donc la page de connexion.
        foreach (['cgu', 'rgpd'] as $action) {
            $html = $this->requete(['action' => $action]);

            $this->assertStringContainsString('personnes-carte-connexion', $html);
            $this->assertStringNotContainsString('page-texte-legal', $html);
        }
    }

    /**
     * MÊME RÉGIME QUE LES CONDITIONS : le pied de page propose la protection des données depuis
     * toutes les pages, connexion comprise — l'exiger d'un compte manquerait précisément les
     * personnes qui n'en ont pas encore.
     */
    public function testLaProtectionDesDonneesEstLisibleSansCompte(): void
    {
        $html = $this->requete([], [], 'GET', '/rgpd');

        $this->assertStringContainsString('Protection des données', $html);
        $this->assertStringContainsString('page-texte-legal', $html);
    }

    /**
     * LES DEUX TEXTES SUIVENT LE MODÈLE DES APPLICATIONS DE LA PLATEFORME : l'application y est nommée comme
     * une application de la plateforme, l'éditeur et concepteur identifié, la version datée et signée.
     */
    public function testLesTextesLegauxNommentLApplicationDeLaPlateforme(): void
    {
        $motif = "#l'application\s+<strong>" . preg_quote(SiteConfig::NOM_APPLICATION, '#')
            . '</strong> de la plateforme\s+<strong>' . preg_quote(SiteConfig::NOM_SITE, '#') . '</strong>#';

        foreach (['/cgu', '/rgpd'] as $chemin) {
            $html = $this->requete([], [], 'GET', $chemin);

            $this->assertMatchesRegularExpression($motif, $html);
            $this->assertStringContainsString('Éditeur et concepteur de la plateforme :', $html);
            $this->assertStringContainsString(SiteConfig::CANTON_FOR_JURIDIQUE . ', le 8 octobre 2026', $html);
        }
    }

    /**
     * CE QUE LA POLITIQUE AFFIRME RESTE VRAI : elle annonce le journal (table LOGS) et dit qu'aucune ressource
     * n'est chargée depuis un autre site — ce que vérifient les balises <link> et <script> de chaque page,
     * connectée ou non. Un CDN réintroduit dans l'en-tête ou le pied de page fait échouer ce test.
     */
    public function testLaPolitiqueAnnonceLeJournalEtAucuneRessourceExterne(): void
    {
        $html = $this->requete([], [], 'GET', '/rgpd');

        $this->assertStringContainsString("4.3 Journal d'activité", $html);
        $this->assertStringContainsString("Aucun service externe n'est sollicité par les pages", $html);

        $pages = [$html, $this->requete()];
        $this->connecter(self::$adminId);
        $pages[] = $this->requete(['action' => 'profil']);
        foreach ($pages as $page) {
            $this->assertStringContainsString('<link rel="stylesheet" href="/resources/css/bootstrap.min.css">', $page);
            $this->assertStringContainsString('<script src="/resources/js/bootstrap.min.js"></script>', $page);
            $this->assertDoesNotMatchRegularExpression('#<(link|script)\b[^>]*\b(href|src)="(https?:)?//#i', $page);
        }
    }

    /**
     * LES DEUX TEXTES DISENT QU'AUCUNE DONNÉE FAMILIALE N'EST ENCORE ENREGISTRÉE, et c'est vrai tant que le
     * schéma ne porte que PERSONNE et LOGS. Ce test lie les deux : la PREMIÈRE table métier le fait échouer,
     * et c'est le but — la politique doit être corrigée AVANT que la table serve (art. 18).
     */
    public function testLesTextesLegauxSuiventLePerimetreReelDuSchema(): void
    {
        // fetch_all(MYSQLI_NUM) : le nom de la colonne de « SHOW FULL TABLES » porte celui du schéma
        // (« Tables_in_3t75aa_foyer_phpunit »), qu'un accès par clé obligerait à reconstruire.
        $tables = array_column(
            self::$db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM),
            0
        );
        sort($tables);

        $this->assertSame(
            ['LOGS', 'PERSONNE'],
            array_map('strtoupper', $tables),
            "Une table métier est apparue : reprenez /cgu et /rgpd avant qu'elle serve (art. 18 de la politique)."
        );

        $this->assertStringContainsString(
            'Aucune donnée familiale n\'est enregistrée à ce jour',
            $this->requete([], [], 'GET', '/rgpd')
        );
        $this->assertStringContainsString(
            'ne sont pas encore ouverts',
            $this->requete([], [], 'GET', '/cgu')
        );
    }

    public function testUneSoumissionSansJetonCsrfEstInterceptee(): void
    {
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test'], 'POST');

        $this->assertSame('/', $this->redirection());
        // Aucun code n'a été émis : la garde s'oppose AVANT le moindre contrôleur.
        $this->assertNull($this->codeAuthDe(self::$membreId));
    }

    public function testUneSoumissionAvecJetonValideAtteintLeControleur(): void
    {
        $jeton = $this->jetonCsrf();

        $this->requete([], [
            'action' => 'email',
            'email' => 'membre@example.test',
            'csrf_token' => $jeton,
        ], 'POST');

        $this->assertNotNull($this->codeAuthDe(self::$membreId));
    }

    public function testUnCodeValideOuvreLaSessionEtMeneAuProfil(): void
    {
        $jeton = $this->jetonCsrf();
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test', 'csrf_token' => $jeton], 'POST');
        $code = (string) $this->codeAuthDe(self::$membreId);
        $this->viderMinuteur();

        $this->requete([], [
            'action' => 'code',
            'code' => $code,
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf(),
        ], 'POST');

        // UNE CONNEXION RÉUSSIE MÈNE À L'ACCUEIL, la racine nue, et non au profil.
        $this->assertSame('/', $this->redirection());
        $this->assertSame('OK', $_SESSION[Authentification::CLE_ETAPE]);
    }

    public function testUnCodeRefuseRameneSurLaPageDeConnexionAvecUnMessage(): void
    {
        $jeton = $this->jetonCsrf();
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test', 'csrf_token' => $jeton], 'POST');
        $this->viderMinuteur();

        $this->requete([], [
            'action' => 'code',
            'code' => 'ZZZZZZ',
            'accept_conditions' => '1',
            'csrf_token' => $this->jetonCsrf(),
        ], 'POST');

        $this->assertSame('/', $this->redirection());
        $this->assertSame(Authentification::MESSAGE_CODE_INVALIDE, \Foyer\App\Flash::prendre('erreur'));
    }

    public function testRenoncerEffaceLeCodeEtRameneALEtape1(): void
    {
        $jeton = $this->jetonCsrf();
        $this->requete([], ['action' => 'email', 'email' => 'membre@example.test', 'csrf_token' => $jeton], 'POST');

        $this->requete([], ['action' => 'annuler', 'csrf_token' => $this->jetonCsrf()], 'POST');

        $this->assertSame('/', $this->redirection());
        $this->assertNull($this->codeAuthDe(self::$membreId));
        $this->assertStringContainsString('name="email"', $this->requete());
    }

    public function testUneActionInconnueRameneSimplementSurLaPageDeConnexion(): void
    {
        $this->requete([], ['action' => 'geste-inconnu', 'csrf_token' => $this->jetonCsrf()], 'POST');

        $this->assertSame('/', $this->redirection());
    }

    public function testLaDeconnexionDetruitLaSession(): void
    {
        $this->connecter(self::$membreId);
        $jeton = $this->jetonCsrf();

        $this->requete([], ['action' => 'deconnexion', 'csrf_token' => $jeton], 'POST');

        $this->assertSame('/', $this->redirection());
        $this->assertArrayNotHasKey(Authentification::CLE_PERSONNE, $_SESSION);
    }

    public function testUneSessionOuverteMeneALAccueilParDefaut(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete();

        $this->assertStringContainsString('id="page-accueil"', $html);
    }

    public function testUneActionInconnueRetombeSurLAccueil(): void
    {
        // ET NON SUR LE PROFIL, qui était le défaut auparavant : une action mal orthographiée
        // présentait alors un formulaire de modification que personne n'avait demandé.
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'nimporte_quoi']);

        $this->assertStringNotContainsString('Mon identité', $html);
    }

    public function testLeProfilResteAtteignableParSonAction(): void
    {
        $this->connecter(self::$membreId);

        $html = $this->requete(['action' => 'profil']);

        $this->assertStringContainsString('Mon profil', $html);
    }

    /**
     * LES DEUX FERMETURES SE REFERMENT EN COURS DE NAVIGATION, et chacune pour sa portée :
     * IS_BLOQUE ferme toute la plateforme, IS_ACTIF ne ferme que ce site. Compte les relit à
     * chaque requête, de sorte qu'aucune session en cours ne survit à l'une ou à l'autre.
     */
    public function testUneIdentiteBloqueePendantSaNavigationRetombeSurLaConnexion(): void
    {
        $this->connecter(self::$membreId);
        self::$db->query('UPDATE ' . self::tableAnnuaire() . ' SET IS_BLOQUE = 1 WHERE ID = ' . self::$membreId);
        \Foyer\App\Compte::reinitialiser();

        $html = $this->requete();

        $this->assertStringContainsString('personnes-carte-connexion', $html);
    }

    public function testUnAccesFermePendantSaNavigationRetombeSurLaConnexion(): void
    {
        $this->connecter(self::$membreId);
        self::$db->query('UPDATE PERSONNE SET IS_ACTIF = 0 WHERE ID = ' . self::$membreId);
        \Foyer\App\Compte::reinitialiser();

        $html = $this->requete();

        $this->assertStringContainsString('personnes-carte-connexion', $html);
    }

    public function testLeMenuNePorteAucuneEntreeDAdministration(): void
    {
        $this->connecter(self::$adminId);

        $html = $this->requete();

        $this->assertStringContainsString('Mon profil', $html);
        $this->assertStringNotContainsString('action=admin_annuaire', $html);
    }

    public function testLaRedirectionEstOubliableEntreDeuxRequetes(): void
    {
        Utils::oublierRedirection();

        $this->assertNull(Utils::derniereRedirection());
    }
}
