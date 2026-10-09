<?php

declare(strict_types=1);

use Foyer\App\Flash;
use Foyer\App\Snippet;

/**
 * Conditions générales d'utilisation, servies à « /cgu ».
 *
 * Page publique : web/.htaccess réécrit « /cgu » vers le point d'entrée (web/index.php), qui la sert
 * d'après le CHEMIN de la requête — jamais d'après un paramètre « action » — et sans exiger de compte.
 *
 * Même contrôleur dans toutes les applications de la plateforme (même classe, même méthode, même
 * corps) : seul le contenu de la page, cgu_template.php, leur est propre.
 *
 * Ici, c'est structurel : la case « J'accepte les conditions générales d'utilisation » de l'étape 2
 * de la connexion pointe sur cette page, et le composant n'en reçoit que le LIEN
 * ({@see \Personnes\Auth\Configuration::$urlConditions}).
 */
class CguController
{
    public function afficher(): void
    {
        $snippet = new Snippet();

        echo $snippet->getHeader("Conditions générales d'utilisation", Flash::prendre('erreur'), Flash::prendre('succes'));
        echo $snippet->getContenu(__DIR__ . '/cgu_template.php');
        echo $snippet->getFooter();
    }
}
