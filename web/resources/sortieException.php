<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Marque la FIN DE LA REQUÊTE là où le code de production ferait `exit`.
 *
 * Elle n'est levée qu'en mode test ({@see Utils::modeTest()}) : le harnais la rattrape et la
 * requête s'arrête au même endroit qu'en production, sans emporter le processus de test.
 * Aucune logique métier ne la produit ni ne la lit.
 */
class SortieException extends \RuntimeException
{
}
