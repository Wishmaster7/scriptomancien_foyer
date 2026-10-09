<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Écritures et comptages de l'écran « Scanner un reçu ».
 */
final class BudgetScanModel
{
    /**
     * Les analyses que la personne a fait facturer pendant l'heure écoulée, d'après le journal —
     * horloge de la base, jamais celle de PHP.
     */
    public static function analysesRecentes(int $personneId): int
    {
        return (int) Database::lignes(
            'SELECT COUNT(*) AS N FROM LOGS
             WHERE CREATED_BY = ? AND TYPE = ? AND CREATED_WHEN > NOW() - INTERVAL 1 HOUR',
            'is',
            [$personneId, Journal::TYPE_ANALYSE]
        )[0]['N'];
    }

    /**
     * Enregistre une dépense et ses articles, et rend son identifiant. À appeler DANS une transaction.
     *
     * @param array{date_document: string, numero_tva: string, vendeur: string, lieu: string, description: string} $entete
     * @param list<array{nom: string, montant: string, monnaie: string}>                                           $articles
     */
    public static function enregistrer(int $foyerId, int $auteur, array $entete, array $articles): int
    {
        $vide = static fn (string $valeur): ?string => $valeur === '' ? null : $valeur;

        $stmt = Database::executer(
            'INSERT INTO BUDGET_SCAN
             (FOYER_ID, PERSONNE_ID, DATE_DOCUMENT, NUMERO_TVA, VENDEUR, DESCRIPTION, LIEU, CREATED_BY, LAST_MODIFIED_BY)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iisssssii',
            [
                $foyerId,
                $auteur,
                $entete['date_document'],
                $vide($entete['numero_tva']),
                $vide($entete['vendeur']),
                $vide($entete['description']),
                $vide($entete['lieu']),
                $auteur,
                $auteur,
            ]
        );
        $scanId = (int) $stmt->insert_id;
        $stmt->close();

        foreach ($articles as $article) {
            Database::executer(
                'INSERT INTO BUDGET_SCAN_ARTICLE (SCAN_ID, NOM, MONTANT, MONNAIE, CREATED_BY, LAST_MODIFIED_BY) VALUES (?, ?, ?, ?, ?, ?)',
                'isssii',
                [$scanId, $article['nom'], $article['montant'], $article['monnaie'], $auteur, $auteur]
            )->close();
        }

        return $scanId;
    }
}
