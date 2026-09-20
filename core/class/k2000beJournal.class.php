<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * La mémoire du plugin, hors base de données.
 *
 * Trois choses vivent ici : la conversation en cours de chaque assistant, le
 * journal des demandes passées, et la purge qui empêche l'une et l'autre de
 * grossir sans fin.
 *
 * Pourquoi des fichiers et non la configuration de l'équipement : une
 * conversation se réécrit ENTIÈREMENT à chaque phrase, et elle pèse vite
 * plusieurs dizaines de kilo-octets. La ranger dans la colonne JSON de
 * l'eqLogic ferait repasser le cœur par eqLogic::save() — donc par les
 * widgets, les événements et le cache — à chaque tour de conversation.
 *
 * Ce fichier ne charge pas core.inc.php : il est toujours inclus depuis
 * k2000be.class.php, qui l'a déjà fait. C'est ce qui permet au rejeu hors
 * ligne de l'inclure sans Jeedom.
 */
class k2000beJournal {

    const DOSSIER_CONVERSATIONS = 'conversations';
    const DOSSIER_JOURNAL = 'journal';

    /* Version de la structure enregistrée. Une conversation portant un autre
     * numéro est repartie de zéro plutôt que relue de travers : perdre un
     * historique de discussion est sans gravité, le relire mal ne l'est pas. */
    const VERSION = 1;

    /* Rétention du journal, en jours, quand la configuration ne dit rien. */
    const RETENTION_DEFAUT = 30;

    /* Une rétention nulle ou négative effacerait le journal du jour même, y
     * compris la demande en cours d'écriture. */
    const RETENTION_MIN = 1;

    /*
     * Lignes conservées dans le fichier d'un jour. Le format imposé est un
     * tableau JSON : ajouter une ligne oblige donc à relire et à réécrire tout
     * le fichier. À trois kilo-octets la ligne, cinq cents lignes coûtent une
     * réécriture d'un mégaoctet et demi — au-delà, l'écriture du journal
     * deviendrait plus lente que l'appel au modèle qu'elle raconte.
     */
    const LIGNES_MAX = 500;

    /* Longueur conservée pour la demande et la réponse. Le journal sert à
     * comprendre après coup, pas à rejouer : une réponse de dix mille
     * caractères y serait illisible et y pèserait pour rien. */
    const TEXTE_MAX = 3000;

    /* Plafond dur de historique(), quoi que demande l'interface. */
    const HISTORIQUE_MAX = 500;

    /*
     * Budget de lecture de historique(). Filtrer par assistant oblige à ouvrir
     * les fichiers un à un jusqu'à trouver assez de lignes : sur un assistant
     * peu bavard — un scénario qui ne parle qu'une fois par mois — cela revient
     * à décoder tout le dossier, soit, à cinq cents lignes de trois kilo-octets
     * par jour, plusieurs dizaines de mégaoctets pour afficher un onglet.
     *
     * Dix jours pleins de journal : au-delà, la recherche s'arrête et le dit,
     * plutôt que de tenir la page une minute sans rien annoncer.
     */
    const HISTORIQUE_LIGNES_LUES = 5000;

    /* Suffixe des fichiers de journal mis de côté parce qu'illisibles. Ni
     * .json ni .tmp : ils ne doivent être ni relus, ni confondus avec une
     * écriture en cours. */
    const SUFFIXE_ILLISIBLE = '.illisible';

    /* Âge à partir duquel un fichier temporaire est considéré comme le reste
     * d'un processus tué plutôt que comme une écriture en cours. Une écriture
     * dure quelques millisecondes ; une heure est déjà mille fois trop. */
    const TEMPORAIRE_AGE = 3600;

    /* Ce qui remplace la clé API dans tout texte journalisé. */
    const MASQUE = '…';

    /* En dessous de cette longueur, la valeur lue dans la configuration n'est
     * pas une clé mais un reste de saisie : la remplacer partout hacherait le
     * texte journalisé sans rien protéger. */
    const CLE_MIN = 12;

    /* Le groupe www-data doit pouvoir écrire : le cron tourne sous ce compte,
     * l'interface aussi, mais un déploiement fait à la main ne les crée pas
     * forcément avec le bon groupe. */
    const DROITS_DOSSIER = 0775;

    /* ==================================================== EMPLACEMENT */

    /*
     * Racine des données du plugin. Ce dossier est produit à l'exécution : il
     * n'est jamais recopié par un déploiement, et survit donc aux mises à jour.
     */
    public static function racine() {
        return dirname(__DIR__, 2) . '/data';
    }

    /*
     * Crée les dossiers de travail. Appelée à l'installation et à chaque mise à
     * jour du plugin, et de nouveau avant toute écriture : une installation où
     * data/ a été vidé à la main doit se réparer toute seule plutôt que de
     * journaliser dans le vide.
     */
    public static function prepare() {
        $racine = self::racine();
        self::creerDossier($racine);
        self::creerDossier($racine . '/' . self::DOSSIER_CONVERSATIONS);
        self::creerDossier($racine . '/' . self::DOSSIER_JOURNAL);
        return $racine;
    }

    /*
     * Un .htaccess par dossier, et non un seul à la racine : le cœur sert
     * data/ depuis la racine web, et une conversation contient tout ce que
     * l'utilisateur a dit à son domicile.
     */
    private static function creerDossier($_chemin) {
        if (!is_dir($_chemin)) {
            @mkdir($_chemin, self::DROITS_DOSSIER, true);
        }
        $htaccess = $_chemin . '/.htaccess';
        if (is_dir($_chemin) && !file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        return is_dir($_chemin);
    }

    private static function dossier($_nom) {
        $chemin = self::racine() . '/' . $_nom;
        if (!is_dir($chemin)) {
            self::prepare();
        }
        return $chemin;
    }

    /* ================================================== CONVERSATIONS */

    /*
     * La conversation d'un assistant, toujours sous la forme attendue par le
     * moteur : un fichier absent, illisible ou d'une autre version rend une
     * conversation neuve. Une seule règle ici : ne jamais rendre une structure
     * incomplète, sinon chaque lecteur devrait tester chaque clé.
     */
    public static function charger($_eqId) {
        $vide = array(
            'version'  => self::VERSION,
            'maj'      => 0,
            'messages' => array(),
            'attente'  => null,
            'tours'    => array(),
        );

        $donnees = self::lireJson(self::cheminConversation($_eqId));
        if (!is_array($donnees) || !isset($donnees['version']) || (int) $donnees['version'] !== self::VERSION) {
            return $vide;
        }

        $conversation = $vide;
        $conversation['maj'] = isset($donnees['maj']) ? (int) $donnees['maj'] : 0;
        if (isset($donnees['messages']) && is_array($donnees['messages'])) {
            $conversation['messages'] = $donnees['messages'];
        }
        if (isset($donnees['tours']) && is_array($donnees['tours'])) {
            $conversation['tours'] = $donnees['tours'];
        }
        if (isset($donnees['attente']) && is_array($donnees['attente'])) {
            $conversation['attente'] = $donnees['attente'];
        }
        return $conversation;
    }

    /*
     * Enregistre la conversation. Ne lève pas : un disque plein doit faire
     * perdre la mémoire de l'assistant, pas la réponse que l'utilisateur
     * attend. L'échec part dans le journal du plugin, où il se voit.
     */
    public static function enregistrer($_eqId, $_conversation) {
        $conversation = is_array($_conversation) ? $_conversation : array();
        $conversation['version'] = self::VERSION;
        $conversation['maj'] = time();
        foreach (array('messages', 'tours') as $cle) {
            if (!isset($conversation[$cle]) || !is_array($conversation[$cle])) {
                $conversation[$cle] = array();
            }
        }
        if (!isset($conversation['attente']) || !is_array($conversation['attente'])) {
            $conversation['attente'] = null;
        }

        try {
            self::dossier(self::DOSSIER_CONVERSATIONS);
            return self::ecrire(self::cheminConversation($_eqId), $conversation);
        } catch (Throwable $e) {
            self::tracer('error', 'conversation ' . (int) $_eqId . ' : ' . $e->getMessage());
            return false;
        }
    }

    public static function effacer($_eqId) {
        $chemin = self::cheminConversation($_eqId);
        if (file_exists($chemin)) {
            return @unlink($chemin);
        }
        return true;
    }

    /* L'identifiant est forcé en entier : c'est lui qui compose le nom de
     * fichier, et rien d'autre ne doit pouvoir s'y glisser. */
    private static function cheminConversation($_eqId) {
        return self::racine() . '/' . self::DOSSIER_CONVERSATIONS . '/' . (int) $_eqId . '.json';
    }

    /* ========================================================= JOURNAL */

    /*
     * Ajoute une ligne au journal du jour. Tout y passe, y compris les refus :
     * le journal est la seule pièce qui permette de répondre, un mois plus
     * tard, à « qu'est-ce qui a ouvert le portail ce soir-là ».
     *
     * Ne lève pas, pour la même raison qu'enregistrer().
     */
    public static function consigner($_eqId, $_entree) {
        try {
            $dossier = self::dossier(self::DOSSIER_JOURNAL);
            $chemin = $dossier . '/' . date('Y-m-d') . '.json';
            $ligne = self::normaliserEntree($_eqId, $_entree);

            /*
             * Un scénario, l'interface et le cron peuvent écrire dans la même
             * seconde. Sans verrou, deux lectures concurrentes du fichier
             * produiraient deux écritures dont la seconde effacerait la ligne
             * de la première : le journal perdrait précisément les moments
             * chargés, ceux qui intéressent.
             */
            $verrou = self::verrouiller($chemin);
            try {
                $lignes = self::lireJson($chemin);
                if (!is_array($lignes)) {
                    /*
                     * Le fichier du jour existe, n'est pas vide, et ne se
                     * relit pas : écriture coupée par un disque plein,
                     * modification à la main, système de fichiers abîmé. Le
                     * réécrire à partir d'un tableau vide effacerait d'un coup
                     * toutes les demandes de la journée — précisément ce que
                     * le journal existe pour ne pas faire. Il est donc mis de
                     * côté, sous un nom qui n'est plus relu, et l'incident
                     * part dans le journal du plugin.
                     */
                    if (!self::ecarterIllisible($chemin)) {
                        return false;
                    }
                    $lignes = array();
                }
                $lignes[] = $ligne;
                if (count($lignes) > self::LIGNES_MAX) {
                    $lignes = array_slice($lignes, -self::LIGNES_MAX);
                }
                $ecrit = self::ecrire($chemin, $lignes);
            } finally {
                self::deverrouiller($verrou);
            }
            return $ecrit;
        } catch (Throwable $e) {
            self::tracer('error', 'journal : ' . $e->getMessage());
            return false;
        }
    }

    /*
     * Les lignes de journal, les plus récentes d'abord. Un identifiant
     * d'assistant nul ou absent rend le journal de tous les assistants, ce dont
     * l'onglet Historique a besoin quand l'installation en compte plusieurs.
     *
     * $_statut, s'il est renseigné, ne retient que les demandes qui se sont
     * terminées ainsi. Le tri se fait ICI et non dans la page, et la nuance
     * n'est pas cosmétique : filtrer les cinquante lignes déjà reçues répond
     * « aucune erreur » à une installation qui en a, simplement parce que la
     * dernière date d'avant-hier. « La fois où ça a été refusé » est la
     * question qu'on vient poser à cet écran ; elle se cherche dans le
     * journal, pas dans ce qu'on en a déjà sous la main.
     *
     * La lecture a un budget, voir HISTORIQUE_LIGNES_LUES. Quand il s'épuise
     * avant la limite demandée, la dernière ligne rendue n'est pas une demande
     * mais une ligne de service qui dit jusqu'où le journal a été parcouru :
     * une liste écourtée sans le dire ferait conclure à l'absence d'une demande
     * qui est pourtant bien enregistrée, plus loin. Un filtre rend ce cas
     * ordinaire — il faut lire beaucoup pour retenir peu —, raison de plus
     * pour que la ligne soit là.
     */
    public static function historique($_eqId, $_limite = 50, $_statut = '') {
        $limite = (int) $_limite;
        if ($limite < 1) {
            $limite = 1;
        }
        if ($limite > self::HISTORIQUE_MAX) {
            $limite = self::HISTORIQUE_MAX;
        }
        $eqId = (int) $_eqId;
        $statut = trim((string) $_statut);

        $fichiers = glob(self::racine() . '/' . self::DOSSIER_JOURNAL . '/*.json');
        if (!is_array($fichiers) || empty($fichiers)) {
            return array();
        }
        /* Les noms de fichiers sont des dates ISO : l'ordre alphabétique
         * inverse est l'ordre chronologique inverse, sans rien parser. */
        rsort($fichiers);

        $lignes = array();
        $examinees = 0;
        $dernierJour = '';
        foreach ($fichiers as $fichier) {
            /*
             * Le budget se vérifie AVANT d'ouvrir un fichier de plus : une fois
             * le fichier décodé, la dépense est faite. Filtrer par assistant
             * peut ne retenir aucune ligne d'une journée entière, et sans ce
             * garde-fou la recherche descendrait jusqu'au plus ancien fichier
             * du dossier pour afficher une page.
             */
            if ($examinees >= self::HISTORIQUE_LIGNES_LUES) {
                $lignes[] = self::ligneBudget($dernierJour);
                return $lignes;
            }
            $jour = self::lireJson($fichier);
            if (!is_array($jour)) {
                continue;
            }
            $examinees += count($jour);
            $dernierJour = basename($fichier, '.json');
            foreach (array_reverse($jour) as $ligne) {
                if (!is_array($ligne)) {
                    continue;
                }
                if ($eqId > 0 && (int) (isset($ligne['eq']) ? $ligne['eq'] : 0) !== $eqId) {
                    continue;
                }
                if ($statut !== '' && (string) (isset($ligne['statut']) ? $ligne['statut'] : '') !== $statut) {
                    continue;
                }
                $lignes[] = $ligne;
                if (count($lignes) >= $limite) {
                    return $lignes;
                }
            }
        }
        return $lignes;
    }

    /*
     * La ligne de service qui clôt un historique écourté par son budget. Elle
     * a la forme d'une ligne de journal — l'interface n'en connaît qu'une — et
     * s'en distingue par son statut, qui ne correspond à aucun statut de
     * demande. Ni jetons ni durée : l'en-tête resterait sinon encombré de zéros
     * qui ne veulent rien dire ici.
     */
    private static function ligneBudget($_dernierJour) {
        $jour = self::jourDuNom((string) $_dernierJour);
        return array(
            'date'        => ($jour === null) ? time() : $jour,
            'eq'          => 0,
            'assistant'   => '',
            'utilisateur' => '',
            'demande'     => '',
            'reponse'     => ($jour === null)
                ? __('Le journal n\'a pas été parcouru jusqu\'au bout : la recherche s\'arrête avant d\'avoir à relire tout le dossier. Les demandes plus anciennes sont toujours enregistrées.', __FILE__)
                : sprintf(__('Le journal a été parcouru jusqu\'au %s : au-delà, la recherche s\'arrête avant d\'avoir à relire tout le dossier. Les demandes plus anciennes sont toujours enregistrées.', __FILE__), date('d/m/Y', $jour)),
            'statut'      => 'TRONQUE',
            'modele'      => '',
            'etapes'      => array(),
        );
    }

    /*
     * Ce que la journée en cours a coûté : le nombre de demandes qui ont
     * atteint le modèle, et les jetons qu'elles ont consommés.
     *
     * Un seul fichier est lu — celui du jour —, jamais le dossier : la lecture
     * a lieu avant chaque demande quand un plafond est réglé, et parcourir un
     * mois de journal pour décider d'une phrase serait hors de proportion.
     *
     * Ne comptent que les lignes qui portent des jetons. Une demande refusée
     * avant tout appel réseau — clé absente, assistant occupé, plafond déjà
     * atteint — est journalisée comme les autres, et la compter reviendrait à
     * ce qu'un plafond atteint se nourrisse de ses propres refus : la journée
     * ne se rouvrirait jamais, même si tout le reste rentrait dans l'ordre.
     * Le chiffre rendu est donc bien ce qui a été facturé, et rien d'autre.
     *
     * Toute la maison, tous assistants confondus : la clé API est commune, la
     * facture aussi.
     */
    public static function consommationDuJour() {
        $consommation = array('demandes' => 0, 'jetons' => 0);
        try {
            $chemin = self::racine() . '/' . self::DOSSIER_JOURNAL . '/' . date('Y-m-d') . '.json';
            if (!is_file($chemin)) {
                return $consommation;
            }
            $lignes = self::lireJson($chemin);
            if (!is_array($lignes)) {
                return $consommation;
            }
            foreach ($lignes as $ligne) {
                if (!is_array($ligne) || !isset($ligne['jetons']) || !is_array($ligne['jetons'])) {
                    continue;
                }
                $total = (int) (isset($ligne['jetons']['total']) ? $ligne['jetons']['total'] : 0);
                if ($total <= 0) {
                    continue;
                }
                $consommation['demandes']++;
                $consommation['jetons'] += $total;
            }
        } catch (Throwable $e) {
            /* Un journal illisible ne doit pas empêcher de répondre : sans
             * chiffre, le plafond laisse passer. Mieux vaut une demande de
             * trop qu'un assistant muet parce qu'un fichier est abîmé. */
            self::tracer('error', 'journal : ' . $e->getMessage());
        }
        return $consommation;
    }

    /*
     * Supprime les journaux plus vieux que la rétention, et les conversations
     * dont l'assistant n'existe plus. Appelée par k2000be::cronDaily().
     * Rend le nombre de fichiers supprimés.
     */
    public static function purger($_jours = null) {
        $jours = ($_jours === null) ? self::retention() : (int) $_jours;
        if ($jours < self::RETENTION_MIN) {
            $jours = self::RETENTION_MIN;
        }
        $limite = strtotime('-' . $jours . ' days');
        $supprimes = 0;

        $dossier = self::racine() . '/' . self::DOSSIER_JOURNAL;
        $fichiers = glob($dossier . '/*.json');
        if (is_array($fichiers)) {
            foreach ($fichiers as $fichier) {
                $jour = self::jourDuNom(basename($fichier, '.json'));
                /* Un nom de fichier qui n'est pas une date n'a pas été écrit
                 * ici : on n'y touche pas. */
                if ($jour === null || $jour >= $limite) {
                    continue;
                }
                if (@unlink($fichier)) {
                    $supprimes++;
                }
            }
        }

        /* Les fichiers mis de côté parce qu'illisibles suivent la même
         * rétention que le journal : ils existent pour être examinés après
         * l'incident, pas pour rester à demeure. */
        $supprimes += self::purgerRestes($dossier . '/*.json' . self::SUFFIXE_ILLISIBLE, $limite);

        /*
         * Les fichiers temporaires d'écriture, eux, ne sont jamais datés dans
         * leur nom : un processus tué entre file_put_contents() et rename() en
         * laisse un, que rien ne ramassait. Leur âge se lit donc sur le
         * système de fichiers, et le seuil est large pour ne pas emporter une
         * écriture en cours dans un autre processus.
         */
        $tempo = time() - self::TEMPORAIRE_AGE;
        $supprimes += self::purgerRestes($dossier . '/*.tmp', $tempo);
        $supprimes += self::purgerRestes(self::racine() . '/' . self::DOSSIER_CONVERSATIONS . '/*.tmp', $tempo);

        $supprimes += self::purgerConversations();
        return $supprimes;
    }

    /* Supprime les fichiers d'un motif dont la date de modification est
     * antérieure à $_limite. Sert aux restes — temporaires et mises de côté —
     * dont le nom ne dit rien de leur âge. */
    private static function purgerRestes($_motif, $_limite) {
        $fichiers = glob($_motif);
        if (!is_array($fichiers)) {
            return 0;
        }
        $supprimes = 0;
        foreach ($fichiers as $fichier) {
            $date = @filemtime($fichier);
            if ($date === false || $date >= $_limite) {
                continue;
            }
            if (@unlink($fichier)) {
                $supprimes++;
            }
        }
        return $supprimes;
    }

    /*
     * L'horodatage d'un nom de fichier de journal, ou null si ce n'en est pas
     * un. strtotime() ne suffit pas : il accepte « 1999 », « now » et une
     * bonne partie de l'anglais courant, si bien qu'un fichier déposé là sous
     * un nom quelconque pouvait être supprimé alors que le commentaire d'à
     * côté promet le contraire. Seule la forme que consigner() écrit est
     * reconnue.
     */
    private static function jourDuNom($_nom) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $_nom, $trouve)) {
            return null;
        }
        if (!checkdate((int) $trouve[2], (int) $trouve[3], (int) $trouve[1])) {
            return null;
        }
        $jour = strtotime($_nom . ' 00:00:00');
        return ($jour === false) ? null : $jour;
    }

    /*
     * Une conversation reste sur le disque après la suppression de son
     * assistant : preRemove() peut ne jamais passer (suppression en base,
     * restauration d'une sauvegarde). Le ménage se fait donc aussi ici.
     */
    private static function purgerConversations() {
        if (!class_exists('eqLogic')) {
            return 0;
        }
        $fichiers = glob(self::racine() . '/' . self::DOSSIER_CONVERSATIONS . '/*.json');
        if (!is_array($fichiers) || empty($fichiers)) {
            return 0;
        }

        /*
         * La liste des assistants est demandée UNE fois, et son absence n'est
         * pas traitée comme la disparition de chacun d'eux.
         *
         * Un eqLogic::byId() par fichier ne distinguait pas « cet assistant a
         * été supprimé » de « la base ne répond pas » : pendant une
         * restauration de sauvegarde, où la table est vide un instant, le cron
         * quotidien effaçait la mémoire de tous les assistants de
         * l'installation, sans un mot. Une base qui ne rend aucun assistant
         * alors que des conversations existent est le signe d'un ennui, pas
         * d'un ménage à faire : on ne touche à rien et on le dit.
         */
        $vivants = null;
        try {
            $vivants = array();
            foreach (eqLogic::byType('k2000be') as $eqLogic) {
                $vivants[(int) $eqLogic->getId()] = true;
            }
        } catch (Throwable $e) {
            self::tracer('warning', 'purge des conversations reportée : ' . $e->getMessage());
            return 0;
        }
        if (empty($vivants)) {
            self::tracer('warning', 'purge des conversations reportée : aucun assistant en base alors que des conversations existent.');
            return 0;
        }

        $supprimes = 0;
        foreach ($fichiers as $fichier) {
            $id = (int) basename($fichier, '.json');
            if ($id <= 0 || isset($vivants[$id])) {
                continue;
            }
            if (@unlink($fichier)) {
                $supprimes++;
            }
        }
        return $supprimes;
    }

    private static function retention() {
        try {
            return (int) config::byKey('historique_jours', 'k2000be', self::RETENTION_DEFAUT);
        } catch (Throwable $e) {
            return self::RETENTION_DEFAUT;
        }
    }

    /* ======================================================== MASQUAGE */

    /*
     * Retire la clé API d'un texte. Appliquée à TOUT ce qui est journalisé.
     *
     * Deux filets, parce qu'un seul ne suffit pas : la clé configurée y passe
     * telle quelle, mais un message d'erreur d'OpenAI ou une trace de curl peut
     * aussi contenir une clé qui n'est plus celle de la configuration — une
     * ancienne, ou celle qu'on vient de saisir pour l'essai et qui a été
     * refusée. C'est justement celle-là que l'on tapera dans un ticket.
     *
     * Accepte aussi un tableau, qu'elle parcourt en profondeur : les étapes et
     * les entrées de journal sont des tableaux, et les passer clé par clé au
     * moment de l'appel finirait fatalement par en oublier une.
     */
    public static function masquer($_texte) {
        if (is_array($_texte)) {
            $masque = array();
            foreach ($_texte as $cle => $valeur) {
                $masque[$cle] = self::masquer($valeur);
            }
            return $masque;
        }
        if (!is_string($_texte) || $_texte === '') {
            return $_texte;
        }

        $texte = $_texte;
        $cle = '';
        try {
            $cle = trim((string) config::byKey('apikey', 'k2000be', ''));
        } catch (Throwable $e) {
            $cle = '';
        }
        if (strlen($cle) >= self::CLE_MIN) {
            $texte = str_replace($cle, self::MASQUE, $texte);
        }

        /* Forme des clés OpenAI : sk-…, sk-proj-…, sk-svcacct-…. */
        $texte = preg_replace('/\bsk-[A-Za-z0-9_\-]{12,}/', self::MASQUE, $texte);
        /* Et l'en-tête HTTP, qui la transporte sans le préfixe dans certains
         * déploiements par passerelle. */
        $texte = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{12,}/i', '${1}' . self::MASQUE, $texte);

        return $texte === null ? $_texte : $texte;
    }

    /* ======================================================== ÉCRITURE */

    /*
     * Écriture atomique : fichier temporaire puis rename(), qui est atomique
     * sur un même système de fichiers. Sans cela, une lecture concurrente —
     * l'interface qui rafraîchit la frise pendant que le moteur enregistre —
     * tomberait un jour sur un JSON coupé en deux, et la conversation serait
     * jugée illisible donc repartirait de zéro.
     */
    private static function ecrire($_chemin, $_donnees) {
        $json = json_encode($_donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception(__('Les données à enregistrer ne sont pas encodables en JSON.', __FILE__));
        }

        /*
         * file_put_contents() rend un NOMBRE D'OCTETS, pas un booléen. Sur un
         * disque plein, un quota atteint ou une écriture interrompue en cours
         * de route, elle rend un nombre plus petit que prévu, qui n'est pas
         * === false : sans la comparaison ci-dessous, le rename() suivait, et
         * un journal valide était remplacé par du JSON tronqué. Le fichier
         * partiel est effacé plutôt que promu.
         */
        $temporaire = $_chemin . '.' . getmypid() . '.tmp';
        $attendu = strlen($json);
        $ecrits = @file_put_contents($temporaire, $json);
        if ($ecrits === false) {
            @unlink($temporaire);
            throw new Exception(sprintf(__('Écriture impossible dans %s.', __FILE__), dirname($_chemin)));
        }
        if ($ecrits !== $attendu) {
            @unlink($temporaire);
            throw new Exception(sprintf(
                __('Écriture incomplète dans %s : %d octets sur %d. Le fichier existant n\'a pas été remplacé.', __FILE__),
                dirname($_chemin), (int) $ecrits, $attendu));
        }
        @chmod($temporaire, 0664);
        if (!@rename($temporaire, $_chemin)) {
            @unlink($temporaire);
            throw new Exception(sprintf(__('Remplacement impossible du fichier %s.', __FILE__), basename($_chemin)));
        }
        return true;
    }

    /*
     * Met de côté un fichier de journal illisible, et rend true quand le
     * chemin est libre pour une écriture neuve.
     *
     * Un fichier absent ou vide n'est pas un incident : c'est le premier
     * passage de la journée, et le chemin est libre. Un fichier qui existe,
     * pèse quelque chose et ne se décode pas, lui, contient des demandes que
     * personne ne pourra plus relire si on écrit par-dessus : il est déplacé,
     * et s'il ne peut pas l'être le chemin n'est PAS déclaré libre. Mieux vaut
     * une journée qui ne se journalise plus, bruyamment, qu'une journée
     * effacée en silence.
     */
    private static function ecarterIllisible($_chemin) {
        if (!file_exists($_chemin)) {
            return true;
        }
        $taille = @filesize($_chemin);
        if ($taille === false || $taille === 0) {
            return true;
        }
        $ecarte = $_chemin . self::SUFFIXE_ILLISIBLE;
        if (@rename($_chemin, $ecarte)) {
            self::tracer('error', 'journal illisible (' . (int) $taille . ' octets) : mis de côté sous ' . basename($ecarte) . '. Les demandes du jour ne sont pas perdues, mais elles ne sont plus relues.');
            return true;
        }
        self::tracer('error', 'journal illisible (' . (int) $taille . ' octets) et impossible à mettre de côté : ' . basename($_chemin) . '. Rien n\'est écrit par-dessus.');
        return false;
    }

    private static function lireJson($_chemin) {
        if (!is_readable($_chemin)) {
            return null;
        }
        $contenu = @file_get_contents($_chemin);
        if ($contenu === false || trim($contenu) === '') {
            return null;
        }
        $donnees = json_decode($contenu, true);
        return is_array($donnees) ? $donnees : null;
    }

    /*
     * Le verrou porte sur un fichier à lui, et le même pour tout le dossier :
     * verrouiller le fichier de journal lui-même ne servirait à rien, puisque
     * rename() le remplace par un autre inode et relâcherait le verrou au
     * milieu de l'opération. Un verrou unique plutôt qu'un par jour, sinon le
     * dossier accumulerait trois cent soixante-cinq fichiers vides par an que
     * la purge, qui ne regarde que les .json, ne ramasserait jamais.
     */
    private static function verrouiller($_chemin) {
        $poignee = @fopen(dirname($_chemin) . '/.ecriture.lock', 'c');
        if ($poignee === false) {
            return null;
        }
        @flock($poignee, LOCK_EX);
        return $poignee;
    }

    private static function deverrouiller($_poignee) {
        if ($_poignee === null) {
            return;
        }
        @flock($_poignee, LOCK_UN);
        @fclose($_poignee);
    }

    /* ==================================================== NORMALISATION */

    /*
     * Met une entrée de journal à la forme du contrat, en la masquant et en la
     * tronquant. Le masquage se fait ICI, sur l'entrée entière : c'est le seul
     * passage obligé de toute écriture, donc le seul endroit où l'oubli est
     * impossible.
     */
    private static function normaliserEntree($_eqId, $_entree) {
        $entree = is_array($_entree) ? $_entree : array();

        $jetons = array('invite' => 0, 'reponse' => 0, 'total' => 0);
        if (isset($entree['jetons']) && is_array($entree['jetons'])) {
            foreach ($jetons as $cle => $rien) {
                if (isset($entree['jetons'][$cle])) {
                    $jetons[$cle] = (int) $entree['jetons'][$cle];
                }
            }
        }

        return array(
            'date'        => isset($entree['date']) ? (int) $entree['date'] : time(),
            'eq'          => (int) $_eqId,
            'assistant'   => self::texte(isset($entree['assistant']) ? $entree['assistant'] : '', 120),
            /* 'scenario' quand la demande ne vient pas d'un humain connecté :
             * sans cela, une ouverture de portail lancée par un scénario
             * apparaîtrait au nom du dernier utilisateur vu. */
            'utilisateur' => self::texte(isset($entree['utilisateur']) && $entree['utilisateur'] !== '' ? $entree['utilisateur'] : 'scenario', 60),
            'demande'     => self::texte(isset($entree['demande']) ? $entree['demande'] : '', self::TEXTE_MAX),
            'reponse'     => self::texte(isset($entree['reponse']) ? $entree['reponse'] : '', self::TEXTE_MAX),
            'statut'      => self::texte(isset($entree['statut']) ? $entree['statut'] : '', 30),
            'modele'      => self::texte(isset($entree['modele']) ? $entree['modele'] : '', 60),
            'jetons'      => $jetons,
            'duree'       => round((float) (isset($entree['duree']) ? $entree['duree'] : 0), 2),
            'etapes'      => self::normaliserEtapes(isset($entree['etapes']) ? $entree['etapes'] : array()),
        );
    }

    private static function normaliserEtapes($_etapes) {
        if (!is_array($_etapes)) {
            return array();
        }
        $etapes = array();
        foreach ($_etapes as $etape) {
            if (!is_array($etape)) {
                continue;
            }
            $etapes[] = array(
                'outil'  => self::texte(isset($etape['outil']) ? $etape['outil'] : '', 60),
                'titre'  => self::texte(isset($etape['titre']) ? $etape['titre'] : '', 200),
                'statut' => self::texte(isset($etape['statut']) ? $etape['statut'] : '', 20),
                'detail' => self::texte(isset($etape['detail']) ? $etape['detail'] : '', 500),
            );
        }
        return $etapes;
    }

    private static function texte($_valeur, $_max) {
        if (is_array($_valeur) || is_object($_valeur)) {
            $_valeur = json_encode($_valeur, JSON_UNESCAPED_UNICODE);
        }
        $texte = trim((string) self::masquer((string) $_valeur));
        if (mb_strlen($texte) > $_max) {
            $texte = mb_substr($texte, 0, $_max - 1) . '…';
        }
        return $texte;
    }

    /* log::add n'existe pas dans le rejeu hors ligne, et une panne d'écriture
     * ne doit pas s'y transformer en erreur fatale. */
    private static function tracer($_niveau, $_message) {
        if (!class_exists('log')) {
            return;
        }
        try {
            log::add('k2000be', $_niveau, self::masquer($_message));
        } catch (Throwable $e) {
            /* Rien à faire de plus : on ne peut pas journaliser l'échec du
             * journal. */
        }
    }
}
