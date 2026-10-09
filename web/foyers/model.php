<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * LES FOYERS ET LEURS MEMBRES : lus par les écrans Budget et Scanner, écrits par l'écran Foyers.
 *
 * Toute identité est lue par la vue PERSONNE_IDENTIFIEE, jamais par une jointure sur l'annuaire.
 */
final class FoyersModel
{
    /** Longueur maximale d'un nom de foyer (FOYER.NOM). */
    public const NOM_LONGUEUR_MAX = 100;

    /**
     * Les foyers dont la personne est membre, par nom.
     *
     * @return list<array{ID: int, NOM: string}>
     */
    public static function foyersDe(int $personneId): array
    {
        return Database::lignes(
            'SELECT f.ID, f.NOM FROM FOYER f
             JOIN FOYER_PERSONNE fp ON fp.FOYER_ID = f.ID
             WHERE fp.PERSONNE_ID = ? ORDER BY f.NOM, f.ID',
            'i',
            [$personneId]
        );
    }

    public static function estMembre(int $foyerId, int $personneId): bool
    {
        return Database::lignes(
            'SELECT 1 FROM FOYER_PERSONNE WHERE FOYER_ID = ? AND PERSONNE_ID = ?',
            'ii',
            [$foyerId, $personneId]
        ) !== [];
    }

    /**
     * Tous les foyers, avec le nombre de leurs membres et leur DÉPENSE MENSUELLE MOYENNE, en centimes
     * et par monnaie — des CHF et des EUR ne s'additionnent jamais.
     *
     * LA MOYENNE porte sur les dépenses actives, et sur tous les mois depuis celui de la plus
     * ancienne (date du document) jusqu'au mois courant — ou jusqu'à la dernière dépense, si elle
     * est datée plus tard. Un mois sans dépense compte pour zéro. Le mois courant est celui de la base.
     *
     * @return list<array{ID: int, NOM: string, NB_MEMBRES: int, MOYENNES: array<string, int>}>
     */
    public static function tous(): array
    {
        $foyers = Database::lignes(
            'SELECT f.ID, f.NOM, (SELECT COUNT(*) FROM FOYER_PERSONNE fp WHERE fp.FOYER_ID = f.ID) AS NB_MEMBRES
             FROM FOYER f ORDER BY f.NOM, f.ID'
        );
        $mois = array_column(Database::lignes(
            "SELECT FOYER_ID,
                    PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM GREATEST(CURDATE(), MAX(DATE_DOCUMENT))),
                                EXTRACT(YEAR_MONTH FROM MIN(DATE_DOCUMENT))) + 1 AS MOIS
             FROM BUDGET_SCAN WHERE STATUT = 'ACTIF' GROUP BY FOYER_ID"
        ), 'MOIS', 'FOYER_ID');
        $totaux = Database::lignes(
            "SELECT s.FOYER_ID, a.MONNAIE, SUM(a.MONTANT) AS TOTAL
             FROM BUDGET_SCAN_ARTICLE a JOIN BUDGET_SCAN s ON s.ID = a.SCAN_ID
             WHERE s.STATUT = 'ACTIF' GROUP BY s.FOYER_ID, a.MONNAIE ORDER BY a.MONNAIE"
        );

        $moyennes = [];
        foreach ($totaux as $total) {
            $moyennes[$total['FOYER_ID']][$total['MONNAIE']] = (int) round(
                Saisie::centimes((string) $total['TOTAL']) / (int) $mois[$total['FOYER_ID']]
            );
        }
        foreach ($foyers as &$foyer) {
            $foyer['MOYENNES'] = $moyennes[$foyer['ID']] ?? [];
        }
        unset($foyer);

        return $foyers;
    }

    /**
     * Les personnes qui peuvent devenir membres : admises sur ce site, et non bloquées.
     *
     * @return list<array<string, mixed>>
     */
    public static function personnesAdmises(): array
    {
        return Database::lignes(
            'SELECT ID, PSEUDONYME, PRENOM, NOM FROM PERSONNE_IDENTIFIEE
             WHERE IS_ACTIF = 1 AND IS_BLOQUE = 0 ORDER BY PSEUDONYME, ID'
        );
    }

    /** @return array{ID: int, NOM: string, CREATED_WHEN: string, LAST_MODIFIED_WHEN: ?string}|null */
    public static function parId(int $foyerId): ?array
    {
        return Database::lignes(
            'SELECT ID, NOM, CREATED_WHEN, LAST_MODIFIED_WHEN FROM FOYER WHERE ID = ?',
            'i',
            [$foyerId]
        )[0] ?? null;
    }

    /**
     * Les membres d'un foyer, admis ou non : un membre dont l'accès a été fermé depuis reste membre.
     *
     * @return list<array<string, mixed>>
     */
    public static function membres(int $foyerId): array
    {
        return Database::lignes(
            'SELECT p.ID, p.PSEUDONYME, p.PRENOM, p.NOM FROM FOYER_PERSONNE fp
             JOIN PERSONNE_IDENTIFIEE p ON p.ID = fp.PERSONNE_ID
             WHERE fp.FOYER_ID = ? ORDER BY p.PSEUDONYME, p.ID',
            'i',
            [$foyerId]
        );
    }

    /** Le nom est-il déjà porté par un autre foyer ? La comparaison suit la collation : sans casse ni accent. */
    public static function nomPris(string $nom, int $saufId = 0): bool
    {
        return Database::lignes('SELECT 1 FROM FOYER WHERE NOM = ? AND ID <> ?', 'si', [$nom, $saufId]) !== [];
    }

    public static function nombreDepenses(int $foyerId): int
    {
        return (int) Database::lignes('SELECT COUNT(*) AS N FROM BUDGET_SCAN WHERE FOYER_ID = ?', 'i', [$foyerId])[0]['N'];
    }

    public static function creer(string $nom, int $auteur): int
    {
        $stmt = Database::executer(
            'INSERT INTO FOYER (NOM, CREATED_BY, LAST_MODIFIED_BY) VALUES (?, ?, ?)',
            'sii',
            [$nom, $auteur, $auteur]
        );
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public static function renommer(int $foyerId, string $nom, int $auteur): void
    {
        Database::executer(
            'UPDATE FOYER SET NOM = ?, LAST_MODIFIED_BY = ? WHERE ID = ?',
            'sii',
            [$nom, $auteur, $foyerId]
        )->close();
    }

    /** Retire le foyer et ses appartenances. L'appelant a vérifié qu'il ne porte aucune dépense. */
    public static function supprimer(int $foyerId): void
    {
        Database::executer('DELETE FROM FOYER WHERE ID = ?', 'i', [$foyerId])->close();
    }

    public static function ajouterMembre(int $foyerId, int $personneId, int $auteur): void
    {
        Database::executer(
            'INSERT INTO FOYER_PERSONNE (FOYER_ID, PERSONNE_ID, CREATED_BY, LAST_MODIFIED_BY) VALUES (?, ?, ?, ?)',
            'iiii',
            [$foyerId, $personneId, $auteur, $auteur]
        )->close();
    }

    public static function retirerMembre(int $foyerId, int $personneId): void
    {
        Database::executer(
            'DELETE FROM FOYER_PERSONNE WHERE FOYER_ID = ? AND PERSONNE_ID = ?',
            'ii',
            [$foyerId, $personneId]
        )->close();
    }
}
