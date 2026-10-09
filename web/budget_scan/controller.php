<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Transaction;

/**
 * « Scanner un reçu » : la page (dépôt ou photo, OCR dans le navigateur, formulaire de relecture),
 * l'appel AJAX qui fait structurer le texte par le LLM, et l'enregistrement des lignes cochées.
 */
class BudgetScanController
{
    public const URL = '/?action=budget_scan';
    public const URL_ANALYSE = '/?action=budget_scan_analyser';

    /** Les fichiers de Tesseract.js, servis par le site — jamais par un CDN. Versionnés : leur cache HTTP est éternel. */
    public const TESSERACT = '/resources/tesseract/7.0.0';

    /**
     * Les langues de lecture proposées, dans l'ordre des drapeaux ; la PREMIÈRE est choisie d'office. La clé est le code
     * du modèle Tesseract (resources/tesseract/…/lang/4.1.0_best/<code>.traineddata.gz).
     *
     * @var array<string, array{0: string, 1: string}> code => [libellé, image du drapeau]
     */
    public const LANGUES = [
        'fra' => ['Français', 'drapeau-francais.png'],
        'eng' => ['Anglais', 'drapeau-anglais.png'],
        'deu' => ['Allemand', 'drapeau-allemand.png'],
        'spa' => ['Espagnol', 'drapeau-espagnol.png'],
        'ita' => ['Italien', 'drapeau-italien.png'],
    ];

    private const ENTITE = 'budget_scan';

    public function traiter(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->enregistrer();
        }

        $foyers = FoyersModel::foyersDe(Compte::id());
        $snippet = new Snippet();

        echo $snippet->getHeader('Scanner un reçu', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/template_budget_scan.php', [
            'foyers' => $foyers,
            'memoire' => MemoireFormulaire::ajout(self::ENTITE),
            'csrf_token' => Utils::jetonCsrf(),
        ]);
        echo $snippet->getFooter();
    }

    /** Le point d'appel AJAX : texte OCR en entrée, JSON en sortie — `success`, puis `donnees` ou `message`. */
    public function analyser(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->reponseAnalyse(), JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function reponseAnalyse(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return self::echec(405, 'Méthode non autorisée.');
        }
        if (FoyersModel::foyersDe(Compte::id()) === []) {
            return self::echec(403, "Vous n'êtes membre d'aucun foyer.");
        }

        $texte = is_string($_POST['texte'] ?? null) ? trim($_POST['texte']) : '';
        if ($texte === '') {
            return self::echec(422, "Aucun texte n'a été reconnu sur l'image. Reprenez la photo, bien à plat et bien éclairée, ou saisissez le reçu à la main.");
        }
        if (BudgetScanModel::analysesRecentes(Compte::id()) >= SiteConfig::ANALYSES_PAR_HEURE) {
            return self::echec(429, 'Vous avez atteint la limite de ' . SiteConfig::ANALYSES_PAR_HEURE
                . ' analyses par heure. Réessayez plus tard, ou saisissez le reçu à la main.');
        }

        $texte = mb_substr($texte, 0, AnalyseurTicket::LONGUEUR_MAX_TEXTE);
        // LA LANGUE DU DRAPEAU CHOISI, dite au modèle ; un code inconnu n'en dit aucune.
        $langue = self::LANGUES[is_string($_POST['langue'] ?? null) ? $_POST['langue'] : ''][0] ?? null;

        try {
            $resultat = AnalyseurTicket::analyser($texte, $langue === null ? null : mb_strtolower($langue));
        } catch (AnalyseImpossible $e) {
            if ($e->appelFacture) {
                self::journaliserAnalyse('Analyse de reçu échouée', $texte, SiteConfig::infomaniakModele());
            }

            // EN MODE DEBUG SEULEMENT, le récit complet de l'échec suit le message : il montre la réponse
            // brute de l'API, que la personne n'a pas à voir en production.
            $echec = self::echec(502, SiteConfig::debugMode() && $e->detail !== ''
                ? $e->getMessage() . "\n\n[DEBUG_MODE]\n" . $e->detail
                : $e->getMessage());

            return SiteConfig::debugMode() ? $echec + self::diagnostic($e->trace) : $echec;
        }

        self::journaliserAnalyse('Reçu analysé', $texte, $resultat['modele'], $resultat['jetons_entree'], $resultat['jetons_sortie']);
        $reponse = ['success' => true, 'donnees' => $resultat['donnees'] + self::changePropose($resultat['donnees'])];

        return SiteConfig::debugMode() ? $reponse + self::diagnostic($resultat['trace'], $resultat) : $reponse;
    }

    /**
     * LE COURS DE CHANGE proposé avec l'analyse, pour le foyer choisi dans le formulaire : quand le reçu
     * est daté et que ses articles sont dans UNE autre monnaie que celle du foyer, le cours de la BCE
     * au jour du reçu ({@see CoursDeChange}). Sinon, ou si le service ne répond pas, un cours vide :
     * il sera demandé à l'enregistrement, ou saisi par la personne.
     *
     * @param  array{date_document: string, articles: list<array{monnaie: string}>}              $donnees
     * @return array{monnaie_foyer: string, monnaie_recu: string, cours_change: string}
     */
    private static function changePropose(array $donnees): array
    {
        $foyer = FoyersModel::parId((int) ($_POST['foyer_id'] ?? 0));
        if ($foyer === null || !FoyersModel::estMembre($foyer['ID'], Compte::id())) {
            return ['monnaie_foyer' => '', 'monnaie_recu' => '', 'cours_change' => ''];
        }

        $etrangeres = self::monnaiesEtrangeres(array_column($donnees['articles'], 'monnaie'), $foyer['MONNAIE_REF']);
        $recu = count($etrangeres) === 1 ? $etrangeres[0] : '';
        $cours = $recu !== '' && $donnees['date_document'] !== ''
            ? CoursDeChange::cours($recu, $foyer['MONNAIE_REF'], substr($donnees['date_document'], 0, 10))
            : null;

        return ['monnaie_foyer' => $foyer['MONNAIE_REF'], 'monnaie_recu' => $recu, 'cours_change' => $cours ?? ''];
    }

    /**
     * Les monnaies, sans doublon, qui ne sont pas celle du foyer.
     *
     * @param  list<string> $monnaies
     * @return list<string>
     */
    private static function monnaiesEtrangeres(array $monnaies, string $monnaieFoyer): array
    {
        return array_values(array_diff(array_unique($monnaies), [$monnaieFoyer]));
    }

    /**
     * LE COURS DE CHANGE ENREGISTRÉ avec la dépense : aucun si tous ses articles sont dans la monnaie
     * du foyer ; sinon celui que la personne a saisi ou gardé, et à défaut celui de la BCE au jour du
     * document. Une dépense ne porte qu'une monnaie étrangère — un seul cours —, et jamais sans cours :
     * l'écran Budget doit pouvoir la convertir.
     *
     * @param array{MONNAIE_REF: string}           $foyer
     * @param list<array{monnaie: string}>         $articles
     */
    private static function coursRetenu(array $foyer, array $articles, string $date, string $saisi): ?string
    {
        $etrangeres = self::monnaiesEtrangeres(array_column($articles, 'monnaie'), $foyer['MONNAIE_REF']);
        if ($etrangeres === []) {
            return null;
        }
        if (count($etrangeres) > 1) {
            self::refuser("Une dépense ne peut porter qu'une monnaie en plus de celle du foyer ({$foyer['MONNAIE_REF']}).");
        }

        $saisi = str_replace(',', '.', trim($saisi));
        if ($saisi !== '') {
            if (preg_match('/^\d{1,8}(\.\d{1,8})?$/', $saisi) !== 1 || (float) $saisi <= 0) {
                self::refuser('Le cours de change doit être un nombre positif, par exemple 0.9412.');
            }

            return number_format((float) $saisi, 8, '.', '');
        }

        return CoursDeChange::cours($etrangeres[0], $foyer['MONNAIE_REF'], substr($date, 0, 10))
            ?? self::refuser("Le cours {$etrangeres[0]} → {$foyer['MONNAIE_REF']} au " . date('d.m.Y', (int) strtotime($date))
                . " n'a pas pu être obtenu : saisissez-le (nombre de {$foyer['MONNAIE_REF']} pour 1 {$etrangeres[0]}).");
    }

    /**
     * LE DIAGNOSTIC D'UNE ANALYSE, ajouté à la réponse EN MODE DEBUG SEULEMENT — jamais en production,
     * où la réponse ne porte que `success` et `donnees` ou `message`.
     *
     * - `input` : le texte reçu de Tesseract.js, tel que posté ;
     * - `prompt` : les deux messages envoyés au modèle (consigne, puis texte) ;
     * - `debug` : les paramètres et le schéma imposé, chaque appel à l'API (adresse, statut, durée,
     *   corps reçu), le contenu brut rendu par le modèle, ce qu'il proposait avant la validation
     *   (`donnees_llm`, à comparer à `donnees`), la lecture des montants retenue par le contrôle du
     *   total (`lecture_des_montants`, {@see AnalyseurTicket::reconcilier()}), les jetons consommés, le contrôle de la somme des
     *   articles par monnaie face au total imprimé, et le quota horaire de la personne.
     *
     * @param  array<string, mixed>                                                                $trace
     * @param  array{donnees: array<string, mixed>, jetons_entree: ?int, jetons_sortie: ?int}|null $resultat
     * @return array{input: mixed, prompt: mixed, debug: array<string, mixed>}
     */
    private static function diagnostic(array $trace, ?array $resultat = null): array
    {
        $texteRecu = $_POST['texte'] ?? null;
        $debug = [
            'texte_tronque_a' => is_string($texteRecu) && mb_strlen(trim($texteRecu)) > AnalyseurTicket::LONGUEUR_MAX_TEXTE ? AnalyseurTicket::LONGUEUR_MAX_TEXTE : null,
            'parametres' => $trace['parametres'] ?? null,
            'appels' => $trace['appels'] ?? [],
            'reponse_llm' => $trace['reponse_llm'] ?? null,
            'donnees_llm' => $trace['donnees_llm'] ?? null,
            'lecture_des_montants' => $trace['lecture_des_montants'] ?? null,
            'analyses_cette_heure' => BudgetScanModel::analysesRecentes(Compte::id()) . ' / ' . SiteConfig::ANALYSES_PAR_HEURE,
        ];

        if ($resultat !== null) {
            $debug['jetons'] = ['entree' => $resultat['jetons_entree'], 'sortie' => $resultat['jetons_sortie']];
            $sommes = [];
            foreach ($resultat['donnees']['articles'] as $article) {
                $sommes[$article['monnaie']] = ($sommes[$article['monnaie']] ?? 0) + Saisie::centimes($article['montant']);
            }
            $debug['controle_total'] = [
                'somme_articles' => array_map(static fn (int $centimes): string => number_format($centimes / 100, 2, '.', ''), $sommes),
                'total_imprime' => $resultat['donnees']['total_imprime'],
            ];
        }

        return ['input' => $texteRecu, 'prompt' => $trace['prompt'] ?? null, 'debug' => $debug];
    }

    /** @return array{success: false, message: string} */
    private static function echec(int $statut, string $message): array
    {
        http_response_code($statut);

        return ['success' => false, 'message' => $message];
    }

    /** La trace d'un appel facturé : sa taille et son coût en jetons, jamais le texte du reçu. */
    private static function journaliserAnalyse(string $description, string $texte, string $modele, ?int $entree = null, ?int $sortie = null): void
    {
        Journal::ecrire(Journal::TYPE_ANALYSE, $description, Compte::id(), null, [
            ...Journal::info('Caractères', (string) mb_strlen($texte)),
            ...Journal::info('Modèle', $modele),
            ...Journal::info('Jetons', $entree === null || $sortie === null ? '' : $entree . ' en entrée, ' . $sortie . ' en sortie'),
        ]);
    }

    /**
     * Enregistre les lignes COCHÉES. Tout refus rouvre le formulaire sur la saisie postée.
     */
    private function enregistrer(): void
    {
        $saisie = self::saisiePostee();
        MemoireFormulaire::memoriserAjout(self::ENTITE, $saisie);

        $foyer = FoyersModel::parId((int) $saisie['foyer_id']);
        if ($foyer === null || !FoyersModel::estMembre($foyer['ID'], Compte::id())) {
            self::refuser("Ce foyer n'est pas l'un des vôtres.");
        }

        $date = Saisie::dateHeure($saisie['date_document']);
        if ($date === '') {
            self::refuser('La date du document est obligatoire, et doit être une date et une heure valides.');
        }

        $articles = [];
        foreach ($saisie['articles'] as $rang => $ligne) {
            if ($ligne['retenu'] !== '1') {
                continue;
            }
            $nom = Saisie::texte($ligne['nom'], AnalyseurTicket::NOM_ARTICLE_MAX);
            $montant = Saisie::montant($ligne['montant']);
            $monnaie = strtoupper(trim($ligne['monnaie']));
            if ($nom === '' || $montant === null || !Saisie::estMonnaie($monnaie)) {
                self::refuser("L'article n° " . ($rang + 1) . ' est incomplet : il lui faut un nom, un montant (ex. 12.50) et une monnaie en trois lettres (ex. CHF).');
            }
            $articles[] = ['nom' => $nom, 'montant' => $montant, 'monnaie' => $monnaie];
        }
        if ($articles === []) {
            self::refuser('Cochez au moins un article à enregistrer.');
        }

        $entete = [
            'date_document' => $date,
            'reference' => Saisie::texte($saisie['reference'], AnalyseurTicket::REFERENCE_MAX),
            'numero_tva' => Saisie::texte($saisie['numero_tva'], AnalyseurTicket::NUMERO_TVA_MAX),
            'vendeur' => Saisie::texte($saisie['vendeur'], AnalyseurTicket::VENDEUR_MAX),
            'lieu' => Saisie::texte($saisie['lieu'], AnalyseurTicket::LIEU_MAX),
            'description' => Saisie::texte($saisie['description'], AnalyseurTicket::DESCRIPTION_MAX),
            // Demandé AVANT la transaction : un appel réseau n'a rien à faire pendant qu'elle est ouverte.
            'cours_change' => self::coursRetenu($foyer, $articles, $date, $saisie['cours_change']),
        ];

        try {
            Transaction::executer(static function () use ($foyer, $entete, $articles): void {
                $scanId = BudgetScanModel::enregistrer($foyer['ID'], Compte::id(), $entete, $articles);
                Journal::ecrire(Journal::TYPE_CREATION, 'Dépense enregistrée', Compte::id(), null, [
                    ...Journal::info('Dépense n°', (string) $scanId),
                    ...Journal::info('Foyer', $foyer['NOM']),
                    ...Journal::info('Date', $entete['date_document']),
                    ...Journal::info('Vendeur', $entete['vendeur']),
                    ...Journal::info('Articles', (string) count($articles)),
                    ...Journal::info('Cours de change', $entete['cours_change']),
                ]);
            });
        } catch (\RuntimeException) {
            self::refuser("L'enregistrement a échoué. Réessayez.");
        }

        MemoireFormulaire::oublierAjout(self::ENTITE);
        Flash::poser('succes', 'Dépense enregistrée : ' . count($articles) . ' article(s).');
        Utils::rediriger('/?action=budget&foyer=' . $foyer['ID']);
    }

    private static function refuser(string $message): never
    {
        Flash::poser('erreur', $message);
        Utils::rediriger(self::URL);
    }

    /**
     * La saisie postée, réduite à des chaînes : c'est elle que le formulaire retrouve après un refus.
     *
     * @return array{foyer_id: string, date_document: string, reference: string, cours_change: string, numero_tva: string, vendeur: string, lieu: string, description: string, articles: list<array{nom: string, montant: string, monnaie: string, retenu: string}>}
     */
    private static function saisiePostee(): array
    {
        $chaine = static fn (mixed $valeur): string => is_string($valeur) ? $valeur : '';

        $articles = [];
        foreach (is_array($_POST['articles'] ?? null) ? $_POST['articles'] : [] as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $articles[] = [
                'nom' => $chaine($ligne['nom'] ?? ''),
                'montant' => $chaine($ligne['montant'] ?? ''),
                'monnaie' => $chaine($ligne['monnaie'] ?? ''),
                'retenu' => $chaine($ligne['retenu'] ?? ''),
            ];
        }

        $saisie = ['articles' => array_slice($articles, 0, AnalyseurTicket::ARTICLES_MAX)];
        foreach (['foyer_id', 'date_document', 'reference', 'cours_change', 'numero_tva', 'vendeur', 'lieu', 'description'] as $champ) {
            $saisie[$champ] = $chaine($_POST[$champ] ?? '');
        }

        return $saisie;
    }
}
