import { describe, expect, it, vi } from 'vitest';

/**
 * LA RECOPIE DANS LA CONSOLE des lignes que le serveur pose en mode debug
 * (web/resources/js/console-debug.js, servi sans commentaire : son raisonnement est ici).
 *
 * Le serveur n'écrit jamais ces lignes par un `<script>` en ligne : il pose des blocs de DONNÉES,
 * `<script type="application/json" data-role="console-debug">` (Utils::consoleDebug()), qu'aucun
 * navigateur n'exécute. Ce script, servi par
 * le site, les lit une fois le document chargé : les blocs du pied de page sont rendus APRÈS lui.
 *
 * Chaque bloc lu est RETIRÉ : après une panne, le gestionnaire d'exceptions charge lui-même le script,
 * le pied de page n'étant pas rendu ; si la page en avait déjà chargé un, la seconde lecture ne trouve
 * plus rien, et aucune ligne n'apparaît deux fois.
 */

const consoleDebug = globalThis.consoleDebug;

describe('la recopie dans la console des lignes du mode debug', () => {
    it('recopie chaque ligne de chaque bloc posé par le serveur, dans l\'ordre', () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        document.body.innerHTML = `
            <script type="application/json" data-role="console-debug">["Version du SGBD : 8.4", "Mode SQL : strict"]</script>
            <script type="application/json" data-role="console-debug">["Message : panne"]</script>`;

        consoleDebug.afficher();

        expect(journal.mock.calls).toEqual([['Version du SGBD : 8.4'], ['Mode SQL : strict'], ['Message : panne']]);
    });

    it('retire chaque bloc lu : une seconde lecture ne répète rien', () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        document.body.innerHTML = '<script type="application/json" data-role="console-debug">["Une fois"]</script>';

        consoleDebug.afficher();
        consoleDebug.afficher();

        expect(journal.mock.calls).toEqual([['Une fois']]);
        expect(document.querySelector('[data-role="console-debug"]')).toBeNull();
    });

    it('n\'écrit rien quand la page n\'en porte aucun', () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        document.body.innerHTML = '<p></p>';

        consoleDebug.afficher();

        expect(journal).not.toHaveBeenCalled();
    });

    it('initialiser attend la fin du chargement du document : un bloc posé après le script est lu', () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        consoleDebug.initialiser();
        document.body.innerHTML = '<script type="application/json" data-role="console-debug">["Posé après"]</script>';

        document.dispatchEvent(new Event('DOMContentLoaded'));

        expect(journal.mock.calls).toEqual([['Posé après']]);
    });
});
