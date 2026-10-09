/**
 * Setup partagé des tests JavaScript, chargé une fois par vitest.config.mjs.
 *
 * Le script du composant s'appuie en production sur un global, le bundle Bootstrap, et sur
 * matchMedia. On installe ici un mock léger mais fidèle du premier et une version pilotable du
 * second : jsdom n'évalue aucune requête média.
 */
import { afterEach, vi } from 'vitest';

// --- Mock Bootstrap : Dropdown -------------------------------------------------------------
// show / hide REPRODUISENT ce que le vrai Bootstrap laisse derrière lui : la classe « show » et
// surtout « aria-expanded », que le script du composant lit pour savoir quelle entrée est
// déployée. show() DÉPLACE aussi le focus sur l'entrée, comme Bootstrap. Un registre PAR ÉLÉMENT,
// pour que getOrCreateInstance() retrouve l'instance déjà créée, comme le vrai.
class FauxDropdown {
    constructor(el) {
        this.el = el;
        this.menu = el.parentElement.querySelector('.dropdown-menu');
        this.show = vi.fn(() => {
            this.menu.classList.add('show');
            el.setAttribute('aria-expanded', 'true');
            el.focus();
        });
        this.hide = vi.fn(() => {
            this.menu.classList.remove('show');
            el.setAttribute('aria-expanded', 'false');
        });
        FauxDropdown._instances.set(el, this);
    }
    static getInstance(el) {
        return FauxDropdown._instances.get(el) || null;
    }
    static getOrCreateInstance(el) {
        return FauxDropdown._instances.get(el) || new FauxDropdown(el);
    }
}
FauxDropdown._instances = new Map();

// --- Mock Bootstrap : Modal (modale « Installer l'application », app-mobile.js) ------------
class FauxModal {
    constructor(el) {
        this.el = el;
        this.show = vi.fn(() => {
            el.classList.add('show');
        });
        FauxModal._instances.set(el, this);
    }
    static getOrCreateInstance(el) {
        return FauxModal._instances.get(el) || new FauxModal(el);
    }
}
FauxModal._instances = new Map();

globalThis.bootstrap = window.bootstrap = { Dropdown: FauxDropdown, Modal: FauxModal };

// --- Type de pointeur (matchMedia) ---------------------------------------------------------
// Pilotable, réglé par défaut sur l'ORDINATEUR : c'est l'affichage que décrit la quasi-totalité de
// la suite. simulerPointeurGrossier() bascule le test en cours sur un écran tactile.
let pointeurFin = true;

export function simulerPointeurGrossier() {
    pointeurFin = false;
}
globalThis.simulerPointeurGrossier = simulerPointeurGrossier;

window.matchMedia = (requete) => ({
    media: String(requete),
    matches: pointeurFin,
    addEventListener() {},
    removeEventListener() {}
});

// --- Chargement des scripts (CommonJS) -----------------------------------------------------
// Chaque script est un fichier classique, qui expose son objet par un bloc `module.exports` gardé.
// Passer par le PIPELINE DE VITE (un import) est indispensable : c'est à cette transformation que
// le provider istanbul l'instrumente. Un chargement hors pipeline le laisserait à 0 %.

// La recopie dans la console des lignes du mode debug. Le document de jsdom est déjà chargé : son
// écoute de « DOMContentLoaded » ne partira pas seule, les tests émettent l'événement.
import moduleConsoleDebug from '../../web/resources/js/console-debug.js';
export const consoleDebug = moduleConsoleDebug;
globalThis.consoleDebug = consoleDebug;

// L'horloge du pied de page. Même remarque : les tests appellent initHorloge() ou émettent l'événement.
import moduleHorloge from '../../web/resources/js/horloge.js';
export const horloge = moduleHorloge;
globalThis.horloge = horloge;

// L'installation de l'application sur mobile. Même remarque : les tests appellent
// initApplicationMobile() ou émettent l'événement.
import moduleAppMobile from '../../web/resources/js/app-mobile.js';
export const appMobile = moduleAppMobile;
globalThis.appMobile = appMobile;

// --- Hygiène entre les tests ---------------------------------------------------------------
afterEach(() => {
    // Vider le body retire aussi les gestionnaires posés sur les entrées : ils vivaient sur des
    // éléments qui n'existent plus.
    document.body.innerHTML = '';
    pointeurFin = true;
    globalThis.bootstrap = window.bootstrap = { Dropdown: FauxDropdown, Modal: FauxModal };
    FauxDropdown._instances.clear();
    FauxModal._instances.clear();
    vi.useRealTimers();
    vi.restoreAllMocks();
});
