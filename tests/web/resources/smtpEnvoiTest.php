<?php

declare(strict_types=1);

use Foyer\App\Smtp;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../TestBase.php';

/**
 * L'EXPÉDITION elle-même, à travers PHPMailer.
 *
 * ISOLATION DE PROCESSUS OBLIGATOIRE, et pour une raison précise : ces cas définissent un
 * FAUX PHPMailer par `eval()` avant que l'autoloader n'ait chargé le vrai. Une classe ne se
 * redéclare pas, et le vrai PHPMailer est chargé dès qu'un autre test l'atteint — chaque cas a
 * donc besoin d'un processus neuf. C'est l'un des rares motifs légitimes d'isolation.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SmtpEnvoiTest extends TestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        // On retire le substitut installé par le harnais : c'est le vrai chemin d'expédition
        // que ces cas mesurent.
        Smtp::substituerEnvoi(null);
    }

    /**
     * Installe un faux PHPMailer qui enregistre ce qu'on lui donne dans $GLOBALS.
     *
     * @param bool $echoue true pour que l'envoi lève, comme le ferait un serveur injoignable
     */
    private function installerFauxPhpmailer(bool $echoue = false): void
    {
        $GLOBALS['phpmailer_dernier'] = [];
        $leve = $echoue ? 'true' : 'false';

        eval('
            namespace PHPMailer\\PHPMailer;

            class Exception extends \\Exception {}

            class PHPMailer
            {
                public const ENCRYPTION_STARTTLS = "tls";

                public $CharSet;
                public $Host;
                public $Port;
                public $SMTPAutoTLS;
                public $SMTPAuth = false;
                public $Username;
                public $Password;
                public $SMTPSecure;
                public $Subject;
                public $Body;
                public $AltBody;

                public function __construct($exceptions = false) {}
                public function isSMTP() { $GLOBALS["phpmailer_dernier"]["smtp"] = true; }
                public function setFrom($adresse, $nom) { $GLOBALS["phpmailer_dernier"]["from"] = [$adresse, $nom]; }
                public function addAddress($adresse) { $GLOBALS["phpmailer_dernier"]["to"] = $adresse; }
                public function isHTML($html) { $GLOBALS["phpmailer_dernier"]["html"] = $html; }

                public function send()
                {
                    $GLOBALS["phpmailer_dernier"]["objet"] = $this;
                    if (' . $leve . ') {
                        throw new Exception("serveur injoignable");
                    }

                    return true;
                }
            }
        ');
    }

    public function testUnEmailTexteSeulEstUnTextePlain(): void
    {
        $this->installerFauxPhpmailer();

        $this->assertTrue(Smtp::envoyer('cible@example.test', 'Sujet', 'Corps en texte'));

        $envoye = $GLOBALS['phpmailer_dernier'];
        $this->assertSame('cible@example.test', $envoye['to']);
        $this->assertFalse($envoye['html']);
        $this->assertSame('Corps en texte', $envoye['objet']->Body);
        // Pas d'alternative texte sur un email qui n'a pas de HTML : ce serait un
        // « multipart/alternative » dont la partie HTML porterait du texte brut.
        $this->assertNull($envoye['objet']->AltBody);
    }

    public function testUnEmailHtmlEmporteSaVersionTexte(): void
    {
        $this->installerFauxPhpmailer();

        Smtp::envoyer('cible@example.test', 'Sujet', 'Version texte', '<p>Version HTML</p>');

        $envoye = $GLOBALS['phpmailer_dernier'];
        $this->assertTrue($envoye['html']);
        $this->assertSame('<p>Version HTML</p>', $envoye['objet']->Body);
        $this->assertSame('Version texte', $envoye['objet']->AltBody);
        $this->assertSame('UTF-8', $envoye['objet']->CharSet);
    }

    public function testSansCompteConfigureNiAuthentificationNiChiffrement(): void
    {
        $this->installerFauxPhpmailer();

        Smtp::envoyer('cible@example.test', 'Sujet', 'Corps');

        $envoye = $GLOBALS['phpmailer_dernier']['objet'];
        $this->assertFalse($envoye->SMTPAuth);
        $this->assertNull($envoye->SMTPSecure);
        $this->assertSame('localhost', $envoye->Host);
        $this->assertSame(1025, $envoye->Port);
    }

    public function testUnCompteConfigureEntraineAuthentificationEtStarttls(): void
    {
        $this->installerFauxPhpmailer();
        putenv('SMTP_USERNAME=compte');
        putenv('SMTP_PASSWORD=secret');

        try {
            Smtp::envoyer('cible@example.test', 'Sujet', 'Corps');

            $envoye = $GLOBALS['phpmailer_dernier']['objet'];
            $this->assertTrue($envoye->SMTPAuth);
            $this->assertSame('compte', $envoye->Username);
            $this->assertSame('tls', $envoye->SMTPSecure);
        } finally {
            putenv('SMTP_USERNAME');
            putenv('SMTP_PASSWORD');
        }
    }

    public function testUnEchecDExpeditionRendFaux(): void
    {
        $this->installerFauxPhpmailer(true);

        $this->assertFalse(Smtp::envoyer('cible@example.test', 'Sujet', 'Corps'));
    }

    public function testLeSubstitutPrimeSurLExpedition(): void
    {
        $vu = null;
        Smtp::substituerEnvoi(static function (string $destinataire) use (&$vu): bool {
            $vu = $destinataire;

            return true;
        });

        $this->assertTrue(Smtp::envoyer('cible@example.test', 'Sujet', 'Corps'));
        $this->assertSame('cible@example.test', $vu);
    }
}
