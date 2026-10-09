/**
 * La page « Scanner un reçu » (web/resources/js/budget-scan.js) : de l'image au formulaire de relecture.
 *
 * Tesseract, `createImageBitmap`, le canevas et `fetch` sont simulés : jsdom n'en a pas, et aucun test ne lit une vraie
 * image ni n'appelle le serveur. Le balisage monté ici reprend celui de web/budget_scan/template_budget_scan.php.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { budgetScan } from './setup.js';

const MESSAGE_ECHEC = "L'analyse du reçu a échoué. Réessayez, ou saisissez le reçu à la main.";

function ligneHtml(index, article = {}) {
    const retenue = (article.retenu ?? '1') === '1';

    return '<tr data-role="ligne-article">'
        + `<td><input type="checkbox" data-champ="retenu" name="articles[${index}][retenu]" value="1"${retenue ? ' checked' : ''}></td>`
        + `<td><input type="text" data-champ="nom" name="articles[${index}][nom]" value="${article.nom ?? ''}"></td>`
        + `<td><input type="text" data-champ="montant" name="articles[${index}][montant]" value="${article.montant ?? ''}"></td>`
        + `<td><input type="text" data-champ="monnaie" name="articles[${index}][monnaie]" value="${article.monnaie ?? ''}"></td>`
        + '<td><button type="button" data-action="retirer-article"><i class="fas fa-xmark"></i></button></td>'
        + '</tr>';
}

/** Monte la page ; `lignes` sont des lignes déjà rendues par le serveur (saisie refusée). */
function monterPage(lignes = []) {
    document.body.innerHTML = `
        <div id="page-budget-scan" data-url-analyse="/?action=budget_scan_analyser"
             data-tesseract-worker="/t/worker.min.js" data-tesseract-core="/t/core" data-tesseract-langues="/t/lang"
             data-monnaie-defaut="CHF">
            <div data-role="langues">
                <button type="button" data-langue="fra" aria-pressed="true"><img alt="Français"></button>
                <button type="button" data-langue="eng" aria-pressed="false"><img alt="Anglais"></button>
                <button type="button" data-langue="deu" aria-pressed="false"><img alt="Allemand"></button>
            </div>
            <div data-role="zone-depot">
                <input type="file" data-role="fichier-photo">
                <input type="file" data-role="fichier-image">
            </div>
            <div data-role="erreur-scan" hidden></div>
            <form data-role="formulaire-depense"${lignes.length > 0 ? '' : ' hidden'}>
                <input type="hidden" name="csrf_token" value="jeton-csrf">
                <input type="hidden" name="foyer_id" value="3">
                <input type="date" name="date_document">
                <input type="text" name="vendeur">
                <input type="text" name="lieu">
                <input type="text" name="numero_tva">
                <input type="text" name="description">
                <div data-role="alerte-total" hidden></div>
                <table><tbody data-role="lignes-articles">${lignes.map((article, i) => ligneHtml(i, article)).join('')}</tbody></table>
                <button type="button" data-action="ajouter-article"><i class="fas fa-plus"></i> Ajouter</button>
            </form>
            <template data-role="modele-article">${ligneHtml('__INDEX__')}</template>
            <div data-role="voile-chargement" hidden><p data-role="etape-chargement"></p></div>
        </div>`;
}

const role = (nom) => document.querySelector(`[data-role="${nom}"]`);
const lignes = () => [...document.querySelectorAll('[data-role="lignes-articles"] [data-role="ligne-article"]')];
const champ = (ligne, nom) => ligne.querySelector(`[data-champ="${nom}"]`);

const PROPOSITION = {
    date_document: '2026-10-08',
    numero_tva: 'CHE-123',
    vendeur: 'Migros',
    lieu: 'Genève',
    description: 'Courses',
    total_imprime: '4.60',
    articles: [
        { nom: 'Pain', montant: '3.50', monnaie: 'CHF' },
        { nom: 'Lait', montant: '1.10', monnaie: 'CHF' }
    ]
};

/** Un Tesseract simulé : `createWorker` rend un worker qui lit `texte`, ou échoue avec `erreur`. */
function simulerTesseract({ texte = 'MIGROS Pain 3.50', echecLecture = null } = {}) {
    const worker = {
        setParameters: vi.fn(() => Promise.resolve()),
        recognize: vi.fn(() => (echecLecture ? Promise.reject(echecLecture) : Promise.resolve({ data: { text: texte } }))),
        terminate: vi.fn(() => Promise.resolve())
    };
    window.Tesseract = {
        OEM: { LSTM_ONLY: 1 },
        createWorker: vi.fn((langue, oem, options) => {
            options.logger({ status: 'loading language traineddata', progress: 0.5 });
            options.logger({ status: 'recognizing text', progress: 0.42 });

            return Promise.resolve(worker);
        })
    };

    return worker;
}

/** Un `fetch` simulé qui rend ce JSON — ou un corps illisible quand `json` vaut `undefined`. */
function simulerServeur(json) {
    window.fetch = vi.fn(() => Promise.resolve({
        json: () => (json === undefined ? Promise.reject(new SyntaxError('HTML')) : Promise.resolve(json))
    }));

    return window.fetch;
}

const imageJpeg = () => new File(['x'], 'recu.jpg', { type: 'image/jpeg' });

const fetchOriginal = window.fetch;

beforeEach(() => {
    delete window.createImageBitmap;
});

afterEach(() => {
    delete window.Tesseract;
    delete window.createImageBitmap;
    window.fetch = fetchOriginal;
});

describe('estImageAcceptee', () => {
    it('accepte JPEG, PNG et WebP, et rien d\'autre', () => {
        expect(budgetScan.estImageAcceptee({ type: 'image/png' })).toBe(true);
        expect(budgetScan.estImageAcceptee({ type: 'image/webp' })).toBe(true);
        expect(budgetScan.estImageAcceptee({ type: 'application/pdf' })).toBe(false);
    });
});

describe('preparerImage', () => {
    it('rend l\'image telle quelle quand le navigateur ne sait pas la décoder', async () => {
        const fichier = imageJpeg();

        await expect(budgetScan.preparerImage(fichier)).resolves.toBe(fichier);
    });

    it('la réduit et la passe en niveaux de gris contrastés, puis libère le bitmap', async () => {
        const bitmap = { width: 4800, height: 1200, close: vi.fn() };
        window.createImageBitmap = vi.fn(() => Promise.resolve(bitmap));
        const contexte = { filter: '', drawImage: vi.fn() };
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(contexte);

        const canevas = await budgetScan.preparerImage(imageJpeg());

        expect(canevas.width).toBe(2400);
        expect(canevas.height).toBe(600);
        expect(contexte.filter).toBe('grayscale(1) contrast(1.4)');
        expect(contexte.drawImage).toHaveBeenCalledWith(bitmap, 0, 0, 2400, 600);
        expect(bitmap.close).toHaveBeenCalled();
    });

    it('ne l\'agrandit jamais', async () => {
        window.createImageBitmap = vi.fn(() => Promise.resolve({ width: 800, height: 600, close() {} }));
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage() {} });

        const canevas = await budgetScan.preparerImage(imageJpeg());

        expect([canevas.width, canevas.height]).toEqual([800, 600]);
    });
});

describe('reconnaitreTexte', () => {
    beforeEach(() => monterPage());

    it('lit le texte en français, avec les fichiers du site en adresses absolues, et libère le worker', async () => {
        const worker = simulerTesseract({ texte: 'TEXTE LU' });

        await expect(budgetScan.reconnaitreTexte('image')).resolves.toBe('TEXTE LU');

        const [langue, oem, options] = window.Tesseract.createWorker.mock.calls[0];
        expect(langue).toBe('fra');
        expect(oem).toBe(1);
        expect(options.workerPath).toBe(new URL('/t/worker.min.js', window.location.href).href);
        expect(options.corePath).toBe(new URL('/t/core/tesseract-core-simd-lstm.wasm.js', window.location.href).href);
        expect(options.langPath).toBe(new URL('/t/lang', window.location.href).href);
        expect(options.cachePath).toBe(options.langPath);
        expect(worker.setParameters).toHaveBeenCalledWith({ tessedit_pageseg_mode: '4', preserve_interword_spaces: '1' });
        expect(worker.recognize).toHaveBeenCalledWith('image');
        expect(worker.terminate).toHaveBeenCalled();
        // La progression de la lecture s'affiche sous le voile ; les autres étapes de Tesseract, non.
        expect(role('etape-chargement').textContent).toBe('Lecture du reçu… 42 %');
    });

    it('lit dans la langue du drapeau cliqué, qui seul reste enfoncé', async () => {
        budgetScan.initBudgetScan();
        simulerTesseract();
        const drapeaux = () => [...document.querySelectorAll('[data-langue]')].map((d) => d.getAttribute('aria-pressed'));

        // Par l'image : le clic remonte jusqu'au drapeau. Un clic entre les drapeaux ne change rien.
        document.querySelector('[data-langue="deu"] img').click();
        role('langues').click();
        expect(drapeaux()).toEqual(['false', 'false', 'true']);
        await budgetScan.reconnaitreTexte('image');

        expect(window.Tesseract.createWorker.mock.calls[0][0]).toBe('deu');
    });

    it('lit en français sans drapeau', () => {
        role('langues').remove();

        expect(budgetScan.langueChoisie()).toBe('fra');
    });

    it('prend le moteur sans SIMD dans un navigateur qui ne les connaît pas', async () => {
        simulerTesseract({ texte: 'TEXTE LU' });
        const validate = vi.spyOn(WebAssembly, 'validate').mockReturnValue(false);

        try {
            await budgetScan.reconnaitreTexte('image');
        } finally {
            validate.mockRestore();
        }

        expect(window.Tesseract.createWorker.mock.calls[0][2].corePath)
            .toBe(new URL('/t/core/tesseract-core-lstm.wasm.js', window.location.href).href);
    });

    it('libère le worker même quand la lecture échoue', async () => {
        const worker = simulerTesseract({ echecLecture: new Error('wasm') });

        await expect(budgetScan.reconnaitreTexte('image')).rejects.toThrow('wasm');
        expect(worker.terminate).toHaveBeenCalled();
    });
});

describe('analyserTexte', () => {
    beforeEach(() => monterPage());

    it('poste le texte et le jeton CSRF en AJAX, et rend la proposition', async () => {
        const fetch = simulerServeur({ success: true, donnees: PROPOSITION });

        await expect(budgetScan.analyserTexte('MIGROS')).resolves.toEqual(PROPOSITION);

        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe(new URL('/?action=budget_scan_analyser', window.location.href).href);
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
        expect(options.body.get('texte')).toBe('MIGROS');
        expect(options.body.get('csrf_token')).toBe('jeton-csrf');
    });

    it('rend le message du serveur, ou le message générique', async () => {
        simulerServeur({ success: false, message: 'Limite atteinte.' });
        await expect(budgetScan.analyserTexte('t')).rejects.toMatchObject({ message: 'Limite atteinte.', affichable: true });

        simulerServeur({ success: false });
        await expect(budgetScan.analyserTexte('t')).rejects.toMatchObject({ message: MESSAGE_ECHEC, affichable: true });

        // Une session expirée renvoie une page HTML : illisible en JSON.
        simulerServeur(undefined);
        await expect(budgetScan.analyserTexte('t')).rejects.toMatchObject({ message: MESSAGE_ECHEC, affichable: true });
    });
});

describe('remplirFormulaire et verifierTotal', () => {
    beforeEach(() => monterPage());

    it('remplit l\'en-tête et une ligne cochée par article, puis révèle le formulaire', () => {
        budgetScan.remplirFormulaire(PROPOSITION);

        const formulaire = role('formulaire-depense');
        expect(formulaire.hidden).toBe(false);
        expect(formulaire.querySelector('[name="vendeur"]').value).toBe('Migros');
        expect(formulaire.querySelector('[name="date_document"]').value).toBe('2026-10-08');
        expect(lignes()).toHaveLength(2);
        expect(champ(lignes()[1], 'nom').name).toBe('articles[1][nom]');
        expect(champ(lignes()[1], 'montant').value).toBe('1.10');
        expect(champ(lignes()[0], 'retenu').checked).toBe(true);
        expect(role('alerte-total').hidden).toBe(true);
    });

    it('sans article ni en-tête, ouvre une ligne vide dans la monnaie par défaut', () => {
        budgetScan.remplirFormulaire(PROPOSITION);
        budgetScan.remplirFormulaire({ total_imprime: null, articles: [] });

        expect(lignes()).toHaveLength(1);
        expect(champ(lignes()[0], 'nom').name).toBe('articles[0][nom]');
        expect(champ(lignes()[0], 'monnaie').value).toBe('CHF');
        expect(role('formulaire-depense').querySelector('[name="vendeur"]').value).toBe('');
    });

    it('signale un écart entre les lignes cochées et le total imprimé', () => {
        budgetScan.remplirFormulaire({ ...PROPOSITION, total_imprime: '5.00' });

        expect(role('alerte-total').hidden).toBe(false);
        expect(role('alerte-total').textContent).toBe(
            'La somme des articles retenus (4.60 CHF) ne correspond pas au total imprimé sur le reçu (5.00 CHF). Vérifiez les lignes.'
        );

        // Une virgule décimale se lit ; une ligne décochée ne compte pas ; un montant illisible vaut zéro.
        champ(lignes()[0], 'montant').value = '3,90';
        champ(lignes()[1], 'retenu').checked = false;
        expect(budgetScan.verifierTotal()).toBe(true);
        champ(lignes()[1], 'retenu').checked = true;
        expect(budgetScan.verifierTotal()).toBe(false);
        champ(lignes()[1], 'montant').value = 'abc';
        expect(budgetScan.verifierTotal()).toBe(true);
        expect(role('alerte-total').textContent).toContain('(3.90 CHF)');
    });

    it('ne compare rien sans total imprimé, ni quand deux monnaies se mêlent', () => {
        budgetScan.remplirFormulaire({ ...PROPOSITION, total_imprime: null });
        expect(budgetScan.verifierTotal()).toBe(false);

        budgetScan.remplirFormulaire({ ...PROPOSITION, articles: [{ nom: 'A', montant: '1', monnaie: 'CHF' }, { nom: 'B', montant: '1', monnaie: 'eur' }] });
        expect(budgetScan.verifierTotal()).toBe(false);
        expect(role('alerte-total').textContent).toBe('');
    });
});

describe('traiterFichier', () => {
    beforeEach(() => monterPage());

    it('refuse ce qui n\'est pas une image, sans voile', async () => {
        await expect(budgetScan.traiterFichier(new File(['%PDF'], 'recu.pdf', { type: 'application/pdf' }))).resolves.toBe(false);

        expect(role('erreur-scan').hidden).toBe(false);
        expect(role('erreur-scan').textContent).toBe("Ce fichier n'est pas une image JPEG, PNG ou WebP.");
        expect(role('voile-chargement').hidden).toBe(true);
    });

    it('de l\'image au formulaire, sous un voile qui bloque la page jusqu\'au bout', async () => {
        simulerTesseract();
        const voiles = [];
        window.fetch = vi.fn(() => {
            voiles.push([role('voile-chargement').hidden, role('etape-chargement').textContent, document.body.getAttribute('aria-busy')]);

            return Promise.resolve({ json: () => Promise.resolve({ success: true, donnees: PROPOSITION }) });
        });

        await expect(budgetScan.traiterFichier(imageJpeg())).resolves.toBe(true);

        expect(voiles).toEqual([[false, 'Analyse du reçu…', 'true']]);
        expect(role('voile-chargement').hidden).toBe(true);
        expect(document.body.getAttribute('aria-busy')).toBe('false');
        expect(role('etape-chargement').textContent).toBe('');
        expect(role('erreur-scan').hidden).toBe(true);
        expect(lignes()).toHaveLength(2);
    });

    it('un reçu sans texte ouvre le formulaire vide, avec un message', async () => {
        simulerTesseract({ texte: '  \n ' });

        await expect(budgetScan.traiterFichier(imageJpeg())).resolves.toBe(false);

        expect(role('erreur-scan').textContent).toContain("Aucun texte n'a été reconnu");
        expect(role('formulaire-depense').hidden).toBe(false);
        expect(lignes()).toHaveLength(1);
        expect(role('voile-chargement').hidden).toBe(true);
    });

    it('une panne technique donne le message générique, jamais son détail', async () => {
        simulerTesseract({ echecLecture: 'RuntimeError: abort(OOM)' });
        await budgetScan.traiterFichier(imageJpeg());
        expect(role('erreur-scan').textContent).toBe(MESSAGE_ECHEC);

        window.createImageBitmap = vi.fn(() => Promise.reject(undefined));
        await budgetScan.traiterFichier(imageJpeg());
        expect(role('erreur-scan').textContent).toBe(MESSAGE_ECHEC);
    });

    it('le refus du serveur est affiché tel quel', async () => {
        simulerTesseract();
        simulerServeur({ success: false, message: 'Vous avez atteint la limite.' });

        await budgetScan.traiterFichier(imageJpeg());

        expect(role('erreur-scan').textContent).toBe('Vous avez atteint la limite.');
    });
});

describe('initBudgetScan', () => {
    it('ne fait rien hors de la page de scan', () => {
        document.body.innerHTML = '<div id="page-budget"></div>';

        expect(budgetScan.initBudgetScan()).toBe(false);
    });

    it('rend une saisie refusée telle quelle : une ligne décochée reste écartée, et la suivante prend le rang libre', () => {
        monterPage([{ nom: 'Pain', montant: '3.50', monnaie: 'CHF' }, { retenu: '', nom: 'Sac', montant: '0.30', monnaie: 'CHF' }]);

        expect(budgetScan.initBudgetScan()).toBe(true);
        expect(champ(lignes()[0], 'nom').disabled).toBe(false);
        expect(champ(lignes()[1], 'montant').disabled).toBe(true);
        expect(lignes()[1].classList.contains('ligne-ecartee')).toBe(true);

        document.querySelector('[data-action="ajouter-article"] i').click();
        expect(lignes()).toHaveLength(3);
        expect(champ(lignes()[2], 'nom').name).toBe('articles[2][nom]');
        expect(champ(lignes()[2], 'monnaie').value).toBe('CHF');
        expect(document.activeElement).toBe(champ(lignes()[2], 'nom'));
    });

    it('cocher, décocher, saisir et retirer une ligne recalculent le contrôle du total', () => {
        monterPage();
        budgetScan.initBudgetScan();
        budgetScan.remplirFormulaire({ ...PROPOSITION, total_imprime: '3.50' });
        expect(role('alerte-total').hidden).toBe(false);

        const caseLait = champ(lignes()[1], 'retenu');
        caseLait.checked = false;
        caseLait.dispatchEvent(new Event('change', { bubbles: true }));
        expect(champ(lignes()[1], 'nom').disabled).toBe(true);
        expect(role('alerte-total').hidden).toBe(true);

        caseLait.checked = true;
        caseLait.dispatchEvent(new Event('change', { bubbles: true }));
        expect(champ(lignes()[1], 'nom').disabled).toBe(false);
        expect(lignes()[1].classList.contains('ligne-ecartee')).toBe(false);
        expect(role('alerte-total').hidden).toBe(false);

        champ(lignes()[1], 'montant').value = '0';
        champ(lignes()[1], 'montant').dispatchEvent(new Event('input', { bubbles: true }));
        expect(role('alerte-total').hidden).toBe(true);

        champ(lignes()[0], 'montant').value = '1';
        champ(lignes()[0], 'montant').dispatchEvent(new Event('change', { bubbles: true }));
        expect(role('alerte-total').hidden).toBe(false);

        lignes()[0].querySelector('[data-action="retirer-article"] i').click();
        expect(lignes()).toHaveLength(1);
        expect(role('alerte-total').textContent).toContain('(0.00 CHF)');

        // Un clic ailleurs dans le formulaire ne touche à rien.
        role('formulaire-depense').querySelector('[name="vendeur"]').click();
        expect(lignes()).toHaveLength(1);
    });

    it('le glisser-déposer surligne la zone, et un fichier déposé est traité', async () => {
        monterPage();
        budgetScan.initBudgetScan();
        const zone = role('zone-depot');

        for (const type of ['dragenter', 'dragover']) {
            const evenement = new Event(type, { cancelable: true });
            zone.dispatchEvent(evenement);
            expect(evenement.defaultPrevented).toBe(true);
            expect(zone.classList.contains('zone-depot-survol')).toBe(true);
            zone.dispatchEvent(new Event('dragleave'));
            expect(zone.classList.contains('zone-depot-survol')).toBe(false);
        }

        const vide = new Event('drop', { cancelable: true });
        vide.dataTransfer = { files: [] };
        zone.classList.add('zone-depot-survol');
        zone.dispatchEvent(vide);
        expect(vide.defaultPrevented).toBe(true);
        expect(zone.classList.contains('zone-depot-survol')).toBe(false);
        expect(role('erreur-scan').hidden).toBe(true);

        const depot = new Event('drop', { cancelable: true });
        depot.dataTransfer = { files: [new File(['x'], 'note.txt', { type: 'text/plain' })] };
        zone.dispatchEvent(depot);
        await vi.waitFor(() => expect(role('erreur-scan').textContent).toBe("Ce fichier n'est pas une image JPEG, PNG ou WebP."));
    });

    it('une photo prise ou une image choisie est traitée ; un choix annulé ne fait rien', async () => {
        monterPage();
        budgetScan.initBudgetScan();
        simulerTesseract();
        simulerServeur({ success: true, donnees: PROPOSITION });

        for (const nom of ['fichier-photo', 'fichier-image']) {
            const champFichier = role(nom);
            Object.defineProperty(champFichier, 'files', { configurable: true, value: [] });
            champFichier.dispatchEvent(new Event('change'));
            expect(window.Tesseract.createWorker).not.toHaveBeenCalled();

            Object.defineProperty(champFichier, 'files', { configurable: true, value: [imageJpeg()] });
            champFichier.dispatchEvent(new Event('change'));
            await vi.waitFor(() => expect(role('formulaire-depense').hidden).toBe(false));
            expect(champFichier.value).toBe('');

            role('formulaire-depense').hidden = true;
            window.Tesseract.createWorker.mockClear();
        }
    });
});
