<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Transaction;

/**
 * « Gestion des foyers » : créer, consulter, modifier — nom et membres — et supprimer un foyer.
 * Réservé aux administrateurs de la plateforme : le point d'entrée ne route ici que pour eux.
 *
 * MÊME PRÉSENTATION QUE « Gestion des conventions » du projet convention : la liste, l'ajout, et
 * deux onglets transitoires — « Détails du foyer » (?foyer=…) et « Modifier un foyer »
 * (?modifier=…) — qui n'existent que lorsqu'un foyer est désigné.
 *
 * Il ne touche PAS à l'accès au site (PERSONNE.IS_ACTIF), qui s'ouvre et se ferme depuis
 * l'application « personnes » : seules les personnes admises peuvent devenir membres.
 */
class FoyersController
{
    public const URL = '/?action=foyers';

    /** Clé de la saisie mémorisée après un refus, à l'ajout comme en édition. */
    private const ENTITE = 'foyer';

    public function traiter(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->traiterPost();
        }

        $edition = FoyersModel::parId((int) ($_GET['modifier'] ?? 0));
        $details = $edition === null ? FoyersModel::parId((int) ($_GET['foyer'] ?? 0)) : null;
        $designe = $edition ?? $details;
        if ($designe !== null) {
            $designe['MEMBRES'] = FoyersModel::membres($designe['ID']);
            $designe['NB_DEPENSES'] = FoyersModel::nombreDepenses($designe['ID']);
        }

        $onglet = match (true) {
            $edition !== null => 'modifier',
            $details !== null => 'details',
            ($_GET['onglet'] ?? '') === 'ajouter' => 'ajouter',
            default => 'liste',
        };

        $snippet = new Snippet();

        echo $snippet->getHeader('Gestion des foyers', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/template_foyers.php', [
            'onglet' => $onglet,
            'foyers' => FoyersModel::tous(),
            'personnes' => FoyersModel::personnesAdmises(),
            'details' => $details === null ? null : $designe,
            'edition' => $edition === null ? null : $designe,
            'saisie_ajout' => MemoireFormulaire::ajout(self::ENTITE),
            'saisie_edition' => $edition === null ? null : (MemoireFormulaire::edition(self::ENTITE, $edition['ID'])['champs'] ?? null),
            'csrf_token' => Utils::jetonCsrf(),
        ]);
        echo $snippet->getFooter();
    }

    private function traiterPost(): void
    {
        match ((string) ($_POST['action_foyer'] ?? '')) {
            'creer' => $this->creer(),
            'modifier' => $this->modifier(),
            'supprimer' => $this->supprimer(),
            default => Utils::rediriger(self::URL),
        };
    }

    private function creer(): never
    {
        $saisie = self::saisiePostee();
        MemoireFormulaire::memoriserAjout(self::ENTITE, $saisie);
        $retour = self::URL . '&onglet=ajouter';

        $nom = self::nomValide($saisie['nom'], 0, $retour);
        $admises = array_column(FoyersModel::personnesAdmises(), 'ID');
        if (array_diff($saisie['membres'], $admises) !== []) {
            self::refuser('Une personne choisie ne peut pas être membre de ce foyer.', $retour);
        }

        $foyerId = self::ecrire($retour, static function () use ($nom, $saisie): int {
            $foyerId = FoyersModel::creer($nom, Compte::id());
            Journal::ecrire(Journal::TYPE_CREATION, 'Foyer créé', Compte::id(), null, Journal::info('Foyer', $nom));
            self::ajouterMembres($foyerId, $nom, $saisie['membres']);

            return $foyerId;
        });

        MemoireFormulaire::oublierAjout(self::ENTITE);
        Flash::poser('succes', "Le foyer « $nom » est créé.");
        Utils::rediriger(self::URL . '&foyer=' . $foyerId);
    }

    private function modifier(): never
    {
        $foyer = FoyersModel::parId((int) ($_POST['foyer_id'] ?? 0));
        if ($foyer === null) {
            self::refuser("Ce foyer n'existe pas.", self::URL);
        }

        $saisie = self::saisiePostee();
        MemoireFormulaire::memoriser(self::ENTITE, $foyer['ID'], 0, $saisie);
        $retour = self::URL . '&modifier=' . $foyer['ID'];

        $nom = self::nomValide($saisie['nom'], $foyer['ID'], $retour);
        // UN MEMBRE DONT L'ACCÈS A ÉTÉ FERMÉ DEPUIS peut rester coché : il n'est plus proposé à
        // l'ajout, mais le garder n'est pas l'ajouter.
        $actuels = array_column(FoyersModel::membres($foyer['ID']), 'ID');
        $admises = array_column(FoyersModel::personnesAdmises(), 'ID');
        if (array_diff($saisie['membres'], $admises, $actuels) !== []) {
            self::refuser('Une personne choisie ne peut pas être membre de ce foyer.', $retour);
        }

        self::ecrire($retour, static function () use ($foyer, $nom, $saisie, $actuels): void {
            if ($nom !== $foyer['NOM']) {
                FoyersModel::renommer($foyer['ID'], $nom, Compte::id());
                Journal::ecrire(Journal::TYPE_MODIFICATION, 'Foyer renommé', Compte::id(), null, Journal::avantApres('Nom', $foyer['NOM'], $nom));
            }
            self::ajouterMembres($foyer['ID'], $nom, array_diff($saisie['membres'], $actuels));
            foreach (array_diff($actuels, $saisie['membres']) as $personneId) {
                FoyersModel::retirerMembre($foyer['ID'], $personneId);
                Journal::ecrire(Journal::TYPE_MODIFICATION, 'Membre retiré du foyer', Compte::id(), $personneId, Journal::info('Foyer', $nom));
            }
        });

        MemoireFormulaire::oublier(self::ENTITE);
        Flash::poser('succes', "Le foyer « $nom » est enregistré.");
        Utils::rediriger(self::URL . '&foyer=' . $foyer['ID']);
    }

    private function supprimer(): never
    {
        $foyer = FoyersModel::parId((int) ($_POST['foyer_id'] ?? 0));
        if ($foyer === null) {
            self::refuser("Ce foyer n'existe pas.", self::URL);
        }
        $retour = self::URL . '&foyer=' . $foyer['ID'];
        if (FoyersModel::nombreDepenses($foyer['ID']) > 0) {
            self::refuser('Ce foyer porte des dépenses : il ne peut pas être supprimé.', $retour);
        }

        self::ecrire($retour, static function () use ($foyer): void {
            FoyersModel::supprimer($foyer['ID']);
            Journal::ecrire(Journal::TYPE_SUPPRESSION, 'Foyer supprimé', Compte::id(), null, Journal::info('Foyer', $foyer['NOM']));
        });

        Flash::poser('succes', "Le foyer « {$foyer['NOM']} » est supprimé.");
        Utils::rediriger(self::URL);
    }

    /** @param list<int> $membres */
    private static function ajouterMembres(int $foyerId, string $nom, array $membres): void
    {
        foreach ($membres as $personneId) {
            FoyersModel::ajouterMembre($foyerId, $personneId, Compte::id());
            Journal::ecrire(Journal::TYPE_MODIFICATION, 'Membre ajouté au foyer', Compte::id(), $personneId, Journal::info('Foyer', $nom));
        }
    }

    /**
     * Exécute les écritures dans une transaction ; une panne de la base ramène à $retour, saisie
     * conservée.
     *
     * @template T
     * @param  \Closure(): T $ecritures
     * @return T
     */
    private static function ecrire(string $retour, \Closure $ecritures): mixed
    {
        try {
            return Transaction::executer($ecritures);
        } catch (\RuntimeException) {
            self::refuser("L'opération a échoué. Réessayez.", $retour);
        }
    }

    /** Le nom, s'il est renseigné, assez court et libre ; sinon le refus. */
    private static function nomValide(string $nom, int $foyerId, string $retour): string
    {
        $nom = Saisie::texte($nom, FoyersModel::NOM_LONGUEUR_MAX + 1);
        if ($nom === '' || mb_strlen($nom) > FoyersModel::NOM_LONGUEUR_MAX) {
            self::refuser('Le nom du foyer est obligatoire, et compte ' . FoyersModel::NOM_LONGUEUR_MAX . ' caractères au plus.', $retour);
        }
        if (FoyersModel::nomPris($nom, $foyerId)) {
            self::refuser('Un foyer porte déjà ce nom.', $retour);
        }

        return $nom;
    }

    private static function refuser(string $message, string $retour): never
    {
        Flash::poser('erreur', $message);
        Utils::rediriger($retour);
    }

    /**
     * La saisie postée : le nom brut, et les identifiants cochés, uniques.
     *
     * @return array{nom: string, membres: list<int>}
     */
    private static function saisiePostee(): array
    {
        $membres = is_array($_POST['membres'] ?? null) ? $_POST['membres'] : [];

        return [
            'nom' => is_string($_POST['nom'] ?? null) ? $_POST['nom'] : '',
            'membres' => array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => is_string($id) ? (int) $id : 0, $membres),
                static fn (int $id): bool => $id > 0
            ))),
        ];
    }
}
