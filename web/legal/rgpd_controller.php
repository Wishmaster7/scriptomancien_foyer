<?php

declare(strict_types=1);

use Foyer\App\Flash;
use Foyer\App\Snippet;

/**
 * Protection des données, servie à « /rgpd ».
 *
 * Page publique : web/.htaccess réécrit « /rgpd » vers le point d'entrée (web/index.php), qui la sert
 * d'après le CHEMIN de la requête — jamais d'après un paramètre « action » — et sans exiger de compte.
 *
 * Même contrôleur dans toutes les applications de la plateforme (même classe, même méthode, même
 * corps) : seul le contenu de la page, rgpd_template.php, leur est propre.
 */
class RgpdController
{
    public function afficher(): void
    {
        $snippet = new Snippet();

        echo $snippet->getHeader('Protection des données', Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/rgpd_template.php');
        echo $snippet->getFooter();
    }
}
