var consoleDebug = (function () {
    function afficher() {
        document.querySelectorAll('script[type="application/json"][data-role="console-debug"]').forEach(function (bloc) {
            JSON.parse(bloc.textContent).forEach(function (ligne) {
                console.log(ligne);
            });
            bloc.remove();
        });
    }

    function initialiser() {
        document.addEventListener('DOMContentLoaded', afficher);
    }

    initialiser();

    return {
        afficher: afficher,
        initialiser: initialiser
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = consoleDebug;
}
