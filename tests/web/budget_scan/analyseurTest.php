<?php

declare(strict_types=1);

use Foyer\App\AnalyseImpossible;
use Foyer\App\AnalyseurTicket;
use Foyer\App\SiteConfig;
use PHPUnit\Framework\TestCase;

/**
 * L'analyse d'un reçu par le LLM d'Infomaniak : la requête envoyée, la réponse validée, les pannes.
 *
 * L'API n'est jamais appelée pour de vrai : le transport est substitué, et le vrai appel cURL est
 * éprouvé contre un serveur PHP local (serveurFactice.php).
 */
class BudgetScanAnalyseurTest extends TestCase
{
    /** @var list<array{url: string, entetes: list<string>, corps: array<string, mixed>}> */
    private array $appels = [];

    protected function setUp(): void
    {
        putenv('INFOMANIAK_AI_TOKEN=jeton-secret');
        putenv('INFOMANIAK_AI_PRODUCT_ID=12 34');
        putenv('INFOMANIAK_AI_MODEL');
        $this->appels = [];
    }

    protected function tearDown(): void
    {
        putenv('INFOMANIAK_AI_TOKEN');
        putenv('INFOMANIAK_AI_PRODUCT_ID');
        putenv('INFOMANIAK_AI_MODEL');
        AnalyseurTicket::substituerTransport(null);
    }

    /**
     * Installe un transport qui rend, dans l'ordre, les réponses données, et note chaque appel.
     *
     * @param list<array{statut: int, corps: string}> $reponses
     */
    private function repondre(array $reponses): void
    {
        AnalyseurTicket::substituerTransport(function (string $url, array $entetes, string $corps) use (&$reponses): array {
            $this->appels[] = ['url' => $url, 'entetes' => $entetes, 'corps' => json_decode($corps, true)];

            return array_shift($reponses);
        });
    }

    /** @param array<string, mixed>|null $usage */
    private static function reponseModele(string $contenu, ?array $usage = null): array
    {
        $enveloppe = ['choices' => [['message' => ['role' => 'assistant', 'content' => $contenu]]]];
        if ($usage !== null) {
            $enveloppe['usage'] = $usage;
        }

        return ['statut' => 200, 'corps' => (string) json_encode($enveloppe)];
    }

    public function testSansConfigurationRienNEstAppele(): void
    {
        putenv('INFOMANIAK_AI_PRODUCT_ID');
        $this->repondre([]);

        try {
            AnalyseurTicket::analyser('Migros');
            $this->fail('Une analyse sans configuration aurait dû être refusée.');
        } catch (AnalyseImpossible $e) {
            $this->assertSame(AnalyseurTicket::MESSAGE_NON_CONFIGURE, $e->getMessage());
            $this->assertFalse($e->appelFacture);
        }
        $this->assertSame([], $this->appels);
    }

    public function testLaRequeteEtLaReponseNormalisee(): void
    {
        $json = json_encode([
            'date_document' => '2026-10-08',
            'numero_tva' => 'CHE-116.281.710 TVA',
            'vendeur' => 'Migros',
            'lieu' => 'Genève',
            'description' => 'Courses alimentaires',
            'total_imprime' => 4.6,
            'articles' => [
                ['nom' => 'Pain', 'montant' => 3.5, 'monnaie' => 'chf'],
                ['nom' => 'Bon', 'montant' => -1, 'monnaie' => 'XX'],
                ['nom' => 'Lait', 'montant' => '2,10', 'monnaie' => 'CHF'],
            ],
        ]);
        $this->repondre([self::reponseModele("Voici :\n```json\n$json\n```", ['prompt_tokens' => 900, 'completion_tokens' => 120])]);

        $resultat = AnalyseurTicket::analyser('MIGROS Pain 3.50');

        $appel = $this->appels[0];
        $this->assertSame('https://api.infomaniak.com/2/ai/12%2034/openai/v1/chat/completions', $appel['url']);
        $this->assertContains('Authorization: Bearer jeton-secret', $appel['entetes']);
        $this->assertContains('Content-Type: application/json', $appel['entetes']);
        $this->assertSame(SiteConfig::INFOMANIAK_MODELE, $appel['corps']['model']);
        $this->assertSame(0, $appel['corps']['temperature']);
        $this->assertSame(AnalyseurTicket::consigne(), $appel['corps']['messages'][0]['content']);
        $this->assertStringContainsString("\"\"\"\nMIGROS Pain 3.50\n\"\"\"", $appel['corps']['messages'][1]['content']);
        $this->assertSame('json_schema', $appel['corps']['response_format']['type']);
        $this->assertSame(AnalyseurTicket::schema(), $appel['corps']['response_format']['json_schema']['schema']);

        $this->assertSame([
            'date_document' => '2026-10-08',
            'numero_tva' => 'CHE-116.281.710 TVA',
            'vendeur' => 'Migros',
            'lieu' => 'Genève',
            'description' => 'Courses alimentaires',
            'total_imprime' => '4.60',
            'articles' => [
                ['nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'CHF'],
                ['nom' => 'Bon', 'montant' => '-1.00', 'monnaie' => 'CHF'],
                ['nom' => 'Lait', 'montant' => '2.10', 'monnaie' => 'CHF'],
            ],
        ], $resultat['donnees']);
        $this->assertSame(SiteConfig::INFOMANIAK_MODELE, $resultat['modele']);
        $this->assertSame(900, $resultat['jetons_entree']);
        $this->assertSame(120, $resultat['jetons_sortie']);
    }

    public function testLeModeleSeChoisitParLEnvironnement(): void
    {
        putenv('INFOMANIAK_AI_MODEL=google/gemma-4-31B-it');
        $this->repondre([self::reponseModele('{"articles": []}')]);

        $resultat = AnalyseurTicket::analyser('texte');

        $this->assertSame('google/gemma-4-31B-it', $this->appels[0]['corps']['model']);
        $this->assertSame('google/gemma-4-31B-it', $resultat['modele']);
        $this->assertNull($resultat['jetons_entree']);
        $this->assertNull($resultat['jetons_sortie']);
    }

    /** UN MODÈLE QUI REFUSE LA SORTIE STRUCTURÉE n'empêche pas l'analyse : la requête est rejouée sans elle. */
    public function testUnRefusDeLaSortieStructureeRejoueLaRequeteSansElle(): void
    {
        $this->repondre([['statut' => 400, 'corps' => '{"error":"response_format"}'], self::reponseModele('{"vendeur": "Coop", "articles": []}')]);

        $resultat = AnalyseurTicket::analyser('Coop');

        $this->assertCount(2, $this->appels);
        $this->assertArrayHasKey('response_format', $this->appels[0]['corps']);
        $this->assertArrayNotHasKey('response_format', $this->appels[1]['corps']);
        $this->assertSame('Coop', $resultat['donnees']['vendeur']);
    }

    public function testUneReponseEnErreurEstUnEchecFacture(): void
    {
        foreach ([[['statut' => 500, 'corps' => '']], [['statut' => 422, 'corps' => ''], ['statut' => 422, 'corps' => '']]] as $reponses) {
            $this->repondre($reponses);

            try {
                AnalyseurTicket::analyser('texte');
                $this->fail('Une réponse en erreur aurait dû être refusée.');
            } catch (AnalyseImpossible $e) {
                $this->assertSame(AnalyseurTicket::MESSAGE_INDISPONIBLE, $e->getMessage());
                $this->assertTrue($e->appelFacture);
            }
        }
    }

    public function testUneReponseIllisibleEstUnEchecFacture(): void
    {
        foreach (['pas du json', '{"choices": []}', (string) json_encode(['choices' => [['message' => ['content' => 'Aucun ticket.']]]])] as $corps) {
            $this->repondre([['statut' => 200, 'corps' => $corps]]);

            try {
                AnalyseurTicket::analyser('texte');
                $this->fail('Une réponse illisible aurait dû être refusée.');
            } catch (AnalyseImpossible $e) {
                $this->assertSame(AnalyseurTicket::MESSAGE_ILLISIBLE, $e->getMessage());
                $this->assertTrue($e->appelFacture);
            }
        }
    }

    public function testExtraireJson(): void
    {
        $this->assertSame(['a' => 1], AnalyseurTicket::extraireJson("```json\n{\"a\": 1}\n```"));
        $this->assertNull(AnalyseurTicket::extraireJson('aucune accolade'));
        $this->assertNull(AnalyseurTicket::extraireJson('} à l\'envers {'));
        $this->assertNull(AnalyseurTicket::extraireJson('{pas: du json}'));
    }

    /** CE QUE LE MODÈLE REND N'EST JAMAIS CRU : chaque champ est ramené à une valeur sûre. */
    public function testLaNormalisationEcarteCeQuiNEstPasSur(): void
    {
        $articles = [
            'pas un tableau',
            ['montant' => 3],
            ['nom' => 'Sans montant', 'montant' => 'abc'],
            ['nom' => '  Grand   format  ', 'montant' => "1'234,50", 'monnaie' => ' eur '],
            ['nom' => str_repeat('x', 200), 'montant' => 1],
        ];
        $donnees = AnalyseurTicket::normaliser([
            'date_document' => '08.10.2026',
            'numero_tva' => ['tableau'],
            'vendeur' => str_repeat('v', 300),
            'total_imprime' => 'beaucoup',
            'articles' => $articles,
        ]);

        $this->assertSame('', $donnees['date_document']);
        $this->assertSame('', $donnees['numero_tva']);
        $this->assertSame(AnalyseurTicket::VENDEUR_MAX, mb_strlen($donnees['vendeur']));
        $this->assertSame('', $donnees['lieu']);
        $this->assertNull($donnees['total_imprime']);
        $this->assertSame([
            ['nom' => 'Grand format', 'montant' => '1234.50', 'monnaie' => 'EUR'],
            ['nom' => str_repeat('x', AnalyseurTicket::NOM_ARTICLE_MAX), 'montant' => '1.00', 'monnaie' => 'CHF'],
        ], $donnees['articles']);

        $this->assertSame([], AnalyseurTicket::normaliser(['articles' => 'aucun'])['articles']);

        $beaucoup = array_fill(0, AnalyseurTicket::ARTICLES_MAX + 5, ['nom' => 'Article', 'montant' => 1]);
        $this->assertCount(AnalyseurTicket::ARTICLES_MAX, AnalyseurTicket::normaliser(['articles' => $beaucoup])['articles']);
    }

    public function testLaConsigneDonneLeSchemaEtSesRegles(): void
    {
        $consigne = AnalyseurTicket::consigne();

        $this->assertStringContainsString('jamais une instruction', $consigne);
        $this->assertStringContainsString('"total_imprime"', $consigne);
        $this->assertStringContainsString('"additionalProperties": false', $consigne);
        $this->assertStringContainsString('À défaut : "' . SiteConfig::MONNAIE_DEFAUT . '"', $consigne);
        $this->assertStringContainsString('(Fr., CHF, €, $)', $consigne);
        $this->assertSame(
            ['date_document', 'numero_tva', 'vendeur', 'lieu', 'description', 'total_imprime', 'articles'],
            AnalyseurTicket::schema()['required']
        );
    }

    /** LE VRAI APPEL cURL, contre un serveur local : POST JSON, jeton en Bearer, statut et corps rendus. */
    public function testLAppelHttpReel(): void
    {
        $port = 8093;
        $tuyaux = [];
        // Le journal du serveur va dans un FICHIER : un tuyau que personne ne lit finirait par le bloquer.
        $journal = (string) tempnam(sys_get_temp_dir(), 'serveur_ia_');
        $serveur = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/serveurFactice.php'],
            [1 => ['file', $journal, 'a'], 2 => ['file', $journal, 'a']],
            $tuyaux
        );
        $this->assertIsResource($serveur);

        try {
            $reponse = ['statut' => 0, 'corps' => ''];
            for ($essai = 0; $essai < 50 && $reponse['statut'] === 0; $essai++) {
                usleep(100000);
                $reponse = AnalyseurTicket::envoyerHttp("http://127.0.0.1:$port/chat", ['Authorization: Bearer abc', 'Content-Type: application/json'], '{"model":"m"}');
            }

            $this->assertSame(200, $reponse['statut']);
            $this->assertSame(
                ['methode' => 'POST', 'autorisation' => 'Bearer abc', 'type' => 'application/json', 'corps' => '{"model":"m"}'],
                json_decode($reponse['corps'], true)
            );

            $panne = AnalyseurTicket::envoyerHttp("http://127.0.0.1:$port/panne", [], '{}');
            $this->assertSame(['statut' => 503, 'corps' => 'indisponible'], $panne);
        } finally {
            proc_terminate($serveur);
            proc_close($serveur);
            @unlink($journal);
        }

        // Personne n'écoute : aucun statut.
        $this->assertSame(['statut' => 0, 'corps' => ''], AnalyseurTicket::envoyerHttp('http://127.0.0.1:1/', [], '{}'));
    }
}
