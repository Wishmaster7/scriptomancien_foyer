<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Les TROIS emails du module d'authentification : le code de connexion, le code de changement
 * d'adresse, et l'alerte envoyée à l'adresse que le compte vient de quitter.
 *
 * LE COMPOSANT COMPOSE, L'APPLICATION ENVOIE ({@see Configuration::$envoiEmail}). Le partage
 * n'est pas arbitraire : le contenu de ces trois messages appartient au module — le changer,
 * c'est changer le module —, tandis que le transport appartient à l'application, qui possède
 * déjà sa configuration SMTP, son journal des échecs d'envoi et son point de substitution pour
 * les tests. Redemander une seconde configuration SMTP, c'était écrire deux fois la même
 * chose, et deux configurations finissent toujours par diverger — d'autant que la première
 * sert déjà à tous les autres emails de l'application.
 *
 * Chaque email part en DEUX VERSIONS, texte et HTML. Le texte n'est pas un repli négligeable :
 * c'est ce que lit qui a désactivé le HTML, et ce que gardera un client qui dégraderait le
 * gabarit. Il dit donc exactement la même chose, code compris.
 *
 * LE NOM DU SITE EST TOUJOURS ACCOMPAGNÉ DE L'ADRESSE DE L'APPLICATION ({@see Configuration::$urlApplication}) :
 * lien dans le HTML, adresse en clair dans le texte. Écrit seul, « Scriptomancien.com » est
 * transformé en lien par le client de messagerie — vers le site de l'éditeur, et non vers le
 * sous-domaine de l'application où la personne vient de demander son code.
 */
class Courriel
{
    /**
     * Envoie le code de CONNEXION.
     *
     * $contexte nomme l'espace par la porte duquel on se connecte — un espace, un client,
     * un domaine. Il coiffe l'email ({@see Configuration::$enteteEmail}) et entre dans son
     * sujet : c'est ce nom-là qu'on vient de lire à l'écran, et c'est lui qu'on cherchera dans
     * sa boîte. Depuis une page de connexion générique, où l'on n'a encore rien désigné, il
     * reste null et l'email est celui du site.
     */
    public static function envoyerCodeAuth(
        string $destinataire,
        string $pseudonyme,
        string $code,
        ?int $personneId = null,
        ?string $contexte = null
    ): bool {
        $configuration = Configuration::courante();
        $nomSite = $configuration->nomSite;

        $objet = $contexte !== null && trim($contexte) !== ''
            ? 'Connexion à ' . trim($contexte)
            : 'Connexion';
        $sujet = ($configuration->sujetEmail)($objet);

        $corps = "Bonjour $pseudonyme,\n\n";
        $corps .= "Vous avez demandé à vous connecter à la plateforme $nomSite ($configuration->urlApplication)";
        $corps .= ", voici votre code d'authentification :\n\n";
        $corps .= "$code\n\n";
        $corps .= "Ce code est valide pendant 1 heure.\n\n";
        $corps .= "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email — ";
        $corps .= "aucune action n'est requise.";

        $html = self::rendre('courriel_code.html', $contexte, [
            '{{PSEUDONYME}}' => $pseudonyme,
            '{{CODE}}' => $code,
        ]);

        return self::envoyer($destinataire, $sujet, $corps, $html, $personneId);
    }

    /**
     * Envoie, à la NOUVELLE adresse visée, le code confirmant un changement d'adresse email.
     *
     * DEUXIÈME code du module, et il ne prouve pas la même chose que celui de la connexion :
     * celui-ci ne dit pas qui l'on est — la session l'a déjà établi — mais que l'on CONTRÔLE la
     * boîte vers laquelle on veut rediriger son compte. D'où un envoi à l'adresse visée, jamais
     * à l'adresse actuelle.
     *
     * IL NOMME LES DEUX ADRESSES. Un email qui dirait seulement « voici votre code »
     * n'apprendrait pas de QUOI il est le code : arrivant dans une boîte qui n'a peut-être
     * jamais servi au site, il doit dire à lui seul quel compte est en train de bouger, et vers
     * où. C'est aussi ce qui permet à son destinataire de reconnaître — ou de désavouer — la
     * demande qu'on lui attribue. L'ancienne adresse ne fuite vers personne : dans le cas
     * légitime les deux boîtes sont celles d'une même personne, et dans le cas illégitime la
     * nouvelle appartient déjà à qui a fait la demande.
     *
     * Bandeau du SITE, sans contexte : l'adresse email est la clé de connexion du COMPTE, qui
     * n'appartient à aucun espace en particulier.
     */
    public static function envoyerCodeChangementEmail(
        string $destinataire,
        string $pseudonyme,
        string $ancien,
        string $code,
        ?int $personneId = null
    ): bool {
        $configuration = Configuration::courante();
        $sujet = ($configuration->sujetEmail)("Confirmation de votre changement d'adresse email");

        $corps = "Bonjour $pseudonyme,\n\n";
        $corps .= "Une demande de changement d'adresse email a été faite sur votre compte ";
        $corps .= $configuration->nomSite . ' (' . $configuration->urlApplication . ") :\n\n";
        $corps .= "Adresse actuelle : $ancien\n";
        $corps .= "Nouvelle adresse : $destinataire (celle-ci)\n\n";
        $corps .= 'Pour confirmer que cette boîte vous appartient et rendre le changement effectif, ';
        $corps .= "saisissez le code suivant dans la rubrique « Mon profil » :\n\n";
        $corps .= "$code\n\n";
        $corps .= "Ce code est valide pendant 1 heure.\n\n";
        $corps .= "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email — ";
        $corps .= "aucune modification ne sera effectuée sans ce code, et l'adresse de connexion du ";
        $corps .= "compte restera $ancien";

        $html = self::rendre('courriel_changement_email.html', null, [
            '{{PSEUDONYME}}' => $pseudonyme,
            '{{CODE}}' => $code,
            '{{ANCIEN_EMAIL}}' => $ancien,
            '{{NOUVEL_EMAIL}}' => $destinataire,
        ]);

        return self::envoyer($destinataire, $sujet, $corps, $html, $personneId);
    }

    /**
     * Prévient l'ANCIENNE adresse qu'elle vient de cesser d'être celle du compte.
     *
     * C'EST LE SEUL MÉCANISME QUI REND UN DÉTOURNEMENT DÉTECTABLE. L'adresse email est la clé
     * de connexion : qui s'assied devant une session restée ouverte peut rediriger le compte
     * vers sa propre boîte, et sans cet avertissement la personne dépossédée n'apprendrait
     * rien — elle constaterait seulement, un jour, ne plus recevoir son code. Ne pas le
     * retirer.
     */
    public static function envoyerAlerteEmailModifie(
        string $ancien,
        string $pseudonyme,
        string $nouveau,
        ?int $personneId = null
    ): bool {
        $configuration = Configuration::courante();
        $nomSite = $configuration->nomSite;
        $sujet = ($configuration->sujetEmail)('votre adresse email a été modifiée');

        $corps = "Bonjour $pseudonyme,\n\n";
        $corps .= "L'adresse email de votre compte $nomSite ($configuration->urlApplication) vient d'être modifiée :\n\n";
        $corps .= "Ancienne adresse : $ancien\n";
        $corps .= "Nouvelle adresse : $nouveau\n\n";
        $corps .= "Vos connexions se feront désormais avec la nouvelle adresse.\n\n";
        $corps .= "Si vous n'êtes pas à l'origine de ce changement, contactez sans tarder ";
        $corps .= "l'administrateur du site $nomSite : votre compte a pu être détourné.\n\n";
        $corps .= "Ce message est envoyé à l'adresse que le compte vient de quitter : c'est le seul ";
        $corps .= "moyen de vous prévenir si le changement n'est pas de votre fait.";

        $html = self::rendre('courriel_alerte_email.html', null, [
            '{{PSEUDONYME}}' => $pseudonyme,
            '{{ANCIEN_EMAIL}}' => $ancien,
            '{{NOUVEL_EMAIL}}' => $nouveau,
        ]);

        return self::envoyer($ancien, $sujet, $corps, $html, $personneId);
    }

    /**
     * Rend un gabarit d'email, ou null s'il est introuvable — l'email part alors en texte seul
     * plutôt que de ne pas partir du tout : un code de connexion illisible vaut mieux qu'un
     * code jamais reçu.
     *
     * TOUTES les valeurs sont échappées, sauf le bandeau, qui est du HTML déjà rendu par
     * l'application. Il est substitué EN DERNIER, et c'est délibéré : un nom qui contiendrait
     * par hasard le texte d'un autre marqueur ne doit plus être traversé par aucune
     * substitution.
     *
     * @param array<string, string> $valeurs
     */
    private static function rendre(string $gabarit, ?string $contexte, array $valeurs): ?string
    {
        $chemin = Chargeur::racine() . '/gabarits/' . $gabarit;
        if (!is_readable($chemin)) {
            return null;
        }

        $configuration = Configuration::courante();
        $valeurs['{{NOM_SITE}}'] = $configuration->nomSite;
        $valeurs['{{URL_APPLICATION}}'] = $configuration->urlApplication;

        $marqueurs = array_keys($valeurs);
        $remplacements = array_map(
            static fn (string $valeur): string => htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8'),
            array_values($valeurs)
        );

        $marqueurs[] = '{{COULEUR_ACCENT}}';
        $remplacements[] = $configuration->couleurAccent;
        $marqueurs[] = '{{COULEUR_FOND}}';
        $remplacements[] = $configuration->couleurFond;
        $marqueurs[] = '{{STYLE_COURRIEL}}';
        $remplacements[] = self::feuilleDeStyle();
        $marqueurs[] = '{{ENTETE}}';
        $remplacements[] = ($configuration->enteteEmail)($contexte);

        return str_replace($marqueurs, $remplacements, (string) file_get_contents($chemin));
    }

    /**
     * LA FEUILLE DE STYLE DES EMAILS DE LA PLATEFORME, couleurs de l'application déjà substituées.
     *
     * ELLE EST PUBLIQUE PARCE QU'ELLE NE SERT PAS QU'ICI. Le premier email qu'une personne reçoit
     * d'une application est celui de sa connexion, composé par ce composant ; tous les autres —
     * une invitation, une place attribuée, un export de données — arrivent dans la même boîte et
     * doivent se ressembler. Une application intégratrice injecte donc CE MÊME texte dans le
     * <style> de ses propres gabarits, à la place du repère « STYLE_COURRIEL ».
     *
     * INJECTÉE, ET NON LIÉE : un client de messagerie ne va jamais chercher une feuille externe.
     * Une application qui veut s'en écarter ajoute ses règles APRÈS celles-ci, dans le même
     * <style> — c'est ainsi qu'un espace habille ses emails à ses couleurs.
     *
     * LE FICHIER NE PORTE AUCUN COMMENTAIRE, et ne doit pas en gagner : il part chez le
     * destinataire, dans le <style> de chaque email. Un commentaire y serait de la documentation
     * interne expédiée par courrier. Ce qu'il y aurait à en dire est ici, qui ne part jamais.
     *
     * LES DEUX COULEURS SONT DES VARIABLES CSS DANS LE FICHIER, RÉSOLUES ICI. Le fichier reste
     * ainsi du CSS valide — un éditeur peut le lire et le formater, ce qu'un repère « {{…}} » en
     * position de valeur interdisait —, mais aucun `var()` ne part : Gmail et Outlook ignorent
     * les propriétés personnalisées, et un email qui en dépendrait arriverait sans ses couleurs.
     * Chaque `var(--couleur-…)` est donc remplacé par la couleur de l'application, et le bloc
     * `:root` retiré : les valeurs qu'il porte ne sont que celles de l'annuaire, pour l'éditeur.
     *
     * Chaîne vide si le fichier manque : un email sans mise en forme reste lisible, et il vaut
     * mieux qu'un envoi interrompu.
     */
    public static function feuilleDeStyle(): string
    {
        $chemin = Chargeur::racine() . '/css/courriel.css';
        if (!is_readable($chemin)) {
            return '';
        }

        $configuration = Configuration::courante();
        $css = (string) preg_replace('/:root\s*\{[^}]*\}\s*/', '', (string) file_get_contents($chemin), 1);

        return str_replace(
            ['var(--couleur-accent)', 'var(--couleur-fond)'],
            [$configuration->couleurAccent, $configuration->couleurFond],
            $css
        );
    }

    /** Confie l'envoi à l'application ({@see Configuration::$envoiEmail}). */
    private static function envoyer(
        string $destinataire,
        string $sujet,
        string $corps,
        ?string $html,
        ?int $personneId
    ): bool {
        return (bool) (Configuration::courante()->envoiEmail)($destinataire, $sujet, $corps, $html, $personneId);
    }
}
