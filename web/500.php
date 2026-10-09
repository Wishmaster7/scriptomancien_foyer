<?php

declare(strict_types=1);

use Foyer\App\ErrorPage;

/**
 * PAGE D'ERREUR 500 - Erreur interne du serveur
 */

require_once(__DIR__ . '/resources/errorPage.php');

(new ErrorPage())->render(500);
