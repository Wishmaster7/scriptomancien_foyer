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

        try {
            $resultat = AnalyseurTicket::analyser($texte);
        } catch (AnalyseImpossible $e) {
            if ($e->appelFacture) {
                self::journaliserAnalyse('Analyse de reçu échouée', $texte, SiteConfig::infomaniakModele());
            }

            return self::echec(502, $e->getMessage());
        }

        self::journaliserAnalyse('Reçu analysé', $texte, $resultat['modele'], $resultat['jetons_entree'], $resultat['jetons_sortie']);

        return ['success' => true, 'donnees' => $resultat['donnees']];
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

        $date = Saisie::date($saisie['date_document']);
        if ($date === '') {
            self::refuser('La date du document est obligatoire, et doit être une date valide.');
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
            'numero_tva' => Saisie::texte($saisie['numero_tva'], AnalyseurTicket::NUMERO_TVA_MAX),
            'vendeur' => Saisie::texte($saisie['vendeur'], AnalyseurTicket::VENDEUR_MAX),
            'lieu' => Saisie::texte($saisie['lieu'], AnalyseurTicket::LIEU_MAX),
            'description' => Saisie::texte($saisie['description'], AnalyseurTicket::DESCRIPTION_MAX),
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
     * @return array{foyer_id: string, date_document: string, numero_tva: string, vendeur: string, lieu: string, description: string, articles: list<array{nom: string, montant: string, monnaie: string, retenu: string}>}
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
        foreach (['foyer_id', 'date_document', 'numero_tva', 'vendeur', 'lieu', 'description'] as $champ) {
            $saisie[$champ] = $chaine($_POST[$champ] ?? '');
        }

        return $saisie;
    }
}
