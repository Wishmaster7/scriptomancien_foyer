<?php

declare(strict_types=1);

/**
 * Fonctions partagées par les hooks PROPRES à ce projet (aujourd'hui `formater-fichier.php`).
 *
 * Les hooks d'AUTORISATION (shell, écriture de fichiers, retrait du préfixe `cd`) ne sont pas
 * ici : ils sont mutualisés pour tous les projets du WAMP dans `c:\wamp64\www\.claude\hooks\` et
 * lisent la liste unique `c:\wamp64\www\.claude\settings.json`. Cf. `.claude/rules/shell-tooling.md`.
 */

/**
 * Ramène un chemin à une forme comparable : sans guillemets, en minuscules,
 * séparateurs `/`, sans `/` final, et forme MSYS (`/c/…`) convertie en `c:/…`.
 */
function normaliserChemin(string $chemin): string
{
    $chemin = trim($chemin);
    if (strlen($chemin) >= 2 && ($chemin[0] === '"' || $chemin[0] === "'") && $chemin[-1] === $chemin[0]) {
        $chemin = substr($chemin, 1, -1);
    }

    $chemin = strtolower(str_replace('\\', '/', $chemin));
    $chemin = rtrim($chemin, '/');

    if (preg_match('#^/([a-z])(/.*)?$#', $chemin, $m) === 1) {
        $chemin = $m[1] . ':' . ($m[2] ?? '');
    }

    return $chemin;
}
