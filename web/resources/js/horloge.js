var horloge = (function () {
    function formaterDateHeure(instant) {
        return instant.toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    function initHorloge() {
        var emplacement = document.getElementById('current-date');
        if (emplacement === null) {
            return null;
        }

        var ecrire = function () {
            emplacement.textContent = formaterDateHeure(new Date());
        };

        ecrire();

        return setInterval(ecrire, 1000);
    }

    document.addEventListener('DOMContentLoaded', initHorloge);

    return {
        formaterDateHeure: formaterDateHeure,
        initHorloge: initHorloge
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = horloge;
}
