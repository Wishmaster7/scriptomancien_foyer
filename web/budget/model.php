<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Lecture des dépenses d'un foyer, et leur suppression.
 *
 * TOUT EST PRÉSENTÉ DANS LA MONNAIE DU FOYER (FOYER.MONNAIE_REF) : un article dans une autre monnaie
 * est converti au cours de change de SA dépense (BUDGET_SCAN.COURS_CHANGE). Les articles restent
 * enregistrés dans la monnaie du reçu ; seule la lecture convertit.
 *
 * LES TOTAUX RESTENT TENUS PAR MONNAIE, en centimes : une dépense étrangère SANS cours — enregistrée
 * avant que les cours existent — ne se convertit pas, et ses montants ne s'additionnent pas à ceux
 * du foyer.
 */
final class BudgetModel
{
    /** Délai pendant lequel une dépense supprimée est EFFACÉE plutôt que marquée supprimée. */
    public const MINUTES_EFFACEMENT = 5;

    /**
     * Les dépenses actives d'un foyer, filtrées, avec leurs articles et les totaux, dans la monnaie du
     * foyer. Chaque article porte, en plus de son montant enregistré, son MONTANT_AFFICHE (centimes)
     * dans sa MONNAIE_AFFICHEE, et CONVERTI s'il a été converti ; chaque dépense, la MONNAIE_RECU que
     * son cours convertit. PAR_PERSONNE regroupe les dépenses de chaque membre, par pseudonyme.
     *
     * @param  array{doc_du: string, doc_au: string, saisie_du: string, saisie_au: string} $filtres Dates « AAAA-MM-JJ », '' = sans borne
     * @return array{depenses: list<array<string, mixed>>, par_personne: array<int, array{PSEUDONYME: string, TOTAUX: array<string, int>, DEPENSES: list<array<string, mixed>>}>}
     */
    public static function depenses(int $foyerId, string $monnaieFoyer, array $filtres): array
    {
        $conditions = "s.FOYER_ID = ? AND s.STATUT = 'ACTIF'";
        $types = 'i';
        $parametres = [$foyerId];
        foreach ([
            'doc_du' => 's.DATE_DOCUMENT >= ?',
            // DATE_DOCUMENT porte l'heure : la borne « au » comprend toute la journée.
            'doc_au' => 's.DATE_DOCUMENT < DATE_ADD(?, INTERVAL 1 DAY)',
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
            "SELECT s.ID, s.PERSONNE_ID, s.DATE_DOCUMENT, s.REFERENCE, s.COURS_CHANGE, s.NUMERO_TVA, s.VENDEUR, s.DESCRIPTION, s.LIEU,
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

        // CHAQUE MEMBRE A SON BLOC, même sans dépense ; un ancien membre a le sien s'il en a dans la période.
        $parPersonne = [];
        foreach (FoyersModel::membres($foyerId) as $membre) {
            $parPersonne[$membre['ID']] = ['PSEUDONYME' => $membre['PSEUDONYME'], 'TOTAUX' => [], 'DEPENSES' => []];
        }
        foreach ($depenses as &$depense) {
            $depense['ARTICLES'] = $parScan[$depense['ID']] ?? [];
            $depense['TOTAUX'] = [];
            $depense['MONNAIE_RECU'] = null;
            $parPersonne[$depense['PERSONNE_ID']] ??= ['PSEUDONYME' => $depense['PSEUDONYME'], 'TOTAUX' => [], 'DEPENSES' => []];
            foreach ($depense['ARTICLES'] as &$article) {
                $centimes = Saisie::centimes($article['MONTANT']);
                $monnaie = $article['MONNAIE'];
                $article['CONVERTI'] = $monnaie !== $monnaieFoyer && $depense['COURS_CHANGE'] !== null;
                if ($article['CONVERTI']) {
                    $depense['MONNAIE_RECU'] = $monnaie;
                    $centimes = (int) round($centimes * (float) $depense['COURS_CHANGE']);
                    $monnaie = $monnaieFoyer;
                }
                $article['MONTANT_AFFICHE'] = $centimes;
                $article['MONNAIE_AFFICHEE'] = $monnaie;
                $depense['TOTAUX'][$monnaie] = ($depense['TOTAUX'][$monnaie] ?? 0) + $centimes;
                $parPersonne[$depense['PERSONNE_ID']]['TOTAUX'][$monnaie] = ($parPersonne[$depense['PERSONNE_ID']]['TOTAUX'][$monnaie] ?? 0) + $centimes;
            }
            unset($article);
            ksort($depense['TOTAUX']);
        }
        unset($depense);
        // Dans l'ordre de la requête : la date du document décroissante.
        foreach ($depenses as $depense) {
            $parPersonne[$depense['PERSONNE_ID']]['DEPENSES'][] = $depense;
        }

        foreach ($parPersonne as &$personne) {
            ksort($personne['TOTAUX']);
        }
        unset($personne);
        uasort($parPersonne, static fn (array $a, array $b): int => strcasecmp($a['PSEUDONYME'], $b['PSEUDONYME']));

        return ['depenses' => $depenses, 'par_personne' => $parPersonne];
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
