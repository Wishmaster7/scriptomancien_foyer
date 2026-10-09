var appMobile = (function () {
    /** Le cookie par lequel l'application installée se fait connaître du serveur (Utils::COOKIE_APPLICATION). */
    var COOKIE_APPLICATION = 'app_installee';

    /** L'invitation d'installer que le navigateur a proposée (Chrome, Android), gardée pour le clic de « Mobile App ». */
    var invitationInstallation = null;

    // Capturée dès le chargement du script, non à l'initialisation : le navigateur ne la propose qu'une fois, et un clic
    // ultérieur ne peut la rejouer que si on l'a gardée. `preventDefault` retient sa bannière, que le menu remplace.
    window.addEventListener('beforeinstallprompt', function (evenement) {
        evenement.preventDefault();
        invitationInstallation = evenement;
    });

    /** Vrai quand la page s'exécute DANS l'application installée (plein écran), non dans un onglet du navigateur. */
    function estApplicationInstallee() {
        // `navigator.standalone` est la seule réponse de Safari (iOS) ; les autres navigateurs répondent à la requête média.
        return window.navigator.standalone === true
            || (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches);
    }

    /** Vrai sur un iPhone ou un iPad, dont Safari n'a aucune API d'installation : on y explique le geste. */
    function estAppareilApple() {
        // Un iPad récent se déclare « Macintosh » : seul son écran tactile le trahit.
        return /iPhone|iPad|iPod/.test(window.navigator.userAgent)
            || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
    }

    /** Vrai sur un téléphone ou une tablette — l'appareil pour lequel l'entrée « Mobile App » a un sens. */
    function estAppareilMobile() {
        return estAppareilApple() || /Android|Mobile/i.test(window.navigator.userAgent);
    }

    /**
     * Signale au serveur de garder la session ouverte (cf. Utils::demarrerSession()).
     *
     * `samesite=lax`, non `strict` : à l'ouverture depuis l'écran d'accueil, certains navigateurs (Safari) ne joignent pas un
     * cookie `strict` à la première requête, et le serveur prendrait l'application pour un onglet.
     *
     * @returns {boolean} Vrai si le cookie vient d'être posé, faux s'il l'était déjà.
     */
    function poserCookieApplication() {
        if (document.cookie.split('; ').includes(COOKIE_APPLICATION + '=1')) {
            return false;
        }

        // Sans `secure`, à dessein : il ne porte aucune donnée, et poser l'attribut n'aurait de sens qu'en HTTPS — un cookie
        // `secure` posé depuis une page en clair serait rejeté, et l'application, en développement local, ne serait jamais reconnue.
        document.cookie = COOKIE_APPLICATION + '=1; path=/; max-age=31536000; samesite=lax';

        return true;
    }

    /**
     * Dans l'application installée, pose ce cookie.
     *
     * LA PAGE SE RECHARGE UNE FOIS, à la première ouverture : la session de l'application vit dans son propre stockage, que le
     * serveur ne choisit qu'à la requête suivante.
     *
     * @returns {boolean} Vrai si le cookie vient d'être posé, donc si la page se recharge.
     */
    function marquerApplicationInstallee() {
        if (!poserCookieApplication()) {
            return false;
        }
        window.location.reload();

        return true;
    }

    /** La dernière page vue dans l'application installée, et le témoin d'un lancement déjà traité dans cette vie de l'application. */
    var CLE_DERNIERE_PAGE = 'derniere_page';
    var CLE_LANCEMENT_TRAITE = 'lancement_traite';

    /** L'adresse que l'icône d'accueil ouvre (manifest.webmanifest) : la seule qu'on remplace par la dernière page vue. */
    var ADRESSE_LANCEMENT = '/';

    /**
     * Dans l'application installée, rouvre la dernière page vue plutôt que l'accueil que l'icône ouvre toujours.
     *
     * SANS WORKER DE SERVICE, ni cache : l'adresse est gardée dans le navigateur, la page se recharge du serveur comme à
     * l'ordinaire (et le serveur refait sa vérification de session). Un LANCEMENT se reconnaît à `sessionStorage` vide : il
     * survit à la mise en arrière-plan, pas à la fermeture de l'application.
     *
     * @returns {boolean} Vrai si la page est remplacée par la dernière vue.
     */
    function reprendreDernierePage() {
        var adresse = window.location.pathname + window.location.search;

        try {
            var lancement = window.sessionStorage.getItem(CLE_LANCEMENT_TRAITE) === null;
            window.sessionStorage.setItem(CLE_LANCEMENT_TRAITE, '1');
            var derniere = window.localStorage.getItem(CLE_DERNIERE_PAGE);

            if (lancement && adresse === ADRESSE_LANCEMENT && derniere !== null && derniere !== adresse) {
                window.location.replace(derniere);

                return true;
            }
            window.localStorage.setItem(CLE_DERNIERE_PAGE, adresse);
        } catch (erreur) {
            // Sans stockage, on ne sait pas reprendre : l'application s'ouvre simplement sur la page demandée.
            return false;
        }

        return false;
    }

    /**
     * « Mobile App » : sur un téléphone, hors de l'application, révèle l'entrée du menu et arme son clic ; dans l'application,
     * pose le cookie de session durable ; ailleurs, ne fait rien — l'entrée reste cachée (`hidden`, posé par le serveur).
     *
     * @returns {string} « installee », « telephone » ou « ordinateur ».
     */
    function initApplicationMobile() {
        if (estApplicationInstallee()) {
            if (!marquerApplicationInstallee()) {
                reprendreDernierePage();
            }

            return 'installee';
        }
        if (!estAppareilMobile()) {
            return 'ordinateur';
        }

        document.querySelectorAll('[data-role="app-mobile"]').forEach(function (entree) {
            entree.removeAttribute('hidden');
        });
        document.querySelectorAll('[data-action="installer-app"]').forEach(function (entree) {
            entree.addEventListener('click', function (evenement) {
                evenement.preventDefault();
                installerApplication();
            });
        });

        return 'telephone';
    }

    /**
     * Lance l'installation : l'invitation du navigateur quand il en a donné une, sinon le mode d'emploi de l'appareil — iOS n'en
     * donne jamais, et Chrome n'en donne pas à une application déjà installée.
     *
     * L'invitation ne sert qu'UNE FOIS, qu'elle soit acceptée ou refusée : le clic suivant retombe sur le mode d'emploi.
     */
    function installerApplication() {
        poserCookieApplication();

        if (invitationInstallation !== null) {
            var invitation = invitationInstallation;
            invitationInstallation = null;
            invitation.prompt();

            return;
        }

        var apple = estAppareilApple();
        document.querySelector('[data-role="app-mobile-ios"]').hidden = !apple;
        document.querySelector('[data-role="app-mobile-autre"]').hidden = apple;
        bootstrap.Modal.getOrCreateInstance(document.querySelector('[data-role="modal-app-mobile"]')).show();
    }

    document.addEventListener('DOMContentLoaded', initApplicationMobile);

    return {
        estApplicationInstallee: estApplicationInstallee,
        estAppareilApple: estAppareilApple,
        estAppareilMobile: estAppareilMobile,
        poserCookieApplication: poserCookieApplication,
        marquerApplicationInstallee: marquerApplicationInstallee,
        reprendreDernierePage: reprendreDernierePage,
        initApplicationMobile: initApplicationMobile,
        installerApplication: installerApplication
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = appMobile;
}
