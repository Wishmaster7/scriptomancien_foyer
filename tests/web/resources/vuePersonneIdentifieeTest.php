<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestBase.php';

/**
 * La vue PERSONNE_IDENTIFIEE, confrontée à la structure RÉELLE du schéma de test.
 *
 * Ce test ne couvre aucune ligne de web/ : il garde trois invariants que rien d'autre ne garde.
 *
 *  1. ⚠ LE « p.* » DE LA VUE EST FIGÉ À SA CRÉATION. Une colonne ajoutée à PERSONNE par une
 *     migration qui oublierait de rejouer « CREATE OR REPLACE VIEW » serait invisible par la vue,
 *     et la première requête qui la lit répondrait « Unknown column » — en production. Ce test
 *     transforme cet oubli en échec de suite, en nommant la colonne.
 *  2. La vue expose l'identité partagée, puisque c'est sa raison d'être.
 *  3. Elle n'expose AUCUN SECRET : un code à usage unique ne se lit que par le composant.
 */
class VuePersonneIdentifieeTest extends TestBase
{
    /** @return array<int, string> */
    private function colonnesDe(string $table): array
    {
        $colonnes = [];
        $resultat = self::$db->query('SHOW COLUMNS FROM ' . $table);
        while ($ligne = $resultat->fetch_assoc()) {
            $colonnes[] = (string) $ligne['Field'];
        }

        return $colonnes;
    }

    public function testToutesLesColonnesDePersonneSontExposeesParLaVue(): void
    {
        $manquantes = array_diff($this->colonnesDe('PERSONNE'), $this->colonnesDe('PERSONNE_IDENTIFIEE'));

        self::assertSame(
            [],
            array_values($manquantes),
            'Colonnes de PERSONNE absentes de la vue : rejouez « CREATE OR REPLACE VIEW PERSONNE_IDENTIFIEE » '
            . 'dans la migration qui les ajoute.'
        );
    }

    public function testLaVueExposeLidentitePartagee(): void
    {
        $colonnes = $this->colonnesDe('PERSONNE_IDENTIFIEE');

        foreach (['EMAIL', 'EMAIL_VALID', 'EMAIL_NEW_TEMP', 'PSEUDONYME', 'NOM', 'PRENOM', 'IS_ADMIN', 'IS_BLOQUE'] as $colonne) {
            self::assertContains($colonne, $colonnes);
        }
    }

    public function testLaVueNexposeAucunCodeAUsageUnique(): void
    {
        $colonnes = $this->colonnesDe('PERSONNE_IDENTIFIEE');

        self::assertNotContains('CODE_AUTH', $colonnes);
        self::assertNotContains('CODE_AUTH_VALID', $colonnes);
        self::assertNotContains('EMAIL_NEW_VALID_CODE', $colonnes);
    }

    /**
     * JOINTURE INTERNE : une identité de l'annuaire sans ligne sur ce site n'apparaît pas dans la
     * vue. C'est ce qui fait qu'une personne inconnue ici n'est lue par aucun écran.
     */
    public function testUneIdentiteSansLigneLocaleNapparaitPasDansLaVue(): void
    {
        $orpheline = $this->creerIdentite('orpheline@example.test', 'Orpheline');

        $ids = array_column(
            self::$db->query('SELECT ID FROM PERSONNE_IDENTIFIEE')->fetch_all(MYSQLI_ASSOC),
            'ID'
        );

        self::assertNotContains((string) $orpheline, $ids);
        self::assertContains((string) self::$membreId, $ids);
    }

    /** La clé étrangère interdit une ligne locale sans identité : la vue n'a jamais de trou. */
    public function testUneLigneLocaleSansIdentiteEstRefusee(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->expectException(mysqli_sql_exception::class);

        self::$db->query('INSERT INTO PERSONNE (ID, CREATED_BY, LAST_MODIFIED_BY, IS_ACTIF) VALUES (999999, 999999, 999999, 1)');
    }
}
