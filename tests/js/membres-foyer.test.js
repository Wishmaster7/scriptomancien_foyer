/**
 * La zone des membres d'un foyer (web/resources/js/membres-foyer.js) : la liste paginée des membres, et la fenêtre de recherche qui en
 * ajoute. LA VÉRITÉ EST DANS LE FORMULAIRE — ses champs cachés « membres[] » — : chaque test vérifie ce qui serait envoyé.
 */
import { describe, expect, it } from 'vitest';
import { membresFoyer } from './setup.js';

/** Monte un formulaire, sa zone et sa fenêtre ; `retenus` sont les membres déjà dans le formulaire. */
function monter(candidats, retenus = []) {
    document.body.innerHTML = `
        <form id="t_formulaire">
            <div data-role="champs-membres">${retenus.map((id) => `<input type="hidden" name="membres[]" value="${id}">`).join('')}</div>
        </form>
        <div data-role="zone-membres" data-formulaire="t_formulaire" data-fenetre="t_fenetre">
            <script type="application/json" data-role="candidats">${JSON.stringify(candidats)}</script>
            <p data-role="aucun-membre" hidden>Aucun membre.</p>
            <ul data-role="liste-membres"></ul>
            <ul data-role="pagination-membres"></ul>
            <button type="button" id="ouvrir">Ajouter un membre</button>
        </div>
        <div id="t_fenetre">
            <input type="search" data-role="recherche-membre">
            <p data-role="aucun-resultat" hidden></p>
            <div data-role="resultats-membres"></div>
        </div>`;

    return membresFoyer.initZone(document.querySelector('[data-role="zone-membres"]'));
}

const role = (nom) => document.querySelector(`[data-role="${nom}"]`);
const listes = () => [...role('liste-membres').querySelectorAll('li > span')].map((s) => s.textContent);
const resultats = () => [...role('resultats-membres').querySelectorAll('[data-ajouter]')].map((b) => b.textContent);
const pages = () => [...role('pagination-membres').querySelectorAll('button')].map((b) => b.textContent);
const envoyes = () => [...document.querySelectorAll('[data-role="champs-membres"] input')].map((c) => Number(c.value));

const PERSONNES = [
    { id: 1, libelle: 'Zoé' },
    { id: 2, libelle: 'Léa (Léa Martin)' },
    { id: 3, libelle: 'Tom' }
];

/** 23 personnes « Personne 01 » à « Personne 23 » : trois pages de dix. */
const FOULE = Array.from({ length: 23 }, (_, i) => ({ id: i + 1, libelle: 'Personne ' + String(i + 1).padStart(2, '0') }));

describe('normaliser', () => {
    it('ôte casse et accents', () => {
        expect(membresFoyer.normaliser('Léa ÉLOÏSE')).toBe('lea eloise');
    });
});

describe('la liste des membres', () => {
    it('montre les membres du formulaire, triés, sans pagination pour une seule page', () => {
        monter(PERSONNES, [1, 2]);

        expect(listes()).toEqual(['Léa (Léa Martin)', 'Zoé']);
        expect(role('aucun-membre').hidden).toBe(true);
        expect(role('pagination-membres').hidden).toBe(true);
        expect(pages()).toEqual([]);
    });

    it('dit qu\'il n\'y a aucun membre', () => {
        monter(PERSONNES);

        expect(listes()).toEqual([]);
        expect(role('aucun-membre').hidden).toBe(false);
    });

    it('pagine par dix, avec précédent et suivant désactivés aux extrémités', () => {
        monter(FOULE, FOULE.map((p) => p.id));

        expect(listes()).toHaveLength(10);
        expect(listes()[0]).toBe('Personne 01');
        expect(pages()).toEqual(['«', '1', '2', '3', '»']);
        const boutons = role('pagination-membres').querySelectorAll('button');
        expect(boutons[0].disabled).toBe(true);
        expect(boutons[1].closest('li').classList.contains('active')).toBe(true);

        boutons[4].click();
        expect(listes()[0]).toBe('Personne 11');
        role('pagination-membres').querySelectorAll('button')[3].click();
        expect(listes()).toEqual(['Personne 21', 'Personne 22', 'Personne 23']);
        expect(role('pagination-membres').querySelectorAll('button')[4].disabled).toBe(true);
    });

    it('retirer un membre le sort du formulaire, et ramène sur la dernière page qui existe encore', () => {
        const zone = monter(FOULE, FOULE.map((p) => p.id));
        zone.allerA(3);

        // Par l'icône du bouton : le clic remonte jusqu'à lui.
        [21, 22, 23].forEach((id) => role('liste-membres').querySelector(`[data-retirer="${id}"] i`).click());

        expect(envoyes()).toHaveLength(20);
        expect(listes()[0]).toBe('Personne 11');
        expect(pages()).toEqual(['«', '1', '2', '»']);
    });

    it('un clic hors des boutons de la zone, ou sur un bouton sans geste, ne change rien', () => {
        monter(PERSONNES, [1]);

        role('liste-membres').querySelector('span').click();
        document.getElementById('ouvrir').click();

        expect(envoyes()).toEqual([1]);
    });
});

describe('la fenêtre de recherche', () => {
    it('propose les personnes qui ne sont pas encore membres, filtrées sans casse ni accent', () => {
        monter(PERSONNES, [1]);
        const recherche = role('recherche-membre');

        // RIEN TANT QUE RIEN N'EST SAISI — ni résultat, ni « aucune personne ».
        expect(resultats()).toEqual([]);
        expect(role('aucun-resultat').hidden).toBe(true);
        recherche.value = '   ';
        recherche.dispatchEvent(new Event('input'));
        expect(resultats()).toEqual([]);
        expect(role('aucun-resultat').hidden).toBe(true);

        recherche.value = 'o';
        recherche.dispatchEvent(new Event('input'));
        expect(resultats()).toEqual(['Tom']);

        recherche.value = '  LEA ';
        recherche.dispatchEvent(new Event('input'));
        expect(resultats()).toEqual(['Léa (Léa Martin)']);
        expect(role('aucun-resultat').hidden).toBe(true);

        recherche.value = 'personne';
        recherche.dispatchEvent(new Event('input'));
        expect(resultats()).toEqual([]);
        expect(role('aucun-resultat').hidden).toBe(false);
    });

    it('ne propose que les cinq premiers résultats', () => {
        monter(FOULE);
        const recherche = role('recherche-membre');

        recherche.value = 'personne';
        recherche.dispatchEvent(new Event('input'));

        expect(resultats()).toEqual(['Personne 01', 'Personne 02', 'Personne 03', 'Personne 04', 'Personne 05']);
    });

    it('ajouter un membre l\'écrit dans le formulaire, le retire des résultats et montre sa page', () => {
        monter(FOULE, FOULE.slice(0, 22).map((p) => p.id));
        role('recherche-membre').value = '23';
        role('recherche-membre').dispatchEvent(new Event('input'));

        role('resultats-membres').querySelector('[data-ajouter="23"] i').click();

        expect(envoyes()).toContain(23);
        expect(resultats()).toEqual([]);
        expect(listes()).toEqual(['Personne 21', 'Personne 22', 'Personne 23']);
    });

    it('n\'ajoute pas deux fois la même personne, et un clic hors d\'un résultat ne fait rien', () => {
        const zone = monter(PERSONNES, [1]);

        expect(zone.ajouter(1)).toBe(false);
        role('recherche-membre').click();

        expect(envoyes()).toEqual([1]);
        expect(zone.membres()).toEqual([1]);
    });

    it('s\'ouvre sur une recherche vierge, puis place le curseur dans le champ', () => {
        monter(PERSONNES);
        const fenetre = document.getElementById('t_fenetre');
        const recherche = role('recherche-membre');
        recherche.value = 'tom';
        recherche.dispatchEvent(new Event('input'));
        expect(resultats()).toEqual(['Tom']);

        fenetre.dispatchEvent(new Event('show.bs.modal'));
        expect(recherche.value).toBe('');
        expect(resultats()).toEqual([]);

        fenetre.dispatchEvent(new Event('shown.bs.modal'));
        expect(document.activeElement).toBe(recherche);
    });
});

describe('la zone en lecture seule (fiche du foyer)', () => {
    it('pagine ses membres, sans bouton ni recherche', () => {
        document.body.innerHTML = `
            <div data-role="zone-membres">
                <script type="application/json" data-role="candidats">${JSON.stringify(FOULE)}</script>
                <p data-role="aucun-membre" hidden></p>
                <ul data-role="liste-membres"><li><span>rendu par le serveur</span></li></ul>
                <ul data-role="pagination-membres"></ul>
            </div>`;

        const zone = membresFoyer.initZone(document.querySelector('[data-role="zone-membres"]'));

        expect(listes()).toHaveLength(10);
        expect(listes()[0]).toBe('Personne 01');
        expect(role('liste-membres').querySelectorAll('button')).toHaveLength(0);
        expect(pages()).toEqual(['«', '1', '2', '3', '»']);
        expect(zone.membres()).toHaveLength(23);

        role('pagination-membres').querySelectorAll('button')[3].click();
        expect(listes()).toEqual(['Personne 21', 'Personne 22', 'Personne 23']);
    });
});

describe('initMembresFoyer', () => {
    it('arme chaque zone de la page', () => {
        monter(PERSONNES, [3]);

        expect(membresFoyer.initMembresFoyer()).toHaveLength(1);
    });
});
