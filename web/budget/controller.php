<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Transaction;

/**
 * « Budget » : les dépenses d'un foyer de la personne connectée — un onglet par foyer quand elle en
 * a plusieurs —, filtrées par date du document et par date de saisie.
 */
class BudgetController
{
    /** Les quatre bornes de filtre, dans l'URL comme dans le formulaire. */
    private const FILTRES = ['doc_du', 'doc_au', 'saisie_du', 'saisie_au'];

    public function traiter(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->supprimer();
        }

        $foyers = FoyersModel::foyersDe(Compte::id());
        $foyer = self::foyerChoisi($foyers, (int) ($_GET['foyer'] ?? 0));
        $filtres = self::filtres($_GET);

        $snippet = new Snippet();

        echo $snippet->getHeader('Budget', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/template_budget.php', [
            'foyers' => $foyers,
            'foyer' => $foyer,
            'filtres' => $filtres,
            'budget' => $foyer === null ? null : BudgetModel::depenses($foyer['ID'], $filtres),
            'personne_id' => Compte::id(),
            'csrf_token' => Utils::jetonCsrf(),
        ]);
        echo $snippet->getFooter();
    }

    /**
     * Les filtres de période. ABSENT, un filtre prend sa valeur par défaut — le mois courant pour la
     * date du document, aucune borne pour la date de saisie ; PRÉSENT mais vide ou invalide, il ne
     * borne rien. C'est ce qui permet à « Tout afficher » de lever le mois courant.
     *
     * @param  array<string, mixed>                                                      $source
     * @return array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string}
     */
    public static function filtres(array $source): array
    {
        $maintenant = Utils::maintenant();
        $defauts = [
            'doc_du' => date('Y-m-01', $maintenant),
            'doc_au' => date('Y-m-t', $maintenant),
            'saisie_du' => '',
            'saisie_au' => '',
        ];

        $filtres = [];
        foreach (self::FILTRES as $filtre) {
            $filtres[$filtre] = array_key_exists($filtre, $source) ? Saisie::date($source[$filtre]) : $defauts[$filtre];
        }

        return $filtres;
    }

    /**
     * L'adresse de la page Budget pour ce foyer et ces filtres.
     *
     * @param array<string, string> $filtres
     */
    public static function url(int $foyerId, array $filtres): string
    {
        return '/?' . http_build_query(['action' => 'budget', 'foyer' => $foyerId] + $filtres);
    }

    /**
     * Le foyer demandé s'il est l'un de ceux de la personne, sinon le premier — ou null si elle n'en a aucun.
     *
     * @param  list<array{ID: int, NOM: string}> $foyers
     * @return array{ID: int, NOM: string}|null
     */
    private static function foyerChoisi(array $foyers, int $demande): ?array
    {
        foreach ($foyers as $foyer) {
            if ($foyer['ID'] === $demande) {
                return $foyer;
            }
        }

        return $foyers[0] ?? null;
    }

    /** Supprime une dépense de la personne connectée, puis revient sur le même foyer et les mêmes filtres. */
    private function supprimer(): void
    {
        $retour = self::url((int) ($_POST['foyer'] ?? 0), self::filtres(is_array($_POST['retour'] ?? null) ? $_POST['retour'] : []));
        $depense = BudgetModel::depenseDe((int) ($_POST['scan_id'] ?? 0), Compte::id());

        if ($depense === null) {
            Flash::poser('erreur', 'Cette dépense est introuvable, ou ce n\'est pas la vôtre.');
            Utils::rediriger($retour);
        }

        try {
            $efface = Transaction::executer(static function () use ($depense): bool {
                $efface = BudgetModel::supprimer($depense['ID'], Compte::id());
                Journal::ecrire(Journal::TYPE_SUPPRESSION, $efface ? 'Dépense effacée' : 'Dépense supprimée', Compte::id(), null, [
                    ...Journal::info('Dépense n°', (string) $depense['ID']),
                    ...Journal::info('Date', (string) $depense['DATE_DOCUMENT']),
                    ...Journal::info('Vendeur', (string) $depense['VENDEUR']),
                ]);

                return $efface;
            });
            Flash::poser('succes', $efface ? 'La dépense est effacée.' : 'La dépense est supprimée.');
        } catch (\RuntimeException) {
            Flash::poser('erreur', 'La suppression a échoué. Réessayez.');
        }

        Utils::rediriger($retour);
    }
}
