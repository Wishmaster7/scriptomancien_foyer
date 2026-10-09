var onglets = (function () {
    /**
     * Retire les onglets transitoires d'une ligne d'onglets (« Détails », « Modifier » : ils portent `data-onglet-edition`) et
     * rend celui qu'ils remplaçaient — l'id qu'ils désignent, l'onglet d'ajout.
     */
    function quitterOngletsEdition(ligne) {
        ligne.querySelectorAll('[data-onglet-edition]').forEach(function (onglet) {
            onglet.closest('.nav-item').classList.add('d-none');
            document.getElementById(onglet.getAttribute('data-onglet-edition')).closest('.nav-item').classList.remove('d-none');
        });
    }

    /**
     * Dès qu'un onglet NON transitoire est montré, les transitoires de sa ligne s'effacent : revenir sur la liste, c'est quitter
     * la fiche ou l'édition. Bootstrap émet `shown.bs.tab` sur l'onglet, et l'événement remonte jusqu'au document.
     */
    function initOngletsEdition() {
        document.addEventListener('shown.bs.tab', function (evenement) {
            var ligne = evenement.target.closest('.onglets-list');
            if (ligne !== null && !evenement.target.hasAttribute('data-onglet-edition')) {
                quitterOngletsEdition(ligne);
            }
        });
    }

    initOngletsEdition();

    return {
        quitterOngletsEdition: quitterOngletsEdition,
        initOngletsEdition: initOngletsEdition
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = onglets;
}
