<?php

declare(strict_types=1);

use Foyer\App\AnalyseImpossible;
use Foyer\App\AnalyseurTicket;
use Foyer\App\SiteConfig;
use PHPUnit\Framework\Attributes\DataProvider;
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
        putenv('INFOMANIAK_AI_URL=' . ADRESSE_LLM_DE_TEST);
        AnalyseurTicket::substituerTransport(null);
    }

    /** LE VERROU DES TESTS : la vraie API n'est jamais visée, même par un test qui oublierait son transport. */
    public function testAucunTestNeViseLaVraieApi(): void
    {
        $this->assertSame('127.0.0.1', parse_url(SiteConfig::infomaniakUrl(), PHP_URL_HOST));
        $this->assertSame('https://api.infomaniak.com/2/ai/{product_id}/openai/v1/chat/completions', SiteConfig::INFOMANIAK_URL_API);
    }

    /** Sans surcharge, c'est l'adresse d'Infomaniak qui est composée — ici remise à un transport substitué. */
    public function testSansSurchargeLAdresseEstCelleDInfomaniak(): void
    {
        putenv('INFOMANIAK_AI_URL');
        $this->repondre([self::reponseModele('{"articles": []}')]);

        AnalyseurTicket::analyser('texte');

        $this->assertSame('https://api.infomaniak.com/2/ai/12%2034/openai/v1/chat/completions', $this->appels[0]['url']);
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
            $this->assertStringEndsWith(' : INFOMANIAK_AI_PRODUCT_ID.', $e->detail);
        }
        $this->assertSame([], $this->appels);
    }

    public function testLaRequeteEtLaReponseNormalisee(): void
    {
        $json = json_encode([
            'date_document' => '2026-10-08T14:32',
            'reference' => ' T-2026/0042#B ',
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

        $resultat = AnalyseurTicket::analyser('MIGROS Pain 3.50', 'allemand');

        $appel = $this->appels[0];
        $this->assertStringStartsWith("Langue du ticket : allemand.\nTexte OCR :", $appel['corps']['messages'][1]['content']);
        $this->assertSame('http://127.0.0.1:9/2/ai/12%2034/openai/v1/chat/completions', $appel['url']);
        $this->assertContains('Authorization: Bearer jeton-secret', $appel['entetes']);
        $this->assertContains('Content-Type: application/json', $appel['entetes']);
        $this->assertSame(SiteConfig::INFOMANIAK_MODELE, $appel['corps']['model']);
        $this->assertSame(0, $appel['corps']['temperature']);
        $this->assertSame(AnalyseurTicket::consigne(), $appel['corps']['messages'][0]['content']);
        $this->assertStringContainsString("\"\"\"\nMIGROS Pain 3.50\n\"\"\"", $appel['corps']['messages'][1]['content']);
        $this->assertSame('json_schema', $appel['corps']['response_format']['type']);
        $this->assertSame(AnalyseurTicket::schema(), $appel['corps']['response_format']['json_schema']['schema']);

        $this->assertSame([
            'date_document' => '2026-10-08T14:32',
            'reference' => 'T-2026/0042#B',
            'numero_tva' => 'CHE-116.281.710 TVA',
            'vendeur' => 'Migros',
            'lieu' => 'Genève',
            'description' => 'Courses alimentaires',
            'total_imprime' => '4.60',
            'articles' => [
                ['nom' => 'Pain', 'montant' => '3.50', 'monnaie' => 'CHF', 'a_verifier' => false],
                ['nom' => 'Bon', 'montant' => '-1.00', 'monnaie' => 'CHF', 'a_verifier' => false],
                ['nom' => 'Lait', 'montant' => '2.10', 'monnaie' => 'CHF', 'a_verifier' => false],
            ],
        ], $resultat['donnees']);
        $this->assertSame(SiteConfig::INFOMANIAK_MODELE, $resultat['modele']);
        $this->assertSame(900, $resultat['jetons_entree']);
        $this->assertSame(120, $resultat['jetons_sortie']);
    }

    public function testLeModeleSeChoisitParLEnvironnement(): void
    {
        putenv('INFOMANIAK_AI_MODEL=mistralai/Ministral-3-14B-Instruct-2512');
        $this->repondre([self::reponseModele('{"articles": []}')]);

        $resultat = AnalyseurTicket::analyser('texte');

        $this->assertSame('mistralai/Ministral-3-14B-Instruct-2512', $this->appels[0]['corps']['model']);
        // Sans langue choisie, le texte vient seul.
        $this->assertStringStartsWith('Texte OCR :', $this->appels[0]['corps']['messages'][1]['content']);
        $this->assertSame('mistralai/Ministral-3-14B-Instruct-2512', $resultat['modele']);
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
                $this->assertStringContainsString("Réponse : HTTP {$reponses[0]['statut']}\nCorps reçu : (vide)", $e->detail);
                // Un appel rejoué sans sortie structurée est raconté lui aussi.
                $this->assertSame(count($reponses) === 2, str_contains($e->detail, 'Appel 2 : POST'));
            }
        }
    }

    /** LE RÉCIT D'UN ÉCHEC dit tout ce qui aide à le comprendre — et jamais le jeton. */
    public function testLeDetailDUnEchecRaconteChaqueAppel(): void
    {
        $this->repondre([
            ['statut' => 400, 'corps' => '{"error":"response_format inconnu"}'],
            ['statut' => 0, 'corps' => '', 'erreur' => 'cURL n° 60 : SSL certificate problem'],
        ]);

        try {
            AnalyseurTicket::analyser('texte');
            $this->fail('Un échec réseau aurait dû être refusé.');
        } catch (AnalyseImpossible $e) {
            $this->assertSame(
                "Appel 1 : POST http://127.0.0.1:9/2/ai/12%2034/openai/v1/chat/completions\n"
                . 'Modèle : ' . SiteConfig::INFOMANIAK_MODELE . ' | sortie structurée (response_format) : oui | corps envoyé : '
                . strlen((string) json_encode(AnalyseurTicket::requete(SiteConfig::INFOMANIAK_MODELE, 'texte'), JSON_UNESCAPED_UNICODE))
                . " octets | jeton : 12 caractères\nRéponse : HTTP 400\nCorps reçu : {\"error\":\"response_format inconnu\"}",
                explode("\n\n", $e->detail)[0]
            );
            $this->assertStringContainsString('sortie structurée (response_format) : non', $e->detail);
            $this->assertStringContainsString("Réponse : HTTP 0\nErreur réseau : cURL n° 60 : SSL certificate problem\nCorps reçu : (vide)", $e->detail);
            $this->assertStringNotContainsString('jeton-secret', $e->detail);
        }
    }

    public function testUneReponseIllisibleEstUnEchecFacture(): void
    {
        $cas = [
            'pas du json' => 'choices[0].message.content absent',
            '{"choices": []}' => 'choices[0].message.content absent',
            (string) json_encode(['choices' => [['message' => ['content' => 'Aucun ticket.']]]]) => 'aucun objet JSON décodable',
        ];
        foreach ($cas as $corps => $explication) {
            $this->repondre([['statut' => 200, 'corps' => $corps]]);

            try {
                AnalyseurTicket::analyser('texte');
                $this->fail('Une réponse illisible aurait dû être refusée.');
            } catch (AnalyseImpossible $e) {
                $this->assertSame(AnalyseurTicket::MESSAGE_ILLISIBLE, $e->getMessage());
                $this->assertTrue($e->appelFacture);
                $this->assertStringContainsString('Corps reçu : ' . $corps, $e->detail);
                $this->assertStringContainsString($explication, $e->detail);
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
            'reference' => str_repeat('r', 80),
            'numero_tva' => ['tableau'],
            'vendeur' => str_repeat('v', 300),
            'total_imprime' => 'beaucoup',
            'articles' => $articles,
        ]);

        $this->assertSame('', $donnees['date_document']);
        $this->assertSame(str_repeat('r', AnalyseurTicket::REFERENCE_MAX), $donnees['reference']);
        $this->assertSame('', $donnees['numero_tva']);
        $this->assertSame('', AnalyseurTicket::normaliser(['reference' => 4242])['reference']);
        $this->assertSame(AnalyseurTicket::VENDEUR_MAX, mb_strlen($donnees['vendeur']));
        $this->assertSame('', $donnees['lieu']);
        $this->assertNull($donnees['total_imprime']);
        // UN ARTICLE SANS NOM est écarté ; sans montant lisible, il est GARDÉ, montant vide, pour être complété.
        $this->assertSame([
            ['nom' => 'Sans montant', 'montant' => '', 'monnaie' => 'CHF', 'a_verifier' => false],
            ['nom' => 'Grand format', 'montant' => '1234.50', 'monnaie' => 'EUR', 'a_verifier' => false],
            ['nom' => str_repeat('x', AnalyseurTicket::NOM_ARTICLE_MAX), 'montant' => '1.00', 'monnaie' => 'CHF', 'a_verifier' => false],
        ], $donnees['articles']);

        $this->assertSame([], AnalyseurTicket::normaliser(['articles' => 'aucun'])['articles']);

        $beaucoup = array_fill(0, AnalyseurTicket::ARTICLES_MAX + 5, ['nom' => 'Article', 'montant' => 1]);
        $this->assertCount(AnalyseurTicket::ARTICLES_MAX, AnalyseurTicket::normaliser(['articles' => $beaucoup])['articles']);
    }

    /** LA FORME D'UN N° DE TVA : celle de l'UE, du Royaume-Uni et de la Suisse — pas une référence de ticket. */
    public function testLaFormeDUnNumeroDeTva(): void
    {
        foreach (['FR12345678901', 'FR 12 345 678 901', 'DE123456789', 'ATU12345678', 'NL123456789B01', 'EL123456789', 'GB123456789', 'CHE-116.281.710 TVA', 'CHE-116.281.710 MWST', 'che116281710', 'IT12345678901'] as $valide) {
            $this->assertTrue(AnalyseurTicket::estNumeroTva($valide), $valide);
        }
        foreach (['T2005040304', 'P2oe5040304', '70000001', '12345678901234', 'FR1234', 'US123456789', 'CHE-116.281.71', '', 'Ticket 123'] as $invalide) {
            $this->assertFalse(AnalyseurTicket::estNumeroTva($invalide), $invalide);
        }

        // Ce que le modèle propose sans en avoir la forme est écarté.
        $this->assertSame('', AnalyseurTicket::normaliser(['numero_tva' => 'P2oe5040304'])['numero_tva']);
        $this->assertSame('CHE-116.281.710 TVA', AnalyseurTicket::normaliser(['numero_tva' => ' CHE-116.281.710 TVA '])['numero_tva']);
    }

    /** LES TAXES IMPRIMÉES, une par ligne : sans montant positif, écartées ; sans libellé, « Taxes ». */
    public function testLesTaxesSontNormalisees(): void
    {
        AnalyseurTicket::normaliser([
            'total_imprime' => 10,
            'taxes' => ['pas une taxe', ['nom' => 'TVA', 'montant' => 0], ['nom' => 'TVA', 'montant' => 'abc'], ['montant' => 1.2], ['nom' => 'IVA', 'montant' => '0,80']],
            'articles' => [['nom' => 'Pain', 'montant' => 10, 'monnaie' => 'EUR']],
        ], $lecture);

        $this->assertSame('2.00', $lecture['taxes']);
        $this->assertSame(['Pain', 'Taxes', 'IVA'], array_column(AnalyseurTicket::normaliser([
            'total_imprime' => 12,
            'taxes' => [['montant' => 1.2], ['nom' => 'IVA', 'montant' => '0,80']],
            'articles' => [['nom' => 'Pain', 'montant' => 10, 'monnaie' => 'EUR']],
        ])['articles'], 'nom'));
        $this->assertNull(AnalyseurTicket::normaliser(['taxes' => 'aucune', 'articles' => []], $lecture)['total_imprime']);
        $this->assertNull($lecture['taxes']);
    }

    /**
     * LE CONTRÔLE PAR LE TOTAL, ticket type par ticket type : c'est le total payé qui dit comment lire
     * chaque ligne, quelles taxes s'y ajoutent, et quel montant l'OCR a mal lu.
     *
     * @param list<array{0: string, 1: int|float|string|null, 2: int|float|null, 3: int|float|null, 4?: bool}> $lignes   [nom, quantité, prix unitaire, montant, douteux]
     * @param list<array{0: string, 1: float}>                                                              $taxes    [libellé, montant]
     * @param list<string>                                                                                  $attendus [nom montant ⚠] retenus, lignes de taxe et d'arrondi comprises
     */
    #[DataProvider('tickets')]
    public function testLeTotalDitCommentLireLesMontants(array $lignes, ?float $total, ?float $totalHt, array $taxes, array $attendus, string $retenue, ?string $totalRetenu): void
    {
        $donnees = AnalyseurTicket::normaliser([
            'total_imprime' => $total,
            'total_ht' => $totalHt,
            'taxes' => array_map(static fn (array $t): array => ['nom' => $t[0], 'montant' => $t[1]], $taxes),
            'articles' => array_map(static fn (array $l): array => [
                'nom' => $l[0], 'quantite' => $l[1], 'prix_unitaire' => $l[2], 'montant' => $l[3], 'douteux' => $l[4] ?? false, 'monnaie' => 'EUR',
            ], $lignes),
        ], $lecture);

        $this->assertSame($attendus, array_map(
            static fn (array $a): string => trim($a['nom'] . ' ' . $a['montant'] . ($a['a_verifier'] ? ' ⚠' : '')),
            $donnees['articles']
        ));
        $this->assertSame($retenue, $lecture['retenue']);
        $this->assertSame($totalRetenu, $donnees['total_imprime']);
    }

    /** @return array<string, array{0: list<array<int, mixed>>, 1: ?float, 2: ?float, 3: list<array{0: string, 1: float}>, 4: list<string>, 5: string, 6: ?string}> */
    public static function tickets(): array
    {
        return [
            // « x2 32,00 » est le total de la ligne ; la TVA est comprise.
            'montants déjà totaux, TVA comprise' => [
                [['Kayak', 2, null, 32], ['Canoë', 1, null, 28], ['Bidon', '2', null, 16]], 76.0, null, [['TVA', 6.67]],
                ['Kayak 32.00', 'Canoë 28.00', 'Bidon 16.00'], 'montants imprimés', '76.00',
            ],
            // « 2 x 1,50 » est un prix unitaire : seule la multiplication tombe sur le total.
            'prix unitaires' => [
                [['Croissant', '2', null, 1.5], ['Café', null, null, 3], ['Pommes', '0,5', null, 4]], 8.0, null, [],
                ['Croissant 3.00', 'Café 3.00', 'Pommes 2.00'], 'montants imprimés, 2 relu(s) par le prix unitaire ou la quantité', '8.00',
            ],
            // LE MODÈLE A CALCULÉ « 2 45,00 » en 90 (ticket MyKomela) : le prix seul tombe sur le total.
            'montant calculé par le modèle' => [
                [['Bracelet', 1, 225, 225], ['Pantalon', 2, 45, 90]], 270.0, null, [],
                ['Bracelet 225.00', 'Pantalon 45.00'], 'montants imprimés, 1 relu(s) par le prix unitaire ou la quantité', '270.00',
            ],
            // Ticket américain : lignes hors taxes, la sales tax s'ajoute au sous-total.
            'sales tax ajoutée' => [
                [['Coffee', 1, null, 4.5], ['Bagel', 2, null, 3]], 11.07, null, [['Sales tax', 0.57]],
                ['Coffee 4.50', 'Bagel 6.00', 'Sales tax 0.57'], 'montants imprimés, 1 relu(s) par le prix unitaire ou la quantité + Sales tax', '11.07',
            ],
            'taxe ajoutée à des totaux de ligne' => [
                [['Coffee', 1, null, 4.5], ['Bagel', 2, null, 6]], 11.07, null, [['Sales tax', 0.57]],
                ['Coffee 4.50', 'Bagel 6.00', 'Sales tax 0.57'], 'montants imprimés + Sales tax', '11.07',
            ],
            // GST INDIENNE (ticket Liquor Street) : les lignes comprennent déjà CGST et SGST, seule la
            // S.Tax s'y ajoute — chaque taxe ajoutée l'est en ligne, comme un article.
            'seules les taxes qui manquent' => [
                [['Chicken', 1, 10, 10.5], ['Dal', 1, 20, 21]], 33.0, 30.0, [['CGST', 0.75], ['SGST', 0.75], ['S.Tax', 1.5]],
                ['Chicken 10.50', 'Dal 21.00', 'S.Tax 1.50'], 'montants imprimés + S.Tax', '33.00',
            ],
            // UN TOTAL ROND : l'écart de moins d'une unité est un arrondi, mis en ligne.
            'arrondi d\'un total rond' => [
                [['Chicken', 1, null, 309.75], ['Dal', 1, null, 288.75]], 628.0, null, [['S.Tax', 29.93]],
                ['Chicken 309.75', 'Dal 288.75', 'S.Tax 29.93', 'Arrondi -0.43'], 'montants imprimés + S.Tax + arrondi', '628.00',
            ],
            'arrondi au 5 centimes' => [
                [['Pain', null, null, 3.33], ['Lait', null, null, 2]], 5.35, null, [],
                ['Pain 3.33', 'Lait 2.00', 'Arrondi 0.02'], 'montants imprimés + arrondi', '5.35',
            ],
            'un centime d\'écart n\'est pas un arrondi' => [
                [['Pain', null, null, 3.33], ['Lait', null, null, 2.03]], 5.37, null, [],
                ['Pain 3.33', 'Lait 2.03'], 'montants imprimés', '5.37',
            ],
            // LE TOTAL MAL LU PAR L'OCR (ticket T21, « 6.80 » pour 45,80) : HT + taxes le retrouve.
            'total imprimé mal lu' => [
                [['Agua', 1, null, 2.5], ['Espeto', 3, 3, 9], ['Fritura', 1, null, 34.3]], 6.8, 41.64, [['IVA', 4.16]],
                ['Agua 2.50', 'Espeto 9.00', 'Fritura 34.30'], 'montants imprimés (total HT + taxes)', '45.80',
            ],
            // LE MONTANT ILLISIBLE, le seul, est ce qui manque au total ; à vérifier.
            'montant illisible déduit' => [
                [['Pain', null, null, 10], ['Vin', null, null, null]], 25.0, null, [],
                ['Pain 10.00', 'Vin 15.00 ⚠'], 'montants imprimés, « Vin » déduit du total', '25.00',
            ],
            'montant illisible recalculé' => [
                [['Pain', 3, 2, null]], 6.0, null, [],
                ['Pain 6.00'], 'montants imprimés', '6.00',
            ],
            'montant illisible qui ne peut manquer' => [
                [['Pain', null, null, 10], ['Vin', null, null, null]], 8.0, null, [],
                ['Pain 10.00', 'Vin'], 'montants imprimés (aucune lecture ne tombe sur le total)', '8.00',
            ],
            'plusieurs montants illisibles' => [
                [['Pain', null, null, null], ['Vin', null, null, null]], 8.0, null, [],
                ['Pain', 'Vin'], 'montants imprimés (plusieurs montants illisibles)', '8.00',
            ],
            // UN CHIFFRE MAL LU (ticket MyKomela, « 1.159,70 » pour 159,70), seule ligne candidate.
            'un chiffre de trop' => [
                [['Robe', null, null, 1159.7], ['Bracelet', null, null, 225]], 384.7, null, [],
                ['Robe 159.70 ⚠', 'Bracelet 225.00'], 'montants imprimés, « Robe » déduit du total', '384.70',
            ],
            // Trois lignes pourraient l'être (ticket Cashflow) : seule compte celle que le modèle dit douteuse.
            'plusieurs candidates, une douteuse' => [
                [['Costume', null, null, 1900, true], ['Chaussure', 1, null, 100], ['Chaussettes', null, null, 8.9]], 2058.9, null, [['TVA', 145.42]],
                ['Costume 1950.00 ⚠', 'Chaussure 100.00', 'Chaussettes 8.90'], 'montants imprimés, « Costume » déduit du total', '2058.90',
            ],
            'plusieurs candidates, aucune douteuse' => [
                [['Costume', null, null, 1900], ['Chaussure', 1, null, 100], ['Chaussettes', null, null, 8.9]], 2058.9, null, [],
                ['Costume 1900.00', 'Chaussure 100.00', 'Chaussettes 8.90'], 'montants imprimés (aucune lecture ne tombe sur le total)', '2058.90',
            ],
            // Ticket incohérent : rien n'est inventé.
            'aucune lecture juste' => [
                [['Kayak', 2, null, 32], ['Pepsi', 1, null, 2]], 50.0, null, [['TVA', 6.67]],
                ['Kayak 32.00', 'Pepsi 2.00'], 'montants imprimés (aucune lecture ne tombe sur le total)', '50.00',
            ],
            'sans total' => [[['Kayak', 2, null, 32]], null, null, [], ['Kayak 32.00'], 'montants imprimés (aucun total imprimé)', null],
            'sans article' => [[], 5.0, null, [['TVA', 5.0]], [], 'montants imprimés (aucun article)', '5.00'],
            'quantité illisible' => [
                [['Kayak', 'x', null, 32], ['Canoë', 0, null, 28]], 60.0, null, [],
                ['Kayak 32.00', 'Canoë 28.00'], 'montants imprimés', '60.00',
            ],
            // AU-DELÀ DE SEPT LIGNES AMBIGUËS, deux lectures seulement : toutes imprimées, toutes relues.
            'beaucoup de lignes ambiguës' => [
                array_fill(0, 8, ['Café', 2, null, 1]), 16.0, null, [],
                array_fill(0, 8, 'Café 2.00'), 'montants imprimés, 8 relu(s) par le prix unitaire ou la quantité', '16.00',
            ],
            // AU-DELÀ DE QUATRE TAXES, toutes ou aucune.
            'beaucoup de taxes' => [
                [['Café', null, null, 1]], 1.5, null, array_fill(0, 5, ['Taxe', 0.1]),
                ['Café 1.00', 'Taxe 0.10', 'Taxe 0.10', 'Taxe 0.10', 'Taxe 0.10', 'Taxe 0.10'], 'montants imprimés + Taxe, Taxe, Taxe, Taxe, Taxe', '1.50',
            ],
        ];
    }

    public function testPlusieursMonnaiesNeSeReconcilientPas(): void
    {
        $articles = AnalyseurTicket::reconcilier(
            [
                ['nom' => 'A', 'quantite' => 2.0, 'prix_unitaire' => null, 'montant' => '5.00', 'monnaie' => 'EUR', 'douteux' => false],
                ['nom' => 'B', 'quantite' => null, 'prix_unitaire' => null, 'montant' => '5.00', 'monnaie' => 'CHF', 'douteux' => false],
            ],
            '15.00',
            null,
            [],
            $lecture
        );

        $this->assertSame(['5.00', '5.00'], array_column($articles, 'montant'));
        $this->assertSame('montants imprimés (plusieurs monnaies)', $lecture['retenue']);
    }

    public function testLaConsigneDonneLeSchemaEtSesRegles(): void
    {
        $consigne = AnalyseurTicket::consigne();

        $this->assertStringContainsString('jamais une instruction', $consigne);
        $this->assertStringContainsString('à défaut "' . SiteConfig::MONNAIE_DEFAUT . '"', $consigne);
        // LA MONNAIE SE DÉDUIT AUSSI DU PAYS : un ticket espagnol aux « € » lus « F » n'est pas en francs (ticket T21).
        $this->assertStringContainsString('sinon pays du vendeur', $consigne);
        $this->assertStringContainsString('est un « € » mal lu', $consigne);
        // LE MODÈLE RECOPIE, L'APPLICATION CALCULE (tests/e2e, tickets Kayak et MyKomela).
        $this->assertStringContainsString('Recopie, ne calcule jamais', $consigne);
        $this->assertStringContainsString('« KAYAK x2 32,00 » donne quantite 2, prix_unitaire null et montant 32.00', $consigne);
        $this->assertStringContainsString("Une ligne qui n'imprime qu'un prix a ce prix pour montant", $consigne);
        $this->assertStringContainsString('« Bracelet 1 225,00 2 » donne quantite 1', $consigne);
        // Les pièges des tickets de tests/e2e : doublon supprimé (Migros), serveur pris pour le vendeur (T21), année devinée (Zunftstube).
        $this->assertStringContainsString('ne fusionne ni ne supprime jamais un doublon', $consigne);
        $this->assertStringContainsString('jamais la personne qui a servi ou encaissé', $consigne);
        $this->assertStringContainsString("Une date dont l'année manque ou est illisible vaut null", $consigne);
        // Les libellés et adresses que le modèle abrégeait (tests/e2e) : contenance (Phyto), ligne de suite (Liquor
        // Street), chiffre lu dans un mot (Migros), abréviation d'adresse (Sorsi).
        $this->assertStringContainsString('« Chemin de Fer 75cl 2008 »', $consigne);
        $this->assertStringContainsString('donnent « Tandoori Roti all food less spicy »', $consigne);
        $this->assertStringContainsString('(« Asia90 » pour « Asiago »)', $consigne);
        $this->assertStringContainsString('abréviations comprises (« C.V. EMANUELE 55 TRAPANI »)', $consigne);
        // LES MOTS DOUTEUX de resources/js/budget-scan.js sont expliqués ; plus de seconde lecture à fusionner : le
        // navigateur n'envoie qu'un texte.
        $this->assertStringNotContainsString('seconde lecture', $consigne);
        $this->assertStringContainsString('Un mot entre ⟦ ⟧ a été lu avec peu de confiance', $consigne);
        $this->assertStringContainsString('dernière ligne de total', $consigne);
        $this->assertStringContainsString('une entrée par ligne — jamais la base', $consigne);
        $this->assertStringNotContainsString('"Taxes"', $consigne);
        // LA RÉFÉRENCE est recopiée telle quelle, signes compris — jamais un autre numéro du ticket.
        $this->assertStringContainsString('« Ticket n°JP_T210002 » donne JP_T210002', $consigne);
        $this->assertStringContainsString('jamais un n° de TVA, de client, de carte, de caisse ni de téléphone', $consigne);
        // LE N° DE TVA SE RECONNAÎT À SA FORME, pas à une mention qui manque souvent.
        $this->assertStringContainsString('Jamais un n° de ticket, de caisse', $consigne);
        // LE GABARIT SUIT LE SCHÉMA : chaque champ exigé y figure, celui des articles compris.
        $this->assertSame(1, preg_match('/^\{"date_document".*\}$/m', $consigne, $gabarit));
        $proprietes = AnalyseurTicket::schema()['properties'];
        foreach ([...AnalyseurTicket::schema()['required'], ...$proprietes['articles']['items']['required'], ...$proprietes['taxes']['items']['required']] as $champ) {
            $this->assertStringContainsString('"' . $champ . '":', $gabarit[0]);
        }
        $this->assertSame(
            ['date_document', 'reference', 'numero_tva', 'vendeur', 'lieu', 'description', 'total_imprime', 'total_ht', 'taxes', 'articles'],
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

        // Personne n'écoute : aucun statut, et l'erreur de cURL qui dit pourquoi.
        $echec = AnalyseurTicket::envoyerHttp('http://127.0.0.1:1/', [], '{}');
        $this->assertSame(0, $echec['statut']);
        $this->assertSame('', $echec['corps']);
        $this->assertStringStartsWith('cURL n° 7 : ', $echec['erreur'] ?? '');
    }
}
