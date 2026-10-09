<?php

declare(strict_types=1);

use Foyer\App\Journal;

require_once __DIR__ . '/../TestBase.php';

/**
 * LE JOURNAL : l'écriture d'une entrée, dans la table LOGS de cette application ou d'une autre, et
 * la composition de son détail.
 */
class JournalTest extends TestBase
{
    public function testEcritUneEntreeDansLeJournalDeLApplication(): void
    {
        $this->assertTrue(Journal::ecrire(Journal::TYPE_MODIFICATION, 'Personne modifiée', self::$adminId, self::$membreId, ['Nom : A → B', 'Prénom : C → D']));

        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame('modification', $journal[0]['TYPE']);
        $this->assertSame('Personne modifiée', $journal[0]['DESCRIPTION']);
        $this->assertSame(self::$adminId, (int) $journal[0]['CREATED_BY']);
        $this->assertSame(self::$membreId, (int) $journal[0]['PERSONNE_ID']);
        $this->assertSame('Nom : A → B | Prénom : C → D', $journal[0]['INFORMATIONS']);
        $this->assertNotNull($journal[0]['CREATED_WHEN']);
    }

    public function testUneEntreeSansSujetNiDetailLaisseCesColonnesVides(): void
    {
        Journal::ecrire(Journal::TYPE_CONNEXION, 'Connexion utilisateur', self::$membreId);

        $entree = $this->journal()[0];
        $this->assertNull($entree['PERSONNE_ID']);
        $this->assertNull($entree['INFORMATIONS']);
    }

    public function testUneEntreeQuiNeSePreparePasRendFalse(): void
    {
        $this->avecPrepareEnEchec('LOGS', function (): void {
            $this->assertFalse(Journal::ecrire(Journal::TYPE_MODIFICATION, 'Personne modifiée', self::$adminId));
        });
    }

    public function testUneEntreeRefuseeParLaBaseRendFalseSansLever(): void
    {
        // Un auteur qui n'existe pas : la clé étrangère refuse l'écriture.
        $this->assertFalse(Journal::ecrire(Journal::TYPE_MODIFICATION, 'Personne modifiée', 999999));

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->assertFalse(Journal::ecrire(Journal::TYPE_MODIFICATION, 'Personne modifiée', 999999));
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $this->assertSame([], $this->journal());
    }

    public function testAvantApresNeDitQueCeQuiAChange(): void
    {
        $this->assertSame([], Journal::avantApres('Nom', 'Durand', ' Durand '));
        $this->assertSame([], Journal::avantApres('Nom', null, ''));
        $this->assertSame(['Nom : Durand → Martin'], Journal::avantApres('Nom', 'Durand', 'Martin'));
        $this->assertSame(['Nom : (vide) → Martin'], Journal::avantApres('Nom', null, 'Martin'));
        $this->assertSame(['Nom : Durand → (vide)'], Journal::avantApres('Nom', 'Durand', ''));
    }

    public function testInfoTaitUneValeurVide(): void
    {
        $this->assertSame([], Journal::info('Nom', null));
        $this->assertSame(['Nom : Durand'], Journal::info('Nom', ' Durand '));
    }

    public function testOuiNon(): void
    {
        $this->assertSame('Oui', Journal::ouiNon(true));
        $this->assertSame('Non', Journal::ouiNon(false));
    }
}
