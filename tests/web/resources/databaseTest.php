<?php

declare(strict_types=1);

use Foyer\App\Database;

require_once __DIR__ . '/../TestBase.php';

/**
 * Les deux aides de requête : une requête refusée par la base LÈVE, qu'elle échoue à se préparer ou
 * à s'exécuter.
 */
class DatabaseTest extends TestBase
{
    public function testLignesRendLesLignesTypees(): void
    {
        $this->assertSame(
            [['ID' => self::$membreId, 'PSEUDONYME' => 'Membrine']],
            Database::lignes('SELECT ID, PSEUDONYME FROM PERSONNE_IDENTIFIEE WHERE ID = ?', 'i', [self::$membreId])
        );
        $this->assertSame([['UN' => 1]], Database::lignes('SELECT 1 AS UN'));
    }

    public function testUneRequeteQuiNeSePreparePasLeve(): void
    {
        $this->avecPrepareEnEchec('SELECT 1', function (): void {
            $this->expectExceptionMessage('Requête impossible à préparer.');
            Database::lignes('SELECT 1');
        });
    }

    public function testUneRequeteQuiNeSExecutePasLeve(): void
    {
        $this->avecExecuteEnEchec('SELECT 1', function (): void {
            $this->expectExceptionMessage('Requête refusée par la base.');
            Database::executer('SELECT 1');
        });
    }
}
