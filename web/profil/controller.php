<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Ecran;
use Personnes\Auth\Identite;
use Personnes\Auth\Profil;

/**
 * « Mon profil » : ce que la personne connectée peut corriger d'elle-même.
 *
 * L'ÉCRAN ET LES TROIS GESTES SONT DANS LE COMPOSANT ({@see Ecran::profil()},
 * {@see Profil::traiter()}) : ils ne touchent que des colonnes du schéma d'identité, qui ont la
 * même valeur dans toutes les applications. Ce contrôleur est donc ce qui reste quand on a retiré
 * tout ce qui n'est pas propre à ce site — la session, le message flash, la redirection.
 */
class ProfilController
{
    public function traiter(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->traiterPost();
        }

        $identite = (array) Compte::courant();
        $mode = (string) ($_GET['mode'] ?? '');

        // LE VERROU OPTIMISTE DU FORMULAIRE PORTE LA VERSION DE L'IDENTITÉ, et celle-ci est lue
        // PAR LE COMPOSANT : la vue PERSONNE_IDENTIFIEE ne montre pas NUM_VERSION — c'est le
        // compteur de l'annuaire, que seul le composant incrémente et compare.
        $identite['NUM_VERSION'] = (int) (Identite::parId((int) $identite['ID'])['NUM_VERSION'] ?? 0);

        // LA SAISIE REFUSÉE PRIME SUR LA BASE, et la VERSION POSTÉE avec elle : la fiche rendue au
        // composant porte ce que la personne venait de taper et la version qu'elle a postée —
        // périmée comprise. Resoumettre refuse donc encore, et c'est un RECHARGEMENT de page qui
        // rend la base, donc un formulaire enregistrable (cf. MemoireFormulaire). La mémoire n'est
        // lue qu'en ÉDITION : la redirection d'un refus y mène, et ailleurs elle serait consommée
        // pour rien.
        $memoire = $mode === 'modifier' ? MemoireFormulaire::edition('profil', (int) $identite['ID']) : null;
        if ($memoire !== null) {
            foreach (['pseudonyme' => 'PSEUDONYME', 'nom' => 'NOM', 'prenom' => 'PRENOM'] as $champ => $colonne) {
                $identite[$colonne] = (string) ($memoire['champs'][$champ] ?? ($identite[$colonne] ?? ''));
            }
            $identite['NUM_VERSION'] = $memoire['num_version'] ?? ($identite['NUM_VERSION'] ?? 0);
        }

        $snippet = new Snippet();

        echo $snippet->getHeader('Mon profil', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/template_profil.php', [
            'contenu_profil' => Ecran::profil($identite, Utils::jetonCsrf(), $mode),
        ]);
        echo $snippet->getFooter();
    }

    /**
     * Toute soumission se termine par une redirection (Post/Redirect/Get).
     *
     * LE VERDICT VIENT DU COMPOSANT, le message flash et la redirection sont à nous. Une action
     * qu'il ne reconnaît pas rend `null` : cette page n'en porte aucune autre, on retombe alors
     * simplement sur la lecture du profil — une action mal orthographiée ne doit pas ouvrir un
     * écran qu'on n'a pas demandé.
     */
    private function traiterPost(): void
    {
        // L'IDENTITÉ D'AVANT, lue avant que le composant n'écrive : c'est le « avant » du journal.
        $avant = (array) Compte::courant();

        // LA SAISIE DE L'IDENTITÉ EST MÉMORISÉE AVANT TOUT CONTRÔLE, avec la version POSTÉE : le
        // formulaire la retrouve au retour, quel que soit le refus. Les deux gestes de l'adresse
        // email ne la déposent pas — ils ne postent ni pseudonyme ni nom, et leur formulaire ne
        // reprend rien (le code soumis ne se repropose pas).
        if (($_POST['action_form'] ?? '') === Profil::ACTION_IDENTITE) {
            MemoireFormulaire::memoriser('profil', (int) $avant['ID'], (int) ($_POST['num_version'] ?? 0), [
                'pseudonyme' => (string) ($_POST['pseudonyme'] ?? ''),
                'nom' => (string) ($_POST['nom'] ?? ''),
                'prenom' => (string) ($_POST['prenom'] ?? ''),
            ]);
        }

        $resultat = Profil::traiter(Compte::id(), Compte::email(), $_POST);

        if ($resultat !== null) {
            // UNE SAISIE MÉMORISÉE DERRIÈRE UN ENREGISTREMENT RÉUSSI ressortirait à la prochaine
            // ouverture du formulaire, avec sa version périmée : un refus immédiat sur une fiche que
            // personne n'a touchée.
            if ($resultat['success']) {
                MemoireFormulaire::oublier('profil');
            }
            // L'IDENTITÉ MÉMORISÉE POUR LA REQUÊTE EN COURS PORTE ENCORE L'ANCIENNE VALEUR : le
            // composant vient de l'écrire en base, et c'est ce que la page suivante doit lire.
            if (($resultat['identite_modifiee'] ?? false) || ($resultat['email_modifie'] ?? false)) {
                Compte::reinitialiser();
            }
            // PAS DE MODIFICATION, PAS DE TRACE : une identité resoumise à l'identique réussit, mais n'est pas un événement.
            if ($resultat['success'] && (($_POST['action_form'] ?? '') !== Profil::ACTION_IDENTITE || ($resultat['identite_modifiee'] ?? false))) {
                $this->journaliser($avant);
            }
            Flash::poser($resultat['success'] ? 'succes' : 'erreur', $resultat['message']);
        }

        Utils::rediriger('/?action=profil');
    }

    /**
     * Journalise le geste du profil qui vient de réussir.
     *
     * LA TRACE SUIT L'ÉCRITURE, hors de sa transaction : c'est le composant qui écrit l'identité, et il
     * ne sait rien du journal de cette application. Un journal refusé ne défait donc pas un profil déjà
     * enregistré — la personne a fait ce qu'elle voulait faire.
     *
     * @param array<string, mixed> $avant L'identité telle qu'elle était avant le geste.
     */
    private function journaliser(array $avant): void
    {
        $id = (int) $avant['ID'];
        $apres = (array) Compte::courant();
        $action = (string) ($_POST['action_form'] ?? '');

        if ($action === Profil::ACTION_IDENTITE) {
            Journal::ecrire(Journal::TYPE_MODIFICATION, 'Profil modifié', $id, $id, [
                ...Journal::avantApres('Pseudonyme', $avant['PSEUDONYME'], $apres['PSEUDONYME']),
                ...Journal::avantApres('Prénom', $avant['PRENOM'], $apres['PRENOM']),
                ...Journal::avantApres('Nom', $avant['NOM'], $apres['NOM']),
            ]);
        } elseif ($action === Profil::ACTION_DEMANDE_CODE) {
            // Rien n'a encore changé : l'adresse du compte reste l'ancienne tant que le code n'est
            // pas recopié. On trace la DEMANDE, et l'adresse visée.
            Journal::ecrire(
                Journal::TYPE_MODIFICATION,
                "Changement d'adresse email demandé",
                $id,
                $id,
                Journal::info('Adresse visée', (string) ($_POST['nouvel_email'] ?? ''))
            );
        } else {
            Journal::ecrire(
                Journal::TYPE_MODIFICATION,
                'Adresse email modifiée',
                $id,
                $id,
                Journal::avantApres('Email', $avant['EMAIL'], $apres['EMAIL'])
            );
        }
    }
}
