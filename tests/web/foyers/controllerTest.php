<?php

declare(strict_types=1);

use Foyer\App\Flash;
use Foyer\App\Journal;

require_once __DIR__ . '/../TestBase.php';

/**
 * « Gestion des foyers » : réservée aux administrateurs, présentée comme « Gestion des conventions »
 * — liste, ajout, et deux onglets transitoires (fiche, édition). Chaque geste est journalisé.
 */
class FoyersControllerTest extends TestBase
{
    /** @param array<string, mixed> $champs */
    private function poster(array $champs): void
    {
        $this->requete(['action' => 'foyers'], $champs + ['csrf_token' => $this->jetonCsrf()], 'POST');
    }

    private function nombreDeFoyers(): int
    {
        return (int) self::$db->query('SELECT COUNT(*) FROM FOYER')->fetch_row()[0];
    }

    /** @return list<int> */
    private function membres(int $foyerId): array
    {
        return array_map('intval', array_column(
            self::$db->query("SELECT PERSONNE_ID FROM FOYER_PERSONNE WHERE FOYER_ID = $foyerId ORDER BY PERSONNE_ID")->fetch_all(),
            0
        ));
    }

    /**
     * Les membres que le formulaire enverrait : ses champs cachés « membres[] ».
     *
     * @return list<int>
     */
    private function membresDuFormulaire(string $html, string $prefixe): array
    {
        $formulaire = (string) strstr((string) strstr($html, 'id="' . $prefixe . 'formulaire"'), '</form>', true);
        preg_match_all('#name="membres\[\]" value="(\d+)"#', $formulaire, $trouves);

        return array_map('intval', $trouves[1]);
    }

    /**
     * Les candidats remis au script de la zone des membres, par identifiant.
     *
     * @return array<int, string>
     */
    private function candidats(string $html, string $prefixe): array
    {
        preg_match('#data-formulaire="' . $prefixe . 'formulaire".*?<script type="application/json" data-role="candidats">(.*?)</script>#s', $html, $trouve);

        return array_column(json_decode($trouve[1], true), 'libelle', 'id');
    }

    /** @return list<string> */
    private function descriptionsDuJournal(): array
    {
        return array_column($this->journal(), 'DESCRIPTION');
    }

    public function testUnMembreNAccedePasAuxFoyersNiNePeutRienEcrire(): void
    {
        $this->connecter(self::$membreId);

        $this->assertStringContainsString('id="page-accueil"', $this->requete(['action' => 'foyers']));

        $this->poster(['action_foyer' => 'creer', 'nom' => 'Pirate']);
        $this->assertSame(0, $this->nombreDeFoyers());
    }

    /** PAR DÉFAUT, LA LISTE : « Détails » et « Modifier » n'existent pas tant qu'aucun foyer n'est désigné. */
    public function testLaListeEstLOngletParDefaut(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId, self::$adminId]);
        $this->creerDepense($maison, self::$membreId, '2026-10-01');
        $this->connecter(self::$adminId);

        $html = $this->requete(['action' => 'foyers']);

        $this->assertStringContainsString('<h2><i class="fas fa-house-user" aria-hidden="true"></i> Gestion des foyers</h2>', $html);
        $this->assertStringContainsString('class="nav-link active" id="tab-liste"', $html);
        $this->assertStringContainsString('<li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-ajouter"', $html);
        $this->assertStringNotContainsString('id="tab-details"', $html);
        $this->assertStringNotContainsString('id="tab-modifier"', $html);
        $this->assertMatchesRegularExpression('#<td>Maison</td>\s*<td class="text-end">2</td>\s*<td class="text-end montant">3.50 CHF</td>#', $html);
        $this->assertStringContainsString('href="/?action=foyers&amp;foyer=' . $maison . '" aria-label="Consulter"', $html);
        $this->assertStringContainsString('href="/?action=foyers&amp;modifier=' . $maison . '" aria-label="Modifier"', $html);
        $this->assertMatchesRegularExpression('#<script src="/resources/js/onglets\.js\?v=\d+"></script>#', $html);
    }

    /**
     * LA DÉPENSE MENSUELLE MOYENNE, par monnaie : sur tous les mois depuis la plus ancienne dépense
     * active jusqu'au mois courant — un mois vide compte pour zéro, une dépense supprimée ne compte pas.
     */
    public function testLaListeAfficheLaDepenseMensuelleMoyenne(): void
    {
        $mois = static fn (int $decalage): string => (string) self::$db->query(
            "SELECT DATE_FORMAT(CURDATE() + INTERVAL $decalage MONTH, '%Y-%m-01')"
        )->fetch_row()[0];
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $this->creerDepense($maison, self::$membreId, $mois(-2), [['Loyer', '90.00'], ['Péage', '30.00', 'EUR']]);
        $this->creerDepense($maison, self::$membreId, $mois(0), [['Pain', '10.00']]);
        $this->creerDepense($maison, self::$membreId, $mois(-1), [['Annulé', '999.00']], statut: 'DELETED');
        $chalet = $this->creerFoyer('Chalet', [self::$membreId]);
        $this->creerDepense($chalet, self::$membreId, $mois(1), [['Réservation', '60.00']]);
        $this->creerFoyer('Vide');
        $this->connecter(self::$adminId);

        $html = $this->requete(['action' => 'foyers']);

        $this->assertStringContainsString('<th class="text-end">Dépenses mensuelles moyennes</th>', $html);
        // 100 CHF sur trois mois (arrondi au centime), 30 EUR sur les mêmes trois mois.
        $this->assertMatchesRegularExpression('#<td>Maison</td>\s*<td class="text-end">1</td>\s*<td class="text-end montant">33.33 CHF<br>10.00 EUR</td>#', $html);
        // Une dépense datée du mois prochain : la période s'étend jusqu'à elle.
        $this->assertMatchesRegularExpression('#<td>Chalet</td>\s*<td class="text-end">1</td>\s*<td class="text-end montant">60.00 CHF</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>Vide</td>\s*<td class="text-end">0</td>\s*<td class="text-end montant">—</td>#', $html);
    }

    public function testSansFoyerLaListeLeDitEtLAjoutPeutEtreDemande(): void
    {
        $this->connecter(self::$adminId);

        $html = $this->requete(['action' => 'foyers', 'onglet' => 'ajouter']);

        $this->assertStringContainsString('data-role="aucun-foyer"', $html);
        $this->assertStringContainsString('class="nav-link active" id="tab-ajouter"', $html);
        $this->assertStringContainsString('class="tab-pane fade show active" id="pane-ajouter"', $html);
        // LA ZONE DES MEMBRES est à part, sous le formulaire, dont elle tient les champs cachés : aucun à l'ajout.
        $this->assertSame([], $this->membresDuFormulaire($html, 'ajouter_'));
        $this->assertStringContainsString('<div class="tab-pane fade show active bloc-formulaire mt-3" data-role="zone-membres"', $html);
        $this->assertLessThan((int) strpos($html, 'data-formulaire="ajouter_formulaire"'), (int) strpos($html, '</form>'));
        // Les candidats de la recherche : admis et non bloqués.
        $this->assertSame([self::$adminId => 'Admine (Alex Martin)', self::$membreId => 'Membrine (Camille Durand)'], $this->candidats($html, 'ajouter_'));
        $this->assertStringContainsString('data-bs-target="#ajouter_fenetre-membres"', $html);
        $this->assertStringContainsString('<div class="modal fade" id="ajouter_fenetre-membres"', $html);
        $this->assertStringContainsString('data-role="recherche-membre"', $html);
        $this->assertMatchesRegularExpression('#<script src="/resources/js/membres-foyer\.js\?v=\d+"></script>#', $html);
        $this->assertStringContainsString('<button type="submit" form="ajouter_formulaire" class="btn btn-primary"><i class="fas fa-plus"></i> Créer le foyer</button>', $html);
        $this->assertStringNotContainsString('Annuler', $html);
        $this->assertStringNotContainsString('modifier_fenetre-membres', $html);
    }

    /** LA FICHE prend la place de l'ajout ; un foyer sans dépense peut y être supprimé. */
    public function testLaFicheDUnFoyer(): void
    {
        $sansNom = $this->creerIdentite('sansnom@example.test', 'Sansnom');
        $this->creerPersonne($sansNom);
        $maison = $this->creerFoyer('Maison', [self::$membreId, $sansNom]);
        $this->connecter(self::$adminId);

        $html = $this->requete(['action' => 'foyers', 'foyer' => (string) $maison]);

        $this->assertStringContainsString('<li class="nav-item d-none" role="presentation">
            <button class="nav-link" id="tab-ajouter"', $html);
        $this->assertStringContainsString('class="nav-link active" id="tab-details" data-onglet-edition="tab-ajouter"', $html);
        $this->assertStringNotContainsString('id="tab-modifier"', $html);
        // LES MEMBRES, DANS UNE SECONDE ZONE sous la fiche.
        $zone = (string) strstr($html, '<div class="tab-pane fade show active card w-100 mt-3" data-role="zone-membres">');
        $this->assertNotSame('', $zone);
        $this->assertStringContainsString('<li class="list-group-item"><span>Membrine (Camille Durand)</span></li>', $zone);
        $this->assertStringContainsString('<li class="list-group-item"><span>Sansnom</span></li>', $zone);
        $this->assertStringContainsString('"libelle":"Membrine (Camille Durand)"', $zone);
        $this->assertStringContainsString('data-role="aucun-membre" hidden>', $zone);
        // LECTURE SEULE : ni formulaire ni fenêtre de recherche.
        $this->assertStringNotContainsString('data-formulaire=', $zone);
        $this->assertStringNotContainsString('libelle-ligne">Membres', $html);
        $this->assertStringContainsString('<em class="text-muted">Jamais</em>', $html);
        $this->assertStringContainsString('id="confirmer-suppression"', $html);
        $this->assertStringContainsString('href="/?action=foyers&amp;modifier=' . $maison . '" class="btn btn-primary"', $html);
    }

    public function testLaFicheDUnFoyerVideOuQuiPorteDesDepenses(): void
    {
        $vide = $this->creerFoyer('Vide');
        $occupe = $this->creerFoyer('Occupé', [self::$membreId]);
        $this->creerDepense($occupe, self::$membreId, '2026-10-01', statut: 'DELETED');
        self::$db->query("UPDATE FOYER SET NOM = 'Occupé !' WHERE ID = $occupe");
        $this->connecter(self::$adminId);

        $this->assertStringContainsString('<p class="mention-discrete mb-0" data-role="aucun-membre">Aucun membre.</p>', $this->requete(['action' => 'foyers', 'foyer' => (string) $vide]));

        $html = $this->requete(['action' => 'foyers', 'foyer' => (string) $occupe]);
        $this->assertStringNotContainsString('id="confirmer-suppression"', $html);
        $this->assertStringContainsString('Ce foyer porte des dépenses : il ne peut pas être supprimé.', $html);
        $this->assertMatchesRegularExpression('#Dernière modification</div>\s*<div[^>]*><p class="mb-0">\d{2}\.\d{2}\.\d{4} à \d{2}:\d{2}</p>#', $html);
    }

    /** L'ÉDITION : les membres actuels sont dans le formulaire, y compris celui dont l'accès a été fermé depuis. */
    public function testLOngletDeModification(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId, self::$bloqueId]);
        $this->connecter(self::$adminId);

        $html = $this->requete(['action' => 'foyers', 'modifier' => (string) $maison, 'foyer' => (string) $maison]);

        $this->assertStringContainsString('class="nav-link active" id="tab-modifier"', $html);
        $this->assertStringNotContainsString('id="tab-details"', $html);
        $this->assertStringContainsString('id="modifier_nom" class="form-control" maxlength="100" value="Maison"', $html);
        $this->assertSame([self::$bloqueId, self::$membreId], $this->membresDuFormulaire($html, 'modifier_'));
        // Le membre bloqué reste nommable : il est ajouté aux candidats, pour que la liste le montre.
        $this->assertSame(
            [self::$adminId => 'Admine (Alex Martin)', self::$membreId => 'Membrine (Camille Durand)', self::$bloqueId => 'Bloquine'],
            $this->candidats($html, 'modifier_')
        );
        $this->assertStringContainsString('<div class="modal fade" id="modifier_fenetre-membres"', $html);
        $this->assertStringContainsString('form="modifier_formulaire" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer', $html);
        $this->assertStringContainsString('<a href="/?action=foyers&amp;foyer=' . $maison . '" class="btn btn-secondary">', $html);
    }

    public function testCreerUnFoyerAvecSesMembres(): void
    {
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'creer', 'nom' => "  Chalet \t d'été  ", 'membres' => [(string) self::$membreId, (string) self::$membreId, 'x', ['tableau']]]);

        $foyerId = (int) self::$db->query('SELECT ID FROM FOYER')->fetch_row()[0];
        $this->assertSame('/?action=foyers&foyer=' . $foyerId, $this->redirection());
        $this->assertSame("Le foyer « Chalet d'été » est créé.", Flash::prendre('succes'));
        $this->assertSame([self::$membreId], $this->membres($foyerId));
        $this->assertSame(['Foyer créé', 'Membre ajouté au foyer'], $this->descriptionsDuJournal());
        $entree = $this->journal()[1];
        $this->assertSame(Journal::TYPE_MODIFICATION, $entree['TYPE']);
        $this->assertSame(self::$membreId, (int) $entree['PERSONNE_ID']);
        $this->assertSame("Foyer : Chalet d'été", $entree['INFORMATIONS']);
        // La saisie réussie ne ressort pas au prochain affichage.
        $this->assertStringContainsString('id="ajouter_nom" class="form-control" maxlength="100" value=""', $this->requete(['action' => 'foyers']));
    }

    /** UN REFUS ROUVRE L'AJOUT sur la saisie postée, cases comprises. */
    public function testUnAjoutRefuseRendLaSaisie(): void
    {
        $this->creerFoyer('Maison');
        $this->connecter(self::$adminId);

        foreach (['   ' => 'Le nom du foyer est obligatoire, et compte 100 caractères au plus.', str_repeat('é', 101) => 'Le nom du foyer est obligatoire, et compte 100 caractères au plus.', 'MAISON' => 'Un foyer porte déjà ce nom.'] as $nom => $message) {
            $this->poster(['action_foyer' => 'creer', 'nom' => (string) $nom, 'membres' => [(string) self::$membreId]]);

            $this->assertSame('/?action=foyers&onglet=ajouter', $this->redirection());
            $this->assertSame($message, Flash::prendre('erreur'));
        }

        $this->poster(['action_foyer' => 'creer', 'nom' => 'Chalet', 'membres' => [(string) self::$bloqueId]]);
        $this->assertSame('Une personne choisie ne peut pas être membre de ce foyer.', Flash::prendre('erreur'));
        $this->assertSame(1, $this->nombreDeFoyers());

        $html = $this->requete(['action' => 'foyers', 'onglet' => 'ajouter']);
        $this->assertStringContainsString('id="ajouter_nom" class="form-control" maxlength="100" value="Chalet"', $html);
        $this->assertSame([self::$bloqueId], $this->membresDuFormulaire($html, 'ajouter_'));
        $this->poster(['action_foyer' => 'creer', 'nom' => 'Chalet', 'membres' => 'pas un tableau']);
        $html = $this->requete(['action' => 'foyers', 'onglet' => 'ajouter']);
        $this->assertSame([], $this->membresDuFormulaire($html, 'ajouter_'));
    }

    /** MODIFIER : renommer, ajouter et retirer des membres — chaque changement, et lui seul, est journalisé. */
    public function testModifierUnFoyer(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId, self::$bloqueId]);
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'modifier', 'foyer_id' => (string) $maison, 'nom' => 'Villa', 'membres' => [(string) self::$bloqueId, (string) self::$adminId]]);

        $this->assertSame('/?action=foyers&foyer=' . $maison, $this->redirection());
        $this->assertSame('Le foyer « Villa » est enregistré.', Flash::prendre('succes'));
        $this->assertSame('Villa', self::$db->query("SELECT NOM FROM FOYER WHERE ID = $maison")->fetch_row()[0]);
        $this->assertSame([self::$adminId, self::$bloqueId], $this->membres($maison));
        $this->assertSame(['Foyer renommé', 'Membre ajouté au foyer', 'Membre retiré du foyer'], $this->descriptionsDuJournal());
        $this->assertSame('Nom : Maison → Villa', $this->journal()[0]['INFORMATIONS']);

        // Rien de changé : rien de journalisé.
        $this->poster(['action_foyer' => 'modifier', 'foyer_id' => (string) $maison, 'nom' => 'Villa', 'membres' => [(string) self::$bloqueId, (string) self::$adminId]]);
        $this->assertCount(3, $this->journal());
    }

    public function testUneModificationRefuseeRendLaSaisie(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $this->creerFoyer('Villa');
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'modifier', 'foyer_id' => '0', 'nom' => 'Autre']);
        $this->assertSame('/?action=foyers', $this->redirection());
        $this->assertSame("Ce foyer n'existe pas.", Flash::prendre('erreur'));

        $this->poster(['action_foyer' => 'modifier', 'foyer_id' => (string) $maison, 'nom' => 'Chalet', 'membres' => [(string) self::$bloqueId]]);
        $this->assertSame('/?action=foyers&modifier=' . $maison, $this->redirection());
        $this->assertSame('Une personne choisie ne peut pas être membre de ce foyer.', Flash::prendre('erreur'));

        $this->poster(['action_foyer' => 'modifier', 'foyer_id' => (string) $maison, 'nom' => 'villa', 'membres' => []]);
        $this->assertSame('Un foyer porte déjà ce nom.', Flash::prendre('erreur'));

        $html = $this->requete(['action' => 'foyers', 'modifier' => (string) $maison]);
        $this->assertStringContainsString('id="modifier_nom" class="form-control" maxlength="100" value="villa"', $html);
        $this->assertSame([], $this->membresDuFormulaire($html, 'modifier_'));
        $this->assertSame('Maison', self::$db->query("SELECT NOM FROM FOYER WHERE ID = $maison")->fetch_row()[0]);
        $this->assertSame([], $this->journal());
    }

    public function testSupprimerUnFoyerSansDepenseRetireAussiSesMembres(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'supprimer', 'foyer_id' => (string) $maison]);

        $this->assertSame('/?action=foyers', $this->redirection());
        $this->assertSame('Le foyer « Maison » est supprimé.', Flash::prendre('succes'));
        $this->assertSame(0, $this->nombreDeFoyers());
        $this->assertSame([], $this->membres($maison));
        $this->assertSame(Journal::TYPE_SUPPRESSION, $this->journal()[0]['TYPE']);
    }

    /** Une dépense SUPPRIMÉE compte aussi : elle est conservée, et reste attachée à son foyer. */
    public function testUnFoyerQuiPorteDesDepensesNeSeSupprimePas(): void
    {
        $maison = $this->creerFoyer('Maison', [self::$membreId]);
        $this->creerDepense($maison, self::$membreId, '2026-10-01', statut: 'DELETED');
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'supprimer', 'foyer_id' => (string) $maison]);
        $this->assertSame('/?action=foyers&foyer=' . $maison, $this->redirection());
        $this->assertSame('Ce foyer porte des dépenses : il ne peut pas être supprimé.', Flash::prendre('erreur'));

        $this->poster(['action_foyer' => 'supprimer', 'foyer_id' => '0']);
        $this->assertSame("Ce foyer n'existe pas.", Flash::prendre('erreur'));
        $this->assertSame(1, $this->nombreDeFoyers());
    }

    public function testUneActionInconnueRameneALaListe(): void
    {
        $this->connecter(self::$adminId);

        $this->poster(['action_foyer' => 'raser']);

        $this->assertSame('/?action=foyers', $this->redirection());
        $this->assertSame('', Flash::prendre('erreur'));
    }

    public function testUnePanneDeLaBaseNeLaisseRienEtRendLaSaisie(): void
    {
        $this->connecter(self::$adminId);

        $this->avecExecuteEnEchec('INSERT INTO FOYER_PERSONNE', function (): void {
            $this->poster(['action_foyer' => 'creer', 'nom' => 'Maison', 'membres' => [(string) self::$membreId]]);
        });

        $this->assertSame('/?action=foyers&onglet=ajouter', $this->redirection());
        $this->assertSame("L'opération a échoué. Réessayez.", Flash::prendre('erreur'));
        $this->assertSame(0, $this->nombreDeFoyers());
        $this->assertSame([], $this->journal());
        $this->assertStringContainsString('value="Maison"', $this->requete(['action' => 'foyers', 'onglet' => 'ajouter']));
    }
}
