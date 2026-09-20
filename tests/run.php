<?php
/* Rejeu hors ligne du plugin K2000, sans réseau et sans Jeedom.
 *
 *   php tests/run.php
 *
 * Le plugin décide d'ouvrir un portail ou non. Cette décision ne peut pas
 * s'éprouver en production : il faudrait ouvrir le portail pour savoir. Tout ce
 * qui suit rejoue donc la maison, le modèle et l'horloge, et vérifie que le
 * plugin décide la même chose à chaque fois.
 *
 * Trois choses sont remplacées, et trois seulement :
 *  - le cœur de Jeedom, par tests/stub.php ;
 *  - le transport HTTP d'OpenAI, par k2000beOpenAI::$transport, à qui l'on
 *    donne des réponses écrites à la main (tests/fixtures/) ou construites
 *    ci-dessous ;
 *  - l'emplacement du plugin, recopié dans un bac à sable temporaire.
 *
 * Tout le reste — sécurité, outils, boucle de conversation, journal — est le
 * code de production, pris tel quel.
 */

require_once __DIR__ . '/stub.php';

/* ==================================================== LE BAC À SABLE */

/*
 * Les cinq classes sont recopiées ailleurs avant d'être incluses, pour deux
 * raisons qui tiennent chacune à une ligne du plugin :
 *
 *  - k2000be.class.php charge core.inc.php en première ligne, qui n'existe pas
 *    ici : la copie s'en passe (les quatre classes de service, elles, ne le
 *    chargent pas, et c'est précisément ce qui rend ce rejeu possible) ;
 *  - k2000beJournal::racine() déduit le dossier data/ de sa propre position.
 *    Inclure les originales ferait écrire les conversations et le journal du
 *    rejeu dans le dépôt, au milieu des vraies données de développement.
 *
 * Le bac est effacé en sortant, même sur erreur fatale.
 */
function effacerDossier($_chemin) {
    if (!is_dir($_chemin)) {
        return;
    }
    foreach (scandir($_chemin) as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }
        $chemin = $_chemin . '/' . $entree;
        if (is_dir($chemin)) {
            effacerDossier($chemin);
        } else {
            @unlink($chemin);
        }
    }
    @rmdir($_chemin);
}

$bac = sys_get_temp_dir() . '/k2000be-rejeu-' . getmypid();
effacerDossier($bac);
@mkdir($bac . '/core/class', 0775, true);
@mkdir($bac . '/plugin_info', 0775, true);
register_shutdown_function(function () use ($bac) {
    effacerDossier($bac);
});

$origine = __DIR__ . '/../core/class/';
foreach (array('k2000beJournal', 'k2000beSecurite', 'k2000beOutils', 'k2000beOpenAI') as $classe) {
    copy($origine . $classe . '.class.php', $bac . '/core/class/' . $classe . '.class.php');
}
file_put_contents($bac . '/core/class/k2000be.class.php',
    preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '',
        file_get_contents($origine . 'k2000be.class.php')));
copy(__DIR__ . '/../plugin_info/info.json', $bac . '/plugin_info/info.json');

require_once $bac . '/core/class/k2000be.class.php';

/* ========================================================== OUTILLAGE */

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-62s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-62s obtenu %s, attendu %s\n", $_label,
            var_export($_actual, true), var_export($_expected, true));
    }
}

/*
 * Un appel qui meurt — erreur fatale, méthode absente — ne doit pas emporter
 * le reste du rejeu : deux cent cinquante contrôles perdus pour un, et le
 * diagnostic devient impossible. L'incident est noté comme un échec, et la
 * suite continue sur une structure vide.
 */
function tenter($_libelle, $_appel) {
    global $failed;
    try {
        return call_user_func($_appel);
    } catch (Throwable $e) {
        $failed++;
        printf("  ECHEC %-62s interrompu par %s : %s\n", $_libelle, get_class($e), $e->getMessage());
        return array('reponse' => '', 'statut' => '', 'etapes' => array(),
                     'attente' => null, 'jetons' => array(), 'modele' => '', 'erreur' => '');
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', strlen($_title)) . "\n";
}

/* Une réponse d'API écrite à la main, telle qu'OpenAI la rendrait. */
function fixture($_nom) {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $_nom . '.json'), true);
}

function reglage($_cle, $_valeur) {
    config::save($_cle, $_valeur, 'k2000be');
}

function invoquer($_methode, $_arguments) {
    return invoquerClasse('k2000be', $_methode, $_arguments);
}

function invoquerClasse($_classe, $_methode, $_arguments) {
    $methode = new ReflectionMethod($_classe, $_methode);
    $methode->setAccessible(true);
    return $methode->invokeArgs(null, $_arguments);
}

/* La même chose sur un objet : la boucle de conversation est une méthode
 * d'instance, et certains de ses garde-fous — le budget de temps — ne
 * s'observent qu'en la jouant avec une horloge truquée. */
function invoquerSur($_objet, $_methode, $_arguments) {
    $methode = new ReflectionMethod(get_class($_objet), $_methode);
    $methode->setAccessible(true);
    return $methode->invokeArgs($_objet, $_arguments);
}

/*
 * Le modèle, remplacé par une file de réponses écrites d'avance. Il enregistre
 * aussi ce qui lui a été envoyé : c'est la seule façon de vérifier ce qui part
 * réellement chez OpenAI — les outils déclarés, la charge corrigée, et surtout
 * qu'aucun secret de la maison ne s'y trouve.
 */
class rejeu {
    public static $reponses = array();
    public static $charges = array();
    public static $chemins = array();

    /*
     * L'espion : appelé au moment précis où le plugin parle au modèle,
     * c'est-à-dire PENDANT un tour, avant que rien n'ait été enregistré. C'est
     * la seule façon d'observer hors ligne ce qui, en production, se voit
     * quand deux onglets parlent en même temps — l'état du fichier de
     * conversation à mi-tour, et ce qu'un second appel obtient.
     */
    public static $espion = null;

    /* Le rang de l'appel au modèle auquel l'espion se déclenche : le premier
     * pour observer un tour à peine commencé, le second pour voir ce que le
     * plugin a enregistré entre deux allers-retours. */
    public static $espionRang = 1;

    public static function brancher() {
        k2000beOpenAI::$transport = array('rejeu', 'servir');
    }

    public static function servir($_chemin, $_charge, $_options) {
        self::$chemins[] = $_chemin;
        self::$charges[] = $_charge;
        if (self::$espion !== null && count(self::$charges) === self::$espionRang) {
            $espion = self::$espion;
            /* Un espion ne sert qu'une fois : il rappelle souvent le plugin,
               et se rappellerait lui-même sans fin. */
            self::$espion = null;
            call_user_func($espion, count(self::$charges));
        }
        if (empty(self::$reponses)) {
            throw new Exception('Rejeu : aucune réponse écrite pour l\'appel n°'
                . count(self::$charges) . ' (' . $_chemin . ').');
        }
        return array_shift(self::$reponses);
    }

    /* Un essai = une file de réponses, et une ardoise remise à zéro : ce qui
     * est parti vers la domotique au tour précédent ne doit jamais être compté
     * pour le tour suivant. */
    public static function scenario($_reponses) {
        self::$reponses = $_reponses;
        self::$charges = array();
        self::$chemins = array();
        self::$espion = null;
        self::$espionRang = 1;
        cmd::vider();
    }

    public static function charge($_rang) {
        return isset(self::$charges[$_rang]) ? self::$charges[$_rang] : array();
    }
}
rejeu::brancher();

/* Les identifiants d'appel doivent être uniques d'un bout à l'autre du rejeu :
 * deux appels portant le même identifiant rendraient le contrôle d'intégrité
 * incapable de distinguer une réponse manquante d'une réponse en double. */
$GLOBALS['rang_appel'] = 0;

/*
 * $_appels accepte, par entrée : 'outil', 'args', 'brut' (arguments non
 * décodables), et 'id' — absent pour un identifiant normal, la chaîne vide ou
 * null pour un appel SANS identifiant, une valeur pour en imposer un et
 * fabriquer ainsi un doublon. Les deux derniers cas ne sont pas théoriques :
 * ils arrivent avec « finish_reason: length » au milieu des tool_calls, et
 * derrière une passerelle « compatible OpenAI » imparfaite.
 */
function reponseOutils($_appels, $_jetons = array(200, 20), $_finish = 'tool_calls') {
    $tool_calls = array();
    foreach ($_appels as $appel) {
        $GLOBALS['rang_appel']++;
        $brique = array(
            'type'     => 'function',
            'function' => array(
                'name'      => $appel['outil'],
                /* L'API rend les arguments dans une CHAÎNE de JSON, jamais dans
                 * un objet : c'est ce détail qui oblige le moteur à les
                 * décoder, et une chaîne tronquée doit rester sans gravité. */
                'arguments' => isset($appel['brut'])
                    ? $appel['brut']
                    : json_encode(isset($appel['args']) ? $appel['args'] : array()),
            ),
        );
        if (!array_key_exists('id', $appel)) {
            $brique['id'] = 'call_' . $GLOBALS['rang_appel'];
        } elseif ($appel['id'] !== null) {
            $brique['id'] = $appel['id'];
        }
        $tool_calls[] = $brique;
    }
    return array(
        'id'      => 'chatcmpl-rejeu',
        'model'   => 'gpt-4o-mini',
        'choices' => array(array(
            'index'         => 0,
            'message'       => array('role' => 'assistant', 'content' => null, 'tool_calls' => $tool_calls),
            'finish_reason' => $_finish,
        )),
        'usage'   => array(
            'prompt_tokens'     => $_jetons[0],
            'completion_tokens' => $_jetons[1],
            'total_tokens'      => $_jetons[0] + $_jetons[1],
        ),
    );
}

/* $_finish porte la raison d'arrêt : « length » pour une réponse coupée par le
 * plafond de jetons, « content_filter » pour un refus du fournisseur. */
function reponseTexte($_texte, $_jetons = array(120, 30), $_finish = 'stop') {
    return array(
        'id'      => 'chatcmpl-rejeu',
        'model'   => 'gpt-4o-mini',
        'choices' => array(array(
            'index'         => 0,
            'message'       => array('role' => 'assistant', 'content' => $_texte),
            'finish_reason' => $_finish,
        )),
        'usage'   => array(
            'prompt_tokens'     => $_jetons[0],
            'completion_tokens' => $_jetons[1],
            'total_tokens'      => $_jetons[0] + $_jetons[1],
        ),
    );
}

/*
 * Le contrôle qui vaut pour toute la boucle : l'API rejette EN BLOC une
 * conversation où un tool_calls n'a pas sa réponse, ou un message tool ne
 * répond à personne. Un refus, une limite atteinte ou une confirmation en
 * attente doivent donc eux aussi produire un message tool. Rend le nombre
 * d'anomalies — zéro est la seule valeur acceptable.
 */
/*
 * Ce contrôle était aveugle aux deux seules façons dont l'invariant peut se
 * briser sans qu'on le voie : un appel SANS identifiant donnait la clé '', que
 * le message tool à identifiant vide venait consommer — zéro anomalie, et une
 * conversation pourtant refusée en bloc par l'API au tour suivant ; un appel à
 * identifiant RÉPÉTÉ écrasait le précédent dans le tableau, et les deux
 * réponses tool se répartissaient sur une seule attente. Comme c'est ce
 * contrôle qui porte tout l'invariant du fichier, son angle mort valait
 * approbation.
 */
function integriteOutils($_messages) {
    $attendus = array();
    $vus = array();
    $anomalies = 0;
    foreach ($_messages as $message) {
        $role = isset($message['role']) ? $message['role'] : '';
        if ($role === 'assistant' && !empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $appel) {
                $identifiant = isset($appel['id']) ? (string) $appel['id'] : '';
                /* Un appel sans identifiant ne peut recevoir aucune réponse :
                 * aucun message tool ne saura le citer. */
                if ($identifiant === '') {
                    $anomalies++;
                    continue;
                }
                /* Un identifiant déjà employé dans cette conversation rend les
                 * deux réponses indiscernables. */
                if (isset($vus[$identifiant])) {
                    $anomalies++;
                    continue;
                }
                $attendus[$identifiant] = true;
                $vus[$identifiant] = true;
            }
            continue;
        }
        if ($role === 'tool') {
            $identifiant = isset($message['tool_call_id']) ? (string) $message['tool_call_id'] : '';
            if ($identifiant === '' || !isset($attendus[$identifiant])) {
                $anomalies++;
                continue;
            }
            unset($attendus[$identifiant]);
        }
    }
    return $anomalies + count($attendus);
}

function compterRole($_messages, $_role) {
    $nombre = 0;
    foreach ($_messages as $message) {
        if (isset($message['role']) && $message['role'] === $_role) {
            $nombre++;
        }
    }
    return $nombre;
}

/*
 * Ce que la maison ne doit jamais laisser sortir : le nom d'un équipement
 * masqué, la pièce où il se trouve, le nom et la valeur d'un état interdit à la
 * lecture, le nom d'un équipement désactivé. Le nom d'une commande est déjà une
 * information sur la maison : il ne suffit pas de taire la valeur.
 */
$GLOBALS['secrets'] = array('Coffre-fort', 'lingots', 'Cave', 'Code alarme', '4271', 'Prise interdite');

function fuites($_donnees) {
    $texte = is_string($_donnees) ? $_donnees : json_encode($_donnees, JSON_UNESCAPED_UNICODE);
    $trouves = array();
    foreach ($GLOBALS['secrets'] as $secret) {
        if (stripos($texte, $secret) !== false) {
            $trouves[] = $secret;
        }
    }
    return $trouves;
}

function outil($_nom, $_arguments = array()) {
    return k2000beOutils::executer($_nom, $_arguments, null);
}

/* ==================================================== LA MAISON D'ESSAI */

const CLE_API = 'sk-proj-rejeuK2000beSecret0123456789';

config::vider();
reglage('apikey', CLE_API);
reglage('model', 'gpt-4o-mini');
reglage('securite', 'actions');
reglage('lecture_defaut', 1);
reglage('max_tool_calls', 10);
reglage('memoire_tours', 12);
reglage('contexte_maison', 1);
reglage('persona', 'kitt');
config::save('info::city', 'Namur', 'core');

creerPiece(1, 'Salon');
creerPiece(2, 'Chambre');
creerPiece(3, 'Entrée');
creerPiece(4, 'Cave');

$lumiere = creerEquipement(10, 'Lumière salon', 'zwavejs', 1);
creerCommande(100, $lumiere, 'État', 'info', 'binary', array('generique' => 'LIGHT_STATE', 'valeur' => '0'));
creerCommande(101, $lumiere, 'Allumer', 'action', 'other', array('generique' => 'LIGHT_ON', 'politique' => 'allow'));
/* Aucune politique : c'est le cas le plus important de tout le fichier, celui
 * d'une commande dont personne n'a rien dit. */
creerCommande(102, $lumiere, 'Éteindre', 'action', 'other', array('generique' => 'LIGHT_OFF'));
creerCommande(103, $lumiere, 'Intensité', 'action', 'slider', array(
    'generique' => 'LIGHT_SLIDER', 'politique' => 'allow', 'min' => 0, 'max' => 100,
));

$volet = creerEquipement(11, 'Volet chambre', 'zwavejs', 2);
creerCommande(110, $volet, 'Position', 'info', 'numeric', array(
    'generique' => 'FLAP_STATE', 'valeur' => 100, 'unite' => '%', 'historise' => 1,
));
creerCommande(111, $volet, 'Ouvrir à', 'action', 'slider', array(
    'generique' => 'FLAP_SLIDER', 'politique' => 'allow', 'min' => 0, 'max' => 100,
));
creerCommande(112, $volet, 'Mode', 'action', 'select', array(
    'politique' => 'allow', 'liste' => '1|Confort;2|Eco;3|Hors gel',
));

$portail = creerEquipement(12, 'Portail', 'gce', 3);
creerCommande(120, $portail, 'Ouvrir', 'action', 'other', array('generique' => 'GB_OPEN', 'politique' => 'confirm'));
creerCommande(121, $portail, 'État portail', 'info', 'binary', array(
    'generique' => 'GB_STATE', 'valeur' => '0', 'lecture' => 'allow',
));

$alarme = creerEquipement(13, 'Alarme', 'alarme', 3);
creerCommande(130, $alarme, 'Désarmer', 'action', 'other', array('generique' => 'ALARM_RELEASED'));
/* Interdite à la lecture : ni son nom ni sa valeur ne doivent apparaître où que
 * ce soit, alors que son équipement, lui, est parfaitement visible. */
creerCommande(131, $alarme, 'Code alarme', 'info', 'string', array('valeur' => '4271', 'lecture' => 'deny'));
creerCommande(132, $alarme, 'État alarme', 'info', 'binary', array('generique' => 'ALARM_STATE', 'valeur' => '1'));

/* Masqué : seul occupant de la Cave, dont la pièce elle-même doit disparaître
 * du champ de vision de l'assistant. */
$coffre = creerEquipement(14, 'Coffre-fort', 'autre', 4, array('masque' => 1));
creerCommande(140, $coffre, 'Contenu', 'info', 'string', array('valeur' => 'lingots'));
/* Autorisée, et pourtant injouable : le masquage de l'équipement prime. */
creerCommande(141, $coffre, 'Ouvrir le coffre', 'action', 'other', array('generique' => 'LOCK_OPEN', 'politique' => 'allow'));

$prise = creerEquipement(15, 'Prise interdite', 'zwavejs', 1, array('actif' => 0));
creerCommande(150, $prise, 'Allumer', 'action', 'other', array('politique' => 'allow'));
creerCommande(151, $prise, 'État', 'info', 'binary', array('valeur' => '1'));

/* Soixante relevés d'un quart d'heure : plus que le plafond de get_history, ce
 * qui oblige l'outil à dégrossir. */
$points = array();
for ($i = 59; $i >= 0; $i--) {
    $points[] = array(date('Y-m-d H:i:s', time() - $i * 900), (string) (100 - $i));
}
history::$points[110] = $points;

/* L'assistant est créé par le cœur factice : save() passe par preSave(),
 * createCommands() et postSave(), comme le ferait le bouton « Ajouter ». */
eqLogic::$suivant = 20;
$kitt = new k2000be();
$kitt->setName('KITT');
$kitt->setEqType_name('k2000be');
$kitt->setObject_id(1);
$kitt->save();

/* ============================================================ CRÉATION */
section('Création de l\'assistant');

check('l\'équipement reçoit son identifiant', $kitt->getId(), 20);
check('preSave l\'active', (int) $kitt->getIsEnable(), 1);
check('la tuile est assez large pour écrire', $kitt->display['width'], '480px');
check('onze commandes créées', count($kitt->getCmdList()), 11);
check('la demande est la seule commande visible', (int) $kitt->getCmd('action', 'ask')->getIsVisible(), 1);
check('le titre du message est désactivé', $kitt->getCmd('action', 'ask')->display['title_disable'], 1);
check('le compte d\'actions est historisé', (int) $kitt->getCmd('info', 'actions')->getIsHistorized(), 1);
check('le compte de jetons aussi', (int) $kitt->getCmd('info', 'jetons')->getIsHistorized(), 1);
check('la réponse ne l\'est pas', (int) $kitt->getCmd('info', 'reply')->getIsHistorized(), 0);
/* Idempotence : le cœur rappelle postSave() à chaque enregistrement. */
$kitt->createCommands();
check('un second enregistrement n\'en crée pas d\'autres', count($kitt->getCmdList()), 11);
check('la version du plugin est lisible', k2000be::pluginVersion(), '0.1');
check('les dossiers de travail existent', is_dir(k2000beJournal::racine() . '/conversations'), true);

/* ============================================================ SÉCURITÉ */
section('Sécurité : ce qui peut partir, et ce qui ne le peut pas');

check('refus par défaut', k2000beSecurite::controler(102)['decision'], 'deny');
check('le refus nomme la commande',
    strpos(k2000beSecurite::controler(102)['motif'], 'Lumière salon — Éteindre') !== false, true);
check('le refus dit où l\'autoriser',
    strpos(k2000beSecurite::controler(102)['motif'], 'onglet Autorisations') !== false, true);
check('autorisation explicite', k2000beSecurite::controler(101)['decision'], 'allow');
check('confirmation demandée', k2000beSecurite::controler(120)['decision'], 'confirm');
check('le titre est lisible par un humain', k2000beSecurite::controler(101)['titre'], 'Lumière salon — Allumer');

check('un état ne s\'exécute pas', k2000beSecurite::controler(100)['decision'], 'deny');
check('et on le dit clairement',
    strpos(k2000beSecurite::controler(100)['motif'], 'il ne s\'exécute pas') !== false, true);
check('un identifiant inventé est refusé', k2000beSecurite::controler(9999)['decision'], 'deny');

check('équipement masqué : refus malgré l\'autorisation', k2000beSecurite::controler(141)['decision'], 'deny');
check('équipement désactivé : refus malgré l\'autorisation', k2000beSecurite::controler(150)['decision'], 'deny');
check('l\'assistant ne se pilote pas lui-même',
    k2000beSecurite::controler($kitt->getCmd('action', 'ask')->getId())['decision'], 'deny');
check('et le motif le dit',
    strpos(k2000beSecurite::controler($kitt->getCmd('action', 'ask')->getId())['motif'], 'ne se pilote pas lui-même') !== false, true);

check('slider hors bornes refusé', k2000beSecurite::controler(103, 250)['decision'], 'deny');
check('le refus rappelle les bornes',
    strpos(k2000beSecurite::controler(103, 250)['motif'], 'de 0 à 100') !== false, true);
check('slider dans les bornes accepté', k2000beSecurite::controler(103, '60')['valeur'], 60);
check('slider sans valeur refusé', k2000beSecurite::controler(103)['decision'], 'deny');
check('slider non numérique refusé', k2000beSecurite::controler(103, 'beaucoup')['decision'], 'deny');
check('la valeur part sous la clé attendue par le cœur',
    k2000beSecurite::options(cmd::byId(103), 60), array('slider' => 60));

check('select hors liste refusé', k2000beSecurite::controler(112, 'Turbo')['decision'], 'deny');
check('le refus énumère les choix',
    strpos(k2000beSecurite::controler(112, 'Turbo')['motif'], 'Confort, Eco, Hors gel') !== false, true);
check('select par valeur technique', k2000beSecurite::controler(112, '2')['valeur'], '2');
/* Le modèle relit le libellé qu'on lui a montré : le traduire évite un refus
 * que l'utilisateur jugerait absurde. */
check('select par libellé lu dans get_equipment', k2000beSecurite::controler(112, 'Confort')['valeur'], '1');

check('un portail est sensible', k2000beSecurite::sensible(cmd::byId(120)), true);
check('une lampe ne l\'est pas', k2000beSecurite::sensible(cmd::byId(101)), false);

check('état lisible par défaut', k2000beSecurite::lisible(cmd::byId(100)), true);
check('état explicitement interdit', k2000beSecurite::lisible(cmd::byId(131)), false);
check('état d\'un équipement masqué', k2000beSecurite::lisible(cmd::byId(140)), false);
check('état d\'un équipement désactivé', k2000beSecurite::lisible(cmd::byId(151)), false);

reglage('lecture_defaut', 0);
check('lecture_defaut à 0 : plus rien par défaut', k2000beSecurite::lisible(cmd::byId(100)), false);
check('sauf ce qui est explicitement lisible', k2000beSecurite::lisible(cmd::byId(121)), true);
reglage('lecture_defaut', 1);

reglage('securite', 'lecture');
check('mode lecture : même une commande autorisée est refusée',
    k2000beSecurite::controler(101)['decision'], 'deny');
check('et le motif renvoie au réglage',
    strpos(k2000beSecurite::controler(101)['motif'], 'lecture seule') !== false, true);
reglage('securite', 'simulation');
check('mode simulation : la commande reste autorisée', k2000beSecurite::controler(101)['decision'], 'allow');
check('mais le motif prévient',
    strpos(k2000beSecurite::controler(101)['motif'], 'rien ne partira') !== false, true);
reglage('securite', 'nimporte quoi');
check('un mode illisible retombe sur le plus prudent', k2000beSecurite::mode(), 'lecture');
reglage('securite', 'actions');

$compteurs = k2000beSecurite::compteurs();
check('commandes autorisées', $compteurs['autorisees'], 4);
check('commandes sous confirmation', $compteurs['confirmation'], 1);
check('commandes interdites, masquées et désactivées comprises', $compteurs['interdites'], 4);
check('états lisibles', $compteurs['lisibles'], 4);

/* L'onglet Autorisations montre ce que l'assistant ne voit pas : c'est là qu'on
 * vient démasquer un équipement. */
$arbre = k2000beSecurite::arbre();
$pieces = array();
foreach ($arbre as $piece) {
    $pieces[] = $piece['objet'];
}
check('l\'arbre range par pièce', $pieces, array('Cave', 'Chambre', 'Entrée', 'Salon'));
check('l\'équipement masqué y figure quand même', $arbre[0]['equipements'][0]['nom'], 'Coffre-fort');
check('et il est marqué comme masqué', $arbre[0]['equipements'][0]['masque'], true);
$types = array();
foreach ($arbre as $piece) {
    foreach ($piece['equipements'] as $equipement) {
        $types[] = $equipement['type'];
    }
}
check('l\'assistant, lui, n\'y figure pas : rien à y régler', in_array('k2000be', $types, true), false);

$leve = false;
try { k2000beSecurite::definirPolitique(100, 'allow'); } catch (Throwable $e) { $leve = true; }
check('on n\'autorise pas l\'exécution d\'un état', $leve, true);
$leve = false;
try { k2000beSecurite::definirPolitique(101, 'peut-être'); } catch (Throwable $e) { $leve = true; }
check('une autorisation inconnue est refusée', $leve, true);
$leve = false;
try { k2000beSecurite::masquerEquipement($kitt->getId(), 1); } catch (Throwable $e) { $leve = true; }
check('masquer un assistant n\'a pas de sens', $leve, true);
k2000beSecurite::definirPolitique(102, 'allow');
check('une autorisation accordée prend effet tout de suite', k2000beSecurite::politique(cmd::byId(102)), 'allow');
k2000beSecurite::definirPolitique(102, 'deny');
check('et se retire aussi vite', k2000beSecurite::controler(102)['decision'], 'deny');

/* ========================================================== ÉTANCHÉITÉ */
section('Étanchéité : ce qui ne doit apparaître nulle part');

k2000beSecurite::oublier();
k2000beOutils::oublier();

$pieces = outil('list_rooms');
check('quatre équipements visibles', $pieces['resultat']['total_equipments'], 4);
check('trois pièces, la Cave exclue', count($pieces['resultat']['rooms']), 3);

$liste = outil('list_equipments');
check('la liste ne montre que les quatre', count($liste['resultat']['equipments']), 4);
$filtre = outil('list_equipments', array('room' => 'salon'));
check('filtre par pièce, sans accent ni casse', count($filtre['resultat']['equipments']), 1);
$filtre = outil('list_equipments', array('generic_type' => 'GB_OPEN'));
check('filtre par type générique', $filtre['resultat']['equipments'][0]['name'], 'Portail');

$detail = outil('get_equipment', array('equipment_ids' => array(10, 13, 14, 15, 20)));
check('deux équipements détaillés sur cinq demandés', count($detail['resultat']['equipments']), 2);
check('les trois autres sont simplement indisponibles', $detail['resultat']['not_available'], array(14, 15, 20));
check('l\'alarme ne montre qu\'un seul état', count($detail['resultat']['equipments'][1]['states']), 1);
check('et c\'est le bon', $detail['resultat']['equipments'][1]['states'][0]['name'], 'État alarme');
check('la lampe montre ses trois actions', count($detail['resultat']['equipments'][0]['actions']), 3);
check('avec leur autorisation en clair', $detail['resultat']['equipments'][0]['actions'][1]['policy'], 'forbidden');

$etats = outil('get_states', array('command_ids' => array(100, 110, 131, 140, 151)));
check('deux états lus', count($etats['resultat']['states']), 2);
check('les trois autres sont refusés sans un mot', $etats['resultat']['not_available'], array(131, 140, 151));

$recherche = outil('search', array('query' => 'alarme'));
check('la recherche trouve l\'équipement', count($recherche['resultat']['devices']), 1);
check('et le seul état lisible qui porte ce nom', count($recherche['resultat']['commands']), 1);
check('le coffre reste introuvable', outil('search', array('query' => 'coffre'))['resultat']['devices'], array());

check('l\'historique d\'un état interdit est refusé',
    outil('get_history', array('command_id' => 131))['resultat']['status'], 'error');

/* Le filet : tout ce que les huit outils, le résumé de la maison et l'invite
 * système ont produit, relu d'un bloc à la recherche des six secrets. */
$tout = array(
    $pieces, $liste, $detail, $etats, $recherche,
    outil('list_equipments', array('limit' => 100)),
    outil('get_equipment', array('equipment_ids' => array(10, 11, 12, 13, 14, 15))),
    outil('get_states', array('command_ids' => array(100, 110, 121, 131, 132, 140, 151))),
    outil('search', array('query' => 'e')),
    outil('search', array('query' => 'o')),
    outil('search', array('query' => 'code')),
    outil('get_history', array('command_id' => 140)),
    k2000beOutils::resumeMaison(),
    $kitt->systemPrompt(),
);
check('aucun secret dans les résultats d\'outils', fuites($tout), array());

/*
 * L'invite ne disait jamais le mode de sécurité : en lecture comme en
 * simulation, le modèle l'apprenait en se cognant au premier résultat d'outil,
 * promettait d'agir, puis devait se dédire. Une ligne conditionnelle économise
 * un aller-retour par demande et une réponse qui se contredit.
 */
reglage('securite', 'lecture');
check('l\'invite annonce la lecture seule',
    strpos($kitt->systemPrompt(), 'lecture seule') !== false, true);
reglage('securite', 'simulation');
check('l\'invite annonce la simulation',
    strpos($kitt->systemPrompt(), 'simulation') !== false, true);
check('et dit au modèle que rien ne part',
    strpos($kitt->systemPrompt(), 'n\'est PAS partie') !== false, true);
reglage('securite', 'actions');
check('en mode actions, l\'invite ne dit rien de tel',
    strpos($kitt->systemPrompt(), 'lecture seule') === false
    && strpos($kitt->systemPrompt(), 'mode simulation') === false, true);
check('ni dans le résumé de la maison', fuites(k2000beOutils::resumeMaison()), array());
check('ni dans l\'invite système', fuites($kitt->systemPrompt()), array());

/* =================================================== FICHE DE LA MAISON */
section('La fiche de la maison');

/*
 * La fiche dit au modèle ce que les équipements ne disent pas : qui habite là,
 * comment on chauffe, à quelle heure on se couche. Elle repart chez OpenAI à
 * chaque demande — ce qui s'y glisse par erreur y reste pour toujours. Tout ce
 * qui suit éprouve donc autant ce qui SORT que ce qui ne sort pas.
 */

/* La ligne d'un champ, isolée du reste de l'invite. */
function ligneFiche($_invite, $_libelle) {
    foreach (explode("\n", $_invite) as $ligne) {
        if (strpos($ligne, $_libelle . ' : ') === 0) {
            return $ligne;
        }
    }
    return '';
}

/* Une en-tête orpheline annoncerait une description de la maison qui ne vient
 * jamais : sans un seul champ rempli, le bloc entier doit disparaître. */
check('aucun champ rempli, aucun bloc',
    strpos($kitt->systemPrompt(), 'telle que son propriétaire la décrit') === false, true);

reglage('maison_chauffage', 'Pompe à chaleur air-eau, poêle à bois au salon.');
$invite = $kitt->systemPrompt();
check('un champ rempli produit sa ligne, préfixée de son libellé',
    ligneFiche($invite, 'Chauffage et eau chaude'),
    'Chauffage et eau chaude : Pompe à chaleur air-eau, poêle à bois au salon.');
check('le bloc s\'ouvre en disant d\'où viennent ces informations',
    strpos($invite, 'La maison, telle que son propriétaire la décrit.') !== false, true);
/* Le point qui compte le plus : la fiche n'est pas vérifiable, et une
 * description vieille de six mois ne doit jamais l'emporter sur un état relu à
 * l'instant. */
check('et qui, des deux, a le dernier mot',
    strpos($invite, 'si un outil dit autre chose, c\'est l\'outil qui a raison') !== false, true);
check('un champ vide ne produit aucune ligne', ligneFiche($invite, 'Animaux'), '');

reglage('maison_animaux', "   \n\t  ");
check('un champ rempli d\'espaces non plus',
    ligneFiche($kitt->systemPrompt(), 'Animaux'), '');

/* Une saisie sur plusieurs lignes doit rester une seule ligne : autrement, la
 * suite du champ passerait pour une information dont plus rien ne dit ce
 * qu'elle décrit. */
reglage('maison_animaux', "Un chat.\nIl circule la nuit.");
check('une saisie sur deux lignes en fait une seule',
    ligneFiche($kitt->systemPrompt(), 'Animaux'), 'Animaux : Un chat. Il circule la nuit.');

$fiche = array(
    'maison_foyer'     => 'Deux adultes, un enfant de trois ans ; quelqu\'un travaille de nuit.',
    'maison_animaux'   => 'Un chat, qui circule la nuit.',
    'maison_logement'  => 'Maison de deux étages, véranda au sud.',
    'maison_chauffage' => 'Pompe à chaleur air-eau, poêle à bois au salon.',
    'maison_habitudes' => 'Coucher vers 23 h, lever à 6 h 30. Télétravail le mardi.',
    'maison_interdits' => 'Ne jamais couper le congélateur du garage.',
    'maison_vehicule'  => 'Une voiture électrique, rechargée la nuit.',
);
foreach ($fiche as $cle => $valeur) {
    reglage($cle, $valeur);
}
$invite = $kitt->systemPrompt();

$libelles = array('Qui vit ici', 'Animaux', 'Logement', 'Chauffage et eau chaude',
                  'Habitudes et horaires', 'À ne jamais faire', 'Véhicule électrique');
$rangs = array();
foreach ($libelles as $libelle) {
    $rangs[] = strpos($invite, "\n" . $libelle . ' : ');
}
$tries = $rangs;
sort($tries);
check('les sept champs sortent, et dans l\'ordre de la page',
    !in_array(false, $rangs, true) && $rangs === $tries, true);
check('tous derrière l\'en-tête',
    strpos($invite, 'La maison, telle que') < $rangs[0], true);

/* L'ordre du bloc n'est pas un détail d'affichage : les faits d'abord, les
 * consignes du propriétaire ensuite, puisque celles-ci doivent pouvoir nuancer
 * tout ce qui précède. */
reglage('prompt_extra', 'Ne me vouvoie jamais.');
$invite = $kitt->systemPrompt();
check('les consignes libres restent après la fiche',
    strpos($invite, 'Consignes du propriétaire de la maison :') > $rangs[6], true);

/* Un champ qui déborde : coupé au dernier mot entier, points de suspension à
 * l'appui, et jamais au milieu d'un mot. */
reglage('maison_habitudes', trim(str_repeat('coucher tard ', 60)));
$ligne = ligneFiche($kitt->systemPrompt(), 'Habitudes et horaires');
$valeur = mb_substr($ligne, mb_strlen('Habitudes et horaires : '));
check('un champ trop long est ramené à 300 caractères',
    mb_strlen($valeur) <= k2000be::MAISON_CHAMP_MAX, true);
check('sans être réduit à peu de chose', mb_strlen($valeur) > 250, true);
check('la coupe se voit', mb_substr($valeur, -1), '…');
check('et tombe à la fin d\'un mot', mb_substr($valeur, -5), 'tard…');

/*
 * Le plafond global. Sept champs pleins feraient 2100 caractères renvoyés à
 * chaque phrase : les champs sont pris dans l'ordre, le premier qui déborde
 * est coupé, et les suivants sont abandonnés plutôt que de laisser la fiche
 * peser autant que le reste de l'invite.
 */
foreach (array_keys($fiche) as $cle) {
    reglage($cle, str_repeat('a', 400));
}
$invite = $kitt->systemPrompt();
$total = 0;
foreach ($libelles as $libelle) {
    $total += mb_strlen(ligneFiche($invite, $libelle));
}
check('la fiche entière tient dans son plafond',
    $total > 0 && $total <= k2000be::MAISON_FICHE_MAX, true);
check('le premier champ est servi en entier',
    mb_strlen(ligneFiche($invite, 'Qui vit ici')), mb_strlen('Qui vit ici : ') + k2000be::MAISON_CHAMP_MAX);
check('les derniers sont abandonnés, jamais réduits à un moignon',
    ligneFiche($invite, 'Véhicule électrique'), '');

/*
 * L'aperçu de la page de configuration passe par systemPrompt(), qui est une
 * méthode d'instance — mais ne lit rien de l'équipement. Une instance neuve
 * doit donc rendre la même chose : c'est ce qui permet de voir ce qu'on envoie
 * AVANT d'avoir créé le premier assistant, c'est-à-dire au moment précis où
 * l'on remplit cette page.
 */
foreach ($fiche as $cle => $valeur) {
    reglage($cle, $valeur);
}
$neuf = new k2000be();
check('un assistant neuf rend la fiche, sans avoir été enregistré',
    ligneFiche($neuf->systemPrompt(), 'Qui vit ici'),
    'Qui vit ici : ' . $fiche['maison_foyer']);
check('aucun secret dans l\'invite garnie', fuites($neuf->systemPrompt()), array());

/* La fiche est un réglage global : ce qui suit ne doit pas en hériter. */
foreach (array_keys($fiche) as $cle) {
    reglage($cle, '');
}
reglage('prompt_extra', '');
check('vidée, la fiche ne laisse rien derrière elle',
    strpos($kitt->systemPrompt(), 'telle que son propriétaire la décrit') === false, true);

/* ============================================================== OUTILS */
section('Les huit outils');

cmd::vider();
check('exécuter une commande autorisée l\'envoie',
    outil('execute_command', array('command_id' => 101))['resultat']['status'], 'sent');
check('elle est partie pour de bon', count(cmd::$envois), 1);
check('le résultat ne prétend pas qu\'elle a abouti',
    strpos(outil('execute_command', array('command_id' => 101))['resultat']['note'], 'not proof') !== false, true);

cmd::vider();
$refus = outil('execute_command', array('command_id' => 102));
check('une commande interdite est refusée', $refus['resultat']['status'], 'refused');
check('rien n\'est parti', count(cmd::$envois), 0);
check('l\'étape porte le refus', $refus['etape']['statut'], 'refus');
check('le modèle reçoit le motif, mot pour mot', $refus['resultat']['reason'], $refus['etape']['detail']);

/*
 * Le refus ordinaire ci-dessus nomme la commande : c'est voulu, le modèle doit
 * pouvoir expliquer la règle. Le refus d'un équipement masqué, lui, ne doit rien
 * apprendre à personne — ni le nom, ni même le fait qu'il existe. Un identifiant
 * inventé et un identifiant masqué doivent donc rendre EXACTEMENT la même chose :
 * c'est ce qui ôte tout intérêt à énumérer les identifiants un par un.
 */
$invente = outil('execute_command', array('command_id' => 9999));
$masquee = outil('execute_command', array('command_id' => 141));
check('un identifiant inventé est refusé', $invente['resultat']['status'], 'refused');
check('une commande d\'équipement masqué aussi', $masquee['resultat']['status'], 'refused');
/* Les deux phrases ne diffèrent que par l'identifiant que le modèle vient
 * d'envoyer lui-même : le remplacer avant de comparer est la seule façon de
 * vérifier qu'il ne reste rien d'autre. */
$sansId = function ($_texte) { return preg_replace('/\d+/', 'N', (string) $_texte); };
check('le motif rendu au modèle est le même',
    $sansId($masquee['resultat']['reason']), $sansId($invente['resultat']['reason']));
check('le titre rendu au modèle aussi',
    $sansId($masquee['resultat']['command']), $sansId($invente['resultat']['command']));
check('et il ne nomme pas l\'équipement masqué',
    strpos(json_encode($masquee['resultat']), 'Coffre') === false, true);
check('la frise, elle, nomme les choses pour l\'administrateur',
    strpos($masquee['etape']['detail'], 'Coffre-fort') !== false, true);

cmd::vider();
$confirmation = outil('execute_command', array('command_id' => 120));
/* Ce qui déclenche la confirmation est la politique posée sur la commande,
 * jamais son caractère sensible : sensible() ne sert qu'à conseiller
 * l'administrateur dans l'onglet Autorisations, et le contrôle ne la consulte
 * pas. L'ancien libellé laissait croire à un mécanisme qui n'existe pas. */
check('une commande dont la politique demande un accord ne part pas', $confirmation['resultat']['status'], 'confirmation_required');
check('rien n\'est parti non plus', count(cmd::$envois), 0);
check('une attente est remontée', $confirmation['attente']['command_id'], 120);
check('le modèle est prié de s\'arrêter là',
    strpos($confirmation['resultat']['next'], 'Do not call any other tool') !== false, true);

cmd::vider();
check('valeur hors bornes refusée par l\'outil aussi',
    outil('execute_command', array('command_id' => 103, 'value' => '250'))['resultat']['status'], 'refused');
check('valeur correcte acceptée',
    outil('execute_command', array('command_id' => 103, 'value' => '60'))['resultat']['status'], 'sent');
check('et transmise au cœur', cmd::$envois[0]['options'], array('slider' => 60));

cmd::vider();
reglage('securite', 'simulation');
$simule = outil('execute_command', array('command_id' => 101));
/* Le libellé disait l'exact contraire de la doctrine du plugin : la note
 * jointe au résultat dit au modèle que RIEN n'est parti, précisément pour
 * qu'il ne rapporte pas une action faite. */
check('en simulation, le modèle apprend que rien n\'est parti', $simule['resultat']['status'], 'simulated');
check('mais rien n\'a bougé dans la maison', count(cmd::$envois), 0);
check('et l\'étape le dit', $simule['etape']['statut'], 'simule');
reglage('securite', 'lecture');
check('en lecture seule, tout est refusé',
    outil('execute_command', array('command_id' => 101))['resultat']['status'], 'refused');
check('et le détail des équipements l\'annonce',
    strpos(outil('get_equipment', array('equipment_ids' => array(10)))['resultat']['mode'], 'read_only') !== false, true);
check('toutes les actions y passent en interdites',
    outil('get_equipment', array('equipment_ids' => array(10)))['resultat']['equipments'][0]['actions'][0]['policy'], 'forbidden');
check('mais les états restent lisibles',
    count(outil('get_equipment', array('equipment_ids' => array(10)))['resultat']['equipments'][0]['states']), 1);
reglage('securite', 'actions');
check('rien n\'est parti pendant tout cela', count(cmd::$envois), 0);

/*
 * Soixante relevés ont été écrits plus haut : l'outil doit en rendre moins, et
 * garder le plus récent. Ce sont les deux seules choses qui comptent pour
 * l'appelant ; la forme exacte du résultat appartient à k2000beOutils, et la
 * figer ici ferait échouer cet essai à chaque enrichissement du rendu.
 *
 * L'archivage du cœur est repoussé à quarante-huit heures le temps de ce
 * passage, et ce n'est pas un artifice : la demande porte sur vingt-quatre
 * heures, et c'est le rapport entre les deux qui décide du chemin. Sous le
 * réglage ordinaire — deux heures — le plugin DEMANDE les moyennes à Jeedom, le
 * cœur rend un point par heure, et il n'y a plus rien à dégrossir : c'est ce
 * que la section « Historique » éprouve plus bas. Ici on veut l'autre branche,
 * celle des relevés bruts d'une fenêtre non archivée, décimés à pas fixe.
 */
config::save('historyArchiveTime', 48, 'core');
$histoire = outil('get_history', array('command_id' => 110));
check('l\'historique répond', isset($histoire['resultat']['points']), true);
check('il est dégrossi', count($histoire['resultat']['points']) < 60, true);
check('et plafonné', count($histoire['resultat']['points']) <= 51, true);
$dernier = $histoire['resultat']['points'][count($histoire['resultat']['points']) - 1];
check('le dernier relevé est conservé',
    (string) (isset($dernier['v']) ? $dernier['v'] : (isset($dernier['value']) ? $dernier['value'] : '')), '100');
check('un état non historisé le dit sans se plaindre',
    outil('get_history', array('command_id' => 100))['resultat']['status'], 'no_history');
check('et ces relevés-là sont bien des relevés, pas des moyennes',
    strpos($histoire['resultat']['sampling'], 'raw') === 0, true);
config::save('historyArchiveTime', 2, 'core');

check('un outil inconnu devient un résultat d\'erreur', outil('bidule')['resultat']['status'], 'error');
check('une commande sans identifiant aussi',
    outil('execute_command', array())['resultat']['status'], 'error');
check('le catalogue compte huit outils', count(k2000beOutils::definitions()), 8);

/* =============================================================== BOUCLE */
section('La boucle de conversation');

$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'get_states', 'args' => array('command_ids' => array(100))))),
    reponseTexte('La lumière du salon est éteinte.'),
));
$retour = $kitt->ask('Le salon est-il allumé ?', array('utilisateur' => 'jerome'));
check('statut', $retour['statut'], 'SUCCESS');
check('la réponse du modèle est rendue telle quelle', $retour['reponse'], 'La lumière du salon est éteinte.');
check('deux allers-retours', count(rejeu::$charges), 2);
check('une étape dans la frise', count($retour['etapes']), 1);
check('les jetons des deux appels sont cumulés', $retour['jetons']['total'], 370);
check('le catalogue d\'outils est envoyé', count(rejeu::charge(0)['tools']), 8);
check('le modèle choisit lui-même', rejeu::charge(0)['tool_choice'], 'auto');
check('l\'invite système ouvre la conversation', rejeu::charge(1)['messages'][0]['role'], 'system');
check('un message tool par tool_call_id', integriteOutils(rejeu::charge(1)['messages']), 0);
check('l\'invite système n\'est pas mémorisée',
    k2000beJournal::charger($kitt->getId())['messages'][0]['role'], 'user');
check('rien de la maison cachée n\'est parti chez OpenAI', fuites(rejeu::$charges), array());

/* Conversation neuve : ce qui suit compte les messages tool d'un seul tour, et
 * la mémoire du tour précédent en porte déjà. */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(
        array('outil' => 'list_rooms'),
        array('outil' => 'list_equipments', 'args' => array('room' => 'salon')),
    )),
    reponseTexte('Quatre équipements, dont un au salon.'),
));
$retour = $kitt->ask('Que vois-tu ?');
check('deux outils dans un seul message', count($retour['etapes']), 2);
check('deux réponses tool', compterRole(rejeu::charge(1)['messages'], 'tool'), 2);
check('intégrité conservée', integriteOutils(rejeu::charge(1)['messages']), 0);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 102)))),
    reponseTexte('Je n\'ai pas le droit d\'éteindre cette lampe.'),
));
$retour = $kitt->ask('Éteins le salon.');
check('un tour entièrement refusé vaut REFUSED', $retour['statut'], 'REFUSED');
check('rien n\'est parti', count(cmd::$envois), 0);
check('le refus a sa réponse tool', integriteOutils(rejeu::charge(1)['messages']), 0);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 101)))),
    reponseTexte('C\'est allumé.'),
));
$retour = $kitt->ask('Allume le salon.');
check('une commande autorisée part', count(cmd::$envois), 1);
check('et c\'est la bonne', cmd::$envois[0]['id'], 101);
check('le compte d\'actions est publié', $kitt->publies['actions'], 1);
check('le statut est publié', $kitt->publies['status'], 'SUCCESS');
check('la réponse est publiée', $kitt->publies['reply'], 'C\'est allumé.');

/*
 * Des arguments tronqués sont un appel sans argument, pas une panne : la
 * conversation continue et le modèle conclut. Le tour, lui, ne vaut plus
 * SUCCESS : le seul outil demandé a échoué, rien n'a été lu ni envoyé, et un
 * scénario qui lisait « réussite » enchaînait sur une phrase d'excuse en
 * croyant la maison pilotée. C'est la grille des statuts qui tranche : rien
 * d'exploitable, donc ERROR.
 */
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'brut' => '{"command_i'))),
    reponseTexte('Je n\'ai pas compris quelle commande lancer.'),
));
$retour = $kitt->ask('Fais quelque chose.');
check('des arguments illisibles n\'arrêtent pas la conversation', $retour['reponse'],
    'Je n\'ai pas compris quelle commande lancer.');
check('mais le tour ne vaut pas succès', $retour['statut'], 'ERROR');
check('rien n\'est parti', count(cmd::$envois), 0);

/* Même règle pour un outil qui n'existe pas : le modèle bavarde, mais rien
 * n'a marché. */
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'ouvre_tout'))),
    reponseTexte('Je n\'y arrive pas.'),
));
$retour = $kitt->ask('Appelle un outil imaginaire.');
check('un outil inconnu ne vaut pas succès non plus', $retour['statut'], 'ERROR');

/* Un refus au milieu de lectures réussies reste un succès : quelque chose a
 * été obtenu, et la grille ne juge que l'absence totale de résultat. */
rejeu::scenario(array(
    reponseOutils(array(
        array('outil' => 'list_rooms'),
        array('outil' => 'execute_command', 'args' => array('command_id' => 102)),
    )),
    reponseTexte('J\'ai regardé, mais je n\'ai pas le droit d\'éteindre.'),
));
$retour = $kitt->ask('Regarde, puis éteins le salon.');
check('un refus parmi des lectures réussies reste un succès', $retour['statut'], 'SUCCESS');

reglage('max_tool_calls', 2);
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseTexte('Je m\'arrête là.'),
));
$retour = $kitt->ask('Décris-moi toute la maison, pièce par pièce.');
check('la limite d\'outils arrête la boucle', $retour['statut'], 'LIMIT');
check('deux outils exécutés, plus l\'étape de limite', count($retour['etapes']), 3);
check('la dernière étape explique l\'arrêt', $retour['etapes'][2]['outil'], 'limite');
check('le dernier appel se fait sans outil', isset(rejeu::charge(3)['tools']), false);
check('et le modèle est prié de conclure',
    strpos(rejeu::charge(3)['messages'][count(rejeu::charge(3)['messages']) - 1]['content'], 'Conclus maintenant') !== false, true);
check('l\'appel de trop a quand même sa réponse tool', integriteOutils(rejeu::charge(3)['messages']), 0);

/* Un modèle qui n'en fait qu'à sa tête : il rappelle un outil alors qu'on ne
 * lui en propose plus. Le garde-fou doit couper. */
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseOutils(array(array('outil' => 'list_rooms'))),
));
$retour = $kitt->ask('Recommence sans fin.');
check('le garde-fou borne les allers-retours', count(rejeu::$charges), 4);
check('statut', $retour['statut'], 'LIMIT');
check('une phrase est écrite malgré le silence du modèle',
    strpos($retour['reponse'], 'Je me suis arrêté') !== false, true);

/*
 * Le champ n'était borné que par le bas : mille outils autorisés donnaient
 * quarante et un allers-retours enchaînés sans que rien ne bronche, et c'est
 * la facture qui partait. Un chiffre tapé de travers ne doit pas pouvoir
 * coûter cela.
 */
reglage('max_tool_calls', 1000);
$reponses = array();
for ($i = 0; $i < k2000be::MAX_OUTILS_MAX + 10; $i++) {
    $reponses[] = reponseOutils(array(array('outil' => 'list_rooms')));
}
rejeu::scenario($reponses);
$retour = $kitt->ask('Fais mille choses.');
check('mille outils autorisés ne donnent pas mille allers-retours',
    count(rejeu::$charges), k2000be::MAX_OUTILS_MAX + 2);
check('et le tour s\'arrête sur la limite', $retour['statut'], 'LIMIT');
reglage('max_tool_calls', 10);

/*
 * Le budget de temps. Le pire cas de la boucle dépassait l'heure, quand PHP
 * coupe à 600 s : il coupait alors AU MILIEU, sans que rien ne soit enregistré,
 * alors que des commandes avaient pu partir. On rejoue ici un tour dont
 * l'horloge dit qu'il dure depuis longtemps déjà.
 */
$ancienMax = ini_get('max_execution_time');
ini_set('max_execution_time', 200);
check('le budget se cale sur max_execution_time', invoquer('budget', array()), 170);
ini_set('max_execution_time', 0);
check('sans limite PHP — un scénario — un plafond s\'applique quand même',
    invoquer('budget', array()), k2000be::BUDGET_DEFAUT);
ini_set('max_execution_time', $ancienMax);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'list_rooms'))),
    reponseTexte('Cette phrase ne devrait jamais être demandée.'),
));
$resultat = invoquerSur($kitt, 'jouer', array(
    array(array('role' => 'user', 'content' => 'Décris-moi la maison.')),
    array(),
    array('debut' => microtime(true) - 100000),
));
check('le premier aller-retour part malgré tout', count(rejeu::$charges), 1);
check('le budget dépassé arrête la boucle', $resultat['statut'], 'LIMIT');
check('et laisse une trace dans la frise',
    $resultat['etapes'][count($resultat['etapes']) - 1]['outil'], 'budget');
check('la conversation reste recevable par l\'API', integriteOutils($resultat['messages']), 0);

/* ============================================= APPELS D'OUTILS ABÎMÉS */
section('Des appels d\'outils abîmés');

/*
 * Un tool_call sans identifiant, ou à identifiant répété, empoisonnait la
 * conversation POUR DE BON : le message tool reprenait l'identifiant tel quel
 * — vide s'il manquait — l'API refusait en bloc au tour suivant, et comme la
 * conversation fautive était enregistrée, l'assistant restait mort jusqu'au
 * prochain reset.
 */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'list_rooms', 'id' => null))),
    reponseTexte('Quatre équipements.'),
));
$retour = $kitt->ask('Que vois-tu ?');
check('un appel sans identifiant est tout de même exécuté', count($retour['etapes']), 1);
check('il reçoit un identifiant de secours',
    integriteOutils(rejeu::charge(1)['messages']), 0);
check('et la conversation enregistrée reste rejouable',
    integriteOutils(k2000beJournal::charger($kitt->getId())['messages']), 0);
$identifiantsTool = array();
foreach (rejeu::charge(1)['messages'] as $message) {
    if (isset($message['role']) && $message['role'] === 'tool') {
        $identifiantsTool[] = (string) $message['tool_call_id'];
    }
}
check('aucun message tool ne cite un identifiant vide',
    in_array('', $identifiantsTool, true), false);

$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(
        array('outil' => 'list_rooms', 'id' => 'call_jumeau'),
        array('outil' => 'list_equipments', 'id' => 'call_jumeau'),
    )),
    reponseTexte('Voilà ce que je vois.'),
));
$retour = $kitt->ask('Décris la maison.');
check('un identifiant répété est écarté, pas exécuté', count($retour['etapes']), 1);
check('la conversation reste recevable', integriteOutils(rejeu::charge(1)['messages']), 0);
check('le tour aboutit quand même', $retour['statut'], 'SUCCESS');

/*
 * Et le contrôle d'intégrité lui-même, qui était aveugle à ces deux cas :
 * l'appel sans identifiant donnait la clé '', que le message tool à identifiant
 * vide venait consommer. Zéro anomalie sur une conversation morte.
 */
$appelSansId = array(
    array('role' => 'user', 'content' => 'salut'),
    array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => '', 'type' => 'function', 'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
    )),
    array('role' => 'tool', 'tool_call_id' => '', 'content' => '{}'),
);
check('un appel sans identifiant est désormais une anomalie', integriteOutils($appelSansId), 2);

$appelEnDouble = array(
    array('role' => 'user', 'content' => 'salut'),
    array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => 'x', 'type' => 'function', 'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
        array('id' => 'x', 'type' => 'function', 'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
    )),
    array('role' => 'tool', 'tool_call_id' => 'x', 'content' => '{}'),
    array('role' => 'tool', 'tool_call_id' => 'x', 'content' => '{}'),
);
check('un identifiant répété aussi', integriteOutils($appelEnDouble), 2);

/* ======================================= CE QUE DIT LA RAISON D'ARRÊT */
section('La raison d\'arrêt du modèle');

$kitt->reset();

/*
 * Une réponse vide : ni texte, ni outil. La phrase de remplacement reste — la
 * frise ne doit pas afficher un vide — mais le statut ne peut plus valoir
 * SUCCESS. Le scénario lisait « réussite » et enchaînait sur « Je n'ai rien à
 * ajouter. » en croyant que l'assistant avait répondu ; c'est un tour perdu, et
 * la grille le nomme ERROR.
 */
rejeu::scenario(array(fixture('reponse-vide')));
$retour = $kitt->ask('Bonjour.');
check('une réponse vide devient une phrase', $retour['reponse'], 'Je n\'ai rien à ajouter.');
check('mais le tour n\'est pas un succès', $retour['statut'], 'ERROR');

/* La raison d'arrêt était calculée puis jetée : « length » rendait une phrase
 * amputée et un SUCCESS. */
rejeu::scenario(array(reponseTexte('Dans le salon, la lampe est allumée et le vol', array(300, 400), 'length')));
$retour = $kitt->ask('Décris-moi le salon en détail.');
check('une réponse coupée n\'est pas un succès', $retour['statut'], 'LIMIT');
check('la phrase rendue dit qu\'elle est coupée',
    strpos($retour['reponse'], 'coupée') !== false, true);
check('et le début de la réponse est conservé',
    strpos($retour['reponse'], 'la lampe est allumée') !== false, true);
check('la frise le montre', $retour['etapes'][count($retour['etapes']) - 1]['outil'], 'longueur');

/* Un refus du fournisseur n'est pas une réponse : le taire rendait une phrase
 * vide et un succès. */
rejeu::scenario(array(reponseTexte('', array(300, 0), 'content_filter')));
$retour = tenter('refus du fournisseur', function () use ($kitt) {
    return $kitt->ask('Une demande que le fournisseur refuse.');
});
check('un filtre de contenu est une erreur', $retour['statut'], 'ERROR');
check('et il est nommé pour ce qu\'il est',
    strpos((string) $retour['erreur'], 'filtre de contenu') !== false, true);

/* Les deux incidents qui ne coûtent pas un jeton. Ils sont racontés comme des
 * pannes, jamais comme des refus, et laissent une trace : sans elle,
 * l'utilisateur chercherait dans l'historique une demande qui n'y serait
 * pas. */
rejeu::scenario(array());
$retour = tenter('demande vide', function () use ($kitt) {
    return $kitt->ask('   ');
});
check('une demande vide est un incident', $retour['statut'], 'ERROR');
check('et n\'appelle pas le modèle', count(rejeu::$charges), 0);
check('la frise en garde la trace',
    $kitt->toAjax()['tours'][count($kitt->toAjax()['tours']) - 1]['statut'], 'ERROR');

reglage('apikey', '');
$retour = tenter('demande sans clé API', function () use ($kitt) {
    return $kitt->ask('Bonjour.');
});
check('sans clé API, l\'assistant le dit avant d\'appeler',
    strpos((string) $retour['erreur'], 'Aucune clé API') !== false, true);
check('et n\'appelle pas le modèle', count(rejeu::$charges), 0);
reglage('apikey', CLE_API);

/* ========================================================= CONFIRMATION */
section('Confirmation');

$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$retour = $kitt->ask('Ouvre le portail.');
check('statut', $retour['statut'], 'CONFIRMATION');
check('un seul aller-retour : la boucle s\'arrête net', count(rejeu::$charges), 1);
check('rien n\'est parti au premier tour', count(cmd::$envois), 0);
check('un jeton de vingt caractères', strlen($retour['attente']['jeton']), 20);
check('la confirmation porte sur une commande précise', $retour['attente']['demandes'][0]['command_id'], 120);
/* Une attente porte UNE demande : l'outil s'arrête au premier
 * « confirmation_required » et prie le modèle de ne rien appeler d'autre. Le
 * code le dit désormais partout, au lieu d'itérer un pluriel que rien ne
 * remplissait jamais. */
check('et sur une seule', count($retour['attente']['demandes']), 1);
check('son résumé nomme la commande', k2000be::resumeAttente($retour['attente']), 'Portail — Ouvrir');
check('une attente absente n\'a pas de résumé', k2000be::resumeAttente(null), '');
check('elle vaut cinq minutes', $retour['attente']['expire'] - $retour['attente']['creee'], 300);
check('la phrase dit sur quoi on s\'engage', $retour['reponse'],
    'Cette action demande votre confirmation : Portail — Ouvrir.');
check('le scénario sait qu\'on attend', $kitt->publies['pending'], 1);
check('et sait sur quoi', $kitt->publies['question'], 'Portail — Ouvrir');

$jeton = $retour['attente']['jeton'];
rejeu::scenario(array(reponseTexte('Le portail s\'ouvre.')));
$suite = $kitt->confirm($jeton, true);
check('la confirmation exécute enfin', count(cmd::$envois), 1);
check('et c\'est bien le portail', cmd::$envois[0]['id'], 120);
check('statut', $suite['statut'], 'SUCCESS');
check('la conversation est reprise là où elle s\'était arrêtée',
    integriteOutils(rejeu::charge(0)['messages']), 0);
check('le modèle retrouve son appel d\'outil',
    compterRole(rejeu::charge(0)['messages'], 'tool'), 1);
check('l\'attente est levée', $kitt->attente(), null);
check('et le scénario en est informé', $kitt->publies['pending'], 0);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$retour = $kitt->ask('Ouvre le portail.');
$jeton = $retour['attente']['jeton'];
rejeu::scenario(array(reponseTexte('Entendu, je n\'ouvre rien.')));
$suite = $kitt->confirm($jeton, false);
check('un refus de confirmation n\'exécute rien', count(cmd::$envois), 0);
check('statut', $suite['statut'], 'REFUSED');
check('l\'étape garde la trace du refus humain',
    strpos($suite['etapes'][0]['detail'], 'Confirmation refusée par l\'utilisateur') !== false, true);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$retour = $kitt->ask('Ouvre le portail.');
$suite = tenter('confirmation avec un jeton erroné', function () use ($kitt) {
    return $kitt->confirm('jetondunautreonglet', true);
});
check('un jeton erroné n\'exécute rien', count(cmd::$envois), 0);
/* Le libellé exact n'est pas le contrat : ce qui compte est que l'utilisateur
 * apprenne que ce jeton-là n'est plus celui de la demande en cours, et que rien
 * n'est parti. Comparer la phrase au mot près ferait échouer l'essai à chaque
 * reformulation. */
check('et le dit', strpos($suite['erreur'], 'ne correspond plus à la demande en cours') !== false, true);
check('sans rien avoir envoyé, et en le disant',
    strpos($suite['erreur'], 'rien n\'a été exécuté') !== false, true);
check('un jeton erroné est un refus, pas une panne', $suite['statut'], 'REFUSED');

/* Le « oui » d'il y a une heure ne vaut plus : on vieillit l'attente dans le
 * fichier, faute de pouvoir avancer l'horloge. */
$conversation = k2000beJournal::charger($kitt->getId());
$jeton = $conversation['attente']['jeton'];
$conversation['attente']['expire'] = time() - 1;
k2000beJournal::enregistrer($kitt->getId(), $conversation);
check('une attente périmée n\'est plus proposée', $kitt->attente(), null);
/* Ignorer l'attente périmée ne suffisait pas : tant qu'elle restait dans la
 * conversation, la tuile du tableau de bord réclamait un accord le lendemain
 * matin et un scénario branché dessus ne redescendait jamais. */
check('elle est effacée de la conversation', k2000beJournal::charger($kitt->getId())['attente'], null);
check('et la tuile ne réclame plus rien', $kitt->publies['pending'], 0);
check('la question posée est retirée aussi', $kitt->publies['question'], '');
$suite = tenter('confirmation périmée', function () use ($kitt, $jeton) {
    return $kitt->confirm($jeton, true);
});
check('une confirmation périmée n\'exécute rien', count(cmd::$envois), 0);
check('statut', $suite['statut'], 'REFUSED');
/* L'attente avait déjà été effacée par attente() : arrivé là, « périmée » et
 * « déjà traitée » ne se distinguent plus, et le message dit les deux. Ce qui
 * compte pour l'utilisateur est que ce jeton ne vaut plus, que rien n'est
 * parti, et qu'il doit reformuler. */
check('le message dit que ce jeton ne vaut plus',
    strpos($suite['erreur'], 'ne correspond plus à la demande en cours') !== false, true);
check('il en donne la raison',
    strpos($suite['erreur'], 'expiré') !== false, true);
check('le message invite à reformuler',
    strpos($suite['erreur'], 'Reformulez') !== false, true);
check('le bandeau disparaît', $kitt->publies['pending'], 0);

/* Deux outils demandés d'un coup, le premier exigeant une confirmation : le
 * second ne doit pas passer en douce. */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(
        array('outil' => 'execute_command', 'args' => array('command_id' => 120)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
    )),
));
$retour = $kitt->ask('Ouvre le portail et allume le salon.');
check('statut', $retour['statut'], 'CONFIRMATION');
check('le second outil n\'a pas été exécuté', count(cmd::$envois), 0);
$conversation = k2000beJournal::charger($kitt->getId());
check('il a quand même reçu sa réponse tool', compterRole($conversation['messages'], 'tool'), 2);
check('la conversation suspendue reste valide pour l\'API',
    integriteOutils($conversation['messages']), 0);
/* Reposer une question plutôt que répondre oui ou non, c'est avoir changé
 * d'avis : le portail ne doit pas s'ouvrir au prochain « confirmer » d'un
 * onglet resté ouvert. */
rejeu::scenario(array(reponseTexte('Entendu, je laisse tomber.')));
$kitt->ask('Finalement, laisse tomber.');
check('une demande neuve annule la confirmation en plan', $kitt->attente(), null);
check('et rien n\'est parti entre-temps', count(cmd::$envois), 0);

/* ============================================== DEUX APPELS À LA FOIS */
section('Deux appels à la fois');

/*
 * Le jeton n'était jamais consommé : confirm() lisait l'attente, vérifiait le
 * jeton, exécutait, et n'enregistrait qu'à la toute fin. Deux appels ayant lu
 * le fichier avant que le premier n'écrive passaient tous les deux — constaté
 * en production, deux envois de la même commande, SUCCESS les deux fois. Deux
 * onglets, la tuile pendant qu'un scénario confirme, un double-clic, un F5 sur
 * un POST : quatre façons ordinaires d'y arriver, sur la porte même que la
 * confirmation existe pour tenir.
 *
 * L'espion rejoue exactement cela : il rappelle confirm() au moment où le
 * premier appel parle au modèle, c'est-à-dire après l'exécution et avant
 * l'enregistrement — la fenêtre où les deux passaient.
 */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$retour = $kitt->ask('Ouvre le portail.');
$jeton = $retour['attente']['jeton'];

$pendant = array('attente' => 'pas observée', 'second' => null);
rejeu::scenario(array(reponseTexte('Le portail s\'ouvre.')));
rejeu::$espion = function () use ($kitt, $jeton, &$pendant) {
    /* L'attente doit déjà avoir disparu du fichier : elle est consommée avant
     * l'exécution, pas après la réponse du modèle. */
    $pendant['attente'] = k2000beJournal::charger($kitt->getId())['attente'];
    $pendant['second'] = $kitt->confirm($jeton, true);
};
$suite = $kitt->confirm($jeton, true);

check('le jeton est consommé avant l\'exécution', $pendant['attente'], null);
check('le second appel simultané n\'exécute rien', count(cmd::$envois), 1);
check('il est refusé, pas exécuté', $pendant['second']['statut'], 'REFUSED');
check('et il dit pourquoi',
    strpos((string) $pendant['second']['erreur'], 'déjà en cours') !== false, true);
check('le premier appel, lui, aboutit', $suite['statut'], 'SUCCESS');

/* Le même jeton rejoué plus tard — le F5 sur un POST — ne trouve plus rien. */
cmd::vider();
$encore = tenter('confirmation rejouée', function () use ($kitt, $jeton) {
    return $kitt->confirm($jeton, true);
});
check('un jeton déjà consommé n\'exécute rien', count(cmd::$envois), 0);
check('et le dit', strpos((string) $encore['erreur'], 'ne correspond plus à la demande en cours') !== false, true);

/*
 * Le même verrou vaut pour une demande ordinaire : la conversation faisait un
 * lire-modifier-écrire nu, et la seconde écriture écrasait la première —
 * mémoire, frise et attente comprises.
 */
$kitt->reset();
$pendant = array('second' => null);
rejeu::scenario(array(reponseTexte('Bonsoir.')));
rejeu::$espion = function () use ($kitt, &$pendant) {
    $pendant['second'] = $kitt->ask('Et pendant ce temps, une autre question ?');
};
$retour = $kitt->ask('Bonsoir ?');
check('une seconde demande simultanée est refusée', $pendant['second']['statut'], 'REFUSED');
check('elle n\'appelle pas le modèle', count(rejeu::$charges), 1);
check('et la première répond normalement', $retour['reponse'], 'Bonsoir.');
$conversation = k2000beJournal::charger($kitt->getId());
check('la mémoire n\'a pas été écrasée', compterRole($conversation['messages'], 'user'), 1);

/*
 * Le point de reprise. Rien n'était enregistré avant la fin du tour : si PHP
 * coupait le processus au milieu de la boucle — et le pire cas dépassait
 * l'heure — ni la conversation ni le journal ne gardaient trace d'un tour où
 * des commandes avaient pourtant pu partir. La conversation connue est donc
 * écrite avant de repartir vers le réseau.
 */
$kitt->reset();
$pendant = array('messages' => array());
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 101)))),
    reponseTexte('C\'est allumé.'),
));
rejeu::$espionRang = 2;
rejeu::$espion = function () use ($kitt, &$pendant) {
    $pendant['messages'] = k2000beJournal::charger($kitt->getId())['messages'];
};
$kitt->ask('Allume le salon.');
check('la commande partie est enregistrée avant le retour au réseau',
    compterRole($pendant['messages'], 'tool'), 1);
check('le point de reprise reste recevable par l\'API',
    integriteOutils($pendant['messages']), 0);
check('il ne laisse pas derrière lui une confirmation fantôme',
    k2000beJournal::charger($kitt->getId())['attente'], null);

/* ================================ UNE PANNE APRÈS UNE COMMANDE ENVOYÉE */
section('Une panne après une commande envoyée');

/*
 * Constaté : confirmation acceptée, portail ouvert, l'appel suivant échoue, et
 * la phrase lue par l'utilisateur était le seul message d'erreur. Elle ne
 * disait pas que le portail était ouvert, alors que les étapes, elles, le
 * savaient : on annonçait le contraire de ce qui venait de se passer.
 */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$retour = $kitt->ask('Ouvre le portail.');
$jeton = $retour['attente']['jeton'];
cmd::vider();
rejeu::scenario(array(fixture('erreur-quota')));
$suite = tenter('panne après exécution', function () use ($kitt, $jeton) {
    return $kitt->confirm($jeton, true);
});
check('le portail est bien parti', count(cmd::$envois), 1);
check('la phrase rendue dit ce qui a été fait',
    strpos((string) $suite['erreur'], 'Portail — Ouvrir') !== false, true);
check('elle le dit AVANT la panne',
    strpos((string) $suite['erreur'], 'Portail — Ouvrir') < strpos((string) $suite['erreur'], 'Quota'), true);
check('la panne reste dite', strpos((string) $suite['erreur'], 'Quota') !== false, true);
check('et le tour est une erreur', $suite['statut'], 'ERROR');
check('la frise raconte la même chose',
    $kitt->toAjax()['tours'][count($kitt->toAjax()['tours']) - 1]['texte'], $suite['erreur']);

/* ========================================================= MÉMOIRE */
section('Mémoire de la conversation');

$avant = array(
    array('role' => 'user', 'content' => 'premier'),
    array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => 'm1', 'type' => 'function', 'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
    )),
    array('role' => 'tool', 'tool_call_id' => 'm1', 'content' => '{}'),
    array('role' => 'assistant', 'content' => 'voilà'),
    array('role' => 'user', 'content' => 'second'),
    array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => 'm2', 'type' => 'function', 'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
    )),
    array('role' => 'tool', 'tool_call_id' => 'm2', 'content' => '{}'),
    array('role' => 'assistant', 'content' => 'voilà'),
);
$coupe = invoquer('limiterMemoire', array($avant, 1));
check('un seul tour conservé', count($coupe), 4);
check('la coupe tombe sur une demande', $coupe[0]['role'], 'user');
check('et ne sépare pas un tool_calls de ses tool', integriteOutils($coupe), 0);
check('deux tours demandés, rien n\'est coupé', count(invoquer('limiterMemoire', array($avant, 2))), 8);
$orphelin = array_merge(array(array('role' => 'tool', 'tool_call_id' => 'perdu', 'content' => '{}')), $avant);
check('un tool orphelin hérité d\'une ancienne version est jeté',
    integriteOutils(invoquer('limiterMemoire', array($orphelin, 5))), 0);

/*
 * La coupe par tours ne savait pas couper à l'intérieur d'un tour : elle ne
 * comptait que les messages « user ». Un seul tour de trente outils fait
 * soixante et un messages, conservés entiers quelle que soit la valeur de
 * memoire_tours, et repartant en entier à chaque demande suivante — le chemin
 * vers le 400 « context_length_exceeded », qui se réenregistre et se rejoue.
 */
$unSeulTour = array(array('role' => 'user', 'content' => 'une seule demande, beaucoup d\'outils'));
for ($i = 0; $i < 70; $i++) {
    $unSeulTour[] = array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => 'long' . $i, 'type' => 'function',
              'function' => array('name' => 'list_rooms', 'arguments' => '{}')),
    ));
    $unSeulTour[] = array('role' => 'tool', 'tool_call_id' => 'long' . $i, 'content' => '{}');
}
check('un seul tour peut dépasser le plafond de messages', count($unSeulTour) > k2000be::MEMOIRE_MESSAGES_MAX, true);
$coupe = invoquer('limiterMemoire', array($unSeulTour, 12));
check('la coupe entre alors dans le tour', count($coupe) <= k2000be::MEMOIRE_MESSAGES_MAX, true);
check('sans séparer un tool_calls de ses tool', integriteOutils($coupe), 0);
check('et en gardant la fin de la conversation',
    $coupe[count($coupe) - 1]['tool_call_id'], 'long69');

/* Le poids compte autant que le nombre : une lecture d'historique tient en un
 * message et pèse des dizaines de kilo-octets. */
$tourLourd = array(array('role' => 'user', 'content' => 'lis-moi tous les historiques'));
for ($i = 0; $i < 20; $i++) {
    $tourLourd[] = array('role' => 'assistant', 'content' => null, 'tool_calls' => array(
        array('id' => 'lourd' . $i, 'type' => 'function',
              'function' => array('name' => 'get_history', 'arguments' => '{}')),
    ));
    $tourLourd[] = array('role' => 'tool', 'tool_call_id' => 'lourd' . $i, 'content' => str_repeat('x', 5000));
}
check('la mémoire brute pèse trop lourd',
    strlen(json_encode($tourLourd)) > k2000be::MEMOIRE_OCTETS_MAX, true);
$coupe = invoquer('limiterMemoire', array($tourLourd, 12));
check('la coupe par la taille s\'applique',
    strlen(json_encode($coupe)) <= k2000be::MEMOIRE_OCTETS_MAX, true);
check('elle ne sépare rien non plus', integriteOutils($coupe), 0);
check('et garde les échanges les plus récents',
    $coupe[count($coupe) - 1]['tool_call_id'], 'lourd19');

/* Les deux réglages n'étaient bornés que par le bas. */
reglage('memoire_tours', 999);
check('la mémoire est bornée par le haut',
    invoquer('reglageEntier', array('memoire_tours', k2000be::MEMOIRE_DEFAUT, k2000be::MEMOIRE_MAX)),
    k2000be::MEMOIRE_MAX);
reglage('memoire_tours', '');
check('un champ vidé reprend le défaut',
    invoquer('reglageEntier', array('memoire_tours', k2000be::MEMOIRE_DEFAUT, k2000be::MEMOIRE_MAX)),
    k2000be::MEMOIRE_DEFAUT);
reglage('memoire_tours', 12);

$kitt->reset();
reglage('memoire_tours', 1);
foreach (array('Première question.', 'Deuxième question.') as $texte) {
    rejeu::scenario(array(
        reponseOutils(array(array('outil' => 'list_rooms'))),
        reponseTexte('Voilà.'),
    ));
    $kitt->ask($texte);
}
$conversation = k2000beJournal::charger($kitt->getId());
check('la mémoire enregistrée est coupée au dernier tour', compterRole($conversation['messages'], 'user'), 1);
check('elle commence par une demande', $conversation['messages'][0]['role'], 'user');
check('et reste envoyable à l\'API', integriteOutils($conversation['messages']), 0);
reglage('memoire_tours', 12);

/*
 * Les demandes restées sans réponse s'empilaient : après deux pannes réseau la
 * mémoire contenait trois « user » d'affilée, et le modèle répondait aux trois
 * questions d'un coup au tour suivant — une conversation que personne n'a eue,
 * et une invite qui grossit à chaque panne. Un tour qui n'a RIEN produit ne
 * laisse donc rien dans la mémoire ; dès qu'une étape existe, tout est gardé,
 * parce que des commandes ont pu partir et que le modèle doit le savoir.
 */
$kitt->reset();
rejeu::scenario(array(reponseTexte('Bonsoir.')));
$kitt->ask('Bonsoir ?');
foreach (array('Première question sans réponse ?', 'Deuxième question sans réponse ?') as $texte) {
    rejeu::scenario(array(fixture('erreur-quota')));
    tenter('demande avortée', function () use ($kitt, $texte) {
        return $kitt->ask($texte);
    });
}
$conversation = k2000beJournal::charger($kitt->getId());
check('deux pannes n\'empilent pas trois demandes', compterRole($conversation['messages'], 'user'), 1);
check('la mémoire garde le seul échange qui a eu lieu',
    $conversation['messages'][0]['content'], 'Bonsoir ?');
check('la frise, elle, garde la trace des deux pannes', count($conversation['tours']), 6);

/* ======================================================= CLIENT OPENAI */
section('Le client OpenAI');

rejeu::scenario(array(fixture('erreur-max-tokens'), reponseTexte('OK')));
$retour = k2000beOpenAI::chat(array(array('role' => 'user', 'content' => 'Salut')));
check('le refus de max_tokens est rattrapé tout seul', count(rejeu::$charges), 2);
check('max_tokens au premier essai', isset(rejeu::charge(0)['max_tokens']), true);
check('max_completion_tokens au second', isset(rejeu::charge(1)['max_completion_tokens']), true);
check('et max_tokens a disparu', isset(rejeu::charge(1)['max_tokens']), false);
check('la réponse finit par arriver', $retour['message']['content'], 'OK');

rejeu::scenario(array(fixture('erreur-temperature'), reponseTexte('OK')));
k2000beOpenAI::chat(array(array('role' => 'user', 'content' => 'Salut')));
check('la température refusée est retirée', isset(rejeu::charge(1)['temperature']), false);
check('elle avait bien été envoyée', isset(rejeu::charge(0)['temperature']), true);

$message = '';
rejeu::scenario(array(fixture('erreur-cle-refusee')));
try { k2000beOpenAI::chat(array(array('role' => 'user', 'content' => 'Salut'))); } catch (Throwable $e) { $message = $e->getMessage(); }
check('401 traduit pour l\'utilisateur', $message,
    'Clé API refusée par OpenAI. Vérifiez la clé dans la configuration du plugin.');
check('une erreur qu\'on ne sait pas corriger n\'est pas réessayée', count(rejeu::$charges), 1);

$message = '';
rejeu::scenario(array(fixture('erreur-quota')));
try { k2000beOpenAI::chat(array(array('role' => 'user', 'content' => 'Salut'))); } catch (Throwable $e) { $message = $e->getMessage(); }
check('429 traduit pour l\'utilisateur', strpos($message, 'Quota ou débit OpenAI dépassé') !== false, true);

/*
 * Un 404 a deux causes, et la plus fréquente derrière une passerelle n'est pas
 * le modèle : c'est une base_url à laquelle il manque « /v1 ». N'annoncer que
 * le modèle envoyait l'utilisateur corriger la seule chose qui allait bien.
 */
$message = '';
rejeu::scenario(array(fixture('erreur-modele')));
try { k2000beOpenAI::chat(array(array('role' => 'user', 'content' => 'Salut'))); } catch (Throwable $e) { $message = $e->getMessage(); }
check('404 nomme le modèle', strpos($message, 'modèle') !== false, true);
check('et aussi l\'adresse de l\'API', strpos($message, '/v1') !== false, true);

/*
 * Sur une relance, la cause initiale était écrasée : un 429 suivi d'une réponse
 * illisible faisait disparaître le quota du message, et l'utilisateur partait
 * vérifier l'adresse de l'API quand c'était son crédit qui manquait. Le rejeu
 * ne passe pas par la boucle de relance — le transport court-circuite curl —
 * mais la composition, elle, s'éprouve directement.
 */
check('deux causes sont rendues dans l\'ordre',
    invoquerClasse('k2000beOpenAI', 'causes', array(array('Quota dépassé.', 'Réponse illisible.'))),
    'Quota dépassé. La relance a échoué à son tour : Réponse illisible.');
check('une seule cause reste une phrase simple',
    invoquerClasse('k2000beOpenAI', 'causes', array(array('Quota dépassé.', ''))), 'Quota dépassé.');
check('et deux fois la même ne se répète pas',
    invoquerClasse('k2000beOpenAI', 'causes', array(array('Quota dépassé.', 'Quota dépassé.'))), 'Quota dépassé.');

/*
 * Trois champs voisins avaient trois conventions muettes : max_tokens à 0
 * valait « pas de plafond », max_tool_calls à 0 valait « 10 », timeout à 1
 * valait « 60 » — sans que rien ne prévienne que la valeur saisie avait été
 * remplacée.
 */
reglage('timeout', 1);
check('un délai trop court est ramené dans les bornes', k2000beOpenAI::delai(), k2000beOpenAI::TIMEOUT_MIN);
reglage('timeout', 99999);
check('un délai absurde aussi', k2000beOpenAI::delai(), k2000beOpenAI::TIMEOUT_MAX);
reglage('timeout', '');
check('et un champ vidé reprend le défaut du .ini', k2000beOpenAI::delai(), 60);
reglage('timeout', 60);

/*
 * Ce que ce dernier contrôle éprouve vraiment, c'est le rejeu lui-même : le
 * cœur rend la valeur du .ini du plugin dès que la valeur stockée est vide, là
 * où le bouchon rendait le défaut passé en argument. Aucun essai n'en dépendait,
 * mais le premier essai portant sur un champ VIDÉ aurait prouvé l'inverse de la
 * production.
 */
reglage('max_tokens', '');
check('un champ vidé retombe sur le .ini, comme dans le cœur',
    (int) config::byKey('max_tokens', 'k2000be', 4242), 1200);
reglage('max_tokens', 1200);

rejeu::scenario(array(reponseTexte('OK')));
$essai = k2000beOpenAI::essai();
check('l\'essai de clé réussit', $essai['ok'], true);
check('il nomme le modèle qui a répondu', $essai['modele'], 'gpt-4o-mini');
check('et ne dépense presque rien', rejeu::charge(0)['max_tokens'], 64);
rejeu::scenario(array(fixture('erreur-quota')));
$essai = k2000beOpenAI::essai();
check('un essai raté est un résultat, pas une panne', $essai['ok'], false);
check('avec la phrase destinée à l\'utilisateur', strpos($essai['message'], 'Quota') !== false, true);

rejeu::scenario(array(array('data' => array(
    array('id' => 'gpt-4o-mini'), array('id' => 'text-embedding-3-small'),
    array('id' => 'whisper-1'), array('id' => 'gpt-5'), array('id' => 'dall-e-3'),
))));
$modeles = k2000beOpenAI::modeles();
check('la liste des modèles écarte ce qui ne cause pas', $modeles, array('gpt-4o-mini', 'gpt-5'));
check('elle interroge le bon point d\'entrée', rejeu::$chemins[0], '/models');

/* =============================================================== JOURNAL */
section('Le journal et la clé API');

check('la clé configurée est retirée de tout texte',
    k2000beJournal::masquer('appel refusé avec ' . CLE_API . ' hier'), 'appel refusé avec … hier');
check('une clé inconnue au format OpenAI aussi',
    k2000beJournal::masquer('sk-proj-UNEAUTRECLE1234567890abcdef'), '…');
check('l\'en-tête Authorization est masqué',
    k2000beJournal::masquer('Authorization: Bearer abcdef1234567890'), 'Authorization: Bearer …');
check('un tableau est parcouru en profondeur',
    k2000beJournal::masquer(array('detail' => array('texte' => CLE_API)))['detail']['texte'], '…');
reglage('apikey', 'cle-passerelle-9f2b7c4d1e');
check('une clé de passerelle, sans préfixe, est masquée aussi',
    k2000beJournal::masquer('gateway key cle-passerelle-9f2b7c4d1e'), 'gateway key …');
reglage('apikey', 'court');
check('un reste de saisie trop court n\'est pas cherché',
    k2000beJournal::masquer('le mot court reste lisible'), 'le mot court reste lisible');
reglage('apikey', CLE_API);

$kitt->reset();
log::vider();
rejeu::scenario(array(fixture('erreur-avec-cle')));
$retour = $kitt->ask('Bonjour.');
check('une panne du modèle est un incident, pas un refus', $retour['statut'], 'ERROR');
check('le message d\'erreur remonte à l\'utilisateur',
    strpos($retour['erreur'], 'OpenAI a refusé la demande') !== false, true);
check('la clé n\'est pas dans le message rendu', strpos($retour['erreur'], 'rejeuK2000beSecret'), false);
check('ni dans le journal du plugin', strpos(log::texte(), 'rejeuK2000beSecret'), false);
$fichierConversation = k2000beJournal::racine() . '/conversations/' . $kitt->getId() . '.json';
check('ni dans la conversation enregistrée',
    strpos(file_get_contents($fichierConversation), 'rejeuK2000beSecret'), false);
$fichierJournal = k2000beJournal::racine() . '/journal/' . date('Y-m-d') . '.json';
check('ni dans le journal des demandes',
    strpos(file_get_contents($fichierJournal), 'rejeuK2000beSecret'), false);
check('le journal a bien été écrit', is_array(json_decode(file_get_contents($fichierJournal), true)), true);

check('aucun fichier temporaire ne traîne',
    count(glob(k2000beJournal::racine() . '/conversations/*.tmp')), 0);
check('la conversation est du JSON relisible',
    is_array(json_decode(file_get_contents($fichierConversation), true)), true);
check('le dossier est interdit au web',
    trim(file_get_contents(k2000beJournal::racine() . '/conversations/.htaccess')), 'Deny from all');

$lignes = k2000beJournal::historique($kitt->getId(), 50);
check('le journal se relit, plus récent d\'abord', $lignes[0]['date'] >= $lignes[1]['date'], true);
check('il note l\'auteur de la demande', $lignes[0]['utilisateur'], 'scenario');
check('un autre assistant n\'y apparaît pas', count(k2000beJournal::historique(999, 50)), 0);
$connus = array();
foreach ($lignes as $ligne) {
    $connus[$ligne['utilisateur']] = true;
}
check('la demande faite au nom d\'un utilisateur lui est attribuée', isset($connus['jerome']), true);

$vieux = k2000beJournal::racine() . '/journal/' . date('Y-m-d', time() - 40 * 86400) . '.json';
$recent = k2000beJournal::racine() . '/journal/' . date('Y-m-d', time() - 2 * 86400) . '.json';
file_put_contents($vieux, '[]');
file_put_contents($recent, '[]');
file_put_contents(k2000beJournal::racine() . '/conversations/777.json', '{"version":1}');
$supprimes = k2000beJournal::purger(30);
check('le journal de plus de trente jours est purgé', file_exists($vieux), false);
check('celui d\'avant-hier est gardé', file_exists($recent), true);
check('la conversation d\'un assistant disparu est ramassée',
    file_exists(k2000beJournal::racine() . '/conversations/777.json'), false);
check('celle de l\'assistant vivant est gardée', file_exists($fichierConversation), true);
check('deux fichiers supprimés', $supprimes, 2);
k2000be::cronDaily();
check('le cron quotidien passe sans rien casser', file_exists($fichierConversation), true);

/* ========================================================= INTERFACE */
section('Ce que reçoit l\'interface');

$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 101)))),
    reponseTexte('C\'est allumé.'),
));
$kitt->ask('Allume le salon.', array('utilisateur' => 'jerome'));
$vue = $kitt->toAjax();
check('deux entrées dans la frise', count($vue['tours']), 2);
check('la demande d\'abord', $vue['tours'][0]['role'], 'user');
check('son texte', $vue['tours'][0]['texte'], 'Allume le salon.');
check('la réponse ensuite', $vue['tours'][1]['role'], 'assistant');
check('avec son statut', $vue['tours'][1]['statut'], 'SUCCESS');
check('et le détail de ce qui a été fait', $vue['tours'][1]['etapes'][0]['titre'], 'Lumière salon — Allumer');
check('l\'étape porte son outil', $vue['tours'][1]['etapes'][0]['outil'], 'execute_command');
check('l\'interface sait que la clé est renseignée', $vue['configure'], true);
check('elle connaît le mode', $vue['mode'], 'actions');
check('et les compteurs d\'autorisations', $vue['compteurs']['autorisees'], 4);
check('aucune confirmation en attente', $vue['attente'], null);
check('la frise ne laisse rien fuir de la maison cachée', fuites($vue), array());

/* La frise est ce que l'utilisateur relit : elle ne doit pas grossir sans fin. */
for ($i = 0; $i < 30; $i++) {
    rejeu::scenario(array(reponseTexte('Réponse ' . $i . '.')));
    $kitt->ask('Question ' . $i . ' ?');
}
check('la frise est plafonnée', count($kitt->toAjax()['tours']), 50);
check('elle garde les tours les plus récents',
    $kitt->toAjax()['tours'][49]['texte'], 'Réponse 29.');

/* ================================================ CE QUE PUBLIE UN INCIDENT */
section('Ce que publient les commandes après un incident');

/*
 * Un incident est une demande comme une autre : il est consigné au journal et
 * inscrit dans la frise. Il ne publiait pourtant ni lastrun, ni actions, ni
 * pending : « Dernière demande » et « Actions exécutées » continuaient de
 * décrire un tour qui n'était plus le dernier, et un scénario branché sur
 * « Actions exécutées > 0 » croyait qu'on venait d'agir sur la maison.
 */
$kitt->reset();
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 101)))),
    reponseTexte('C\'est allumé.'),
));
$kitt->ask('Allume le salon.');
check('un tour ordinaire publie son compte d\'actions', $kitt->publies['actions'], 1);
$dateAvant = $kitt->publies['lastrun'];

rejeu::scenario(array());
tenter('demande vide', function () use ($kitt) {
    return $kitt->ask('   ');
});
check('un incident remet le compte d\'actions à zéro', $kitt->publies['actions'], 0);
check('et publie une date de dernière demande', $kitt->publies['lastrun'] !== '', true);
check('la réponse publiée est celle de l\'incident',
    strpos($kitt->publies['reply'], 'Demande vide') !== false, true);

/*
 * Publier « plus rien n'attend » par réflexe serait pire que de ne rien
 * publier : un jeton venu d'un autre onglet laisse la confirmation en cours
 * debout, et éteindre la tuile ferait disparaître une demande à laquelle
 * personne n'a encore répondu.
 */
rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$kitt->ask('Ouvre le portail.');
check('la tuile réclame un accord', $kitt->publies['pending'], 1);
$suite = tenter('jeton d\'un autre onglet', function () use ($kitt) {
    return $kitt->confirm('jetondunautreonglet', true);
});
check('un jeton erroné n\'éteint pas la confirmation en cours', $kitt->publies['pending'], 1);
check('et la question reste affichée', $kitt->publies['question'], 'Portail — Ouvrir');
check('l\'attente est toujours là', $kitt->attente()['demandes'][0]['command_id'], 120);

$kitt->reset();
check('la remise à zéro éteint la tuile', $kitt->publies['pending'], 0);
check('et efface la date de la dernière demande', $kitt->publies['lastrun'], '');

check('l\'assistant se retrouve par son identifiant', k2000be::assistant(20)->getName(), 'KITT');
check('ou tout seul quand il est le seul', k2000be::assistant()->getId(), 20);
check('un équipement ordinaire n\'est pas un assistant', k2000be::assistant(10), null);

/* ======================================================== SCÉNARIOS */
section('Depuis un scénario');

$kitt->reset();
$demander = $kitt->getCmd('action', 'ask');
rejeu::scenario(array(reponseTexte('Bonsoir.')));
check('un scénario obtient la réponse', $demander->execCmd(array('message' => 'Bonsoir')), 'Bonsoir.');
check('et la demande lui est attribuée',
    k2000beJournal::historique($kitt->getId(), 1)[0]['utilisateur'], 'scenario');
$leve = false;
try { $demander->execCmd(array('message' => '')); } catch (Throwable $e) { $leve = true; }
check('une demande vide échoue bruyamment', $leve, true);

rejeu::scenario(array(
    reponseOutils(array(array('outil' => 'execute_command', 'args' => array('command_id' => 120)))),
));
$kitt->ask('Ouvre le portail.');
cmd::vider();
rejeu::scenario(array(reponseTexte('Le portail s\'ouvre.')));
$kitt->getCmd('action', 'confirm')->execCmd(array());
$partis = array();
foreach (cmd::$envois as $envoi) {
    $partis[] = $envoi['id'];
}
check('la commande Confirmer ouvre enfin le portail', in_array(120, $partis, true), true);
check('l\'attente est levée', $kitt->attente(), null);

$leve = false;
try { $kitt->getCmd('action', 'cancel')->execCmd(array()); } catch (Throwable $e) { $leve = true; }
check('annuler sans rien en attente échoue bruyamment', $leve, true);

$kitt->getCmd('action', 'reset')->execCmd(array());
check('la remise à zéro efface la conversation',
    file_exists(k2000beJournal::racine() . '/conversations/' . $kitt->getId() . '.json'), false);
check('et vide la frise', count($kitt->toAjax()['tours']), 0);

/* ============================================ DE QUOI ÉPROUVER LA SUITE */

/*
 * Quatre équipements de plus, créés ICI et pas en tête de fichier : la maison
 * d'essai sert de référence à tout ce qui précède — quatre équipements
 * visibles, quatre commandes autorisées, trois pièces — et y ajouter de quoi
 * éprouver les plafonds déplacerait chacun de ces comptes sans rien apprendre.
 */
reglage('securite', 'actions');
reglage('lecture_defaut', 1);

$garage = creerEquipement(16, 'Garage', 'gce', 3);
/* Interdite, et d'un type générique qu'aucune autre commande de l'équipement
 * ne porte : c'est ce qui permet de voir si son type ressort malgré tout. */
creerCommande(160, $garage, 'Ouvrir la serrure', 'action', 'other', array('generique' => 'LOCK_OPEN'));
creerCommande(161, $garage, 'État porte', 'info', 'binary', array('generique' => 'DOOR_STATE', 'valeur' => '0'));
/* « Ne pas tenir compte » ET interdite : elle ne doit apparaître nulle part, et
 * son refus ne doit rien apprendre non plus. */
creerCommande(162, $garage, 'Réglage usine', 'action', 'other', array('generique' => 'DONT'));

$notifieur = creerEquipement(17, 'Notifieur', 'virtual', 1);
creerCommande(170, $notifieur, 'Notifier', 'action', 'message', array('politique' => 'allow'));
creerCommande(171, $notifieur, 'Teinte', 'action', 'color', array('politique' => 'allow'));
/* Sans bornes : c'est la seule façon d'atteindre le garde-fou de l'infini, un
 * slider borné refusant déjà 1e999 pour dépassement. */
creerCommande(172, $notifieur, 'Consigne libre', 'action', 'slider', array('politique' => 'allow'));
creerCommande(173, $notifieur, 'Consigne mal bornée', 'action', 'slider', array(
    'politique' => 'allow', 'min' => 100, 'max' => 0,
));

$capteurs = creerEquipement(18, 'Capteurs', 'zwavejs', 2);
creerCommande(180, $capteurs, 'Température', 'info', 'numeric', array(
    'generique' => 'TEMPERATURE', 'valeur' => '19.5', 'unite' => '°C',
));
$troisAns = date('Y-m-d H:i:s', time() - 3 * 365 * 86400);
creerCommande(181, $capteurs, 'Capteur muet', 'info', 'numeric', array(
    'generique' => 'TEMPERATURE', 'valeur' => '12', 'unite' => '°C', 'date' => $troisAns,
));
/* Le cas qui a fait écrire dateDeLecture() : la valeur n'a pas bougé depuis des
 * années, mais le capteur parle toutes les minutes. */
creerCommande(182, $capteurs, 'Thermostat stable', 'info', 'numeric', array(
    'generique' => 'TEMPERATURE', 'valeur' => '20', 'unite' => '°C',
    'date' => $troisAns, 'collecte' => date('Y-m-d H:i:s'),
));
$jamais = creerCommande(183, $capteurs, 'Jamais renseigné', 'info', 'string', array());
$jamais->value = null;
creerCommande(184, $capteurs, 'Chaîne vide', 'info', 'string', array('valeur' => ''));
creerCommande(185, $capteurs, 'Température extérieure', 'info', 'numeric', array(
    'generique' => 'TEMPERATURE', 'valeur' => '18', 'unite' => '°C', 'historise' => 1,
));
creerCommande(186, $capteurs, 'Porte de service', 'info', 'binary', array(
    'generique' => 'DOOR_STATE', 'valeur' => '0', 'historise' => 1,
));
/* Archivée en maximum : demander une moyenne à sa place mélangerait deux
 * lectures de la même courbe. */
creerCommande(187, $capteurs, 'Puissance crête', 'info', 'numeric', array(
    'generique' => 'POWER', 'valeur' => '22', 'unite' => 'W', 'historise' => 1,
))->setConfiguration('historizeMode', 'max');

/* Trente états et trente actions : au-delà des vingt que le détail accepte. */
$centrale = creerEquipement(19, 'Centrale', 'alarme', 3);
for ($i = 0; $i < 30; $i++) {
    creerCommande(1900 + $i, $centrale, 'Zone ' . $i, 'info', 'binary', array('valeur' => '0'));
    creerCommande(1930 + $i, $centrale, 'Activer zone ' . $i, 'action', 'other', array('politique' => 'allow'));
}

/* Mille quatre cent quarante relevés, un par minute sur vingt-quatre heures :
 * ce que remonte un capteur ordinaire, et ce que le cœur a déjà remplacé par
 * des moyennes horaires au-delà de son délai d'archivage. */
$parMinute = array();
$porte = array();
$crete = array();
for ($i = 1439; $i >= 0; $i--) {
    $instant = date('Y-m-d H:i:s', time() - $i * 60);
    $parMinute[] = array($instant, (string) (18 + ($i % 5)));
    /* Une seule minute ouverte dans toute la journée. */
    $porte[] = array($instant, ($i === 700) ? '1' : '0');
    /* Une pointe par heure, sur un fond plat : une moyenne horaire la noierait
     * autour de 18,4 et ne rendrait JAMAIS 40. C'est ce qui permet de dire, en
     * regardant les valeurs seules, laquelle des deux fonctions a servi. */
    $crete[] = array($instant, (date('i', strtotime($instant)) === '30') ? '40' : '18');
}
history::$points[185] = $parMinute;
history::$points[186] = $porte;
history::$points[187] = $crete;

k2000beSecurite::oublier();

/* La ligne d'un état ou d'une action, retrouvée par son identifiant : les
 * outils rendent des listes ordonnées par le cœur, et compter sur un rang
 * ferait échouer ces essais au premier ajout de commande. */
function ligneParId($_lignes, $_id) {
    foreach ((array) $_lignes as $ligne) {
        if (isset($ligne['id']) && (int) $ligne['id'] === (int) $_id) {
            return $ligne;
        }
    }
    return array();
}

/* ============================================================ HISTORIQUE */
section('L\'historique, tel que le cœur le rend');

/*
 * Le réglage du cœur reprend sa valeur ordinaire : deux heures. Une demande de
 * vingt-quatre heures porte donc très majoritairement sur de l'archivé, et le
 * plugin doit demander l'agrégation plutôt que mélanger cent vingt relevés
 * bruts avec vingt-deux moyennes.
 */
config::save('historyArchiveTime', 2, 'core');

$agrege = outil('get_history', array('command_id' => 185));
check('un capteur numérique historisé est rendu en moyennes horaires',
    $agrege['resultat']['sampling'], 'hourly_avg');
check('une vingtaine de points, et non mille quatre cent quarante',
    count($agrege['resultat']['points']) >= 20 && count($agrege['resultat']['points']) <= 30, true);
check('la note dit que les moyennes viennent de Jeedom',
    strpos($agrege['resultat']['note'], 'averages computed by Jeedom') !== false, true);
check('la période rendue couvre bien les vingt-quatre heures demandées',
    strtotime($agrege['resultat']['to']) - strtotime($agrege['resultat']['from']) >= 23 * 3600, true);
/* Le cœur ayant agrégé, il n'y a rien eu à décimer : le nombre de points
 * d'origine est celui des points rendus. Un écart signalerait que le plugin a
 * dégrossi lui-même une série qu'il présente pourtant comme des moyennes. */
check('le compte d\'origine est celui que le cœur a rendu',
    $agrege['resultat']['source_points'], count($agrege['resultat']['points']));
check('les valeurs restent dans l\'ordre de grandeur des relevés',
    (float) $agrege['resultat']['points'][0]['v'] >= 18
    && (float) $agrege['resultat']['points'][0]['v'] <= 22, true);

/* Au-delà du budget de points, l'heure laisse la place au jour : une semaine en
 * points horaires coûterait cent soixante-huit lignes à chaque tour de la
 * conversation. */
$semaine = outil('get_history', array('command_id' => 185, 'hours' => 168));
check('une semaine est rendue en moyennes journalières',
    $semaine['resultat']['sampling'], 'daily_avg');
check('et tient en une poignée de points',
    count($semaine['resultat']['points']) <= 8, true);

/* La fonction d'agrégation est celle que l'administrateur a choisie pour CETTE
 * commande : une commande archivée en maximum ne se relit pas en moyenne. */
$maximum = outil('get_history', array('command_id' => 187));
check('une commande archivée en maximum est relue en maximum',
    $maximum['resultat']['sampling'], 'hourly_max');
$valeursCrete = array();
foreach ($maximum['resultat']['points'] as $point) {
    $valeursCrete[] = (float) $point['v'];
}
check('la pointe horaire ressort', in_array(40.0, $valeursCrete, true), true);
check('et aucune valeur intermédiaire, comme en aurait produit une moyenne',
    array_values(array_diff(array_unique($valeursCrete), array(18.0, 40.0))), array());

/*
 * La porte : le cœur ne lisse pas un binaire, et décimer à pas fixe
 * supprimerait précisément ce qu'on demande à l'historique d'une porte.
 */
$transitions = outil('get_history', array('command_id' => 186));
check('un binaire est rendu en transitions', $transitions['resultat']['sampling'], 'transitions');
$valeursPorte = array();
foreach ($transitions['resultat']['points'] as $point) {
    $valeursPorte[] = (string) $point['v'];
}
check('l\'ouverture d\'une seule minute est conservée',
    in_array('1', $valeursPorte, true), true);
check('et mille quatre cent quarante relevés tiennent en quelques points',
    count($transitions['resultat']['points']) <= 10, true);
check('le cœur, lui, en avait bien rendu mille quatre cent quarante',
    $transitions['resultat']['source_points'], 1440);

/* ================================= AUTORISATIONS, MODES ET PLAFONDS */
section('Autorisations, modes et plafonds');

/*
 * La confirmation en simulation. Le bandeau que l'utilisateur lit avant de
 * cliquer reprend ce motif mot pour mot : sans la mention, on lui fait engager
 * sa responsabilité sur une ouverture de portail qui ne partira jamais.
 */
reglage('securite', 'simulation');
$attenteSimulee = k2000beSecurite::controler(120);
check('en simulation, la confirmation est toujours demandée', $attenteSimulee['decision'], 'confirm');
check('mais la question dit que rien ne partira',
    strpos($attenteSimulee['motif'], 'simulation') !== false
    && strpos($attenteSimulee['motif'], 'ne partira pas') !== false, true);
cmd::vider();
$demandeSimulee = outil('execute_command', array('command_id' => 120));
check('l\'outil pose la même question', $demandeSimulee['resultat']['status'], 'confirmation_required');
check('et le modèle reçoit l\'avertissement avec',
    strpos($demandeSimulee['resultat']['reason'], 'simulation') !== false, true);
check('rien n\'est parti', count(cmd::$envois), 0);

/*
 * Mode lecture seule. Le refus d'une action marquée « ne pas tenir compte » ne
 * doit rien apprendre de plus qu'un identifiant inventé : le même identifiant
 * rendrait sinon le nom ou non selon un réglage global qui n'a rien à voir
 * avec cette commande.
 */
reglage('securite', 'lecture');
$aIgnorer = outil('execute_command', array('command_id' => 162));
$imaginaire = outil('execute_command', array('command_id' => 8888));
check('les deux sont refusées', $aIgnorer['resultat']['status'], $imaginaire['resultat']['status']);
check('le motif rendu au modèle est le même',
    $sansId($aIgnorer['resultat']['reason']), $sansId($imaginaire['resultat']['reason']));
check('le titre rendu au modèle aussi',
    $sansId($aIgnorer['resultat']['command']), $sansId($imaginaire['resultat']['command']));
check('et rien n\'en nomme la commande',
    strpos(json_encode($aIgnorer['resultat']), 'Réglage usine') === false, true);

$compteursLecture = k2000beSecurite::compteurs();
check('en lecture seule, plus aucune commande n\'est exécutable',
    $compteursLecture['autorisees'], 0);
check('ni sous confirmation', $compteursLecture['confirmation'], 0);
check('les états, eux, restent lisibles', $compteursLecture['lisibles'] > 0, true);

$listeLecture = outil('list_equipments', array('limit' => 100));
check('la liste annonce le mode',
    strpos($listeLecture['resultat']['mode'], 'read_only') !== false, true);
$actionsAnnoncees = 0;
foreach ($listeLecture['resultat']['equipments'] as $equipement) {
    $actionsAnnoncees += $equipement['actions'];
}
check('et n\'annonce aucune action exécutable', $actionsAnnoncees, 0);

reglage('securite', 'actions');

/* Hors lecture seule, le refus d'une commande « à ignorer » reste indistinct :
 * c'est la politique qui le décide, pas le mode. */
$aIgnorer = outil('execute_command', array('command_id' => 162));
$imaginaire = outil('execute_command', array('command_id' => 8888));
check('hors lecture seule, le refus reste le même',
    $sansId($aIgnorer['resultat']['reason']), $sansId($imaginaire['resultat']['reason']));

/*
 * Le type générique d'une action interdite est une information sur la maison :
 * « types: LOCK_OPEN, actions: 0 » dit qu'il y a une serrure là, et laquelle.
 */
$vueGarage = outil('list_equipments', array('search' => 'garage'));
check('le garage est bien visible', count($vueGarage['resultat']['equipments']), 1);
check('mais le type de sa serrure interdite n\'y est pas',
    in_array('LOCK_OPEN', $vueGarage['resultat']['equipments'][0]['types'], true), false);
check('ni celui de la commande à ignorer',
    in_array('DONT', $vueGarage['resultat']['equipments'][0]['types'], true), false);
check('l\'état, lui, annonce son type',
    in_array('DOOR_STATE', $vueGarage['resultat']['equipments'][0]['types'], true), true);
check('et chercher ce type générique ne trouve rien',
    count(outil('list_equipments', array('generic_type' => 'LOCK_OPEN'))['resultat']['equipments']), 0);

$detailGarage = outil('get_equipment', array('equipment_ids' => array(16)));
check('le détail nomme la serrure interdite, pour que le modèle l\'explique',
    ligneParId($detailGarage['resultat']['equipments'][0]['actions'], 160)['policy'], 'forbidden');
check('mais retire celle qui est à ignorer',
    ligneParId($detailGarage['resultat']['equipments'][0]['actions'], 162), array());

/* Le plafond par équipement. Dix équipements de quatre-vingts commandes
 * faisaient près de vingt mille jetons dans un seul résultat d'outil. */
$grosse = outil('get_equipment', array('equipment_ids' => array(19)))['resultat']['equipments'][0];
check('vingt états au plus', count($grosse['states']), 20);
check('vingt actions au plus', count($grosse['actions']), 20);
check('et le compte de ce qui reste', $grosse['more_states'], 10);
check('des deux côtés', $grosse['more_actions'], 10);
check('la troncature est dite au modèle',
    strpos(outil('get_equipment', array('equipment_ids' => array(19)))['resultat']['note'], 'more_states') !== false, true);

$douze = outil('get_equipment', array('equipment_ids' => array(10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 8887, 8888)));
$traites = count($douze['resultat']['equipments'])
    + count(isset($douze['resultat']['not_available']) ? $douze['resultat']['not_available'] : array());
check('douze identifiants demandés, dix lus', $traites, 10);
check('et le modèle en est averti', isset($douze['resultat']['note']), true);

/* ================================ FRAÎCHEUR, VALEURS ET NOTIFICATIONS */
section('Fraîcheur, valeurs et notifications');

$detailCapteurs = outil('get_equipment', array('equipment_ids' => array(18)))['resultat']['equipments'][0];

$frais = ligneParId($detailCapteurs['states'], 180);
check('un état frais ne porte pas d\'horodatage inutile', isset($frais['updated']), false);
check('ni de mention de péremption', isset($frais['stale']), false);

$muet = ligneParId($detailCapteurs['states'], 181);
check('un capteur muet depuis trois ans est marqué périmé', $muet['stale'], true);
check('et daté, pour que le modèle n\'en parle pas au présent', $muet['updated'] !== '', true);

/* La date de LECTURE et celle du CHANGEMENT sont deux choses : un thermostat
 * stable depuis trois ans mais relevé à l'instant n'est pas une archive. */
$stable = ligneParId($detailCapteurs['states'], 182);
check('une valeur inchangée mais relue à l\'instant reste fraîche',
    isset($stable['stale']), false);

check('une valeur jamais renseignée se distingue d\'une chaîne vide',
    ligneParId($detailCapteurs['states'], 183)['value'], null);
check('la chaîne vide, elle, reste une chaîne vide',
    ligneParId($detailCapteurs['states'], 184)['value'], '');

$lus = outil('get_states', array('command_ids' => array(180)))['resultat']['states'][0];
check('la lecture d\'un état rend toujours son horodatage', $lus['updated'] !== '', true);
check('et toujours son type générique', $lus['generic'], 'TEMPERATURE');
check('elle nomme aussi l\'équipement', $lus['device'], 'Capteurs');

/*
 * La notification. Les deux clés s'appelaient « value » — l'une pour annoncer
 * le type attendu, l'autre pour porter la valeur — et un modèle qui recopiait
 * ce qu'il venait de lire envoyait value: "message".
 */
$detailNotifieur = outil('get_equipment', array('equipment_ids' => array(17)))['resultat']['equipments'][0];
$ligneNotifier = ligneParId($detailNotifieur['actions'], 170);
check('le type attendu est annoncé sous « expects »', $ligneNotifier['expects'], 'message');
check('et jamais sous le nom du paramètre qui porte la valeur',
    isset($ligneNotifier['value']), false);
check('un bouton simple, lui, n\'annonce rien',
    isset(ligneParId(outil('get_equipment', array('equipment_ids' => array(10)))['resultat']['equipments'][0]['actions'], 101)['expects']), false);

cmd::vider();
$notification = outil('execute_command', array(
    'command_id' => 170, 'value' => 'Le café est prêt.', 'title' => 'Cuisine',
));
check('la notification part', $notification['resultat']['status'], 'sent');
check('avec un titre et un corps distincts', cmd::$envois[0]['options'],
    array('title' => 'Cuisine', 'message' => 'Le café est prêt.'));

cmd::vider();
$curseur = outil('execute_command', array('command_id' => 103, 'value' => '60', 'title' => 'Bonjour'));
check('un titre envoyé à un curseur n\'empêche pas la commande de partir',
    $curseur['resultat']['status'], 'sent');
check('et il est simplement ignoré', cmd::$envois[0]['options'], array('slider' => 60));

/* ================================== L'ATTENTE AVANT VÉRIFICATION */
section('L\'attente avant vérification');

/*
 * Le plugin dit au modèle qu'une commande envoyée n'est pas une commande
 * aboutie, et de vérifier avec get_states. Mais rien n'attendait : entre
 * l'envoi et la lecture, il ne s'écoulait que l'aller-retour vers le modèle.
 * Un équipement qui remonte son état en cinq secondes faisait donc lire
 * l'ANCIENNE valeur, et le modèle annonçait un échec qui n'avait pas eu lieu.
 *
 * Deux équipements suffisent à tout éprouver : un qui répond au bout de deux
 * secondes — un volet en fin de course — et un qui ne répond jamais, pile
 * vide ou ampoule hors tension. Le bouchon ne les fait parler qu'au passage
 * par execCmd() : une attente qui se contenterait de relire la date mémorisée
 * par l'objet, comme le fait le cœur, ne les verrait jamais parler.
 */
reglage('securite', 'actions');
reglage('lecture_defaut', 1);

$lents = creerEquipement(21, 'Équipements lents', 'zwavejs', 1);
$voletLent = creerCommande(210, $lents, 'Position volet', 'info', 'numeric', array(
    'generique' => 'FLAP_STATE', 'valeur' => '100', 'unite' => '%',
));
$lampeMuette = creerCommande(211, $lents, 'État lampe', 'info', 'binary', array(
    'generique' => 'LIGHT_STATE', 'valeur' => '0',
));
k2000beSecurite::oublier();
k2000beOutils::nouvelleDemande();

/* Le cumul déjà dépensé, qu'il faut pouvoir poser sans attendre quinze
 * secondes pour de vrai. */
$cumulAttente = new ReflectionProperty('k2000beOutils', 'attenteCumulee');
$cumulAttente->setAccessible(true);

/* Ce que le plugin accepte d'attendre, avant même de dormir : c'est là que se
 * jouent les deux plafonds, et les éprouver ici évite dix secondes de rejeu. */
check('sans wait, on n\'attend pas',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(0)), 0.0);
check('une attente négative non plus',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(-5)), 0.0);
check('une attente déraisonnable est ramenée au plafond',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(999)),
    (float) k2000beOutils::ATTENTE_MAX);
check('une attente raisonnable est accordée telle quelle',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(4)), 4.0);

/* L'équipement qui répond au bout de deux secondes : l'attente doit s'arrêter
 * là, et pas au bout des huit demandées. */
$voletLent->parleA = microtime(true) + 2;
$voletLent->parleValeur = '0';
$lecturesAvant = $voletLent->lectures;
$depart = microtime(true);
$verification = outil('get_states', array('command_ids' => array(210), 'wait' => 8));
$duree = microtime(true) - $depart;

check('on n\'a pas répondu avant que l\'équipement ait parlé', $duree > 1.9, true);
check('mais on n\'a pas attendu les huit secondes demandées', $duree < 5.0, true);
check('l\'état est marqué rafraîchi',
    $verification['resultat']['states'][0]['refreshed'], true);
check('et porte la valeur remontée PENDANT l\'attente',
    $verification['resultat']['states'][0]['value'], '0');
check('le temps réellement attendu est rendu au modèle',
    $verification['resultat']['waited'] > 1.5 && $verification['resultat']['waited'] < 5.0, true);
/* La preuve que l'attente relit par le cœur, et pas une seule fois au début :
 * sans relecture, le bouchon n'aurait jamais parlé. */
check('la commande a été relue plusieurs fois pendant l\'attente',
    $voletLent->lectures > $lecturesAvant + 2, true);

/* L'équipement muet : l'attente va jusqu'au bout du délai, sans le dépasser,
 * et le modèle apprend que l'équipement n'a rien dit — ce qui n'est pas la
 * même chose qu'une valeur inchangée. */
k2000beOutils::nouvelleDemande();
$depart = microtime(true);
$silence = outil('get_states', array('command_ids' => array(211), 'wait' => 2));
$duree = microtime(true) - $depart;

check('un équipement muet fait attendre le délai demandé', $duree > 1.9, true);
check('et pas davantage', $duree < 3.0, true);
check('son état n\'est pas marqué rafraîchi',
    $silence['resultat']['states'][0]['refreshed'], false);
check('la valeur rendue reste celle d\'avant',
    $silence['resultat']['states'][0]['value'], '0');
check('et le modèle apprend qu\'il n\'a rien dit',
    strpos($silence['resultat']['note'], 'reported nothing') !== false, true);

/*
 * Le cas qui justifie de guetter la date de RELEVÉ et non la valeur : une
 * lampe rallumée alors qu'elle était déjà allumée ne change pas de valeur,
 * mais son plugin la relève quand même. Guetter la valeur ferait attendre en
 * vain à chaque commande sans effet visible.
 */
k2000beOutils::nouvelleDemande();
$lampeMuette->parleA = microtime(true) + 1;
$lampeMuette->parleValeur = '0';
$inchange = outil('get_states', array('command_ids' => array(211), 'wait' => 6));
check('un relevé sans changement de valeur compte comme un relevé',
    $inchange['resultat']['states'][0]['refreshed'], true);
check('et la valeur, elle, n\'a pas bougé',
    $inchange['resultat']['states'][0]['value'], '0');

/* Une lecture ordinaire ne coûte rien : ni attente, ni clé de plus dans le
 * résultat, qui se repaie à chaque tour de la conversation. */
$depart = microtime(true);
$ordinaire = outil('get_states', array('command_ids' => array(210, 211)));
check('une lecture sans wait ne dort pas', (microtime(true) - $depart) < 0.3, true);
check('et ne parle pas d\'attente', isset($ordinaire['resultat']['waited']), false);
check('ni de rafraîchissement',
    isset($ordinaire['resultat']['states'][0]['refreshed']), false);

/*
 * Le cumul. L'attente se fait verrou tenu et sur le budget de temps du tour :
 * dix vérifications de trois secondes le mangeraient en entier. On pose le
 * cumul à un demi-pouce du plafond plutôt que d'attendre quinze secondes.
 */
$cumulAttente->setValue(null, (float) k2000beOutils::ATTENTE_CUMUL_MAX - 0.5);
check('le reliquat du budget borne l\'attente accordée',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(5)), 0.5);
$depart = microtime(true);
$ecourtee = outil('get_states', array('command_ids' => array(211), 'wait' => 5));
check('et l\'attente s\'y tient', (microtime(true) - $depart) < 1.5, true);
check('le modèle est averti qu\'elle a été écourtée',
    strpos($ecourtee['resultat']['note'], 'cut to') !== false, true);

$cumulAttente->setValue(null, (float) k2000beOutils::ATTENTE_CUMUL_MAX);
$depart = microtime(true);
$epuise = outil('get_states', array('command_ids' => array(211), 'wait' => 5));
check('budget épuisé, on n\'attend plus du tout', $epuise['resultat']['waited'], 0.0);
check('et on ne dort pas non plus', (microtime(true) - $depart) < 0.3, true);
check('le modèle sait que le budget est dépensé',
    strpos($epuise['resultat']['note'], 'budget') !== false, true);

/* Un nouveau tour rend le budget : un scénario qui enchaîne deux demandes dans
 * le même processus PHP ne doit pas voir la seconde privée d'attente. */
k2000beOutils::nouvelleDemande();
check('un nouveau tour rend le budget d\'attente',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(5)), 5.0);

/*
 * On n'attend JAMAIS pour un état que l'assistant n'a pas le droit de lire :
 * la durée de la réponse serait alors elle-même une information sur la maison,
 * et le budget d'attente se dépenserait pour une lecture qui n'aura pas lieu.
 */
$depart = microtime(true);
$interdit = outil('get_states', array('command_ids' => array(131), 'wait' => 6));
check('aucune attente pour un état interdit', (microtime(true) - $depart) < 0.3, true);
check('rien n\'a été attendu', $interdit['resultat']['waited'], 0.0);
check('et l\'état reste indisponible, sans un mot de plus',
    $interdit['resultat']['not_available'], array(131));
check('le budget d\'attente n\'a pas été entamé',
    invoquerClasse('k2000beOutils', 'attenteAccordee', array(5)), 5.0);

/* Ce que le modèle lit : la consigne doit être dans la définition de l'outil,
 * dans la note d'une commande envoyée et dans l'invite système. Sans quoi il
 * ne demandera jamais d'attente. */
$definitions = k2000beOutils::definitions();
$definitionEtats = array();
foreach ($definitions as $definition) {
    if ($definition['function']['name'] === 'get_states') {
        $definitionEtats = $definition['function'];
    }
}
check('get_states annonce son paramètre wait',
    isset($definitionEtats['parameters']['properties']['wait']), true);
check('sans le rendre obligatoire',
    $definitionEtats['parameters']['required'], array('command_ids'));
check('et sa description dit pourquoi attendre',
    strpos($definitionEtats['description'], 'wait') !== false, true);

cmd::vider();
$envoi = outil('execute_command', array('command_id' => 101));
check('la note d\'une commande envoyée dit comment vérifier',
    strpos($envoi['resultat']['note'], 'wait: 3 to 5') !== false, true);
cmd::vider();

$invite = $kitt->systemPrompt();
check('l\'invite demande de vérifier avec une attente',
    strpos($invite, 'en lui passant wait') !== false, true);
check('et prévient que « refreshed »: false n\'est pas un échec',
    strpos($invite, 'refreshed') !== false, true);

/* ============================ PLUSIEURS ACTIONS, UN SEUL ALLER-RETOUR */
section('Plusieurs actions, un seul aller-retour');

/*
 * Rien ne bride les appels parallèles — aucun parallel_tool_calls: false ne
 * part dans la charge — et la boucle exécute déjà tous les appels d'un même
 * message. Il manquait de le dire au modèle, et un plafond assez haut pour que
 * la demande emblématique y tienne : « je vais me coucher » situe la maison,
 * liste deux pièces, détaille quelques équipements, éteint une demi-douzaine
 * de choses puis vérifie. Douze outils en deux allers-retours, là où dix
 * coupaient au milieu de l'extinction.
 */
check('l\'invite demande de grouper les actions indépendantes',
    strpos($invite, 'MÊME message') !== false, true);
$definitionAction = array();
foreach ($definitions as $definition) {
    if ($definition['function']['name'] === 'execute_command') {
        $definitionAction = $definition['function'];
    }
}
check('la définition d\'execute_command le dit aussi',
    strpos($definitionAction['description'], 'same message') !== false, true);

$coucher = array(
    /* Un premier message : la découverte, groupée. */
    reponseOutils(array(
        array('outil' => 'list_rooms'),
        array('outil' => 'list_equipments', 'args' => array('room' => 'salon')),
        array('outil' => 'list_equipments', 'args' => array('room' => 'chambre')),
        array('outil' => 'get_equipment', 'args' => array('equipment_ids' => array(10))),
        array('outil' => 'get_equipment', 'args' => array('equipment_ids' => array(11))),
    )),
    /* Un second : les six extinctions et la vérification, groupées elles aussi. */
    reponseOutils(array(
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'execute_command', 'args' => array('command_id' => 101)),
        array('outil' => 'get_states', 'args' => array('command_ids' => array(100))),
    )),
    reponseTexte('Bonne nuit. Tout est éteint.'),
);

/* Le champ vidé : c'est alors le .ini du plugin qui décide, comme en
 * production, et c'est cette valeur-là qu'on veut éprouver. */
reglage('max_tool_calls', '');
check('le plafond d\'outils par défaut vaut quinze',
    invoquer('reglageEntier', array('max_tool_calls', k2000be::MAX_OUTILS_DEFAUT, k2000be::MAX_OUTILS_MAX)), 15);

$kitt->reset();
rejeu::scenario($coucher);
$retour = $kitt->ask('Je vais me coucher.');
check('les douze outils passent', $retour['statut'], 'SUCCESS');
check('en trois allers-retours seulement', count(rejeu::$charges), 3);
check('les six extinctions sont parties', count(cmd::$envois), 6);
check('rien ne bride les appels parallèles dans la charge',
    isset(rejeu::charge(1)['parallel_tool_calls']), false);
check('la conversation reste recevable par l\'API',
    integriteOutils(rejeu::charge(2)['messages']), 0);

/* La même demande sous l'ancien plafond : elle coupait au milieu. */
reglage('max_tool_calls', 10);
$kitt->reset();
rejeu::scenario(array($coucher[0], $coucher[1], reponseTexte('Je m\'arrête là.')));
$retour = $kitt->ask('Je vais me coucher.');
check('sous l\'ancien plafond de dix, la même demande était coupée',
    $retour['statut'], 'LIMIT');
check('et deux extinctions sur six restaient à faire', count(cmd::$envois), 5);
reglage('max_tool_calls', 10);

/* ======================================================== L'AMBIGUÏTÉ */
section('L\'ambiguïté');

/*
 * L'invite disait de ne pas inventer ce qu'on ne voit pas, et rien de ce qu'il
 * faut faire quand on voit TROIS candidats. Le modèle choisissait : une chance
 * sur trois d'ouvrir le volet de la mauvaise chambre.
 */
check('l\'invite interdit de trancher au hasard',
    strpos($invite, 'ne tranche pas au hasard') !== false, true);
check('et demande de poser la question',
    strpos($invite, 'demande lequel') !== false, true);
check('la maison d\'essai sait produire l\'ambiguïté',
    count(outil('search', array('query' => 'état'))['resultat']['commands']) > 1, true);

/* ======================================================= VALEURS LIMITES */
section('Les valeurs limites');

/*
 * 1e999 passe is_numeric() et devient INF. Une commande sans bornes le laissait
 * aller jusqu'à la conversation enregistrée, où INF n'est pas encodable en
 * JSON : l'enregistrement échouait, et le tour cessait silencieusement d'être
 * consigné.
 */
$infini = k2000beSecurite::controler(172, '1e999');
check('l\'infini est refusé', $infini['decision'], 'deny');
check('et rien d\'inencodable ne ressort du verdict',
    json_encode(array('valeur' => $infini['valeur'])) !== false, true);
check('un grand nombre ordinaire, lui, passe',
    k2000beSecurite::controler(172, '1000000')['valeur'], 1000000);

$envers = k2000beSecurite::controler(173, 50);
check('des bornes à l\'envers refusent toute valeur', $envers['decision'], 'deny');
check('et le motif désigne la configuration, pas la valeur proposée',
    strpos($envers['motif'], 'configur') !== false, true);
check('en nommant la commande à corriger',
    strpos($envers['motif'], 'Consigne mal bornée') !== false, true);

check('une couleur à trois chiffres est acceptée et dépliée',
    k2000beSecurite::controler(171, '#abc')['valeur'], '#aabbcc');
check('même sans le croisillon, que le modèle oublie une fois sur deux',
    k2000beSecurite::controler(171, 'abc')['valeur'], '#aabbcc');
check('une couleur qui n\'en est pas une reste refusée',
    k2000beSecurite::controler(171, 'rouge')['decision'], 'deny');

/*
 * Ignorer une valeur est le bon choix — la composer avec la commande la ferait
 * partir à l'aveugle — mais le taire faisait rapporter au modèle « j'ai réglé
 * le mode sur 30 » à propos d'une commande partie nue.
 */
cmd::vider();
$nue = outil('execute_command', array('command_id' => 101, 'value' => '30'));
check('une valeur envoyée à un bouton ne l\'empêche pas de partir',
    $nue['resultat']['status'], 'sent');
check('elle n\'est pas transmise à la maison', cmd::$envois[0]['options'], null);
check('et le modèle est averti qu\'elle a été ignorée',
    strpos($nue['resultat']['note'], 'ignored') !== false, true);

/* ===================================================== LE JOURNAL MALMENÉ */
section('Un journal malmené');

$dossierJournal = k2000beJournal::racine() . '/journal';
$dossierConversations = k2000beJournal::racine() . '/conversations';
$fichierJour = $dossierJournal . '/' . date('Y-m-d') . '.json';

/*
 * Un fichier du jour abîmé à la main. Le réécrire à partir d'un tableau vide
 * effaçait d'un coup les quatre cents demandes de la journée — précisément ce
 * que le journal existe pour ne pas faire.
 */
@unlink($fichierJour . k2000beJournal::SUFFIXE_ILLISIBLE);
$abime = '{"ceci n\'est plus du JSON';
file_put_contents($fichierJour, $abime);
log::vider();
$consigne = k2000beJournal::consigner($kitt->getId(), array(
    'demande' => 'Une demande après la casse.', 'statut' => 'SUCCESS',
));
check('la demande du jour est tout de même consignée', $consigne, true);
$ecarte = $fichierJour . k2000beJournal::SUFFIXE_ILLISIBLE;
check('le fichier abîmé est mis de côté sous un autre nom', file_exists($ecarte), true);
check('et son contenu est toujours sur le disque', file_get_contents($ecarte), $abime);
$relu = json_decode(file_get_contents($fichierJour), true);
check('le nouveau fichier ne contient que la ligne du jour', count($relu), 1);
check('et c\'est bien celle qu\'on vient d\'écrire',
    $relu[0]['demande'], 'Une demande après la casse.');
check('l\'incident est journalisé', strpos(log::texte(), 'illisible') !== false, true);

/*
 * Une écriture qui ne passe pas ne doit jamais remplacer un journal valide :
 * c'est tout l'objet du fichier temporaire et du rename(). Le bouchon met un
 * disque plein à la place du temporaire, dont le nom est prévisible.
 */
$valide = file_get_contents($fichierJour);
log::vider();
check('le disque plein est bien en place', bloquerEcriture($fichierJour), true);
$ecrit = k2000beJournal::consigner($kitt->getId(), array(
    'demande' => 'Celle-ci ne doit pas s\'écrire.', 'statut' => 'SUCCESS',
));
debloquerEcriture($fichierJour);
check('l\'écriture impossible est signalée', $ecrit, false);
check('le journal valide est intact, au caractère près',
    file_get_contents($fichierJour), $valide);
check('rien n\'est resté en travers', count(glob($dossierJournal . '/*.tmp')), 0);
check('et l\'incident est journalisé', strpos(log::texte(), 'journal') !== false, true);

/*
 * Le budget de lecture. Filtrer par assistant oblige à ouvrir les fichiers un à
 * un : sur un assistant peu bavard, cela revenait à décoder tout le dossier
 * pour afficher un onglet. Onze journées pleines, une demande portant sur un
 * autre assistant — la recherche doit s'arrêter et le DIRE, faute de quoi on
 * conclurait à l'absence d'une demande pourtant bien enregistrée, plus loin.
 */
foreach (glob($dossierJournal . '/*') as $reste) {
    if (!is_dir($reste) && basename($reste) !== '.htaccess') {
        @unlink($reste);
    }
}
for ($jour = 0; $jour < 11; $jour++) {
    $lignesJour = array();
    for ($ligne = 0; $ligne < 500; $ligne++) {
        $lignesJour[] = array(
            'date' => time() - $jour * 86400, 'eq' => 2000, 'assistant' => 'Autre',
            'utilisateur' => 'jerome', 'demande' => 'demande ' . $ligne, 'reponse' => 'réponse',
            'statut' => 'SUCCESS', 'modele' => 'gpt-4o-mini',
            'jetons' => array('invite' => 1, 'reponse' => 1, 'total' => 2),
            'duree' => 0.1, 'etapes' => array(),
        );
    }
    file_put_contents($dossierJournal . '/' . date('Y-m-d', time() - $jour * 86400) . '.json',
        json_encode($lignesJour));
}

$ecourte = k2000beJournal::historique(2001, 50);
/* Onze fichiers, un budget de dix : le onzième n'est jamais ouvert, et la seule
 * ligne rendue est celle qui le dit. */
check('une recherche sans résultat ne parcourt pas tout le dossier', count($ecourte), 1);
$service = $ecourte[count($ecourte) - 1];
check('la réponse se termine par une ligne de service', $service['statut'], 'TRONQUE');
/* La date attendue se relit sur le nom du dixième fichier, et non d'une
 * soustraction de secondes : un changement d'heure décale l'une et pas
 * l'autre, et l'essai tomberait deux fois l'an sans rien avoir à dire. */
$dixiemeJour = date('Y-m-d', time() - 9 * 86400);
check('qui dit jusqu\'où le journal a été parcouru',
    strpos($service['reponse'], date('d/m/Y', strtotime($dixiemeJour . ' 00:00:00'))) !== false, true);
check('et rappelle que le reste est toujours enregistré',
    strpos($service['reponse'], 'toujours enregistrées') !== false, true);
$complet = k2000beJournal::historique(2000, 50);
check('l\'assistant qui a parlé, lui, obtient ses lignes', count($complet), 50);
check('et aucune ligne de service', $complet[49]['statut'], 'SUCCESS');

/* ============================ LES CONSIGNES D'UN ASSISTANT */
section('Les consignes propres à un assistant');

/*
 * Le plugin affirmait qu'un équipement est un assistant et que plusieurs
 * peuvent coexister, mais les consignes vivaient dans la configuration du
 * plugin : spécialiser l'un revenait à spécialiser tous les autres.
 */
reglage('prompt_extra', 'Ne jamais parler du chat.');
$inviteNue = $kitt->systemPrompt();
check('sans consignes propres, rien n\'est ajouté',
    strpos($inviteNue, 'propres à cet assistant') !== false, false);
check('les consignes générales, elles, sont bien là',
    strpos($inviteNue, 'Ne jamais parler du chat.') !== false, true);

$kitt->setConfiguration('consignes', "Tu fais des levées de doute.\nCommence par les règles croisées.");
$invite = $kitt->systemPrompt();
check('les consignes de l\'assistant partent dans l\'invite',
    strpos($invite, 'Tu fais des levées de doute.') !== false, true);
/* Un champ multiligne ferait passer ses propres lignes pour des consignes
 * séparées, orphelines de leur en-tête : il est aplati comme la fiche. */
check('et tiennent sur une seule ligne',
    strpos($invite, "levées de doute.\nCommence") !== false, false);
check('elles passent APRÈS les consignes générales, pour pouvoir les nuancer',
    strpos($invite, 'Ne jamais parler du chat.') < strpos($invite, 'Tu fais des levées de doute.'), true);

/* Bornées, comme tout ce qui repart chez OpenAI à chaque demande. */
$kitt->setConfiguration('consignes', str_repeat('consigne ', 400));
$longue = $kitt->systemPrompt();
$debut = strpos($longue, 'Consignes propres à cet assistant');
check('une consigne trop longue est coupée',
    mb_strlen(mb_substr($longue, $debut)) <= k2000be::CONSIGNES_MAX + 120, true);
check('et la coupe se voit', mb_substr(trim(mb_substr($longue, $debut)), -1), '…');

/* Un second assistant ne voit pas celles du premier : c'est tout l'objet. */
$sobre = new k2000be();
$sobre->setName('Sobre');
$sobre->setEqType_name('k2000be');
$sobre->id = 449;
eqLogic::$tous[449] = $sobre;
check('un autre assistant n\'hérite de rien',
    strpos($sobre->systemPrompt(), 'consigne consigne') !== false, false);
check('mais garde les consignes générales',
    strpos($sobre->systemPrompt(), 'Ne jamais parler du chat.') !== false, true);
unset(eqLogic::$tous[449]);

$kitt->setConfiguration('consignes', '');
reglage('prompt_extra', '');

/* ======================= TROUVER UN ÉQUIPEMENT PAR CE QU'IL EST */
section('Chercher par type générique');

/*
 * Huit caméras nommées d'après leur orientation — EST, NORD, SUD —, toutes
 * dans la même pièce. « caméra » ne rendait alors AUCUN équipement, et une
 * seule commande : « Caméra masquée », la seule dont le nom porte le mot, et
 * justement celle qu'aucun événement n'a jamais renseignée. L'assistant a
 * répondu là-dessus que toutes les caméras étaient masquées, alors que les
 * détections de mouvement, elles, étaient bien là — sous le nom « Mouvement ».
 */
$estCam = creerEquipement(33, 'EST', 'nvr', 5);
creerCommande(330, $estCam, 'Dernière image', 'info', 'string', array('generique' => 'CAMERA_URL'));
creerCommande(331, $estCam, 'Mouvement', 'info', 'binary', array(
    'generique' => 'PRESENCE', 'valeur' => '0', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 900),
));
/* Jamais renseignée : ni date de relevé, ni date de changement. C'est
 * exactement l'état d'une commande qu'aucun événement n'a jamais levée — et
 * c'est ce que le stub produit avec une date vide. */
creerCommande(332, $estCam, 'Caméra masquée', 'info', 'binary', array(
    'generique' => 'SABOTAGE', 'date' => '', 'collecte' => '',
));
k2000beSecurite::oublier();

$camera = outil('search', array('query' => 'camera'));
$nomsTrouves = array();
foreach ($camera['resultat']['devices'] as $equipement) {
    $nomsTrouves[] = $equipement['name'];
}
check('une caméra nommée « EST » se trouve en cherchant « camera »',
    in_array('EST', $nomsTrouves, true), true);
check('et elle dit ce qu\'elle est', in_array('CAMERA_URL', $camera['resultat']['devices'][0]['types'], true), true);
/* Accents et casse, comme partout ailleurs dans cette classe. */
check('« caméra » avec l\'accent trouve la même chose',
    count(outil('search', array('query' => 'Caméra'))['resultat']['devices']),
    count($camera['resultat']['devices']));

/*
 * La garde qui compte. Les types génériques sortent de resumeCommandes(), qui
 * ne retient que les commandes autorisées : la serrure du garage est interdite,
 * son type ne doit donc rattacher son équipement à aucune recherche. Sans quoi
 * chercher « lock » apprendrait qu'une serrure existe là, et où — ce que la
 * règle en tête du fichier interdit.
 */
$serrure = outil('search', array('query' => 'lock'));
$nomsSerrure = array();
foreach ($serrure['resultat']['devices'] as $equipement) {
    $nomsSerrure[] = $equipement['name'];
}
check('une commande interdite ne rend pas son équipement trouvable par son type',
    in_array('Garage', $nomsSerrure, true), false);

/*
 * Et le fond de l'affaire : un état jamais relevé ne doit pas être rendu comme
 * une valeur vide. C'est en lisant « value: "" » sur « Caméra masquée » que le
 * modèle s'est rabattu sur le NOM de la commande pour répondre.
 */
$vus = outil('get_equipment', array('equipment_ids' => array(33)));
$parNom = array();
foreach ($vus['resultat']['equipments'][0]['states'] as $etat) {
    $parNom[$etat['name']] = $etat;
}
check('un état jamais renseigné est signalé comme tel',
    isset($parNom['Caméra masquée']['never_read']), true);
check('un état qui porte une valeur ne l\'est pas',
    isset($parNom['Mouvement']['never_read']), false);
check('et la réponse explique ce que cela veut dire',
    strpos($vus['resultat']['note'], 'NEVER guess the state of the home from the NAME') !== false, true);

$lecture = outil('get_states', array('command_ids' => array(332)));
check('get_states le dit aussi', isset($lecture['resultat']['states'][0]['never_read']), true);
/* Une date vide se lisait comme un champ qu'on aurait dû remplir. */
check('et ne rend pas une date vide',
    isset($lecture['resultat']['states'][0]['updated']), false);

foreach (array(330, 331, 332) as $id) {
    unset(cmd::$toutes[$id]);
}
unset(eqLogic::$tous[33]);
k2000beSecurite::oublier();

/* ============================== CE QUI S'EST PASSÉ RÉCEMMENT */
section('Ce qui s\'est passé récemment');

/*
 * Une caméra, telle qu'un plugin de vidéosurveillance la pose : une vingtaine
 * d'états binaires, dont trois seulement sont historisés, et une valeur qui ne
 * redescend pas. C'est ce dernier point qui a fait écrire l'outil : un
 * franchissement de ligne ponctuel lève la commande à 1, aucun événement de fin
 * ne la remet à 0, et trois semaines plus tard elle vaut toujours 1.
 */
creerPiece(5, 'Extérieur');
$camera = creerEquipement(30, 'NORD', 'nvr', 5);
creerCommande(300, $camera, 'Humain détecté', 'info', 'binary', array(
    'generique' => 'PRESENCE', 'valeur' => '0', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 2 * 3600),
));
creerCommande(301, $camera, 'Mouvement', 'info', 'binary', array(
    'generique' => 'PRESENCE', 'valeur' => '0', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 30 * 3600),
));
creerCommande(302, $camera, 'Ligne franchie', 'info', 'binary', array(
    'valeur' => '1', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 20 * 86400),
));
/* Encore allumée : la détection est en cours, pas terminée. */
creerCommande(306, $camera, 'Présence en cours', 'info', 'binary', array(
    'valeur' => '1', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 120),
));
/* Non historisée : l'administrateur n'a pas jugé son évolution digne d'être
 * conservée, l'outil ne la remonte donc pas. C'est le seul réglage qui décide,
 * et il se corrige dans Jeedom. */
creerCommande(303, $camera, 'Visage détecté', 'info', 'binary', array(
    'valeur' => '0', 'date' => date('Y-m-d H:i:s', time() - 600),
));
/* Historisée mais pas binaire : une mesure n'est pas un événement. */
creerCommande(304, $camera, 'Température boîtier', 'info', 'numeric', array(
    'generique' => 'TEMPERATURE', 'valeur' => '31', 'unite' => '°C', 'historise' => 1,
));
/* Historisée, binaire, récente — et masquée à la lecture. */
creerCommande(305, $camera, 'Rôdeur', 'info', 'binary', array(
    'valeur' => '1', 'historise' => 1, 'lecture' => 'deny',
    'date' => date('Y-m-d H:i:s', time() - 300),
));

/* Un équipement entièrement masqué : ce qui s'y passe ne doit apparaître
 * nulle part, pas même sous forme de date. */
$secrete = creerEquipement(31, 'Caméra intérieure', 'nvr', 5, array('masque' => 1));
creerCommande(310, $secrete, 'Mouvement', 'info', 'binary', array(
    'valeur' => '1', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 60),
));

k2000beSecurite::oublier();

/* ---- La date jointe à l'état lui-même ---- */
$detail = outil('get_equipment', array('equipment_ids' => array(30)));
$etats = array();
foreach ($detail['resultat']['equipments'][0]['states'] as $etat) {
    $etats[$etat['name']] = $etat;
}
check('un état événementiel porte la date de son dernier changement',
    isset($etats['Humain détecté']['since']), true);
check('une mesure n\'en porte pas', isset($etats['Température boîtier']['since']), false);
check('un binaire non historisé non plus', isset($etats['Visage détecté']['since']), false);
check('l\'état masqué en lecture n\'est pas rendu du tout',
    isset($etats['Rôdeur']), false);
/*
 * Le cas qui produisait une fausse affirmation : la valeur dit 1, et sans date
 * le modèle en parle au présent. Elle est vieille de vingt jours, donc périmée
 * au sens de la fraîcheur : les deux dates se confondent et une seule sort.
 */
check('la ligne franchie il y a vingt jours est datée',
    isset($etats['Ligne franchie']['updated']), true);
check('et signalée comme périmée', $etats['Ligne franchie']['stale'], true);
check('sans répéter deux fois la même date',
    isset($etats['Ligne franchie']['since']), false);

/* ---- L'outil ---- */
$recents = outil('get_events', array());
$noms = array();
foreach ($recents['resultat']['events'] as $evenement) {
    $noms[] = $evenement['equipment'] . ' / ' . $evenement['name'];
}
$encours = null;
foreach ($recents['resultat']['events'] as $evenement) {
    if ($evenement['command_id'] === 306) {
        $encours = $evenement;
    }
}
check('une détection encore allumée se lit « commencée »', $encours['what'], 'started');
check('la fenêtre par défaut est de douze heures',
    $recents['resultat']['window_hours'], k2000beOutils::EVENEMENTS_HEURES);
check('la détection de cette nuit ressort', in_array('NORD / Humain détecté', $noms, true), true);
check('celle d\'il y a trente heures est hors fenêtre',
    in_array('NORD / Mouvement', $noms, true), false);
check('l\'état masqué en lecture ne ressort jamais',
    in_array('NORD / Rôdeur', $noms, true), false);
check('ni l\'équipement masqué',
    in_array('Caméra intérieure / Mouvement', $noms, true), false);
/* La maison d'essai a ses propres états événementiels — une porte de service
 * historisée, entre autres : l'événement de la caméra se cherche par son nom,
 * il n'est pas forcément le plus récent de la maison. */
$detection = null;
foreach ($recents['resultat']['events'] as $evenement) {
    if ($evenement['command_id'] === 300) {
        $detection = $evenement;
    }
}
check('la pièce accompagne l\'événement', $detection['room'], 'Extérieur');
/*
 * Le piège qui a produit une fausse réponse en production : la détection
 * humaine s'était allumée puis éteinte, la ligne rendait la valeur d'APRÈS —
 * zéro — et le modèle a répondu que personne n'était passé. Une détection
 * terminée reste une détection qui a eu lieu.
 */
check('une détection retombée se lit « terminée », et non « zéro »',
    $detection['what'], 'ended');
check('et la valeur brute ne traîne plus dans la ligne',
    isset($detection['value']), false);
check('la note dit comment lire les deux cas',
    strpos($recents['resultat']['note'], 'never read it as "nothing happened"') !== false, true);
check('et l\'équipement qui l\'a vu', $detection['equipment'], 'NORD');

/* La fenêtre s'élargit, et l'ordre est celui du plus récent au plus ancien. */
$large = outil('get_events', array('hours' => 48));
$dates = array();
foreach ($large['resultat']['events'] as $evenement) {
    $dates[] = $evenement['at'];
}
$triees = $dates;
rsort($triees);
check('à quarante-huit heures, le mouvement ressort aussi', count($dates) >= 2, true);
check('et les événements vont du plus récent au plus ancien', $dates, $triees);

/* Les bornes, des deux côtés. */
$borne = outil('get_events', array('hours' => 10000, 'room' => 'Extérieur'));
check('une fenêtre démesurée est ramenée à la semaine',
    $borne['resultat']['window_hours'], k2000beOutils::EVENEMENTS_HEURES_MAX);
/*
 * Trois événements dehors, et pas quatre : la ligne franchie date de vingt
 * jours. La valeur vaut pourtant toujours 1, faute d'événement de fin — c'est
 * exactement ce que l'outil ne doit pas faire passer pour de l'actualité.
 */
check('et la ligne franchie d\'il y a vingt jours reste dehors',
    count($borne['resultat']['events']), 3);
$nulle = outil('get_events', array('hours' => 0));
check('une fenêtre nulle reprend le défaut',
    $nulle['resultat']['window_hours'], k2000beOutils::EVENEMENTS_HEURES);

/* Le filtre par pièce, sans accent et partiel, comme partout ailleurs. */
$dehors = outil('get_events', array('room' => 'exterieur'));
check('le filtre de pièce est insensible aux accents',
    count($dehors['resultat']['events']), 2);
$cave = outil('get_events', array('room' => 'cave'));
check('une pièce sans rien rend une liste vide', $cave['resultat']['events'], array());
/*
 * « Rien ne s'est passé » et « rien n'est surveillé » se ressemblent trop :
 * sans cette phrase, le modèle conclut au calme sur une maison qui n'a aucun
 * état historisé.
 */
check('et dit que le silence ne prouve rien',
    strpos($cave['resultat']['note'], 'does not prove') !== false, true);

/* Le plafond de lignes. Une nuit de vent devant une caméra en produit des
 * centaines : ce sont les plus récentes qui répondent, et le compte dit le
 * reste. */
$bavarde = creerEquipement(32, 'Caméra bavarde', 'nvr', 5);
for ($i = 0; $i < k2000beOutils::EVENEMENTS_MAX + 5; $i++) {
    creerCommande(3200 + $i, $bavarde, 'Détection ' . $i, 'info', 'binary', array(
        'valeur' => '1', 'historise' => 1,
        'date' => date('Y-m-d H:i:s', time() - 60 - $i),
    ));
}
k2000beSecurite::oublier();
$trop = outil('get_events', array());
check('la liste est plafonnée',
    count($trop['resultat']['events']), k2000beOutils::EVENEMENTS_MAX);
check('et dit combien elle a laissé de côté', $trop['resultat']['more'] > 0, true);
check('la note explique la troncature',
    strpos($trop['resultat']['note'], 'most recent') !== false, true);

/* Une réponse vide reste une réponse : l'outil n'a aucun cas d'erreur, et une
 * maison sans rien d'historisé le lit comme un silence, pas comme une panne. */
check('l\'outil ne rend jamais une erreur', $cave['etape']['statut'], 'ok');

/* La maison d'essai retrouve sa forme : les sections suivantes comptent les
 * équipements et les commandes. */
foreach (array(30, 31, 32) as $id) {
    unset(eqLogic::$tous[$id]);
}
/* Les commandes de l'assistant reçoivent leur identifiant du stub, dans une
 * plage qu'on ne connaît pas : on ne retire que celles qu'on a posées soi-même,
 * jamais un intervalle. */
foreach (array(300, 301, 302, 303, 304, 305, 306, 310) as $id) {
    unset(cmd::$toutes[$id]);
}
for ($i = 0; $i < k2000beOutils::EVENEMENTS_MAX + 5; $i++) {
    unset(cmd::$toutes[3200 + $i]);
}
unset(jeeObject::$tous[5]);
k2000beSecurite::oublier();

/* ================================ LE VERDICT, CALCULÉ PAR LE PLUGIN */
section('Le verdict est calculé, pas demandé au modèle');

/*
 * Une règle de détection croisée — elle ne tire que lorsque deux détections
 * indépendantes se recoupent — et une caméra qui voit du mouvement toute la
 * journée. C'est exactement la maison où une consigne ne suffit pas : le modèle
 * additionne les mouvements isolés et finit par annoncer une intrusion.
 */
creerPiece(6, 'Jardin');
$regle = creerEquipement(34, 'Detection NORD', 'nvr', 6);
$declenchee = creerCommande(340, $regle, 'Déclenchée', 'info', 'binary', array(
    'generique' => 'ALARM_STATE', 'valeur' => '0', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 3 * 3600),
));
$vigie = creerEquipement(35, 'NORD', 'nvr', 6);
creerCommande(350, $vigie, 'Mouvement', 'info', 'binary', array(
    'generique' => 'PRESENCE', 'valeur' => '1', 'historise' => 1,
    'date' => date('Y-m-d H:i:s', time() - 300),
));
k2000beSecurite::oublier();

/* Sans déclaration, l'outil n'existe pas : il ne coûte pas un jeton aux
 * assistants qui n'en ont que faire. */
$kitt->setConfiguration('verdict_types', '');
check('sans état décisif déclaré, le catalogue reste à huit outils',
    count(k2000beOutils::definitions($kitt)), 8);
check('et l\'outil refuse poliment s\'il est appelé quand même',
    k2000beOutils::executer('check_alert', array(), $kitt)['resultat']['status'], 'error');

$kitt->setConfiguration('verdict_types', 'alarm_state');
check('la déclaration est normalisée en majuscules', $kitt->typesDecisifs(), array('ALARM_STATE'));
check('déclaré, l\'outil apparaît', count(k2000beOutils::definitions($kitt)), 9);

/* La règle a tiré il y a trois heures : le verdict est un fait, pas une
 * appréciation. */
$avere = k2000beOutils::executer('check_alert', array(), $kitt);
check('une règle déclenchée donne CONFIRMED', $avere['resultat']['verdict'], 'CONFIRMED');
check('et elle est nommée', $avere['resultat']['deciding'][0]['equipment'], 'Detection NORD');
check('une détection retombée compte quand même', $avere['resultat']['deciding'][0]['what'], 'ended');
/* La maison d'essai a ses propres états événementiels — une porte de service
 * historisée : le mouvement de la caméra se cherche par son identifiant. */
$contexteVu = null;
foreach ($avere['resultat']['context'] as $ligne) {
    if ($ligne['command_id'] === 350) {
        $contexteVu = $ligne;
    }
}
check('le mouvement isolé n\'est que du contexte', $contexteVu['name'], 'Mouvement');
check('et il n\'a pas décidé du verdict',
    count($avere['resultat']['deciding']), 1);
check('la note interdit de recalculer le verdict',
    strpos($avere['resultat']['note'], 'you may not change it') !== false, true);
check('et dit que le contexte ne décide jamais',
    strpos($avere['resultat']['note'], 'NEVER make a verdict') !== false, true);

/*
 * Le cas qui motive tout le reste : la règle n'a pas tiré, mais la caméra a vu
 * du mouvement il y a cinq minutes. Le verdict reste RIEN — c'est le plugin qui
 * le dit, et le modèle n'a plus la latitude d'en décider autrement.
 */
$declenchee->valueDate = date('Y-m-d H:i:s', time() - 30 * 3600);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
$calme = k2000beOutils::executer('check_alert', array(), $kitt);
check('hors fenêtre, le verdict est NOTHING', $calme['resultat']['verdict'], 'NOTHING');
$toujoursLa = false;
foreach ($calme['resultat']['context'] as $ligne) {
    if ($ligne['command_id'] === 350) {
        $toujoursLa = true;
    }
}
check('alors même que le mouvement, lui, est dans la fenêtre', $toujoursLa, true);
check('et qu\'aucun état décisif n\'y est', $calme['resultat']['deciding'], array());
check('une règle qui n\'a pas tiré reste une règle surveillée',
    $calme['resultat']['watched'], 1);

/* La fenêtre s'élargit : la même règle redevient décisive. */
check('à quarante-huit heures, elle ressort',
    k2000beOutils::executer('check_alert', array('hours' => 48), $kitt)['resultat']['verdict'],
    'CONFIRMED');

/*
 * UNKNOWN n'est pas RIEN, et la distinction est le cœur du sujet : un type
 * déclaré de travers, ou une autorisation retirée, rendraient sinon « rien à
 * signaler » sur une maison que personne ne surveille.
 */
$kitt->setConfiguration('verdict_types', 'TYPE_INEXISTANT');
$aveugle = k2000beOutils::executer('check_alert', array(), $kitt);
check('sans rien à surveiller, le verdict est UNKNOWN', $aveugle['resultat']['verdict'], 'UNKNOWN');
check('et il ne compte aucun état décisif', $aveugle['resultat']['watched'], 0);
check('la note dit que ce n\'est pas le calme',
    strpos($aveugle['resultat']['note'], 'does not mean quiet') !== false, true);

/* Un état décisif que l'assistant n'a pas le droit de lire ne décide de rien —
 * et ne se laisse pas davantage deviner par un verdict qui changerait. */
$kitt->setConfiguration('verdict_types', 'ALARM_STATE');
$declenchee->valueDate = date('Y-m-d H:i:s', time() - 3600);
$declenchee->collectDate = $declenchee->valueDate;
$declenchee->setConfiguration('k2000beRead', 'deny');
k2000beSecurite::oublier();
$masque = k2000beOutils::executer('check_alert', array(), $kitt);
check('une règle masquée ne décide de rien', $masque['resultat']['verdict'], 'UNKNOWN');
check('et n\'est pas même comptée', $masque['resultat']['watched'], 0);

/* ------------------------- L'ALERTE AUTOMATIQUE ------------------------- */

/*
 * La même déclaration sert au verdict et au réveil : l'assistant se réveille
 * sur ce qui décide, et sur rien d'autre.
 */
$kitt->setConfiguration('verdict_types', 'ALARM_STATE');
$declenchee->setConfiguration('k2000beRead', 'allow');
$declenchee->valueDate = date('Y-m-d H:i:s', time() - 600);
$declenchee->collectDate = $declenchee->valueDate;
$kitt->setConfiguration('alerte_auto', 0);
$kitt->setConfiguration('alerte_vu', 0);
$kitt->setConfiguration('alerte_derniere', 0);
k2000beSecurite::oublier();

rejeu::scenario(array());
check('sans la case cochée, rien ne se déclenche', $kitt->surveiller(), false);

/*
 * Le premier passage pose le repère et se tait. Sans cela, cocher la case un
 * matin ferait raconter la détection de l'avant-veille comme si elle venait
 * d'arriver.
 */
$kitt->setConfiguration('alerte_auto', 1);
check('le premier passage n\'alerte pas', $kitt->surveiller(), false);
check('mais il pose le repère', (int) $kitt->getConfiguration('alerte_vu', 0) > 0, true);
check('et rien n\'est parti chez OpenAI', count(rejeu::$charges), 0);

check('un passage sans rien de neuf ne fait rien', $kitt->surveiller(), false);

/* La règle bascule : l'assistant est réveillé, et la demande porte le
 * déclenchement. */
$declenchee->valueDate = date('Y-m-d H:i:s', time() - 5);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array(reponseTexte('CONFIRME. La règle NORD a tiré il y a une minute.')));
check('un basculement neuf réveille l\'assistant', $kitt->surveiller(), true);
check('et le modèle a bien été appelé', count(rejeu::$charges), 1);
$demande = rejeu::charge(0)['messages'];
check('la demande nomme l\'équipement qui a basculé',
    strpos($demande[count($demande) - 1]['content'], 'Detection NORD') !== false, true);
check('et demande la levée de doute',
    strpos($demande[count($demande) - 1]['content'], 'levée de doute') !== false, true);
check('le journal attribue la demande à l\'alerte, et non à un humain',
    k2000beJournal::historique($kitt->getId(), 1)[0]['utilisateur'], 'alerte');

/*
 * Le repos. Une seule règle a tiré trente-huit fois dans la journée sur
 * l'installation qui a servi à écrire ceci : sans repos, autant de demandes
 * facturées, dont la plupart refusées par le verrou.
 */
$declenchee->valueDate = date('Y-m-d H:i:s');
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array());
check('un second basculement pendant le repos ne réveille pas', $kitt->surveiller(), false);
check('et ne coûte rien', count(rejeu::$charges), 0);
/* Le repère a tout de même avancé : l'événement est couvert par l'alerte
 * précédente, il ne doit pas ressortir à la fin du repos. */
check('mais le repère a avancé',
    (int) $kitt->getConfiguration('alerte_vu', 0) >= strtotime($declenchee->valueDate), true);

/* Repos échu : le basculement suivant réveille de nouveau. */
$kitt->setConfiguration('alerte_derniere', time() - 3600);
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 1);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array(reponseTexte('RIEN.')));
check('le repos échu, l\'alerte repart', $kitt->surveiller(), true);

/* ---- Le garde-fou d'armement, fermé par défaut ---- */

/*
 * Une détection de caméra n'a de sens que maison armée. Tant que l'alarme n'est
 * pas branchée — ou pas en marche — l'alerte automatique ne doit rien coûter.
 */
$alarme = creerEquipement(36, 'Alarme maison', 'alarme', 6);
$armement = creerCommande(360, $alarme, 'Armée', 'info', 'binary', array(
    'generique' => 'ALARM_ENABLE_STATE', 'valeur' => '0',
));
k2000beSecurite::oublier();

$kitt->setConfiguration('alerte_derniere', 0);
$kitt->setConfiguration('alerte_si', 360);
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 10);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array());
check('maison désarmée, le basculement ne réveille personne', $kitt->surveiller(), false);
check('et ne coûte pas un jeton', count(rejeu::$charges), 0);
/* Le repère avance quand même : ce qui s'est passé désarmé ne doit pas
 * ressortir au moment où l'on arme. */
check('mais le repère a avancé',
    (int) $kitt->getConfiguration('alerte_vu', 0) >= strtotime($declenchee->valueDate), true);

$armement->value = '1';
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 20);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array(reponseTexte('CONFIRME.')));
check('maison armée, l\'alerte repart', $kitt->surveiller(), true);

/*
 * Le cas qui motive le champ : on désigne l'alarme AVANT de l'avoir installée.
 * Tout ce qui n'est pas un oui franc vaut non — sans quoi désigner une commande
 * qui n'existe pas encore lancerait des demandes facturées toute la journée.
 */
$kitt->setConfiguration('alerte_derniere', 0);
$kitt->setConfiguration('alerte_si', 99999);
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 30);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
log::vider();
rejeu::scenario(array());
check('une commande d\'armement introuvable ferme la porte', $kitt->surveiller(), false);
check('et le journal du plugin dit pourquoi',
    strpos(log::texte(), 'armement') !== false, true);

/* Une valeur vide — l'état existe mais n'a jamais rien porté — vaut non
 * également : c'est l'alarme installée mais pas encore branchée. */
$kitt->setConfiguration('alerte_derniere', 0);
$kitt->setConfiguration('alerte_si', 360);
$armement->value = '';
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 40);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array());
check('un état d\'armement jamais renseigné vaut non', $kitt->surveiller(), false);

/* Sans condition, on retrouve le comportement d'avant. */
$kitt->setConfiguration('alerte_si', 0);
$kitt->setConfiguration('alerte_derniere', 0);
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 50);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array(reponseTexte('RIEN.')));
check('sans condition, l\'alerte part comme avant', $kitt->surveiller(), true);

unset(cmd::$toutes[360]);
unset(eqLogic::$tous[36]);

/* Et la garde qui vaut partout : un état qu'on n'a pas le droit de lire ne
 * réveille personne. */
$kitt->setConfiguration('alerte_derniere', 0);
$declenchee->setConfiguration('k2000beRead', 'deny');
$declenchee->valueDate = date('Y-m-d H:i:s', time() + 2);
$declenchee->collectDate = $declenchee->valueDate;
k2000beSecurite::oublier();
rejeu::scenario(array());
check('un état décisif masqué ne réveille pas l\'assistant', $kitt->surveiller(), false);
check('et n\'appelle personne', count(rejeu::$charges), 0);

$kitt->setConfiguration('alerte_auto', 0);
$kitt->setConfiguration('alerte_vu', 0);
$kitt->setConfiguration('alerte_derniere', 0);
$kitt->setConfiguration('alerte_si', 0);
$kitt->setConfiguration('verdict_types', '');
$kitt->reset();
foreach (array(340, 350) as $id) {
    unset(cmd::$toutes[$id]);
}
unset(eqLogic::$tous[34], eqLogic::$tous[35], jeeObject::$tous[6]);
k2000beSecurite::oublier();

/* ====================== PLAFOND DU JOUR, JOURNAL FILTRÉ, SANTÉ */
section('Le plafond du jour, le journal filtré et la santé');

/* Le dossier repart à plat : les onze journées de l'essai précédent
 * fausseraient chacun des comptes qui suivent. */
foreach (glob($dossierJournal . '/*') as $reste) {
    if (!is_dir($reste) && basename($reste) !== '.htaccess') {
        @unlink($reste);
    }
}

function ligneJournal($_eqId, $_statut, $_jetons, $_date) {
    return array(
        'date' => $_date, 'eq' => $_eqId, 'assistant' => 'KITT',
        'utilisateur' => 'jerome', 'demande' => 'demande', 'reponse' => 'réponse',
        'statut' => $_statut, 'modele' => 'gpt-4o-mini',
        'jetons' => array('invite' => $_jetons, 'reponse' => 0, 'total' => $_jetons),
        'duree' => 1.0, 'etapes' => array(),
    );
}

/*
 * La seule erreur du journal date d'hier, et soixante demandes se sont bien
 * passées depuis : elle est donc hors des cinquante dernières lignes. C'est
 * exactement le cas que le filtre de l'écran ne savait pas voir — il ne triait
 * que ce qui lui avait déjà été envoyé, et répondait « aucune erreur » à une
 * installation qui en avait une.
 */
file_put_contents($dossierJournal . '/' . date('Y-m-d', time() - 86400) . '.json',
    json_encode(array(ligneJournal($kitt->getId(), 'ERROR', 0, time() - 86400))));

$journee = array();
for ($ligne = 0; $ligne < 60; $ligne++) {
    $journee[] = ligneJournal($kitt->getId(), 'SUCCESS', 10, time());
}
/* La plus récente : un refus, qui n'a rien coûté parce qu'il n'est jamais
 * parti. */
$journee[] = ligneJournal($kitt->getId(), 'REFUSED', 0, time());
file_put_contents($dossierJournal . '/' . date('Y-m-d') . '.json', json_encode($journee));

$cinquante = k2000beJournal::historique($kitt->getId(), 50);
$statutsVus = array();
foreach ($cinquante as $ligne) {
    $statutsVus[$ligne['statut']] = true;
}
check('sans filtre, on ne voit que les cinquante dernières', count($cinquante), 50);
check('et l\'erreur d\'hier n\'en fait pas partie', isset($statutsVus['ERROR']), false);

$erreurs = k2000beJournal::historique($kitt->getId(), 50, 'ERROR');
check('filtrée, elle est retrouvée', count($erreurs), 1);
check('et c\'est bien elle', $erreurs[0]['statut'], 'ERROR');
check('le filtre ne retient que ce qu\'on lui demande',
    count(k2000beJournal::historique($kitt->getId(), 50, 'REFUSED')), 1);
check('un statut demandé et jamais rencontré rend une liste vide',
    k2000beJournal::historique($kitt->getId(), 50, 'CONFIRMATION'), array());

/*
 * Ce que la journée a coûté. Les deux lignes à zéro jeton — le refus
 * d'aujourd'hui, l'erreur d'hier — n'en sont pas : la première n'est jamais
 * partie, la seconde n'est pas d'aujourd'hui. Si un refus se comptait
 * lui-même, une journée plafonnée ne se rouvrirait jamais.
 */
$consommation = k2000beJournal::consommationDuJour();
check('soixante demandes facturées aujourd\'hui', $consommation['demandes'], 60);
check('et six cents jetons', $consommation['jetons'], 600);

/* Le plafond, lui, se lit borné des deux côtés. */
reglage('max_demandes_jour', 5000);
log::vider();
check('un plafond démesuré est ramené à la borne',
    invoquer('plafondJournalier', array()), k2000be::DEMANDES_JOUR_MAX);
check('et le journal du plugin le dit', strpos(log::texte(), 'max_demandes_jour') !== false, true);
reglage('max_demandes_jour', 0);
check('zéro veut dire « pas de plafond »', invoquer('plafondJournalier', array()), 0);

/*
 * Le plafond atteint. Ce qui compte n'est pas seulement le refus : c'est qu'il
 * tombe AVANT le réseau. Une file de réponses vide le prouve — servir() lève
 * dès qu'on lui demande quoi que ce soit.
 */
reglage('max_demandes_jour', 60);
rejeu::scenario(array());
$refus = $kitt->ask('Éteins tout.');
check('au-delà du plafond, la demande est refusée', $refus['statut'], k2000be::STATUT_REFUSED);
check('le message dit où se change le réglage',
    strpos($refus['erreur'], 'Demandes par jour') !== false, true);
check('et rien n\'est parti chez OpenAI', count(rejeu::$charges), 0);
check('le refus est publié sur la tuile',
    $kitt->getCmd('info', 'status')->execCmd(), k2000be::STATUT_REFUSED);
check('et il n\'a rien coûté', (int) $kitt->getCmd('info', 'jetons')->execCmd(), 0);

/* Desserré d'un cran, le même assistant répond de nouveau — et publie cette
 * fois ce que le tour a coûté. */
reglage('max_demandes_jour', 61);
rejeu::scenario(array(reponseTexte('Tout est éteint.', array(120, 30))));
$passe = $kitt->ask('Éteins tout.');
check('sous le plafond, la demande passe', $passe['statut'], k2000be::STATUT_SUCCESS);
check('les jetons du tour sont publiés',
    (int) $kitt->getCmd('info', 'jetons')->execCmd(), 150);
reglage('max_demandes_jour', 0);

/*
 * La page Santé. Le cœur la lit sans rien vérifier : chaque ligne doit porter
 * ses quatre clés, faute de quoi c'est un avertissement PHP qui s'affiche dans
 * la page Analyse de l'utilisateur.
 */
reglage('securite', 'actions');
k2000beSecurite::oublier();
$sante = k2000be::health();
$parTest = array();
$completes = true;
foreach ($sante as $ligne) {
    if (!isset($ligne['test'], $ligne['result'], $ligne['state']) || !array_key_exists('advice', $ligne)) {
        $completes = false;
        continue;
    }
    $parTest[$ligne['test']] = $ligne;
}
check('chaque ligne de santé porte ses quatre clés', $completes, true);
check('la clé API est vue comme renseignée', $parTest['Clé API OpenAI']['state'], true);
check('le dossier de travail aussi', $parTest['Dossier de travail']['state'], true);
check('la consommation du jour y figure',
    strpos($parTest['Consommation du jour']['result'], '61 demandes') !== false, true);
check('sans plafond réglé, la ligne conseille d\'en poser un',
    strpos($parTest['Consommation du jour']['advice'], 'scénario qui s\'emballe') !== false, true);
check('aucune commande sensible ne part sans confirmation',
    $parTest['Commandes sensibles sans confirmation']['state'], true);

/*
 * La serrure du garage passe en « Autorisée ». C'est un réglage licite, que
 * l'interface déconseille sans l'interdire — et c'est la seule ligne de la
 * page Santé qui doit alors virer au rouge : sur cent seize équipements,
 * personne ne déroule deux cent cinquante actions pour vérifier qu'aucune ne
 * traîne.
 */
k2000beSecurite::definirPolitique(160, k2000beSecurite::POLITIQUE_AUTORISE);
k2000beSecurite::oublier();
check('la serrure autorisée est comptée à part', k2000beSecurite::compteurs()['sensibles'], 1);
$alerte = k2000be::health();
$ligneSensible = null;
foreach ($alerte as $ligne) {
    if ($ligne['test'] === 'Commandes sensibles sans confirmation') {
        $ligneSensible = $ligne;
    }
}
check('et la page Santé le signale', $ligneSensible['state'], false);
check('en disant quoi faire',
    strpos($ligneSensible['advice'], 'Confirmation') !== false, true);

/*
 * En lecture seule, aucune commande ne peut partir : la compter comme
 * dangereuse ferait clignoter une page Santé sur une maison où rien ne bouge.
 */
reglage('securite', 'lecture');
k2000beSecurite::oublier();
check('en lecture seule, plus rien n\'est compté comme sensible',
    k2000beSecurite::compteurs()['sensibles'], 0);
$repos = k2000be::health();
check('et la ligne de mode dit que rien ne part',
    strpos($repos[1]['advice'], 'Rien n\'est envoyé') !== false, true);

k2000beSecurite::definirPolitique(160, k2000beSecurite::POLITIQUE_REFUS);
reglage('securite', 'actions');
k2000beSecurite::oublier();

/* ============================================================== LA PURGE */
section('La purge');

/* Un nom de fichier qui n'est pas une date n'a pas été écrit par le plugin :
 * strtotime() acceptait pourtant « 1999 », et le supprimait. */
file_put_contents($dossierJournal . '/1999.json', '[]');
file_put_contents($dossierJournal . '/notes.json', '[]');

$vieuxJournal = $dossierJournal . '/' . date('Y-m-d', time() - 40 * 86400) . '.json';
file_put_contents($vieuxJournal, '[]');

/* Mis de côté il y a quarante jours : conservé pour être examiné après
 * l'incident, pas pour rester à demeure. */
$vieilEcarte = $dossierJournal . '/' . date('Y-m-d', time() - 40 * 86400) . '.json'
    . k2000beJournal::SUFFIXE_ILLISIBLE;
file_put_contents($vieilEcarte, 'abîmé');
touch($vieilEcarte, time() - 40 * 86400);

/* Le reste d'un processus tué entre file_put_contents() et rename() : son nom
 * ne dit rien de son âge, seul le système de fichiers le sait. */
$temporaireJournal = $dossierJournal . '/2025-01-01.json.4242.tmp';
file_put_contents($temporaireJournal, '{');
touch($temporaireJournal, time() - 3660);
$temporaireConversation = $dossierConversations . '/20.json.4242.tmp';
file_put_contents($temporaireConversation, '{');
touch($temporaireConversation, time() - 3660);

file_put_contents($dossierConversations . '/' . $kitt->getId() . '.json', '{"version":1}');
file_put_contents($dossierConversations . '/888.json', '{"version":1}');

$supprimes = k2000beJournal::purger(30);
check('un fichier nommé 1999.json survit', file_exists($dossierJournal . '/1999.json'), true);
check('un fichier nommé notes.json aussi', file_exists($dossierJournal . '/notes.json'), true);
check('le journal de plus de trente jours part', file_exists($vieuxJournal), false);
check('le fichier mis de côté périmé aussi', file_exists($vieilEcarte), false);
check('le temporaire d\'une heure est ramassé', file_exists($temporaireJournal), false);
check('celui des conversations également', file_exists($temporaireConversation), false);
check('la conversation d\'un assistant disparu est ramassée',
    file_exists($dossierConversations . '/888.json'), false);
check('celle de l\'assistant vivant est gardée',
    file_exists($dossierConversations . '/' . $kitt->getId() . '.json'), true);
check('cinq fichiers en tout', $supprimes, 5);

/*
 * Le cas de la restauration de sauvegarde : la table est vide un instant, et le
 * cron quotidien effaçait la mémoire de TOUS les assistants de l'installation,
 * sans un mot. Une base qui ne rend aucun assistant alors que des conversations
 * existent est le signe d'un ennui, pas d'un ménage à faire.
 */
$assistantsEnBase = eqLogic::$tous;
foreach (eqLogic::$tous as $id => $eqLogic) {
    if ($eqLogic->getEqType_name() === 'k2000be') {
        unset(eqLogic::$tous[$id]);
    }
}
log::vider();
$pendantRestauration = k2000beJournal::purger(30);
check('aucune conversation n\'est supprimée quand la base n\'en rend aucun',
    file_exists($dossierConversations . '/' . $kitt->getId() . '.json'), true);
check('et rien n\'est compté comme supprimé', $pendantRestauration, 0);
check('la purge reportée est journalisée',
    strpos(log::texte(), 'purge des conversations reportée') !== false, true);
eqLogic::$tous = $assistantsEnBase;
k2000beSecurite::oublier();

/* =============================================================== BILAN */
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d contrôles réussis, %d en échec.\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
