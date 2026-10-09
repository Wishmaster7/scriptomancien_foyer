<?php

declare(strict_types=1);

use Foyer\App\CoursDeChange;
use Foyer\App\SiteConfig;
use PHPUnit\Framework\TestCase;

/**
 * Le cours de change d'un reçu étranger, demandé au service des cours de la BCE (Frankfurter).
 *
 * Le service n'est jamais appelé pour de vrai : le transport est substitué, et le vrai appel cURL
 * est éprouvé contre un serveur PHP local (serveurFactice.php).
 */
class BudgetScanCoursTest extends TestCase
{
    protected function tearDown(): void
    {
        CoursDeChange::substituerTransport(null);
        putenv('COURS_CHANGE_URL=' . ADRESSE_COURS_DE_TEST);
    }

    /** LE VERROU DES TESTS : le vrai service n'est jamais visé, même par un test qui oublierait son transport. */
    public function testAucunTestNeViseLeVraiService(): void
    {
        $this->assertSame('127.0.0.1', parse_url(SiteConfig::coursChangeUrl(), PHP_URL_HOST));
        putenv('COURS_CHANGE_URL');
        $this->assertSame('https://api.frankfurter.dev/v1', SiteConfig::coursChangeUrl());
    }

    /** La date et les deux monnaies, et rien d'autre, partent au service ; le cours revient à huit décimales. */
    public function testLeCoursDuJour(): void
    {
        $demandes = [];
        CoursDeChange::substituerTransport(static function (string $url) use (&$demandes): array {
            $demandes[] = $url;

            return ['statut' => 200, 'corps' => '{"amount":1.0,"base":"INR","date":"2018-05-18","rates":{"CHF":0.01452}}'];
        });

        $this->assertSame('0.01452000', CoursDeChange::cours('INR', 'CHF', '2018-05-20'));
        $this->assertSame([ADRESSE_COURS_DE_TEST . '/2018-05-20?base=INR&symbols=CHF'], $demandes);
    }

    /** PANNE, MONNAIE NON COTÉE, RÉPONSE ILLISIBLE : aucun cours — jamais un cours inventé. */
    public function testSansCoursLisibleAucunCours(): void
    {
        foreach ([
            ['statut' => 0, 'corps' => ''],
            ['statut' => 404, 'corps' => '{"message":"not found"}'],
            ['statut' => 200, 'corps' => 'pas du json'],
            ['statut' => 200, 'corps' => '{"rates":{}}'],
            ['statut' => 200, 'corps' => '{"rates":{"CHF":"0.94"}}'],
            ['statut' => 200, 'corps' => '{"rates":{"CHF":0}}'],
        ] as $reponse) {
            CoursDeChange::substituerTransport(static fn (): array => $reponse);

            $this->assertNull(CoursDeChange::cours('EUR', 'CHF', '2026-10-08'), $reponse['corps']);
        }

        // Un cours entier est un cours.
        CoursDeChange::substituerTransport(static fn (): array => ['statut' => 200, 'corps' => '{"rates":{"CHF":1}}']);
        $this->assertSame('1.00000000', CoursDeChange::cours('EUR', 'CHF', '2026-10-08'));
    }

    /** LE VRAI APPEL cURL, contre un serveur local : GET, réponse JSON ; personne n'écoute, statut 0. */
    public function testLAppelHttpReel(): void
    {
        $port = 8094;
        $tuyaux = [];
        // Le journal du serveur va dans un FICHIER : un tuyau que personne ne lit finirait par le bloquer.
        $journal = (string) tempnam(sys_get_temp_dir(), 'serveur_cours_');
        $serveur = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/serveurFactice.php'],
            [1 => ['file', $journal, 'a'], 2 => ['file', $journal, 'a']],
            $tuyaux
        );
        $this->assertIsResource($serveur);
        putenv("COURS_CHANGE_URL=http://127.0.0.1:$port/v1");

        try {
            $cours = null;
            for ($essai = 0; $essai < 50 && $cours === null; $essai++) {
                usleep(100000);
                $cours = CoursDeChange::cours('EUR', 'CHF', '2026-10-08');
            }

            $this->assertSame('0.94120000', $cours);
            $this->assertSame(503, CoursDeChange::envoyerHttp("http://127.0.0.1:$port/panne")['statut']);
        } finally {
            proc_terminate($serveur);
            proc_close($serveur);
            @unlink($journal);
        }

        $this->assertSame(['statut' => 0, 'corps' => ''], CoursDeChange::envoyerHttp('http://127.0.0.1:1/v1/2026-10-08'));
    }
}
