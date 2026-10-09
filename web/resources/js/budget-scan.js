var budgetScan = (function () {
    var TYPES_ACCEPTES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Plus grand côté de l'image remise à l'OCR : au-delà, la lecture ralentit sans gagner en précision. */
    var DIMENSION_MAX = 2400;

    var MESSAGE_ECHEC = "L'analyse du reçu a échoué. Réessayez, ou saisissez le reçu à la main.";
    var CHAMPS_ENTETE = ['date_document', 'numero_tva', 'vendeur', 'lieu', 'description'];

    var compteur = 0;
    var totalImprime = null;

    function element(role) {
        return document.querySelector('[data-role="' + role + '"]');
    }

    function configuration() {
        return document.getElementById('page-budget-scan').dataset;
    }

    function urlAbsolue(chemin) {
        return new URL(chemin, window.location.href).href;
    }

    /** Le plus petit module WebAssembly qui emploie une instruction SIMD : le navigateur ne le valide que s'il les connaît. */
    var MODULE_SIMD = new Uint8Array([0, 97, 115, 109, 1, 0, 0, 0, 1, 5, 1, 96, 0, 1, 123, 3, 2, 1, 0, 10, 10, 1, 8, 0, 65, 0, 253, 15, 253, 98, 11]);

    /**
     * Le moteur de Tesseract, CHOISI ICI et non par Tesseract.js : laissé à lui-même, il prend dans Chrome le moteur
     * « relaxed SIMD », qui s'arrête net sur un modèle « best » complet (calcul en flottants, fonction DotProductSSE absente).
     */
    function moteurTesseract(repertoire) {
        return repertoire + (WebAssembly.validate(MODULE_SIMD) ? '/tesseract-core-simd-lstm.wasm.js' : '/tesseract-core-lstm.wasm.js');
    }

    /** Une erreur dont le message est écrit pour la personne ; toute autre est remplacée par MESSAGE_ECHEC. */
    function erreurAffichable(message) {
        var erreur = new Error(message);
        erreur.affichable = true;

        return erreur;
    }

    function estImageAcceptee(fichier) {
        return TYPES_ACCEPTES.indexOf(fichier.type) !== -1;
    }

    function afficherChargement(visible, etape) {
        element('voile-chargement').hidden = !visible;
        element('etape-chargement').textContent = etape || '';
        document.body.setAttribute('aria-busy', visible ? 'true' : 'false');
    }

    function afficherErreur(message) {
        var zone = element('erreur-scan');
        zone.textContent = message;
        zone.hidden = message === '';
    }

    /**
     * Réduit l'image et la passe en niveaux de gris contrastés : Tesseract lit mieux un reçu sans couleur. Sans
     * `createImageBitmap`, l'image part telle quelle.
     */
    function preparerImage(fichier) {
        if (typeof window.createImageBitmap !== 'function') {
            return Promise.resolve(fichier);
        }

        return window.createImageBitmap(fichier).then(function (image) {
            var echelle = Math.min(1, DIMENSION_MAX / Math.max(image.width, image.height));
            var canevas = document.createElement('canvas');
            canevas.width = Math.round(image.width * echelle);
            canevas.height = Math.round(image.height * echelle);
            var contexte = canevas.getContext('2d');
            contexte.filter = 'grayscale(1) contrast(1.4)';
            contexte.drawImage(image, 0, 0, canevas.width, canevas.height);
            image.close();

            return canevas;
        });
    }

    /** Le code Tesseract de la langue du reçu : celle du drapeau choisi, le français sans drapeau. */
    function langueChoisie() {
        var choisi = document.querySelector('[data-role="langues"] [aria-pressed="true"]');

        return choisi === null ? 'fra' : choisi.dataset.langue;
    }

    /** Choisit une langue : son drapeau seul est enfoncé. */
    function choisirLangue(code) {
        document.querySelectorAll('[data-role="langues"] [data-langue]').forEach(function (drapeau) {
            drapeau.setAttribute('aria-pressed', drapeau.dataset.langue === code ? 'true' : 'false');
        });
    }

    /**
     * Lit le texte de l'image, dans le navigateur, dans la langue choisie. Les fichiers de Tesseract sont servis par le site ; le modèle de langue
     * est gardé par Tesseract dans le stockage du navigateur, et n'est téléchargé qu'une fois.
     *
     * LA CLÉ DE CE STOCKAGE EST LE RÉPERTOIRE DU MODÈLE (cachePath) : par défaut, Tesseract range tout sous « ./fra.traineddata »,
     * et un navigateur garderait l'ancien modèle après qu'on en a changé.
     */
    function reconnaitreTexte(image) {
        var config = configuration();
        var langues = urlAbsolue(config.tesseractLangues);

        return window.Tesseract.createWorker(langueChoisie(),window.Tesseract.OEM.LSTM_ONLY, {
            workerPath: urlAbsolue(config.tesseractWorker),
            corePath: urlAbsolue(moteurTesseract(config.tesseractCore)),
            langPath: langues,
            cachePath: langues,
            logger: function (message) {
                if (message.status === 'recognizing text') {
                    afficherChargement(true, 'Lecture du reçu… ' + Math.round(message.progress * 100) + ' %');
                }
            }
        }).then(function (worker) {
            // Mode 4 : une seule colonne de texte de tailles variables, la forme d'un ticket. Les espaces conservés
            // gardent l'alignement des prix.
            return worker.setParameters({ tessedit_pageseg_mode: '4', preserve_interword_spaces: '1' })
                .then(function () {
                    return worker.recognize(image);
                })
                .then(function (resultat) {
                    return resultat.data.text;
                })
                .finally(function () {
                    return worker.terminate();
                });
        });
    }

    /** Fait structurer le texte par le serveur, qui interroge le LLM puis valide sa réponse. */
    function analyserTexte(texte) {
        var donnees = new FormData();
        donnees.append('csrf_token', element('formulaire-depense').querySelector('[name="csrf_token"]').value);
        donnees.append('texte', texte);

        return window.fetch(urlAbsolue(configuration().urlAnalyse), {
            method: 'POST',
            body: donnees,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (reponse) {
            return reponse.json().catch(function () {
                throw erreurAffichable(MESSAGE_ECHEC);
            });
        }).then(function (resultat) {
            if (!resultat.success) {
                throw erreurAffichable(resultat.message || MESSAGE_ECHEC);
            }

            return resultat.donnees;
        });
    }

    /** Ajoute une ligne d'article, recopiée du <template> que le serveur a rendu. */
    function ajouterLigne(article) {
        var corps = element('lignes-articles');
        corps.insertAdjacentHTML('beforeend', element('modele-article').innerHTML.split('__INDEX__').join(String(compteur)));
        compteur += 1;

        var ligne = corps.lastElementChild;
        ligne.querySelector('[data-champ="nom"]').value = article.nom;
        ligne.querySelector('[data-champ="montant"]').value = article.montant;
        ligne.querySelector('[data-champ="monnaie"]').value = article.monnaie;

        return ligne;
    }

    function articleVide() {
        return { nom: '', montant: '', monnaie: configuration().monnaieDefaut };
    }

    /** Remplit le formulaire de relecture avec une proposition d'analyse, et le révèle. Toutes les lignes sont cochées. */
    function remplirFormulaire(donnees) {
        var formulaire = element('formulaire-depense');
        CHAMPS_ENTETE.forEach(function (champ) {
            formulaire.querySelector('[name="' + champ + '"]').value = donnees[champ] || '';
        });

        element('lignes-articles').innerHTML = '';
        compteur = 0;
        (donnees.articles.length > 0 ? donnees.articles : [articleVide()]).forEach(ajouterLigne);

        totalImprime = donnees.total_imprime;
        formulaire.hidden = false;
        verifierTotal();
    }

    function saisieManuelle() {
        afficherErreur('');
        remplirFormulaire({ total_imprime: null, articles: [] });
    }

    /** Une ligne décochée est grisée, et ses champs désactivés : ils ne sont ni validés ni envoyés. */
    function basculerLigne(caseACocher) {
        var ligne = caseACocher.closest('[data-role="ligne-article"]');
        ligne.querySelectorAll('[data-champ="nom"], [data-champ="montant"], [data-champ="monnaie"]').forEach(function (champ) {
            champ.disabled = !caseACocher.checked;
        });
        ligne.classList.toggle('ligne-ecartee', !caseACocher.checked);
    }

    /**
     * Compare la somme des lignes cochées au total imprimé sur le reçu, en centimes. L'alerte ne vaut que pour une seule
     * monnaie : des CHF et des EUR ne s'additionnent pas.
     *
     * @returns {boolean} Vrai si un écart est signalé.
     */
    function verifierTotal() {
        var sommes = {};
        element('lignes-articles').querySelectorAll('[data-role="ligne-article"]').forEach(function (ligne) {
            if (!ligne.querySelector('[data-champ="retenu"]').checked) {
                return;
            }
            var montant = Number(ligne.querySelector('[data-champ="montant"]').value.replace(',', '.'));
            var monnaie = ligne.querySelector('[data-champ="monnaie"]').value.toUpperCase();
            sommes[monnaie] = (sommes[monnaie] || 0) + (Number.isNaN(montant) ? 0 : Math.round(montant * 100));
        });

        var monnaies = Object.keys(sommes);
        var ecart = totalImprime !== null && monnaies.length === 1
            && sommes[monnaies[0]] !== Math.round(Number(totalImprime) * 100);

        var alerte = element('alerte-total');
        alerte.hidden = !ecart;
        alerte.textContent = ecart
            ? 'La somme des articles retenus (' + (sommes[monnaies[0]] / 100).toFixed(2) + ' ' + monnaies[0]
                + ') ne correspond pas au total imprimé sur le reçu (' + Number(totalImprime).toFixed(2) + ' ' + monnaies[0]
                + '). Vérifiez les lignes.'
            : '';

        return ecart;
    }

    /**
     * De l'image au formulaire : préparation, lecture, analyse. Le voile bloque la page jusqu'au bout ; un échec ouvre le
     * formulaire vide, pour une saisie à la main.
     *
     * @returns {Promise<boolean>} Vrai si le formulaire a été rempli par l'analyse.
     */
    function traiterFichier(fichier) {
        afficherErreur('');
        if (!estImageAcceptee(fichier)) {
            afficherErreur("Ce fichier n'est pas une image JPEG, PNG ou WebP.");

            return Promise.resolve(false);
        }

        afficherChargement(true, "Préparation de l'image…");

        return preparerImage(fichier)
            .then(reconnaitreTexte)
            .then(function (texte) {
                if (texte.trim() === '') {
                    throw erreurAffichable("Aucun texte n'a été reconnu sur l'image. Reprenez la photo, bien à plat et bien éclairée.");
                }
                afficherChargement(true, 'Analyse du reçu…');

                return analyserTexte(texte);
            })
            .then(function (donnees) {
                remplirFormulaire(donnees);

                return true;
            })
            .catch(function (erreur) {
                saisieManuelle();
                afficherErreur(erreur && erreur.affichable ? erreur.message : MESSAGE_ECHEC);

                return false;
            })
            .finally(function () {
                afficherChargement(false);
            });
    }

    function initBudgetScan() {
        var zone = element('zone-depot');
        if (zone === null) {
            return false;
        }

        var formulaire = element('formulaire-depense');
        var lignes = element('lignes-articles');
        compteur = lignes.querySelectorAll('[data-role="ligne-article"]').length;
        totalImprime = null;
        lignes.querySelectorAll('[data-champ="retenu"]').forEach(basculerLigne);

        element('langues').addEventListener('click', function (evenement) {
            var drapeau = evenement.target.closest('[data-langue]');
            if (drapeau !== null) {
                choisirLangue(drapeau.dataset.langue);
            }
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (evenement) {
                evenement.preventDefault();
                zone.classList.add('zone-depot-survol');
            });
        });
        zone.addEventListener('dragleave', function () {
            zone.classList.remove('zone-depot-survol');
        });
        zone.addEventListener('drop', function (evenement) {
            evenement.preventDefault();
            zone.classList.remove('zone-depot-survol');
            if (evenement.dataTransfer.files.length > 0) {
                traiterFichier(evenement.dataTransfer.files[0]);
            }
        });

        document.querySelectorAll('[data-role="fichier-photo"], [data-role="fichier-image"]').forEach(function (champ) {
            champ.addEventListener('change', function () {
                var fichier = champ.files[0];
                // Vidé, pour qu'une même photo choisie deux fois de suite déclenche encore l'analyse.
                champ.value = '';
                if (fichier) {
                    traiterFichier(fichier);
                }
            });
        });

        formulaire.addEventListener('click', function (evenement) {
            if (evenement.target.closest('[data-action="ajouter-article"]')) {
                ajouterLigne(articleVide()).querySelector('[data-champ="nom"]').focus();
            }
            var retirer = evenement.target.closest('[data-action="retirer-article"]');
            if (retirer) {
                retirer.closest('[data-role="ligne-article"]').remove();
                verifierTotal();
            }
        });
        formulaire.addEventListener('change', function (evenement) {
            if (evenement.target.matches('[data-champ="retenu"]')) {
                basculerLigne(evenement.target);
            }
            verifierTotal();
        });
        formulaire.addEventListener('input', verifierTotal);

        return true;
    }

    document.addEventListener('DOMContentLoaded', initBudgetScan);

    return {
        estImageAcceptee: estImageAcceptee,
        langueChoisie: langueChoisie,
        choisirLangue: choisirLangue,
        preparerImage: preparerImage,
        reconnaitreTexte: reconnaitreTexte,
        analyserTexte: analyserTexte,
        remplirFormulaire: remplirFormulaire,
        saisieManuelle: saisieManuelle,
        verifierTotal: verifierTotal,
        traiterFichier: traiterFichier,
        initBudgetScan: initBudgetScan
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = budgetScan;
}
