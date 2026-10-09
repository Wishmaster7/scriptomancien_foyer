<?php

declare(strict_types=1);

use Foyer\App\AnalyseurTicket;
use Foyer\App\BudgetScanController;
use Foyer\App\Saisie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

require_once __DIR__ . '/E2ETestBase.php';

/**
 * « SCANNER UN REÇU », DE BOUT EN BOUT, à partir du texte que Tesseract a lu dans le navigateur.
 *
 * UN SOUS-TEST PAR RÉPERTOIRE de tests/e2e/data/budget_scan/ : en ajouter un suffit, sans toucher à
 * ce fichier. Chaque répertoire porte EXACTEMENT ces cinq fichiers, et rien d'autre :
 *
 * - `1-source.jpg`        la photo du ticket, en JPEG, telle qu'envoyée à la page ;
 * - `2-lang-XX.md`        sa langue (FR, EN, DE, ES, IT), celle du drapeau choisi — le fichier peut
 *                         être vide, c'est son nom qui compte ;
 * - `3-ocr-extract.txt`   le texte que la page en tire (resources/js/budget-scan.js) ;
 * - `4-llm-response.json` la réponse du LLM à ce texte, que le FAUX LLM (serveurLlmFactice.php) rend
 *                         à sa place : la vraie API n'est jamais appelée ;
 * - `5-expected.json`     ce que la proposition doit être, LU SUR LA PHOTO — pas sur le texte OCR.
 *
 * Un fichier manquant, en trop ou mal nommé fait échouer le sous-test, en le nommant.
 *
 * Le parcours exercé est celui de la production : session ouverte, appel AJAX d'analyse — texte et
 * langue —, réponse validée et réconciliée par l'application, puis enregistrement de la quittance.
 *
 * LA PROPOSITION EST COMPARÉE À `5-expected.json`, et le sous-test liste TOUS les écarts :
 *
 * - à l'identique : date_document, reference, total_imprime, et pour chaque article, dans l'ordre,
 *   montant et monnaie ;
 * - numero_tva à la forme près (espaces, points, tirets, et la mention TVA / MWST / IVA finale) ;
 * - vendeur, lieu et nom d'article : le texte attendu doit SE TROUVER dans le texte proposé, sans
 *   égard à la casse, aux accents, aux espaces ni à la ponctuation ; un texte attendu vide exige un
 *   texte proposé vide ;
 * - la description, seul champ que le modèle rédige, n'est pas comparée.
 *
 * Comme l'attendu est la vérité du ticket, un écart peut venir de l'OCR comme du LLM : c'est ainsi
 * qu'une lecture ou une réponse fautive devient un cas à corriger — dans resources/js/budget-scan.js
 * ou dans la consigne (AnalyseurTicket::consigne()).
 */
class BudgetScanTest extends E2ETestBase
{
    private const REPERTOIRE = __DIR__ . '/data/budget_scan';

    /** Le suffixe du fichier de langue, et le drapeau (code Tesseract) qu'il désigne. */
    private const LANGUES = ['FR' => 'fra', 'EN' => 'eng', 'DE' => 'deu', 'ES' => 'spa', 'IT' => 'ita'];

    private const SOURCE = '1-source.jpg';
    private const OCR = '3-ocr-extract.txt';
    private const REPONSE_LLM = '4-llm-response.json';
    private const ATTENDU = '5-expected.json';

    /** @return array<string, array{0: string}> */
    public static function tickets(): array
    {
        $tickets = [];
        foreach (glob(self::REPERTOIRE . '/*', GLOB_ONLYDIR) ?: [] as $repertoire) {
            $tickets[basename($repertoire)] = [basename($repertoire)];
        }

        return $tickets;
    }

    /** Chaque langue de lecture de la page a son suffixe, et chaque suffixe une langue de la page. */
    public function testLesSuffixesDeLangueSontCeuxDeLaPage(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(BudgetScanController::LANGUES), array_values(self::LANGUES));
        $this->assertNotSame([], self::tickets(), 'Aucun ticket dans ' . self::REPERTOIRE . '.');
    }

    #[DataProvider('tickets')]
    #[TestDox('Ticket $ticket : analysé, réconcilié sur son total, enregistré')]
    public function testTicket(string $ticket): void
    {
        [$langue, $texte, $reponseLlm, $attendu] = $this->lireTicket($ticket);
        $this->imposerReponseLlm($reponseLlm);

        $donnees = $this->analyserTicket($texte, $langue);

        // CE QUE L'APPLICATION PROPOSE est la réponse du LLM, validée et réconciliée, plus le cours de change.
        $change = ['monnaie_foyer', 'monnaie_recu', 'cours_change'];
        $this->assertSame(AnalyseurTicket::normaliser((array) json_decode($reponseLlm, true)), array_diff_key($donnees, array_flip($change)));
        $this->assertNotSame([], $donnees['articles'], 'Aucun article proposé.');

        // LE COURS DE CHANGE PROPOSÉ : le foyer est en CHF ; un reçu daté, dans UNE autre monnaie, reçoit
        // celui du faux service des cours (serveurLlmFactice.php).
        $recu = self::monnaieRecu($donnees['articles']);
        $this->assertSame(
            ['monnaie_foyer' => 'CHF', 'monnaie_recu' => $recu, 'cours_change' => $recu !== '' && $donnees['date_document'] !== '' ? '1.50000000' : ''],
            array_intersect_key($donnees, array_flip($change))
        );

        // LA SOMME DES ARTICLES, monnaie par monnaie, est le total payé — dès qu'il y en a un.
        $sommes = [];
        foreach ($donnees['articles'] as $article) {
            $sommes[$article['monnaie']] = ($sommes[$article['monnaie']] ?? 0) + Saisie::centimes($article['montant']);
        }
        if ($donnees['total_imprime'] !== null) {
            $this->assertCount(1, $sommes, 'Un ticket se paie dans une seule monnaie.');
            $this->assertSame(
                $donnees['total_imprime'],
                number_format(array_sum($sommes) / 100, 2, '.', ''),
                'La somme des articles proposés n\'est pas le total payé.'
            );
        }

        $this->enregistrerQuittance($donnees);

        // ET C'EST CE QUE PORTE LE TICKET — vérifié en dernier : le parcours est éprouvé jusqu'au bout
        // même quand la lecture ou la réponse s'écarte de l'attendu.
        $ecarts = self::ecarts($attendu, $donnees);
        $this->assertSame([], $ecarts, "$ticket : la proposition s'écarte de " . self::ATTENDU . " :\n- " . implode("\n- ", $ecarts));
    }

    /**
     * La monnaie étrangère du reçu pour un foyer en CHF : celle de ses articles qui n'est pas le franc,
     * s'il n'y en a qu'une ; sinon ''.
     *
     * @param list<array{monnaie: string}> $articles
     */
    private static function monnaieRecu(array $articles): string
    {
        $etrangeres = array_values(array_diff(array_unique(array_column($articles, 'monnaie')), ['CHF']));

        return count($etrangeres) === 1 ? $etrangeres[0] : '';
    }

    /**
     * Le contenu du répertoire du ticket, après contrôle de sa nomenclature : la langue (code
     * Tesseract), le texte OCR, la réponse du LLM et la proposition attendue.
     *
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function lireTicket(string $ticket): array
    {
        $repertoire = self::REPERTOIRE . '/' . $ticket;
        $fichiers = array_values(array_diff(scandir($repertoire) ?: [], ['.', '..']));
        $nomenclature = 'Attendus, et eux seuls : ' . self::SOURCE . ', 2-lang-XX.md (XX parmi ' . implode(', ', array_keys(self::LANGUES))
            . '), ' . self::OCR . ', ' . self::REPONSE_LLM . ', ' . self::ATTENDU . '. Trouvés : ' . implode(', ', $fichiers) . '.';

        $langues = preg_grep('/^2-lang-(' . implode('|', array_keys(self::LANGUES)) . ')\.md$/', $fichiers);
        $this->assertCount(1, $langues, "$ticket : fichier de langue manquant ou mal nommé. $nomenclature");
        $this->assertEqualsCanonicalizing([self::SOURCE, ...$langues, self::OCR, self::REPONSE_LLM, self::ATTENDU], $fichiers, "$ticket : $nomenclature");

        $this->assertSame("\xFF\xD8\xFF", (string) file_get_contents($repertoire . '/' . self::SOURCE, false, null, 0, 3), "$ticket : " . self::SOURCE . " n'est pas un JPEG.");
        $texte = (string) file_get_contents($repertoire . '/' . self::OCR);
        $this->assertNotSame('', trim($texte), "$ticket : " . self::OCR . ' est vide.');
        $reponseLlm = (string) file_get_contents($repertoire . '/' . self::REPONSE_LLM);
        $this->assertIsArray(json_decode($reponseLlm, true), "$ticket : " . self::REPONSE_LLM . " n'est pas un objet JSON.");

        return [self::LANGUES[substr((string) reset($langues), strlen('2-lang-'), 2)], $texte, $reponseLlm, $this->lireAttendu($ticket, $repertoire . '/' . self::ATTENDU)];
    }

    /**
     * `5-expected.json`, après contrôle de sa forme : tous les champs comparés, en chaînes, et des
     * articles qui ont chacun un nom, un montant et une monnaie.
     *
     * @return array<string, mixed>
     */
    private function lireAttendu(string $ticket, string $fichier): array
    {
        $attendu = json_decode((string) file_get_contents($fichier), true);
        $forme = "$ticket : " . self::ATTENDU . ' doit porter date_document, reference, numero_tva, vendeur, lieu (chaînes, vides si absents), '
            . 'total_imprime (chaîne « 12.50 » ou null) et articles (liste de { nom, montant, monnaie }).';
        $this->assertIsArray($attendu, $forme);
        foreach (['date_document', 'reference', 'numero_tva', 'vendeur', 'lieu'] as $champ) {
            $this->assertIsString($attendu[$champ] ?? null, $forme);
        }
        $this->assertTrue(array_key_exists('total_imprime', $attendu) && ($attendu['total_imprime'] === null || is_string($attendu['total_imprime'])), $forme);
        $this->assertIsList($attendu['articles'] ?? null, $forme);
        foreach ($attendu['articles'] as $article) {
            $this->assertSame(['nom', 'montant', 'monnaie'], is_array($article) ? array_keys($article) : null, $forme);
        }

        return $attendu;
    }

    /**
     * Les écarts entre la proposition et l'attendu, un par champ ou par article, selon les règles de
     * comparaison de cette classe.
     *
     * @param  array<string, mixed> $attendu
     * @param  array<string, mixed> $propose
     * @return list<string>
     */
    private static function ecarts(array $attendu, array $propose): array
    {
        $ecarts = [];
        $ecart = static function (string $champ, mixed $voulu, mixed $obtenu) use (&$ecarts): void {
            $ecarts[] = $champ . ' : attendu ' . json_encode($voulu, JSON_UNESCAPED_UNICODE) . ', proposé ' . json_encode($obtenu, JSON_UNESCAPED_UNICODE);
        };

        foreach (['date_document', 'reference', 'total_imprime'] as $champ) {
            if ($attendu[$champ] !== $propose[$champ]) {
                $ecart($champ, $attendu[$champ], $propose[$champ]);
            }
        }
        $tva = static fn (string $numero): string => (string) preg_replace('/(TVA|MWST|IVA)$/', '', strtoupper((string) preg_replace('/[\s.\-]/', '', $numero)));
        if ($tva($attendu['numero_tva']) !== $tva($propose['numero_tva'])) {
            $ecart('numero_tva', $attendu['numero_tva'], $propose['numero_tva']);
        }
        foreach (['vendeur', 'lieu'] as $champ) {
            if (!self::seTrouveDans($attendu[$champ], $propose[$champ])) {
                $ecart($champ, $attendu[$champ], $propose[$champ]);
            }
        }

        if (count($attendu['articles']) !== count($propose['articles'])) {
            $ecart('nombre d\'articles', count($attendu['articles']), count($propose['articles']));
        }
        foreach ($attendu['articles'] as $rang => $article) {
            $obtenu = $propose['articles'][$rang] ?? ['nom' => '', 'montant' => '', 'monnaie' => ''];
            $libelle = 'article ' . ($rang + 1) . ' « ' . $article['nom'] . ' »';
            if (!self::seTrouveDans($article['nom'], $obtenu['nom'])) {
                $ecart($libelle . ', nom', $article['nom'], $obtenu['nom']);
            }
            foreach (['montant', 'monnaie'] as $champ) {
                if ($article[$champ] !== $obtenu[$champ]) {
                    $ecart($libelle . ', ' . $champ, $article[$champ], $obtenu[$champ]);
                }
            }
        }

        return $ecarts;
    }

    /** Le texte attendu se trouve-t-il dans le texte proposé — sans égard à la casse, aux accents, aux espaces ni à la ponctuation ? */
    private static function seTrouveDans(string $attendu, string $propose): bool
    {
        $simplifier = static fn (string $texte): string => (string) preg_replace(
            '/[^a-z0-9]/',
            '',
            (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texte)
        );

        return $attendu === '' ? $propose === '' : str_contains($simplifier($propose), $simplifier($attendu));
    }

    /**
     * Ouvre une session de membre d'un foyer, puis poste le texte OCR du ticket à l'analyse, avec la
     * langue de son drapeau, comme le fait resources/js/budget-scan.js. Vérifie que la requête est
     * bien partie vers le FAUX LLM — consigne, langue et texte compris —, et rend la proposition validée.
     *
     * @return array{date_document: string, reference: string, numero_tva: string, vendeur: string, lieu: string, description: string, total_imprime: ?string, articles: list<array{nom: string, montant: string, monnaie: string, a_verifier: bool}>, monnaie_foyer: string, monnaie_recu: string, cours_change: string}
     */
    private function analyserTicket(string $texte, string $langue): array
    {
        $id = $this->creerIdentite('scan@example.test', 'Scanneur');
        self::$db->query("INSERT INTO FOYER (NOM, CREATED_BY, LAST_MODIFIED_BY) VALUES ('Maison', $id, $id)");
        $foyerId = (int) self::$db->insert_id;
        self::$db->query("INSERT INTO FOYER_PERSONNE (FOYER_ID, PERSONNE_ID, CREATED_BY, LAST_MODIFIED_BY) VALUES ($foyerId, $id, $id, $id)");
        $this->seConnecter('scan@example.test');

        $page = $this->get('/?action=budget_scan');
        $this->assertSame(200, $page['statut']);

        $reponse = $this->post('/?action=budget_scan_analyser', ['texte' => $texte, 'langue' => $langue, 'foyer_id' => (string) $foyerId, 'csrf_token' => $this->jetonCsrf($page['corps'])]);
        $resultat = json_decode($reponse['corps'], true);
        $this->assertTrue($resultat['success'] ?? false, 'Analyse refusée : ' . $reponse['corps']);

        // L'APPEL EST PARTI VERS LE FAUX LLM, une seule fois, jeton, consigne, langue et texte compris.
        $appels = array_map(
            static fn (string $ligne): array => json_decode($ligne, true),
            file(self::$journalLlm, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
        );
        $this->assertCount(1, $appels);
        $this->assertSame('/2/ai/4242/openai/v1/chat/completions', $appels[0]['chemin']);
        $this->assertSame('Bearer jeton-e2e', $appels[0]['autorisation']);
        $this->assertSame(AnalyseurTicket::consigne(), $appels[0]['requete']['messages'][0]['content']);
        $this->assertSame(
            AnalyseurTicket::requete('', trim($texte), mb_strtolower(BudgetScanController::LANGUES[$langue][0]))['messages'][1]['content'],
            $appels[0]['requete']['messages'][1]['content']
        );

        return $resultat['donnees'];
    }

    /**
     * Enregistre la proposition telle quelle, toutes lignes cochées, comme le formulaire de relecture.
     * Sans date, ou avec un montant resté vide, l'enregistrement est REFUSÉ et rien n'est écrit : la
     * personne doit d'abord compléter le formulaire.
     *
     * @param array{date_document: string, reference: string, cours_change: string, numero_tva: string, vendeur: string, lieu: string, description: string, articles: list<array{nom: string, montant: string, monnaie: string}>} $donnees
     */
    private function enregistrerQuittance(array $donnees): void
    {
        $page = $this->get('/?action=budget_scan');
        preg_match('/name="foyer_id" value="(\d+)"/', $page['corps'], $foyer);
        $champs = [
            'csrf_token' => $this->jetonCsrf($page['corps']),
            'foyer_id' => $foyer[1] ?? '',
        ];
        foreach (['date_document', 'reference', 'cours_change', 'numero_tva', 'vendeur', 'lieu', 'description'] as $champ) {
            $champs[$champ] = $donnees[$champ];
        }
        foreach ($donnees['articles'] as $rang => $article) {
            foreach (['nom', 'montant', 'monnaie'] as $champ) {
                $champs["articles[$rang][$champ]"] = $article[$champ];
            }
            $champs["articles[$rang][retenu]"] = '1';
        }

        $reponse = $this->post('/?action=budget_scan', $champs);
        $this->assertSame(302, $reponse['statut']);

        $complete = $donnees['date_document'] !== '' && !in_array('', array_column($donnees['articles'], 'montant'), true);
        if (!$complete) {
            $this->assertSame('/?action=budget_scan', $reponse['location'], 'Une proposition incomplète ne s\'enregistre pas.');
            $this->assertSame('0', self::$db->query('SELECT COUNT(*) FROM BUDGET_SCAN')->fetch_row()[0]);

            return;
        }

        $this->assertStringStartsWith('/?action=budget&foyer=', (string) $reponse['location']);
        $quittance = self::$db->query('SELECT ID, DATE_DOCUMENT, REFERENCE, COURS_CHANGE FROM BUDGET_SCAN')->fetch_assoc();
        $this->assertSame(str_replace('T', ' ', $donnees['date_document']) . ':00', $quittance['DATE_DOCUMENT']);
        $this->assertSame($donnees['reference'] === '' ? null : $donnees['reference'], $quittance['REFERENCE']);
        // Un reçu étranger porte son cours — proposé, ou demandé à l'enregistrement — ; un reçu en CHF, aucun.
        $this->assertSame(self::monnaieRecu($donnees['articles']) === '' ? null : '1.50000000', $quittance['COURS_CHANGE']);
        $this->assertSame(
            [(string) count($donnees['articles'])],
            self::$db->query('SELECT COUNT(*) FROM BUDGET_SCAN_ARTICLE WHERE SCAN_ID = ' . (int) $quittance['ID'])->fetch_row()
        );
    }
}
