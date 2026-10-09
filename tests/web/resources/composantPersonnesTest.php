<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * La copie du composant « personnes » n'a pas été retouchée.
 *
 * web/resources/personnes/ est déposé, et remplacé en bloc, par installer-composant.py du projet
 * « personnes » : une correction faite sur place disparaîtrait à la mise à jour suivante, sans
 * bruit, en emportant le comportement qui avait été testé. Sans ce test, la règle « on ne modifie
 * jamais la copie » ne serait qu'une consigne.
 *
 * Il n'a besoin ni du projet « personnes » ni du réseau : CHECKSUM.txt, livré avec la copie, porte
 * l'empreinte SHA-256 de chaque fichier.
 */
class ComposantPersonnesTest extends TestCase
{
    private const RACINE = __DIR__ . '/../../../web/resources/personnes';

    /** @return array<string, string> chemin relatif => empreinte attendue */
    private function releve(): array
    {
        $releve = [];
        foreach (file(self::RACINE . '/CHECKSUM.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            [$empreinte, $relatif] = explode('  ', $ligne, 2);
            $releve[$relatif] = $empreinte;
        }

        return $releve;
    }

    public function testChaqueFichierEmpreintEstIntact(): void
    {
        $releve = $this->releve();
        self::assertNotSame([], $releve, 'CHECKSUM.txt est vide ou absent.');

        foreach ($releve as $relatif => $empreinte) {
            self::assertFileExists(self::RACINE . '/' . $relatif);
            self::assertSame(
                $empreinte,
                hash_file('sha256', self::RACINE . '/' . $relatif),
                "$relatif a été modifié : corrigez-le dans le projet « personnes », puis réinstallez."
            );
        }
    }

    /**
     * LA RÉCIPROQUE : aucun fichier présent qui ne soit empreint. Sans elle, une classe déposée à
     * la main passerait au travers — le chargeur du composant la chargerait, et la mise à jour
     * suivante l'effacerait. CHECKSUM.txt est seul exclu : il ne peut pas contenir sa propre empreinte.
     */
    public function testAucunFichierNaEteAjoute(): void
    {
        $racine = (string) realpath(self::RACINE);
        $presents = [];

        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            $relatif = str_replace('\\', '/', substr((string) $fichier->getPathname(), strlen($racine) + 1));
            if ($relatif !== 'CHECKSUM.txt') {
                $presents[] = $relatif;
            }
        }

        $ajoutes = array_diff($presents, array_keys($this->releve()));

        self::assertSame([], array_values($ajoutes), 'Fichiers absents de CHECKSUM.txt : ils seraient effacés à la prochaine installation.');
    }
}
