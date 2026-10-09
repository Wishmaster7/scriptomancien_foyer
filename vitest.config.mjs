import { defineConfig } from 'vitest/config';

// Tests unitaires JavaScript — l'équivalent de phpunit.xml pour les scripts servis qui nous
// appartiennent : web/resources/js/console-debug.js (la recopie dans la console des lignes que le
// serveur pose en mode debug), horloge.js (l'horloge du pied de page) et app-mobile.js
// (l'installation de l'application sur mobile et la reprise de session).
//
// LE SCRIPT DU COMPOSANT N'EST PAS MESURÉ ICI : web/resources/personnes/js/personnes.js est une
// COPIE, couverte dans le projet « personnes » qui la publie. La mesurer ici obligerait à la
// retester à chaque réinstallation, pour un verdict déjà rendu là-bas.
//
// La couverture est écrite dans coverage/js/ afin de NE PAS écraser celle de PHP.
export default defineConfig({
    test: {
        environment: 'jsdom',
        setupFiles: ['tests/js/setup.js'],
        include: ['tests/js/**/*.test.js'],
        // Le rapport de couverture ne compte AUCUN test : il dit quelles instructions ont été
        // traversées, jamais si la suite qui les traverse est passée. Le journal JUnit, écrit à côté,
        // porte cette seconde information — c'est lui que lit la synthèse des rapports du WAMP.
        reporters: ['default', 'junit'],
        outputFile: { junit: 'coverage/js/junit.xml' },
        coverage: {
            // « istanbul » et NON « v8 » : le remapping des relevés v8 compte la branche « else »
            // d'un `if` qui n'en a pas comme count(if) - count(then), et retombe souvent à 0 —
            // des branches pourtant exercées passent pour non couvertes. Istanbul compte celles
            // réellement prises. setup.js charge le script PAR LE PIPELINE VITE pour que cette
            // instrumentation ait lieu.
            provider: 'istanbul',
            include: [
                'web/resources/js/console-debug.js',
                'web/resources/js/horloge.js',
                'web/resources/js/app-mobile.js'
            ],
            reporter: ['text', 'html', 'clover'],
            reportsDirectory: 'coverage/js',
            // Couverture COMPLÈTE sur les quatre axes : une branche non couverte signale un vrai
            // chemin non testé, et se traite — jamais en abaissant ce seuil.
            thresholds: {
                lines: 100,
                functions: 100,
                statements: 100,
                branches: 100
            }
        }
    }
});
