<?php
/* Remplaçants minimaux du cœur de Jeedom, pour rejouer les cinq classes du
 * plugin hors d'une installation : ni base de données, ni session, ni réseau.
 *
 * Ils ne simulent que ce que les classes touchent réellement — la
 * configuration, le journal, les deux classes de base, les objets et
 * l'historique — et rien de plus. Le but n'est pas d'éprouver Jeedom, mais de
 * pouvoir bâtir une petite maison d'essai (salon, chambre, portail, alarme, un
 * coffre masqué) et de vérifier que la sécurité, les outils et la boucle de
 * conversation en tirent toujours les mêmes conclusions.
 *
 * Deux points méritent d'être dits, parce qu'ils ont coûté du temps :
 *
 *  - l'identifiant d'un équipement neuf vaut null, jamais 0 : preSave() teste
 *    « getId() == '' » pour reconnaître une création, et 0 == '' est faux
 *    depuis PHP 8. Un identifiant à 0 ferait silencieusement sauter les
 *    valeurs par défaut de l'équipement ;
 *  - eqLogic::save() rejoue preSave() et postSave(), comme le cœur. C'est ce
 *    qui fait passer le rejeu par createCommands() au lieu de l'éviter — et
 *    donc ce qui éprouve la création des commandes de l'assistant.
 */

date_default_timezone_set('Europe/Brussels');

function __($_texte, $_fichier = null) {
    return $_texte;
}

/* Le rejeu n'ouvre aucune page : init() ne sert qu'à ne pas faire échouer un
 * fichier du plugin qui l'appellerait au chargement. */
function init($_nom, $_defaut = '') {
    return isset($_REQUEST[$_nom]) ? $_REQUEST[$_nom] : $_defaut;
}

/* ========================================================== CONFIGURATION */

class config {
    public static $valeurs = array();

    /* Les valeurs du .ini du plugin, lues une fois par plugin. */
    private static $ini = array();

    /*
     * Le cœur ne rend le défaut passé en argument qu'en DERNIER recours : dès
     * que la valeur stockée est vide, il retombe sur core/config/<id>.config.ini
     * (core/class/config.class.php, byKey → getDefaultConfiguration), et c'est
     * cette valeur-là que voit le plugin.
     *
     * Le bouchon rendait le défaut de l'appelant. Aucun essai n'en dépendait,
     * mais le premier essai portant sur un champ VIDÉ aurait prouvé l'exact
     * inverse de la production : « timeout effacé ⇒ 60 » ici, « timeout effacé
     * ⇒ la valeur du .ini » là-bas. Un rejeu qui ment sur un point ne se
     * rattrape pas, il s'utilise.
     */
    public static function byKey($_cle, $_plugin = 'core', $_defaut = '') {
        $index = $_plugin . '::' . $_cle;
        $valeur = isset(self::$valeurs[$index]) ? self::$valeurs[$index] : null;
        if ($valeur !== null && $valeur !== '') {
            return $valeur;
        }
        $ini = self::ini($_plugin);
        if (isset($ini[$_cle]) && $ini[$_cle] !== '') {
            return $ini[$_cle];
        }
        return $_defaut;
    }

    private static function ini($_plugin) {
        if (!isset(self::$ini[$_plugin])) {
            self::$ini[$_plugin] = array();
            $chemin = __DIR__ . '/../core/config/' . $_plugin . '.config.ini';
            if (is_readable($chemin)) {
                $lu = @parse_ini_file($chemin, true);
                if (is_array($lu) && isset($lu[$_plugin]) && is_array($lu[$_plugin])) {
                    self::$ini[$_plugin] = $lu[$_plugin];
                }
            }
        }
        return self::$ini[$_plugin];
    }

    public static function save($_cle, $_valeur, $_plugin = 'core') {
        self::$valeurs[$_plugin . '::' . $_cle] = $_valeur;
        return true;
    }

    /* Le rejeu change de réglage à chaque section : remettre la configuration
     * à plat entre deux essais évite qu'un mode « lecture » laissé derrière
     * soi n'explique un refus qu'on croirait dû à la politique. */
    public static function vider() {
        self::$valeurs = array();
    }
}

/* ================================================================ JOURNAUX */

class log {
    public static $lignes = array();

    public static function add($_plugin, $_niveau, $_message, $_logicalId = '') {
        self::$lignes[] = $_niveau . ' : ' . $_message;
    }

    /* Tout ce que le plugin a journalisé depuis le dernier effacement, en un
     * seul texte : c'est là-dedans qu'on cherche une clé API qui aurait fui. */
    public static function texte() {
        return implode("\n", self::$lignes);
    }

    public static function vider() {
        self::$lignes = array();
    }
}

class message {
    public static $ajoutes = array();

    public static function add($_plugin, $_message, $_action = '', $_logicalId = '') {
        self::$ajoutes[] = $_message;
    }

    public static function removeAll($_plugin, $_logicalId = '') {}
}

class cache {
    public static $valeurs = array();

    public static function set($_cle, $_valeur, $_duree = 0) {
        self::$valeurs[$_cle] = $_valeur;
    }

    public static function byKey($_cle) {
        $entree = new self();
        $entree->valeur = isset(self::$valeurs[$_cle]) ? self::$valeurs[$_cle] : null;
        return $entree;
    }

    public $valeur = null;

    public function getValue($_defaut = '') {
        return ($this->valeur === null) ? $_defaut : $this->valeur;
    }
}

/* ==================================================== ÉCRITURE QUI ÉCHOUE */

/*
 * Un disque qui n'accepte pas l'écriture, pour éprouver ce que fait le journal
 * quand file_put_contents() ne va pas au bout.
 *
 * k2000beJournal::ecrire() écrit d'abord dans « <chemin>.<pid>.tmp », puis
 * renomme. Ce nom est entièrement prévisible : il suffit de le remplacer par un
 * lien vers /dev/full, qui refuse chaque octet qu'on lui donne, pour que
 * l'écriture échoue là où le plugin l'attend. Le fichier existant, lui, ne doit
 * pas bouger : c'est tout l'objet du fichier temporaire.
 *
 * Ce que ce bouchon ne peut PAS produire, et il faut le dire : une écriture
 * réellement PARTIELLE, celle où file_put_contents() rend un nombre d'octets
 * plus petit que prévu. Elle demanderait d'intercepter l'écriture elle-même, et
 * PHP sert un chemin ordinaire par son gestionnaire interne sans jamais passer
 * par un gestionnaire de flux déclaré depuis le script. /dev/full refuse dès le
 * premier octet ; c'est la même branche vue du côté du contrat — rien n'est
 * écrit, rien n'est remplacé, l'incident est dit.
 */
function bloquerEcriture($_chemin) {
    if (!file_exists('/dev/full')) {
        return false;
    }
    $temporaire = $_chemin . '.' . getmypid() . '.tmp';
    @unlink($temporaire);
    return @symlink('/dev/full', $temporaire);
}

/* ecrire() efface déjà le temporaire qu'elle n'a pas pu remplir ; ceci n'est là
 * que pour le cas où elle n'y serait jamais arrivée. */
function debloquerEcriture($_chemin) {
    @unlink($_chemin . '.' . getmypid() . '.tmp');
}

/* ================================================================= PIÈCES */

class jeeObject {
    public static $tous = array();
    public static $suivant = 1;

    public $id = null;
    public $name = '';

    public static function all() {
        return array_values(self::$tous);
    }

    public static function byId($_id) {
        return isset(self::$tous[(int) $_id]) ? self::$tous[(int) $_id] : null;
    }

    public function getId() { return $this->id; }
    public function getName() { return $this->name; }

    public function save() {
        if ($this->id === null) {
            $this->id = self::$suivant++;
        }
        self::$tous[(int) $this->id] = $this;
        return true;
    }
}

/* =============================================================== COMMANDES */

class cmd {
    public static $toutes = array();
    /* Les commandes de la maison portent des identifiants à trois chiffres,
     * écrits à la main dans le rejeu : ils doivent rester lisibles dans un
     * message d'échec. Le compteur ne sert qu'aux commandes créées par le
     * plugin lui-même. */
    public static $suivant = 900;

    /*
     * Journal de ce qui est parti vers la domotique. C'est la seule preuve qui
     * compte : un refus, une simulation ou une confirmation en attente doivent
     * laisser ce tableau intact, quoi que raconte le modèle.
     */
    public static $envois = array();

    public $id = null;
    public $eqLogic_id = null;
    public $logicalId = '';
    public $name = '';
    public $type = 'info';
    public $subType = 'other';
    public $generic_type = '';
    public $unite = '';
    public $isVisible = 1;
    public $isHistorized = 0;
    public $order = 0;
    public $value = '';
    /*
     * Deux dates, et non une seule : le cœur pose collectDate à CHAQUE relevé
     * et valueDate seulement quand la valeur a changé (cmd.class.php, event()).
     * Le bouchon n'en tenait qu'une, si bien que la fraîcheur d'un état se
     * jugeait ici sur la date du dernier changement — l'exact défaut que
     * k2000beOutils::dateDeLecture() existe pour corriger, et qu'un rejeu à
     * une seule date ne pouvait pas voir : un thermostat stable depuis six
     * heures, mais relevé à l'instant, passait pour une archive.
     */
    public $valueDate = '';
    public $collectDate = '';
    /*
     * De quoi rejouer un équipement qui répond en DIFFÉRÉ, et c'est le seul
     * moyen d'éprouver l'attente de get_states : la valeur doit changer
     * PENDANT que le plugin attend, sans second processus.
     *
     * « parleA » est l'instant (microtime) où le démon de l'équipement est
     * censé remonter « parleValeur ». Le relevé ne se produit qu'au passage
     * par execCmd(), et c'est voulu : le cœur mémorise la date de relevé dans
     * l'objet — cmd::getCollectDate() ne relit le cache que si elle est encore
     * vide — si bien qu'une attente qui se contenterait de relire la date
     * verrait éternellement la même seconde. Un bouchon qui parlerait tout
     * seul laisserait passer cette erreur-là ; celui-ci la fait échouer.
     *
     * « lectures » compte les passages, ce qui permet de vérifier que
     * l'attente relit vraiment, et pas une seule fois au début.
     */
    public $parleA = null;
    public $parleValeur = null;
    public $lectures = 0;
    public $configuration = array();
    public $display = array();
    public $template = array();

    public static function byId($_id) {
        $id = (int) $_id;
        return isset(self::$toutes[$id]) ? self::$toutes[$id] : null;
    }

    public static function all() {
        return array_values(self::$toutes);
    }

    public static function byEqLogicIdCmdName($_eqLogicId, $_nom) {
        foreach (self::$toutes as $cmd) {
            if ((int) $cmd->eqLogic_id === (int) $_eqLogicId && $cmd->name === $_nom) {
                return $cmd;
            }
        }
        return null;
    }

    public function getId() { return $this->id; }
    public function getEqLogic_id() { return $this->eqLogic_id; }
    public function getLogicalId() { return $this->logicalId; }
    public function getName() { return $this->name; }
    public function getType() { return $this->type; }
    public function getSubType() { return $this->subType; }
    public function getGeneric_type() { return $this->generic_type; }
    public function getUnite() { return $this->unite; }
    public function getIsVisible() { return $this->isVisible; }
    public function getIsHistorized() { return $this->isHistorized; }
    public function getValueDate() { return $this->valueDate; }
    public function getCollectDate() { return $this->collectDate; }
    public function getOrder() { return $this->order; }

    public function setEqLogic_id($_v) { $this->eqLogic_id = $_v; return $this; }
    public function setLogicalId($_v) { $this->logicalId = $_v; return $this; }
    public function setName($_v) { $this->name = $_v; return $this; }
    public function setType($_v) { $this->type = $_v; return $this; }
    public function setSubType($_v) { $this->subType = $_v; return $this; }
    public function setGeneric_type($_v) { $this->generic_type = $_v; return $this; }
    public function setUnite($_v) { $this->unite = $_v; return $this; }
    public function setIsVisible($_v) { $this->isVisible = $_v; return $this; }
    public function setIsHistorized($_v) { $this->isHistorized = $_v; return $this; }
    public function setOrder($_v) { $this->order = $_v; return $this; }
    public function setCollectDate($_v) { $this->collectDate = $_v; return $this; }
    public function setDisplay($_cle, $_valeur) { $this->display[$_cle] = $_valeur; return $this; }
    public function setTemplate($_version, $_valeur) { $this->template[$_version] = $_valeur; return $this; }

    public function getConfiguration($_cle, $_defaut = '') {
        return isset($this->configuration[$_cle]) ? $this->configuration[$_cle] : $_defaut;
    }

    public function setConfiguration($_cle, $_valeur) {
        $this->configuration[$_cle] = $_valeur;
        return $this;
    }

    public function getEqLogic() {
        return eqLogic::byId($this->eqLogic_id);
    }

    public function save() {
        if ($this->id === null) {
            $this->id = self::$suivant++;
        }
        self::$toutes[(int) $this->id] = $this;
        return true;
    }

    /*
     * Une commande d'information rend sa valeur ; une commande d'action est
     * notée puis déléguée à execute(), comme le fait le cœur. C'est ce qui
     * permet d'éprouver k2000beCmd::execute() par le même chemin qu'un
     * scénario.
     */
    public function execCmd($_options = null) {
        if ($this->type === 'info') {
            $this->lectures++;
            /* L'heure de la remontée est venue : le démon publie, comme le
             * ferait le plugin propriétaire de l'équipement. Une seule fois. */
            if ($this->parleA !== null && microtime(true) >= $this->parleA) {
                $this->parleA = null;
                $this->event($this->parleValeur);
            }
            return $this->value;
        }
        self::$envois[] = array(
            'id'      => (int) $this->id,
            'nom'     => $this->name,
            'options' => $_options,
        );
        if (method_exists($this, 'execute')) {
            return $this->execute(is_array($_options) ? $_options : array());
        }
        return true;
    }

    /* Le cœur émet un événement et met la valeur à jour : le rejeu retient les
     * deux, l'équipement gardant la trace de ce qu'il a publié. */
    public function event($_valeur) {
        /* Comme le cœur : la date de lecture bouge à chaque relevé, celle du
         * changement seulement quand la valeur diffère de la précédente. */
        $changement = ((string) $this->value !== (string) $_valeur);
        $this->collectDate = date('Y-m-d H:i:s');
        if ($changement || $this->valueDate === '') {
            $this->valueDate = $this->collectDate;
        }
        $this->value = $_valeur;
        $eqLogic = $this->getEqLogic();
        if (is_object($eqLogic)) {
            $eqLogic->publies[$this->logicalId] = $_valeur;
        }
        return true;
    }

    public static function vider() {
        self::$envois = array();
    }
}

/* ============================================================= ÉQUIPEMENTS */

class eqLogic {
    public static $tous = array();
    public static $suivant = 1;

    public $id = null;
    public $name = '';
    public $eqType_name = '';
    public $object_id = null;
    public $isEnable = 1;
    public $isVisible = 1;
    public $configuration = array();
    public $display = array();
    public $cache = array();
    /* Ce que l'assistant a publié sur ses commandes d'information : la frise,
     * le statut et le compte d'actions s'y relisent sans passer par le cœur. */
    public $publies = array();

    public static function all($_type = null) {
        if ($_type === null) {
            return array_values(self::$tous);
        }
        $liste = array();
        foreach (self::$tous as $eqLogic) {
            if ($eqLogic->eqType_name === $_type) {
                $liste[] = $eqLogic;
            }
        }
        return $liste;
    }

    public static function byId($_id) {
        $id = (int) $_id;
        return isset(self::$tous[$id]) ? self::$tous[$id] : null;
    }

    public static function byType($_type, $_seulementActifs = false) {
        $liste = array();
        foreach (self::all($_type) as $eqLogic) {
            if ($_seulementActifs && (int) $eqLogic->isEnable !== 1) {
                continue;
            }
            $liste[] = $eqLogic;
        }
        return $liste;
    }

    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getEqType_name() { return $this->eqType_name; }
    public function getObject_id() { return $this->object_id; }
    public function getIsEnable() { return $this->isEnable; }
    public function getIsVisible() { return $this->isVisible; }
    public function getHumanName() { return '[' . $this->name . ']'; }

    public function setName($_v) { $this->name = $_v; return $this; }
    public function setEqType_name($_v) { $this->eqType_name = $_v; return $this; }
    public function setObject_id($_v) { $this->object_id = $_v; return $this; }
    public function setIsEnable($_v) { $this->isEnable = $_v; return $this; }
    public function setIsVisible($_v) { $this->isVisible = $_v; return $this; }
    public function setDisplay($_cle, $_valeur) { $this->display[$_cle] = $_valeur; return $this; }

    public function getConfiguration($_cle, $_defaut = '') {
        return isset($this->configuration[$_cle]) ? $this->configuration[$_cle] : $_defaut;
    }

    public function setConfiguration($_cle, $_valeur) {
        $this->configuration[$_cle] = $_valeur;
        return $this;
    }

    /* Publiques dans le cœur, et elles doivent le rester ici : les redéclarer
     * en privé dans le plugin est une erreur fatale au chargement, que
     * check-classes.php traque par réflexion. */
    public function getCache($_cle = '', $_defaut = '') {
        return isset($this->cache[$_cle]) ? $this->cache[$_cle] : $_defaut;
    }

    public function setCache($_cle, $_valeur = null) {
        $this->cache[$_cle] = $_valeur;
        return $this;
    }

    public function getCmd($_type = null, $_logicalId = null) {
        foreach (cmd::$toutes as $cmd) {
            if ((int) $cmd->eqLogic_id !== (int) $this->id) {
                continue;
            }
            if ($_logicalId !== null && $cmd->logicalId !== $_logicalId) {
                continue;
            }
            if ($_type !== null && $cmd->type !== $_type) {
                continue;
            }
            return $cmd;
        }
        return null;
    }

    public function getCmdList() {
        $liste = array();
        foreach (cmd::$toutes as $cmd) {
            if ((int) $cmd->eqLogic_id === (int) $this->id) {
                $liste[] = $cmd;
            }
        }
        return $liste;
    }

    public function save() {
        if (method_exists($this, 'preSave')) {
            $this->preSave();
        }
        if ($this->id === null) {
            $this->id = self::$suivant++;
        }
        self::$tous[(int) $this->id] = $this;
        if (method_exists($this, 'postSave')) {
            $this->postSave();
        }
        return true;
    }

    public function remove() {
        if (method_exists($this, 'preRemove')) {
            $this->preRemove();
        }
        unset(self::$tous[(int) $this->id]);
        return true;
    }

    public function refreshWidget() {}
}

/* ============================================================= HISTORIQUE */

/*
 * Le cœur rend des objets history ; la classe est donc à la fois le magasin
 * (all()) et le point relevé (getDatetime(), getValue()), comme dans Jeedom.
 *
 * Le quatrième argument — le groupement — n'est pas un ornement. Sans lui, le
 * bouchon rendait toujours les relevés bruts, et k2000beOutils::getHistory()
 * en concluait que le cœur n'avait pas agrégé : il retombait sur son chemin de
 * repli « raw » et TOUT l'échantillonnage par moyennes restait invérifiable,
 * sans que rien ne le signale. La borne de fin manquait pour la même raison :
 * une demande de vingt-quatre heures rendait aussi ce qui suit l'instant
 * présent, ce que le cœur, lui, ne fait jamais.
 *
 * Ce qui est reproduit vient de core/class/history.class.php, méthode all() :
 *  - filtre « datetime >= startTime » ET « datetime <= endTime » ;
 *  - groupement « fonction::unité », la fonction étant avg (défaut), max/high,
 *    min/low ou sum, l'unité hour, minute, ou le jour pour tout le reste ;
 *  - l'horodatage rendu est celui du SEAU et non d'un relevé : '%Y-%m-%d
 *    %H:00:00' à l'heure, la date seule au jour ;
 *  - les valeurs passent par CAST(value AS DECIMAL(12,2)), et l'agrégat garde
 *    l'échelle que MySQL lui donne — deux décimales pour min, max et sum, six
 *    pour une moyenne. C'est une CHAÎNE qui revient de PDO, pas un nombre, et
 *    le plugin la recopie telle quelle dans ce qu'il montre au modèle ;
 *  - tout ressort trié par datetime croissant.
 *
 * Une simplification, dite pour qu'elle ne devienne pas un mensonge : au jour,
 * le cœur groupe sur DATE(datetime - 1 seconde) tout en étiquetant le seau
 * avec DATE(datetime). Un relevé tombant à minuit pile est donc rangé la
 * veille et peut porter la date du lendemain. Le bouchon groupe et étiquette
 * sur DATE(datetime) : la seconde qui les sépare ne concerne qu'un relevé sur
 * quatre-vingt-six mille, et la reproduire exigerait de reproduire aussi le
 * choix arbitraire que fait MySQL de l'étiquette dans un groupe.
 */
class history {
    public static $points = array();

    public $datetime = '';
    public $value = '';

    public static function all($_cmdId, $_debut = null, $_fin = null, $_groupement = null, $_valeurPrecedente = false) {
        $id = (int) $_cmdId;
        if (!isset(self::$points[$id])) {
            return array();
        }

        $retenus = array();
        $avant = null;
        foreach (self::$points[$id] as $point) {
            if ($point[1] === null) {
                continue;
            }
            if ($_debut !== null && $point[0] < $_debut) {
                /* Le dernier relevé d'avant la fenêtre, que le cœur rajoute en
                 * tête quand on le lui demande. */
                if ($avant === null || $point[0] > $avant[0]) {
                    $avant = $point;
                }
                continue;
            }
            if ($_fin !== null && $point[0] > $_fin) {
                continue;
            }
            $retenus[] = $point;
        }

        $groupe = (is_string($_groupement) && strpos($_groupement, '::') !== false);
        if ($_valeurPrecedente && !$groupe && $avant !== null && !empty($retenus)) {
            array_unshift($retenus, $avant);
        }

        usort($retenus, function ($_a, $_b) {
            return strcmp($_a[0], $_b[0]);
        });

        if (!$groupe) {
            return self::lignes($retenus);
        }

        $decoupe = explode('::', $_groupement);
        $fonction = $decoupe[0];
        $unite = isset($decoupe[1]) ? $decoupe[1] : 'day';

        $seaux = array();
        foreach ($retenus as $point) {
            $horodatage = strtotime($point[0]);
            if ($unite === 'hour') {
                $etiquette = date('Y-m-d H:00:00', $horodatage);
            } elseif ($unite === 'minute') {
                $etiquette = date('Y-m-d H:i:00', $horodatage);
            } else {
                $etiquette = date('Y-m-d', $horodatage);
            }
            if (!isset($seaux[$etiquette])) {
                $seaux[$etiquette] = array();
            }
            /* Le CAST du cœur : une valeur qui n'est pas un nombre vaut zéro,
             * et deux décimales au plus. */
            $seaux[$etiquette][] = round(is_numeric($point[1]) ? (float) $point[1] : 0.0, 2);
        }
        ksort($seaux);

        $agreges = array();
        foreach ($seaux as $etiquette => $valeurs) {
            if ($fonction === 'max' || $fonction === 'high') {
                $agregat = max($valeurs);
                $decimales = 2;
            } elseif ($fonction === 'min' || $fonction === 'low') {
                $agregat = min($valeurs);
                $decimales = 2;
            } elseif ($fonction === 'sum') {
                $agregat = array_sum($valeurs);
                $decimales = 2;
            } else {
                $agregat = array_sum($valeurs) / count($valeurs);
                $decimales = 6;
            }
            $agreges[] = array($etiquette, number_format($agregat, $decimales, '.', ''));
        }
        return self::lignes($agreges);
    }

    private static function lignes($_points) {
        $lignes = array();
        foreach ($_points as $point) {
            $ligne = new self();
            $ligne->datetime = $point[0];
            $ligne->value = $point[1];
            $lignes[] = $ligne;
        }
        return $lignes;
    }

    public function getDatetime() { return $this->datetime; }
    public function getValue() { return $this->value; }
}

/* ====================================================== LA MAISON D'ESSAI */

/*
 * Trois fabriques, pour que le rejeu décrive la maison plutôt que la
 * construire. Les identifiants sont imposés : ils apparaissent dans les
 * messages d'échec et dans les appels d'outils écrits à la main.
 */
function creerPiece($_id, $_nom) {
    $objet = new jeeObject();
    $objet->id = $_id;
    $objet->name = $_nom;
    $objet->save();
    return $objet;
}

function creerEquipement($_id, $_nom, $_type, $_pieceId, $_options = array()) {
    $eqLogic = new eqLogic();
    $eqLogic->id = $_id;
    $eqLogic->name = $_nom;
    $eqLogic->eqType_name = $_type;
    $eqLogic->object_id = $_pieceId;
    $eqLogic->isEnable = isset($_options['actif']) ? (int) $_options['actif'] : 1;
    if (!empty($_options['masque'])) {
        $eqLogic->setConfiguration('k2000beHidden', 1);
    }
    eqLogic::$tous[$_id] = $eqLogic;
    return $eqLogic;
}

/*
 * $_options porte, selon le cas : 'generique', 'unite', 'valeur', 'politique'
 * (commande action), 'lecture' (commande info), 'min', 'max', 'liste',
 * 'historise'. Les clés de configuration sont celles du cœur : c'est sous ces
 * noms-là que k2000beSecurite ira les chercher.
 */
function creerCommande($_id, $_eqLogic, $_nom, $_type, $_sousType, $_options = array()) {
    $cmd = new cmd();
    $cmd->id = $_id;
    $cmd->eqLogic_id = (int) $_eqLogic->getId();
    $cmd->name = $_nom;
    $cmd->type = $_type;
    $cmd->subType = $_sousType;
    $cmd->generic_type = isset($_options['generique']) ? $_options['generique'] : '';
    $cmd->unite = isset($_options['unite']) ? $_options['unite'] : '';
    $cmd->value = isset($_options['valeur']) ? $_options['valeur'] : '';
    $cmd->valueDate = isset($_options['date']) ? $_options['date'] : date('Y-m-d H:i:s');
    /* Faute de précision, une commande a été lue quand elle a changé : c'est le
     * cas d'un capteur ordinaire, et cela laisse « date » seule décider de la
     * fraîcheur comme avant. « collecte » sert à écarter les deux dates, ce qui
     * est justement le cas qu'on veut pouvoir éprouver. */
    $cmd->collectDate = isset($_options['collecte']) ? $_options['collecte'] : $cmd->valueDate;
    $cmd->isHistorized = !empty($_options['historise']) ? 1 : 0;

    if (isset($_options['politique'])) { $cmd->setConfiguration('k2000be', $_options['politique']); }
    if (isset($_options['lecture']))   { $cmd->setConfiguration('k2000beRead', $_options['lecture']); }
    if (isset($_options['min']))       { $cmd->setConfiguration('minValue', $_options['min']); }
    if (isset($_options['max']))       { $cmd->setConfiguration('maxValue', $_options['max']); }
    if (isset($_options['liste']))     { $cmd->setConfiguration('listValue', $_options['liste']); }

    cmd::$toutes[$_id] = $cmd;
    return $cmd;
}

/* ================================================================= TÂCHES */

/*
 * La table des tâches du cœur, réduite à ce que le plugin y fait : planifier
 * une tâche « once » et la lancer aussitôt (k2000be::planifierAlerte).
 *
 * run() ne joue RIEN, et c'est tout l'intérêt : dans le cœur, run() lance
 * jeeCron.php en arrière-plan et rend la main sur-le-champ. Un bouchon qui
 * appellerait la fonction dans run() ferait passer pour différée une alerte
 * jouée dans cron() — l'exact défaut que la tâche de fond corrige. Les tâches
 * lancées attendent donc dans $lancees que le rejeu les joue lui-même, comme
 * le ferait jeeCron.php : $classe::$fonction($options), puis effacement de la
 * tâche « once ».
 *
 * $panne fait échouer le prochain save(), pour éprouver le repli.
 */
class cron {
    public static $taches = array();
    public static $lancees = array();
    public static $suivant = 1;
    public static $panne = false;

    public $id = null;
    public $class = '';
    public $function = '';
    public $option = null;
    public $once = 0;
    public $schedule = '';
    public $timeout = 0;
    public $lastRun = '';

    public static function convertDateToCron($_date) {
        return date('i', $_date) . ' ' . date('H', $_date) . ' ' . date('d', $_date) . ' ' . date('m', $_date) . ' *';
    }

    public function getId() { return $this->id; }
    public function getClass() { return $this->class; }
    public function getFunction() { return $this->function; }
    public function getOnce() { return $this->once; }
    public function getSchedule() { return $this->schedule; }
    public function getTimeout() { return $this->timeout; }
    public function getLastRun() { return $this->lastRun; }

    /* Comme le cœur : les options sont stockées en JSON et relues décodées,
     * ce qui éprouve au passage qu'elles survivent à l'aller-retour. */
    public function getOption() { return json_decode($this->option ?? '', true); }

    public function setClass($_v) { $this->class = $_v; return $this; }
    public function setFunction($_v) { $this->function = $_v; return $this; }
    public function setOption($_v) { $this->option = json_encode($_v, JSON_UNESCAPED_UNICODE); return $this; }
    public function setOnce($_v) { $this->once = $_v; return $this; }
    public function setSchedule($_v) { $this->schedule = $_v; return $this; }
    public function setTimeout($_v) { $this->timeout = $_v; return $this; }
    public function setLastRun($_v) { $this->lastRun = $_v; }

    public function save() {
        if (self::$panne) {
            self::$panne = false;
            throw new Exception('Rejeu : la table des tâches refuse l\'écriture.');
        }
        if ($this->id === null) {
            $this->id = self::$suivant++;
        }
        self::$taches[$this->id] = $this;
        return true;
    }

    public function run($_noErrorReport = false) {
        self::$lancees[] = $this->id;
    }

    public function remove($_arreter = true) {
        unset(self::$taches[$this->id]);
        return true;
    }

    /* Ce que ferait jeeCron.php pour chaque tâche lancée. Rend le nombre de
     * tâches jouées. */
    public static function jouerLancees() {
        $jouees = 0;
        while (!empty(self::$lancees)) {
            $id = array_shift(self::$lancees);
            if (!isset(self::$taches[$id])) {
                continue;
            }
            $tache = self::$taches[$id];
            $classe = $tache->getClass();
            $fonction = $tache->getFunction();
            $classe::$fonction($tache->getOption());
            if ((int) $tache->getOnce() === 1) {
                $tache->remove(false);
            }
            $jouees++;
        }
        return $jouees;
    }

    public static function vider() {
        self::$taches = array();
        self::$lancees = array();
        self::$panne = false;
    }
}
