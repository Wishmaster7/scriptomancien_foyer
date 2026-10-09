import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * L'INSTALLATION DE L'APPLICATION SUR MOBILE (web/resources/js/app-mobile.js, servi sans
 * commentaire : son raisonnement est ici).
 *
 * Trois responsabilités distinctes :
 *  - révéler l'icône « Mobile App » sur un téléphone, hors de l'application installée
 *    (initApplicationMobile, estAppareilMobile) ;
 *  - déclencher l'installation au clic, par l'invitation du navigateur ou, à défaut, par un mode
 *    d'emploi (installerApplication) ;
 *  - dans l'application installée, garder la session active (poserCookieApplication) et rouvrir
 *    la dernière page vue plutôt que toujours l'accueil (reprendreDernierePage).
 */

const appMobile = globalThis.appMobile;

function definirNavigateur(proprietes) {
    Object.defineProperty(window.navigator, 'userAgent', {
        value: proprietes.userAgent ?? '',
        configurable: true
    });
    Object.defineProperty(window.navigator, 'platform', {
        value: proprietes.platform ?? '',
        configurable: true
    });
    Object.defineProperty(window.navigator, 'maxTouchPoints', {
        value: proprietes.maxTouchPoints ?? 0,
        configurable: true
    });
    if ('standalone' in proprietes) {
        Object.defineProperty(window.navigator, 'standalone', {
            value: proprietes.standalone,
            configurable: true
        });
    } else {
        delete window.navigator.standalone;
    }
}

beforeEach(() => {
    definirNavigateur({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' });
    document.cookie = 'app_installee=; path=/; max-age=0';
    window.localStorage.clear();
    window.sessionStorage.clear();
    // Le mock générique de matchMedia (tests/js/setup.js) répond toujours « vrai » par défaut, y
    // compris pour « display-mode: standalone » — faux pour cette suite, où « hors de
    // l'application » est le cas par défaut.
    window.matchMedia = (requete) => ({ media: String(requete), matches: false });
});

afterEach(() => {
    document.cookie = 'app_installee=; path=/; max-age=0';
});

describe('estAppareilApple', () => {
    it('reconnaît un iPhone à son user-agent', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' });

        expect(appMobile.estAppareilApple()).toBe(true);
    });

    it('reconnaît un iPad récent, déguisé en Macintosh mais tactile', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', platform: 'MacIntel', maxTouchPoints: 5 });

        expect(appMobile.estAppareilApple()).toBe(true);
    });

    it('ne prend pas un Mac de bureau pour un iPad', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', platform: 'MacIntel', maxTouchPoints: 0 });

        expect(appMobile.estAppareilApple()).toBe(false);
    });

    it('rend faux pour un ordinateur ordinaire', () => {
        expect(appMobile.estAppareilApple()).toBe(false);
    });
});

describe('estAppareilMobile', () => {
    it('reconnaît un Android à son user-agent', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (Linux; Android 14)' });

        expect(appMobile.estAppareilMobile()).toBe(true);
    });

    it('reconnaît un appareil Apple comme mobile', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' });

        expect(appMobile.estAppareilMobile()).toBe(true);
    });

    it('rend faux pour un ordinateur de bureau', () => {
        expect(appMobile.estAppareilMobile()).toBe(false);
    });
});

describe('estApplicationInstallee', () => {
    it('est vraie quand navigator.standalone répond vrai (Safari iOS)', () => {
        definirNavigateur({ standalone: true });

        expect(appMobile.estApplicationInstallee()).toBe(true);
    });

    it('est vraie quand le mode plein écran est détecté par matchMedia', () => {
        const original = window.matchMedia;
        window.matchMedia = (requete) => ({ media: requete, matches: requete === '(display-mode: standalone)' });

        try {
            expect(appMobile.estApplicationInstallee()).toBe(true);
        } finally {
            window.matchMedia = original;
        }
    });

    it('est fausse dans un onglet ordinaire', () => {
        expect(appMobile.estApplicationInstallee()).toBe(false);
    });
});

describe('poserCookieApplication', () => {
    it('pose le cookie une première fois et rend vrai', () => {
        expect(appMobile.poserCookieApplication()).toBe(true);
        expect(document.cookie).toContain('app_installee=1');
    });

    it('ne repose pas un cookie déjà présent, et rend faux', () => {
        document.cookie = 'app_installee=1; path=/';

        expect(appMobile.poserCookieApplication()).toBe(false);
    });
});

describe('marquerApplicationInstallee', () => {
    it('pose le cookie et recharge la page quand il ne l\'était pas', () => {
        const reload = vi.fn();
        Object.defineProperty(window, 'location', { value: { ...window.location, reload }, configurable: true });

        expect(appMobile.marquerApplicationInstallee()).toBe(true);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('ne recharge pas si le cookie était déjà posé', () => {
        document.cookie = 'app_installee=1; path=/';
        const reload = vi.fn();
        Object.defineProperty(window, 'location', { value: { ...window.location, reload }, configurable: true });

        expect(appMobile.marquerApplicationInstallee()).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });
});

describe('reprendreDernierePage', () => {
    it('reprend la dernière page vue au lancement sur l\'adresse de départ', () => {
        window.localStorage.setItem('derniere_page', '/?action=profil');
        const replace = vi.fn();
        Object.defineProperty(window, 'location', {
            value: { ...window.location, pathname: '/', search: '', replace },
            configurable: true
        });

        expect(appMobile.reprendreDernierePage()).toBe(true);
        expect(replace).toHaveBeenCalledWith('/?action=profil');
    });

    it('ne reprend rien si la dernière page est la même que l\'adresse de départ', () => {
        window.localStorage.setItem('derniere_page', '/');
        Object.defineProperty(window, 'location', {
            value: { ...window.location, pathname: '/', search: '' },
            configurable: true
        });

        expect(appMobile.reprendreDernierePage()).toBe(false);
    });

    it('ne reprend rien hors de l\'adresse de départ, et mémorise la page courante', () => {
        Object.defineProperty(window, 'location', {
            value: { ...window.location, pathname: '/', search: '?action=profil' },
            configurable: true
        });

        expect(appMobile.reprendreDernierePage()).toBe(false);
        expect(window.localStorage.getItem('derniere_page')).toBe('/?action=profil');
    });

    it('ne reprend rien au second appel de la même vie de session (lancement déjà traité)', () => {
        window.sessionStorage.setItem('lancement_traite', '1');
        window.localStorage.setItem('derniere_page', '/?action=profil');
        Object.defineProperty(window, 'location', {
            value: { ...window.location, pathname: '/', search: '' },
            configurable: true
        });

        expect(appMobile.reprendreDernierePage()).toBe(false);
    });

    it('rend faux sans lever quand les stockages sont inaccessibles', () => {
        const espion = vi.spyOn(window.sessionStorage.__proto__, 'getItem').mockImplementation(() => {
            throw new Error('Stockage refusé');
        });

        try {
            expect(appMobile.reprendreDernierePage()).toBe(false);
        } finally {
            espion.mockRestore();
        }
    });
});

describe('initApplicationMobile', () => {
    it('rend « ordinateur » et ne révèle rien sur un ordinateur de bureau', () => {
        document.body.innerHTML = '<a data-role="app-mobile" hidden></a>';

        expect(appMobile.initApplicationMobile()).toBe('ordinateur');
        expect(document.querySelector('[data-role="app-mobile"]').hidden).toBe(true);
    });

    it('révèle les entrées et arme leur clic sur un téléphone, hors de l\'application', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (Linux; Android 14)' });
        document.body.innerHTML = `
            <a data-role="app-mobile" data-action="installer-app" hidden></a>
            <ol data-role="app-mobile-ios" hidden></ol>
            <ol data-role="app-mobile-autre" hidden></ol>
            <div data-role="modal-app-mobile"></div>
        `;

        expect(appMobile.initApplicationMobile()).toBe('telephone');
        document.querySelectorAll('[data-role="app-mobile"]').forEach((entree) => {
            expect(entree.hidden).toBe(false);
        });

        // Le clic ne suit pas le lien (comportement par défaut empêché) ; installerApplication()
        // lui-même est vérifié indépendamment plus bas.
        const lien = document.querySelector('[data-action="installer-app"]');
        const evenement = new MouseEvent('click', { bubbles: true, cancelable: true });
        lien.dispatchEvent(evenement);
        expect(evenement.defaultPrevented).toBe(true);
    });

    it('pose le cookie et recharge au premier lancement dans l\'application installée', () => {
        definirNavigateur({ standalone: true });
        const reload = vi.fn();
        Object.defineProperty(window, 'location', { value: { ...window.location, reload }, configurable: true });

        expect(appMobile.initApplicationMobile()).toBe('installee');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('reprend la dernière page dans l\'application installée quand le cookie est déjà posé', () => {
        definirNavigateur({ standalone: true });
        document.cookie = 'app_installee=1; path=/';
        window.localStorage.setItem('derniere_page', '/?action=profil');
        const replace = vi.fn();
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            value: { ...window.location, pathname: '/', search: '', replace, reload },
            configurable: true
        });

        expect(appMobile.initApplicationMobile()).toBe('installee');
        expect(reload).not.toHaveBeenCalled();
        expect(replace).toHaveBeenCalledWith('/?action=profil');
    });
});

describe('installerApplication', () => {
    it('utilise l\'invitation du navigateur quand beforeinstallprompt a été capté', () => {
        const prompt = vi.fn();
        window.dispatchEvent(Object.assign(new Event('beforeinstallprompt', { cancelable: true }), { prompt }));

        appMobile.installerApplication();

        expect(prompt).toHaveBeenCalledTimes(1);
        expect(document.cookie).toContain('app_installee=1');
    });

    it('ne rejoue pas une invitation déjà consommée : un second appel retombe sur le mode d\'emploi', () => {
        const prompt = vi.fn();
        window.dispatchEvent(Object.assign(new Event('beforeinstallprompt', { cancelable: true }), { prompt }));
        document.body.innerHTML = `
            <ol data-role="app-mobile-ios" hidden></ol>
            <ol data-role="app-mobile-autre" hidden></ol>
            <div data-role="modal-app-mobile"></div>
        `;

        appMobile.installerApplication();
        appMobile.installerApplication();

        expect(prompt).toHaveBeenCalledTimes(1);
    });

    it('affiche le mode d\'emploi iOS sur un appareil Apple sans invitation', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' });
        document.body.innerHTML = `
            <ol data-role="app-mobile-ios" hidden></ol>
            <ol data-role="app-mobile-autre" hidden></ol>
            <div data-role="modal-app-mobile"></div>
        `;

        appMobile.installerApplication();

        expect(document.querySelector('[data-role="app-mobile-ios"]').hidden).toBe(false);
        expect(document.querySelector('[data-role="app-mobile-autre"]').hidden).toBe(true);
        expect(document.querySelector('[data-role="modal-app-mobile"]').classList.contains('show')).toBe(true);
    });

    it('affiche le mode d\'emploi générique ailleurs sans invitation', () => {
        definirNavigateur({ userAgent: 'Mozilla/5.0 (Linux; Android 14)' });
        document.body.innerHTML = `
            <ol data-role="app-mobile-ios" hidden></ol>
            <ol data-role="app-mobile-autre" hidden></ol>
            <div data-role="modal-app-mobile"></div>
        `;

        appMobile.installerApplication();

        expect(document.querySelector('[data-role="app-mobile-ios"]').hidden).toBe(true);
        expect(document.querySelector('[data-role="app-mobile-autre"]').hidden).toBe(false);
    });
});
