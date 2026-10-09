<?php
declare(strict_types=1);

use Personnes\Auth\Code;
use Personnes\Auth\Ecran;

/**
 * ÉTAPE 2 DE LA CONNEXION : saisie du code reçu par email.
 *
 * Gabarit du composant « personnes », servi à l'identique par toutes les applications
 * intégratrices — voir template_connexion_email.php.
 *
 * Variables attendues depuis le scope incluant :
 * @var string $csrf_token        Jeton CSRF de l'application
 * @var string $action_formulaire URL de soumission : la PAGE COURANTE
 * @var string $email_destinataire Adresse à laquelle le code vient d'être envoyé
 * @var string $url_conditions     Lien vers les conditions générales d'utilisation de L'APPLICATION
 */
?>
<form method="POST" action="<?php echo Ecran::echapper($action_formulaire); ?>">
    <p class="text-muted mb-3">
        Un code a été envoyé à :<br>
        <strong><?php echo Ecran::echapper($email_destinataire); ?></strong>
    </p>
    <div class="mb-3">
        <?php // SIX CARACTÈRES, chiffres ET majuscules (Code::generer()). Le motif borne la
              // SEULE règle qui existe ici — la longueur —, celle-là même que le serveur
              // revérifie (Code::normaliser()) ; un « [0-9]{6} » ferait refuser par le
              // navigateur un code pourtant fidèlement recopié. Pas d'`inputmode` numérique non
              // plus, qui ouvrirait sur mobile un pavé sans lettres. La casse n'est pas imposée
              // à la saisie : `autocapitalize` suggère les majuscules, et c'est le serveur qui
              // ramène la saisie à la forme émise — une saisie minuscule reste donc valable.?>
        <label for="code" class="form-label">Code d'authentification (<?php echo Code::LONGUEUR; ?> caractères) :</label>
        <input type="text" class="form-control form-control-lg text-center personnes-champ-code" id="code" name="code"
               placeholder="••••••" pattern=".{<?php echo Code::LONGUEUR; ?>}"
               minlength="<?php echo Code::LONGUEUR; ?>" maxlength="<?php echo Code::LONGUEUR; ?>"
               autocapitalize="characters" spellcheck="false" autocomplete="one-time-code" required>
    </div>
    <?php // L'ACCEPTATION DES CONDITIONS est demandée ICI, à l'étape du code, et non à celle de
          // l'email : c'est la seule des deux où l'identité est PROUVÉE (le code reçu par
          // email), donc la seule où l'acceptation puisse être attribuée à quelqu'un. À
          // l'étape 1 elle n'aurait engagé que le propriétaire de l'adresse saisie, qui n'est
          // pas forcément celui qui la saisit — et l'étape 1 simule le passage à l'étape 2 pour
          // une adresse inconnue.
          //
          // JAMAIS pré-cochée, et redemandée à chaque connexion : une case déjà cochée à
          // l'arrivée n'est pas un geste, et ne prouve donc rien. `required` la rend bloquante
          // côté navigateur ; le composant rejoue le refus. Le bouton « Annuler » porte
          // formnovalidate : on doit pouvoir renoncer sans accepter.
          //
          // LES CONDITIONS SONT CELLES DE L'APPLICATION, jamais du composant : c'est elle qui
          // fournit le lien, et c'est elle qui décide quoi faire de l'acceptation.?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="accept_conditions" name="accept_conditions" value="1" required>
        <label class="form-check-label personnes-texte-petit" for="accept_conditions">
            J'accepte les
            <a href="<?php echo Ecran::echapper($url_conditions); ?>" target="_blank" rel="noopener">conditions générales d'utilisation</a>
        </label>
    </div>
    <input type="hidden" name="action" value="code">
    <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
    <button type="submit" class="btn btn-primary w-100 mt-2">
        <i class="fas fa-check"></i> Valider le code
    </button>
    <button type="submit" class="btn btn-secondary w-100 mt-2" name="action" value="annuler" formnovalidate>
        <i class="fas fa-times"></i> Annuler
    </button>
    <p class="text-center mt-3 personnes-mention">
        Pas reçu le code ? Vérifiez votre dossier spam.
    </p>
</form>
