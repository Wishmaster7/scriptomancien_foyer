/**
 * Les onglets transitoires des écrans de gestion (web/resources/js/onglets.js) : « Détails » et « Modifier » prennent la place de
 * « Ajouter » ; montrer un autre onglet de la ligne les retire et rend l'ajout.
 */
import { describe, expect, it } from 'vitest';
import { onglets } from './setup.js';

function monter(transitoires) {
    document.body.innerHTML = `
        <ul class="nav onglets-list">
            <li class="nav-item"><button class="nav-link" id="tab-liste">Liste</button></li>
            <li class="nav-item${transitoires ? ' d-none' : ''}"><button class="nav-link" id="tab-ajouter">Ajouter</button></li>
            ${transitoires ? '<li class="nav-item"><button class="nav-link" id="tab-details" data-onglet-edition="tab-ajouter">Détails</button></li>' : ''}
            ${transitoires ? '<li class="nav-item"><button class="nav-link" id="tab-modifier" data-onglet-edition="tab-ajouter">Modifier</button></li>' : ''}
        </ul>
        <ul class="nav"><li class="nav-item"><button class="nav-link" id="hors-ligne">Ailleurs</button></li></ul>`;
}

const montrer = (id) => document.getElementById(id).dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));
const masque = (id) => document.getElementById(id).closest('.nav-item').classList.contains('d-none');

describe('onglets transitoires', () => {
    it('montrer la liste retire les onglets transitoires et rend l\'ajout', () => {
        monter(true);

        montrer('tab-liste');

        expect(masque('tab-details')).toBe(true);
        expect(masque('tab-modifier')).toBe(true);
        expect(masque('tab-ajouter')).toBe(false);
    });

    it('passer d\'un onglet transitoire à l\'autre, ou un onglet hors ligne de gestion, ne retire rien', () => {
        monter(true);

        montrer('tab-modifier');
        montrer('hors-ligne');

        expect(masque('tab-details')).toBe(false);
        expect(masque('tab-ajouter')).toBe(true);
    });

    it('quitterOngletsEdition est sans effet sur une ligne sans onglet transitoire', () => {
        monter(false);

        onglets.quitterOngletsEdition(document.querySelector('.onglets-list'));

        expect(masque('tab-ajouter')).toBe(false);
    });
});
