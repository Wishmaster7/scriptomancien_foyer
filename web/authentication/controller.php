<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Authentification;

/**
 * Écran de connexion : il ne fait qu'aiguiller vers le composant et rendre ce qu'il produit.
 *
 * C'EST TOUT CE QU'UNE APPLICATION INTÉGRATRICE A À ÉCRIRE, et c'est délibérément peu : le
 * flux, les messages, les gardes et l'affichage appartiennent au module. Ce contrôleur ne
 * décide que ce que le module ne peut pas savoir — où rediriger après un succès, et quel
 * habillage entoure la carte.
 */
class AuthentificationController
{
    /** Traite la soumission (Post/Redirect/Get) puis affiche l'étape courante. */
    public function traiter(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->traiterPost();
        }

        $this->afficher();
    }

    /**
     * Ferme la session et repose la personne devant la porte.
     *
     * La session est DÉTRUITE, pas seulement vidée de ses clés : elle ne porte plus rien
     * d'utile, et lui laisser un identifiant reviendrait à garder ouverte une porte qu'on
     * vient de fermer.
     */
    public static function deconnecter(): never
    {
        // JOURNALISÉE AVANT de fermer la session : après, plus personne n'est là pour la signer. Une
        // soumission sans session ouverte ne laisse aucune trace — personne ne s'est déconnecté.
        if (Compte::estConnecte()) {
            Journal::ecrire(Journal::TYPE_DECONNEXION, 'Déconnexion utilisateur', Compte::id(), Compte::id());
        }

        Authentification::oublierSession();
        Compte::reinitialiser();
        session_unset();
        session_destroy();

        // LE COOKIE DE SESSION EST EFFACÉ CÔTÉ NAVIGATEUR : dans l'application installée, il porte
        // une échéance d'un an ({@see Utils::demarrerSession()}) que `session_destroy()` ne retire
        // pas elle-même — sans cela, il resterait présent, vide de sens, jusqu'à sa propre expiration.
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => Utils::maintenant() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        Utils::rediriger('/');
    }

    /** Toute soumission se termine par une redirection : un rafraîchissement ne rejoue rien. */
    private function traiterPost(): void
    {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'annuler') {
            Authentification::annuler();
            Utils::rediriger('/');
        }

        if ($action === 'email') {
            $resultat = Authentification::demanderCode((string) ($_POST['email'] ?? ''));
            Flash::poser($resultat['success'] ? 'succes' : 'erreur', $resultat['message']);
            Utils::rediriger('/');
        }

        if ($action === 'code') {
            // Une case non cochée n'est pas postée : son ABSENCE est le refus, et c'est le
            // composant qui l'oppose — le navigateur l'a déjà refusée, une soumission forgée non.
            $resultat = Authentification::verifierCode(
                (string) ($_POST['code'] ?? ''),
                isset($_POST['accept_conditions'])
            );
            // UNE CONNEXION RÉUSSIE MÈNE À L'ACCUEIL, et non au profil : la racine nue, qui est une
            // page et non une redirection. C'est là qu'on CONSTATE que la connexion a abouti.
            if ($resultat['success']) {
                Compte::reinitialiser();
                Utils::rediriger('/');
            }
            Flash::poser('erreur', $resultat['message']);
        }

        Utils::rediriger('/');
    }

    private function afficher(): void
    {
        $etape = Authentification::etapeCourante() === 2 ? 2 : 1;
        $snippet = new Snippet();

        echo $snippet->getHeader('Connexion', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getCarteConnexion($etape, Utils::jetonCsrf(), '/');
        echo $snippet->getFooter();
    }
}
