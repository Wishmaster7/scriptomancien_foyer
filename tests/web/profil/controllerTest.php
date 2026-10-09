<?php

declare(strict_types=1);

use Foyer\App\Flash;
use Personnes\Auth\Identite;

require_once __DIR__ . '/../TestBase.php';

/**
 * « Mon profil » : les trois gestes qu'une personne fait sur elle-même.
 */
class ProfilControllerTest extends TestBase
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
            // NUM_VERSION EST UNE COLONNE D'IDENTITÉ : elle vit dans l'annuaire, pas dans la table
            // locale, et c'est le composant qui l'incrémente.
            $post['num_version'] = (string) self::$db->query(
                'SELECT NUM_VERSION FROM ' . self::tableAnnuaire() . ' WHERE ID = ' . self::$membreId
            )->fetch_row()[0];
        }
        $post['csrf_token'] = $this->jetonCsrf();
        $this->requete(['action' => 'profil'], $post, 'POST');
    }

    public function testAfficheLIdentiteDeLaPersonneConnectee(): void
    {
        $html = $this->requete(['action' => 'profil']);

        $this->assertStringContainsString('Membrine', $html);
        $this->assertStringContainsString('membre@example.test', $html);
    }

    /** La lecture ne propose que des boutons : aucun champ ne s'y modifie par mégarde. */
    public function testLaLectureNOuvreAucunFormulaireDIdentite(): void
    {
        $html = $this->requete(['action' => 'profil']);

        $this->assertStringNotContainsString('name="pseudonyme"', $html);
        $this->assertStringContainsString('mode=modifier', $html);
        $this->assertStringContainsString('mode=email', $html);
    }

    public function testLeModeModifierOuvreLeFormulaireDIdentite(): void
    {
        $html = $this->requete(['action' => 'profil', 'mode' => 'modifier']);

        $this->assertStringContainsString('value="Membrine"', $html);
        $this->assertStringContainsString('name="pseudonyme"', $html);
        $this->assertStringContainsString('value="modifier_profil"', $html);
    }

    /** Le drapeau d'administration n'est plus une ligne de la fiche, pas même pour un administrateur. */
    public function testLAccesAdministrateurNEstPasAnnonce(): void
    {
        $this->assertStringNotContainsString('Administrateur (global)', $this->requete(['action' => 'profil']));

        $this->connecter(self::$adminId);
        $html = $this->requete(['action' => 'profil']);
        $this->assertStringNotContainsString('Administrateur (global)', $html);
        $this->assertStringNotContainsString('>Accès<', $html);
    }

    public function testEnregistreLePseudonymeLeNomEtLePrenom(): void
    {
        $this->soumettre([
            'action_form' => 'modifier_profil',
            'pseudonyme' => 'Renommee',
            'nom' => 'Nouveau',
            'prenom' => 'Prenom',
        ]);

        $ligne = Identite::parId(self::$membreId);
        $this->assertSame('Renommee', $ligne['PSEUDONYME']);
        $this->assertSame('Nouveau', $ligne['NOM']);
        $this->assertSame('Profil mis à jour.', Flash::prendre('succes'));
        $this->assertSame('/?action=profil', $this->redirection());
    }

    /** Le formulaire rendu porte la version de la fiche : c'est elle que le serveur recompare à l'envoi. */
    public function testLeFormulaireDIdentitePorteLaVersionDeLaFiche(): void
    {
        self::$db->query('UPDATE ' . self::tableAnnuaire() . ' SET NUM_VERSION = 7 WHERE ID = ' . self::$membreId);
        \Foyer\App\Compte::reinitialiser();

        $html = $this->requete(['action' => 'profil', 'mode' => 'modifier']);

        $this->assertStringContainsString('name="num_version" value="7"', $html);
    }

    /**
     * DEUX ONGLETS SUR « MON PROFIL » : le premier change le nom, le second — ouvert AVANT — le prénom. Sans verrou, le second
     * renvoyait l'ancien nom et le réécrivait. Il est refusé, ne touche à rien, et le message dit de recharger.
     */
    public function testUnSecondOngletPerimeEstRefuseSansEcraserLePremier(): void
    {
        $ouverture = (string) self::$db->query('SELECT NUM_VERSION FROM ' . self::tableAnnuaire() . ' WHERE ID = ' . self::$membreId)->fetch_row()[0];

        $this->soumettre(['action_form' => 'modifier_profil', 'num_version' => $ouverture, 'pseudonyme' => 'Membrine', 'nom' => 'Premier', 'prenom' => '']);
        $this->assertSame('Profil mis à jour.', Flash::prendre('succes'));

        $this->soumettre(['action_form' => 'modifier_profil', 'num_version' => $ouverture, 'pseudonyme' => 'Membrine', 'nom' => 'Durand', 'prenom' => 'Second']);

        $this->assertSame(
            "Quelqu'un d'autre a enregistré cette fiche entretemps ; rechargez la page (avec la touche F5) avant de pouvoir l'enregistrer.",
            Flash::prendre('erreur')
        );
        $ligne = Identite::parId(self::$membreId);
        $this->assertSame('Premier', $ligne['NOM'], 'Le nom écrit par le premier onglet reste.');
        $this->assertNotSame('Second', $ligne['PRENOM'], 'Le second onglet ne touche à rien.');
    }

    /**
     * UN REFUS REND LA SAISIE ET LA VERSION POSTÉE : le formulaire rouvert porte ce qui a été tapé,
     * la version périmée y reste, et un rechargement rend la base.
     */
    public function testUnRefusRendLaSaisieEtLaVersionPostee(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'num_version' => '1', 'pseudonyme' => 'Saisie perdue', 'nom' => 'Tapé', 'prenom' => 'Zoé']);
        $this->assertStringContainsString('entretemps', Flash::prendre('erreur'));

        $html = $this->requete(['action' => 'profil', 'mode' => 'modifier']);

        $this->assertStringContainsString('value="Saisie perdue"', $html);
        $this->assertStringContainsString('value="Tapé"', $html);
        $this->assertStringContainsString('value="Zoé"', $html);
        $this->assertStringContainsString('name="num_version" value="1"', $html);

        $frais = $this->requete(['action' => 'profil', 'mode' => 'modifier']);
        $this->assertStringContainsString('value="Membrine"', $frais);
        $this->assertStringNotContainsString('Saisie perdue', $frais);
    }

    public function testUnSuccesOublieLaSaisieMemorisee(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'pseudonyme' => 'Renommee', 'nom' => '', 'prenom' => '']);
        $this->assertSame('Profil mis à jour.', Flash::prendre('succes'));

        $html = $this->requete(['action' => 'profil', 'mode' => 'modifier']);

        $this->assertStringContainsString('value="Renommee"', $html);
    }

    /** Les gestes de l'adresse email ne déposent aucune saisie d'identité. */
    public function testLesGestesDeLAdresseNeMemorisentPasLIdentite(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'nouvelle@example.test']);

        $this->assertArrayNotHasKey('profil_edition', $_SESSION['formulaires'] ?? []);
    }

    public function testUneSaisieMemoriseeNEstLueQuEnEdition(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'num_version' => '1', 'pseudonyme' => 'Saisie perdue', 'nom' => '', 'prenom' => '']);

        $lecture = $this->requete(['action' => 'profil']);
        $this->assertStringNotContainsString('Saisie perdue', $lecture);
    }

    public function testUnEnvoiSansVersionEstRefuseCommePerime(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'pseudonyme' => 'Renommee', 'num_version' => '']);

        $this->assertStringContainsString('entretemps', Flash::prendre('erreur'));
        $this->assertSame('Membrine', Identite::parId(self::$membreId)['PSEUDONYME']);
    }

    public function testUneVersionIllisibleEstRefusee(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'pseudonyme' => 'Renommee', 'num_version' => 'abc']);

        $this->assertStringContainsString('entretemps', Flash::prendre('erreur'));
        $this->assertSame('Membrine', Identite::parId(self::$membreId)['PSEUDONYME']);
    }

    /** Une identité resoumise à l'identique réussit, le dit, et ne bouge ni la version ni l'auteur. */
    public function testUneIdentiteResoumiseALIdentiqueLeDitSansRienEcrire(): void
    {
        $avant = Identite::parId(self::$membreId);

        $this->soumettre([
            'action_form' => 'modifier_profil',
            'pseudonyme' => (string) $avant['PSEUDONYME'],
            'nom' => (string) $avant['NOM'],
            'prenom' => (string) $avant['PRENOM'],
        ]);

        $this->assertSame("Aucune modification n'a été enregistrée.", Flash::prendre('succes'));
        $apres = Identite::parId(self::$membreId);
        $this->assertSame($avant['NUM_VERSION'], $apres['NUM_VERSION']);
        $this->assertSame($avant['LAST_MODIFIED_WHEN'], $apres['LAST_MODIFIED_WHEN']);
    }

    public function testRefuseUnPseudonymeTropCourt(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'pseudonyme' => 'ab']);

        $this->assertSame('Membrine', Identite::parId(self::$membreId)['PSEUDONYME']);
        $this->assertStringContainsString('pseudonyme', Flash::prendre('erreur'));
    }

    public function testRefuseUnNomTropLong(): void
    {
        $this->soumettre([
            'action_form' => 'modifier_profil',
            'pseudonyme' => 'Membrine',
            'nom' => str_repeat('a', 256),
        ]);

        $this->assertStringContainsString('dépasser', Flash::prendre('erreur'));
    }

    public function testRefuseUnPseudonymeDejaPris(): void
    {
        $this->soumettre(['action_form' => 'modifier_profil', 'pseudonyme' => 'Admine']);

        $this->assertSame('Ce pseudonyme est déjà utilisé.', Flash::prendre('erreur'));
        $this->assertSame('Membrine', Identite::parId(self::$membreId)['PSEUDONYME']);
    }

    public function testDemandeUnCodeEtAfficheLeSecondFormulaire(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'neuve@example.test']);

        $this->assertSame('neuve@example.test', Identite::parId(self::$membreId)['EMAIL_NEW_TEMP']);
        $this->assertCount(1, $this->emailsEnvoyes());

        $html = $this->requete(['action' => 'profil', 'mode' => 'email']);
        $this->assertStringContainsString('personnes-champ-code', $html);
        $this->assertStringContainsString('neuve@example.test', $html);
    }

    public function testValideLeChangementDAdresse(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'neuve@example.test']);
        $code = (string) $this->codeEmailDe(self::$membreId);
        $this->viderMinuteur();

        $this->soumettre([
            'action_form' => 'modifier_email',
            'nouvel_email' => 'neuve@example.test',
            'code_email' => $code,
        ]);

        $this->assertSame('neuve@example.test', Identite::parId(self::$membreId)['EMAIL']);
        $this->assertSame('Adresse email mise à jour.', Flash::prendre('succes'));
    }

    public function testUnCodeFauxLaisseLAdresseInchangee(): void
    {
        $this->soumettre(['action_form' => 'demander_code_email', 'nouvel_email' => 'neuve@example.test']);
        $this->viderMinuteur();

        $this->soumettre([
            'action_form' => 'modifier_email',
            'nouvel_email' => 'neuve@example.test',
            'code_email' => 'ZZZZZZ',
        ]);

        $this->assertSame('membre@example.test', Identite::parId(self::$membreId)['EMAIL']);
        $this->assertNotSame('', Flash::prendre('erreur'));
    }

    public function testUneActionInconnueRamèneSimplementSurLeProfil(): void
    {
        $this->soumettre(['action_form' => 'geste-inconnu']);

        $this->assertSame('/?action=profil', $this->redirection());
    }

    public function testLeSecondFormulaireNApparaitPasSansDemandeEnCours(): void
    {
        // Le champ du code ne paraît QUE si une demande est écrite en base : sans elle, il n'y a
        // aucun code à recopier — et le mode « email » n'ouvre que la saisie de la nouvelle adresse.
        $html = $this->requete(['action' => 'profil', 'mode' => 'email']);

        $this->assertStringContainsString('name="nouvel_email"', $html);
        $this->assertStringNotContainsString('personnes-champ-code', $html);
    }
}
