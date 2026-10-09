<?php

declare(strict_types=1);

use Foyer\App\ErrorPage;

/**
 * PAGE D'ERREUR 404 - Page non trouvée
 */

require_once(__DIR__ . '/resources/errorPage.php');

(new ErrorPage())->render(404);
