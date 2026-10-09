<?php

declare(strict_types=1);

use Foyer\App\Utils;

/**
 * Gabarit de contenu des pages d'erreur (403 / 404 / 500).
 *
 * Variables attendues depuis le scope incluant :
 * @var int    $code           Code HTTP affiché en grand.
 * @var string $sous_titre     Libellé court de l'erreur.
 * @var string $message        Phrase explicative.
 * @var string $classe_couleur Classe CSS colorant le grand nombre (cf. .code-erreur-*).
 */
?>
<div class="container mt-4 text-center py-5">
    <div class="code-erreur <?php echo Utils::echapper($classe_couleur); ?>"><?php echo (int) $code; ?></div>
    <h2 class="mt-3"><?php echo Utils::echapper($sous_titre); ?></h2>
    <p class="mention-discrete mb-4"><?php echo Utils::echapper($message); ?></p>
    <a href="/" class="btn btn-primary btn-lg">
        <i class="fas fa-house"></i> Retour à l'accueil
    </a>
</div>
