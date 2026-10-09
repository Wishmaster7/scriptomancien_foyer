<?php

declare(strict_types=1);

use Foyer\App\AccueilController;
use Foyer\App\AuthentificationController;
use Foyer\App\BudgetController;
use Foyer\App\BudgetScanController;
use Foyer\App\Compte;
use Foyer\App\Flash;
use Foyer\App\FoyersController;
use Foyer\App\ProfilController;
use Foyer\App\Utils;

/**
 * POINT D'ENTRÉE UNIQUE de l'application.
 *
 * Toutes les URL sont de la forme « /?action=… ». En l'absence de session authentifiée, la
 * seule chose servie est l'écran de connexion du composant : il n'y a pas d'autre porte.
 *
 * TROIS GARDES, dans cet ordre, et l'ordre compte : le jeton CSRF d'abord (une soumission
 * forgée ne doit pas atteindre le moindre contrôleur), la déconnexion ensuite (elle doit
 * fonctionner même depuis une page dont on n'a plus le droit), puis l'authentification.
 */

require_once __DIR__ . '/resources/bootstrap.php';
require_once __DIR__ . '/authentication/controller.php';

$action = (string) ($_GET['action'] ?? '');
$estPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// GARDE CSRF GLOBALE : aucun contrôleur ne vérifie le jeton lui-même.
if ($estPost && !Utils::jetonCsrfValide($_POST['csrf_token'] ?? null)) {
    Flash::poser('erreur', 'Session expirée. Recommencez votre action.');
    Utils::rediriger('/');
}

if ($estPost && ($_POST['action'] ?? '') === 'deconnexion') {
    AuthentificationController::deconnecter();
}

// LES DEUX TEXTES LÉGAUX SONT LISIBLES SANS COMPTE, et c'est nécessaire : la case à cocher de
// l'étape 2 renvoie aux conditions, avant toute session, et le pied de page propose les deux
// depuis toutes les pages — connexion comprise. Ils sont servis d'après le CHEMIN « /cgu » ou
// « /rgpd », que le serveur réécrit vers ce fichier (cf. web/.htaccess), et jamais d'après une
// action : « /?action=cgu » n'est pas une adresse de ces pages.
$chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($chemin === '/cgu') {
    require_once __DIR__ . '/legal/cgu_controller.php';
    (new CguController())->afficher();

    return;
}

if ($chemin === '/rgpd') {
    require_once __DIR__ . '/legal/rgpd_controller.php';
    (new RgpdController())->afficher();

    return;
}

if (!Compte::estConnecte()) {
    (new AuthentificationController())->traiter();

    return;
}

if ($action === 'profil') {
    require_once __DIR__ . '/profil/controller.php';
    (new ProfilController())->traiter();

    return;
}

if (in_array($action, ['budget', 'budget_scan', 'budget_scan_analyser'], true)) {
    require_once __DIR__ . '/foyers/model.php';
    require_once __DIR__ . '/budget/model.php';
    require_once __DIR__ . '/budget/controller.php';
    require_once __DIR__ . '/budget_scan/analyseur.php';
    require_once __DIR__ . '/budget_scan/model.php';
    require_once __DIR__ . '/budget_scan/controller.php';

    match ($action) {
        'budget' => (new BudgetController())->traiter(),
        'budget_scan' => (new BudgetScanController())->traiter(),
        'budget_scan_analyser' => (new BudgetScanController())->analyser(),
    };

    return;
}

// LES FOYERS NE SE GÈRENT QUE PAR UN ADMINISTRATEUR : pour toute autre personne, l'action n'existe
// pas, et la requête retombe sur l'accueil.
if ($action === 'foyers' && Compte::estAdmin()) {
    require_once __DIR__ . '/foyers/model.php';
    require_once __DIR__ . '/foyers/controller.php';
    (new FoyersController())->traiter();

    return;
}

// L'ACCUEIL EST LE DÉFAUT, et c'est ce qui fait de « / » une page et non une redirection : une
// action inconnue y retombe plutôt que d'ouvrir un écran qu'on n'a pas demandé. C'était le profil
// auparavant — une action mal orthographiée présentait alors un formulaire de modification.
require_once __DIR__ . '/accueil/controller.php';
(new AccueilController())->afficher();
