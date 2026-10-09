<?php

declare(strict_types=1);

use Foyer\App\MemoireFormulaire;
use PHPUnit\Framework\TestCase;

/**
 * La saisie d'une édition refusée : déposée, rendue UNE fois, jamais à une autre ligne.
 */
class MemoireFormulaireTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testRendLaSaisieEtLaVersionPuisLEfface(): void
    {
        MemoireFormulaire::memoriser('profil', 4, 7, ['nom' => 'Tapé']);

        $this->assertSame(['num_version' => 7, 'champs' => ['nom' => 'Tapé']], MemoireFormulaire::edition('profil', 4));
        $this->assertNull(MemoireFormulaire::edition('profil', 4));
    }

    public function testUneAutreLigneNeLaRecoitPasEtLaJette(): void
    {
        MemoireFormulaire::memoriser('profil', 4, 7, ['nom' => 'Tapé']);

        $this->assertNull(MemoireFormulaire::edition('profil', 5));
        $this->assertNull(MemoireFormulaire::edition('profil', 4));
    }

    public function testUnIdentifiantNulOuAbsentNeRendRien(): void
    {
        MemoireFormulaire::memoriser('profil', 0, 1, []);

        $this->assertNull(MemoireFormulaire::edition('profil', 0));
        $this->assertNull(MemoireFormulaire::edition('profil', 3));
    }

    public function testOublierEfface(): void
    {
        MemoireFormulaire::memoriser('profil', 4, 7, ['nom' => 'Tapé']);
        MemoireFormulaire::oublier('profil');

        $this->assertNull(MemoireFormulaire::edition('profil', 4));
    }

    public function testUneMemoireSansVersionNiChampsRetombeSurLaBase(): void
    {
        $_SESSION['formulaires']['profil_edition'] = ['id' => 4, 'champs' => 'illisible'];

        $this->assertSame(['num_version' => null, 'champs' => []], MemoireFormulaire::edition('profil', 4));
    }

    public function testLaCleDEditionNEstPasCelleDeLAjout(): void
    {
        $_SESSION['formulaires']['profil'] = ['id' => 4, 'champs' => ['nom' => 'ajout']];

        $this->assertNull(MemoireFormulaire::edition('profil', 4));
    }
}
