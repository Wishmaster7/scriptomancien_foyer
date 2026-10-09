<?php
declare(strict_types=1);

use Personnes\Auth\Ecran;

/**
 * ÉTAPE 1 DE LA CONNEXION : saisie de l'adresse email.
 *
 * Gabarit du composant « personnes » : il est SERVI À L'IDENTIQUE par toutes les applications
 * qui intègrent le module, et c'est tout son objet — l'écran de connexion doit être le même
 * partout, sans quoi chaque application finirait par en avoir une variante.
 *
 * Il n'utilise que des classes Bootstrap et des classes préfixées « personnes- », définies par
 * la feuille de style du composant (css/connexion.css) : il ne suppose donc rien de la feuille
 * de style de l'application qui l'accueille.
 *
 * Variables attendues depuis le scope incluant :
 * @var string $csrf_token        Jeton CSRF de l'application, inséré dans le formulaire
 * @var string $action_formulaire URL de soumission : la PAGE COURANTE, pour y revenir après le
 *                                traitement
 */
?>
<form method="POST" action="<?php echo Ecran::echapper($action_formulaire); ?>">
    <div class="mb-3">
        <label for="email" class="form-label">Adresse email :</label>
        <input type="email" class="form-control" id="email" name="email" maxlength="255" placeholder="votre.email@example.com" required>
    </div>
    <input type="hidden" name="action" value="email">
    <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
    <button type="submit" class="btn btn-primary w-100 mt-2">
        <i class="fas fa-paper-plane"></i> Envoyer le code
    </button>
    <p class="text-center mt-3 personnes-mention">
        Un code d'authentification vous sera envoyé par email.<br>
        Ce code est valide pendant 1 heure.
    </p>
</form>
