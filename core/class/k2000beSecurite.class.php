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
 * Le juge du plugin.
 *
 * Tout le reste — le moteur, les outils, l'interface — n'est qu'un moyen
 * d'amener une demande jusqu'ici. Le modèle DEMANDE, ce fichier DÉCIDE. Rien
 * de ce qu'écrit le modèle n'est une autorisation : ni le nom de la commande
 * qu'il cite, ni sa certitude, ni l'insistance de l'utilisateur.
 *
 * Deux principes tiennent le fichier entier :
 *
 * 1. Refus par défaut. Une commande dont personne n'a rien dit est interdite.
 *    Toute branche de code qui ne sait pas conclure conclut au refus. Une
 *    erreur de lecture de la configuration, une base indisponible, un mode
 *    inconnu : refus. Le coût d'un refus de trop est une phrase d'excuse ; le
 *    coût d'une autorisation de trop est une porte ouverte.
 *
 * 2. Un refus s'explique. Chaque refus porte un motif, une phrase française
 *    complète, qui part telle quelle au modèle ET s'affiche à l'utilisateur.
 *    Le modèle doit pouvoir dire « je n'ai pas le droit d'ouvrir le portail,
 *    il faut me l'autoriser dans les réglages » plutôt qu'inventer une panne.
 *
 * Les autorisations vivent dans la configuration de la commande Jeedom
 * surveillée, jamais dans celle du plugin : elles suivent la commande, et
 * survivent volontairement à une désinstallation du plugin.
 *
 * Ce fichier ne charge pas core.inc.php : il est inclus depuis
 * k2000be.class.php, qui l'a déjà fait.
 */
class k2000beSecurite {

    /* ================================ POLITIQUES D'UNE COMMANDE ACTION */

    /* L'absence de valeur vaut REFUS : c'est ce qui rend le plugin sûr sur une
     * installation de trois mille commandes que personne n'a passées en revue. */
    const POLITIQUE_REFUS = 'deny';
    const POLITIQUE_AUTORISE = 'allow';
    const POLITIQUE_CONFIRMATION = 'confirm';

    /* ================================== LECTURE D'UNE COMMANDE INFO */

    /* Pour la lecture, l'absence de valeur ne vaut pas refus mais « suit le
     * réglage lecture_defaut » : consulter une température n'ouvre aucune
     * porte, et exiger un clic sur chacun des huit cents états d'une maison
     * rendrait le plugin inutilisable le premier jour. */
    const LECTURE_DEFAUT = '';
    const LECTURE_AUTORISE = 'allow';
    const LECTURE_REFUS = 'deny';

    /* ======================================== MODES GLOBAUX DU PLUGIN */

    const MODE_LECTURE = 'lecture';
    const MODE_SIMULATION = 'simulation';
    const MODE_ACTIONS = 'actions';

    /* ================================================ CLÉS DE STOCKAGE */

    /* Clés écrites dans la configuration des objets surveillés. Courtes et
     * préfixées du nom du plugin : elles cohabitent avec celles du plugin
     * propriétaire de la commande, qui ne doit pas les voir bouger. */
    const CLE_POLITIQUE = 'k2000be';
    const CLE_LECTURE = 'k2000beRead';
    const CLE_MASQUE = 'k2000beHidden';

    /* ================================================ TYPES SENSIBLES */

    /*
     * Types génériques dont l'usage engage la sécurité ou l'intégrité physique
     * du logement.
     *
     * Cette liste n'autorise ni n'interdit rien : controler() ne consulte
     * jamais sensible(), et il n'existe aucun bouton « tout autoriser » qu'elle
     * aurait à retenir. Elle sert à l'onglet Autorisations, qui marque ces
     * commandes d'un avertissement et signale celles qui sont passées en
     * « autorisée » sans confirmation. Un drapeau visuel, donc, et rien de
     * plus : la décision reste entièrement dans la politique de la commande.
     *
     * Les noms sont ceux du cœur, relevés dans
     * core/config/jeedom.config.php (clé cmd.generic_type) de Jeedom 4.6 —
     * et non ceux qu'on imagine : le portail et le garage y partagent la
     * famille GB_*, et il n'existe ni GATE_OPEN, ni GARAGE_OPEN, ni ALARM_OFF.
     *
     * La fermeture y figure au même titre que l'ouverture : un portail ou une
     * porte de garage qui se referme sur un véhicule ou sur quelqu'un est le
     * risque le plus concret de toute cette liste.
     */
    const TYPES_SENSIBLES = array(
        'LOCK_OPEN',        /* déverrouille une serrure */
        'GB_OPEN',          /* ouvre un portail ou un garage */
        'GB_CLOSE',         /* le referme, donc sur ce qui se trouve dessous */
        'GB_TOGGLE',        /* bascule : ouvre une fois sur deux, sans le savoir */
        'ALARM_RELEASED',   /* désarme l'alarme */
        'ALARM_ARMED',      /* l'arme, donc déclenche à la première présence */
        'ALARM_SET_MODE',   /* change le mode, « désarmé » compris */
        'SIREN_ON',
        'SIREN_OFF',
    );

    /*
     * Type générique du cœur qui signifie « ne pas tenir compte de cette
     * commande ». L'administrateur l'a posé pour les tableaux de bord ; il vaut
     * aussi pour l'assistant, qui n'a rien à gagner à lire une commande que la
     * maison a déjà déclarée sans intérêt.
     */
    const TYPE_IGNORE = 'DONT';

    /* ================================================ PLAFONDS */

    /*
     * Nombre d'états dont arbre() va chercher la valeur courante. Chaque
     * valeur coûte une lecture du cache du cœur, soit un aller-retour ; sur une
     * installation de plusieurs milliers de commandes, les lire toutes ferait
     * d'un simple affichage de l'onglet Autorisations une requête d'une
     * dizaine de secondes. Au-delà du plafond, la colonne reste vide : les
     * autorisations, elles, sont toutes affichées.
     */
    const VALEURS_MAX = 800;

    /* Longueur d'une valeur de select dont le plugin propriétaire n'annonce
     * pas les choix possibles. Voir choixLibre(), qui dit pourquoi une telle
     * valeur est acceptée et jusqu'où. */
    const CHOIX_LIBRE_MAX = 255;

    /* Ce qu'un message d'erreur cite d'un select : au plus vingt choix, et
     * soixante caractères chacun. Un plugin tiers peut y mettre des phrases
     * entières, et ce texte-là part au modèle. */
    const CHOIX_CITES_MAX = 20;
    const CHOIX_TEXTE_MAX = 60;

    /* Mémoire d'un appel : sans elle, arbre() et compteurs() reliraient toute
     * la table des commandes deux fois de suite. */
    private static $commandes = null;
    private static $equipements = null;
    private static $objets = null;

    /* ==================================================== RÉGLAGES */

    /*
     * Le mode global. Un mode illisible — configuration corrompue, valeur
     * écrite à la main — rend le plus prudent des trois plutôt que le plus
     * permissif : c'est le refus par défaut appliqué au réglage lui-même.
     */
    public static function mode() {
        $mode = '';
        try {
            $mode = trim((string) config::byKey('securite', 'k2000be', self::MODE_ACTIONS));
        } catch (Throwable $e) {
            return self::MODE_LECTURE;
        }
        if ($mode === '') {
            return self::MODE_ACTIONS;
        }
        if (!in_array($mode, array(self::MODE_LECTURE, self::MODE_SIMULATION, self::MODE_ACTIONS), true)) {
            return self::MODE_LECTURE;
        }
        return $mode;
    }

    public static function lectureParDefaut() {
        try {
            return ((int) config::byKey('lecture_defaut', 'k2000be', 1) === 1);
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ==================================================== POLITIQUES */

    /*
     * La politique d'une commande action, et rien d'autre : ni le mode global,
     * ni l'état de l'équipement n'entrent ici. C'est controler() qui compose
     * les sept contrôles ; cette méthode-ci répond à « qu'a décidé
     * l'administrateur pour CETTE commande », y compris quand la réponse est
     * « rien », auquel cas c'est un refus.
     */
    public static function politique($_cmd) {
        if (!is_object($_cmd)) {
            return self::POLITIQUE_REFUS;
        }
        $politique = '';
        try {
            $politique = trim((string) $_cmd->getConfiguration(self::CLE_POLITIQUE, ''));
        } catch (Throwable $e) {
            return self::POLITIQUE_REFUS;
        }
        if ($politique === self::POLITIQUE_AUTORISE || $politique === self::POLITIQUE_CONFIRMATION) {
            return $politique;
        }
        return self::POLITIQUE_REFUS;
    }

    /*
     * La lecture d'une commande info, tout compris.
     *
     * Contrairement à politique(), cette méthode juge l'équipement autant que
     * la commande : il n'existe pas de controler() pour la lecture, et c'est
     * donc ici — et nulle part ailleurs — qu'un équipement masqué disparaît du
     * champ de vision de l'assistant. Tout ce qui lit un état passe par elle.
     *
     * L'équipement peut être fourni pour éviter une requête par commande :
     * l'arbre en parcourt des milliers d'un coup.
     */
    public static function lisible($_cmd, $_eqLogic = null) {
        if (!is_object($_cmd)) {
            return false;
        }
        try {
            if ($_cmd->getType() !== 'info') {
                return false;
            }
            if ($_cmd->getGeneric_type() === self::TYPE_IGNORE) {
                return false;
            }
            $eqLogic = is_object($_eqLogic) ? $_eqLogic : $_cmd->getEqLogic();
            if (!self::equipementVisible($eqLogic)) {
                return false;
            }
            $choix = trim((string) $_cmd->getConfiguration(self::CLE_LECTURE, ''));
        } catch (Throwable $e) {
            return false;
        }

        if ($choix === self::LECTURE_REFUS) {
            return false;
        }
        if ($choix === self::LECTURE_AUTORISE) {
            return true;
        }
        return self::lectureParDefaut();
    }

    /* Valeur brute du réglage de lecture, sans le défaut global ni l'état de
     * l'équipement : l'onglet Autorisations a besoin de savoir si le choix est
     * explicite ou hérité. */
    public static function lectureBrute($_cmd) {
        if (!is_object($_cmd)) {
            return self::LECTURE_DEFAUT;
        }
        $choix = trim((string) $_cmd->getConfiguration(self::CLE_LECTURE, ''));
        if ($choix === self::LECTURE_AUTORISE || $choix === self::LECTURE_REFUS) {
            return $choix;
        }
        return self::LECTURE_DEFAUT;
    }

    public static function sensible($_cmd) {
        if (!is_object($_cmd)) {
            return false;
        }
        return in_array((string) $_cmd->getGeneric_type(), self::TYPES_SENSIBLES, true);
    }

    /*
     * Un équipement est dans le champ de vision de l'assistant s'il existe, est
     * activé, n'est pas masqué, et n'est pas un assistant lui-même.
     *
     * Le dernier point n'est pas une précaution de style : sans lui, le modèle
     * verrait la commande « Demander » d'un assistant, pourrait l'appeler, et
     * une conversation en lancerait une autre — chacune consommant des jetons
     * et pouvant à son tour en lancer une troisième.
     */
    public static function equipementVisible($_eqLogic) {
        if (!is_object($_eqLogic)) {
            return false;
        }
        try {
            if ($_eqLogic->getEqType_name() === 'k2000be') {
                return false;
            }
            if ((int) $_eqLogic->getIsEnable() !== 1) {
                return false;
            }
            return ((int) $_eqLogic->getConfiguration(self::CLE_MASQUE, 0) !== 1);
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ================================================= ÉCRITURE DES CHOIX */

    /*
     * Ces trois méthodes écrivent dans des objets qui appartiennent à d'autres
     * plugins. C'est assumé, et c'est la seule façon d'attacher l'autorisation
     * à la commande plutôt qu'à une table du plugin : $cmd->save() passera par
     * le preSave() du plugin propriétaire, comme le fait la page de
     * configuration d'une commande dans le cœur.
     *
     * Elles lèvent sur saisie invalide, à la différence du reste du fichier :
     * elles ne sont appelées que par l'interface d'administration, où une
     * erreur doit se voir tout de suite et non se traduire par un réglage qui
     * n'a pas pris.
     */
    public static function definirPolitique($_cmdId, $_politique) {
        $politique = trim((string) $_politique);
        if (!in_array($politique, array(self::POLITIQUE_REFUS, self::POLITIQUE_AUTORISE, self::POLITIQUE_CONFIRMATION), true)) {
            throw new Exception(sprintf(__('Autorisation inconnue : %s.', __FILE__), $politique));
        }
        $cmd = cmd::byId((int) $_cmdId);
        if (!is_object($cmd)) {
            throw new Exception(sprintf(__('Commande introuvable (identifiant %s).', __FILE__), (int) $_cmdId));
        }
        if ($cmd->getType() !== 'action') {
            throw new Exception(__('Seule une commande de type action porte une autorisation d\'exécution.', __FILE__));
        }
        $cmd->setConfiguration(self::CLE_POLITIQUE, $politique);
        $cmd->save();
        self::oublier();
        return true;
    }

    public static function definirLecture($_cmdId, $_valeur) {
        $valeur = trim((string) $_valeur);
        if (!in_array($valeur, array(self::LECTURE_DEFAUT, self::LECTURE_AUTORISE, self::LECTURE_REFUS), true)) {
            throw new Exception(sprintf(__('Réglage de lecture inconnu : %s.', __FILE__), $valeur));
        }
        $cmd = cmd::byId((int) $_cmdId);
        if (!is_object($cmd)) {
            throw new Exception(sprintf(__('Commande introuvable (identifiant %s).', __FILE__), (int) $_cmdId));
        }
        if ($cmd->getType() !== 'info') {
            throw new Exception(__('Seule une commande de type info porte un réglage de lecture.', __FILE__));
        }
        $cmd->setConfiguration(self::CLE_LECTURE, $valeur);
        $cmd->save();
        self::oublier();
        return true;
    }

    public static function masquerEquipement($_eqLogicId, $_masque) {
        $eqLogic = eqLogic::byId((int) $_eqLogicId);
        if (!is_object($eqLogic)) {
            throw new Exception(sprintf(__('Équipement introuvable (identifiant %s).', __FILE__), (int) $_eqLogicId));
        }
        if ($eqLogic->getEqType_name() === 'k2000be') {
            throw new Exception(__('Un assistant est déjà invisible pour les assistants : il ne se pilote pas lui-même.', __FILE__));
        }
        $eqLogic->setConfiguration(self::CLE_MASQUE, ((int) $_masque === 1) ? 1 : 0);
        $eqLogic->save();
        self::oublier();
        return true;
    }

    /* ============================================ LE CONTRÔLE AVANT ACTION */

    /*
     * Les sept contrôles du cahier des charges, dans l'ordre, arrêt au premier
     * refus. L'ordre n'est pas décoratif : chaque contrôle suppose que le
     * précédent est passé, et surtout le motif rendu doit désigner la VRAIE
     * cause. Dire « valeur hors bornes » d'une commande de toute façon
     * interdite enverrait l'administrateur régler le mauvais problème.
     *
     * La visibilité de l'équipement passe avant le type de la commande, et ce
     * n'est pas un détail de présentation : le contrôle « c'est un état, pas
     * une action » nomme l'équipement ET l'état. Placé avant la visibilité, il
     * livrait les deux noms d'une commande appartenant à un équipement masqué,
     * c'est-à-dire exactement ce que le masquage promet de ne jamais dire.
     *
     * Ne lève jamais : une demande impossible est un refus motivé, pas une
     * panne. Le modèle doit pouvoir l'expliquer, et la conversation continuer.
     */
    public static function controler($_cmdId, $_valeur = null) {
        $id = (int) $_cmdId;

        /* 1 — la commande existe */
        $cmd = null;
        try {
            $cmd = cmd::byId($id);
        } catch (Throwable $e) {
            $cmd = null;
        }
        if (!is_object($cmd)) {
            return self::refusIndistinct($id,
                sprintf(__('Aucune commande ne porte l\'identifiant %s : elle a été supprimée, ou cet identifiant est inventé.', __FILE__), $id));
        }

        $titre = self::titre($cmd);

        /* 2 — l'équipement existe, est activé, n'est pas masqué */
        $eqLogic = null;
        try {
            $eqLogic = $cmd->getEqLogic();
        } catch (Throwable $e) {
            $eqLogic = null;
        }
        if (!is_object($eqLogic)) {
            return self::refusIndistinct($id,
                sprintf(__('L\'équipement de « %s » n\'existe plus.', __FILE__), $titre),
                $cmd, null, $titre);
        }
        if ((int) $eqLogic->getIsEnable() !== 1) {
            return self::refusIndistinct($id,
                sprintf(__('L\'équipement « %s » est désactivé dans Jeedom : aucune de ses commandes ne peut partir.', __FILE__), $eqLogic->getName()),
                $cmd, $eqLogic, $titre);
        }
        if ((int) $eqLogic->getConfiguration(self::CLE_MASQUE, 0) === 1) {
            return self::refusIndistinct($id,
                sprintf(__('L\'équipement « %s » a été masqué à l\'assistant : il ne le voit pas et ne peut rien lui demander.', __FILE__), $eqLogic->getName()),
                $cmd, $eqLogic, $titre);
        }

        /* 3 — ce n'est pas un assistant */
        if ($eqLogic->getEqType_name() === 'k2000be') {
            return self::refusIndistinct($id,
                __('Un assistant ne se pilote pas lui-même, ni n\'en pilote un autre.', __FILE__),
                $cmd, $eqLogic, $titre);
        }

        /* 4 — c'est bien une action */
        if ($cmd->getType() !== 'action') {
            $motif = sprintf(__('« %s » est un état, pas une commande : il se consulte, il ne s\'exécute pas.', __FILE__), $titre);
            /*
             * L'équipement, lui, est visible ; reste à savoir si CET état
             * l'est. S'il est lisible, le modèle en connaît déjà le nom —
             * get_equipment ou get_states le lui ont donné — et le lui
             * rappeler l'aide à corriger son appel sans rien lui apprendre.
             * S'il ne l'est pas, le nommer trahirait un état que
             * l'administrateur a mis hors de portée : refus indistinct.
             */
            if ($cmd->getType() === 'info' && self::lisible($cmd, $eqLogic)) {
                return self::verdict(self::POLITIQUE_REFUS, $motif, $cmd, $eqLogic, $titre);
            }
            return self::refusIndistinct($id, $motif, $cmd, $eqLogic, $titre);
        }

        /* 5 — le mode global */
        $mode = self::mode();
        if ($mode === self::MODE_LECTURE) {
            $motif = __('L\'assistant est réglé en lecture seule : il peut consulter la maison, mais aucune commande ne peut être exécutée. Ce réglage se change dans la configuration du plugin.', __FILE__);
            /*
             * Le mode ne doit pas court-circuiter le filtre du contrôle
             * suivant. Une action marquée « ne pas tenir compte » et non
             * autorisée est retirée de tout ce que le modèle lit ; la nommer
             * ici parce que le plugin se trouve en lecture seule livrerait son
             * nom pour un réglage global qui n'a rien à voir avec elle — et le
             * même identifiant rendrait le nom ou non selon ce réglage.
             */
            if ($cmd->getGeneric_type() === self::TYPE_IGNORE && self::politique($cmd) === self::POLITIQUE_REFUS) {
                return self::refusIndistinct($id, $motif, $cmd, $eqLogic, $titre);
            }
            return self::verdict(self::POLITIQUE_REFUS, $motif, $cmd, $eqLogic, $titre);
        }

        /* 6 — la politique de la commande */
        $politique = self::politique($cmd);
        if ($politique === self::POLITIQUE_REFUS) {
            $motif = sprintf(__('« %s » n\'est pas autorisée pour l\'assistant. Toute commande est interdite tant qu\'elle n\'a pas été autorisée dans l\'onglet Autorisations du plugin.', __FILE__), $titre);
            /*
             * Une action interdite est normalement nommée : le modèle l'a lue
             * dans get_equipment avec sa politique « forbidden », et ce motif
             * est ce qui lui fait dire « il faut me l'autoriser » plutôt
             * qu'inventer une panne.
             *
             * Sauf celles que l'administrateur a marquées « ne pas tenir
             * compte » : k2000beOutils::getEquipment les retire de sa réponse
             * quand elles sont interdites, et les nommer ici les ferait
             * découvrir par le refus — le filtre d'à côté n'aurait servi à
             * rien.
             */
            if ($cmd->getGeneric_type() === self::TYPE_IGNORE) {
                return self::refusIndistinct($id, $motif, $cmd, $eqLogic, $titre);
            }
            return self::verdict(self::POLITIQUE_REFUS, $motif, $cmd, $eqLogic, $titre);
        }

        /* 7 — la valeur */
        $valeur = null;
        try {
            $valeur = self::normaliserValeur($cmd, $_valeur);
        } catch (Throwable $e) {
            return self::verdict(self::POLITIQUE_REFUS, $e->getMessage(), $cmd, $eqLogic, $titre);
        }

        if ($politique === self::POLITIQUE_CONFIRMATION) {
            /*
             * Le mode simulation se dit ICI aussi, et pas seulement deux
             * lignes plus bas. Ce motif est recopié tel quel dans la commande
             * « Objet de la confirmation » et dans le bandeau que l'utilisateur
             * lit avant de cliquer : sans cette phrase, on lui fait engager sa
             * responsabilité sur une ouverture de portail qui ne partira pas.
             * La confirmation est conservée — c'est ce qui permet d'éprouver le
             * parcours entier sans rien risquer — mais la question dit la
             * vérité.
             */
            $motif = ($mode === self::MODE_SIMULATION)
                ? sprintf(__('« %s » demande une confirmation humaine, mais l\'assistant est en mode simulation : même confirmée, elle ne partira pas vers la domotique.', __FILE__), $titre)
                : sprintf(__('« %s » demande une confirmation humaine avant de partir.', __FILE__), $titre);
            return self::verdict(self::POLITIQUE_CONFIRMATION, $motif, $cmd, $eqLogic, $titre, $valeur);
        }

        /* Autorisée. Le motif sert alors à la frise et au journal : il dit ce
         * qui a été décidé, pas seulement que rien n'a été refusé. */
        if ($mode === self::MODE_SIMULATION) {
            return self::verdict(self::POLITIQUE_AUTORISE,
                sprintf(__('« %s » est autorisée, mais l\'assistant est en mode simulation : rien ne partira réellement vers la domotique.', __FILE__), $titre),
                $cmd, $eqLogic, $titre, $valeur);
        }
        return self::verdict(self::POLITIQUE_AUTORISE,
            sprintf(__('« %s » est autorisée pour l\'assistant.', __FILE__), $titre),
            $cmd, $eqLogic, $titre, $valeur);
    }

    /*
     * Le verdict, et pourquoi il porte deux motifs.
     *
     * Un motif de refus n'a pas un destinataire mais deux : l'utilisateur, qui
     * le lit dans la frise et dans le journal, et le modèle, à qui
     * k2000beOutils::appliquer() recopie le refus dans le résultat de
     * execute_command — d'où il repart chez OpenAI, et y reste pour tout le
     * reste de la conversation.
     *
     * Ces deux-là n'ont pas droit à la même phrase. « L'équipement Coffre-fort
     * a été masqué » est exactement ce que l'administrateur doit lire ; le
     * dire au modèle lui livrerait, identifiant après identifiant, le nom des
     * équipements que cet administrateur venait justement de cacher.
     *
     * D'où quatre champs plutôt que deux :
     *
     * - 'motif' et 'titre' : la vérité, pour l'humain ;
     * - 'motif_modele' et 'titre_modele' : ce qui part chez OpenAI.
     *
     * Hors des refus indistincts les deux paires sont identiques, et c'est le
     * cas courant : un refus d'autorisation doit être dit en clair au modèle,
     * sans quoi il invente une panne. Seul k2000beOutils lit ces deux derniers
     * champs ; l'onglet Autorisations et la frise n'en ont que faire.
     */
    private static function verdict($_decision, $_motif, $_cmd, $_eqLogic, $_titre, $_valeur = null, $_motifModele = null, $_titreModele = null) {
        return array(
            'decision'     => $_decision,
            'motif'        => $_motif,
            'cmd'          => is_object($_cmd) ? $_cmd : null,
            'valeur'       => $_valeur,
            'titre'        => $_titre,
            'motif_modele' => ($_motifModele === null) ? $_motif : $_motifModele,
            'titre_modele' => ($_titreModele === null) ? $_titre : $_titreModele,
        );
    }

    /*
     * Le refus dont le modèle ne peut rien tirer : la vérité pour l'humain, un
     * identifiant nu et un verdict indistinct pour la machine.
     *
     * Commande inexistante, équipement disparu, désactivé, masqué, assistant,
     * état non lisible, action marquée « ne pas tenir compte » : tous rendent
     * au modèle LE MÊME texte, au mot près. C'est la condition pour qu'il ne
     * distingue rien — deux formulations différentes sont déjà une
     * information, et le modèle qui énumère execute_command sur 1, 2, 3…
     * apprendrait lesquels de ces identifiants existent.
     *
     * Ce qu'il apprend à la place est vrai pour lui : cet identifiant ne mène à
     * rien qu'il puisse exécuter. C'est la même réponse que celle déjà rendue
     * par get_states et get_equipment aux identifiants hors de leur portée.
     */
    private static function refusIndistinct($_id, $_motifHumain, $_cmd = null, $_eqLogic = null, $_titre = null) {
        $id = (int) $_id;
        return self::verdict(self::POLITIQUE_REFUS,
            $_motifHumain, $_cmd, $_eqLogic,
            ($_titre === null || $_titre === '') ? self::titreIndistinct($id) : $_titre,
            null,
            self::motifIndistinct($id),
            self::titreIndistinct($id));
    }

    /* Le texte unique de tous les refus indistincts. Il ne ment pas au modèle :
     * du point de vue de l'assistant, cet identifiant ne désigne réellement
     * aucune commande exécutable. La dernière phrase lui épargne de brûler ses
     * appels d'outils à essayer les identifiants voisins. */
    private static function motifIndistinct($_id) {
        return sprintf(__('Aucune commande exécutable ne porte l\'identifiant %s pour l\'assistant. N\'essayez pas d\'autres identifiants au hasard, et ne parlez pas de cette commande à l\'utilisateur comme si elle existait.', __FILE__), (int) $_id);
    }

    /* Le libellé rendu au modèle quand la commande ne doit pas être nommée :
     * l'identifiant qu'il a lui-même envoyé, et rien d'autre. */
    private static function titreIndistinct($_id) {
        return sprintf(__('Commande %s', __FILE__), (int) $_id);
    }

    /* « Lumière salon — Éteindre ». Pas getHumanName(), dont les crochets
     * ([Salon][Lumière salon][Éteindre]) sont faits pour les expressions de
     * scénario et se lisent mal dans une phrase adressée à l'utilisateur. */
    public static function titre($_cmd, $_eqLogic = null) {
        if (!is_object($_cmd)) {
            return '';
        }
        /* getEqLogic() interroge le cœur, donc la base : une exception ici
         * traverserait controler(), dont l'en-tête promet qu'il ne lève
         * jamais, et couperait la conversation au lieu de la refuser. */
        try {
            $eqLogic = is_object($_eqLogic) ? $_eqLogic : $_cmd->getEqLogic();
            if (!is_object($eqLogic)) {
                return (string) $_cmd->getName();
            }
            return $eqLogic->getName() . ' — ' . $_cmd->getName();
        } catch (Throwable $e) {
            return (string) $_cmd->getName();
        }
    }

    /* ================================================ VALEUR D'UNE ACTION */

    /*
     * Vérifie la valeur proposée par le modèle et la ramène à ce qu'attend le
     * cœur. Lève sur valeur invalide ; controler() transforme le message en
     * motif de refus.
     *
     * Pourquoi refuser plutôt que borner : un modèle qui demande 250 sur un
     * variateur de 0 à 100 s'est trompé quelque part — sur l'échelle, sur
     * l'unité, ou de commande. Ramener silencieusement à 100 exécuterait une
     * demande que personne n'a formulée, et le modèle rapporterait ensuite
     * « j'ai mis 250 ». Refuser lui donne la chance de corriger.
     */
    public static function normaliserValeur($_cmd, $_valeur) {
        if (!is_object($_cmd)) {
            return null;
        }
        $sousType = (string) $_cmd->getSubType();
        $nom = self::titre($_cmd);

        switch ($sousType) {

            case 'slider':
                if ($_valeur === null || $_valeur === '' || is_array($_valeur)) {
                    throw new Exception(sprintf(__('« %s » attend une valeur numérique, et aucune n\'a été fournie.', __FILE__), $nom));
                }
                if (!is_numeric($_valeur)) {
                    throw new Exception(sprintf(__('« %s » attend une valeur numérique ; « %s » n\'en est pas une.', __FILE__), $nom, (string) $_valeur));
                }
                $valeur = $_valeur + 0;
                /*
                 * is_numeric() accepte « 1e999 », que PHP convertit en INF.
                 * Une commande sans bornes le laissait passer jusqu'au cœur,
                 * et surtout jusqu'à la conversation enregistrée : INF n'est
                 * pas encodable en JSON, l'enregistrement échouait, et le tour
                 * cessait silencieusement d'être consigné. Le select a son
                 * garde-fou ; voici celui du slider.
                 */
                if (!is_finite((float) $valeur)) {
                    throw new Exception(sprintf(__('« %s » attend une valeur numérique ordinaire ; « %s » n\'en est pas une.', __FILE__), $nom, (string) $_valeur));
                }
                $min = $_cmd->getConfiguration('minValue', '');
                $max = $_cmd->getConfiguration('maxValue', '');
                $borneBasse = ($min === '' || $min === null) ? null : $min + 0;
                $borneHaute = ($max === '' || $max === null) ? null : $max + 0;
                /*
                 * Des bornes à l'envers ne refusent pas une valeur : elles
                 * refusent toutes les valeurs. Envoyer le modèle corriger la
                 * sienne le ferait essayer indéfiniment ; le vrai problème est
                 * dans la configuration de la commande, et c'est cela qu'il
                 * faut pouvoir rapporter à l'utilisateur.
                 */
                if ($borneBasse !== null && $borneHaute !== null && $borneBasse > $borneHaute) {
                    throw new Exception(sprintf(
                        __('« %s » est mal configurée dans Jeedom : sa valeur minimale (%s) est supérieure à sa valeur maximale (%s). Aucune valeur ne peut lui convenir tant que ce réglage n\'est pas corrigé.', __FILE__),
                        $nom, (string) $borneBasse, (string) $borneHaute));
                }
                if (($borneBasse !== null && $valeur < $borneBasse) || ($borneHaute !== null && $valeur > $borneHaute)) {
                    throw new Exception(sprintf(
                        __('La valeur %s sort des limites de « %s », qui accepte de %s à %s.', __FILE__),
                        (string) $valeur, $nom,
                        ($borneBasse === null ? '?' : (string) $borneBasse),
                        ($borneHaute === null ? '?' : (string) $borneHaute)
                    ));
                }
                return $valeur;

            case 'select':
                if ($_valeur === null || $_valeur === '' || is_array($_valeur)) {
                    throw new Exception(sprintf(__('« %s » attend un choix, et aucun n\'a été fourni.', __FILE__), $nom));
                }
                return self::valeurDansListe($_cmd, (string) $_valeur, $nom);

            case 'color':
                if (is_array($_valeur)) {
                    throw new Exception(sprintf(__('« %s » attend une couleur au format #rrggbb.', __FILE__), $nom));
                }
                $couleur = strtolower(trim((string) $_valeur));
                if ($couleur !== '' && substr($couleur, 0, 1) !== '#') {
                    $couleur = '#' . $couleur;
                }
                /* La forme courte à trois chiffres est du CSS parfaitement
                 * ordinaire, et c'est ce qu'un modèle écrit une fois sur deux.
                 * Elle se déplie sans rien interpréter : #abc vaut #aabbcc, et
                 * le cœur, lui, n'accepte que la forme longue. */
                if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $couleur, $trouve)) {
                    $couleur = '#' . $trouve[1] . $trouve[1] . $trouve[2] . $trouve[2] . $trouve[3] . $trouve[3];
                }
                if (!preg_match('/^#[0-9a-f]{6}$/', $couleur)) {
                    throw new Exception(sprintf(__('« %s » attend une couleur au format #rrggbb ; « %s » n\'en est pas une.', __FILE__), $nom, (string) $_valeur));
                }
                return $couleur;

            case 'message':
                $titre = '';
                $message = '';
                if (is_array($_valeur)) {
                    $titre = isset($_valeur['title']) ? (string) $_valeur['title'] : (isset($_valeur['titre']) ? (string) $_valeur['titre'] : '');
                    $message = isset($_valeur['message']) ? (string) $_valeur['message'] : '';
                } else {
                    $message = (string) $_valeur;
                }
                if (trim($message) === '') {
                    throw new Exception(sprintf(__('« %s » attend un message, et aucun n\'a été fourni.', __FILE__), $nom));
                }
                return array('title' => $titre, 'message' => $message);

            default:
                /*
                 * Sous-type « other » et tous ceux qu'on ne connaît pas : la
                 * commande s'exécute sans paramètre. Une valeur proposée n'est
                 * pas une erreur du modèle — il ne sait pas toujours — mais
                 * elle est ignorée plutôt que transmise à l'aveugle.
                 *
                 * Ignorée ne veut pas dire tue : k2000beOutils::appliquer()
                 * compare la valeur reçue à celle qui est réellement partie et
                 * le dit au modèle, faute de quoi il rapporterait « j'ai réglé
                 * le mode sur 30 » alors que la commande est partie nue.
                 */
                return null;
        }
    }

    private static function valeurDansListe($_cmd, $_valeur, $_nom) {
        $liste = trim((string) $_cmd->getConfiguration('listValue', ''));
        if ($liste === '') {
            return self::choixLibre($_valeur, $_nom);
        }

        $valeurs = array();
        $libelles = array();
        foreach (explode(';', $liste) as $element) {
            $couple = explode('|', $element);
            $valeur = trim($couple[0]);
            if ($valeur === '') {
                continue;
            }
            $valeurs[] = $valeur;
            $libelles[$valeur] = isset($couple[1]) ? trim($couple[1]) : $valeur;
        }
        if (empty($valeurs)) {
            /* Liste présente mais illisible — des points-virgules et rien
             * d'autre : c'est le cas précédent déguisé. */
            return self::choixLibre($_valeur, $_nom);
        }

        foreach ($valeurs as $valeur) {
            if (strcasecmp($valeur, $_valeur) === 0) {
                return $valeur;
            }
        }
        /* Le modèle a de bonnes chances de renvoyer le LIBELLÉ lu dans
         * get_equipment plutôt que la valeur technique : « Confort » pour
         * « 1 ». Le traduire ici évite un refus que l'utilisateur jugerait
         * absurde.
         *
         * Le transtypage n'est pas décoratif : PHP convertit en entier toute
         * clé de tableau qui ressemble à un entier, si bien que $libelles['1']
         * se relit en 1. Sans lui, « Confort » rendait l'entier 1 là où « 1 »
         * rendait la chaîne « 1 » — deux chemins vers la même commande, deux
         * valeurs différentes dans la frise et dans le journal. La chaîne est
         * ce que porte réellement le listValue de Jeedom. */
        foreach ($libelles as $valeur => $libelle) {
            if (strcasecmp($libelle, $_valeur) === 0) {
                return (string) $valeur;
            }
        }

        /* Le motif part chez OpenAI et y reste pour toute la conversation : la
         * liste citée est bornée comme l'est celle de get_equipment, sans quoi
         * un select de deux cents entrées se paierait à chaque tour. */
        throw new Exception(sprintf(
            __('« %s » n\'accepte pas « %s ». Les choix possibles sont : %s.', __FILE__),
            $_nom, self::abreger($_valeur, 60), self::listeCourte($libelles)
        ));
    }

    /* Les premiers choix d'un select, abrégés, pour un message d'erreur. */
    private static function listeCourte($_libelles) {
        $morceaux = array();
        foreach ($_libelles as $libelle) {
            $morceaux[] = self::abreger((string) $libelle, self::CHOIX_TEXTE_MAX);
            if (count($morceaux) >= self::CHOIX_CITES_MAX) {
                $morceaux[] = '…';
                break;
            }
        }
        return implode(', ', $morceaux);
    }

    private static function abreger($_texte, $_max) {
        $texte = trim((string) $_texte);
        if (mb_strlen($texte) > $_max) {
            return mb_substr($texte, 0, $_max - 1) . '…';
        }
        return $texte;
    }

    /*
     * Le select dont le plugin propriétaire n'a pas rempli listValue.
     *
     * Arbitrage, puisqu'il se discute : la valeur passe. Ce n'est pas une
     * entorse au refus par défaut, parce que ce qui s'autorise ou se refuse
     * ici est la COMMANDE, pas son paramètre — et controler() a déjà vérifié,
     * deux contrôles plus haut, que l'administrateur l'a explicitement
     * autorisée. Refuser ferait payer à l'utilisateur un réglage manquant qui
     * n'est ni le sien ni celui du plugin : il est dans un plugin tiers, et
     * personne ici n'a les moyens de deviner les choix qu'il attend. La
     * commande deviendrait inutilisable sans aucun recours.
     *
     * Ce qui ne passe pas, en revanche, c'est une valeur qui n'a pas la forme
     * d'un choix. Sans liste pour la borner, cette chaîne part telle quelle
     * dans la substitution de #select# du cœur, donc au milieu de la requête
     * que le plugin propriétaire fabrique. Un choix tient sur une ligne et en
     * quelques dizaines de caractères ; un pavé multiligne de deux kilo-octets
     * n'en est pas un, et le modèle qui l'envoie s'est trompé de commande.
     * C'est le seul point où le refus par défaut a encore quelque chose à dire
     * sur une valeur libre, et il le dit.
     */
    private static function choixLibre($_valeur, $_nom) {
        $valeur = trim((string) $_valeur);
        if ($valeur === '') {
            throw new Exception(sprintf(__('« %s » attend un choix, et aucun n\'a été fourni.', __FILE__), $_nom));
        }
        if (mb_strlen($valeur) > self::CHOIX_LIBRE_MAX) {
            /* La valeur refusée n'est pas recopiée dans le motif : elle est
             * précisément trop longue pour cela, et le motif part au modèle. */
            throw new Exception(sprintf(
                __('« %s » n\'annonce pas les choix qu\'elle accepte, et la valeur proposée est trop longue pour en être un : %d caractères au maximum.', __FILE__),
                $_nom, self::CHOIX_LIBRE_MAX));
        }
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $valeur)) {
            throw new Exception(sprintf(
                __('« %s » n\'annonce pas les choix qu\'elle accepte, et un choix tient sur une seule ligne, sans caractère de contrôle.', __FILE__),
                $_nom));
        }
        return $valeur;
    }

    /*
     * Traduit la valeur normalisée en options de cmd::execCmd(). Les clés sont
     * celles que le cœur attend (cmd.class.php, substitution de #slider#,
     * #color#, #select# et #message#) : s'en écarter ferait partir la commande
     * sans son paramètre, donc réussir une action vide.
     */
    public static function options($_cmd, $_valeur) {
        if (!is_object($_cmd) || $_valeur === null) {
            return null;
        }
        switch ((string) $_cmd->getSubType()) {
            case 'slider':
                return array('slider' => $_valeur);
            case 'select':
                return array('select' => $_valeur);
            case 'color':
                return array('color' => $_valeur);
            case 'message':
                return is_array($_valeur) ? $_valeur : array('title' => '', 'message' => (string) $_valeur);
            default:
                return null;
        }
    }

    /* ============================================ MATIÈRE DE L'INTERFACE */

    /*
     * L'arbre pièce → équipement → commandes de l'onglet Autorisations.
     *
     * Deux différences volontaires avec ce que voit le modèle : les
     * équipements désactivés et les équipements masqués y figurent — l'onglet
     * sert justement à les démasquer —, mais les assistants n'y figurent pas,
     * car aucun réglage n'aurait de sens sur eux.
     *
     * Trois requêtes en tout, et non une par équipement : sur une installation
     * de deux cents équipements, la version naïve tenait la minute.
     */
    public static function arbre() {
        $objets = self::objets();
        $equipements = self::equipements();
        $commandes = self::commandes();

        /* Les commandes rangées par équipement une fois pour toutes. */
        $parEquipement = array();
        foreach ($commandes as $cmd) {
            $eqId = (int) $cmd->getEqLogic_id();
            if (!isset($parEquipement[$eqId])) {
                $parEquipement[$eqId] = array();
            }
            $parEquipement[$eqId][] = $cmd;
        }

        $pieces = array();
        $valeursLues = 0;

        foreach ($equipements as $eqLogic) {
            if ($eqLogic->getEqType_name() === 'k2000be') {
                continue;
            }
            $eqId = (int) $eqLogic->getId();
            $objetId = (int) $eqLogic->getObject_id();
            $piece = isset($objets[$objetId]) ? $objets[$objetId] : __('Sans pièce', __FILE__);

            if (!isset($pieces[$piece])) {
                $pieces[$piece] = array('objet' => $piece, 'equipements' => array());
            }

            $liste = array();
            $cmds = isset($parEquipement[$eqId]) ? $parEquipement[$eqId] : array();
            foreach ($cmds as $cmd) {
                if ($cmd->getType() === 'action') {
                    $liste[] = array(
                        'id'        => (int) $cmd->getId(),
                        'nom'       => (string) $cmd->getName(),
                        'type'      => 'action',
                        'sousType'  => (string) $cmd->getSubType(),
                        'generique' => (string) $cmd->getGeneric_type(),
                        'politique' => self::politique($cmd),
                        'sensible'  => self::sensible($cmd),
                    );
                    continue;
                }
                if ($cmd->getType() !== 'info') {
                    continue;
                }

                /*
                 * La lecture effective, et non le réglage brut : l'onglet
                 * n'offre que deux boutons, Lisible et Masquée, et un réglage
                 * vide n'en éclairerait aucun des deux. 'heritee' dit à
                 * l'interface que ce choix vient du réglage global du plugin et
                 * non d'un clic sur cette commande.
                 */
                $lisible = self::lisible($cmd, $eqLogic);
                $ligne = array(
                    'id'        => (int) $cmd->getId(),
                    'nom'       => (string) $cmd->getName(),
                    'type'      => 'info',
                    'sousType'  => (string) $cmd->getSubType(),
                    'generique' => (string) $cmd->getGeneric_type(),
                    'lecture'   => $lisible ? self::LECTURE_AUTORISE : self::LECTURE_REFUS,
                    'heritee'   => (self::lectureBrute($cmd) === self::LECTURE_DEFAUT),
                    'valeur'    => '',
                    'unite'     => (string) $cmd->getUnite(),
                );
                if ($valeursLues < self::VALEURS_MAX) {
                    $valeursLues++;
                    try {
                        $valeur = $cmd->execCmd();
                        $ligne['valeur'] = is_scalar($valeur) ? (string) $valeur : '';
                    } catch (Throwable $e) {
                        $ligne['valeur'] = '';
                    }
                }
                $liste[] = $ligne;
            }

            $pieces[$piece]['equipements'][] = array(
                'id'        => $eqId,
                'nom'       => (string) $eqLogic->getName(),
                'type'      => (string) $eqLogic->getEqType_name(),
                'masque'    => ((int) $eqLogic->getConfiguration(self::CLE_MASQUE, 0) === 1),
                'actif'     => ((int) $eqLogic->getIsEnable() === 1),
                'commandes' => $liste,
            );
        }

        /* « Sans pièce » en dernier : c'est le fourre-tout, pas le sujet. */
        $sans = __('Sans pièce', __FILE__);
        $fourreTout = isset($pieces[$sans]) ? $pieces[$sans] : null;
        unset($pieces[$sans]);
        ksort($pieces, SORT_FLAG_CASE | SORT_NATURAL);
        $arbre = array_values($pieces);
        if ($fourreTout !== null) {
            $arbre[] = $fourreTout;
        }
        return $arbre;
    }

    /*
     * Les compteurs de l'en-tête. Ils comptent l'état EFFECTIF, pas le réglage
     * écrit : les commandes d'un équipement masqué ou désactivé comptent pour
     * interdites, puisque c'est ce qui leur arrivera. Un compteur qui
     * annoncerait douze commandes autorisées dont trois inatteignables
     * mentirait précisément là où l'on vient chercher une certitude.
     *
     * Le mode global en fait partie, et pour la même raison : en lecture seule,
     * AUCUNE action ne partira, quelle que soit sa politique. Ces compteurs
     * partent dans le résumé de la maison, donc dans l'invite système à chaque
     * tour ; annoncer là « douze commandes exécutables » à une installation en
     * lecture seule contredirait mot pour mot ce que les outils répondent
     * ensuite sur les mêmes commandes.
     */
    public static function compteurs() {
        $compteurs = array('autorisees' => 0, 'confirmation' => 0, 'interdites' => 0, 'lisibles' => 0,
            'sensibles' => 0);
        $lectureSeule = (self::mode() === self::MODE_LECTURE);

        $equipements = array();
        foreach (self::equipements() as $eqLogic) {
            $equipements[(int) $eqLogic->getId()] = $eqLogic;
        }

        foreach (self::commandes() as $cmd) {
            $eqId = (int) $cmd->getEqLogic_id();
            $eqLogic = isset($equipements[$eqId]) ? $equipements[$eqId] : null;
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() === 'k2000be') {
                continue;
            }
            $visible = self::equipementVisible($eqLogic);

            if ($cmd->getType() === 'action') {
                $politique = ($visible && !$lectureSeule) ? self::politique($cmd) : self::POLITIQUE_REFUS;
                if ($politique === self::POLITIQUE_AUTORISE) {
                    $compteurs['autorisees']++;
                    /*
                     * Une serrure, un portail, une alarme ou une sirène réglés
                     * sur « Autorisée » : l'assistant les actionne sans rien
                     * demander. C'est un choix licite — l'interface le
                     * déconseille sans l'interdire — mais c'est le seul réglage
                     * du plugin qui mérite d'être compté à part : sur cent
                     * seize équipements, il se perd dans l'arbre, et personne
                     * ne déroule deux cent cinquante actions pour vérifier
                     * qu'aucune ne traîne. Ce compteur existe pour qu'il se
                     * voie sans chercher, dans l'onglet et dans la page Santé.
                     */
                    if (self::sensible($cmd)) {
                        $compteurs['sensibles']++;
                    }
                } elseif ($politique === self::POLITIQUE_CONFIRMATION) {
                    $compteurs['confirmation']++;
                } else {
                    $compteurs['interdites']++;
                }
                continue;
            }
            if ($cmd->getType() === 'info' && self::lisible($cmd, $eqLogic)) {
                $compteurs['lisibles']++;
            }
        }
        return $compteurs;
    }

    /* ================================================== INDEX DE TRAVAIL */

    /* id d'objet → nom de pièce. */
    public static function objets() {
        if (self::$objets === null) {
            self::$objets = array();
            foreach (jeeObject::all() as $objet) {
                self::$objets[(int) $objet->getId()] = (string) $objet->getName();
            }
        }
        return self::$objets;
    }

    /* Tous les équipements, désactivés compris : c'est arbre() qui a besoin de
     * les montrer, et les listes destinées au modèle filtrent elles-mêmes. */
    public static function equipements() {
        if (self::$equipements === null) {
            self::$equipements = eqLogic::all();
        }
        return self::$equipements;
    }

    public static function commandes() {
        if (self::$commandes === null) {
            self::$commandes = cmd::all();
        }
        return self::$commandes;
    }

    /*
     * Après une écriture, l'index est périmé : le bouton suivant de l'onglet
     * Autorisations doit voir le choix qu'on vient de faire.
     *
     * k2000beOutils tient le sien — équipements visibles, commandes par
     * équipement — entièrement dérivé de celui-ci. Le vider sans vider le sien
     * laisserait, pour le reste de la requête, un équipement démasqué toujours
     * invisible et un équipement fraîchement masqué toujours visible : la
     * seconde moitié est une fuite, pas une simple incohérence d'affichage.
     * Le couplage va dans ce sens et jamais dans l'autre : la sécurité ne
     * dépend de rien, les outils dépendent d'elle.
     *
     * class_exists() plutôt qu'un appel direct : ce fichier doit rester
     * utilisable seul, comme le fait l'onglet Autorisations, qui ne charge pas
     * les outils.
     */
    public static function oublier() {
        self::$commandes = null;
        self::$equipements = null;
        self::$objets = null;
        if (class_exists('k2000beOutils')) {
            k2000beOutils::oublier();
        }
    }
}
