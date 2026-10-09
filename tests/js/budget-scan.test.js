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
        + '</tr>';
}

/**
 * Monte la page ; `lignes` sont des lignes déjà rendues par le serveur (saisie refusée). Le foyer est par défaut un champ
 * caché — une personne qui n'en a qu'un —, en CHF.
 */
function monterPage(lignes = [], foyer = '<input type="hidden" name="foyer_id" value="3" data-monnaie="CHF">') {
    document.body.innerHTML = `
        <div id="page-budget-scan" data-url-analyse="/?action=budget_scan_analyser"
             data-tesseract-worker="/t/worker.min.js" data-tesseract-core="/t/core" data-tesseract-langues="/t/lang"
             data-monnaie-defaut="CHF" data-cours-url="https://cours.test/v1">
            <div data-role="langues">
                <button type="button" data-langue="fra" aria-pressed="true" title="Français"><img alt="Français"></button>
                <button type="button" data-langue="eng" aria-pressed="false" title="Anglais"><img alt="Anglais"></button>
                <button type="button" data-langue="deu" aria-pressed="false" title="Allemand"><img alt="Allemand"></button>
            </div>
            <div data-role="zone-depot">
                <input type="file" data-role="fichier-photo">
                <input type="file" data-role="fichier-image">
            </div>
            <div data-role="erreur-scan" hidden></div>
            <form data-role="formulaire-depense"${lignes.length > 0 ? '' : ' hidden'}>
                <input type="hidden" name="csrf_token" value="jeton-csrf">
                ${foyer}
                <input type="datetime-local" name="date_document">
                <input type="text" name="reference">
                <div data-role="ligne-cours" hidden>
                    1 <input type="text" data-role="cours-monnaie-recu" maxlength="3"> =
                    <input type="text" name="cours_change">
                    <span data-role="cours-monnaie-foyer"></span>
                    <a href="https://cours.test/v1" data-role="source-cours"><i class="fas fa-circle-info"></i></a>
                </div>
                <input type="text" name="vendeur">
                <input type="text" name="lieu">
                <input type="text" name="numero_tva">
                <input type="text" name="description">
                <table><tbody data-role="lignes-articles">${lignes.map((article, i) => ligneHtml(i, article)).join('')}</tbody>
                    <tfoot>
                        <tr data-role="ligne-total-calcule"><td></td><th>Total calculé</th><td data-role="total-calcule"></td><td data-role="monnaie-total-calcule"></td></tr>
                        <tr data-role="ecart-total" hidden><td></td><td colspan="3">La somme des articles ne correspond pas au total affiché sur le reçu : vérifiez les lignes.</td></tr>
                        <tr><td></td><th>Total affiché</th><td data-role="total-affiche"></td><td data-role="monnaie-total-affiche"></td></tr>
                    </tfoot>
                </table>
                <h3>Articles <button type="button" data-action="ajouter-article" aria-label="Ajouter un article"><i class="fas fa-circle-plus"></i></button></h3>
            </form>
            <template data-role="modele-article">${ligneHtml('__INDEX__')}</template>
            <div data-role="voile-chargement" hidden><p data-role="etape-chargement"></p></div>
        </div>`;
}

const role = (nom) => document.querySelector(`[data-role="${nom}"]`);
const lignes = () => [...document.querySelectorAll('[data-role="lignes-articles"] [data-role="ligne-article"]')];
const champ = (ligne, nom) => ligne.querySelector(`[data-champ="${nom}"]`);
/** Total calculé, sa monnaie, total affiché, sa monnaie. */
const totaux = () => ['total-calcule', 'monnaie-total-calcule', 'total-affiche', 'monnaie-total-affiche'].map((nom) => role(nom).textContent);
const ecartSignale = () => !role('ecart-total').hidden;

const PROPOSITION = {
    date_document: '2026-10-08T14:32',
    reference: 'JP_T210002',
    cours_change: '0.94120000',
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

/** Ce que rend une lecture simulée : un texte seul, ou le détail des mots déjà construit ({@see lecture}). */
const lu = (texte) => (typeof texte === 'string' ? { text: texte } : texte);

/**
 * Une lecture au détail des mots, lue sur l'image réduite à `facteur` : chaque ligne est `[y, mots]`, chaque mot
 * `[texte, x0, x1, confiance = 95]` en coordonnées de l'image en taille réelle, haut de 20 px.
 */
function lecture(lignes, facteur = 1) {
    return {
        text: '',
        blocks: [{ paragraphs: [{ lines: lignes.map(([y, mots]) => ({
            text: mots.map(([texte]) => texte).join(' '),
            words: mots.map(([text, x0, x1, confidence = 95]) => ({
                text,
                confidence,
                bbox: { x0: x0 * facteur, y0: y * facteur, x1: x1 * facteur, y1: (y + 20) * facteur }
            }))
        })) }] }]
    };
}

/** Un Tesseract simulé : `createWorker` rend un worker qui lit `texte`, ou échoue avec `erreur`. */
function simulerTesseract({ texte = 'MIGROS Pain 3.50', echecLecture = null } = {}) {
    const worker = {
        setParameters: vi.fn(() => Promise.resolve()),
        // `texte` peut être une liste : une lecture par découpage, dans l'ordre.
        recognize: vi.fn(() => (echecLecture
            ? Promise.reject(echecLecture)
            : Promise.resolve({ data: lu(Array.isArray(texte) ? texte[worker.recognize.mock.calls.length - 1] : texte) }))),
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
    delete window.bootstrap;
    window.fetch = fetchOriginal;
});

describe('estImageAcceptee', () => {
    it('accepte JPEG, PNG et WebP, et rien d\'autre', () => {
        expect(budgetScan.estImageAcceptee({ type: 'image/png' })).toBe(true);
        expect(budgetScan.estImageAcceptee({ type: 'image/webp' })).toBe(true);
        expect(budgetScan.estImageAcceptee({ type: 'application/pdf' })).toBe(false);
    });
});

describe('choisirLecture', () => {
    it('compte les montants à deux décimales, et garde la première à égalité', () => {
        expect(budgetScan.choisirLecture(['Bleu S 2 45,00 2\nTotal 599,60', 'Bleu S = 4500'])).toBe('Bleu S 2 45,00 2\nTotal 599,60');
        expect(budgetScan.choisirLecture(['A 1.50', 'B 2,00'])).toBe('A 1.50');
        // « 1.159,70 » est un montant ; « 4950 » et « 12.505 » n'en sont pas.
        expect(budgetScan.choisirLecture(['x 4950 12.505', 'Robe 1.159,70'])).toBe('Robe 1.159,70');
        expect(budgetScan.choisirLecture(['sans montant', 'rien'])).toBe('sans montant');
    });
});

/** Un contexte 2D simulé, dont l'image est `pixels` (RVBA à plat). */
function contexteSimule(pixels = []) {
    const image = { data: Uint8ClampedArray.from(pixels) };

    return { filter: '', drawImage: vi.fn(), getImageData: vi.fn(() => image), putImageData: vi.fn(), image };
}

describe('preparerImage', () => {
    it('rend l\'image telle quelle quand le navigateur ne sait pas la décoder', async () => {
        const fichier = imageJpeg();

        await expect(budgetScan.preparerImage(fichier)).resolves.toBe(fichier);
    });

    it('la réduit, en efface l\'encre rouge, la passe en niveaux de gris contrastés, puis libère le bitmap', async () => {
        const bitmap = { width: 4800, height: 1200, close: vi.fn() };
        window.createImageBitmap = vi.fn(() => Promise.resolve(bitmap));
        // Un pixel d'encre rouge, un pixel brun d'une photo (rouge à peine dominant), un pixel noir.
        const couleur = contexteSimule([200, 40, 40, 255, 150, 110, 90, 255, 0, 0, 0, 255]);
        const gris = contexteSimule();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValueOnce(couleur).mockReturnValueOnce(gris);

        const canevas = await budgetScan.preparerImage(imageJpeg());

        expect(canevas.width).toBe(2400);
        expect(canevas.height).toBe(600);
        expect(couleur.drawImage).toHaveBeenCalledWith(bitmap, 0, 0, 2400, 600);
        expect(bitmap.close).toHaveBeenCalled();
        expect(couleur.getImageData).toHaveBeenCalledWith(0, 0, 2400, 600);
        expect([...couleur.image.data]).toEqual([255, 255, 255, 255, 150, 110, 90, 255, 0, 0, 0, 255]);
        expect(couleur.putImageData).toHaveBeenCalledWith(couleur.image, 0, 0);
        expect(gris.filter).toBe('grayscale(1) contrast(1.4)');
        // Le canevas en couleur, nettoyé, est recopié en gris sur un second canevas.
        const [source, x, y] = gris.drawImage.mock.calls[0];
        expect([source.width, source.height, x, y]).toEqual([2400, 600, 0, 0]);
        expect(source).not.toBe(canevas);
        vi.restoreAllMocks();
    });

    it('agrandit une image étroite jusqu\'à 1000 px de large, au plus trois fois — et laisse les autres', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => contexteSimule());
        const taille = async (width, height) => {
            window.createImageBitmap = vi.fn(() => Promise.resolve({ width, height, close() {} }));
            const canevas = await budgetScan.preparerImage(imageJpeg());

            return [canevas.width, canevas.height];
        };

        expect(await taille(500, 952)).toEqual([1000, 1904]);
        expect(await taille(200, 400)).toEqual([600, 1200]);
        expect(await taille(1200, 1600)).toEqual([1200, 1600]);
        vi.restoreAllMocks();
    });
});

describe('reduire', () => {
    it('rend l\'image préparée réduite à ce facteur', () => {
        const contexte = contexteSimule();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(contexte);
        const canevas = document.createElement('canvas');
        canevas.width = 1001;
        canevas.height = 2000;

        const reduite = budgetScan.reduire(canevas, 0.5);

        expect([reduite.width, reduite.height]).toEqual([501, 1000]);
        expect(contexte.drawImage).toHaveBeenCalledWith(canevas, 0, 0, 501, 1000);
        expect([budgetScan.reduire(canevas, 0.75).width, budgetScan.reduire(canevas, 0.75).height]).toEqual([751, 1500]);
        vi.restoreAllMocks();
    });
});

describe('texteMarque et nettoyerTexte', () => {
    const mot = (text, confidence) => ({ text, confidence });

    it('marque ⟦ ⟧ les mots à chiffres lus avec peu de confiance, en gardant l\'alignement', () => {
        const donnees = {
            text: 'ignoré',
            blocks: [
                { paragraphs: [{ lines: [
                    // Le « 2 » douteux est le code de TVA, pas le premier chiffre de « 225,00 ».
                    { text: 'Bracelet   1 225,00 2\n', words: [mot('Bracelet', 20), mot('1', 95), mot('225,00', 90), mot('2', 30)] },
                    { text: 'COSTUME     19£0 00', words: [mot('COSTUME', 90), mot('19£0', 44), mot('00', 61)] }
                ] }] },
                // Un mot introuvable dans sa ligne est ignoré.
                { paragraphs: [{ lines: [{ text: 'Total 5,00', words: [mot('Totel', 10), mot('5,00', 59)] }] }] }
            ]
        };

        expect(budgetScan.texteMarque(donnees)).toBe('Bracelet   1 225,00 ⟦2⟧\nCOSTUME     ⟦19£0⟧ 00\nTotal ⟦5,00⟧');
    });

    it('sans le détail des blocs, rend le texte tel quel', () => {
        expect(budgetScan.texteMarque({ text: 'TEXTE LU' })).toBe('TEXTE LU');
    });

    it('remplace le « ı » turc par « i », et laisse le « | » d\'un bord de colonne', () => {
        expect(budgetScan.nettoyerTexte('Nıssın Soba 1.80 |')).toBe('Nissin Soba 1.80 |');
    });

    it('rend « cl » l\'unité lue « c1 », « c! », « cI » ou « c| » après un nombre — et seulement là', () => {
        expect(budgetScan.nettoyerTexte('Fer 75c! 2008 · 33 ⟦c1⟧ · 5 cI · 7c|'))
            .toBe('Fer 75cl 2008 · 33 ⟦cl⟧ · 5 cl · 7cl');
        expect(budgetScan.nettoyerTexte('Bloc c1 · 5c12 · 4c1a')).toBe('Bloc c1 · 5c12 · 4c1a');
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
        // DEUX LECTURES, découpage 6 puis 4, sur la même image et le même worker, avec le détail des mots.
        expect(worker.setParameters.mock.calls).toEqual([
            [{ tessedit_pageseg_mode: '6', preserve_interword_spaces: '1' }],
            [{ tessedit_pageseg_mode: '4', preserve_interword_spaces: '1' }]
        ]);
        const sortie = { text: true, blocks: true };
        expect(worker.recognize.mock.calls).toEqual([['image', {}, sortie], ['image', {}, sortie]]);
        expect(worker.terminate).toHaveBeenCalledTimes(1);
        // La progression de la lecture s'affiche sous le voile ; les autres étapes de Tesseract, non.
        expect(role('etape-chargement').textContent).toBe('Lecture du reçu (1/2)… 42 %');
    });

    it('garde la lecture qui trouve le plus de montants, compactée', async () => {
        simulerTesseract({ texte: ['Pain\nLait 1,95', 'Pain          3,50\n\n  ***  \nLait 1,95   '] });

        // Ni ligne vide, ni ligne sans lettre ni chiffre, ni espaces de fin ; l'alignement réduit à trois espaces.
        await expect(budgetScan.reconnaitreTexte('image')).resolves.toBe('Pain   3,50\nLait 1,95');
    });

    /** Une image préparée — un canevas, que l'on peut réduire —, de 1000 × 2000 px. */
    function canevasPrepare() {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => contexteSimule());
        const canevas = document.createElement('canvas');
        canevas.width = 1000;
        canevas.height = 2000;

        return canevas;
    }

    it('une image préparée est lue cinq fois : deux en taille réelle, puis réduite à 0,5, 0,6 et 0,75 — et rend un seul texte', async () => {
        const worker = simulerTesseract({ texte: ['Pain 3,50 Nıssın', 'Pain', 'Total 3,50', 'Pain', 'Pain'] });

        await expect(budgetScan.reconnaitreTexte(canevasPrepare())).resolves.toBe('Pain 3,50 Nissin');

        expect(worker.setParameters.mock.calls.map(([parametres]) => parametres.tessedit_pageseg_mode)).toEqual(['6', '4', '4', '6', '6']);
        expect(worker.recognize.mock.calls.map(([image]) => image.width)).toEqual([1000, 1000, 500, 600, 750]);
        expect(role('etape-chargement').textContent).toBe('Lecture du reçu (1/5)… 42 %');
        vi.restoreAllMocks();
    });

    it('chaque mot de la lecture gardée passe au vote des quatre autres, lu au même endroit', async () => {
        simulerTesseract({ texte: [
            // La lecture gardée (découpage 6) : autant de montants que l'autre, elle passe la première.
            lecture([
                [0, [['Tandoor', 0, 100], ['285.00', 200, 300], ['23.00', 400, 500], ['Lait', 600, 700], ['1.159,70', 800, 900, 40]]],
                [100, [['16/07', 0, 100], ['Pain', 200, 300], ['88,006', 400, 560]]]
            ]),
            lecture([
                [0, [['Tandoori', 0, 100], ['295.00', 200, 300], ['23,00', 400, 500], ['Lalt', 600, 700], ['159,70', 800, 900]]],
                [100, [['18/07', 0, 100], ['Pain', 200, 300]]]
            ]),
            // Une lecture sans détail des mots ne vote pas.
            'Total 3,50',
            // Ces deux-là lisent « Lalt » trop étroit, et « 18/07 » une ligne plus bas : ces mots-là ne votent pas.
            lecture([
                [0, [['Tandoori', 0, 100], ['295.00', 200, 300], ['23,00', 400, 500], ['Lalt', 600, 640], ['159,70', 800, 900]]],
                [130, [['18/07', 0, 100]]],
                [100, [['85,00', 400, 500]]]
            ], 0.6),
            lecture([
                [0, [['Tandoori', 0, 100], ['295.00', 200, 300], ['23,00', 400, 500], ['Lalt', 600, 640], ['159,70', 800, 900]]],
                [160, [['18/07', 0, 100]]],
                [100, [['Pain', 200, 300], ['85.00', 400, 500]]]
            ], 0.75)
        ] });

        // « Tandoori » l'emporte (trois voix sur quatre). « 285.00 », bien lu, est contesté par d'autres chiffres : jamais
        // remplacé, il est marqué douteux ; « 1.159,70 », mal lu, prend la lecture majoritaire, et reste douteux. « 23,00 »
        // n'est que l'autre séparateur. « Lait » et « 16/07 » n'ont pas de majorité contre eux. « 85,00 » et « 85.00 »
        // votent ensemble, par leurs chiffres, contre « 88,006 » — plus large qu'eux, il les contient : deux voix sur trois.
        await expect(budgetScan.reconnaitreTexte(canevasPrepare())).resolves.toBe(
            'Tandoori ⟦285.00⟧ 23.00 Lait ⟦159,70⟧\n16/07 Pain 85,00'
        );
        vi.restoreAllMocks();
    });

    it('reprend, à sa place, une ligne perdue que deux autres lectures trouvent, dans sa meilleure version', async () => {
        simulerTesseract({ texte: [
            lecture([[0, [['Pain', 0, 100], ['3,50', 200, 300]]], [100, []], [200, [['Total', 0, 100], ['3,50', 200, 300]]]]),
            lecture([[0, [['Pain', 0, 100]]], [100, [['Lait', 0, 100], ['1,9S', 200, 300]]]]),
            // La meilleure version : un montant de plus que la précédente, une meilleure confiance que la suivante.
            lecture([
                [100, [['Lait', 0, 100], ['1,95', 200, 300]]], [400, [['Tache', 0, 100]]], [600, [['Merci', 0, 100]]],
                [800, [[';', 0, 20, 30], ['No', 40, 100, 40]]],
                // « Total » lu un peu plus bas que son montant : c'est la ligne « Total 3,50 », déjà lue.
                [212, [['Total', 0, 100]]]
            ], 0.5),
            // Deux versions dans la même lecture ne comptent que pour une.
            lecture([[100, [['Lalt', 0, 100, 50], ['1,95', 200, 300, 50]]], [105, [['1,95', 200, 300]]], [800, [[';', 0, 20, 20]]]], 0.6),
            lecture([[600, [['Merci', 0, 100]]], [212, [['Total', 0, 100]]]], 0.75)
        ] });

        // « Tache », qu'une seule lecture trouve, n'est pas reprise, ni « ; No », mal lu dans toutes ses versions ;
        // « Merci », sous la dernière ligne, l'est.
        await expect(budgetScan.reconnaitreTexte(canevasPrepare())).resolves.toBe('Pain 3,50\nLait 1,95\nTotal 3,50\nMerci');
        vi.restoreAllMocks();
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
        // La langue du drapeau choisi accompagne le texte, et le foyer choisi : le serveur propose son cours de change.
        expect(options.body.get('langue')).toBe('fra');
        expect(options.body.get('foyer_id')).toBe('3');
    });

    it('en mode debug, écrit en quatre lignes le texte envoyé au LLM et le JSON renvoyé, mis en forme', async () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        simulerServeur({ success: true, donnees: PROPOSITION, input: 'MIGROS 1,10', debug: { reponse_llm: '{"a":1}' } });

        await budgetScan.analyserTexte('MIGROS');

        expect(journal.mock.calls).toEqual([
            ['Texte brut envoyé au LLM :'],
            ['MIGROS 1,10'],
            ['JSON renvoyé par le LLM :'],
            ['{\n  "a": 1\n}']
        ]);

        // Un échec sans texte, au contenu illisible ou absent, est journalisé sans erreur.
        journal.mockClear();
        simulerServeur({ success: false, message: 'Échec.', debug: { reponse_llm: 'pas du JSON' } });
        await expect(budgetScan.analyserTexte('t')).rejects.toMatchObject({ message: 'Échec.' });
        expect(journal.mock.calls[1]).toEqual([undefined]);
        expect(journal.mock.calls[3]).toEqual(['pas du JSON']);

        journal.mockClear();
        simulerServeur({ success: true, donnees: PROPOSITION, input: 'ticket' });
        await budgetScan.analyserTexte('t');
        expect(journal.mock.calls[1]).toEqual(['ticket']);
        expect(journal.mock.calls[3]).toEqual(['undefined']);

        vi.restoreAllMocks();
    });

    it('hors debug, n\'écrit rien dans la console', async () => {
        const journal = vi.spyOn(console, 'log').mockImplementation(() => {});
        simulerServeur({ success: true, donnees: PROPOSITION });

        await budgetScan.analyserTexte('MIGROS');

        expect(journal).not.toHaveBeenCalled();
        vi.restoreAllMocks();
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
        expect(formulaire.querySelector('[name="date_document"]').value).toBe('2026-10-08T14:32');
        expect(formulaire.querySelector('[name="reference"]').value).toBe('JP_T210002');
        expect(formulaire.querySelector('[name="cours_change"]').value).toBe('0.94120000');
        expect(lignes()).toHaveLength(2);
        expect(champ(lignes()[1], 'nom').name).toBe('articles[1][nom]');
        expect(champ(lignes()[1], 'montant').value).toBe('1.10');
        expect(champ(lignes()[0], 'retenu').checked).toBe(true);
        expect(ecartSignale()).toBe(false);
        expect(lignes()[0].classList.contains('ligne-a-verifier')).toBe(false);
    });

    it('signale un montant déduit du total, faute d\'avoir été lu', () => {
        budgetScan.remplirFormulaire({ ...PROPOSITION, articles: [{ nom: 'Robe', montant: '159.70', monnaie: 'EUR', a_verifier: true }] });

        expect(lignes()[0].classList.contains('ligne-a-verifier')).toBe(true);
        expect(champ(lignes()[0], 'montant').title).toBe('Montant illisible sur le reçu, déduit du total imprimé : vérifiez-le.');
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

        // LES DEUX TOTAUX en pied des articles ; l'écart met le total calculé en couleur d'alerte, et se dit en dessous.
        expect(totaux()).toEqual(['4.60', 'CHF', '5.00', 'CHF']);
        expect(ecartSignale()).toBe(true);
        expect(role('ligne-total-calcule').classList.contains('table-warning')).toBe(true);

        // Une virgule décimale se lit ; une ligne décochée ne compte pas ; un montant illisible vaut zéro.
        champ(lignes()[0], 'montant').value = '3,90';
        champ(lignes()[1], 'retenu').checked = false;
        expect(budgetScan.verifierTotal()).toBe(true);
        champ(lignes()[1], 'retenu').checked = true;
        expect(budgetScan.verifierTotal()).toBe(false);
        champ(lignes()[1], 'montant').value = 'abc';
        expect(budgetScan.verifierTotal()).toBe(true);
        expect(totaux()[0]).toBe('3.90');
        // Les totaux égaux : plus de couleur, plus de message.
        champ(lignes()[1], 'montant').value = '1.10';
        expect(budgetScan.verifierTotal()).toBe(false);
        expect(role('ligne-total-calcule').classList.contains('table-warning')).toBe(false);
        expect(ecartSignale()).toBe(false);
    });

    it('ne compare rien sans total imprimé, ni quand deux monnaies se mêlent', () => {
        budgetScan.remplirFormulaire({ ...PROPOSITION, total_imprime: null });
        expect(budgetScan.verifierTotal()).toBe(false);
        expect(totaux()).toEqual(['4.60', 'CHF', '—', '']);

        // Deux monnaies : une somme par monnaie, et aucune monnaie au total affiché.
        budgetScan.remplirFormulaire({ ...PROPOSITION, articles: [{ nom: 'A', montant: '1', monnaie: 'CHF' }, { nom: 'B', montant: '1', monnaie: 'eur' }] });
        expect(budgetScan.verifierTotal()).toBe(false);
        expect(totaux()).toEqual(['1.00 CHF + 1.00 EUR', '', '4.60', '']);
        expect(ecartSignale()).toBe(false);

        // Aucune ligne cochée : un total calculé nul.
        champ(lignes()[0], 'retenu').checked = false;
        champ(lignes()[1], 'retenu').checked = false;
        budgetScan.verifierTotal();
        expect(totaux().slice(0, 2)).toEqual(['0.00', '']);
    });
});

describe('la ligne du cours de change', () => {
    const ligneCours = () => role('ligne-cours');
    const source = () => role('source-cours');
    const proposer = (articles, date = '2026-10-08T14:32') => budgetScan.remplirFormulaire({ ...PROPOSITION, date_document: date, articles });

    it('est cachée pour un reçu dans la monnaie du foyer', () => {
        monterPage();
        proposer([{ nom: 'Pain', montant: '3.50', monnaie: 'CHF' }]);

        expect(ligneCours().hidden).toBe(true);
    });

    it('montre « 1 EUR = [cours] CHF », et la demande faite au service des cours au jour du reçu', () => {
        monterPage();
        proposer([{ nom: 'Pain', montant: '3.50', monnaie: 'CHF' }, { nom: 'Café', montant: '2.00', monnaie: 'eur' }]);

        expect(ligneCours().hidden).toBe(false);
        expect(role('cours-monnaie-recu').value).toBe('EUR');
        expect(role('cours-monnaie-foyer').textContent).toBe('CHF');
        expect(role('formulaire-depense').querySelector('[name="cours_change"]').value).toBe('0.94120000');
        expect(source().href).toBe('https://cours.test/v1/2026-10-08?base=EUR&symbols=CHF');
        // Aucun title natif : l'infobulle est celle de Bootstrap.
        expect(source().hasAttribute('title')).toBe(false);
    });

    it('l\'infobulle Bootstrap s\'ouvre sans délai ni animation, et dit l\'adresse visée au moment où elle s\'ouvre', () => {
        const Tooltip = vi.fn();
        window.bootstrap = { Tooltip };
        monterPage();

        budgetScan.initBudgetScan();

        // L'icône du cours, puis les trois drapeaux.
        expect(Tooltip).toHaveBeenCalledTimes(4);
        const [cible, options] = Tooltip.mock.calls[0];
        expect(cible).toBe(source());
        expect(options).toMatchObject({ delay: 0, animation: false, customClass: 'infobulle-adresse' });
        proposer([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }]);
        expect(options.title()).toBe('https://cours.test/v1/2026-10-08?base=EUR&symbols=CHF');
        proposer([{ nom: 'Thé', montant: '2.00', monnaie: 'USD' }]);
        expect(options.title()).toBe('https://cours.test/v1/2026-10-08?base=USD&symbols=CHF');
    });

    it('chaque drapeau a son infobulle Bootstrap, immédiate : le nom de la langue, que porte son title', () => {
        const Tooltip = vi.fn();
        window.bootstrap = { Tooltip };
        monterPage();

        budgetScan.initBudgetScan();

        const drapeaux = Tooltip.mock.calls.slice(1);
        expect(drapeaux.map(([drapeau]) => drapeau.getAttribute('title'))).toEqual(['Français', 'Anglais', 'Allemand']);
        // Au survol seulement : au focus, elle resterait sur le drapeau cliqué.
        drapeaux.forEach(([, options]) => expect(options).toEqual({ delay: 0, animation: false, trigger: 'hover' }));
    });

    it('cliquer un drapeau ferme son infobulle — et sans infobulle, ou sans Bootstrap, le choix se fait quand même', () => {
        const hide = vi.fn();
        const Tooltip = vi.fn();
        Tooltip.getInstance = vi.fn((cible) => (cible.dataset.langue === 'eng' ? { hide } : null));
        window.bootstrap = { Tooltip };
        monterPage();
        budgetScan.initBudgetScan();

        document.querySelector('[data-langue="eng"] img').click();
        expect(hide).toHaveBeenCalledTimes(1);
        document.querySelector('[data-langue="deu"]').click();
        expect(hide).toHaveBeenCalledTimes(1);
        delete window.bootstrap;
        document.querySelector('[data-langue="fra"]').click();

        expect(budgetScan.langueChoisie()).toBe('fra');
    });

    it('sans Bootstrap, ou sans son infobulle, le lien reste un simple lien', () => {
        monterPage();
        expect(budgetScan.initInfobulleCours()).toBeNull();

        window.bootstrap = {};
        expect(budgetScan.initInfobulleCours()).toBeNull();
    });

    it('vise le dernier cours publié tant que le reçu n\'est pas daté', () => {
        monterPage();
        proposer([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }], '');

        expect(source().href).toBe('https://cours.test/v1/latest?base=EUR&symbols=CHF');
    });

    it('est cachée pour deux monnaies étrangères ; une ligne décochée ou sans monnaie ne compte pas', () => {
        monterPage();
        budgetScan.initBudgetScan();
        proposer([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }, { nom: 'Thé', montant: '3.00', monnaie: 'USD' }, { nom: 'Sac', montant: '1.00', monnaie: '' }]);
        expect(ligneCours().hidden).toBe(true);

        const caseThe = champ(lignes()[1], 'retenu');
        caseThe.checked = false;
        caseThe.dispatchEvent(new Event('change', { bubbles: true }));
        expect(ligneCours().hidden).toBe(false);
        expect(role('cours-monnaie-recu').value).toBe('EUR');
    });

    it('suit le foyer choisi dans la liste, et sa monnaie', () => {
        monterPage([], `<select name="foyer_id">
            <option value="3" data-monnaie="CHF">Maison (CHF)</option>
            <option value="4" data-monnaie="EUR" selected>Paris (EUR)</option>
            <option value="5">Sans monnaie</option>
        </select>`);
        budgetScan.initBudgetScan();
        proposer([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }]);
        expect(ligneCours().hidden).toBe(true);

        const foyer = role('formulaire-depense').querySelector('[name="foyer_id"]');
        foyer.value = '3';
        foyer.dispatchEvent(new Event('change', { bubbles: true }));
        expect(ligneCours().hidden).toBe(false);
        expect(role('cours-monnaie-foyer').textContent).toBe('CHF');

        foyer.value = '5';
        foyer.dispatchEvent(new Event('change', { bubbles: true }));
        expect(role('cours-monnaie-foyer').textContent).toBe('');

        // Une liste sans option : aucune monnaie de foyer.
        foyer.innerHTML = '';
        budgetScan.afficherCours();
        expect(role('cours-monnaie-foyer').textContent).toBe('');
    });

    it('une monnaie saisie sur la ligne du cours passe sur tous les articles, une fois ses trois lettres tapées', () => {
        monterPage();
        budgetScan.initBudgetScan();
        proposer([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }, { nom: 'Pain', montant: '3.50', monnaie: 'CHF' }]);
        const monnaie = role('cours-monnaie-recu');
        const saisir = (valeur) => {
            monnaie.value = valeur;
            monnaie.dispatchEvent(new Event('input', { bubbles: true }));
        };

        // Pendant la saisie, la ligne reste, et le champ n'est pas réécrit sous le curseur.
        monnaie.focus();
        saisir('U');
        expect(lignes().map((ligne) => champ(ligne, 'monnaie').value)).toEqual(['EUR', 'CHF']);
        expect(monnaie.value).toBe('U');
        expect(ligneCours().hidden).toBe(false);

        saisir('usd');
        expect(lignes().map((ligne) => champ(ligne, 'monnaie').value)).toEqual(['USD', 'USD']);
        expect(source().href).toBe('https://cours.test/v1/2026-10-08?base=USD&symbols=CHF');

        // La monnaie du foyer : plus rien à convertir, la ligne s'efface.
        saisir('CHF');
        expect(ligneCours().hidden).toBe(true);
    });

    it('une saisie refusée, rendue par le serveur, montre sa ligne de cours dès l\'ouverture', () => {
        monterPage([{ nom: 'Café', montant: '2.00', monnaie: 'EUR' }]);
        budgetScan.initBudgetScan();

        expect(ligneCours().hidden).toBe(false);
        expect(role('cours-monnaie-recu').value).toBe('EUR');
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

    it('une image préparée est lue deux fois, puis réduite pour la seconde lecture et les deux votantes', async () => {
        window.createImageBitmap = vi.fn(() => Promise.resolve({ width: 1200, height: 1600, close() {} }));
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => contexteSimule());
        const worker = simulerTesseract();
        simulerServeur({ success: true, donnees: PROPOSITION });

        await expect(budgetScan.traiterFichier(imageJpeg())).resolves.toBe(true);

        const images = worker.recognize.mock.calls.map(([image]) => [image.width, image.height]);
        expect(images).toEqual([[1200, 1600], [1200, 1600], [600, 800], [720, 960], [900, 1200]]);
        vi.restoreAllMocks();
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

    it('cocher, décocher et saisir recalculent le contrôle du total', () => {
        monterPage();
        budgetScan.initBudgetScan();
        budgetScan.remplirFormulaire({ ...PROPOSITION, total_imprime: '3.50' });
        expect(ecartSignale()).toBe(true);

        const caseLait = champ(lignes()[1], 'retenu');
        caseLait.checked = false;
        caseLait.dispatchEvent(new Event('change', { bubbles: true }));
        expect(champ(lignes()[1], 'nom').disabled).toBe(true);
        expect(ecartSignale()).toBe(false);

        caseLait.checked = true;
        caseLait.dispatchEvent(new Event('change', { bubbles: true }));
        expect(champ(lignes()[1], 'nom').disabled).toBe(false);
        expect(lignes()[1].classList.contains('ligne-ecartee')).toBe(false);
        expect(ecartSignale()).toBe(true);

        champ(lignes()[1], 'montant').value = '0';
        champ(lignes()[1], 'montant').dispatchEvent(new Event('input', { bubbles: true }));
        expect(ecartSignale()).toBe(false);

        champ(lignes()[0], 'montant').value = '1';
        champ(lignes()[0], 'montant').dispatchEvent(new Event('change', { bubbles: true }));
        expect(ecartSignale()).toBe(true);

        // UN AUTRE FOYER OU UNE AUTRE DATE : le cours proposé ne vaut plus, il est vidé. Un autre champ n'y touche pas.
        const formulaire = role('formulaire-depense');
        const cours = formulaire.querySelector('[name="cours_change"]');
        for (const nom of ['vendeur', 'foyer_id', 'date_document']) {
            cours.value = '0.9412';
            formulaire.querySelector(`[name="${nom}"]`).dispatchEvent(new Event('change', { bubbles: true }));
            expect(cours.value).toBe(nom === 'vendeur' ? '0.9412' : '');
        }

        // Un clic ailleurs dans le formulaire ne touche à rien.
        role('formulaire-depense').querySelector('[name="vendeur"]').click();
        expect(lignes()).toHaveLength(2);
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
