<?php

declare(strict_types=1);

use Foyer\App\Dependances;
use PHPUnit\Framework\TestCase;

/**
 * L'autoloader des dépendances vendorées sous web/resources/dependencies/.
 *
 * En test comme en développement, l'autoloader de Composer a déjà résolu PHPMailer et celui-ci
 * n'est jamais consulté pour lui : c'est en PRODUCTION qu'il compte, où vendor/ n'existe pas.
 * D'où des assertions sur la RÉSOLUTION elle-même, seule façon d'exercer ici le chemin qui n'est
 * emprunté que là-bas.
 */
class DependancesTest extends TestCase
{
    public function testLautoloadResoutLesDependancesVendorees(): void
    {
        self::assertSame('dependencies/phpmailer/PHPMailer.php', Dependances::fichierDe(\PHPMailer\PHPMailer\PHPMailer::class));
        self::assertSame('dependencies/phpmailer/SMTP.php', Dependances::fichierDe(\PHPMailer\PHPMailer\SMTP::class));
    }

    /**
     * Chaque classe que l'envoi atteint existe RÉELLEMENT dans l'arborescence déployée : sans
     * elle, la production échouerait là où le développement, servi par Composer, ne verrait rien.
     */
    public function testLesFichiersDesigneExistentDansLarborescenceDeployee(): void
    {
        $classes = [
            \PHPMailer\PHPMailer\PHPMailer::class,
            \PHPMailer\PHPMailer\SMTP::class,
            \PHPMailer\PHPMailer\Exception::class,
        ];
        foreach ($classes as $classe) {
            self::assertFileExists(__DIR__ . '/../../../web/resources/' . Dependances::fichierDe($classe));
        }
    }

    /**
     * Un nom de classe ne devient un CHEMIN qu'après avoir été borné aux caractères d'un
     * identifiant PHP : sans ce contrôle, un nom fabriqué ferait remonter l'inclusion hors du
     * répertoire des dépendances.
     */
    public function testUnNomDeClasseFabriqueNeDevientPasUnChemin(): void
    {
        self::assertNull(Dependances::fichierDe('PHPMailer\PHPMailer\..\..\..\secret'));
        self::assertNull(Dependances::fichierDe('PHPMailer\PHPMailer\Classe-Invalide'));
    }

    public function testUneClasseQuiNeLuiAppartientPasNestPasResolue(): void
    {
        self::assertNull(Dependances::fichierDe(TestCase::class));
        self::assertNull(Dependances::fichierDe('ClasseSansNamespace'));
    }

    /** Classe absente du répertoire : on rend la main, l'autoloader suivant répondra. */
    public function testChargerNeLevePasSurUneDependanceAbsente(): void
    {
        $avant = get_included_files();

        Dependances::charger('PHPMailer\PHPMailer\ClasseQuiNexistePas');

        self::assertSame($avant, get_included_files(), 'Aucun fichier ne doit être inclus.');
    }

    public function testChargerNeFaitRienPourUneClasseQuiNeLuiAppartientPas(): void
    {
        $avant = get_included_files();

        Dependances::charger('Une\Autre\Bibliotheque\Classe');

        self::assertSame($avant, get_included_files(), 'Aucun fichier ne doit être inclus.');
    }

    /**
     * Le fichier vendoré est bien INCLUS : c'est le chemin de production. POP3 est la seule
     * classe de PHPMailer que l'application n'atteint jamais — Composer ne l'a donc pas chargée,
     * et l'inclure depuis la copie embarquée ne heurte aucune déclaration existante.
     */
    public function testChargerInclutLeFichierVendore(): void
    {
        self::assertFalse(class_exists(\PHPMailer\PHPMailer\POP3::class, false));

        Dependances::charger(\PHPMailer\PHPMailer\POP3::class);

        self::assertTrue(class_exists(\PHPMailer\PHPMailer\POP3::class, false));
    }

    /**
     * Le PREMIER enregistrement a lieu dans l'amorçage, avant que la mesure ne commence : on le
     * rejoue ici, puis on retire le chargeur ajouté pour laisser le processus tel qu'il était.
     */
    public function testEnregistrerAjouteLeChargeur(): void
    {
        $drapeau = new \ReflectionProperty(Dependances::class, 'enregistre');
        $drapeau->setValue(null, false);
        $avant = spl_autoload_functions();

        try {
            Dependances::enregistrer();

            $ajoutes = array_values(array_filter(
                spl_autoload_functions(),
                static fn (callable $chargeur): bool => !in_array($chargeur, $avant, true)
            ));
            self::assertCount(1, $ajoutes);
        } finally {
            foreach ($ajoutes ?? [] as $chargeur) {
                spl_autoload_unregister($chargeur);
            }
            $drapeau->setValue(null, true);
        }
    }

    /** Un second enregistrement n'ajoute pas un second chargeur. */
    public function testEnregistrerEstIdempotent(): void
    {
        $avant = count(spl_autoload_functions());

        Dependances::enregistrer();

        self::assertSame($avant, count(spl_autoload_functions()));
    }
}
