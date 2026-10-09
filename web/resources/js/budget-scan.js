var budgetScan = (function () {
    var TYPES_ACCEPTES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Plus grand côté de l'image remise à l'OCR : au-delà, la lecture ralentit sans gagner en précision. */
    var DIMENSION_MAX = 2400;

    /**
     * Largeur minimale de l'image remise à l'OCR : une image plus étroite (capture d'écran, ticket de 500 px) est
     * AGRANDIE jusqu'à elle, au plus trois fois. Ses caractères trop petits faisaient perdre à Tesseract des lignes
     * entières (ticket MyKomela, tests/e2e).
     */
    var LARGEUR_MIN = 1000;

    /**
     * Les deux découpages essayés : 6 lit la page comme un bloc de lignes, 4 comme une colonne de tailles variables.
     * Sur un ticket, chacun perd parfois une ligne que l'autre trouve ; la lecture qui compte le plus de montants
     * est gardée ({@see choisirLecture}).
     */
    var DECOUPAGES = ['6', '4'];

    /**
     * Les lectures RÉDUITES, chacune `[facteur, découpage]` sur l'image préparée. Tesseract ne confond pas les mêmes
     * caractères à chaque taille, et ne perd pas les mêmes lignes : la moitié lit mieux les gros caractères (« Total 45,80 »
     * en double hauteur, ticket T21) et les tickets trop agrandis (Liquor Street). Elles ne partent pas au LLM : chaque
     * mot de la lecture gardée passe à leur vote ({@see voter}) — « 23.05.2009 » y redevient « 28.05.2009 », « Tandoor »
     * « Tandoori » —, et la lecture gardée reprend les lignes qu'elle a perdues et qu'elles trouvent ({@see lignesPerdues}).
     */
    var REDUCTIONS = [[0.5, '4'], [0.6, '6'], [0.75, '6']];

    /**
     * Nombre de lectures, hors la lecture gardée, qui doivent trouver une ligne qu'elle a perdue pour qu'elle la reprenne :
     * une seule lecture qui trouve une ligne lit le plus souvent une tache ou un bord de papier.
     */
    var LECTURES_MIN_LIGNE_PERDUE = 2;

    /**
     * Au-delà de ces espaces, une suite d'espaces est raccourcie à ce nombre : ils gardent la colonne du prix visible
     * sans que des dizaines d'espaces d'alignement coûtent des jetons au LLM.
     */
    var ESPACES_MAX = 3;

    /**
     * Part de largeur commune (sur la largeur réunie) au-delà de laquelle deux mots de deux lectures sont le même mot
     * imprimé. Plus bas, « C.V.EMANUELE » était remplacé par le seul « EMANUELE » d'une lecture qui l'avait coupé.
     */
    var RECOUVREMENT_MIN = 0.85;

    /** Sous cette confiance (sur 100), un mot qui porte un chiffre est marqué ⟦douteux⟧ pour le LLM. */
    var CONFIANCE_MIN = 60;

    /**
     * L'avance du rouge sur le vert et le bleu au-delà de laquelle un pixel est de l'ENCRE ROUGE (un stylo qui entoure
     * une ligne) et devient blanc : Tesseract perdait la ligne entourée (« S.Tax », ticket Liquor Street).
     */
    var ROUGE_MIN = 50;

    /** Un montant imprimé : « 45,00 », « 1'234.50 », « 159,70 ». */
    var MONTANT = /\d[,.]\d{2}(?!\d)/g;

    /** Un mot qui n'est qu'un montant, signe ou monnaie collés compris : « -49.00 », « 1,035.00 », « 2,50f ». */
    var MONTANT_SEUL = /^\D*\d[\d.,']*[.,]\d{2}\D*$/;

    var MESSAGE_ECHEC = "L'analyse du reçu a échoué. Réessayez, ou saisissez le reçu à la main.";
    var MESSAGE_A_VERIFIER = 'Montant illisible sur le reçu, déduit du total imprimé : vérifiez-le.';
    var CHAMPS_ENTETE = ['date_document', 'reference', 'cours_change', 'numero_tva', 'vendeur', 'lieu', 'description'];

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
     * Met l'image à la taille que Tesseract lit le mieux — agrandie si elle est étroite, réduite si elle est grande —,
     * en efface l'encre rouge, et la passe en niveaux de gris contrastés : Tesseract lit mieux un reçu sans couleur.
     * Sans `createImageBitmap`, l'image part telle quelle.
     *
     * Un seuillage adaptatif (Sauvola, normalisation locale) a été essayé à la place du contraste : il ne gagnait rien
     * sur les tickets de tests/e2e, et en perdait sur les photos.
     */
    function preparerImage(fichier) {
        if (typeof window.createImageBitmap !== 'function') {
            return Promise.resolve(fichier);
        }

        return window.createImageBitmap(fichier).then(function (image) {
            var echelle = image.width < LARGEUR_MIN
                ? Math.min(3, LARGEUR_MIN / image.width)
                : Math.min(1, DIMENSION_MAX / Math.max(image.width, image.height));
            var couleur = document.createElement('canvas');
            couleur.width = Math.round(image.width * echelle);
            couleur.height = Math.round(image.height * echelle);
            var contexteCouleur = couleur.getContext('2d', { willReadFrequently: true });
            contexteCouleur.drawImage(image, 0, 0, couleur.width, couleur.height);
            image.close();
            effacerEncreRouge(contexteCouleur, couleur.width, couleur.height);

            var canevas = document.createElement('canvas');
            canevas.width = couleur.width;
            canevas.height = couleur.height;
            var contexte = canevas.getContext('2d');
            contexte.filter = 'grayscale(1) contrast(1.4)';
            contexte.drawImage(couleur, 0, 0);

            return canevas;
        });
    }

    /** Blanchit chaque pixel nettement plus rouge que vert et bleu. */
    function effacerEncreRouge(contexte, largeur, hauteur) {
        var image = contexte.getImageData(0, 0, largeur, hauteur);
        var pixels = image.data;
        for (var i = 0; i < pixels.length; i += 4) {
            if (pixels[i] - Math.max(pixels[i + 1], pixels[i + 2]) > ROUGE_MIN) {
                pixels[i] = 255;
                pixels[i + 1] = 255;
                pixels[i + 2] = 255;
            }
        }
        contexte.putImageData(image, 0, 0);
    }

    /** L'image préparée (un canevas), réduite à ce facteur. */
    function reduire(image, facteur) {
        var reduite = document.createElement('canvas');
        reduite.width = Math.round(image.width * facteur);
        reduite.height = Math.round(image.height * facteur);
        reduite.getContext('2d').drawImage(image, 0, 0, reduite.width, reduite.height);

        return reduite;
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
     *
     * Une image préparée (un canevas) est lue cinq fois : deux découpages en taille réelle, puis les lectures réduites.
     * Rend UN SEUL TEXTE, décidé ici et non par le LLM ({@see assemblerLectures}). Une image restée fichier n'est lue que
     * deux fois.
     */
    function reconnaitreTexte(image) {
        var config = configuration();
        var langues = urlAbsolue(config.tesseractLangues);
        var passes = DECOUPAGES.map(function (decoupage) {
            return { image: image, decoupage: decoupage, facteur: 1 };
        });
        if (image instanceof HTMLCanvasElement) {
            REDUCTIONS.forEach(function (reduction) {
                passes.push({ image: reduire(image, reduction[0]), decoupage: reduction[1], facteur: reduction[0] });
            });
        }
        var lectures = [];

        return window.Tesseract.createWorker(langueChoisie(), window.Tesseract.OEM.LSTM_ONLY, {
            workerPath: urlAbsolue(config.tesseractWorker),
            corePath: urlAbsolue(moteurTesseract(config.tesseractCore)),
            langPath: langues,
            cachePath: langues,
            logger: function (message) {
                if (message.status === 'recognizing text') {
                    afficherChargement(true, 'Lecture du reçu (' + (lectures.length + 1) + '/' + passes.length + ')… '
                        + Math.round(message.progress * 100) + ' %');
                }
            }
        }).then(function (worker) {
            // Une lecture par passe, l'une après l'autre sur le même worker. Les espaces conservés gardent l'alignement
            // des prix ; les blocs donnent la confiance de chaque mot.
            return passes.reduce(function (precedente, passe) {
                return precedente.then(function () {
                    return worker.setParameters({ tessedit_pageseg_mode: passe.decoupage, preserve_interword_spaces: '1' });
                }).then(function () {
                    return worker.recognize(passe.image, {}, { text: true, blocks: true });
                }).then(function (resultat) {
                    lectures.push({ donnees: resultat.data, facteur: passe.facteur });
                });
            }, Promise.resolve())
                .then(function () {
                    return nettoyerTexte(assemblerLectures(lectures));
                })
                .finally(function () {
                    return worker.terminate();
                });
        });
    }

    /**
     * LE SEUL TEXTE ENVOYÉ AU LLM, décidé ici : la meilleure lecture en taille réelle ({@see choisirLecture}), chaque mot
     * passé au vote des autres lectures, complétée à leur place des lignes qu'elle a perdues ({@see lignesPerdues}), puis
     * compactée ({@see compacter}). Les lectures entières, envoyées côte à côte, doublaient le texte — et les jetons du LLM.
     * Une lecture gardée sans le détail des mots part telle quelle.
     */
    function assemblerLectures(lectures) {
        var textes = lectures.map(function (lecture) {
            return texteMarque(lecture.donnees);
        });
        var rang = textes.indexOf(choisirLecture(textes.slice(0, DECOUPAGES.length)));
        if (!Array.isArray(lectures[rang].donnees.blocks)) {
            return compacter(textes[rang]);
        }
        var autres = lectures.filter(function (lecture, i) {
            return i !== rang;
        }).map(lignesPlacees);
        var votants = autres.map(function (lignes) {
            return [].concat.apply([], lignes.map(function (ligne) {
                return ligne.mots;
            }));
        });
        var gardees = lignesPlacees(lectures[rang]);
        var lignes = gardees.map(function (ligne) {
            return { centre: ligne.centre, texte: marquerLigne(ligne.lue, votants) };
        });
        lignesPerdues(gardees, autres).forEach(function (perdue) {
            var suivante = lignes.findIndex(function (ligne) {
                return ligne.centre > perdue.centre;
            });
            lignes.splice(suivante === -1 ? lignes.length : suivante, 0, { centre: perdue.centre, texte: marquerLigne(perdue.lue, []) });
        });

        return compacter(lignes.map(function (ligne) {
            return ligne.texte;
        }).join('\n'));
    }

    /**
     * Les lignes d'une lecture qui portent des mots, à leur place sur l'image en taille réelle : `{ lue, mots, centre }`,
     * où `lue` est la ligne de Tesseract, chaque mot `{ texte, confiance, x0, y0, x1, y1 }`, et `centre` la médiane des
     * hauteurs de ses mots (qui ne sert qu'à ranger une ligne reprise). Sans détail des mots, aucune.
     */
    function lignesPlacees(lecture) {
        var lignes = [];
        (lecture.donnees.blocks || []).forEach(function (bloc) {
            bloc.paragraphs.forEach(function (paragraphe) {
                paragraphe.lines.forEach(function (ligne) {
                    if (ligne.words.length === 0) {
                        return;
                    }
                    var mots = ligne.words.map(function (mot) {
                        return {
                            texte: mot.text,
                            confiance: mot.confidence,
                            x0: mot.bbox.x0 / lecture.facteur,
                            y0: mot.bbox.y0 / lecture.facteur,
                            x1: mot.bbox.x1 / lecture.facteur,
                            y1: mot.bbox.y1 / lecture.facteur
                        };
                    });
                    lignes.push({
                        lue: ligne,
                        mots: mots,
                        centre: mediane(mots.map(function (mot) { return mot.y0 + mot.y1; })) / 2
                    });
                });
            });
        });

        return lignes;
    }

    /** La valeur du milieu d'une liste de nombres non vide (la plus basse des deux du milieu, pour une liste paire). */
    function mediane(valeurs) {
        var triees = valeurs.slice().sort(function (a, b) {
            return a - b;
        });

        return triees[Math.floor((triees.length - 1) / 2)];
    }

    /**
     * La ligne `a` est-elle déjà lue dans `b` : la moitié au moins de ses mots recouvrent-ils un mot de `b`, à la même
     * hauteur ({@see recouvrement}) ?
     *
     * PAR LES MOTS, pas par la hauteur des lignes : Tesseract rattache à une ligne un « | » de bord de colonne haut de deux
     * lignes (ticket Phyto), ou en lit les montants plus haut que le libellé (MyKomela) — comparées par leur hauteur, ces
     * lignes passaient pour perdues, et étaient reprises en double.
     */
    function memeLigne(a, b) {
        var communs = a.mots.filter(function (mot) {
            return b.mots.some(function (autre) {
                return recouvrement(mot, autre) > 0;
            });
        });

        return communs.length * 2 >= a.mots.length;
    }

    /**
     * Les lignes que la lecture gardée a perdues : celles qu'au moins LECTURES_MIN_LIGNE_PERDUE autres lectures trouvent au
     * même endroit, là où la lecture gardée n'a rien (« S.Tax », entouré au stylo, ticket Liquor Street). De ses versions,
     * celle qui porte le plus de montants, puis la mieux lue.
     */
    function lignesPerdues(gardees, autres) {
        var lues = {
            mots: [].concat.apply([], gardees.map(function (gardee) {
                return gardee.mots;
            }))
        };
        var groupes = [];
        autres.forEach(function (lignes, rang) {
            lignes.forEach(function (ligne) {
                if (memeLigne(ligne, lues)) {
                    return;
                }
                var groupe = groupes.find(function (candidat) {
                    return memeLigne(ligne, candidat.versions[0]);
                });
                if (groupe === undefined) {
                    groupes.push({ versions: [ligne], lectures: [rang] });
                } else if (groupe.lectures.indexOf(rang) === -1) {
                    groupe.versions.push(ligne);
                    groupe.lectures.push(rang);
                }
            });
        });

        return groupes.filter(function (groupe) {
            return groupe.lectures.length >= LECTURES_MIN_LIGNE_PERDUE;
        }).map(function (groupe) {
            return groupe.versions.reduce(function (meilleure, version) {
                return valeurLigne(version) > valeurLigne(meilleure) ? version : meilleure;
            });
        }).filter(function (ligne) {
            // Mal lue même dans sa meilleure version, c'est une tache, un pli, un bout de doigt (« ; No », ticket T21).
            return confianceMoyenne(ligne) >= CONFIANCE_MIN;
        });
    }

    /** Ce que vaut une version de ligne : ses montants d'abord, sa confiance moyenne (sur 100) ensuite. */
    function valeurLigne(ligne) {
        return (ligne.lue.text.match(MONTANT) || []).length * 1000 + confianceMoyenne(ligne);
    }

    /** La confiance moyenne (sur 100) des mots d'une ligne placée. */
    function confianceMoyenne(ligne) {
        return ligne.mots.reduce(function (somme, mot) {
            return somme + mot.confiance;
        }, 0) / ligne.mots.length;
    }

    /**
     * Le texte sans ce qui coûte des jetons au LLM sans rien lui apprendre : les lignes vides ou sans lettre ni chiffre,
     * les espaces de fin de ligne, et les longues suites d'espaces d'alignement, raccourcies à ESPACES_MAX.
     */
    function compacter(texte) {
        return texte.split('\n').map(function (ligne) {
            return ligne.replace(/\s+$/, '').replace(new RegExp(' {' + (ESPACES_MAX + 1) + ',}', 'g'), ' '.repeat(ESPACES_MAX));
        }).filter(function (ligne) {
            return /[\p{L}\d]/u.test(ligne);
        }).join('\n');
    }

    /**
     * Le texte lu, où chaque mot porteur d'un chiffre lu avec peu de confiance est marqué ⟦ ⟧ : le LLM sait ainsi quel
     * montant douter (« ⟦19£0⟧ 00 », « ⟦1.159,70⟧ »). Sans le détail des blocs, le texte tel quel.
     */
    function texteMarque(donnees) {
        if (!Array.isArray(donnees.blocks)) {
            return donnees.text;
        }
        var lignes = [];
        donnees.blocks.forEach(function (bloc) {
            bloc.paragraphs.forEach(function (paragraphe) {
                paragraphe.lines.forEach(function (ligne) {
                    lignes.push(marquerLigne(ligne, []));
                });
            });
        });

        return lignes.join('\n');
    }

    /** Une ligne lue, ses mots votés, ses mots douteux marqués, son alignement (espaces conservés) intact. */
    function marquerLigne(ligne, votants) {
        var texte = ligne.text.replace(/\n$/, '');
        var marquee = '';
        var curseur = 0;
        ligne.words.forEach(function (mot) {
            // Chaque mot est cherché APRÈS le précédent : le « 2 » d'un code de TVA n'est pas celui de « 225,00 ».
            var position = texte.indexOf(mot.text, curseur);
            if (position === -1) {
                return;
            }
            var vote = voter(mot, votants);
            var douteux = vote === null || (mot.confidence < CONFIANCE_MIN && /\d/.test(mot.text));
            var retenu = vote === null ? mot.text : vote;
            marquee += texte.slice(curseur, position) + (douteux ? '⟦' + retenu + '⟧' : retenu);
            curseur = position + mot.text.length;
        });

        return marquee + texte.slice(curseur);
    }

    /**
     * Le mot retenu après le vote des autres lectures : celui que lit une MAJORITÉ STRICTE des lectures, le mot lui-même
     * compris ; sans majorité, le mot tel quel.
     *
     * UN MONTANT N'EST JAMAIS REMPLACÉ : les lectures réduites se trompent ensemble sur certains chiffres (« -49.00 » lu
     * « -48.00 » à toutes les petites tailles, ticket Phyto). Contesté par une majorité qui lit d'autres chiffres, il rend
     * null — le mot est alors marqué douteux, et la réconciliation du serveur tranche avec le total.
     *
     * LES MONTANTS VOTENT PAR LEURS CHIFFRES : « 85.00 », « 85,00 » et « 85.00 » sont trois voix pour le même montant,
     * contre le « 88,006 » d'une lecture du gros total (ticket Phyto) ; le texte retenu est celui de la première voix.
     */
    function voter(mot, votants) {
        var voix = Object.create(null);
        var total = 1;
        voix[cleDeVote(mot.text)] = { nombre: 1, texte: mot.text };
        votants.forEach(function (mots) {
            var lu = motAuMemeEndroit(mot, mots);
            if (lu !== null) {
                var cle = cleDeVote(lu);
                voix[cle] = voix[cle] || { nombre: 0, texte: lu };
                voix[cle].nombre++;
                total++;
            }
        });
        var majoritaire = Object.keys(voix).filter(function (cle) {
            return voix[cle].nombre * 2 > total;
        })[0];
        if (majoritaire === undefined || majoritaire === cleDeVote(mot.text)) {
            return mot.text;
        }
        if (MONTANT_SEUL.test(mot.text) && mot.confidence >= CONFIANCE_MIN) {
            return null;
        }

        return voix[majoritaire].texte;
    }

    /** Ce sur quoi un mot vote : un montant, ses chiffres ; tout autre mot, son texte. */
    function cleDeVote(texte) {
        return MONTANT_SEUL.test(texte) ? '#' + texte.replace(/\D/g, '') : texte;
    }

    /**
     * Le texte du mot d'une autre lecture qui recouvre le mieux ce mot de Tesseract, d'au moins RECOUVREMENT_MIN ; null
     * s'il n'y en a pas.
     *
     * UN MONTANT VOTE AUSSI POUR UN MOT À CHIFFRES PLUS LARGE QUI LE CONTIENT : Tesseract rattache parfois une tache au
     * montant (« 88,006 », étiré jusqu'au bord du ticket, contre « 85.00 », ticket Phyto). Un mot sans chiffre exige le
     * recouvrement mutuel : « EMANUELE » ne vote pas pour « C.V.EMANUELE ».
     */
    function motAuMemeEndroit(mot, mots) {
        var trouve = null;
        var meilleur = RECOUVREMENT_MIN;
        var aChiffres = /\d/.test(mot.text);
        mots.forEach(function (autre) {
            var part = aChiffres && MONTANT_SEUL.test(autre.texte)
                ? Math.max(recouvrement(mot.bbox, autre), inclusion(autre, mot.bbox))
                : recouvrement(mot.bbox, autre);
            if (part >= meilleur) {
                trouve = autre.texte;
                meilleur = part;
            }
        });

        return trouve;
    }

    /**
     * La largeur commune de deux mots sur leur largeur réunie — 0 pour deux mots de lignes différentes (centres écartés de
     * plus de 60 % de la hauteur du premier).
     */
    function recouvrement(a, b) {
        return memeHauteur(a, b) ? largeurCommune(a, b) / (Math.max(a.x1, b.x1) - Math.min(a.x0, b.x0)) : 0;
    }

    /** La part de la largeur du mot `a` comprise dans le mot `b`, à la même hauteur ; 0 sur une autre ligne. */
    function inclusion(a, b) {
        return memeHauteur(b, a) ? largeurCommune(a, b) / (a.x1 - a.x0) : 0;
    }

    /** Les centres de deux mots sont-ils écartés de moins de 60 % de la hauteur du premier ? */
    function memeHauteur(a, b) {
        return Math.abs(a.y0 + a.y1 - b.y0 - b.y1) / 2 <= (a.y1 - a.y0) * 0.6;
    }

    /** La largeur commune de deux mots, 0 s'ils ne se croisent pas. */
    function largeurCommune(a, b) {
        return Math.max(0, Math.min(a.x1, b.x1) - Math.max(a.x0, b.x0));
    }

    /**
     * Corrige ce que Tesseract confond à coup sûr :
     * - le « ı » turc (sans point), que le modèle allemand rend pour chaque « i » (ticket Migros) ;
     * - « c1 », « c! », « cI », « c| » après un nombre : c'est l'unité « cl » (« 75c! », ticket Phyto).
     *
     * Un « | » en fin de ligne n'est PAS changé en « 1 » : c'est aussi le bord d'une colonne (ticket Phyto).
     */
    function nettoyerTexte(texte) {
        return texte.replace(/ı/g, 'i').replace(/(\d ?⟦?)c[1!I|](?![A-Za-z0-9])/g, '$1cl');
    }

    /** La lecture qui compte le plus de montants — la première à égalité. */
    function choisirLecture(lectures) {
        return lectures.reduce(function (meilleure, lecture) {
            return (lecture.match(MONTANT) || []).length > (meilleure.match(MONTANT) || []).length ? lecture : meilleure;
        });
    }

    /**
     * Écrit dans la console du navigateur ce que le serveur joint à sa réponse EN MODE DEBUG SEULEMENT (`input`, `debug`,
     * {@see BudgetScanController}) : le texte OCR envoyé au LLM et le JSON qu'il a renvoyé. Hors debug, ces clés sont absentes
     * et rien n'est écrit.
     */
    function journaliserDiagnostic(resultat) {
        if (resultat.input === undefined && resultat.debug === undefined) {
            return;
        }
        var reponse = (resultat.debug || {}).reponse_llm;

        console.log('Texte brut envoyé au LLM :');
        console.log(resultat.input);
        console.log('JSON renvoyé par le LLM :');
        console.log(formaterJson(reponse));
    }

    /** Le JSON mis en forme (indenté) ; un contenu absent ou illisible est rendu tel quel. */
    function formaterJson(contenu) {
        try {
            return JSON.stringify(JSON.parse(contenu), null, 2);
        } catch (e) {
            return String(contenu);
        }
    }

    /**
     * Fait structurer le texte par le serveur, qui interroge le LLM — en lui disant la langue du reçu — puis valide sa
     * réponse. Le foyer choisi l'accompagne : le serveur propose le cours de change vers sa monnaie.
     */
    function analyserTexte(texte) {
        var formulaire = element('formulaire-depense');
        var donnees = new FormData();
        donnees.append('csrf_token', formulaire.querySelector('[name="csrf_token"]').value);
        donnees.append('texte', texte);
        donnees.append('langue', langueChoisie());
        donnees.append('foyer_id', formulaire.querySelector('[name="foyer_id"]').value);

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
            journaliserDiagnostic(resultat);
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
        // UN MONTANT DÉDUIT DU TOTAL, faute d'avoir été lu (AnalyseurTicket::reconcilier()), est signalé.
        if (article.a_verifier) {
            ligne.classList.add('ligne-a-verifier');
            ligne.querySelector('[data-champ="montant"]').title = MESSAGE_A_VERIFIER;
        }

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
        afficherCours();
    }

    /** La monnaie du foyer choisi : celle de l'option sélectionnée, ou du champ caché quand il n'y a qu'un foyer. */
    function monnaieFoyer() {
        var champ = element('formulaire-depense').querySelector('[name="foyer_id"]');
        var source = champ.tagName === 'SELECT' ? champ.selectedOptions[0] : champ;

        return source === undefined ? '' : (source.dataset.monnaie || '');
    }

    /** La monnaie des lignes cochées qui n'est pas celle du foyer, s'il n'y en a qu'une ; sinon ''. */
    function monnaieRecu(foyer) {
        var etrangeres = [];
        element('lignes-articles').querySelectorAll('[data-role="ligne-article"]').forEach(function (ligne) {
            var monnaie = ligne.querySelector('[data-champ="monnaie"]').value.trim().toUpperCase();
            if (ligne.querySelector('[data-champ="retenu"]').checked && monnaie !== '' && monnaie !== foyer && etrangeres.indexOf(monnaie) === -1) {
                etrangeres.push(monnaie);
            }
        });

        return etrangeres.length === 1 ? etrangeres[0] : '';
    }

    /**
     * LA LIGNE DU COURS DE CHANGE, « 1 [EUR] = [X] CHF (i) » : montrée seulement pour un reçu dans UNE autre monnaie
     * que celle du foyer. L'icône mène à la demande que le serveur fait au service des cours pour ces deux monnaies,
     * au jour du reçu (CoursDeChange::cours()) — au dernier cours publié tant que le reçu n'est pas daté.
     *
     * La monnaie du reçu n'est pas réécrite pendant qu'on la saisit : le curseur n'y sauterait pas en fin de champ.
     */
    function afficherCours() {
        var foyer = monnaieFoyer();
        var recu = monnaieRecu(foyer);
        var champRecu = element('cours-monnaie-recu');
        element('ligne-cours').hidden = recu === '';
        if (document.activeElement !== champRecu) {
            champRecu.value = recu;
        }
        element('cours-monnaie-foyer').textContent = foyer;

        var date = element('formulaire-depense').querySelector('[name="date_document"]').value.slice(0, 10);
        var source = element('source-cours');
        source.href = configuration().coursUrl + '/' + (date || 'latest') + '?base=' + encodeURIComponent(recu)
            + '&symbols=' + encodeURIComponent(foyer);
    }

    /**
     * Une INFOBULLE BOOTSTRAP qui s'ouvre sans délai ni animation. Son texte est le `title` de la cible, sauf option
     * contraire. Sans Bootstrap (ou sans Popper, qu'il exige), rien : le `title` reste l'infobulle du navigateur.
     */
    function infobulle(cible, options) {
        if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
            return null;
        }

        return new window.bootstrap.Tooltip(cible, Object.assign({ delay: 0, animation: false }, options));
    }

    /**
     * L'INFOBULLE DE LA SOURCE DU COURS : elle dit l'adresse que le lien vise AU MOMENT où elle s'ouvre — celle-ci
     * change avec le foyer, la date et les monnaies.
     */
    function initInfobulleCours() {
        var source = element('source-cours');

        return infobulle(source, {
            title: function () {
                return source.href;
            },
            customClass: 'infobulle-adresse'
        });
    }

    /**
     * Les infobulles des drapeaux : le nom de la langue, que porte le `title` de chacun. Au survol seulement : ouverte
     * aussi au focus (le défaut), elle restait affichée sur le drapeau cliqué, qui garde le focus.
     */
    function initInfobullesLangues() {
        document.querySelectorAll('[data-role="langues"] [data-langue]').forEach(function (drapeau) {
            infobulle(drapeau, { trigger: 'hover' });
        });
    }

    /** Ferme l'infobulle de cette cible, s'il y en a une. */
    function masquerInfobulle(cible) {
        var instance = window.bootstrap && window.bootstrap.Tooltip ? window.bootstrap.Tooltip.getInstance(cible) : null;
        if (instance) {
            instance.hide();
        }
    }

    /**
     * LA MONNAIE DU REÇU, SAISIE SUR LA LIGNE DU COURS, est recopiée dans la monnaie de chaque article — une fois ses
     * trois lettres tapées : recopier « E », puis « EU », ferait disparaître la ligne pendant la saisie.
     */
    function changerMonnaieRecu(champ) {
        var monnaie = champ.value.trim().toUpperCase();
        if (!/^[A-Z]{3}$/.test(monnaie)) {
            return;
        }
        element('lignes-articles').querySelectorAll('[data-champ="monnaie"]').forEach(function (champMonnaie) {
            champMonnaie.value = monnaie;
        });
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
     * LES DEUX TOTAUX, en pied des articles : le total calculé — la somme des lignes cochées, en centimes — et le total
     * affiché, imprimé sur le reçu. Ils ne se comparent que pour une seule monnaie : des CHF et des EUR ne s'additionnent
     * pas, et chaque monnaie a alors sa somme. Un écart met le total calculé dans la couleur d'alerte, et se dit en
     * dessous.
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

        var uneMonnaie = monnaies.length === 1;
        element('total-calcule').textContent = monnaies.length === 0
            ? '0.00'
            : monnaies.map(function (monnaie) {
                return (sommes[monnaie] / 100).toFixed(2) + (uneMonnaie ? '' : ' ' + monnaie);
            }).join(' + ');
        element('monnaie-total-calcule').textContent = uneMonnaie ? monnaies[0] : '';
        element('total-affiche').textContent = totalImprime === null ? '—' : Number(totalImprime).toFixed(2);
        element('monnaie-total-affiche').textContent = totalImprime !== null && uneMonnaie ? monnaies[0] : '';

        element('ligne-total-calcule').classList.toggle('table-warning', ecart);
        element('ecart-total').hidden = !ecart;

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
            .then(function (image) {
                return reconnaitreTexte(image);
            })
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
                masquerInfobulle(drapeau);
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
                afficherCours();
            }
        });
        formulaire.addEventListener('change', function (evenement) {
            if (evenement.target.matches('[data-champ="retenu"]')) {
                basculerLigne(evenement.target);
            }
            // UN AUTRE FOYER OU UNE AUTRE DATE, ET LE COURS PROPOSÉ NE VAUT PLUS : vidé, il sera demandé à l'enregistrement.
            if (evenement.target.matches('[name="foyer_id"], [name="date_document"]')) {
                formulaire.querySelector('[name="cours_change"]').value = '';
            }
            verifierTotal();
            afficherCours();
        });
        formulaire.addEventListener('input', function (evenement) {
            if (evenement.target === element('cours-monnaie-recu')) {
                changerMonnaieRecu(evenement.target);
            }
            verifierTotal();
            afficherCours();
        });
        // Une saisie refusée, rendue par le serveur, montre sa ligne de cours si elle en a besoin.
        afficherCours();
        initInfobulleCours();
        initInfobullesLangues();

        return true;
    }

    document.addEventListener('DOMContentLoaded', initBudgetScan);

    return {
        estImageAcceptee: estImageAcceptee,
        choisirLecture: choisirLecture,
        langueChoisie: langueChoisie,
        choisirLangue: choisirLangue,
        preparerImage: preparerImage,
        reduire: reduire,
        reconnaitreTexte: reconnaitreTexte,
        texteMarque: texteMarque,
        nettoyerTexte: nettoyerTexte,
        analyserTexte: analyserTexte,
        remplirFormulaire: remplirFormulaire,
        afficherCours: afficherCours,
        initInfobulleCours: initInfobulleCours,
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
