<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * AMORÇAGE : session, chargement des classes, configuration du composant.
 *
 * Inclus par le point d'entrée unique (web/index.php) et par le harnais de test, qui exercent
 * ainsi exactement le même montage.
 */

// ---------------------------------------------------------------------------
// Classes de l'application. Un autoloader d'une ligne suffit : l'application
// tient en quelques classes, toutes rangées à plat sous resources/ ou dans le
// répertoire de leur fonctionnalité.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/sortieException.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/errorPage.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/snippet.php';
require_once __DIR__ . '/compte.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/memoireFormulaire.php';
require_once __DIR__ . '/saisie.php';
require_once dirname(__DIR__) . '/authentication/model.php';

// Dépendances d'exécution (PHPMailer), EMBARQUÉES sous web/ : seul web/ est déployé.
require_once __DIR__ . '/dependances.php';
Dependances::enregistrer();

// ---------------------------------------------------------------------------
// Composant d'authentification « personnes ». Tout le branchement est dans
// IdentitePartagee, seule frontière entre les deux.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/identite.php';
IdentitePartagee::configurer();

// ---------------------------------------------------------------------------
// Session et gestionnaire global d'exceptions
// ---------------------------------------------------------------------------
Utils::demarrerSession();
Utils::installerGestionnaireDException();
