<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Lecture des dépenses d'un foyer, et leur suppression.
 *
 * LES TOTAUX SONT TENUS PAR MONNAIE, en centimes : des CHF et des EUR ne s'additionnent jamais.
 */
final class BudgetModel
{
    /** Délai pendant lequel une dépense supprimée est EFFACÉE plutôt que marquée supprimée. */
    public const MINUTES_EFFACEMENT = 5;

    /**
     * Les dépenses actives d'un foyer, filtrées, avec leurs articles et les totaux.
     *
     * @param  array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string} $filtres Dates « AAAA-MM-JJ », '' = sans borne
     * @return array{depenses: list<array<string, mixed>>, par_personne: array<int, array{PSEUDONYME: string, TOTAUX: array<string, int>}>, total: array<string, int>}
     */
    public static function depenses(int $foyerId, array $filtres): array
    {
        $conditions = "s.FOYER_ID = ? AND s.STATUT = 'ACTIF'";
        $types = 'i';
        $parametres = [$foyerId];
        foreach ([
            'doc_du' => 's.DATE_DOCUMENT >= ?',
            'doc_au' => 's.DATE_DOCUMENT <= ?',
            'saisie_du' => 's.CREATED_WHEN >= ?',
            'saisie_au' => 's.CREATED_WHEN < DATE_ADD(?, INTERVAL 1 DAY)',
        ] as $filtre => $condition) {
            if ($filtres[$filtre] !== '') {
                $conditions .= ' AND ' . $condition;
                $types .= 's';
                $parametres[] = $filtres[$filtre];
            }
        }

        $depenses = Database::lignes(
            "SELECT s.ID, s.PERSONNE_ID, s.DATE_DOCUMENT, s.NUMERO_TVA, s.VENDEUR, s.DESCRIPTION, s.LIEU,
                    s.CREATED_WHEN, p.PSEUDONYME
             FROM BUDGET_SCAN s JOIN PERSONNE_IDENTIFIEE p ON p.ID = s.PERSONNE_ID
             WHERE $conditions ORDER BY s.DATE_DOCUMENT DESC, s.ID DESC",
            $types,
            $parametres
        );
        $articles = Database::lignes(
            "SELECT a.SCAN_ID, a.NOM, a.MONTANT, a.MONNAIE
             FROM BUDGET_SCAN_ARTICLE a JOIN BUDGET_SCAN s ON s.ID = a.SCAN_ID
             WHERE $conditions ORDER BY a.ID",
            $types,
            $parametres
        );

        $parScan = [];
        foreach ($articles as $article) {
            $parScan[$article['SCAN_ID']][] = $article;
        }

        $parPersonne = [];
        $total = [];
        foreach ($depenses as &$depense) {
            $depense['ARTICLES'] = $parScan[$depense['ID']] ?? [];
            $depense['TOTAUX'] = [];
            $parPersonne[$depense['PERSONNE_ID']] ??= ['PSEUDONYME' => $depense['PSEUDONYME'], 'TOTAUX' => []];
            foreach ($depense['ARTICLES'] as $article) {
                $centimes = Saisie::centimes($article['MONTANT']);
                $monnaie = $article['MONNAIE'];
                $depense['TOTAUX'][$monnaie] = ($depense['TOTAUX'][$monnaie] ?? 0) + $centimes;
                $parPersonne[$depense['PERSONNE_ID']]['TOTAUX'][$monnaie] = ($parPersonne[$depense['PERSONNE_ID']]['TOTAUX'][$monnaie] ?? 0) + $centimes;
                $total[$monnaie] = ($total[$monnaie] ?? 0) + $centimes;
            }
            ksort($depense['TOTAUX']);
        }
        unset($depense);

        foreach ($parPersonne as &$personne) {
            ksort($personne['TOTAUX']);
        }
        unset($personne);
        uasort($parPersonne, static fn (array $a, array $b): int => strcasecmp($a['PSEUDONYME'], $b['PSEUDONYME']));
        ksort($total);

        return ['depenses' => $depenses, 'par_personne' => $parPersonne, 'total' => $total];
    }

    /**
     * La dépense active de cet auteur, dans un foyer dont il est encore membre — ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function depenseDe(int $scanId, int $auteur): ?array
    {
        return Database::lignes(
            "SELECT s.ID, s.FOYER_ID, s.DATE_DOCUMENT, s.VENDEUR FROM BUDGET_SCAN s
             JOIN FOYER_PERSONNE fp ON fp.FOYER_ID = s.FOYER_ID AND fp.PERSONNE_ID = s.PERSONNE_ID
             WHERE s.ID = ? AND s.PERSONNE_ID = ? AND s.STATUT = 'ACTIF'",
            'ii',
            [$scanId, $auteur]
        )[0] ?? null;
    }

    /**
     * Supprime une dépense : EFFACÉE avec ses articles si elle a moins de
     * {@see self::MINUTES_EFFACEMENT} minutes (horloge de la base), sinon marquée DELETED.
     *
     * Rend vrai si elle a été effacée.
     */
    public static function supprimer(int $scanId, int $auteur): bool
    {
        $stmt = Database::executer(
            'DELETE FROM BUDGET_SCAN WHERE ID = ? AND CREATED_WHEN >= NOW() - INTERVAL ' . self::MINUTES_EFFACEMENT . ' MINUTE',
            'i',
            [$scanId]
        );
        $efface = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$efface) {
            Database::executer(
                "UPDATE BUDGET_SCAN SET STATUT = 'DELETED', LAST_MODIFIED_BY = ? WHERE ID = ?",
                'ii',
                [$auteur, $scanId]
            )->close();
        }

        return $efface;
    }
}
