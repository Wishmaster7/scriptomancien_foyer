<?php

declare(strict_types=1);

require_once __DIR__ . '/../TestBase.php';

/**
 * « Mon profil » AU JOURNAL : chacun des trois gestes réussis y laisse son entrée, avec ce qui a
 * changé ; un geste refusé n'y laisse rien.
 */
class ProfilJournalisationTest extends TestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connecter(self::$membreId);
    }

    /** @param array<string, string> $post */
    private function soumettre(array $post): void
    {
        // Le formulaire rendu renvoie la version lue à son ouverture : sauf demande contraire (clé `num_version` donnée),
        // le test poste celle de la fiche telle qu'elle est en base.
        if (($post['action_form'] ?? '') === 'modifier_profil' && !array_key_exists('num_version', $post)) {
            $post['num_version'] = (string) self::$db->query('SELECT NUM_VERSION FROM ' . self::tableAnnuaire() . ' WHERE ID = ' . self::$membreId)->fetch_row()[0];
        }
        $post['csrf_token'] = $this->jetonCsrf();
        $this->requete(['action' => 'profil'], $post, 'POST');
    }

    public function testUneIdentiteModifieeEstJournaliseeAvecSesValeursPrecedentes(): void
    {
        $this->soumettre([
            'action_form' => 'modifier_profil',
            'pseudonyme' => 'Renommee',
            'prenom' => 'Camille',
            'nom' => 'Nouveau',
        ]);

        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame('modification', $journal[0]['TYPE']);
        $this->assertSame('Profil modifié', $journal[0]['DESCRIPTION']);
        $this->assertSame(self::$membreId, (int) $journal[0]['CREATED_BY']);
        $this->assertSame(self::$membreId, (int) $journal[0]['PERSONNE_ID']);
        $this->assertSame('Pseudonyme : Membrine → Renommee | Nom : Durand → Nouveau', $journal[0]['INFORMATIONS']);
    }

    /** PAS DE MODIFICATION, PAS DE TRACE : une identité resoumise à l'identique n'est pas un événement. */
    public function testUneIdentiteResoumiseALIdentiqueNeLaisseAucuneTrace(): void
    {
        $this->soumettre([
            'action_form' => 'modifier_profil',
            'pseudonyme' => 'Membrine',
            'prenom' => (string) \Personnes\Auth\Identite::parId(self::$membreId)['PRENOM'],
            'nom' => 'Durand',
        ]);

        $this->assertSame([], $this->journal());
    }

    /** Un formulaire périmé est refusé : rien n'est écrit, rien n'est tracé. */
    public function testUnFormulairePerimeNeLaisseAucuneTrace(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'num_version' => '99', 'pseudonyme' => 'Renommee']);

        $this->assertSame([], $this->journal());
    }

    public function testUneDemandeDeChangementDAdresseEstJournalisee(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'neuve@example.test']);

        $journal = $this->journal();
        $this->assertCount(1, $journal);
        $this->assertSame("Changement d'adresse email demandé", $journal[0]['DESCRIPTION']);
        $this->assertSame('Adresse visée : neuve@example.test', $journal[0]['INFORMATIONS']);
    }

    public function testUnChangementDAdresseValideEstJournalise(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'neuve@example.test']);
        $code = (string) $this->codeEmailDe(self::$membreId);
        $this->viderMinuteur();

        $this->soumettre([
            'action_form' => 'modifier_email',
            'nouvel_email' => 'neuve@example.test',
            'code_email' => $code,
        ]);

        $journal = $this->journal();
        $this->assertCount(2, $journal);
        $this->assertSame('Adresse email modifiée', $journal[1]['DESCRIPTION']);
        $this->assertSame('Email : membre@example.test → neuve@example.test', $journal[1]['INFORMATIONS']);
    }

    public function testUnGesteRefuseOuInconnuNeLaisseAucuneTrace(): void
    {
        $this->soumettre([
            'action_form' => 'modifier_email',
            'nouvel_email' => 'neuve@example.test',
            'code_email' => 'ZZZZZZ',
        ]);
        $this->soumettre(['action_form' => 'geste-inconnu']);

        $this->assertSame([], $this->journal());
    }
}
