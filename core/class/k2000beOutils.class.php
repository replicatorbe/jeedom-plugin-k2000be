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
 * Les huit outils que le modèle peut demander, et ce que le plugin accepte d'y
 * répondre.
 *
 * C'est la frontière du plugin : tout ce qui part chez OpenAI sort d'ici, et
 * tout ce que le modèle réclame y entre. Trois règles la gouvernent.
 *
 * 1. Rien ne sort sans autorisation. Un état non lisible n'apparaît NULLE
 *    PART, pas même par son nom : ni dans une liste, ni dans un message
 *    d'erreur, ni dans un compte. Le nom d'une commande est déjà une
 *    information sur la maison.
 *
 * 2. Rien ne part sans passer par k2000beSecurite::controler(). Cette classe
 *    n'a aucune décision à prendre : elle met en forme la question et la
 *    réponse, et c'est tout.
 *
 * 3. Aucune exception ne remonte. Une erreur devient un résultat d'erreur que
 *    le modèle peut lire et expliquer. Une exception qui remonterait couperait
 *    la conversation au milieu d'une série d'outils, et laisserait un message
 *    « tool_calls » sans ses réponses — ce que l'API refuse au tour suivant.
 *
 * S'y ajoute une contrainte moins noble mais constante : chaque caractère
 * rendu est un jeton payé, à chaque tour, pour toute la suite de la
 * conversation puisqu'il reste dans l'historique. Les listes sont donc
 * plafonnées, les identifiants internes inutiles ne sortent pas, et les noms
 * de clés sont courts.
 *
 * Les noms d'outils et leurs paramètres sont en anglais : c'est la langue de
 * l'API, et les modèles y sont nettement plus fiables. Les VALEURS rendues,
 * elles, portent les noms français de la maison.
 *
 * Ce fichier ne charge pas core.inc.php : il est inclus depuis
 * k2000be.class.php, qui l'a déjà fait.
 */
class k2000beOutils {

    /* ============================================================ PLAFONDS */

    /*
     * Ces nombres sont le budget en jetons du plugin. Une maison de deux cents
     * équipements décrite en entier coûte plusieurs dizaines de milliers de
     * jetons — à chaque tour, et pour toute la conversation. Le modèle doit
     * donc découvrir par étapes : les pièces, puis les équipements d'une
     * pièce, puis le détail de deux ou trois d'entre eux.
     */
    const LIMITE_EQUIPEMENTS = 40;
    const LIMITE_EQUIPEMENTS_MAX = 100;

    /* Le détail d'un équipement pèse dix fois sa ligne de liste. */
    const MAX_DETAIL = 10;

    /*
     * Et le plafond de dix porte sur les ÉQUIPEMENTS, pas sur leurs commandes.
     * Un module de chauffage, un équipement Virtuel ou une centrale d'alarme en
     * portent couramment quarante à quatre-vingts : dix de ceux-là faisaient
     * près de vingt mille jetons dans un seul résultat d'outil, qui reste
     * ensuite dans l'historique et se repaie à chaque tour de la conversation.
     *
     * Vingt états et vingt actions suffisent à comprendre ce que fait un
     * équipement ; ce qui dépasse se retrouve par search, qui cherche par nom.
     */
    const MAX_ETATS_EQUIPEMENT = 20;
    const MAX_ACTIONS_EQUIPEMENT = 20;

    const MAX_ETATS = 30;
    const MAX_RECHERCHE = 25;

    /* Borne de sécurité sur une liste d'identifiants reçue du modèle, avant
     * même le plafond de l'outil : la lecture dédoublonne, ce qui est
     * quadratique, et rien ne garantit la longueur de ce qu'il envoie. */
    const IDENTIFIANTS_MAX = 200;

    /* Types génériques résumés par équipement dans la liste : au-delà, la
     * ligne devient plus longue que le détail qu'elle est censée éviter. */
    const MAX_TYPES = 8;

    const HISTORIQUE_HEURES = 24;
    const HISTORIQUE_HEURES_MAX = 168;

    /* Cinquante points suffisent à faire voir une tendance, un palier ou un
     * pic — c'est tout ce qu'un modèle tire d'une courbe. Cent points n'en
     * diraient pas davantage et coûteraient deux fois plus, à chaque tour,
     * pour tout le reste de la conversation. Au-delà, on dégrossit. */
    const HISTORIQUE_POINTS = 50;

    /* Pièces citées dans le résumé de la maison, qui part dans l'invite
     * système à CHAQUE tour : c'est le texte le plus cher du plugin. */
    /*
     * Ce que rend get_events : la fenêtre par défaut, celle qu'on ne peut pas
     * dépasser, et le nombre d'événements rendus.
     *
     * Douze heures, parce que la question type est « est-ce que quelqu'un est
     * passé cette nuit ? » posée au réveil. Une semaine au plus : au-delà, on
     * ne cherche plus ce qui s'est passé, on consulte un historique, et c'est
     * get_history qui le fait, commande par commande.
     *
     * Vingt-cinq lignes : une nuit ordinaire en produit une poignée, une nuit
     * de vent devant une caméra en produit trois cents, et c'est justement là
     * qu'il ne faut pas les envoyer toutes au modèle — la réponse est dans les
     * plus récentes, et le compte total dit le reste.
     */
    const EVENEMENTS_HEURES = 12;
    const EVENEMENTS_HEURES_MAX = 168;
    const EVENEMENTS_MAX = 25;

    /*
     * Le verdict : sa fenêtre par défaut, et le nombre d'événements de contexte
     * qui l'accompagnent.
     *
     * Dix lignes de contexte, pas trente : elles ne décident de rien, elles
     * situent. En rendre davantage, c'est remettre sous les yeux du modèle la
     * pile de détections isolées dont on vient précisément de lui retirer le
     * droit de conclure.
     */
    const VERDICT_HEURES = 12;
    const VERDICT_CONTEXTE_MAX = 10;

    const RESUME_PIECES = 12;

    /* Longueur d'une valeur d'état recopiée. Une commande peut contenir un
     * JSON de plusieurs kilo-octets (une prévision météo, une liste de
     * titres) : la recopier entière ferait exploser la facture. */
    const VALEUR_MAX = 120;

    /* Les choix d'un select annoncés au modèle : leur nombre était borné, leur
     * longueur ne l'était pas. Un plugin tiers y met parfois des phrases. */
    const CHOIX_MAX = 20;
    const CHOIX_TEXTE_MAX = 60;
    const CHOIX_TOTAL_MAX = 400;

    /*
     * Âge à partir duquel la valeur d'un état cesse d'être une mesure du
     * présent et devient une archive. Vingt-quatre heures : au-dessous, tout
     * capteur normalement bavard a parlé au moins une fois — ne serait-ce que
     * pour répéter la même valeur, ce que le cœur horodate dans collectDate.
     */
    const FRAICHEUR_HEURES = 24;

    /* Ce qui est dit au modèle quand la valeur qu'il a jointe n'avait pas de
     * destinataire. En anglais comme tout ce qui part dans un résultat
     * d'outil. */
    /*
     * Ce qu'il faut dire d'un état qui n'a jamais porté de valeur.
     *
     * Le cas est arrivé en production, et la réponse était fausse : huit
     * commandes « Caméra masquée » d'un plugin de vidéosurveillance, jamais
     * renseignées parce qu'aucun sabotage n'a jamais eu lieu, sont parties chez
     * le modèle en « value: "" ». Faute de pouvoir lire la valeur, il a lu le
     * NOM, et a répondu au propriétaire que toutes ses caméras étaient
     * masquées. Une valeur vide et une valeur jamais écrite se ressemblaient
     * trop ; la dernière phrase de cette note est celle qui compte.
     */
    const NOTE_JAMAIS_RELEVE = 'Some states have never carried any value since the plugin has been watching them ("never_read": true): their device has not reported them even once, not even to say zero. That is not an empty value, and not a false: you cannot tell what they are. Say plainly that the value is unknown, and NEVER guess the state of the home from the NAME of the command.';

    const NOTE_VALEUR_IGNOREE = 'The value you passed was ignored: this command takes no parameter. Do not tell the user you set anything to that value.';

    /* ======================================= L'ATTENTE AVANT VÉRIFICATION */

    /*
     * Le plugin dit au modèle qu'une commande envoyée n'est pas une commande
     * aboutie, et de vérifier avec get_states. Mais entre l'envoi et la
     * lecture, il ne s'écoule que l'aller-retour vers OpenAI : une à trois
     * secondes. Un équipement qui remonte son état en cinq — un volet en fin de
     * course, une ampoule Zigbee derrière un routeur, un thermostat qui ne
     * parle qu'au relevé suivant — fait donc lire au modèle L'ANCIENNE VALEUR.
     * Il annonce alors un échec qui n'a pas eu lieu, et il est tenté de refaire
     * l'action : la consigne de vérifier se retourne contre elle-même.
     *
     * D'où une attente bornée, demandée par le modèle lui-même, pendant
     * laquelle on relit les commandes jusqu'à ce qu'elles aient parlé.
     */

    /*
     * Dix secondes au plus pour une lecture. Les remontées d'état domestiques
     * tiennent en une à cinq secondes (Z-Wave, Zigbee, MQTT, la plupart des
     * plugins IP) ; dix couvrent le retardataire sans changer de nature. Au-delà
     * ce ne serait plus une vérification mais une veille, et elle se ferait au
     * pire endroit : le verrou de l'assistant est tenu pendant tout le tour, et
     * l'utilisateur, lui, regarde une page qui ne répond pas.
     */
    const ATTENTE_MAX = 10;

    /*
     * Et le cumul, sur toute la demande. Sans lui, dix vérifications de trois
     * secondes mangeraient trente secondes du budget de temps du tour — celui
     * qui existe pour que PHP ne tue pas le processus au milieu d'une action.
     * Quinze secondes valent une vérification franche, ou trois brèves, et
     * laissent le reste du budget aux allers-retours qui, eux, font le travail.
     */
    const ATTENTE_CUMUL_MAX = 15;

    /*
     * Le pas de relecture. Plus court, il multiplierait les lectures de cache
     * sans rien apprendre de plus ; plus long, il ferait attendre jusqu'à une
     * seconde de trop un équipement qui a déjà répondu.
     */
    const ATTENTE_PAS_MS = 300;

    /* Ce qui a déjà été attendu dans cette demande. Remis à zéro par
     * nouvelleDemande(), au début de chaque tour. */
    private static $attenteCumulee = 0.0;

    /* Mémoire d'un appel : la liste des commandes est relue par presque tous
     * les outils, et la conversation en enchaîne plusieurs. */
    private static $parEquipement = null;
    private static $parId = null;
    private static $visibles = null;

    /* ======================================================= DÉFINITIONS */

    /*
     * Le catalogue au format attendu par l'API (champ « tools »).
     *
     * Les descriptions ne décrivent pas seulement ce que fait l'outil : elles
     * disent au modèle comment s'en servir sobrement, et surtout qu'un refus
     * est normal. Sans cette dernière phrase, un modèle refusé réessaie, puis
     * invente une panne matérielle pour expliquer son échec.
     */
    public static function definitions($_eqLogic = null) {
        $outils = array(

            self::outil('list_rooms',
                'List the rooms of the home with the number of devices the assistant can see in each. Start here: it is the cheapest way to get your bearings.',
                array(), array()),

            self::outil('list_equipments',
                'List devices, with a short summary of what each can do: its generic types, how many states you may read, and how many actions you are ALLOWED to run on it. Always narrow the list with room, search or generic_type instead of listing the whole home.',
                array(
                    'room' => array('type' => 'string', 'description' => 'Restrict to a room, by name. Partial and accent-insensitive.'),
                    'search' => array('type' => 'string', 'description' => 'Restrict to devices whose name contains this text.'),
                    'generic_type' => array('type' => 'string', 'description' => 'Restrict to devices having a command of this Jeedom generic type, for instance LIGHT_ON, FLAP_STATE, TEMPERATURE.'),
                    'limit' => array('type' => 'integer', 'description' => 'Maximum number of devices returned. Default 40, maximum 100.'),
                ), array()),

            self::outil('get_equipment',
                'Get the detail of a few devices: their states with current value and unit, and the commands the assistant may run, each with its permission. Ask for at most ten devices, and only those you actually need. Long devices are cut: more_states and more_actions say how many were left out. A state that records an event also carries "since", the moment its value last changed: for a detection or an opening, that date is the information, and the value alone never says when it happened.',
                array(
                    'equipment_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Device identifiers, as returned by list_equipments. Ten at most.',
                    ),
                ), array('equipment_ids')),

            self::outil('get_states',
                'Read the current value of specific states, each with "since" when it records an event: the moment its value last changed, which is what a detection or an opening is really about. Use it to check the result of a command you have just sent, rather than assuming it worked. A device needs a few seconds to report back: when you check right after acting, pass "wait", otherwise you read the value from BEFORE your command and wrongly report a failure.',
                array(
                    'command_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'State command identifiers, as returned by get_equipment. Thirty at most.',
                    ),
                    'wait' => array(
                        'type' => 'integer',
                        'description' => 'Seconds to wait for the devices to report a new reading, 0 to 10. Use 3 to 5 right after sending a command, 0 when you are only consulting. The answer comes back as soon as every state asked for has been read again, and each one carries "refreshed": false means its device said nothing during the wait, which is not the same as a value that did not change.',
                    ),
                ), array('command_ids')),

            self::outil('search',
                'Search devices and commands across the whole home, by name and by Jeedom generic type. Use it when the user names something you have not located yet — including a KIND of device: searching "camera" finds the cameras even when they are named after the direction they watch. A device found this way carries "types", which says what it is.',
                array(
                    'query' => array('type' => 'string', 'description' => 'Text to look for in device and command names. Accent-insensitive.'),
                ), array('query')),

            self::outil('get_history',
                'Get past values of one state. Only works on states Jeedom records history for. The answer says what it gives back: "sampling" tells whether the points are raw readings, state changes, or averages computed by Jeedom, and "from"/"to" give the period actually covered, which may be shorter than the one you asked for.',
                array(
                    'command_id' => array('type' => 'integer', 'description' => 'State command identifier.'),
                    'hours' => array('type' => 'integer', 'description' => 'How far back to look, in hours. Default 24, maximum 168.'),
                ), array('command_id')),

            self::outil('get_events',
                'List what recently happened in the home: the states that record an event — a motion detection, a door opening, a leak, a smoke alarm — sorted by the moment they changed, most recent first. Each line says what happened ("started" or "ended"), not the value afterwards: a detection that is over reads as "ended", and that is still something that happened. Ask this for "did anything happen last night" instead of reading sensors one by one. Only states the administrator chose to keep history for appear here.',
                array(
                    'hours' => array('type' => 'integer', 'description' => 'How far back to look, in hours. Default 12, maximum 168.'),
                    'room' => array('type' => 'string', 'description' => 'Restrict to a room, by name. Partial and accent-insensitive.'),
                ), array()),

            self::outil('execute_command',
                'Ask the plugin to run one action command. The plugin decides: it may run it, ask the user to confirm it, or refuse it. A refusal is a house rule, not a failure — report it plainly to the user and do not retry. A command that was sent is not a command that worked: check with get_states, passing wait: 3 to 5, before saying it is done. Independent actions belong in the same message: they then run in one round trip.',
                array(
                    'command_id' => array('type' => 'integer', 'description' => 'Action command identifier, as returned by get_equipment.'),
                    /*
                     * « expects » et non « value » dans la ligne d'action :
                     * les deux clés s'appelaient value, l'une pour annoncer le
                     * TYPE attendu et l'autre pour porter la valeur. Un modèle
                     * qui recopiait ce qu'il venait de lire envoyait
                     * value: "number" — et sur un select sans liste de choix,
                     * c'était accepté et transmis à la maison.
                     */
                    'value' => array('type' => 'string', 'description' => 'The value itself, when the action announces one in its "expects" field: the number for "number", one of the listed choices for "choice", #rrggbb or #rgb for "color", the text to send for "message". Never send the word "number", "choice", "color" or "message" itself. Leave it out for a plain button.'),
                    'title' => array('type' => 'string', 'description' => 'Only for an action whose "expects" is "message": the short title of the notification. The body goes in value.'),
                ), array('command_id')),
        );

        /*
         * Le huitième outil et demi : il n'existe que pour un assistant à qui
         * l'administrateur a déclaré des états décisifs. Un outil annoncé qui
         * répondrait toujours « rien à surveiller » se paierait en jetons à
         * chaque demande de tous les autres assistants, et le modèle finirait
         * par l'appeler pour rien.
         */
        if (self::typesDecisifs($_eqLogic) !== array()) {
            $outils[] = self::outil('check_alert',
                'Decide whether something is actually going on. The plugin computes the verdict itself, from the only states the administrator declared as deciding — typically cross-detections, which fire when two independent detections overlap. Call this for "is someone there", "any intrusion", "lift the doubt". You report the verdict; you never compute your own: the events returned under "context" are there to describe what was seen, and they never make a verdict, however many of them there are.',
                array(
                    'hours' => array('type' => 'integer', 'description' => 'How far back to look, in hours. Default 12, maximum 168.'),
                ), array());
        }

        return $outils;
    }

    /* Les types décisifs de l'assistant courant, ou rien s'il n'en a pas — un
     * scénario, un rejeu hors ligne et l'aperçu de l'invite appellent tous
     * definitions() sans équipement. */
    private static function typesDecisifs($_eqLogic) {
        if (!is_object($_eqLogic) || !method_exists($_eqLogic, 'typesDecisifs')) {
            return array();
        }
        try {
            $types = $_eqLogic->typesDecisifs();
        } catch (Throwable $e) {
            return array();
        }
        return is_array($types) ? $types : array();
    }

    private static function outil($_nom, $_description, $_proprietes, $_requis) {
        return array(
            'type' => 'function',
            'function' => array(
                'name' => $_nom,
                'description' => $_description,
                'parameters' => array(
                    'type' => 'object',
                    'properties' => empty($_proprietes) ? new stdClass() : $_proprietes,
                    'required' => $_requis,
                ),
            ),
        );
    }

    /* ======================================================== EXÉCUTION */

    /*
     * Point d'entrée unique. Rend toujours la même structure, et ne lève
     * jamais : voir la règle 3 en tête de fichier.
     */
    public static function executer($_nom, $_arguments, $_eqLogic = null) {
        $nom = (string) $_nom;
        $arguments = is_array($_arguments) ? $_arguments : array();

        try {
            switch ($nom) {
                case 'list_rooms':
                    return self::listRooms();
                case 'list_equipments':
                    return self::listEquipments($arguments);
                case 'get_equipment':
                    return self::getEquipment($arguments);
                case 'get_states':
                    return self::getStates($arguments);
                case 'search':
                    return self::rechercher($arguments);
                case 'get_history':
                    return self::getHistory($arguments);
                case 'get_events':
                    return self::evenements($arguments);
                case 'check_alert':
                    return self::verdict($arguments, $_eqLogic);
                case 'execute_command':
                    return self::executeCommand($arguments);
            }
            return self::erreur($nom, sprintf(__('L\'outil « %s » n\'existe pas.', __FILE__), $nom));
        } catch (Throwable $e) {
            /*
             * Le message d'une exception du cœur peut contenir n'importe quoi,
             * y compris une requête SQL : il passe par le masquage avant
             * d'aller chez OpenAI.
             */
            return self::erreur($nom, k2000beJournal::masquer($e->getMessage()));
        }
    }

    /*
     * Exécute une commande dont la confirmation vient d'être accordée.
     *
     * Elle rejoue TOUS les contrôles — l'équipement a pu être désactivé, la
     * commande supprimée ou l'autorisation retirée entre la demande et le clic
     * — et ne neutralise que celui-là même auquel l'utilisateur vient de
     * répondre. Sans ce passage, confirm() devrait rappeler execute_command,
     * qui redemanderait une confirmation : la boucle ne se refermerait jamais.
     *
     * $_demande est une entrée de la liste « demandes » de l'attente.
     */
    public static function executerApresConfirmation($_demande, $_eqLogic = null) {
        try {
            $demande = is_array($_demande) ? $_demande : array();
            $id = isset($demande['command_id']) ? (int) $demande['command_id'] : 0;
            $valeur = isset($demande['valeur']) ? $demande['valeur'] : null;
            return self::appliquer(k2000beSecurite::controler($id, $valeur), true);
        } catch (Throwable $e) {
            return self::erreur('execute_command', k2000beJournal::masquer($e->getMessage()));
        }
    }

    /* ========================================================== LES HUIT */

    private static function listRooms() {
        $pieces = array();
        $total = 0;
        foreach (self::equipementsVisibles() as $eqLogic) {
            $piece = self::piece($eqLogic);
            if (!isset($pieces[$piece])) {
                $pieces[$piece] = 0;
            }
            $pieces[$piece]++;
            $total++;
        }
        ksort($pieces, SORT_FLAG_CASE | SORT_NATURAL);

        $liste = array();
        foreach ($pieces as $nom => $nombre) {
            $liste[] = array('room' => $nom, 'equipments' => $nombre);
        }

        return self::rendu('list_rooms',
            array('rooms' => $liste, 'total_equipments' => $total),
            __('Pièces de la maison', __FILE__),
            'ok',
            sprintf(__('%d pièce(s), %d équipement(s) visibles.', __FILE__), count($liste), $total));
    }

    private static function listEquipments($_arguments) {
        $piece = self::pliage(self::argument($_arguments, 'room', ''));
        $recherche = self::pliage(self::argument($_arguments, 'search', ''));
        $generique = strtoupper(trim((string) self::argument($_arguments, 'generic_type', '')));
        $limite = (int) self::argument($_arguments, 'limit', self::LIMITE_EQUIPEMENTS);
        if ($limite < 1) {
            $limite = self::LIMITE_EQUIPEMENTS;
        }
        if ($limite > self::LIMITE_EQUIPEMENTS_MAX) {
            $limite = self::LIMITE_EQUIPEMENTS_MAX;
        }

        /* Lu une fois pour toute la liste : le mode ne change pas au milieu
         * d'un appel, et le relire par commande ferait une lecture de
         * configuration par commande de la maison. */
        $lectureSeule = (k2000beSecurite::mode() === k2000beSecurite::MODE_LECTURE);

        $retenus = array();
        $total = 0;
        foreach (self::equipementsVisibles() as $eqLogic) {
            $nomPiece = self::piece($eqLogic);
            if ($piece !== '' && strpos(self::pliage($nomPiece), $piece) === false) {
                continue;
            }
            if ($recherche !== '' && strpos(self::pliage($eqLogic->getName()), $recherche) === false) {
                continue;
            }

            $resume = self::resumeCommandes($eqLogic, $lectureSeule);
            if ($generique !== '' && !in_array($generique, $resume['generiques'], true)) {
                continue;
            }

            $total++;
            if (count($retenus) >= $limite) {
                continue;
            }
            $retenus[] = array(
                'id'      => (int) $eqLogic->getId(),
                'name'    => (string) $eqLogic->getName(),
                'room'    => $nomPiece,
                'types'   => array_slice($resume['generiques'], 0, self::MAX_TYPES),
                'actions' => $resume['actions'],
                'states'  => $resume['etats'],
            );
        }

        $resultat = array('equipments' => $retenus, 'total_matching' => $total);
        if ($total > count($retenus)) {
            $resultat['note'] = 'Only the first ' . count($retenus) . ' of ' . $total . ' matching devices are listed. Narrow the search instead of raising the limit.';
        }
        if ($lectureSeule) {
            /* Le compte d'actions est alors nul partout : sans cette ligne, le
             * modèle croirait la maison dépourvue de commandes plutôt que
             * fermée. */
            $resultat['mode'] = 'read_only: the assistant is not allowed to run any command at the moment, so no device announces any runnable action.';
        }

        return self::rendu('list_equipments', $resultat,
            __('Équipements', __FILE__), 'ok',
            sprintf(__('%d équipement(s) rendus sur %d.', __FILE__), count($retenus), $total));
    }

    private static function getEquipment($_arguments) {
        $demandes = self::identifiants(self::argument($_arguments, 'equipment_ids', array()));
        $ids = array_slice($demandes, 0, self::MAX_DETAIL);
        if (empty($ids)) {
            return self::erreur('get_equipment', __('Aucun identifiant d\'équipement n\'a été fourni.', __FILE__));
        }
        $notes = array();
        if (count($demandes) > count($ids)) {
            /* La liste d'équipements annonce ses troncatures ; celle-ci se
             * taisait, et le modèle croyait avoir reçu le détail des vingt
             * équipements qu'il avait demandés. */
            $notes[] = 'Only the first ' . count($ids) . ' of the ' . count($demandes) . ' device identifiers you asked for were read. Ask for the others in another call.';
        }

        /* En lecture seule, aucune commande ne partira : l'annoncer ici évite
         * au modèle de proposer des actions qu'il ne pourra pas mener. */
        $lectureSeule = (k2000beSecurite::mode() === k2000beSecurite::MODE_LECTURE);
        $visibles = self::equipementsVisibles();

        $equipements = array();
        $introuvables = array();
        $tronque = false;
        foreach ($ids as $id) {
            if (!isset($visibles[$id])) {
                /* Masqué, désactivé, inexistant ou assistant : une seule
                 * réponse pour les quatre, car distinguer « masqué » de
                 * « inexistant » dirait déjà que l'équipement existe. */
                $introuvables[] = $id;
                continue;
            }
            $eqLogic = $visibles[$id];
            $etats = array();
            $actions = array();
            $etatsTotal = 0;
            $actionsTotal = 0;

            foreach (self::commandesDe($id) as $cmd) {
                if ($cmd->getType() === 'info') {
                    if (!k2000beSecurite::lisible($cmd, $eqLogic)) {
                        continue;
                    }
                    $etatsTotal++;
                    if (count($etats) >= self::MAX_ETATS_EQUIPEMENT) {
                        continue;
                    }
                    $etats[] = self::ligneEtat($cmd);
                    continue;
                }
                if ($cmd->getType() !== 'action') {
                    continue;
                }
                $politique = k2000beSecurite::politique($cmd);
                if ($cmd->getGeneric_type() === k2000beSecurite::TYPE_IGNORE && $politique === k2000beSecurite::POLITIQUE_REFUS) {
                    /* Marquée « ne pas tenir compte » par l'administrateur ET
                     * non autorisée : elle n'a rien à faire dans la réponse. */
                    continue;
                }
                $actionsTotal++;
                if (count($actions) >= self::MAX_ACTIONS_EQUIPEMENT) {
                    continue;
                }
                $actions[] = self::ligneAction($cmd, $lectureSeule ? k2000beSecurite::POLITIQUE_REFUS : $politique);
            }

            $ligne = array(
                'id'      => $id,
                'name'    => (string) $eqLogic->getName(),
                'room'    => self::piece($eqLogic),
                'states'  => $etats,
                'actions' => $actions,
            );
            if ($etatsTotal > count($etats)) {
                $ligne['more_states'] = $etatsTotal - count($etats);
                $tronque = true;
            }
            if ($actionsTotal > count($actions)) {
                $ligne['more_actions'] = $actionsTotal - count($actions);
                $tronque = true;
            }
            $equipements[] = $ligne;
        }

        $resultat = array('equipments' => $equipements);
        if (!empty($introuvables)) {
            $resultat['not_available'] = $introuvables;
            $notes[] = 'Those device identifiers are not available to the assistant. Do not mention them to the user as existing.';
        }
        if (!empty($tronque)) {
            $notes[] = 'This device has more states or actions than listed (see more_states and more_actions); find the missing ones by name with search.';
        }
        foreach ($equipements as $equipement) {
            if (self::contientJamaisReleve($equipement['states'])) {
                $notes[] = self::NOTE_JAMAIS_RELEVE;
                break;
            }
        }
        if (!empty($notes)) {
            $resultat['note'] = implode(' ', $notes);
        }
        if ($lectureSeule) {
            $resultat['mode'] = 'read_only: the assistant is not allowed to run any command at the moment.';
        }

        return self::rendu('get_equipment', $resultat,
            __('Détail des équipements', __FILE__), 'ok',
            sprintf(__('%d équipement(s) détaillés.', __FILE__), count($equipements)));
    }

    private static function getStates($_arguments) {
        $demandes = self::identifiants(self::argument($_arguments, 'command_ids', array()));
        $ids = array_slice($demandes, 0, self::MAX_ETATS);
        if (empty($ids)) {
            return self::erreur('get_states', __('Aucun identifiant de commande n\'a été fourni.', __FILE__));
        }
        $notes = array();
        if (count($demandes) > count($ids)) {
            $notes[] = 'Only the first ' . count($ids) . ' of the ' . count($demandes) . ' state identifiers you asked for were read. Ask for the others in another call.';
        }

        $visibles = self::equipementsVisibles();
        $lisibles = array();
        $refuses = array();
        foreach ($ids as $id) {
            /* L'index des commandes est déjà en mémoire : un cmd::byId() par
             * identifiant refaisait jusqu'à trente requêtes au cœur, plus une
             * par équipement pour la lisibilité, là où tout était déjà lu. */
            $cmd = self::commande($id);
            $eqId = is_object($cmd) ? (int) $cmd->getEqLogic_id() : 0;
            $eqLogic = isset($visibles[$eqId]) ? $visibles[$eqId] : null;
            if (!is_object($cmd) || !is_object($eqLogic) || !k2000beSecurite::lisible($cmd, $eqLogic)) {
                /*
                 * Pas de nom, pas d'équipement, pas de motif détaillé : un
                 * état non lisible ne doit rien laisser filtrer, et « la
                 * commande 45 existe mais vous est interdite » en dit déjà
                 * trop sur la maison.
                 */
                $refuses[] = $id;
                continue;
            }
            $lisibles[$id] = array('cmd' => $cmd, 'eqLogic' => $eqLogic);
        }

        /*
         * L'attente, avant toute lecture — et seulement sur les commandes
         * retenues ci-dessus. Attendre pour une commande interdite ferait de la
         * durée de la réponse une information sur la maison : « ça a mis cinq
         * secondes, donc quelque chose bouge là-bas ». Et cela ferait payer au
         * budget d'attente une lecture qui n'aura pas lieu.
         */
        $demandee = (int) self::argument($_arguments, 'wait', 0);
        $accordee = self::attenteAccordee($demandee);
        $attendu = 0.0;
        $rafraichis = array();
        if ($accordee > 0 && !empty($lisibles)) {
            $commandes = array();
            foreach ($lisibles as $id => $couple) {
                $commandes[$id] = $couple['cmd'];
            }
            $bilan = self::attendreReleves($commandes, $accordee);
            $attendu = $bilan['duree'];
            $rafraichis = $bilan['rafraichis'];
            self::$attenteCumulee += $attendu;
        }

        $etats = array();
        foreach ($lisibles as $id => $couple) {
            /*
             * L'horodatage ET le type générique. La description de cet outil
             * demande au modèle de vérifier l'effet d'une commande avec lui :
             * sans le générique, il lit « value: 1 » sans savoir si 1 veut
             * dire ouvert ou fermé, et se prononce quand même.
             */
            $ligne = self::ligneEtat($couple['cmd']);
            $ligne['device'] = (string) $couple['eqLogic']->getName();
            /* Une date vide est pire qu'une date absente : elle se lit comme un
             * champ qui aurait dû être rempli, et le modèle en tire des
             * conclusions. Quand il n'y a pas de date, c'est never_read qui
             * parle. */
            $lue = self::dateCourte(self::dateDeLecture($couple['cmd']));
            if ($lue !== '') {
                $ligne['updated'] = $lue;
            }
            if ($accordee > 0) {
                /*
                 * La distinction qui évite le faux échec : « l'état n'a pas
                 * changé » et « l'équipement n'a rien dit » se lisaient pareil,
                 * c'est-à-dire comme une commande sans effet.
                 */
                $ligne['refreshed'] = isset($rafraichis[$id]);
            }
            $etats[] = $ligne;
        }

        $resultat = array('states' => $etats);
        if (self::contientJamaisReleve($etats)) {
            $notes[] = self::NOTE_JAMAIS_RELEVE;
        }
        if (!empty($refuses)) {
            $resultat['not_available'] = $refuses;
            $notes[] = 'Those state identifiers are not readable by the assistant.';
        }
        if ($demandee > 0) {
            /* Le temps réellement attendu, même quand il est nul : le modèle a
             * demandé une attente, il doit savoir si elle a eu lieu. */
            $resultat['waited'] = $attendu;
        }
        /* Les notes, elles, n'ont de sens que s'il y avait quelque chose à
         * attendre : sans état lisible, « not_available » dit déjà tout. */
        if ($demandee > 0 && !empty($lisibles)) {
            if ($accordee <= 0) {
                $notes[] = 'Nothing was waited for: the waiting budget of this request ('
                    . self::ATTENTE_CUMUL_MAX . ' s in total) is spent. Read again in a later turn if a value still looks old.';
            } else {
                $muets = count($lisibles) - count($rafraichis);
                if ($muets > 0) {
                    $notes[] = $muets . ' state(s) were not read again during the wait ("refreshed": false): their device reported nothing, which is not the same as a value that did not change. Do not conclude from that alone that a command failed.';
                }
                if ($accordee < $demandee) {
                    $notes[] = 'The wait was cut to ' . $accordee . ' s (' . self::ATTENTE_MAX
                        . ' s at most per call, ' . self::ATTENTE_CUMUL_MAX . ' s in total per request).';
                }
            }
        }
        if (!empty($notes)) {
            $resultat['note'] = implode(' ', $notes);
        }

        return self::rendu('get_states', $resultat,
            __('États', __FILE__), 'ok',
            ($attendu > 0)
                ? sprintf(__('%d état(s) lus après %s s d\'attente.', __FILE__), count($etats), $attendu)
                : sprintf(__('%d état(s) lus.', __FILE__), count($etats)));
    }

    /*
     * Le temps qu'on accepte d'attendre pour cette lecture-ci : ce que le modèle
     * demande, ramené au plafond d'un appel, puis à ce qui reste du budget de la
     * demande entière.
     *
     * Le reliquat inférieur à un pas ne vaut pas la peine d'être attendu : on
     * dormirait moins longtemps que l'intervalle de relecture, donc sans jamais
     * relire.
     */
    private static function attenteAccordee($_demandee) {
        $demandee = (int) $_demandee;
        if ($demandee < 1) {
            return 0.0;
        }
        if ($demandee > self::ATTENTE_MAX) {
            $demandee = self::ATTENTE_MAX;
        }
        $reste = round(self::ATTENTE_CUMUL_MAX - self::$attenteCumulee, 2);
        if ($reste < (self::ATTENTE_PAS_MS / 1000)) {
            return 0.0;
        }
        return ($demandee > $reste) ? $reste : (float) $demandee;
    }

    /*
     * Attend que les commandes demandées soient relues par la maison, et rend
     * ce qui s'est passé : la durée réellement attendue, et celles qui ont
     * parlé.
     *
     * On s'arrête dès que TOUTES ont parlé — la vérification porte sur un lot,
     * et répondre avec la moitié des états à jour serait pire que d'attendre un
     * pas de plus. Sinon, le délai fait foi.
     *
     * Ce qu'on surveille est la date de RELEVÉ, pas la valeur : une lampe
     * qu'on rallume alors qu'elle était déjà allumée ne change pas de valeur,
     * mais son plugin la relève quand même. Guetter la valeur ferait attendre
     * en vain à chaque commande sans effet visible.
     */
    private static function attendreReleves($_commandes, $_secondes) {
        $debut = microtime(true);
        $fin = $debut + $_secondes;

        $avant = array();
        foreach ($_commandes as $id => $cmd) {
            $avant[$id] = self::releve($cmd);
        }

        $rafraichis = array();
        $restants = array_keys($avant);
        while (!empty($restants)) {
            $reste = $fin - microtime(true);
            if ($reste <= 0) {
                break;
            }
            usleep((int) round(min(self::ATTENTE_PAS_MS / 1000, $reste) * 1000000));
            foreach ($restants as $rang => $id) {
                if (self::plusRecente($avant[$id], self::releve($_commandes[$id]))) {
                    $rafraichis[$id] = true;
                    unset($restants[$rang]);
                }
            }
        }

        return array(
            'duree'      => round(microtime(true) - $debut, 1),
            'rafraichis' => $rafraichis,
        );
    }

    /*
     * La date de relevé d'une commande, relue POUR DE BON.
     *
     * Le détail qui décide de tout : le cœur mémorise la date dans l'objet.
     * cmd::getCollectDate() ne va la chercher dans le cache que si elle est
     * encore vide (cmd.class.php : « if ($this->_collectDate == '') execCmd() »),
     * et c'est execCmd() qui la repose depuis le cache à chaque appel. Relire la
     * date sans relire la valeur rendrait donc éternellement la même seconde, et
     * l'attente ne verrait jamais l'équipement parler — une boucle qui dort dix
     * secondes pour rien.
     *
     * Le cache, lui, est bien relu : FileCache::fetch() ouvre son fichier à
     * chaque appel et ne garde rien en mémoire de processus (cache.class.php).
     * Ce que le démon de l'équipement écrit pendant que nous dormons est donc
     * vu au pas suivant. Cela fait une lecture de fichier par commande et par
     * pas — trente commandes, dix secondes : neuf cents lectures d'un fichier
     * que le cœur écrit de toute façon en permanence.
     */
    private static function releve($_cmd) {
        self::valeur($_cmd);
        return self::dateDeLecture($_cmd);
    }

    /* Deux dates de relevé successives : la maison a-t-elle reparlé ? Le cœur
     * horodate à la seconde, donc l'égalité stricte vaut « rien de neuf ». Une
     * date qui recule — changement d'heure, horloge remise — ne compte pas pour
     * un relevé. */
    private static function plusRecente($_avant, $_apres) {
        if ($_apres === '' || $_apres === $_avant) {
            return false;
        }
        $apres = strtotime($_apres);
        if ($apres === false) {
            return false;
        }
        $avant = strtotime((string) $_avant);
        if ($avant === false) {
            /* Rien avant, quelque chose maintenant : la commande vient d'être
             * relevée pour la première fois. */
            return true;
        }
        return ($apres >= $avant);
    }

    /*
     * Un nouveau tour commence : le budget d'attente est celui d'UNE demande.
     *
     * Appelée par la boucle de conversation, et pas seulement laissée au hasard
     * de la fin du processus PHP : un scénario qui enchaîne deux « ask » dans le
     * même processus verrait sinon la seconde demande privée d'attente parce que
     * la première a dépensé le budget.
     */
    public static function nouvelleDemande() {
        self::$attenteCumulee = 0.0;
    }

    private static function rechercher($_arguments) {
        $requete = self::pliage(self::argument($_arguments, 'query', ''));
        if ($requete === '') {
            return self::erreur('search', __('La recherche a été lancée sans texte à chercher.', __FILE__));
        }

        /* La même règle que get_equipment, et pour la même raison : en lecture
         * seule aucune action ne partira, donc aucune ne peut être annoncée
         * « allowed ». Deux outils qui répondent différemment sur la même
         * commande font promettre au modèle une action qu'il se verra refuser
         * au tour suivant : un aller-retour payé pour se contredire. */
        $lectureSeule = (k2000beSecurite::mode() === k2000beSecurite::MODE_LECTURE);

        $equipements = array();
        $commandes = array();
        $tronque = false;

        foreach (self::equipementsVisibles() as $id => $eqLogic) {
            $piece = self::piece($eqLogic);
            $resume = self::resumeCommandes($eqLogic, $lectureSeule);

            /*
             * On cherche dans le nom de l'équipement ET dans ses types
             * génériques.
             *
             * Le nom seul ne suffisait pas, et le cas qui l'a montré est
             * exemplaire : huit caméras nommées d'après leur orientation —
             * EST, NORD, SUD, OUESTPTZ —, toutes dans la même pièce. Chercher
             * « caméra » ne rendait AUCUN équipement, et une seule commande :
             * « Caméra masquée », la seule dont le nom porte le mot, et
             * justement celle qu'aucun événement n'a jamais renseignée.
             * L'assistant a donc répondu, sur ce seul indice, que toutes les
             * caméras étaient masquées. Les détections de mouvement et les
             * franchissements de ligne, eux, étaient là, nommés « Mouvement »
             * et « Ligne franchie », et rien ne les rattachait au mot cherché.
             *
             * Les types génériques du cœur rattachent l'équipement à ce qu'il
             * EST — CAMERA_URL, THERMOSTAT_SETPOINT, FLAP_STATE — plutôt qu'au
             * nom que son propriétaire lui a donné.
             *
             * Ils sortent de resumeCommandes(), et c'est essentiel : cette
             * méthode ne retient que les types des commandes que l'assistant a
             * le DROIT de voir. Chercher « lock » ne peut donc pas révéler
             * qu'une serrure interdite existe quelque part.
             */
            $trouve = (strpos(self::pliage($eqLogic->getName()), $requete) !== false);
            if (!$trouve) {
                foreach ($resume['generiques'] as $generique) {
                    if (strpos(self::pliage($generique), $requete) !== false) {
                        $trouve = true;
                        break;
                    }
                }
            }
            if ($trouve) {
                if (count($equipements) < self::MAX_RECHERCHE) {
                    $equipements[] = array(
                        'id'    => $id,
                        'name'  => (string) $eqLogic->getName(),
                        'room'  => $piece,
                        /* Le modèle doit pouvoir dire POURQUOI cet équipement
                         * répond à « caméra » alors que son nom dit « EST ». */
                        'types' => $resume['generiques'],
                    );
                } else {
                    $tronque = true;
                }
            }

            foreach (self::commandesDe($id) as $cmd) {
                if (strpos(self::pliage($cmd->getName()), $requete) === false) {
                    continue;
                }
                if (count($commandes) >= self::MAX_RECHERCHE) {
                    $tronque = true;
                    break;
                }
                if ($cmd->getType() === 'info') {
                    if (!k2000beSecurite::lisible($cmd, $eqLogic)) {
                        continue;
                    }
                    $ligne = self::ligneEtat($cmd);
                    $ligne['device'] = (string) $eqLogic->getName();
                    $ligne['room'] = $piece;
                    $ligne['kind'] = 'state';
                    $commandes[] = $ligne;
                    continue;
                }
                if ($cmd->getType() !== 'action') {
                    continue;
                }
                $politique = k2000beSecurite::politique($cmd);
                if ($cmd->getGeneric_type() === k2000beSecurite::TYPE_IGNORE && $politique === k2000beSecurite::POLITIQUE_REFUS) {
                    /* Même retrait que dans get_equipment : une commande
                     * marquée « ne pas tenir compte » et non autorisée n'a pas
                     * à réapparaître par la porte de la recherche. */
                    continue;
                }
                $ligne = self::ligneAction($cmd, $lectureSeule ? k2000beSecurite::POLITIQUE_REFUS : $politique);
                $ligne['device'] = (string) $eqLogic->getName();
                $ligne['room'] = $piece;
                $ligne['kind'] = 'action';
                $commandes[] = $ligne;
            }
        }

        $resultat = array('devices' => $equipements, 'commands' => $commandes);
        $notes = array();
        if ($tronque) {
            $notes[] = 'Results were truncated. Refine the query.';
        }
        /*
         * La recherche est l'outil par lequel le modèle rencontre le plus
         * souvent une commande dont il ne sait rien : c'est ici que la
         * confusion entre « vide » et « jamais relevé » a produit une fausse
         * réponse, et c'est donc ici qu'il faut le dire aussi.
         */
        if (self::contientJamaisReleve($commandes)) {
            $notes[] = self::NOTE_JAMAIS_RELEVE;
        }
        if (!empty($notes)) {
            $resultat['note'] = implode(' ', $notes);
        }
        if ($lectureSeule) {
            $resultat['mode'] = 'read_only: the assistant is not allowed to run any command at the moment.';
        }

        return self::rendu('search', $resultat,
            sprintf(__('Recherche « %s »', __FILE__), self::argument($_arguments, 'query', '')), 'ok',
            sprintf(__('%d équipement(s), %d commande(s).', __FILE__), count($equipements), count($commandes)));
    }

    private static function getHistory($_arguments) {
        $id = (int) self::argument($_arguments, 'command_id', 0);
        $heures = (int) self::argument($_arguments, 'hours', self::HISTORIQUE_HEURES);
        if ($heures < 1) {
            $heures = self::HISTORIQUE_HEURES;
        }
        if ($heures > self::HISTORIQUE_HEURES_MAX) {
            $heures = self::HISTORIQUE_HEURES_MAX;
        }

        /* Même index que get_states : la commande et son équipement sont déjà
         * en mémoire, et la lisibilité se juge avec les deux. */
        $cmd = ($id > 0) ? self::commande($id) : null;
        $visibles = self::equipementsVisibles();
        $eqId = is_object($cmd) ? (int) $cmd->getEqLogic_id() : 0;
        $eqLogic = isset($visibles[$eqId]) ? $visibles[$eqId] : null;
        if (!is_object($cmd) || !is_object($eqLogic) || !k2000beSecurite::lisible($cmd, $eqLogic)) {
            return self::erreur('get_history', __('Cet historique n\'est pas accessible à l\'assistant.', __FILE__));
        }
        if ((int) $cmd->getIsHistorized() !== 1) {
            return self::rendu('get_history',
                array('status' => 'no_history', 'reason' => 'Jeedom does not record history for this state. Only its current value is available, through get_states.'),
                k2000beSecurite::titre($cmd), 'erreur',
                __('Cet état n\'est pas historisé dans Jeedom.', __FILE__));
        }
        if (!class_exists('history')) {
            return self::erreur('get_history', __('L\'historique n\'est pas disponible sur cette installation.', __FILE__));
        }

        /*
         * Ce que le cœur rend vraiment, et qui change tout.
         *
         * history::all() lit l'union de « history » et de « historyArch ».
         * Passé historyArchiveTime — deux heures par défaut —, le cron du cœur
         * remplace les relevés par des MOYENNES horaires (history.class.php,
         * archive(), GROUP BY sur historyArchivePackage). Sur un capteur qui
         * remonte chaque minute, une demande de vingt-quatre heures rendait
         * donc cent vingt points bruts et vingt-deux moyennes, mélangés : des
         * valeurs jamais mesurées, remises au modèle comme des mesures, et un
         * axe si peu uniforme que cinq sixièmes des points rendus décrivaient
         * les deux dernières heures.
         *
         * D'où deux décisions. Quand la période dépasse l'archivage, on DEMANDE
         * l'agrégation au cœur, sur toute la période et dans la fonction que
         * l'administrateur a choisie pour cette commande : l'axe redevient
         * régulier, et la réponse dit que ce sont des moyennes.
         *
         * Et jamais pour un binaire : le cœur ne le lisse pas — seul le
         * sous-type « numeric » a canBeSmooth —, il n'en archive que les
         * changements. Décimer à pas fixe une porte supprimerait précisément
         * les ouvertures brèves, c'est-à-dire la seule chose qu'on demande à
         * l'historique d'une porte.
         */
        $archivage = self::heuresArchivage();
        $lissable = self::lissableParLeCoeur($cmd);
        $fonction = self::fonctionArchivage($cmd);

        $groupement = null;
        $echantillon = 'raw';
        if ($lissable && $heures > $archivage) {
            /* Une heure par point tant que cela tient dans le budget, un jour
             * au-delà : une semaine en points horaires coûterait cent
             * soixante-huit lignes à chaque tour de la conversation. */
            if ($heures <= self::HISTORIQUE_POINTS) {
                $groupement = $fonction . '::hour';
                $echantillon = 'hourly_' . $fonction;
            } else {
                $groupement = $fonction . '::day';
                $echantillon = 'daily_' . $fonction;
            }
        }

        $debut = date('Y-m-d H:i:s', time() - $heures * 3600);
        $fin = date('Y-m-d H:i:s');
        $lignes = history::all($id, $debut, $fin, $groupement);
        if (!is_array($lignes)) {
            $lignes = array();
        }
        $origine = count($lignes);

        /*
         * L'agrégation a-t-elle réellement eu lieu ? Un cœur qui ignorerait ce
         * quatrième argument — une version plus ancienne, un rejeu hors ligne —
         * rendrait les relevés bruts, et la réponse les présenterait comme des
         * moyennes : exactement le mensonge que tout ceci cherche à éviter. Un
         * nombre de lignes très supérieur au nombre de seaux attendus le dit
         * sans rien supposer d'autre.
         */
        if ($groupement !== null && $origine > 2 * self::seauxAttendus($heures, $groupement)) {
            $groupement = null;
            $echantillon = 'raw';
        }

        if ($groupement !== null) {
            /* Le cœur a déjà fait le travail : un point par seau, l'axe est
             * régulier, il n'y a rien à décimer. */
            $points = self::points($lignes);
        } elseif (!$lissable) {
            /* Brut et non lissable : on ne garde qu'un point par palier. C'est
             * sans perte sur un état — une valeur vaut jusqu'à la suivante —
             * et cela conserve chaque transition, y compris celle qui ne dure
             * qu'une minute. */
            $points = self::points(self::paliers($lignes));
            $echantillon = 'transitions';
        } else {
            /* Brut et dans la fenêtre non archivée : les relevés sont
             * homogènes, un pas fixe garde donc un axe régulier. */
            /* Un point de moins que le budget : le dernier relevé, ajouté
             * hors du pas, doit encore tenir dedans, faute de quoi la coupe
             * finale emporterait le premier point pour rien. */
            $pas = ($origine > self::HISTORIQUE_POINTS) ? (int) ceil($origine / (self::HISTORIQUE_POINTS - 1)) : 1;
            $points = array();
            for ($i = 0; $i < $origine; $i += $pas) {
                $points[] = self::point($lignes[$i]);
            }
            /* Le dernier point compte plus que les autres : c'est lui qui dit
             * où l'on en est, et le pas fixe le saute une fois sur deux. */
            if ($origine > 0 && ($origine - 1) % $pas !== 0) {
                $points[] = self::point($lignes[$origine - 1]);
            }
            if ($pas > 1) {
                $echantillon = 'raw_every_' . $pas;
            }
        }

        /*
         * Dernier filet. Si le compte dépasse encore le budget, ce sont les
         * points les plus RÉCENTS qu'on garde, et la période réellement
         * couverte est rendue avec eux : une série écourtée par le début, sans
         * le dire, ferait conclure à une absence de relevés là où il y en a.
         */
        $ecourte = false;
        if (count($points) > self::HISTORIQUE_POINTS) {
            $points = array_slice($points, -self::HISTORIQUE_POINTS);
            $ecourte = true;
        }

        $resultat = array(
            'command'       => k2000beSecurite::titre($cmd),
            'hours'         => $heures,
            'sampling'      => $echantillon,
            'source_points' => $origine,
            'points'        => $points,
        );
        $unite = trim((string) $cmd->getUnite());
        if ($unite !== '') {
            $resultat['unit'] = $unite;
        }
        if (!empty($points)) {
            $resultat['from'] = $points[0]['t'];
            $resultat['to'] = $points[count($points) - 1]['t'];
        }
        $notes = array();
        if ($groupement !== null) {
            $notes[] = 'These values are averages computed by Jeedom over each bucket, not individual readings: do not quote one of them as a measured value.';
        }
        if ($ecourte) {
            $notes[] = 'Older points were dropped to fit: the series starts at "from", not ' . $heures . ' hours ago.';
        }
        if (!empty($notes)) {
            $resultat['note'] = implode(' ', $notes);
        }

        return self::rendu('get_history', $resultat,
            k2000beSecurite::titre($cmd), 'ok',
            sprintf(__('%d point(s) sur %d heure(s).', __FILE__), count($points), $heures));
    }

    /* Combien de seaux une période doit rendre, au plus, dans le groupement
     * demandé. Sert à vérifier que le cœur a bien agrégé, et rien d'autre. */
    private static function seauxAttendus($_heures, $_groupement) {
        if (substr($_groupement, -5) === '::day') {
            return (int) ceil($_heures / 24) + 2;
        }
        return (int) $_heures + 2;
    }

    /* Un point d'historique tel que le modèle le lit. */
    private static function point($_ligne) {
        return array(
            't' => self::dateCourte($_ligne->getDatetime()),
            'v' => self::abreger((string) $_ligne->getValue()),
        );
    }

    private static function points($_lignes) {
        $points = array();
        foreach ($_lignes as $ligne) {
            $points[] = self::point($ligne);
        }
        return $points;
    }

    /* Ne garde que le premier relevé de chaque palier, et toujours le dernier
     * de la série : sur un état, répéter « fermé » cent fois n'apprend rien,
     * mais l'instant où il passe à « ouvert » est toute l'information. */
    private static function paliers($_lignes) {
        $gardes = array();
        $precedente = null;
        $total = count($_lignes);
        foreach ($_lignes as $rang => $ligne) {
            $valeur = (string) $ligne->getValue();
            if ($precedente === null || $valeur !== $precedente || $rang === ($total - 1)) {
                $gardes[] = $ligne;
                $precedente = $valeur;
            }
        }
        return $gardes;
    }

    /*
     * Au-delà de combien d'heures le cœur a remplacé les relevés par des
     * moyennes. Le réglage est celui du cœur, pas du plugin, et son défaut est
     * celui de core/config/default.config.ini.
     */
    private static function heuresArchivage() {
        $heures = 2;
        try {
            $heures = (int) config::byKey('historyArchiveTime', 'core', 2);
        } catch (Throwable $e) {
            $heures = 2;
        }
        return ($heures < 1) ? 1 : $heures;
    }

    /*
     * Le cœur ne lisse que ce qu'il peut lisser : dans jeedom.config.php, seul
     * le sous-type info « numeric » porte canBeSmooth, et un historizeMode
     * « none » le désactive commande par commande. Tout le reste — binaire,
     * chaîne — est archivé en l'état, transitions comprises.
     */
    private static function lissableParLeCoeur($_cmd) {
        if ((string) $_cmd->getSubType() !== 'numeric') {
            return false;
        }
        return ((string) $_cmd->getConfiguration('historizeMode', 'avg') !== 'none');
    }

    /* La fonction d'agrégation choisie pour cette commande : demander une
     * moyenne d'une commande archivée en maximum mélangerait deux lectures de
     * la même courbe. */
    private static function fonctionArchivage($_cmd) {
        $mode = (string) $_cmd->getConfiguration('historizeMode', 'avg');
        return in_array($mode, array('avg', 'min', 'max'), true) ? $mode : 'avg';
    }

    /*
     * Ce qui s'est passé récemment dans la maison.
     *
     * L'outil existe parce que la réponse à « est-ce que quelqu'un est passé
     * cette nuit ? » n'est pas dans les valeurs mais dans leurs dates, et que
     * les lire une par une coûtait un get_equipment par équipement puis un
     * get_history par capteur. Sur une installation à huit caméras, chacune
     * portant une vingtaine d'états, la question dépensait le plafond d'outils
     * entier pour relire cent cinquante zéros.
     *
     * Il ne connaît aucun plugin par son nom, et c'est délibéré. Une caméra, un
     * contact de porte, un détecteur de fuite et un capteur de fumée remontent
     * ici par la même porte : un état binaire, historisé, lisible, dont la date
     * de bascule tombe dans la fenêtre demandée. Une installation sans
     * vidéosurveillance obtient donc ses portes et ses fuites, et une
     * installation qui n'historise rien obtient une liste vide — jamais une
     * erreur, et rien à afficher.
     *
     * L'ordre des contrôles n'est pas indifférent. estEvenementiel() ne lit ni
     * base ni cache : il écarte l'immense majorité des commandes pour rien. Le
     * droit de lecture passe AVANT la date : la date d'un état qu'on n'a pas le
     * droit de lire est déjà une information sur la maison — savoir qu'un
     * capteur masqué a bougé cette nuit en dit assez.
     */
    /*
     * Le verdict, calculé ici et non par le modèle.
     *
     * Une consigne écrite en toutes lettres — « ne te fie qu'aux détections
     * croisées » — reste une consigne. Le modèle la suit le plus souvent ;
     * puis un jour il additionne trois mouvements isolés et annonce une
     * intrusion, ou lit « Humain détecté : 0 » et annonce le calme. Quand la
     * réponse engage la sécurité du logement, « le plus souvent » ne suffit
     * pas.
     *
     * La règle du plugin s'applique donc à la lecture comme elle s'appliquait
     * déjà à l'action : le modèle demande, le plugin décide. Il ne reçoit plus
     * des faits à peser, il reçoit une conclusion à formuler, et les états qui
     * l'ont produite pour pouvoir la dire.
     *
     * Trois verdicts, et le troisième compte autant que les deux autres :
     *  - CONFIRMED : au moins un état décisif a basculé dans la fenêtre ;
     *  - NOTHING   : aucun, et il y avait bien des états décisifs à surveiller
     *                — c'est un silence constaté, pas un silence supposé ;
     *  - UNKNOWN   : aucun état décisif lisible. Rien n'a été surveillé, donc
     *                rien ne peut être conclu. Sans ce cas, une déclaration de
     *                travers ou une autorisation retirée rendraient « rien à
     *                signaler » sur une maison que personne ne regarde.
     *
     * Le contexte accompagne le verdict sans jamais le faire : c'est ce qui
     * permet de dire « rien d'avéré, mais les caméras ont vu du mouvement au
     * nord » sans que le mouvement devienne une preuve.
     */
    /*
     * Le balayage des états événementiels, partagé par l'outil de verdict et
     * par la surveillance automatique.
     *
     * Il existe en un seul exemplaire parce qu'il porte les contrôles de
     * sécurité : équipement visible, commande lisible. Les écrire deux fois,
     * c'est les voir diverger le jour où l'un des deux appelants change — et
     * ce jour-là, un capteur masqué réveillerait l'assistant en pleine nuit
     * alors que l'outil, lui, refuserait toujours de le nommer.
     *
     * $_depuis à 0 ne borne rien : c'est ce que demande la surveillance, qui
     * compare des dates entre elles plutôt qu'à une fenêtre.
     */
    public static function balayage($_types, $_depuis = 0) {
        $types = is_array($_types) ? $_types : array();
        $decisifs = array();
        $contexte = array();
        $surveilles = 0;

        foreach (self::equipementsVisibles() as $eqId => $eqLogic) {
            $nomPiece = self::piece($eqLogic);
            foreach (self::commandesDe($eqId) as $cmd) {
                if ($cmd->getType() !== 'info' || !self::estEvenementiel($cmd)) {
                    continue;
                }
                /* Le droit de lecture avant tout le reste, ici comme dans
                 * get_events : un état qu'on n'a pas le droit de lire ne peut
                 * pas davantage décider d'un verdict, ni déclencher une
                 * alerte. */
                if (!k2000beSecurite::lisible($cmd, $eqLogic)) {
                    continue;
                }

                $decisif = in_array((string) $cmd->getGeneric_type(), $types, true);
                if ($decisif) {
                    /* Compté même s'il n'a jamais rien porté : une règle qui
                     * n'a jamais tiré est une règle surveillée, et c'est ce qui
                     * distingue NOTHING d'UNKNOWN. */
                    $surveilles++;
                }

                $brute = self::dateEvenementBrute($cmd);
                if ($brute === '') {
                    continue;
                }
                $horodatage = strtotime($brute);
                if ($horodatage === false || $horodatage < $_depuis) {
                    continue;
                }
                $date = self::dateCourte($brute);

                $valeur = self::valeur($cmd);
                if ($valeur === null || $valeur === '') {
                    $quoi = 'changed';
                } else {
                    $quoi = ($valeur === '1' || $valeur === 'true') ? 'started' : 'ended';
                }
                $ligne = array(
                    'at'         => $date,
                    'room'       => $nomPiece,
                    'equipment'  => (string) $eqLogic->getName(),
                    'name'       => (string) $cmd->getName(),
                    'what'       => $quoi,
                    'command_id' => (int) $cmd->getId(),
                    'ts'         => $horodatage,
                );
                if ($decisif) {
                    $decisifs[] = $ligne;
                } else {
                    $contexte[] = $ligne;
                }
            }
        }

        return array('surveilles' => $surveilles, 'decisifs' => $decisifs, 'contexte' => $contexte);
    }

    private static function verdict($_arguments, $_eqLogic) {
        $types = self::typesDecisifs($_eqLogic);
        if ($types === array()) {
            return self::erreur('check_alert',
                __('Aucun type d\'état décisif n\'est déclaré pour cet assistant.', __FILE__));
        }

        $heures = (int) self::argument($_arguments, 'hours', self::VERDICT_HEURES);
        if ($heures < 1) {
            $heures = self::VERDICT_HEURES;
        }
        if ($heures > self::EVENEMENTS_HEURES_MAX) {
            $heures = self::EVENEMENTS_HEURES_MAX;
        }

        $balayage = self::balayage($types, time() - $heures * 3600);
        $decisifs = $balayage['decisifs'];
        $contexte = $balayage['contexte'];
        $surveilles = $balayage['surveilles'];

        $recent = function ($_a, $_b) {
            if ($_a['ts'] === $_b['ts']) {
                return 0;
            }
            return ($_a['ts'] > $_b['ts']) ? -1 : 1;
        };
        usort($decisifs, $recent);
        usort($contexte, $recent);

        $contexteTotal = count($contexte);
        $contexte = array_slice($contexte, 0, self::VERDICT_CONTEXTE_MAX);
        foreach ($decisifs as $rang => $ligne) {
            unset($decisifs[$rang]['ts']);
        }
        foreach ($contexte as $rang => $ligne) {
            unset($contexte[$rang]['ts']);
        }

        if ($surveilles === 0) {
            $rendu = 'UNKNOWN';
        } elseif (count($decisifs) > 0) {
            $rendu = 'CONFIRMED';
        } else {
            $rendu = 'NOTHING';
        }

        $resultat = array(
            'verdict'      => $rendu,
            'window_hours' => $heures,
            'watched'      => $surveilles,
            'deciding'     => array_values($decisifs),
            'context'      => array_values($contexte),
        );
        if ($contexteTotal > count($contexte)) {
            $resultat['more_context'] = $contexteTotal - count($contexte);
        }

        $notes = array(
            'The verdict above was computed by the plugin, from the only states the administrator declared as deciding. Report it as it stands: you may phrase it, you may not change it, and you may not argue with it.',
            'The events under "context" are descriptive only. They NEVER make a verdict, however many of them there are — that is precisely why this tool exists.',
        );
        if ($rendu === 'UNKNOWN') {
            $notes[] = 'UNKNOWN does not mean quiet: it means nothing was being watched, so nothing can be concluded. Say exactly that.';
        } elseif ($rendu === 'NOTHING') {
            $notes[] = 'NOTHING means the ' . $surveilles . ' deciding state(s) stayed silent over that window. If "context" is not empty, you may mention what the sensors saw, while making it plain that nothing is established.';
        } else {
            $notes[] = 'CONFIRMED: name the deciding events, where and how long ago.';
        }
        $resultat['note'] = implode(' ', $notes);

        return self::rendu('check_alert', $resultat,
            __('Levée de doute', __FILE__), 'ok',
            sprintf(__('%s — %d état(s) décisif(s) surveillé(s) sur %d heures.', __FILE__),
                $rendu, $surveilles, $heures));
    }

    private static function evenements($_arguments) {
        $heures = (int) self::argument($_arguments, 'hours', self::EVENEMENTS_HEURES);
        if ($heures < 1) {
            $heures = self::EVENEMENTS_HEURES;
        }
        if ($heures > self::EVENEMENTS_HEURES_MAX) {
            $heures = self::EVENEMENTS_HEURES_MAX;
        }
        $piece = self::pliage((string) self::argument($_arguments, 'room', ''));
        $depuis = time() - $heures * 3600;

        $lignes = array();
        foreach (self::equipementsVisibles() as $eqId => $eqLogic) {
            $nomPiece = self::piece($eqLogic);
            if ($piece !== '' && strpos(self::pliage($nomPiece), $piece) === false) {
                continue;
            }
            foreach (self::commandesDe($eqId) as $cmd) {
                if ($cmd->getType() !== 'info' || !self::estEvenementiel($cmd)) {
                    continue;
                }
                if (!k2000beSecurite::lisible($cmd, $eqLogic)) {
                    continue;
                }
                $brute = self::dateEvenementBrute($cmd);
                if ($brute === '') {
                    continue;
                }
                $horodatage = strtotime($brute);
                if ($horodatage === false || $horodatage < $depuis) {
                    continue;
                }
                $date = self::dateCourte($brute);
                /*
                 * « what » et non « value », et c'est tout le sujet.
                 *
                 * La ligne rendait la valeur APRÈS le changement. Une
                 * détection humaine qui s'allume à 17 h 21 et retombe à
                 * 17 h 22 sortait donc en « Humain détecté : 0, à 17 h 22 » —
                 * et le modèle, lisant zéro, a répondu au propriétaire que
                 * personne n'était passé. C'était exactement le contraire de
                 * ce que la ligne racontait.
                 *
                 * Une valeur d'état est une réponse à « où en est-on » ; un
                 * événement répond à « que s'est-il passé ». Mélanger les deux
                 * dans la même clé produit la pire des réponses : fausse, et
                 * argumentée.
                 */
                $valeur = self::valeur($cmd);
                if ($valeur === null || $valeur === '') {
                    $quoi = 'changed';
                } else {
                    $quoi = ($valeur === '1' || $valeur === 'true') ? 'started' : 'ended';
                }

                $lignes[] = array(
                    'at'           => $date,
                    'room'         => $nomPiece,
                    'equipment'    => (string) $eqLogic->getName(),
                    'equipment_id' => (int) $eqId,
                    'command_id'   => (int) $cmd->getId(),
                    'name'         => (string) $cmd->getName(),
                    'what'         => $quoi,
                    /* Le tri se fait sur l'horodatage et non sur la chaîne :
                     * deux formats de date dans la même installation — un
                     * plugin qui écrit sans les secondes — se trieraient
                     * autrement dans l'ordre alphabétique. */
                    'ts'           => $horodatage,
                );
            }
        }

        usort($lignes, function ($_a, $_b) {
            if ($_a['ts'] === $_b['ts']) {
                return 0;
            }
            return ($_a['ts'] > $_b['ts']) ? -1 : 1;
        });

        $total = count($lignes);
        $lignes = array_slice($lignes, 0, self::EVENEMENTS_MAX);
        foreach ($lignes as $rang => $ligne) {
            unset($lignes[$rang]['ts']);
        }

        $resultat = array(
            'window_hours' => $heures,
            'events'       => array_values($lignes),
        );
        $notes = array();
        if ($total > 0) {
            $notes[] = 'How to read these: "started" means the state turned on at that moment and is still on. "ended" means it turned OFF then — so the thing itself happened just BEFORE that time, and it did happen. An "ended" detection is a detection that took place; never read it as "nothing happened". Only the LAST change of each state is listed: call get_history on a command_id to see the earlier ones.';
        }
        if ($total > count($lignes)) {
            $resultat['more'] = $total - count($lignes);
            $notes[] = 'Only the ' . count($lignes) . ' most recent of ' . $total . ' events are listed; more says how many were left out. Narrow the window or the room rather than asking again.';
        }
        if ($total === 0) {
            /*
             * « Rien n'est arrivé » et « rien n'est surveillé » se ressemblent
             * beaucoup vus d'ici. Sans cette phrase, le modèle conclut au calme
             * sur une maison qui n'a simplement aucun état historisé.
             */
            $notes[] = 'Nothing was recorded during that window. This does not prove the home was quiet: only states kept in history appear here, and there may be none.';
        }
        if (!empty($notes)) {
            $resultat['note'] = implode(' ', $notes);
        }

        return self::rendu('get_events', $resultat,
            __('Ce qui s\'est passé récemment', __FILE__), 'ok',
            sprintf(__('%d événement(s) sur %d heures.', __FILE__), $total, $heures));
    }

    /*
     * Un budget d'identifiants introuvables par conversation a été envisagé
     * ici, pour qu'un modèle ne puisse pas brûler ses dix appels d'outils à
     * énumérer des identifiants. Il n'y est pas, et c'est délibéré : cette
     * classe ne connaît pas la conversation. Ses deux seules mémoires sont des
     * index de travail qui vivent le temps d'une requête PHP, c'est-à-dire
     * d'un tour. Un compteur posé là ne serait pas « par conversation » mais
     * « par tour », remis à zéro à chaque phrase de l'utilisateur, et il
     * refuserait au passage des identifiants légitimes au milieu d'un tour un
     * peu long. Inventer ici un état de conversation reviendrait à en tenir
     * un second, à côté de celui qui existe déjà.
     *
     * La conversation vit dans k2000be.class.php, qui compte déjà les outils
     * exécutés d'un tour et tient l'historique d'un bout à l'autre : c'est là,
     * et nulle part ailleurs, qu'un tel plafond aurait un sens.
     *
     * Ce qui ferme la porte de l'énumération n'est de toute façon pas un
     * plafond mais le refus indistinct de k2000beSecurite::controler() :
     * depuis qu'il rend le même texte pour un identifiant inventé et pour un
     * équipement masqué, énumérer ne rapporte plus rien à énumérer.
     */
    private static function executeCommand($_arguments) {
        $id = (int) self::argument($_arguments, 'command_id', 0);
        if ($id <= 0) {
            return self::erreur('execute_command', __('Aucune commande n\'a été désignée.', __FILE__));
        }
        $valeur = self::argument($_arguments, 'value', null);
        /*
         * Le couple titre + message, que la normalisation sait lire mais
         * qu'aucun appel conforme au schéma ne pouvait produire : le schéma
         * n'accepte qu'une chaîne pour « value », et le sous-type message était
         * donc inatteignable autrement qu'en envoyant un objet JSON sérialisé
         * en guise de corps, ou en perdant le titre. Deux champs plats, que le
         * modèle sait remplir, et le couple se recompose ici.
         */
        $titre = trim((string) self::argument($_arguments, 'title', ''));
        if ($titre !== '' && !is_array($valeur) && self::attendUnMessage($id)) {
            $valeur = array('title' => $titre, 'message' => (string) $valeur);
        }
        return self::appliquer(k2000beSecurite::controler($id, $valeur), false, $valeur);
    }

    /*
     * Cette commande attend-elle un message ? Un titre envoyé à une commande
     * qui n'en attend pas est simplement ignoré : le composer avec la valeur
     * ferait refuser un slider sur « attend une valeur numérique », ce qui
     * enverrait le modèle corriger un nombre parfaitement correct.
     *
     * Aucune fuite ici : la réponse ne dépend que du sous-type, et un
     * identifiant hors de portée rend false comme un identifiant inventé.
     * C'est controler() qui décide, juste après.
     */
    private static function attendUnMessage($_id) {
        $cmd = self::commande($_id);
        return (is_object($cmd) && (string) $cmd->getSubType() === 'message');
    }

    /*
     * Ce qui suit le verdict. Rien n'y est décidé : la décision vient de
     * controler(), et les motifs qu'elle porte sont recopiés tels quels. Deux
     * formulations du même refus finiraient par se contredire.
     *
     * « Tels quels » veut dire deux textes et non un seul, parce qu'un refus a
     * deux lecteurs : la frise et le journal reçoivent le motif vrai, le
     * résultat de l'outil reçoit celui que controler() destine au modèle. Voir
     * k2000beSecurite::verdict(), qui explique pourquoi les deux diffèrent sur
     * un équipement masqué.
     */
    private static function appliquer($_controle, $_confirme, $_valeurDemandee = null) {
        $titre = $_controle['titre'];
        $motif = $_controle['motif'];

        if ($_controle['decision'] === k2000beSecurite::POLITIQUE_REFUS) {
            /*
             * Le titre suit la même règle que le motif, sans quoi il dirait à
             * sa place ce que le motif tait : « Coffre-fort — Ouvrir » est un
             * nom d'équipement masqué tout autant que la phrase qui
             * l'accompagne. Sur un refus indistinct, controler() rend ici un
             * « Commande 42 » qui ne nomme que ce que le modèle a envoyé.
             *
             * Reste un chemin où le motif vrai repart tout de même au modèle :
             * le rejeu après confirmation, où k2000be::executerConfirmee()
             * recopie l'étape dans un message système. Il ne lui apprend rien
             * qu'il ne sache déjà : pour en arriver là, il faut que la commande
             * ait été autorisée sous confirmation au tour précédent, donc
             * nommée.
             */
            $titreModele = isset($_controle['titre_modele']) ? $_controle['titre_modele'] : $titre;
            $motifModele = isset($_controle['motif_modele']) ? $_controle['motif_modele'] : $motif;
            return self::rendu('execute_command',
                array('status' => 'refused', 'command' => $titreModele, 'reason' => $motifModele),
                $titre, 'refus', $motif);
        }

        if ($_controle['decision'] === k2000beSecurite::POLITIQUE_CONFIRMATION && !$_confirme) {
            $demande = array(
                'command_id' => (int) $_controle['cmd']->getId(),
                'titre'      => $titre,
                'valeur'     => $_controle['valeur'],
                'motif'      => $motif,
            );
            return self::rendu('execute_command',
                array(
                    'status'  => 'confirmation_required',
                    'command' => $titre,
                    'reason'  => $motif,
                    'next'    => 'Stop here. Do not call any other tool. Tell the user, in one sentence, what you are about to do and that you are waiting for their confirmation.',
                ),
                $titre, 'confirmation', $motif, $demande);
        }

        $cmd = $_controle['cmd'];
        $options = k2000beSecurite::options($cmd, $_controle['valeur']);

        /*
         * La valeur proposée à une commande qui n'en prend pas — un bouton, un
         * sous-type « other » — est ignorée par la normalisation, et l'était
         * en silence : le modèle rapportait alors « j'ai réglé le mode sur
         * 30 » à propos d'une commande partie nue. Ignorer est le bon choix ;
         * le taire ne l'est pas.
         */
        $ignoree = ($options === null
            && $_valeurDemandee !== null && $_valeurDemandee !== ''
            && !is_array($_valeurDemandee));

        if (k2000beSecurite::mode() === k2000beSecurite::MODE_SIMULATION) {
            /* Aucun execCmd() ici, et c'est tout l'intérêt du mode : la
             * maison ne bouge pas. Le modèle n'est pas trompé pour autant —
             * la note ci-dessous lui dit que rien n'est parti et qu'il doit
             * l'annoncer comme une simulation. Le laisser croire qu'il a agi
             * lui ferait rapporter à l'utilisateur une action faite, et
             * l'utilisateur le croirait : le mode servirait alors à produire
             * exactement le mensonge qu'il est censé éviter. Ce que
             * l'utilisateur voit, lui, dans la frise, c'est ce qui SERAIT
             * parti — de quoi éprouver ses autorisations sans rien risquer. */
            return self::rendu('execute_command',
                array(
                    'status'  => 'simulated',
                    'command' => $titre,
                    'note'    => 'Simulation mode: the plugin allowed this command but sent nothing to the home. Report it to the user as simulated, not as done.'
                        . ($ignoree ? ' ' . self::NOTE_VALEUR_IGNOREE : ''),
                ),
                $titre, 'simule',
                sprintf(__('Simulation : « %s » n\'est pas partie.', __FILE__), $titre));
        }

        $cmd->execCmd($options);

        /*
         * « sent » et non « done ». Jeedom rend la main dès que la commande
         * est partie vers le plugin propriétaire ; l'ampoule, elle, peut être
         * hors tension. Laisser le modèle annoncer « c'est fait » sur cette
         * seule base est la façon la plus sûre de lui faire dire des choses
         * fausses avec aplomb.
         *
         * La note dit désormais COMMENT vérifier, et pas seulement qu'il faut
         * le faire. Sans le « wait », le modèle relisait l'état une seconde
         * après l'envoi, y trouvait la valeur d'avant, et annonçait un échec
         * qui n'avait pas eu lieu : la consigne de vérifier produisait
         * exactement le mensonge qu'elle devait éviter.
         */
        return self::rendu('execute_command',
            array(
                'status'  => 'sent',
                'command' => $titre,
                'note'    => 'The command was sent. This is not proof that it took effect: read the matching state with get_states, passing wait: 3 to 5, before telling the user it is done. Reading it straight away would show you the value from before the command.'
                    . ($ignoree ? ' ' . self::NOTE_VALEUR_IGNOREE : ''),
            ),
            $titre, 'ok', sprintf(__('Commande envoyée : %s.', __FILE__), $titre));
    }

    /* ================================================ RÉSUMÉ DE LA MAISON */

    /*
     * Le texte joint à l'invite système quand contexte_maison vaut 1. Il repart
     * à chaque tour : il doit tenir en quelques lignes, donner des repères
     * (les pièces, l'ordre de grandeur) et surtout rappeler au modèle qu'il ne
     * voit pas tout.
     */
    public static function resumeMaison() {
        try {
            $pieces = array();
            $total = 0;
            foreach (self::equipementsVisibles() as $eqLogic) {
                $piece = self::piece($eqLogic);
                if (!isset($pieces[$piece])) {
                    $pieces[$piece] = 0;
                }
                $pieces[$piece]++;
                $total++;
            }
            arsort($pieces);
            $citees = array_slice($pieces, 0, self::RESUME_PIECES, true);

            $morceaux = array();
            foreach ($citees as $nom => $nombre) {
                $morceaux[] = $nom . ' (' . $nombre . ')';
            }
            $reste = count($pieces) - count($citees);
            if ($reste > 0) {
                $morceaux[] = sprintf(__('et %d autre(s) pièce(s)', __FILE__), $reste);
            }

            /* Ces compteurs tiennent compte du mode : en lecture seule, ils
             * annoncent zéro commande exécutable, ce qui est la vérité et ce
             * que les outils répondront. Reste à dire pourquoi, sans quoi le
             * modèle croirait la maison dépourvue de commandes. */
            $compteurs = k2000beSecurite::compteurs();
            $lectureSeule = (k2000beSecurite::mode() === k2000beSecurite::MODE_LECTURE);

            $texte = sprintf(__('La maison compte %d équipement(s) visibles par l\'assistant, répartis ainsi : %s.', __FILE__),
                    $total, implode(', ', $morceaux))
                . "\n"
                . sprintf(__('Autorisations en vigueur : %d commande(s) exécutables, %d sous confirmation, %d interdites, %d états lisibles.', __FILE__),
                    $compteurs['autorisees'], $compteurs['confirmation'], $compteurs['interdites'], $compteurs['lisibles'])
                . "\n";
            if ($lectureSeule) {
                $texte .= __('L\'assistant est réglé en lecture seule : aucune commande ne peut partir, quelles que soient les autorisations de chacune.', __FILE__)
                    . "\n";
            }
            $texte .= __('Ce décompte est indicatif : seuls les outils disent ce qui existe réellement.', __FILE__);

            return $texte;
        } catch (Throwable $e) {
            /* Un résumé impossible ne doit pas empêcher la conversation : le
             * modèle se débrouillera avec list_rooms. */
            return '';
        }
    }

    /* ================================================== MISE EN FORME */

    private static function rendu($_nom, $_resultat, $_titre, $_statut = 'ok', $_detail = '', $_attente = null) {
        return array(
            'resultat' => $_resultat,
            'etape'    => array(
                'outil'  => $_nom,
                'titre'  => $_titre,
                'statut' => $_statut,
                'detail' => $_detail,
            ),
            'attente'  => $_attente,
        );
    }

    private static function erreur($_nom, $_message) {
        return self::rendu($_nom,
            array('status' => 'error', 'reason' => $_message),
            __('Outil en erreur', __FILE__), 'erreur', $_message);
    }

    /* Une ligne d'action, telle que le modèle la lit. La politique y est
     * traduite en anglais et en clair : « deny » ne lui dit rien, alors que
     * « forbidden » lui fait produire la bonne phrase à l'utilisateur. */
    private static function ligneAction($_cmd, $_politique) {
        $politiques = array(
            k2000beSecurite::POLITIQUE_AUTORISE     => 'allowed',
            k2000beSecurite::POLITIQUE_CONFIRMATION => 'needs_user_confirmation',
            k2000beSecurite::POLITIQUE_REFUS        => 'forbidden',
        );

        $ligne = array(
            'id'     => (int) $_cmd->getId(),
            'name'   => (string) $_cmd->getName(),
            'policy' => isset($politiques[$_politique]) ? $politiques[$_politique] : 'forbidden',
        );
        /* Une clé vide se paie sur chaque ligne et n'apprend rien : le fichier
         * omet déjà « expects » sur un bouton simple pour ce motif. */
        $generique = (string) $_cmd->getGeneric_type();
        if ($generique !== '') {
            $ligne['generic'] = $generique;
        }

        /*
         * « expects » : le TYPE attendu, et non « value ». Le paramètre de
         * execute_command s'appelle value lui aussi, et un modèle qui recopiait
         * ce qu'il venait de lire envoyait value: "number" — accepté, sur un
         * select sans liste de choix, et transmis à la maison.
         */
        switch ((string) $_cmd->getSubType()) {
            case 'slider':
                $ligne['expects'] = 'number';
                $min = $_cmd->getConfiguration('minValue', '');
                $max = $_cmd->getConfiguration('maxValue', '');
                if ($min !== '' && $min !== null) {
                    $ligne['min'] = $min + 0;
                }
                if ($max !== '' && $max !== null) {
                    $ligne['max'] = $max + 0;
                }
                break;
            case 'select':
                $ligne['expects'] = 'choice';
                $choix = self::choix($_cmd);
                if (!empty($choix)) {
                    $ligne['choices'] = $choix;
                }
                break;
            case 'color':
                $ligne['expects'] = 'color';
                break;
            case 'message':
                $ligne['expects'] = 'message';
                break;
            default:
                /* Bouton simple : la clé est omise plutôt que rendue à
                 * « none », ce qui la ferait payer sur chaque ligne. */
                break;
        }
        return $ligne;
    }

    /*
     * Une ligne d'état, telle que le modèle la lit. Le type générique
     * l'accompagne quand il existe : sans lui, « value: 1 » ne dit pas si 1
     * veut dire ouvert ou fermé.
     */
    private static function ligneEtat($_cmd) {
        $ligne = array(
            'id'    => (int) $_cmd->getId(),
            'name'  => (string) $_cmd->getName(),
            'value' => self::valeur($_cmd),
        );
        $unite = trim((string) $_cmd->getUnite());
        if ($unite !== '') {
            $ligne['unit'] = $unite;
        }
        $generique = (string) $_cmd->getGeneric_type();
        if ($generique !== '') {
            $ligne['generic'] = $generique;
        }
        /*
         * L'horodatage n'accompagne la valeur que lorsqu'il apprend quelque
         * chose. Une température relevée il y a trois minutes n'a pas besoin
         * d'être datée ; un capteur muet depuis trois ans, si — sans quoi il se
         * lit comme courant, et le modèle en parle au présent.
         */
        /*
         * Aucune date, ni de lecture ni de changement : cette commande n'a
         * jamais rien porté. Le dire explicitement est le seul moyen pour le
         * modèle de distinguer « la porte est fermée » de « personne n'a
         * jamais rien dit de cette porte ». Voir NOTE_JAMAIS_RELEVE.
         */
        if (self::dateDeLecture($_cmd) === '') {
            $ligne['never_read'] = true;
        }

        $perime = self::datePerimee($_cmd);
        if ($perime !== '') {
            $ligne['updated'] = $perime;
            $ligne['stale'] = true;
        }

        /*
         * L'état dont la DATE est l'information.
         *
         * La règle du dessus vaut pour une mesure : une température relevée il
         * y a trois minutes n'a pas besoin d'être datée. Elle est exactement
         * fausse pour un événement. « Ligne franchie : 1 » ne dit pas qu'on
         * franchit la ligne, il dit qu'on l'a franchie — et le modèle, faute de
         * date, en parle au présent. Le cas n'est pas théorique : un plugin de
         * vidéosurveillance qui reçoit une détection ponctuelle lève sa
         * commande à 1 et rien ne la redescend, faute d'événement de fin. Trois
         * semaines plus tard, l'assistant annonce toujours un intrus.
         *
         * « since » porte donc la date du dernier CHANGEMENT, et non celle de
         * la dernière lecture : un contact de porte interrogé toutes les
         * minutes est lu à l'instant et ouvert depuis hier soir, et c'est
         * « hier soir » que l'on veut lire.
         */
        $depuis = self::dateEvenement($_cmd);
        if ($depuis !== '' && $depuis !== $perime) {
            $ligne['since'] = $depuis;
        }
        return $ligne;
    }

    /*
     * Vrai pour un état qui raconte un événement plutôt qu'une mesure.
     *
     * Le critère est binaire ET historisé, et il n'est pas arbitraire :
     * historiser un état, c'est dire que son évolution dans le temps mérite
     * d'être conservée — donc que l'instant où il a basculé compte. C'est un
     * réglage de l'administrateur, pas une devinette du plugin sur le nom ou le
     * type générique de la commande, et il se corrige là où il se lit : une
     * détection d'incendie qu'on veut voir ici s'historise dans Jeedom.
     *
     * Le contrôle ne lit rien : ni cache, ni base. C'est ce qui permet de le
     * poser avant tout le reste quand on parcourt la maison entière.
     */
    private static function estEvenementiel($_cmd) {
        try {
            return ((string) $_cmd->getSubType() === 'binary' && (int) $_cmd->getIsHistorized() === 1);
        } catch (Throwable $e) {
            return false;
        }
    }

    /*
     * La date du dernier changement d'un état événementiel, ou rien du tout.
     *
     * getValueDate() d'abord — c'est la date de bascule — et la date de lecture
     * seulement à défaut : une commande jamais relue depuis l'installation n'a
     * pas de date de changement, et rendre une chaîne vide vaudrait moins que
     * la seule date connue.
     */
    private static function dateEvenement($_cmd) {
        return self::dateCourte(self::dateEvenementBrute($_cmd));
    }

    /*
     * La même date, mais entière.
     *
     * dateCourte() coupe les secondes, ce qui convient à l'affichage et pas du
     * tout à la comparaison : la surveillance automatique compare la date d'un
     * basculement à celle du dernier qu'elle a vu, et deux dates tronquées à la
     * même minute se valent. Un déclenchement survenu trente secondes après le
     * précédent disparaissait ainsi sans laisser de trace — et le repère
     * n'avançait pas, si bien que le repos se terminait sur un événement déjà
     * périmé.
     */
    private static function dateEvenementBrute($_cmd) {
        if (!self::estEvenementiel($_cmd)) {
            return '';
        }
        $date = '';
        try {
            $date = trim((string) $_cmd->getValueDate());
        } catch (Throwable $e) {
            $date = '';
        }
        if ($date === '') {
            $date = self::dateDeLecture($_cmd);
        }
        return $date;
    }

    /*
     * Les choix d'un select, bornés en nombre ET en longueur. Un plugin tiers
     * peut mettre des phrases entières dans listValue, et cette liste part dans
     * l'historique de la conversation, où elle se repaie à chaque tour.
     */
    private static function choix($_cmd) {
        $liste = trim((string) $_cmd->getConfiguration('listValue', ''));
        if ($liste === '') {
            return array();
        }
        $choix = array();
        $longueur = 0;
        foreach (explode(';', $liste) as $element) {
            $couple = explode('|', $element);
            $valeur = trim($couple[0]);
            if ($valeur === '') {
                continue;
            }
            if (mb_strlen($valeur) > self::CHOIX_TEXTE_MAX) {
                $valeur = mb_substr($valeur, 0, self::CHOIX_TEXTE_MAX - 1) . '…';
            }
            $longueur += mb_strlen($valeur) + 2;
            if ($longueur > self::CHOIX_TOTAL_MAX) {
                break;
            }
            $choix[] = $valeur;
            if (count($choix) >= self::CHOIX_MAX) {
                break;
            }
        }
        return $choix;
    }

    /* Valeur courante d'un état, abrégée. Aucun contrôle d'autorisation ici :
     * l'appelant a déjà vérifié la lisibilité, et l'y remettre donnerait
     * l'illusion que cette méthode protège quelque chose.
     *
     * Rend null — et non la chaîne vide — quand la commande n'a JAMAIS été
     * renseignée : un capteur qui n'a jamais rien remonté et un capteur qui
     * remonte une chaîne vide se lisaient pareil, et le modèle concluait de
     * l'un comme de l'autre que la maison répond. En JSON, null se distingue
     * de "" sans coûter un mot de plus. */
    private static function valeur($_cmd) {
        try {
            $valeur = $_cmd->execCmd();
        } catch (Throwable $e) {
            return null;
        }
        if ($valeur === null) {
            return null;
        }
        if (is_bool($valeur)) {
            return $valeur ? '1' : '0';
        }
        if (is_array($valeur)) {
            $valeur = json_encode($valeur, JSON_UNESCAPED_UNICODE);
        }
        return self::abreger((string) $valeur);
    }

    /*
     * La date qui répond à « est-ce frais ».
     *
     * Le cœur en tient deux : valueDate, date du dernier CHANGEMENT, et
     * collectDate, date de la dernière LECTURE (cmd.class.php, event() :
     * collectDate est posée à chaque relevé, valueDate seulement quand la
     * valeur diffère de la précédente). L'ancienne réponse employait la
     * première, si bien qu'un thermostat stable depuis six heures passait pour
     * périmé alors qu'il parle toutes les minutes.
     *
     * Le repli sur valueDate n'est pas de la précaution de style : cette classe
     * doit rester utilisable par un cœur factice, qui peut ne pas connaître
     * collectDate.
     */
    private static function dateDeLecture($_cmd) {
        try {
            $date = trim((string) $_cmd->getCollectDate());
            if ($date !== '') {
                return $date;
            }
        } catch (Throwable $e) {
            /* Rien : on retombe sur la date de changement. */
        }
        try {
            return trim((string) $_cmd->getValueDate());
        } catch (Throwable $e) {
            return '';
        }
    }

    /* La date de dernière lecture, mais seulement si elle est assez vieille
     * pour changer la lecture de la valeur. Chaîne vide sinon : une date de
     * plus sur chaque ligne se paie à chaque tour. */
    private static function datePerimee($_cmd) {
        $date = self::dateDeLecture($_cmd);
        if ($date === '') {
            return '';
        }
        $horodatage = strtotime($date);
        if ($horodatage === false || $horodatage >= (time() - self::FRAICHEUR_HEURES * 3600)) {
            return '';
        }
        return self::dateCourte($date);
    }

    /* Vrai dès qu'une ligne d'état du lot n'a jamais rien porté : la note
     * générale ne part qu'alors, elle se paierait sinon à chaque lecture. */
    private static function contientJamaisReleve($_lignes) {
        foreach ($_lignes as $ligne) {
            if (is_array($ligne) && isset($ligne['never_read'])) {
                return true;
            }
        }
        return false;
    }

    private static function abreger($_texte) {
        $texte = trim((string) $_texte);
        if (mb_strlen($texte) > self::VALEUR_MAX) {
            return mb_substr($texte, 0, self::VALEUR_MAX - 1) . '…';
        }
        return $texte;
    }

    private static function dateCourte($_date) {
        $date = trim((string) $_date);
        if ($date === '') {
            return '';
        }
        $horodatage = strtotime($date);
        return ($horodatage === false) ? $date : date('Y-m-d H:i', $horodatage);
    }

    /* ==================================================== INDEX DE TRAVAIL */

    /*
     * Les équipements que l'assistant a le droit de voir, indexés par
     * identifiant. Le filtre est celui de k2000beSecurite : masqués,
     * désactivés et assistants n'entrent jamais dans cette liste, et tout ce
     * qui sort de cette classe part de là.
     */
    private static function equipementsVisibles() {
        if (self::$visibles === null) {
            self::$visibles = array();
            foreach (k2000beSecurite::equipements() as $eqLogic) {
                if (k2000beSecurite::equipementVisible($eqLogic)) {
                    self::$visibles[(int) $eqLogic->getId()] = $eqLogic;
                }
            }
        }
        return self::$visibles;
    }

    /*
     * Les commandes rangées par équipement, en une seule lecture de la table.
     * Une conversation enchaîne cinq à dix outils : les relire équipement par
     * équipement ferait des centaines de requêtes pour une seule phrase de
     * l'utilisateur.
     */
    private static function commandesDe($_eqId) {
        if (self::$parEquipement === null) {
            self::$parEquipement = array();
            foreach (k2000beSecurite::commandes() as $cmd) {
                $eqId = (int) $cmd->getEqLogic_id();
                if (!isset(self::$parEquipement[$eqId])) {
                    self::$parEquipement[$eqId] = array();
                }
                self::$parEquipement[$eqId][] = $cmd;
            }
        }
        $id = (int) $_eqId;
        return isset(self::$parEquipement[$id]) ? self::$parEquipement[$id] : array();
    }

    /* La même table, indexée par identifiant de commande. get_states en
     * demande jusqu'à trente d'un coup : les chercher une à une dans le cœur
     * refaisait autant de requêtes sur une liste déjà chargée. */
    private static function commande($_id) {
        if (self::$parId === null) {
            self::$parId = array();
            foreach (k2000beSecurite::commandes() as $cmd) {
                self::$parId[(int) $cmd->getId()] = $cmd;
            }
        }
        $id = (int) $_id;
        return isset(self::$parId[$id]) ? self::$parId[$id] : null;
    }

    /*
     * Ce qu'un équipement donne à voir dans une liste. Les actions comptées
     * sont celles que l'assistant a le DROIT de lancer, pas celles qui
     * existent : sur une installation neuve, où tout est interdit, annoncer
     * « douze actions » enverrait le modèle demander le détail de douze
     * équipements pour s'entendre refuser douze fois.
     *
     * Le mode global entre donc dans ce compte, au même titre que la politique
     * de chaque commande : en lecture seule, le DROIT de lancer n'existe pour
     * aucune. Sans cela, le même équipement était annoncé « 3 actions » ici et
     * « forbidden, forbidden, forbidden » par get_equipment, et le modèle
     * promettait à l'utilisateur ce qu'il se verrait refuser au tour suivant.
     *
     * Le type générique d'une action interdite ne sort pas non plus. La règle
     * en tête de fichier — rien ne sort sans autorisation, pas même un nom —
     * était tenue pour les états et pas pour les actions : un filtre par type
     * générique rendait l'équipement avec « types: LOCK_OPEN, actions: 0 »,
     * c'est-à-dire qu'il y a une serrure là, et laquelle.
     */
    private static function resumeCommandes($_eqLogic, $_lectureSeule) {
        $generiques = array();
        $actions = 0;
        $etats = 0;
        foreach (self::commandesDe($_eqLogic->getId()) as $cmd) {
            if ($cmd->getType() === 'action') {
                if ($_lectureSeule || k2000beSecurite::politique($cmd) === k2000beSecurite::POLITIQUE_REFUS) {
                    continue;
                }
                $actions++;
            } elseif ($cmd->getType() === 'info') {
                if (!k2000beSecurite::lisible($cmd, $_eqLogic)) {
                    continue;
                }
                $etats++;
            } else {
                continue;
            }
            $generique = (string) $cmd->getGeneric_type();
            if ($generique !== '' && $generique !== k2000beSecurite::TYPE_IGNORE && !in_array($generique, $generiques, true)) {
                $generiques[] = $generique;
            }
        }
        return array('generiques' => $generiques, 'actions' => $actions, 'etats' => $etats);
    }

    private static function piece($_eqLogic) {
        $objets = k2000beSecurite::objets();
        $id = (int) $_eqLogic->getObject_id();
        return isset($objets[$id]) ? $objets[$id] : __('Sans pièce', __FILE__);
    }

    /*
     * Après une écriture d'autorisation, l'index de la sécurité est vidé : le
     * nôtre en dépend et doit l'être aussi.
     *
     * Le couplage est réel et non plus seulement décrit : c'est
     * k2000beSecurite::oublier() qui appelle cette méthode, après chaque
     * definirPolitique(), definirLecture() et masquerEquipement(). Elle était
     * restée sans appelant, ce qui laissait un équipement fraîchement masqué
     * visible dans self::$visibles pour tout le reste de la requête.
     *
     * Ne jamais rappeler k2000beSecurite::oublier() d'ici : ce serait une
     * récursion sans fin, et l'inversion de la dépendance.
     */
    public static function oublier() {
        self::$parEquipement = null;
        self::$parId = null;
        self::$visibles = null;
    }

    /* ======================================================== ARGUMENTS */

    /*
     * Les arguments viennent du modèle : ils peuvent manquer, arriver en
     * chaîne là où un entier est attendu, ou porter un nom approchant. On lit
     * ce qui est utilisable et on ignore le reste, plutôt que de refuser un
     * appel pour une virgule.
     */
    private static function argument($_arguments, $_cle, $_defaut) {
        if (!is_array($_arguments) || !isset($_arguments[$_cle])) {
            return $_defaut;
        }
        $valeur = $_arguments[$_cle];
        if ($valeur === null || $valeur === '') {
            return $_defaut;
        }
        return $valeur;
    }

    /*
     * Une liste d'identifiants, dédoublonnée. Le modèle envoie parfois un
     * entier seul là où un tableau est attendu, ou une chaîne « 45,46 » : les
     * deux sont acceptées, un refus ici coûterait un tour de conversation pour
     * rien.
     *
     * Le plafond n'est PAS appliqué ici : c'est l'appelant qui coupe, et qui
     * peut donc dire au modèle combien d'identifiants il a laissés de côté. La
     * troncature muette faisait croire au modèle qu'il avait reçu le détail de
     * tout ce qu'il avait demandé. Reste une borne de sécurité, parce que la
     * recherche de doublon est quadratique et que la liste vient du modèle.
     */
    private static function identifiants($_valeur) {
        $brut = $_valeur;
        if (is_string($brut)) {
            $brut = explode(',', $brut);
        } elseif (!is_array($brut)) {
            $brut = array($brut);
        }

        $ids = array();
        foreach ($brut as $element) {
            if (is_array($element)) {
                continue;
            }
            $id = (int) trim((string) $element);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::IDENTIFIANTS_MAX) {
                break;
            }
        }
        return $ids;
    }

    /* Comparaison souple : le modèle écrit « salon » là où la pièce s'appelle
     * « Salon », et « piece de vie » pour « Pièce de vie ». */
    private static function pliage($_texte) {
        $texte = mb_strtolower(trim((string) $_texte), 'UTF-8');
        return strtr($texte, array(
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'æ' => 'ae', 'œ' => 'oe',
        ));
    }
}
