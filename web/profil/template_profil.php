<?php
declare(strict_types=1);

/**
 * « Mon profil » — la page de l'annuaire.
 *
 * ELLE NE PORTE QUE LA COLONNE : tout le contenu vient du composant ({@see Ecran::profil()}), qui
 * sert le même écran à toutes les applications de la plateforme. Une application qui a des champs
 * à elle — des allergies, un solde, des rôles — les affiche AUTOUR, dans ce gabarit ; l'annuaire,
 * lui, n'en a aucun : son schéma EST le schéma d'identité.
 *
 * Variables attendues depuis le scope incluant :
 * @var string $contenu_profil HTML rendu par le composant
 */
?>
<div class="container mt-4">
    <?php echo $contenu_profil; ?>
</div>
