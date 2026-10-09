import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * L'HORLOGE DU PIED DE PAGE (web/resources/js/horloge.js, servi sans commentaire : son raisonnement
 * est ici, formaterDateHeure / initHorloge).
 *
 * La page est rendue une fois et reste ouverte des heures : une date posée par le serveur y
 * vieillirait sous les yeux. C'est donc l'heure du VISITEUR qui s'affiche, réécrite chaque seconde.
 *
 *  - formaterDateHeure() est PURE, séparée de l'horloge qui l'appelle : c'est le formatage qui est
 *    délicat — la langue, le mois en toutes lettres, les deux chiffres de l'heure — et il se vérifie
 *    sur une date choisie, sans faire tourner le temps ;
 *  - initHorloge() pose la date UNE PREMIÈRE FOIS avant d'armer le battement : sans cela, le pied
 *    resterait vide pendant la première seconde de chaque page ;
 *  - le battement n'est armé que si l'emplacement existe : toute page en porte un, mais un fragment
 *    rendu seul — la page de panne de Utils::gererException(), un test — n'en a pas, et une horloge
 *    qui écrirait dans le vide lèverait une erreur à chaque seconde, indéfiniment.
 */

const horloge = globalThis.horloge;

describe('formaterDateHeure', () => {
    it('écrit la date et l\'heure en français, mois en toutes lettres', () => {
        const texte = horloge.formaterDateHeure(new Date(2026, 8, 9, 8, 6, 21));

        expect(texte).toContain('septembre');
        expect(texte).toContain('2026');
        expect(texte).toContain('08:06:21');
    });
});

describe('initHorloge', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('écrit la date AVANT le premier battement', () => {
        vi.useFakeTimers();
        document.body.innerHTML = '<span id="current-date"></span>';

        horloge.initHorloge();

        expect(document.getElementById('current-date').textContent).not.toBe('');
    });

    it('réécrit la date à chaque seconde', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 8, 9, 8, 6, 21));
        document.body.innerHTML = '<span id="current-date"></span>';

        horloge.initHorloge();
        const premier = document.getElementById('current-date').textContent;

        vi.setSystemTime(new Date(2026, 8, 9, 8, 6, 22));
        vi.advanceTimersByTime(1000);

        expect(document.getElementById('current-date').textContent).not.toBe(premier);
    });

    it('ne s\'arme pas sur une page sans horloge', () => {
        document.body.innerHTML = '<main></main>';

        expect(horloge.initHorloge()).toBeNull();
    });

    it('s\'arme d\'elle-même à la fin du chargement du document', () => {
        vi.useFakeTimers();
        document.body.innerHTML = '<span id="current-date"></span>';

        document.dispatchEvent(new Event('DOMContentLoaded'));

        expect(document.getElementById('current-date').textContent).not.toBe('');
    });
});
