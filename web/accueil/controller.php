<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * PAGE D'ACCUEIL, servie après authentification et à elle seule.
 *
 * Elle n'existe pas pour décorer : c'est la première page qu'une personne voit une fois le module
 * d'authentification passé, donc le seul endroit d'où l'on peut CONSTATER que l'intégration du
 * composant aboutit sur une application, et non sur un écran de connexion qui se rejoue. Le test
 * end-to-end du parcours de connexion s'arrête ici.
 */
class AccueilController
{
    public function afficher(): void
    {
        $snippet = new Snippet();

        echo $snippet->getHeader('Accueil', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/template_accueil.php', []);
        echo $snippet->getFooter();
    }
}
