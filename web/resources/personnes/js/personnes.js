var personnesMenusSurvol = (function () {
    var DELAI_FERMETURE = 200;

    function pointeurFin() {
        if (typeof window.matchMedia !== 'function') {
            return true;
        }
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    function basculer(bascule, ouvrir) {
        var menu = bootstrap.Dropdown.getOrCreateInstance(bascule);
        if (!ouvrir) {
            menu.hide();
            return;
        }
        var focalise = document.activeElement;
        menu.show();
        if (document.activeElement !== focalise) {
            bascule.blur();
            focalise.focus({ preventScroll: true });
        }
    }

    function initialiser() {
        var compte = document.getElementById('navCompte');
        if (compte === null || typeof bootstrap === 'undefined' || !pointeurFin()) {
            return false;
        }

        var barre = compte.closest('nav') || compte.parentElement;
        var bascules = Array.prototype.slice.call(barre.querySelectorAll('[data-bs-toggle="dropdown"]'));
        var fermeture = null;

        bascules.forEach(function (bascule) {
            var entree = bascule.parentElement;

            entree.addEventListener('mouseenter', function () {
                if (fermeture !== null) {
                    clearTimeout(fermeture);
                    fermeture = null;
                }
                bascules.forEach(function (autre) {
                    if (autre !== bascule && autre.getAttribute('aria-expanded') === 'true') {
                        basculer(autre, false);
                    }
                });
                basculer(bascule, true);
            });

            entree.addEventListener('mouseleave', function () {
                fermeture = setTimeout(function () {
                    fermeture = null;
                    basculer(bascule, false);
                }, DELAI_FERMETURE);
            });
        });

        return true;
    }

    function demarrer() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialiser);
            return;
        }
        initialiser();
    }

    demarrer();

    return {
        DELAI_FERMETURE: DELAI_FERMETURE,
        pointeurFin: pointeurFin,
        basculer: basculer,
        initialiser: initialiser,
        demarrer: demarrer
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = personnesMenusSurvol;
}
