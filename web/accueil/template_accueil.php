<?php
declare(strict_types=1);

/**
 * Page d'accueil : ce que la personne connectée peut faire ici, et rien de plus.
 *
 * Aucune variable attendue, et AUCUN BLOC : les écrans de ce site (Budget, Scanner un reçu, Foyers)
 * sont dans la barre de navigation, et « Mon profil » dans le menu « Mon compte » que rend le
 * composant — une carte qui les aurait répétés n'aurait annoncé aucune porte de plus.
 *
 * L'IDENTIFIANT EST CE QUI NOMME CETTE PAGE, et c'est sa seule marque tant qu'elle est vide : une
 * page sans contenu propre ne se distingue d'aucune autre par son texte, et les tests ont besoin
 * d'un repère stable pour dire « l'accueil a bien été servi ». Même rôle que #contenu-page ou
 * #zone-messages dans l'en-tête.
 */
?>
<div class="container mt-4" id="page-accueil">
</div>
