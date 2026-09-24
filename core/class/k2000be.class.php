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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autoload du cœur ne sait charger qu'une classe par plugin, celle qui porte
 * son identifiant (core/php/core.inc.php, jeedomAutoload) : les quatre classes
 * de service ci-dessous ne seraient jamais trouvées toutes seules. */
require_once __DIR__ . '/k2000beJournal.class.php';
require_once __DIR__ . '/k2000beSecurite.class.php';
require_once __DIR__ . '/k2000beOutils.class.php';
require_once __DIR__ . '/k2000beOpenAI.class.php';

/*
 * Un assistant en langage naturel pour la maison.
 *
 * Le principe tient en une phrase : le modèle demande, le plugin décide. Rien
 * de ce que dit le modèle n'atteint la domotique sans passer par
 * k2000beSecurite, qui n'autorise que ce que l'administrateur a autorisé, et
 * demande une confirmation humaine pour le reste. Un modèle qui se trompe, qui
 * hallucine un identifiant ou qu'on aurait convaincu par un texte malicieux ne
 * peut donc rien ouvrir de plus qu'un modèle sage.
 *
 * Un équipement = un assistant : sa conversation, sa mémoire, ses commandes.
 * Plusieurs assistants peuvent coexister (un bavard pour le salon, un sobre
 * pour les scénarios), ils ne partagent ni mémoire ni journal.
 *
 * Ce fichier tient la boucle de conversation. C'est le seul endroit du plugin
 * qui parle au modèle ; tout ce qui touche à la domotique passe par les trois
 * classes de service.
 */
class k2000be extends eqLogic {

    /* ================================================== CONFIGURATION */

    /*
     * Le cœur lit cette propriété par réflexion : config::save() chiffre alors
     * la valeur avant de l'écrire en base, et config::byKey() la déchiffre à la
     * lecture (core/class/config.class.php, lignes 122, 212 et 248). Le plugin,
     * lui, ne change pas d'un iota : il continue de lire config::byKey('apikey')
     * et reçoit la clé en clair. Une clé déjà enregistrée en clair par une
     * version antérieure reste lisible — utils::decrypt() rend telle quelle une
     * valeur sans préfixe « crypt: » — et se chiffre au prochain enregistrement.
     *
     * Ce que cela protège : la table `config` et les sauvegardes Jeedom, qui
     * partent ailleurs — sur un NAS, dans un nuage, dans un ticket de support —
     * et où la clé serait autrement lisible à l'œil nu.
     *
     * Ce que cela ne protège pas : core/ajax/config.ajax.php rend la valeur
     * déchiffrée à son action « getKey », derrière un simple isConnect() — tout
     * compte connecté, administrateur ou non, peut donc la demander, et la page
     * de configuration du plugin la réaffiche en clair. Le chiffrement met la
     * clé à l'abri du disque, pas des comptes de la box.
     */
    public static $_encryptConfigKey = array('apikey');

    /* ================================================== STATUTS D'UN TOUR */

    /*
     * La grille, et elle se lit dans cet ordre :
     *
     *  - CONFIRMATION si une attente est née : rien n'est parti, la
     *    conversation est suspendue en l'état ;
     *  - LIMIT si un plafond a arrêté le tour — la limite d'outils, le budget
     *    de temps, ou une réponse coupée par max_tokens. La réponse ne tient
     *    compte que de ce que le modèle savait à ce moment-là ;
     *  - ERROR si rien d'exploitable n'a été obtenu : panne du modèle, réponse
     *    vide, ou tous les outils en erreur ;
     *  - REFUSED si rien n'est parti et qu'au moins un refus explique pourquoi.
     *    C'est une règle de la maison, pas un incident ;
     *  - SUCCESS sinon.
     *
     * Cette grille est le contrat de la commande « Statut », que lisent les
     * scénarios. Sans elle, « j'ai répondu » et « rien n'a marché mais j'ai
     * bavardé » se ressemblent : un tour où le seul outil appelé n'existait pas
     * valait SUCCESS, et le scénario enchaînait comme si la maison avait obéi.
     */

    const STATUT_SUCCESS = 'SUCCESS';
    const STATUT_CONFIRMATION = 'CONFIRMATION';
    const STATUT_REFUSED = 'REFUSED';
    const STATUT_LIMIT = 'LIMIT';
    const STATUT_ERROR = 'ERROR';

    /* ====================================================== LA BOUCLE */

    /*
     * Plafond par défaut du nombre d'outils exécutés pour une seule demande.
     *
     * Quinze, et non dix : c'est la demande emblématique qui donne l'échelle.
     * « Je vais me coucher » sur une maison d'une soixantaine d'équipements
     * situe la maison (list_rooms), liste deux ou trois pièces, détaille
     * quelques équipements, éteint une demi-douzaine de choses puis vérifie ce
     * qui est parti : douze à dix-huit outils. À dix, la boucle coupait au
     * milieu de l'extinction, et le modèle concluait — poliment — sur une
     * maison à moitié éteinte.
     *
     * Le plafond ne borne d'ailleurs pas le nombre d'ALLERS-RETOURS quand le
     * modèle groupe ses appels : six extinctions demandées dans le même message
     * coûtent six outils mais un seul aller-retour. C'est ce que l'invite
     * système lui demande de faire, et c'est ce qui rend quinze raisonnable.
     */
    const MAX_OUTILS_DEFAUT = 15;

    /*
     * Et son plafond. Le champ de configuration n'était borné que par le bas :
     * mille outils autorisés donnaient quarante et un allers-retours enchaînés
     * sans que rien ne bronche, et c'est la facture qui partait. Trente outils
     * pour une seule phrase sont déjà au-delà de tout ce qu'une maison
     * demande — « je vais me coucher », la demande la plus large qu'on lui
     * connaisse, en dépense une quinzaine — et le budget de temps couperait de
     * toute façon bien avant.
     */
    const MAX_OUTILS_MAX = 30;

    /*
     * Garde-fou au-dessus de la limite d'outils : deux allers-retours de plus
     * que le nombre d'outils autorisés. Ils couvrent le tour de conclusion et
     * le cas d'un modèle qui n'appellerait aucun outil sans conclure pour
     * autant — sans ce plafond, la boucle serait infinie et facturée.
     */
    const ALLERS_SUPPLEMENTAIRES = 2;

    /* Échanges conservés dans la mémoire de la conversation, par défaut. */
    const MEMOIRE_DEFAUT = 12;

    /*
     * Et son plafond, pour la même raison que celui des outils : la mémoire
     * repart EN ENTIER à chaque demande, et elle est facturée à chaque fois.
     * Cinquante tours dépassent déjà largement ce dont une conversation
     * domestique a besoin.
     */
    const MEMOIRE_MAX = 50;

    /*
     * Les deux plafonds qui manquaient à la coupe de mémoire.
     *
     * Compter les tours ne suffit pas : un seul tour de trente outils fait
     * soixante et un messages, que « garder un tour » conservait en entier, et
     * qui repartaient en entier à chaque demande suivante — droit vers le 400
     * « context_length_exceeded », lequel se réenregistre et se rejoue.
     *
     * Le poids compte autant que le nombre : une lecture d'historique ou un
     * catalogue d'équipements tiennent en un message et pèsent des dizaines de
     * kilo-octets. Soixante kilo-octets de mémoire, c'est de l'ordre de quinze
     * mille jetons d'invite à chaque demande : au-delà, on paie un contexte que
     * personne ne relit.
     */
    const MEMOIRE_MESSAGES_MAX = 120;
    const MEMOIRE_OCTETS_MAX = 60000;

    /* ==================================================== BUDGET DE TEMPS */

    /*
     * Le pire cas de la boucle dépasse de très loin ce que PHP tolère : une
     * tentative vaut le délai configuré (60 s), appel() en fait deux plus une
     * seconde d'attente, chat() peut enchaîner trois corrections de charge, et
     * jouer() jusqu'à max_tool_calls + 2 allers. Soit plus d'une heure, quand
     * max_execution_time vaut 600 s sur une box ordinaire : PHP tue alors le
     * processus AU MILIEU de la boucle, et comme rien n'était enregistré avant
     * la fin du tour, ni la conversation ni le journal n'en gardaient trace —
     * alors que des commandes avaient pu partir.
     *
     * Un tour reçoit donc un budget de temps explicite, vérifié avant chaque
     * appel au modèle. Il est calé sur max_execution_time, moins la marge
     * ci-dessous : il faut qu'il reste de quoi enregistrer la conversation,
     * écrire le journal et publier les commandes après la coupure.
     */
    const BUDGET_MARGE = 30;

    /* En dessous, le budget ne laisserait pas la place à un seul appel. */
    const BUDGET_MIN = 60;

    /*
     * En ligne de commande — un scénario, le cron — max_execution_time vaut 0
     * et PHP ne coupe rien du tout. Ce n'est pas une raison pour laisser un
     * tour durer une heure : le scénario qui attend, lui, n'a pas plus de
     * patience que l'utilisateur devant sa page.
     */
    const BUDGET_DEFAUT = 600;

    /*
     * Tours conservés dans la frise affichée par l'interface. Ce n'est pas la
     * mémoire du modèle : c'est ce que l'utilisateur relit. Cinquante tours
     * tiennent dans une page sans peser sur le fichier de conversation.
     */
    const TOURS_MAX = 50;

    /*
     * Plafond haut du réglage « Demandes par jour ». Le réglage lui-même est
     * livré à 0, c'est-à-dire sans plafond : le plugin ne s'arroge pas le droit
     * de décider combien de fois on a celui de parler à sa maison.
     *
     * Ce qu'il borne, c'est l'emballement. Rien du côté de la box ne limitait
     * la dépense : un scénario branché sur « Demander » et déclenché par un
     * capteur qui vibre, une boucle qui se rappelle elle-même, un widget resté
     * ouvert sur une page qui se recharge — et la facture court jusqu'à ce que
     * quelqu'un regarde, chez OpenAI, un ou deux jours plus tard. Le plafond de
     * dépense d'OpenAI est mensuel et se mesure en dollars ; celui-ci est
     * quotidien, se mesure en demandes, et arrête la chose avant tout appel
     * réseau.
     *
     * Deux mille : de quoi ne jamais gêner un usage humain, même bavard, tout
     * en gardant un sens à la borne.
     */
    const DEMANDES_JOUR_MAX = 2000;

    /* ================================================== CONFIRMATIONS */

    /*
     * Une confirmation vaut cinq minutes. Au-delà, ouvrir le portail sur un
     * « oui » cliqué une heure plus tôt reviendrait à obéir à une intention
     * périmée, dans un contexte que personne ne vérifie plus.
     */
    const ATTENTE_DUREE = 300;

    /* Longueur du jeton qui identifie une confirmation. Il est tiré au hasard :
     * il ne doit pas être devinable depuis un autre onglet ou un autre
     * utilisateur de la box. */
    const JETON_LONGUEUR = 20;

    /* ================================================ FICHE DE LA MAISON */

    /*
     * La maison telle qu'on la vit, que les équipements ne disent pas : qui y
     * habite, comment elle est chauffée, à quelle heure on se couche. Sans
     * elle, « je vais me coucher » et « j'ai froid » n'ont aucune réponse
     * intelligente — le plugin connaît soixante équipements et pas une
     * habitude.
     *
     * L'ordre de ce tableau est celui du bloc envoyé au modèle, et les clés
     * sont celles de la configuration : ajouter un champ ici suffit à le faire
     * apparaître dans l'invite, à condition de l'ajouter aussi au .ini et à la
     * page de configuration.
     *
     * Le critère d'admission d'un champ, et il ne souffre pas d'exception : on
     * ne demande que ce qui change une décision. Pas d'adresse — la ville de la
     * box part déjà —, pas de prénoms ni d'âges nominatifs, pas de marque de
     * véhicule. Cette fiche repart chez OpenAI à CHAQUE demande, pour
     * toujours : une ligne inutile est une donnée personnelle exposée en pure
     * perte, et elle dilue les lignes utiles.
     */
    const MAISON_CHAMPS = array(
        'maison_foyer'     => 'Qui vit ici',
        'maison_animaux'   => 'Animaux',
        'maison_logement'  => 'Logement',
        'maison_chauffage' => 'Chauffage et eau chaude',
        'maison_habitudes' => 'Habitudes et horaires',
        'maison_interdits' => 'À ne jamais faire',
        'maison_vehicule'  => 'Véhicule électrique',
    );

    /*
     * La phrase qui ouvre le bloc, et c'est la plus importante de la fiche :
     * elle dit d'où viennent ces informations et quelle autorité elles ont.
     * Sans elle, le modèle traite « nous nous couchons à 23 h » comme un fait
     * de la maison au même rang qu'un état relevé à l'instant, et préfère la
     * fiche à l'outil quand les deux se contredisent — un propriétaire qui a
     * déménagé sa chambre il y a six mois ferait éteindre la mauvaise pièce.
     */
    const MAISON_ENTETE = 'La maison, telle que son propriétaire la décrit. Ces informations ne sont pas vérifiables par les outils : si un outil dit autre chose, c\'est l\'outil qui a raison.';

    /*
     * Trois ou quatre phrases par champ : de quoi dire ce qui change une
     * décision, trop peu pour y recopier une vie. Au-delà, la suite est coupée
     * au dernier mot entier — une phrase tronquée en plein milieu vaut mieux
     * qu'un champ qui gonfle sans fin chaque facture.
     */
    const MAISON_CHAMP_MAX = 300;

    /*
     * Et un plafond pour la fiche entière. Les sept champs au maximum feraient
     * 2100 caractères, là où le reste de l'invite — persona, consignes, mode de
     * sécurité, résumé de la maison — en pèse près de 2700 : la fiche ferait
     * presque la moitié de ce que lit le modèle à chaque demande, et les
     * consignes de conduite s'y noieraient. Mille cinq cents caractères, soit
     * quelque quatre cents jetons renvoyés à chaque phrase, laissent de quoi
     * être généreux sur les deux ou trois champs qui comptent vraiment sans
     * renverser cet équilibre. Les champs sont pris dans l'ordre du tableau :
     * ce qui dépasse est coupé, puis abandonné.
     */
    const MAISON_FICHE_MAX = 1500;

    /*
     * Les consignes propres à UN assistant.
     *
     * Le plugin affirmait depuis le début qu'un équipement est un assistant, et
     * que plusieurs peuvent coexister — « un bavard pour le salon, un sobre
     * pour les scénarios ». C'était faux sur le seul point qui les
     * distinguerait vraiment : les consignes vivaient dans la configuration du
     * PLUGIN, donc les mêmes pour tous. Spécialiser un assistant revenait à
     * spécialiser tous les autres, présents et à venir.
     *
     * Deux mille caractères : c'est une marche à suivre, pas une fiche à
     * champs, et elle mérite plus qu'une ligne. Elle repart chez OpenAI à
     * chaque demande DE CET ASSISTANT, et de lui seul.
     */
    const CONSIGNES_MAX = 2000;

    /*
     * Les types génériques qui décident d'un verdict, pour CET assistant.
     *
     * Une consigne écrite en toutes lettres — « ne te fie qu'aux détections
     * croisées » — reste une consigne : le modèle la suit le plus souvent, et
     * un jour il additionne trois mouvements isolés et annonce une intrusion.
     * Quand la réponse engage la sécurité du logement, ce n'est pas assez.
     *
     * Le verdict est donc calculé par le plugin, à partir des seuls états dont
     * l'administrateur a déclaré ici le type générique — ALARM_STATE pour une
     * règle de détection croisée, par exemple. Le modèle ne reçoit plus des
     * faits à peser, il reçoit une conclusion à formuler. C'est la même règle
     * que pour les actions : le modèle demande, le plugin décide.
     *
     * Huit types au plus : au-delà, ce n'est plus un critère, c'est un
     * inventaire, et le verdict redeviendrait ce qu'on voulait lui éviter.
     */
    const VERDICT_TYPES_MAX = 8;

    /*
     * La surveillance automatique : le repos entre deux réveils.
     *
     * Sans lui, l'idée est inutilisable. Sur l'installation qui a servi à
     * l'écrire, une seule règle de détection croisée a tiré trente-huit fois
     * dans la journée : trente-huit demandes facturées, dont la plupart
     * refusées par le verrou « un tour à la fois » puisqu'un tour dure des
     * dizaines de secondes. Une alerte qui se répète toutes les minutes n'est
     * pas une alerte, c'est un bruit qu'on finit par couper.
     *
     * Quinze minutes par défaut : assez pour ne pas manquer un second passage
     * qui compte, assez pour ne pas raconter trois fois le même.
     */
    const REPOS_DEFAUT = 15;
    const REPOS_MIN = 1;
    const REPOS_MAX = 1440;

    /*
     * L'alerte se joue dans une tâche de fond, et ces trois bornes la tiennent.
     *
     * La patience : une alerte qui trouve l'assistant en plein tour attend son
     * tour au lieu d'être refusée comme le serait une demande humaine. Personne
     * n'est derrière la porte, et une levée de doute perdue parce que quelqu'un
     * demandait la température du salon serait la pire des économies. Deux
     * minutes couvrent un tour ordinaire ; au-delà, ask() refuse et le journal
     * dit que l'alerte n'a pas pu passer.
     *
     * La péremption : une tâche du cœur planifiée « à cette minute-ci » porte
     * une date sans année, et une tâche restée en base — processus tué avant la
     * fin — repartirait l'an prochain à la même minute. Une alerte vieille de
     * plus de dix minutes ne raconte plus rien d'utile : elle est jetée.
     */
    const ALERTE_PATIENCE = 120;
    const ALERTE_PAS = 2;
    const ALERTE_PEREMPTION = 600;

    /* ========================================================= AFFICHAGE */

    /* Le cœur ne fournit pas de date en français, et setlocale() dépend des
     * paquets installés sur la box : la table est plus sûre qu'un réglage
     * système qui peut manquer. */
    const JOURS = array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi');
    const MOIS = array('', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

    /* =========================================================== JOURNAL */

    /*
     * Le seul point de journalisation de cette classe. La règle du plugin est
     * que rien n'est écrit dans le journal sans passer par masquer() : un
     * message d'exception recopie volontiers l'URL, l'en-tête ou le corps qui
     * transportaient la clé API, et le journal, lui, finit dans un ticket.
     * Douze appels enrobés à la main finiraient par en oublier un ; un seul
     * point ne l'oublie jamais.
     */
    private static function tracer($_niveau, $_message) {
        try {
            log::add(__CLASS__, $_niveau, k2000beJournal::masquer((string) $_message));
        } catch (Throwable $e) {
            /* Rien à faire de plus : on ne peut pas journaliser l'échec du
             * journal. */
        }
    }

    /* ===================================================== CONFIGURATION LUE */

    /*
     * Un entier de la configuration, borné des deux côtés, et qui le dit.
     *
     * Trois champs voisins avaient trois conventions muettes : max_tokens à 0
     * valait « pas de plafond », max_tool_calls à 0 valait « 10 », timeout à 1
     * valait « 60 ». Aucun ne prévenait que sa valeur avait été remplacée, et
     * l'utilisateur qui tapait 1 croyait avoir réglé quelque chose.
     *
     * La règle est désormais la même partout, ici comme dans le client OpenAI :
     * un champ vide ou absurde (≤ 0) reprend le défaut, une valeur trop grande
     * est ramenée au plafond, et les deux laissent une ligne dans le journal du
     * plugin. Seul max_tokens garde sa convention propre — 0 y veut dire « pas
     * de plafond », ce qui est une valeur, pas une erreur de saisie — et elle
     * est écrite à côté du champ.
     */
    private static function reglageEntier($_cle, $_defaut, $_max) {
        $brut = config::byKey($_cle, __CLASS__, $_defaut);
        $valeur = (int) $brut;

        if ($valeur < 1) {
            if (trim((string) $brut) !== '' && (int) $_defaut !== $valeur) {
                self::tracer('info', sprintf(__('Réglage « %s » inutilisable (%s) : %s est employé.', __FILE__),
                    $_cle, (string) $brut, (int) $_defaut));
            }
            return (int) $_defaut;
        }
        if ($valeur > $_max) {
            self::tracer('info', sprintf(__('Réglage « %s » au-dessus du plafond (%s) : %s est employé.', __FILE__),
                $_cle, $valeur, $_max));
            return (int) $_max;
        }
        return $valeur;
    }

    /*
     * Le plafond de demandes du jour, ou 0 s'il n'y en a pas.
     *
     * reglageEntier() ne convient pas ici : sa convention est qu'un zéro est
     * une saisie absurde à remplacer par le défaut, et zéro est précisément la
     * valeur qui a un sens — « pas de plafond ». C'est la même exception que
     * max_tokens, et elle est écrite à côté du champ.
     */
    private static function plafondJournalier() {
        $valeur = (int) config::byKey('max_demandes_jour', __CLASS__, 0);
        if ($valeur <= 0) {
            return 0;
        }
        if ($valeur > self::DEMANDES_JOUR_MAX) {
            self::tracer('info', sprintf(__('Réglage « %s » au-dessus du plafond (%s) : %s est employé.', __FILE__),
                'max_demandes_jour', $valeur, self::DEMANDES_JOUR_MAX));
            return self::DEMANDES_JOUR_MAX;
        }
        return $valeur;
    }

    /*
     * Le temps qu'un tour a le droit de durer, en secondes.
     *
     * On lit max_execution_time plutôt que de figer un chiffre : c'est lui qui
     * décide quand PHP tue le processus, et un budget qui le dépasserait ne
     * servirait à rien.
     */
    private static function budget() {
        $max = (int) ini_get('max_execution_time');
        if ($max <= 0) {
            return self::BUDGET_DEFAUT;
        }
        $budget = $max - self::BUDGET_MARGE;
        return ($budget < self::BUDGET_MIN) ? self::BUDGET_MIN : $budget;
    }

    /* ============================================================== CRON */

    /*
     * Une fois par jour : la purge du journal. Rien d'autre ne tourne tout
     * seul, l'assistant ne parle que quand on lui parle.
     */
    /*
     * Toutes les cinq minutes. Une confirmation ne vaut que cinq minutes, et
     * personne n'ouvre forcément la page du plugin pendant ce temps : sans ce
     * passage, la tuile du tableau de bord resterait sur « K2000 attend votre
     * accord » jusqu'à la demande suivante, et une notification de scénario se
     * répéterait dans le vide. Le balayage ne coûte rien : il ne regarde que
     * les assistants dont la commande dit qu'une confirmation est en cours.
     */
    /*
     * Toutes les minutes : la surveillance des états décisifs.
     *
     * Pourquoi la minute et non un listener du cœur, qui serait immédiat : un
     * listener s'exécute DANS le processus qui a publié la valeur, c'est-à-dire
     * dans le démon du plugin de vidéosurveillance. Un tour de conversation
     * dure dix à trente secondes et part sur le réseau ; le tenir là ferait
     * attendre le démon qui vient de recevoir l'événement, et derrière lui tous
     * les événements suivants. Une minute de retard sur une levée de doute se
     * paie bien moins cher qu'un démon d'alarme bloqué.
     *
     * Le balayage ne coûte rien quand personne n'a rien déclaré : il s'arrête à
     * la première ligne pour chaque assistant qui n'a pas demandé la
     * surveillance.
     */
    public static function cron() {
        foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->surveiller();
            } catch (Throwable $e) {
                self::tracer('error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Réveille l'assistant quand un état décisif a basculé depuis le dernier
     * passage.
     *
     * Trois précautions, et chacune répond à une façon de se tromper :
     *
     *  - le premier passage n'alerte jamais. Il pose le repère et s'en va :
     *    sans cela, cocher la case un matin ferait raconter la détection de
     *    l'avant-veille comme si elle venait d'arriver ;
     *  - le repère avance même quand on n'alerte pas, repos compris. Ne pas
     *    l'avancer ferait ressortir, à la fin du repos, un événement déjà
     *    périmé — et le repos ne serait qu'un report ;
     *  - le repère est écrit AVANT l'appel au modèle. Un tour interrompu au
     *    milieu ne doit pas se rejouer à la minute suivante, puis à la
     *    suivante, jusqu'à épuiser le plafond du jour.
     */
    public function surveiller() {
        if ((int) $this->getConfiguration('alerte_auto', 0) !== 1) {
            return false;
        }
        $types = $this->typesDecisifs();
        if ($types === array()) {
            return false;
        }

        $balayage = k2000beOutils::balayage($types);
        k2000beOutils::oublier();

        $dernier = 0;
        $recent = null;
        $combien = 0;
        foreach ($balayage['decisifs'] as $ligne) {
            if ($ligne['ts'] > $dernier) {
                $dernier = $ligne['ts'];
                $recent = $ligne;
            }
        }
        if ($dernier === 0) {
            /* Rien n'a jamais basculé : il n'y a même pas de repère à poser. */
            return false;
        }
        foreach ($balayage['decisifs'] as $ligne) {
            if ($ligne['ts'] > (int) $this->getConfiguration('alerte_vu', 0)) {
                $combien++;
            }
        }

        $vu = (int) $this->getConfiguration('alerte_vu', 0);
        if ($vu === 0) {
            $this->setConfiguration('alerte_vu', $dernier);
            $this->save();
            return false;
        }
        if ($dernier <= $vu) {
            return false;
        }

        $this->setConfiguration('alerte_vu', $dernier);

        /*
         * Le garde-fou d'armement, et il se ferme par défaut.
         *
         * Une détection de caméra ne mérite pas un appel facturé à toute heure :
         * elle n'a de sens que lorsque la maison est armée. Le champ
         * « N'alerter que si » désigne l'état qui le dit — celui de l'alarme —
         * et tant qu'il ne vaut pas « en marche », rien ne part.
         *
         * Tout ce qui n'est pas un oui franc est un non : commande supprimée,
         * jamais renseignée, valeur vide, plugin d'alarme pas encore installé.
         * C'est délibéré et c'est le sens même du champ — le jour où l'on
         * désigne une alarme qui n'existe pas encore, il ne doit rien se passer,
         * surtout pas des demandes facturées toute la journée.
         *
         * Le repère a déjà avancé : ce qui s'est passé pendant que la maison
         * était désarmée ne ressortira pas au moment où on l'arme. C'est la même
         * règle que pour le repos, et pour la même raison.
         *
         * Ce garde-fou ne vaut que pour l'alerte automatique. Une question
         * posée par un humain — check_alert, get_events — reçoit sa réponse
         * quelle que soit l'alarme : on a le droit de demander si quelqu'un est
         * passé cette nuit sans avoir armé quoi que ce soit.
         */
        $garde = (int) $this->getConfiguration('alerte_si', 0);
        if ($garde > 0 && !self::armee($garde)) {
            $this->save();
            return false;
        }

        /* Le repos se lit sur l'ASSISTANT et non sur le plugin : deux
         * assistants surveillant deux choses différentes n'ont aucune raison
         * de se taire aussi longtemps l'un que l'autre. reglageEntier() ne
         * convient donc pas ici, elle ne lit que la configuration du plugin. */
        $repos = (int) $this->getConfiguration('alerte_repos', self::REPOS_DEFAUT);
        if ($repos < self::REPOS_MIN) {
            $repos = self::REPOS_DEFAUT;
        }
        if ($repos > self::REPOS_MAX) {
            $repos = self::REPOS_MAX;
        }
        $precedente = (int) $this->getConfiguration('alerte_derniere', 0);
        if ($precedente > 0 && (time() - $precedente) < $repos * 60) {
            /* En repos : le repère a tout de même avancé, l'événement est donc
             * couvert par l'alerte précédente et ne ressortira pas. */
            $this->save();
            return false;
        }

        $this->setConfiguration('alerte_derniere', time());
        $this->save();

        $message = sprintf(
            __('Alerte : %s — %s, à %s.', __FILE__),
            $recent['equipment'], $recent['name'], $recent['at']);
        if ($combien > 1) {
            $message .= ' ' . sprintf(__('%s états décisifs ont basculé.', __FILE__), $combien);
        }
        $message .= ' ' . __('Fais la levée de doute et dis ce qu\'il en est.', __FILE__);

        $this->planifierAlerte($message);
        return true;
    }

    /*
     * Confie la levée de doute à une tâche de fond du cœur, lancée sur-le-champ.
     *
     * Pourquoi pas ask() ici même : le cœur joue les cron() de TOUS les
     * plugins l'un après l'autre, dans un seul processus (plugin::cron). Une
     * levée de doute dure dix à trente secondes, jusqu'à dix minutes de
     * budget : pendant ce temps, les autres plugins attendent leur minute, et
     * au quatrième chevauchement le cœur publie un message qui accuse K2000 et
     * conseille de le désactiver. C'est l'argument du listener, en plus
     * large : on ne tient pas un processus partagé pour un appel réseau.
     *
     * La tâche est celle que le cœur sait déjà jouer seul (jeeCron.php) :
     * « once », elle s'efface après son passage ; run() la lance aussitôt en
     * arrière-plan, sans attendre la minute suivante. Le message part tout
     * fait dans les options : la détection a déjà eu lieu, les repères ont
     * déjà avancé, la tâche n'a plus rien à décider.
     *
     * Le repli, s'il est impossible de lancer la tâche, est l'appel sur place,
     * comme avant. Le choix est délibéré : le repère a déjà avancé, une alerte
     * abandonnée ici ne ressortirait jamais — une intrusion passerait sous
     * silence. Un cron() en retard d'une demi-minute, une fois, sur une box
     * dont la table des tâches est en panne, coûte moins cher ; le cœur ne
     * crie qu'au-delà de trois chevauchements.
     */
    private function planifierAlerte($_message) {
        $tache = null;
        try {
            $tache = new cron();
            $tache->setClass(__CLASS__);
            $tache->setFunction('alerteDifferee');
            /* Les options ne sont jamais vides : le cœur fusionnerait alors la
             * tâche avec une autre de même classe et même fonction
             * (cron::preSave), et deux alertes rapprochées n'en feraient
             * qu'une. */
            $tache->setOption(array(
                'eqLogic_id' => (int) $this->getId(),
                'message'    => $_message,
                'planifiee'  => time(),
            ));
            $tache->setOnce(1);
            $tache->setSchedule(cron::convertDateToCron(time()));
            /* En minutes. Le budget d'un tour en ligne de commande, plus
             * l'attente du verrou, plus une de marge : le maître des tâches ne
             * doit pas tuer une levée de doute légitime, et
             * maxExecTimeCrontask peut avoir été réglé plus court. */
            $tache->setTimeout((int) ceil((self::BUDGET_DEFAUT + self::ALERTE_PATIENCE) / 60) + 1);
            $tache->save();
            /* La dernière exécution datée de cette minute : le maître des
             * tâches, qui la verrait due, ne la lance pas une seconde fois
             * (cron::isDue). Le cache ne se pose qu'une fois l'identifiant
             * connu, donc après save(). */
            $tache->setLastRun(date('Y-m-d H:i:s'));
            $tache->run();
            return true;
        } catch (Throwable $e) {
            self::tracer('error', sprintf(
                __('%s : la tâche de fond de l\'alerte n\'a pas pu être lancée, l\'alerte est jouée sur place. %s', __FILE__),
                $this->getHumanName(), $e->getMessage()));
            /* Une tâche enregistrée mais pas lancée doit partir, sinon elle
             * dormirait jusqu'à la même minute l'an prochain. La péremption la
             * jetterait de toute façon, mais autant ne pas la laisser traîner. */
            if (is_object($tache) && $tache->getId() != '') {
                try {
                    $tache->remove(false);
                } catch (Throwable $f) {
                }
            }
        }
        /* « alerte » et non « scenario » : le journal doit distinguer une
         * demande née d'un déclenchement d'une demande écrite par quelqu'un. */
        $this->ask($_message, array('utilisateur' => 'alerte'));
        return false;
    }

    /*
     * La levée de doute elle-même, jouée par jeeCron.php dans son propre
     * processus.
     *
     * Rien ne doit en sortir en exception : le cœur laisserait alors la tâche
     * en base, à l'état « error », et elle repartirait l'an prochain. Tout est
     * donc attrapé et dit dans le journal du plugin — le seul que l'on lit
     * quand une alerte n'est pas arrivée.
     *
     * Rend le résultat de ask(), ou null quand rien n'a été joué ; jeeCron n'en
     * fait rien, le rejeu s'en sert.
     */
    public static function alerteDifferee($_options = array()) {
        try {
            $id = (is_array($_options) && isset($_options['eqLogic_id'])) ? (int) $_options['eqLogic_id'] : 0;
            $message = (is_array($_options) && isset($_options['message'])) ? trim((string) $_options['message']) : '';
            $planifiee = (is_array($_options) && isset($_options['planifiee'])) ? (int) $_options['planifiee'] : 0;
            if ($id <= 0 || $message === '') {
                self::tracer('error', __('Alerte différée sans assistant ni message : rien n\'a été joué.', __FILE__));
                return null;
            }
            if ($planifiee <= 0 || (time() - $planifiee) > self::ALERTE_PEREMPTION) {
                self::tracer('info', sprintf(__('Alerte périmée jetée sans être jouée : %s', __FILE__), $message));
                return null;
            }

            /* Supprimé ou désactivé entre la détection et maintenant : il n'y
             * a plus personne pour faire la levée de doute, et ce n'est pas
             * une erreur. assistant() refuse déjà un équipement d'un autre
             * plugin. */
            $eqLogic = self::assistant($id);
            if (!is_object($eqLogic) || (int) $eqLogic->getIsEnable() !== 1) {
                self::tracer('info', sprintf(
                    __('Alerte abandonnée : l\'assistant %s n\'existe plus ou est désactivé.', __FILE__), $id));
                return null;
            }

            /* Attendre la fin d'un tour en cours plutôt que se faire refuser.
             * On ne garde pas le verrou : ask() le prend lui-même, et le
             * rendre ici puis le reprendre là laisse une fenêtre d'un instant.
             * Si un humain s'y glisse, ask() refuse et le journal le dit —
             * c'est le même refus qu'avant, devenu rare au lieu d'ordinaire. */
            $limite = time() + self::ALERTE_PATIENCE;
            while (true) {
                $verrou = $eqLogic->verrouiller();
                if ($verrou !== false) {
                    $eqLogic->deverrouiller($verrou);
                    break;
                }
                if (time() >= $limite) {
                    break;
                }
                sleep(self::ALERTE_PAS);
            }

            /* « alerte » et non « scenario » : le journal doit distinguer une
             * demande née d'un déclenchement d'une demande écrite par
             * quelqu'un. */
            return $eqLogic->ask($message, array('utilisateur' => 'alerte'));
        } catch (Throwable $e) {
            self::tracer('error', __('Alerte différée interrompue :', __FILE__) . ' ' . $e->getMessage());
            return null;
        }
    }

    /*
     * L'état d'armement vaut-il « en marche » ?
     *
     * Il n'est pas soumis au droit de LECTURE de l'assistant : c'est le plugin
     * qui lit sa propre configuration, pas le modèle qui consulte la maison.
     * Un administrateur peut donc masquer l'état de son alarme au modèle tout
     * en s'en servant comme condition — et c'est même le réglage souhaitable.
     */
    private static function armee($_cmdId) {
        try {
            $cmd = cmd::byId((int) $_cmdId);
            if (!is_object($cmd) || $cmd->getType() !== 'info') {
                self::tracer('info', sprintf(
                    __('Alerte retenue : la commande d\'armement %s est introuvable.', __FILE__), (int) $_cmdId));
                return false;
            }
            $valeur = $cmd->execCmd();
        } catch (Throwable $e) {
            self::tracer('info', __('Alerte retenue : l\'état d\'armement est illisible.', __FILE__)
                . ' ' . $e->getMessage());
            return false;
        }
        if (is_bool($valeur)) {
            return $valeur;
        }
        $texte = strtolower(trim((string) $valeur));
        return in_array($texte, array('1', 'true', 'on', 'armed'), true);
    }

    public static function cron5() {
        foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
            try {
                $cmd = $eqLogic->getCmd('info', 'pending');
                if (!is_object($cmd) || (int) $cmd->execCmd() !== 1) {
                    continue;
                }
                /* attente() fait le ménage elle-même : c'est le seul endroit
                 * qui sait ce qu'une attente périmée doit laisser derrière. */
                $eqLogic->attente();
            } catch (Throwable $e) {
                self::tracer('error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public static function cronDaily() {
        try {
            k2000beJournal::purger();
        } catch (Throwable $e) {
            self::tracer('error', __('Purge du journal impossible :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /* ============================================================= SANTÉ */

    /*
     * La page Santé du cœur (Analyse → Santé). Le cœur appelle cette méthode
     * tout seul dès qu'elle existe, et affiche une ligne par entrée : le
     * libellé, l'avis en infobulle, et le résultat en vert ou en rouge selon
     * « state ».
     *
     * Ce qui est en rouge doit mériter qu'on se lève : une case rouge de plus
     * sur cette page et c'est la page entière qu'on cesse de regarder. Un mode
     * simulation, un plafond réglé, une maison silencieuse depuis trois jours
     * ne sont pas des pannes et restent verts, avec la phrase qui va bien.
     *
     * Trois lignes seulement peuvent virer au rouge : pas de clé API — le
     * plugin ne peut alors rien faire du tout —, un dossier de travail qui ne
     * s'écrit pas — plus de mémoire, plus de journal —, et une commande qui
     * engage la sécurité du logement réglée sur « Autorisée ». Cette dernière
     * est un choix licite, que l'interface déconseille sans l'interdire ; elle
     * est ici parce que c'est le seul endroit de Jeedom où on la reverra sans
     * la chercher, et parce que le compteur reste à zéro tant que le mode
     * global n'est pas « actions » : ce qui s'allume en rouge peut vraiment
     * partir.
     */
    public static function health() {
        $lignes = array();

        $cle = (trim((string) config::byKey('apikey', __CLASS__, '')) !== '');
        $lignes[] = array(
            'test'   => __('Clé API OpenAI', __FILE__),
            'result' => $cle ? __('Renseignée', __FILE__) : __('Absente', __FILE__),
            'state'  => $cle,
            'advice' => $cle ? '' : __('Sans clé, chaque demande est refusée avant même de partir. Elle se saisit dans la configuration du plugin.', __FILE__),
        );

        $modes = array(
            k2000beSecurite::MODE_LECTURE    => __('Lecture seule', __FILE__),
            k2000beSecurite::MODE_SIMULATION => __('Simulation', __FILE__),
            k2000beSecurite::MODE_ACTIONS    => __('Actions réelles', __FILE__),
        );
        $mode = k2000beSecurite::mode();
        $lignes[] = array(
            'test'   => __('Mode de sécurité', __FILE__),
            'result' => isset($modes[$mode]) ? $modes[$mode] : $mode,
            /* Aucun des trois modes n'est une panne : celui qui a choisi la
             * simulation l'a choisie. La ligne est là pour qu'on n'aille pas
             * chercher une panne de domotique là où il n'y a qu'un
             * interrupteur logiciel. */
            'state'  => true,
            'advice' => ($mode === k2000beSecurite::MODE_ACTIONS) ? ''
                : __('Rien n\'est envoyé à la domotique dans ce mode : l\'assistant consulte, et raconte au mieux ce qu\'il aurait fait.', __FILE__),
        );

        $lignes[] = array(
            'test'   => __('Modèle employé', __FILE__),
            'result' => trim((string) config::byKey('model', __CLASS__, k2000beOpenAI::MODELE_DEFAUT)),
            'state'  => true,
            'advice' => '',
        );

        $compteurs = array('autorisees' => 0, 'confirmation' => 0, 'sensibles' => 0);
        try {
            $lus = k2000beSecurite::compteurs();
            if (is_array($lus)) {
                $compteurs = array_merge($compteurs, $lus);
            }
        } catch (Throwable $e) {
            self::tracer('debug', $e->getMessage());
        }

        $agissantes = (int) $compteurs['autorisees'] + (int) $compteurs['confirmation'];
        $lignes[] = array(
            'test'   => __('Commandes autorisées', __FILE__),
            'result' => sprintf(__('%s autorisées, %s en confirmation', __FILE__),
                (int) $compteurs['autorisees'], (int) $compteurs['confirmation']),
            /* Zéro n'est pas une panne — c'est l'état livré, et une maison qui
             * ne veut qu'un assistant bavard s'y tient très bien. */
            'state'  => true,
            'advice' => ($agissantes > 0) ? ''
                : __('Aucune action n\'est autorisée : l\'assistant répond aux questions, et refuse tout le reste. L\'onglet « Autorisations » de la page du plugin dit ce qu\'il a le droit d\'employer.', __FILE__),
        );

        $sensibles = (int) $compteurs['sensibles'];
        $lignes[] = array(
            'test'   => __('Commandes sensibles sans confirmation', __FILE__),
            'result' => ($sensibles === 0) ? __('Aucune', __FILE__)
                : sprintf(__('%s à vérifier', __FILE__), $sensibles),
            'state'  => ($sensibles === 0),
            'advice' => ($sensibles === 0) ? ''
                : __('Des commandes qui ouvrent, déverrouillent ou désarment sont réglées sur « Autorisée » : l\'assistant les actionne sans rien demander. « Confirmation » est le réglage recommandé, dans l\'onglet « Autorisations ».', __FILE__),
        );

        $ecrit = false;
        try {
            k2000beJournal::prepare();
            $ecrit = is_writable(k2000beJournal::racine());
        } catch (Throwable $e) {
            self::tracer('debug', $e->getMessage());
        }
        $lignes[] = array(
            'test'   => __('Dossier de travail', __FILE__),
            'result' => $ecrit ? __('Accessible en écriture', __FILE__) : __('Inaccessible', __FILE__),
            'state'  => $ecrit,
            'advice' => $ecrit ? ''
                : __('Sans lui, l\'assistant perd sa mémoire entre deux demandes et plus rien ne s\'inscrit au journal. Vérifiez les droits du dossier data/ du plugin.', __FILE__),
        );

        $consommation = k2000beJournal::consommationDuJour();
        $plafond = self::plafondJournalier();
        $lignes[] = array(
            'test'   => __('Consommation du jour', __FILE__),
            'result' => sprintf(__('%s demandes, %s jetons', __FILE__),
                (int) $consommation['demandes'], (int) $consommation['jetons'])
                . (($plafond > 0) ? sprintf(__(' (plafond : %s)', __FILE__), $plafond) : ''),
            /* Atteindre son propre plafond n'est pas une panne, c'est le
             * plafond qui fait son travail : la ligne le dit, elle ne
             * l'alarme pas. */
            'state'  => true,
            'advice' => ($plafond > 0) ? ''
                : __('Aucun plafond n\'est réglé : un scénario qui s\'emballe peut facturer sans limite côté box. Le réglage « Demandes par jour » de la configuration du plugin l\'arrête avant tout appel réseau.', __FILE__),
        );

        return $lignes;
    }

    /* ================================================= CYCLE DE VIE eqLogic */

    /*
     * Aucune exception ici : le cœur crée l'équipement avec son seul nom, et
     * une validation stricte rendrait le bouton « Ajouter » définitivement
     * inopérant, sans message exploitable.
     */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
            /* La tuile de discussion porte une zone de saisie : plus étroite,
             * elle deviendrait inutilisable sur un tableau de bord. */
            $this->setDisplay('width', '480px');
        }
    }

    public function postSave() {
        $this->createCommands();

        /* Les dossiers de travail sont créés à l'installation, mais un plugin
         * déployé par copie de fichiers ne passe pas par install.php : sans ce
         * filet, la première demande échouerait sur un dossier absent. */
        try {
            k2000beJournal::prepare();
        } catch (Throwable $e) {
            self::tracer('error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* DB::remove() met l'id à null avant postRemove : le nettoyage se fait ici,
     * tant que l'identifiant est encore lisible. */
    public function preRemove() {
        try {
            k2000beJournal::effacer($this->getId());
        } catch (Throwable $e) {
            self::tracer('debug', __('Nettoyage impossible :', __FILE__) . ' ' . $e->getMessage());
        }
        return true;
    }

    /* Un assistant sans clé API ne peut rien faire : l'interface le dit plutôt
     * que de laisser l'utilisateur écrire une demande pour rien. */
    public function isConfigured() {
        return trim((string) config::byKey('apikey', __CLASS__, '')) !== '';
    }

    /* ============================================================ COMMANDES */

    /*
     * Création idempotente. On ne récrit jamais une commande existante : sa
     * visibilité, son historisation et son affichage appartiennent à
     * l'utilisateur dès qu'il y a touché.
     */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }

        $cmd = new k2000beCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);

        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement, pas seulement cette commande. */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);

        if (isset($_options['order']))  { $cmd->setOrder($_options['order']); }
        if (isset($_options['icon']))   { $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>'); }
        if (isset($_options['title_disable'])) { $cmd->setDisplay('title_disable', $_options['title_disable']); }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    /*
     * Les logicalId sont figés : l'interface, le widget et les scénarios des
     * utilisateurs s'appuient dessus. En renommer un reviendrait à casser tous
     * les scénarios écrits avant la mise à jour.
     */
    public function createCommands() {
        $order = 0;

        /* La seule commande visible par défaut : c'est la tuile de discussion,
         * et elle suffit. Neuf widgets empilés rendraient le tableau de bord
         * illisible pour une fonction qui tient en une phrase. */
        $this->addCmdIfMissing('ask', 'Demander', 'action', 'message', array(
            'order' => $order++, 'isVisible' => 1, 'icon' => 'fas fa-comment-dots',
            'template' => __CLASS__,
            /* Le gabarit de discussion n'a pas de place pour le champ
             * « titre » du sous-type message, et personne n'a de titre à donner
             * à « éteins le salon ». */
            'title_disable' => 1,
        ));

        $this->addCmdIfMissing('reply', 'Réponse', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('status', 'Statut', 'info', 'string', array('order' => $order++));

        /* Le seul chiffre qui mérite un historique : combien de commandes
         * l'assistant a réellement envoyées. Une réponse ou un statut sont des
         * textes, les historiser n'apprendrait rien. */
        $this->addCmdIfMissing('actions', 'Actions exécutées', 'info', 'numeric', array(
            'order' => $order++, 'isHistorized' => 1,
        ));

        /* Le second, et le seul qui parle d'argent : ce que le tour a coûté.
         * Le détail par demande vit dans l'onglet Historique, mais il n'y vit
         * que demande par demande — personne n'additionne trois cents lignes
         * pour savoir si le mois dérape. Historisée, cette commande donne la
         * courbe, et un scénario peut s'en servir pour prévenir. */
        $this->addCmdIfMissing('jetons', 'Jetons consommés', 'info', 'numeric', array(
            'order' => $order++, 'isHistorized' => 1, 'icon' => 'fas fa-coins',
        ));

        $this->addCmdIfMissing('lastrun', 'Dernière demande', 'info', 'string', array(
            'order' => $order++, 'icon' => 'fas fa-clock',
        ));

        /* Ce que guette un scénario qui veut prévenir sur le téléphone qu'une
         * confirmation est attendue. */
        $this->addCmdIfMissing('pending', 'Confirmation en attente', 'info', 'binary', array(
            'order' => $order++, 'icon' => 'fas fa-hourglass-half',
        ));

        /* Le binaire ci-dessus dit qu'on attend quelque chose, pas quoi. Un
         * scénario qui annonce « K2000 attend votre accord » sans dire sur quoi
         * pousse à confirmer sans savoir — exactement ce que la confirmation
         * doit empêcher. Cette commande porte donc la phrase, et le widget du
         * tableau de bord s'en sert. */
        $this->addCmdIfMissing('question', 'Objet de la confirmation', 'info', 'string', array(
            'order' => $order++, 'icon' => 'fas fa-question-circle',
        ));

        $this->addCmdIfMissing('confirm', 'Confirmer', 'action', 'other', array(
            'order' => $order++, 'icon' => 'fas fa-check',
        ));
        $this->addCmdIfMissing('cancel', 'Annuler', 'action', 'other', array(
            'order' => $order++, 'icon' => 'fas fa-times',
        ));
        $this->addCmdIfMissing('reset', 'Nouvelle conversation', 'action', 'other', array(
            'order' => $order++, 'icon' => 'fas fa-eraser',
        ));
    }

    /* Un seul point d'écriture des commandes d'information : l'événement est
     * émis même si la valeur ne change pas, sinon deux réponses identiques à
     * deux minutes d'intervalle laisseraient le widget figé sur la première.
     *
     * Le masquage s'applique ici, et pas dans chaque appelant : « reply » reçoit
     * le message d'erreur quand le tour a échoué, et ce message vient parfois
     * d'OpenAI ou de curl, qui y recopient volontiers la clé. Masquée dans le
     * journal mais affichée en clair sur la tuile du tableau de bord, elle
     * n'aurait été masquée nulle part. masquer() rend les valeurs non
     * textuelles — les compteurs, les binaires — telles quelles. */
    private function publier($_logicalId, $_valeur) {
        $cmd = $this->getCmd('info', $_logicalId);
        if (is_object($cmd)) {
            $cmd->event(k2000beJournal::masquer($_valeur));
        }
    }

    /* ============================================================= VERROU */

    /*
     * Un tour de conversation à la fois par assistant.
     *
     * La conversation faisait un lire-modifier-écrire nu, là où le journal,
     * lui, prend un flock depuis toujours. Deux demandes simultanées sur le
     * même assistant — la tuile pendant qu'un scénario parle, deux onglets, un
     * F5 sur un POST — se lisaient donc le même fichier et la seconde écriture
     * écrasait la première : mémoire, frise et attente comprises, pendant que
     * « Confirmation en attente » avait été publiée par l'autre chemin.
     *
     * Le même verrou règle la confirmation jouée deux fois : confirm() lisait
     * l'attente, vérifiait le jeton, exécutait, et n'enregistrait qu'ensuite.
     * Deux appels ayant lu avant que le premier n'écrive passaient tous les
     * deux, et la commande partait deux fois — la porte même que la
     * confirmation existe pour tenir.
     *
     * Le second appelant est refusé, pas mis en file d'attente, et c'est un
     * choix : le verrou est tenu pendant des appels réseau qui durent des
     * dizaines de secondes. Attendre ferait patienter un navigateur ou un
     * scénario derrière une porte close, jusqu'à ce que PHP le tue au passage
     * de max_execution_time, pour finalement jouer une demande devenue
     * obsolète. Refuser tout de suite est immédiat, explicite, et laisse la
     * main à celui qui parle.
     *
     * Rend une poignée de fichier si le verrou est pris, false s'il est tenu
     * ailleurs, null si la box ne sait pas le créer — dans ce dernier cas le
     * tour se joue quand même : un dossier data/ en lecture seule doit faire
     * perdre la protection, pas la réponse.
     */
    private function verrouiller() {
        $chemin = null;
        try {
            $chemin = k2000beJournal::racine() . '/' . 'conversations';
            if (!is_dir($chemin)) {
                k2000beJournal::prepare();
            }
        } catch (Throwable $e) {
            return null;
        }
        /* Un verrou par assistant : deux assistants n'ont ni mémoire ni
         * fichier communs, les faire attendre l'un pour l'autre n'aurait pas de
         * sens. Le fichier est un fichier à lui, jamais la conversation
         * elle-même : celle-ci est remplacée par rename(), donc par un autre
         * inode, ce qui relâcherait le verrou au milieu de l'opération. */
        $poignee = @fopen($chemin . '/.tour-' . (int) $this->getId() . '.lock', 'c');
        if ($poignee === false) {
            return null;
        }
        if (!@flock($poignee, LOCK_EX | LOCK_NB)) {
            @fclose($poignee);
            return false;
        }
        return $poignee;
    }

    private function deverrouiller($_poignee) {
        if ($_poignee === null || $_poignee === false) {
            return;
        }
        @flock($_poignee, LOCK_UN);
        @fclose($_poignee);
    }

    /* La phrase du refus, écrite une fois : elle est rendue à la tuile, au
     * scénario et à la page, qui doivent dire la même chose. */
    private static function messageOccupe() {
        return __('Une demande est déjà en cours pour cet assistant : rien n\'a été exécuté. Attendez sa réponse avant de reparler.', __FILE__);
    }

    /* ======================================================= CONVERSATION */

    /* La conversation enregistrée, toujours sous sa forme complète : les
     * appelants n'ont pas à se demander si une clé existe. */
    private function conversation() {
        $conversation = null;
        try {
            $conversation = k2000beJournal::charger($this->getId());
        } catch (Throwable $e) {
            self::tracer('error', __('Conversation illisible :', __FILE__) . ' ' . $e->getMessage());
        }
        if (!is_array($conversation)) {
            $conversation = array();
        }
        if (!isset($conversation['messages']) || !is_array($conversation['messages'])) {
            $conversation['messages'] = array();
        }
        if (!isset($conversation['tours']) || !is_array($conversation['tours'])) {
            $conversation['tours'] = array();
        }
        if (!isset($conversation['attente']) || !is_array($conversation['attente'])) {
            $conversation['attente'] = null;
        }
        $conversation['version'] = 1;
        return $conversation;
    }

    /* La phrase qui dit ce que l'assistant attend. Écrite une fois : le widget,
     * le scénario et la frise doivent poser la même question, sans quoi
     * l'utilisateur confirmerait sur un libellé et exécuterait autre chose. */
    public static function resumeAttente($_attente) {
        $demande = self::demandeAttendue($_attente);
        return ($demande === null) ? '' : (string) $demande['titre'];
    }

    /*
     * La commande sur laquelle porte une attente, ou null.
     *
     * Une attente en porte exactement une, et le code le dit désormais partout
     * au lieu de boucler. Le pluriel d'hier était une promesse en l'air :
     * nouvelleAttente() n'a jamais su en fabriquer plus d'une — l'outil
     * s'arrête au premier « confirmation_required » et prie le modèle de ne
     * plus rien appeler — mais confirm(), resumeAttente() et la réponse par
     * défaut itéraient « demandes » comme s'il pouvait y en avoir plusieurs. Le
     * lecteur y voyait un groupage qui n'existait nulle part, et la première
     * refactorisation l'aurait cru.
     *
     * La clé enregistrée reste une liste d'un seul élément : des confirmations
     * écrites par une version antérieure dorment dans les fichiers de
     * conversation, et le numéro de version du format ne se change pas d'ici.
     * C'est la seule raison de cette forme ; rien d'autre ne s'appuie dessus.
     */
    private static function demandeAttendue($_attente) {
        if (!is_array($_attente) || !isset($_attente['demandes']) || !is_array($_attente['demandes'])) {
            return null;
        }
        $demandes = array_values($_attente['demandes']);
        if (count($demandes) === 0 || !is_array($demandes[0])) {
            return null;
        }
        $demande = $demandes[0];
        $demande['titre'] = isset($demande['titre']) ? (string) $demande['titre'] : '';
        return $demande;
    }

    /* La confirmation en attente, ou null. Une attente périmée n'en est plus
     * une : la proposer encore ferait exécuter un « oui » d'il y a une heure. */
    public function attente() {
        $conversation = $this->conversation();
        $attente = $conversation['attente'];
        if (!is_array($attente)) {
            return null;
        }
        if (isset($attente['expire']) && $attente['expire'] < time()) {
            /*
             * Une attente périmée ne se contente pas d'être ignorée : tant
             * qu'elle traîne dans la conversation, la commande « Confirmation
             * en attente » reste à 1. La tuile du tableau de bord continue donc
             * de réclamer un accord le lendemain matin, et un scénario branché
             * dessus ne redescend jamais. On l'efface ici, seul chemin commun à
             * la page, au widget et à la tâche de ménage.
             */
            $this->oublierAttente($conversation);
            return null;
        }
        return $attente;
    }

    /* Efface une attente devenue caduque et remet les deux commandes qui en
     * dépendent dans l'état où elles doivent être : plus rien n'attend. */
    private function oublierAttente($_conversation) {
        /*
         * L'écriture se fait sous verrou, et seulement si personne ne joue un
         * tour. La tâche de ménage, la page et le widget passent ici sans
         * prévenir : récrire la conversation qu'ils ont lue par-dessus celle
         * d'un tour en cours reviendrait à annuler ce tour — messages et
         * étapes compris — pour effacer une attente que ce tour-là aura de
         * toute façon remplacée. Tant pis pour le nettoyage, il aura lieu au
         * passage suivant.
         */
        $verrou = $this->verrouiller();
        if ($verrou !== false) {
            try {
                $conversation = $_conversation;
                $conversation['attente'] = null;
                k2000beJournal::enregistrer($this->getId(), $conversation);
            } catch (Throwable $e) {
                self::tracer('error', __('Attente périmée non effacée :', __FILE__) . ' ' . $e->getMessage());
            } finally {
                $this->deverrouiller($verrou);
            }
        }

        /* Les commandes, elles, sont publiées dans tous les cas : c'est
         * l'affichage d'une confirmation qui n'a plus lieu d'être, et il ne
         * coûte rien de le corriger deux fois. */
        $this->publier('pending', 0);
        $this->publier('question', '');
    }

    /*
     * La boucle. Envoie la demande, exécute les outils que la sécurité
     * autorise, recommence jusqu'à la réponse finale, la confirmation ou la
     * limite.
     *
     * Deux règles de l'API tiennent toute la mécanique :
     *  - un message d'assistant portant des tool_calls doit être suivi d'un
     *    message « tool » par tool_call_id, sans exception. Un refus, une
     *    limite atteinte ou une attente de confirmation produisent donc eux
     *    aussi un message tool, sinon l'appel suivant est rejeté en bloc ;
     *  - la conversation qui part au modèle est reconstruite à chaque tour avec
     *    l'invite système en tête : celle-ci n'est jamais enregistrée, la date
     *    qu'elle contient serait fausse dès le lendemain.
     */
    private function jouer($_messages, $_etapes = array(), $_contexte = array()) {
        $messages = array_values($_messages);
        $etapes = array_values($_etapes);
        $attente = null;
        $statut = self::STATUT_SUCCESS;
        $reponse = '';
        $erreur = null;
        $jetons = array('invite' => 0, 'reponse' => 0, 'total' => 0, 'cache' => 0);
        $modele = trim((string) config::byKey('model', __CLASS__, k2000beOpenAI::MODELE_DEFAUT));
        $limite = false;
        $conclu = false;
        $tronque = false;
        $executes = 0;

        /* Le budget d'attente de get_states est celui d'un tour : un scénario
         * qui enchaîne deux demandes dans le même processus PHP ne doit pas
         * voir la seconde privée d'attente parce que la première l'a dépensé. */
        k2000beOutils::nouvelleDemande();

        /* La mémoire d'avant la demande, pour le cas où le tour ne produirait
         * rien du tout : voir plus bas, à « memoire ». */
        $avant = (isset($_contexte['avant']) && is_array($_contexte['avant']))
            ? array_values($_contexte['avant']) : $messages;
        $debut = isset($_contexte['debut']) ? (float) $_contexte['debut'] : microtime(true);
        $jalon = (isset($_contexte['jalon']) && is_callable($_contexte['jalon'])) ? $_contexte['jalon'] : null;

        $budget = self::budget();
        /* Ce que coûte au pire un aller-retour, pour ne pas en commencer un
         * qu'on sait ne pas pouvoir finir. C'est une minoration — appel() fait
         * deux tentatives et chat() peut corriger la charge — mais une
         * minoration suffit : elle empêche d'entamer le dernier appel à trois
         * secondes de la coupure, là où l'ancien code en commençait un de
         * soixante. */
        $cout = k2000beOpenAI::delai();

        $maxOutils = self::reglageEntier('max_tool_calls', self::MAX_OUTILS_DEFAUT, self::MAX_OUTILS_MAX);
        $allersMax = $maxOutils + self::ALLERS_SUPPLEMENTAIRES;

        /* Les identifiants d'appel déjà employés dans cette conversation :
         * deux tool_calls portant le même id rendraient la conversation
         * irrecevable pour l'API, et pour de bon. */
        $identifiants = self::identifiantsConnus($messages);

        try {
            /*
             * L'invite est construite UNE fois par tour, et le catalogue aussi :
             * d'un aller-retour à l'autre, la requête ne fait que s'allonger, et
             * tout ce qui a déjà été envoyé repart à l'identique, octet pour
             * octet — c'est la condition du cache d'OpenAI, qui ne reconnaît
             * qu'un début strictement commun. Les messages mémorisés repartent
             * eux aussi tels qu'ils ont été écrits : les résultats d'outils sont
             * des chaînes figées par enJson(), les appels sont ceux que
             * normaliserAppels() a déjà remis d'aplomb. D'une demande à l'autre,
             * seule la fin de l'invite change — voir systemPrompt().
             */
            $systeme = array('role' => 'system', 'content' => $this->systemPrompt());

            /* Le catalogue d'outils ne change pas d'un tour à l'autre : le
             * relire à chaque aller-retour relirait la base pour rien. */
            /* Le catalogue dépend de l'assistant : l'outil de verdict n'existe
             * que pour celui à qui on a déclaré des états décisifs. Annoncer un
             * outil qui répondrait toujours « rien à surveiller » coûterait des
             * jetons à chaque demande de tous les autres. */
            $catalogue = k2000beOutils::definitions($this);

            for ($aller = 1; $aller <= $allersMax; $aller++) {
                /*
                 * Le budget de temps, vérifié avant chaque appel et pas après :
                 * après, la coupure serait déjà passée. Le premier aller part
                 * toujours, quoi qu'en dise l'horloge — refuser d'appeler le
                 * modèle une seule fois reviendrait à ne rien faire du tout,
                 * et une box réglée serré ne pourrait plus jamais répondre.
                 */
                if ($aller > 1 && (microtime(true) - $debut) + $cout > $budget) {
                    $limite = true;
                    $statut = self::STATUT_LIMIT;
                    $etapes[] = array(
                        'outil'  => 'budget',
                        'titre'  => __('Temps imparti dépassé', __FILE__),
                        'statut' => 'erreur',
                        'detail' => sprintf(__('La demande dure depuis plus de %s secondes : la boucle s\'arrête avant que PHP ne coupe le processus au milieu d\'une action.', __FILE__), $budget),
                    );
                    self::tracer('info', $this->getHumanName() . ' : ' . __('budget de temps dépassé, le tour s\'arrête.', __FILE__));
                    break;
                }

                /* Passé la limite, on reprend la parole sans outil : c'est la
                 * seule façon d'obliger le modèle à conclure avec ce qu'il
                 * sait, au lieu d'appeler un onzième outil. */
                $outils = $limite ? array() : $catalogue;

                $retour = k2000beOpenAI::chat(array_merge(array($systeme), $messages), $outils);

                $jetons['invite'] += (int) $retour['usage']['invite'];
                $jetons['reponse'] += (int) $retour['usage']['reponse'];
                $jetons['total'] += (int) $retour['usage']['total'];
                $jetons['cache'] += isset($retour['usage']['cache']) ? (int) $retour['usage']['cache'] : 0;
                $modele = $retour['modele'];

                $message = is_array($retour['message']) ? $retour['message'] : array();
                $contenu = (isset($message['content']) && is_string($message['content'])) ? trim($message['content']) : '';
                $appels = (isset($message['tool_calls']) && is_array($message['tool_calls'])) ? $message['tool_calls'] : array();
                $finish = isset($retour['finish']) ? (string) $retour['finish'] : 'stop';

                /*
                 * La raison d'arrêt était calculée puis jetée. Deux valeurs ne
                 * peuvent pas l'être :
                 *
                 *  - « content_filter » est un refus du fournisseur, pas une
                 *    réponse. Le taire rendait une phrase vide et un SUCCESS ;
                 *  - « length » dit que la réponse est coupée net. Elle rendait
                 *    un SUCCESS et une phrase amputée : l'utilisateur lisait
                 *    une demi-réponse, le scénario lisait une réussite.
                 */
                if ($finish === 'content_filter') {
                    throw new Exception(__('Le fournisseur du modèle a refusé de produire cette réponse (filtre de contenu). Reformulez la demande.', __FILE__));
                }
                $tronque = ($finish === 'length');

                if (count($appels) === 0) {
                    $messages[] = array('role' => 'assistant', 'content' => $contenu);
                    $reponse = $contenu;
                    $conclu = true;
                    break;
                }

                /*
                 * Les appels sont remis d'aplomb avant d'être recopiés : un
                 * tool_call sans id, ou à id déjà employé, empoisonnait la
                 * conversation pour de bon. Le message tool reprenait l'id tel
                 * quel — vide s'il manquait — l'API refusait EN BLOC au tour
                 * suivant, et comme la conversation fautive était enregistrée,
                 * l'assistant restait mort jusqu'au prochain reset, sur un 400
                 * que personne n'aurait rattaché à cela. Cela arrive avec
                 * « finish_reason: length » au milieu des tool_calls, ou
                 * derrière une passerelle « compatible OpenAI » imparfaite.
                 */
                $appels = self::normaliserAppels($appels, $identifiants);
                if (count($appels) === 0) {
                    /* Il ne restait rien d'exploitable : on n'écrit pas un
                     * message assistant avec un tool_calls vide, que l'API
                     * refuserait à son tour. */
                    $messages[] = array('role' => 'assistant', 'content' => $contenu);
                    $reponse = $contenu;
                    $conclu = true;
                    $etapes[] = array(
                        'outil'  => 'appel',
                        'titre'  => __('Appel d\'outil inutilisable', __FILE__),
                        'statut' => 'erreur',
                        'detail' => __('Le modèle a demandé des outils sans identifiant exploitable : rien n\'a pu être exécuté.', __FILE__),
                    );
                    break;
                }

                /*
                 * INVARIANT : à partir d'ici et jusqu'à la fin de la boucle
                 * « foreach », rien ne doit s'intercaler entre le message
                 * assistant et ses réponses tool. L'intégrité tool_calls ↔ tool
                 * tient par construction — le message est ajouté, puis TOUTES
                 * ses réponses le sont, sans qu'aucun appel susceptible de
                 * lever ne passe entre les deux — et c'est la seule raison pour
                 * laquelle une conversation interrompue (refus, limite,
                 * confirmation, panne) reste envoyable à l'API. Glisser ici un
                 * enregistrement, une publication ou un appel réseau casserait
                 * l'invariant sans qu'aucun essai hors ligne ne s'en aperçoive :
                 * c'est l'API, en production, qui le dirait.
                 */
                $messages[] = array(
                    'role'       => 'assistant',
                    'content'    => ($contenu === '') ? null : $contenu,
                    'tool_calls' => array_values($appels),
                );

                foreach ($appels as $appel) {
                    $identifiant = (string) $appel['id'];
                    $nom = isset($appel['function']['name']) ? (string) $appel['function']['name'] : '';
                    $arguments = self::arguments($appel);

                    if ($attente !== null) {
                        /* Une confirmation suspend tout : les outils suivants du
                         * même message ne sont pas exécutés. Le modèle doit
                         * comprendre qu'ils ne sont pas refusés, seulement
                         * remis à plus tard. */
                        $resultat = array(
                            'status' => 'pending_confirmation',
                            'reason' => 'Une confirmation humaine est attendue : cet outil sera rejoué après la réponse de l\'utilisateur.',
                        );
                    } elseif ($executes >= $maxOutils) {
                        $limite = true;
                        $resultat = array(
                            'status' => 'limit_reached',
                            'reason' => 'Limite d\'outils atteinte pour cette demande. Conclus avec ce que tu sais déjà.',
                        );
                    } else {
                        $sortie = k2000beOutils::executer($nom, $arguments, $this);
                        $executes++;

                        $resultat = isset($sortie['resultat']) ? $sortie['resultat'] : null;
                        if (isset($sortie['etape']) && is_array($sortie['etape'])) {
                            $etapes[] = $sortie['etape'];
                        }
                        if (isset($sortie['attente']) && is_array($sortie['attente'])) {
                            $attente = self::nouvelleAttente($sortie['attente']);
                        }
                    }

                    $messages[] = array(
                        'role'         => 'tool',
                        'tool_call_id' => $identifiant,
                        'content'      => self::enJson($resultat),
                    );
                }

                /* Fin de l'invariant : les réponses tool sont toutes écrites,
                 * la conversation est de nouveau cohérente. C'est ici, et
                 * seulement ici, qu'on a le droit de sortir ou d'enregistrer. */

                if ($attente !== null) {
                    /* Arrêt net. La conversation est enregistrée telle quelle
                     * par l'appelant : confirm() la reprendra exactement là,
                     * avec ses tool_calls déjà répondus. */
                    $statut = self::STATUT_CONFIRMATION;
                    $reponse = $contenu;
                    break;
                }

                if ($tronque) {
                    /* Le modèle a été coupé au milieu de ses appels d'outils :
                     * ce qu'il a demandé a été exécuté et répondu, mais il ne
                     * faut pas repartir sur une intention tronquée. */
                    $statut = self::STATUT_LIMIT;
                    $reponse = $contenu;
                    break;
                }

                /*
                 * Point de reprise. La conversation connue à cet instant est
                 * écrite avant de repartir vers le réseau, parce que ce qui
                 * précède contient peut-être des commandes déjà parties dans la
                 * maison : si PHP tue le processus pendant l'appel suivant, le
                 * fichier garde la trace de ce qui a été fait au lieu de
                 * repartir comme si rien ne s'était passé. Il est posé ici,
                 * hors de l'invariant, jamais entre un tool_calls et ses tool.
                 */
                if ($jalon !== null && count($etapes) > 0) {
                    try {
                        call_user_func($jalon, $messages);
                    } catch (Throwable $e) {
                        /* Un point de reprise raté ne doit pas faire perdre la
                         * réponse que l'utilisateur attend. */
                        self::tracer('debug', __('Point de reprise non écrit :', __FILE__) . ' ' . $e->getMessage());
                    }
                }

                if ($limite) {
                    $statut = self::STATUT_LIMIT;
                    $etapes[] = array(
                        'outil'  => 'limite',
                        'titre'  => __('Limite atteinte', __FILE__),
                        'statut' => 'erreur',
                        'detail' => sprintf(__('Limite de %s outils atteinte pour cette demande : la réponse ne tient compte que de ce qui précède.', __FILE__), $maxOutils),
                    );
                    $messages[] = array(
                        'role'    => 'system',
                        'content' => 'Tu as atteint la limite d\'outils autorisés pour cette demande. Conclus maintenant, en français, avec ce que tu sais, et dis clairement ce que tu n\'as pas pu vérifier.',
                    );
                }
            }
        } catch (Throwable $e) {
            /* Une panne du modèle n'est pas un refus : elle ne doit pas être
             * racontée à l'utilisateur comme une règle de la maison. */
            $erreur = $e->getMessage();
            $statut = self::STATUT_ERROR;
            self::tracer('error', $this->getHumanName() . ' : ' . $erreur);
            /*
             * La panne se raconte après ce qui a été fait, jamais à sa place.
             * Constaté : confirmation acceptée, portail ouvert, l'appel suivant
             * échoue, et la phrase lue par l'utilisateur était le seul message
             * d'erreur — elle ne disait pas que le portail était ouvert, alors
             * que les étapes, elles, le savaient.
             */
            $erreur = self::apresCoup($etapes, $erreur);
        }

        if ($erreur === null && !$conclu && $attente === null && !$tronque) {
            /* Le garde-fou a joué : le modèle tourne sans conclure. */
            $statut = self::STATUT_LIMIT;
        }

        if ($erreur === null && $tronque && $statut !== self::STATUT_CONFIRMATION) {
            /* Le plafond de jetons a coupé la phrase : le dire, sans quoi
             * l'utilisateur lit une moitié de réponse en la croyant entière.
             * C'est un plafond qui a arrêté le tour, donc LIMIT — et surtout
             * pas le SUCCESS que rendait une demi-réponse. Une confirmation
             * née malgré la coupure, elle, garde son statut : elle attend une
             * réponse humaine, et c'est le seul message qui compte. */
            $statut = self::STATUT_LIMIT;
            $coupe = __('(réponse coupée : le plafond de jetons de la configuration a été atteint)', __FILE__);
            $reponse = (trim((string) $reponse) === '')
                ? __('Ma réponse a été coupée avant d\'avoir commencé : le plafond de jetons de la configuration est trop bas pour cette demande.', __FILE__)
                : trim((string) $reponse) . ' ' . $coupe;
            $etapes[] = array(
                'outil'  => 'longueur',
                'titre'  => __('Réponse coupée', __FILE__),
                'statut' => 'erreur',
                'detail' => __('Le modèle a atteint le plafond de jetons de réponse : ce qui précède est incomplet. Augmentez max_tokens dans la configuration du plugin, ou posez une question plus étroite.', __FILE__),
            );
        }

        /* Relevé AVANT que la phrase par défaut ne comble le vide : c'est le
         * silence du modèle qu'on juge, pas la phrase que le plugin écrit à sa
         * place. */
        $muet = (trim((string) $reponse) === '');

        if ($erreur === null && $muet) {
            $reponse = self::reponseParDefaut($statut, $attente);
        }

        $statut = self::statutDuTour($statut, $etapes, $muet);

        /*
         * Ce que la mémoire garde d'un tour qui n'a rien produit : rien.
         *
         * Après deux pannes réseau, la mémoire contenait trois « user »
         * d'affilée, et le modèle répondait aux trois questions d'un coup au
         * tour suivant — une conversation que personne n'a eue. La demande
         * n'est donc conservée que si le modèle l'a vue faire quelque chose :
         * dès qu'une étape existe, tout est gardé, parce que des commandes ont
         * pu partir et que le modèle doit le savoir. Sinon on revient à la
         * mémoire d'avant. Rien n'est perdu pour l'utilisateur : la frise et le
         * journal, eux, gardent la question et la panne.
         */
        if ($erreur !== null && count($etapes) === 0) {
            $messages = $avant;
        }

        return array(
            'messages' => $messages,
            'reponse'  => $reponse,
            'statut'   => $statut,
            'etapes'   => $etapes,
            'attente'  => $attente,
            'jetons'   => $jetons,
            'modele'   => $modele,
            'erreur'   => $erreur,
        );
    }

    /*
     * Le statut final, selon la grille écrite au-dessus des constantes.
     *
     * Elle ne s'applique qu'à un tour resté « SUCCESS » : une confirmation, une
     * limite ou une panne se sont déjà nommées elles-mêmes, et rien de ce qui
     * suit ne doit les recouvrir.
     */
    private static function statutDuTour($_statut, $_etapes, $_muet) {
        if ($_statut !== self::STATUT_SUCCESS) {
            return $_statut;
        }

        $bilan = self::bilanEtapes($_etapes);

        if ($bilan['total'] > 0 && $bilan['utiles'] === 0) {
            /*
             * Tous les outils du tour ont échoué. queDesRefus() ne comptait que
             * les refus : un nom d'outil inconnu, des arguments illisibles ou
             * une exception du cœur laissaient le statut à SUCCESS, et le
             * scénario ne distinguait plus « j'ai répondu » de « rien n'a
             * marché mais j'ai bavardé ».
             *
             * Dès qu'une panne s'en mêle, c'est ERROR : un refus s'explique à
             * l'utilisateur, une panne se corrige par l'administrateur, et les
             * confondre enverrait l'un chercher ce que l'autre doit réparer.
             */
            return ($bilan['erreurs'] > 0) ? self::STATUT_ERROR : self::STATUT_REFUSED;
        }

        if ($bilan['total'] === 0 && $_muet) {
            /* Ni texte, ni outil : le modèle n'a rien rendu d'exploitable. Ce
             * n'est pas une réponse, c'est un tour perdu — et un scénario qui
             * lisait SUCCESS enchaînait sur « Je n'ai rien à ajouter. ». */
            return self::STATUT_ERROR;
        }

        return self::STATUT_SUCCESS;
    }

    /*
     * Ce que les étapes disent d'un tour : combien ont produit quelque chose,
     * combien ont été refusées, combien ont échoué.
     *
     * « Utile » vaut pour une lecture réussie, une commande envoyée ou une
     * simulation : dans les trois cas le modèle a obtenu de quoi répondre. Une
     * confirmation en attente compte aussi — c'est un résultat, et le tour
     * porte alors le statut CONFIRMATION de toute façon.
     */
    private static function bilanEtapes($_etapes) {
        $bilan = array('total' => 0, 'utiles' => 0, 'refus' => 0, 'erreurs' => 0);
        foreach ($_etapes as $etape) {
            $statut = isset($etape['statut']) ? (string) $etape['statut'] : '';
            if ($statut === '') {
                continue;
            }
            $bilan['total']++;
            if ($statut === 'refus') {
                $bilan['refus']++;
            } elseif ($statut === 'erreur') {
                $bilan['erreurs']++;
            } else {
                $bilan['utiles']++;
            }
        }
        return $bilan;
    }

    /*
     * Une panne qui suit une action dit d'abord ce qui a été fait.
     *
     * Seules les commandes réellement parties sont citées : une lecture ou une
     * simulation n'ont rien changé dans la maison, et les annoncer donnerait à
     * l'utilisateur un compte rendu d'actions qui n'ont pas eu lieu.
     */
    private static function apresCoup($_etapes, $_erreur) {
        $faits = array();
        foreach ($_etapes as $etape) {
            $outil = isset($etape['outil']) ? $etape['outil'] : '';
            $statut = isset($etape['statut']) ? $etape['statut'] : '';
            $titre = isset($etape['titre']) ? trim((string) $etape['titre']) : '';
            if ($outil === 'execute_command' && $statut === 'ok' && $titre !== '') {
                $faits[] = $titre;
            }
        }
        if (count($faits) === 0) {
            return $_erreur;
        }
        return sprintf(__('%s : la commande est bien partie. En revanche, la suite de la demande a échoué : %s', __FILE__),
            implode(', ', $faits), $_erreur);
    }

    /*
     * Remet d'aplomb les tool_calls rendus par le modèle.
     *
     * Deux défauts se réparent ici, et un seul se jette :
     *  - un appel sans identifiant en reçoit un de secours. L'API n'exige pas
     *    que l'identifiant vienne d'elle, seulement que le message tool cite
     *    exactement celui du tool_call ;
     *  - un appel dont l'identifiant a déjà servi dans cette conversation est
     *    écarté : deux réponses tool portant le même id sont irrattrapables,
     *    et exécuter deux fois le même appel serait pire que de n'en exécuter
     *    aucun.
     *
     * $_connus est tenu à jour d'un aller à l'autre : la conversation entière
     * compte, pas seulement le message en cours.
     */
    private static function normaliserAppels($_appels, &$_connus) {
        $retenus = array();
        foreach ($_appels as $appel) {
            if (!is_array($appel)) {
                continue;
            }
            $identifiant = isset($appel['id']) ? trim((string) $appel['id']) : '';

            if ($identifiant === '') {
                $identifiant = self::identifiantDeSecours();
                self::tracer('info', __('Appel d\'outil sans identifiant : un identifiant de secours est employé.', __FILE__));
            }
            if (isset($_connus[$identifiant])) {
                self::tracer('info', sprintf(__('Appel d\'outil à identifiant déjà employé (%s) : il est écarté.', __FILE__), $identifiant));
                continue;
            }

            $_connus[$identifiant] = true;
            $appel['id'] = $identifiant;
            if (!isset($appel['type'])) {
                $appel['type'] = 'function';
            }
            $retenus[] = $appel;
        }
        return $retenus;
    }

    /* Les identifiants d'appel déjà présents dans une conversation. */
    private static function identifiantsConnus($_messages) {
        $connus = array();
        foreach ($_messages as $message) {
            if (!isset($message['tool_calls']) || !is_array($message['tool_calls'])) {
                continue;
            }
            foreach ($message['tool_calls'] as $appel) {
                if (is_array($appel) && isset($appel['id']) && (string) $appel['id'] !== '') {
                    $connus[(string) $appel['id']] = true;
                }
            }
        }
        return $connus;
    }

    /* Un identifiant qui ne peut pas entrer en collision avec ceux du modèle,
     * ni avec un autre de secours tiré dans la même seconde. */
    private static function identifiantDeSecours() {
        return 'call_k2000be_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
    }

    /* Les arguments d'un appel d'outil arrivent en JSON, dans une chaîne. Un
     * modèle qui bafouille rend parfois une chaîne vide ou tronquée : c'est un
     * appel sans argument, pas une panne. */
    private static function arguments($_appel) {
        $brut = isset($_appel['function']['arguments']) ? $_appel['function']['arguments'] : array();
        if (is_array($brut)) {
            return $brut;
        }
        $decode = json_decode((string) $brut, true);
        return is_array($decode) ? $decode : array();
    }

    /* Ce que voit le modèle d'un résultat d'outil : du JSON, jamais du PHP. */
    private static function enJson($_valeur) {
        $json = json_encode($_valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($json === false) ? '{"status":"error","reason":"resultat illisible"}' : $json;
    }

    /*
     * Le jeton d'une confirmation, et sa date de péremption.
     *
     * Une attente porte UNE demande : l'outil s'arrête au premier
     * « confirmation_required » et prie le modèle de ne rien appeler d'autre,
     * de sorte qu'aucun chemin n'en produit deux. La clé « demandes » reste
     * néanmoins une liste — voir demandeAttendue() pour la seule raison de
     * cette forme.
     */
    private static function nouvelleAttente($_demande) {
        return array(
            'jeton'    => self::jeton(),
            'creee'    => time(),
            'expire'   => time() + self::ATTENTE_DUREE,
            'demandes' => array(array(
                'command_id' => isset($_demande['command_id']) ? (int) $_demande['command_id'] : 0,
                'titre'      => isset($_demande['titre']) ? (string) $_demande['titre'] : '',
                'valeur'     => isset($_demande['valeur']) ? $_demande['valeur'] : null,
                'motif'      => isset($_demande['motif']) ? (string) $_demande['motif'] : '',
            )),
        );
    }

    private static function jeton() {
        try {
            return substr(bin2hex(random_bytes(self::JETON_LONGUEUR)), 0, self::JETON_LONGUEUR);
        } catch (Throwable $e) {
            /* random_bytes() lève si la box n'a pas de source d'aléa. Le jeton
             * reste alors imprévisible en pratique, et vaut mieux qu'une
             * confirmation impossible à demander. */
            return substr(md5(uniqid((string) mt_rand(), true)), 0, self::JETON_LONGUEUR);
        }
    }

    /* Un modèle qui appelle un outil et s'arrête ne dit rien à l'utilisateur :
     * c'est au plugin d'écrire la phrase, sinon la frise affiche un vide. */
    private static function reponseParDefaut($_statut, $_attente) {
        if ($_statut === self::STATUT_CONFIRMATION) {
            $resume = self::resumeAttente($_attente);
            if ($resume !== '') {
                return sprintf(__('Cette action demande votre confirmation : %s.', __FILE__), $resume);
            }
        }
        if ($_statut === self::STATUT_LIMIT) {
            return __('Je me suis arrêté avant d\'avoir terminé : trop d\'opérations pour une seule demande. Reformulez en plus court.', __FILE__);
        }
        return __('Je n\'ai rien à ajouter.', __FILE__);
    }

    /* ============================================================== DEMANDE */

    /*
     * Le point d'entrée : une phrase en français, une réponse et ce qui a été
     * fait pour l'obtenir.
     */
    public function ask($_texte, $_options = array()) {
        $debut = microtime(true);
        $texte = trim((string) $_texte);
        $utilisateur = trim((string) (isset($_options['utilisateur']) ? $_options['utilisateur'] : ''));
        if ($utilisateur === '') {
            $utilisateur = self::utilisateur();
        }

        if ($texte === '') {
            return $this->incident(__('Demande vide : il n\'y a rien à traiter.', __FILE__), $texte, $utilisateur, $debut);
        }
        if (!$this->isConfigured()) {
            return $this->incident(__('Aucune clé API n\'est renseignée dans la configuration du plugin : l\'assistant ne peut pas répondre.', __FILE__), $texte, $utilisateur, $debut);
        }

        /*
         * Le plafond du jour, avant le verrou et avant tout appel réseau :
         * dépassé, la demande ne coûte rien du tout.
         *
         * Il ne s'applique qu'ici, et pas à confirm() : une confirmation
         * reprend un tour déjà commencé, déjà payé, et déjà à moitié joué sur
         * la maison. Couper là laisserait une action envoyée sans que personne
         * n'aille en vérifier l'effet — un volet à mi-course et un assistant
         * muet valent moins que la poignée de jetons économisés.
         */
        $plafond = self::plafondJournalier();
        if ($plafond > 0) {
            $consommation = k2000beJournal::consommationDuJour();
            if ($consommation['demandes'] >= $plafond) {
                return $this->incident(
                    sprintf(__('Plafond du jour atteint : %s demandes ont déjà été facturées aujourd\'hui. Le réglage « Demandes par jour » se change dans la configuration du plugin, et le compte repart à minuit.', __FILE__), $plafond),
                    $texte, $utilisateur, $debut, self::STATUT_REFUSED);
            }
        }

        $verrou = $this->verrouiller();
        if ($verrou === false) {
            return $this->incident(self::messageOccupe(), $texte, $utilisateur, $debut, self::STATUT_REFUSED);
        }

        try {
            $conversation = $this->conversation();

            /* Une demande neuve prime sur une confirmation laissée en plan : si
             * l'utilisateur repose une question au lieu de répondre oui ou non,
             * c'est qu'il a changé d'avis. Le portail ne s'ouvrira pas au
             * prochain « confirmer » d'un onglet resté ouvert. */
            $conversation['attente'] = null;

            $avant = $conversation['messages'];
            $messages = $avant;
            $messages[] = array('role' => 'user', 'content' => $texte);

            $resultat = $this->jouer($messages, array(), array(
                'debut' => $debut,
                'avant' => $avant,
                'jalon' => $this->jalon($conversation),
            ));
            return $this->enregistrer($conversation, $texte, $resultat, $utilisateur, $debut);
        } finally {
            $this->deverrouiller($verrou);
        }
    }

    /*
     * Le point de reprise confié à la boucle : il écrit la conversation telle
     * qu'elle est connue entre deux appels au modèle, sans toucher à la frise
     * ni au journal — ceux-là racontent un tour terminé, et un tour en cours
     * n'en est pas un.
     *
     * Il ne sert qu'à une chose : si PHP tue le processus au milieu de la
     * boucle, la conversation garde les commandes déjà parties et le modèle,
     * au tour suivant, sait ce qu'il a fait au lieu de le refaire.
     */
    private function jalon($_conversation) {
        $conversation = $_conversation;
        $eqLogic = $this;
        return function ($_messages) use ($conversation, $eqLogic) {
            $intermediaire = $conversation;
            $intermediaire['messages'] = array_values($_messages);
            /* L'attente, elle, n'existe pas encore : un tour interrompu ne doit
             * pas laisser derrière lui une confirmation que personne n'a
             * demandée. */
            $intermediaire['attente'] = null;
            k2000beJournal::enregistrer($eqLogic->getId(), $intermediaire);
        };
    }

    /*
     * Reprend une conversation suspendue. Les commandes en attente sont
     * exécutées (ou non), puis la main revient au modèle : c'est lui qui
     * vérifie l'état obtenu et conclut, de sorte que la confirmation ne
     * ressemble pas à un dialogue coupé en deux.
     */
    public function confirm($_jeton, $_accepte) {
        $debut = microtime(true);
        $utilisateur = self::utilisateur();
        $accepte = ($_accepte === true || $_accepte === 1 || $_accepte === '1');

        /*
         * Tout ce qui suit — lire l'attente, la consommer, exécuter, jouer,
         * enregistrer — se fait sous verrou. C'est ce qui empêche deux appels
         * d'ouvrir le portail deux fois. Le second est refusé sur-le-champ
         * plutôt que mis en attente : voir verrouiller().
         */
        $verrou = $this->verrouiller();
        if ($verrou === false) {
            return $this->incident(self::messageOccupe(), '', $utilisateur, $debut, self::STATUT_REFUSED);
        }

        try {
            return $this->confirmer($_jeton, $accepte, $utilisateur, $debut);
        } finally {
            $this->deverrouiller($verrou);
        }
    }

    /* Le corps de confirm(), une fois le verrou tenu. */
    private function confirmer($_jeton, $_accepte, $_utilisateur, $_debut) {
        $conversation = $this->conversation();
        $attente = $conversation['attente'];
        $demande = self::demandeAttendue($attente);

        if ($demande === null) {
            /*
             * Une attente périmée est effacée par attente(), que la page, le
             * widget et la tâche de ménage appellent tous ; une attente
             * consommée l'est par le tour qui vient de la jouer. Arrivé ici, on
             * ne peut plus distinguer « elle a expiré » de « elle a déjà été
             * traitée ». Le message dit donc les deux, plutôt que de dépendre
             * de qui a ouvert la page entre-temps. Dans les deux cas rien n'a
             * été exécuté : c'est un refus, pas une panne.
             */
            return $this->incident(
                __('Cette confirmation ne correspond plus à la demande en cours : elle a expiré ou a déjà été traitée, et rien n\'a été exécuté. Reformulez la demande.', __FILE__),
                '', $_utilisateur, $_debut, self::STATUT_REFUSED
            );
        }
        if ((string) $attente['jeton'] !== (string) $_jeton) {
            /* Deux onglets ouverts, ou un scénario qui rejoue un vieux jeton :
             * dans les deux cas, ce n'est pas la demande en cours. Rien n'est
             * parti non plus : refus, et pas erreur. */
            return $this->incident(
                __('Cette confirmation ne correspond plus à la demande en cours : rien n\'a été exécuté.', __FILE__),
                '', $_utilisateur, $_debut, self::STATUT_REFUSED
            );
        }

        $resume = self::resumeAttente($attente);

        /*
         * LE JETON EST CONSOMMÉ ICI, et écrit sur le disque, avant la moindre
         * exécution.
         *
         * Auparavant l'attente n'était effacée qu'à l'enregistrement, tout à la
         * fin du tour : deux appels qui avaient lu le fichier avant que le
         * premier n'écrive passaient tous les deux le contrôle du jeton, et la
         * commande partait deux fois — constaté, deux envois, SUCCESS les deux
         * fois. Le verrou suffirait ; l'écriture immédiate tient quand même,
         * parce qu'une box qui ne sait pas poser de verrou joue le tour sans
         * lui, et parce que le processus peut mourir au milieu de la boucle.
         *
         * Le second appel trouve alors une attente vide et repart sur « ne
         * correspond plus à la demande en cours ».
         */
        $conversation['attente'] = null;
        try {
            k2000beJournal::enregistrer($this->getId(), $conversation);
        } catch (Throwable $e) {
            self::tracer('error', __('Confirmation non consommée :', __FILE__) . ' ' . $e->getMessage());
        }
        $this->publier('pending', 0);
        $this->publier('question', '');

        if (isset($attente['expire']) && $attente['expire'] < time()) {
            /* Rien n'a été envoyé : ce n'est pas un incident, c'est un refus
             * par forfait. L'attente vient d'être effacée, le bandeau de
             * confirmation disparaît au lieu d'inviter à un clic sans effet. */
            return $this->incident(
                sprintf(__('La confirmation a expiré : rien n\'a été exécuté pour « %s ». Reformulez la demande.', __FILE__), $resume),
                '', $_utilisateur, $_debut, self::STATUT_REFUSED
            );
        }

        $avant = $conversation['messages'];
        $messages = $avant;
        $etapes = array();

        if (!$_accepte) {
            $demandeTexte = sprintf(__('Annulation : %s', __FILE__), $resume);
            $etapes[] = array(
                'outil'  => 'execute_command',
                'titre'  => $demande['titre'],
                'statut' => 'refus',
                'detail' => __('Confirmation refusée par l\'utilisateur : la commande n\'a pas été envoyée.', __FILE__),
            );
            $messages[] = array(
                'role'    => 'system',
                'content' => 'L\'utilisateur a refusé la confirmation : ' . $resume . '. Rien n\'a été envoyé. Prends-en acte en une phrase, sans insister et sans proposer de contourner.',
            );

            $resultat = $this->jouer($messages, $etapes, array(
                'debut' => $_debut,
                'avant' => $avant,
                'jalon' => $this->jalon($conversation),
            ));
            if ($resultat['statut'] === self::STATUT_SUCCESS) {
                $resultat['statut'] = self::STATUT_REFUSED;
            }
            return $this->enregistrer($conversation, $demandeTexte, $resultat, $_utilisateur, $_debut);
        }

        $demandeTexte = sprintf(__('Confirmation : %s', __FILE__), $resume);
        $fait = $this->executerConfirmee($demande);
        $etapes[] = $fait['etape'];
        $messages[] = array(
            'role'    => 'system',
            'content' => 'L\'utilisateur a confirmé. ' . $fait['texte']
                . ' Vérifie l\'état par get_states si cela a du sens, puis réponds en une ou deux phrases.',
        );

        $resultat = $this->jouer($messages, $etapes, array(
            'debut' => $_debut,
            'avant' => $avant,
            'jalon' => $this->jalon($conversation),
        ));
        return $this->enregistrer($conversation, $demandeTexte, $resultat, $_utilisateur, $_debut);
    }

    /*
     * L'exécution d'une commande que l'utilisateur vient de confirmer.
     *
     * Elle passe par k2000beOutils, comme n'importe quelle exécution : c'est là
     * que vivent le contrôle, le mode simulation et la traduction de la valeur
     * vers la clé que le cœur attend. Écrire ici un second chemin d'exécution
     * reviendrait à entretenir deux règles de sécurité qui finiraient par
     * diverger — et c'est toujours la plus permissive des deux qu'on découvre
     * en production.
     *
     * Les contrôles sont donc rejoués : entre la demande et le clic,
     * l'administrateur a pu retirer l'autorisation, désactiver l'équipement ou
     * basculer le plugin en lecture seule. Une confirmation n'est pas un
     * laissez-passer, c'est un accord sur une action précise, à cet instant-là.
     */
    private function executerConfirmee($_demande) {
        $fait = k2000beOutils::executerApresConfirmation($_demande, $this);

        $etape = isset($fait['etape']) ? $fait['etape'] : array(
            'outil'  => 'execute_command',
            'titre'  => '',
            'statut' => 'erreur',
            'detail' => __('L\'exécution n\'a rien rendu.', __FILE__),
        );
        if ($etape['titre'] === '' && isset($_demande['titre'])) {
            $etape['titre'] = (string) $_demande['titre'];
        }

        /*
         * Ce qui repart au modèle vient du résultat de l'outil, jamais de la
         * frise. Les deux disent la vérité, mais à deux destinataires
         * différents : la frise nomme l'équipement pour l'administrateur, le
         * résultat de l'outil se tait sur ce que le modèle n'a pas à savoir —
         * un équipement masqué entre-temps, par exemple. Recopier la frise ici
         * rouvrirait la fuite que k2000beSecurite vient de fermer.
         */
        $resultat = (isset($fait['resultat']) && is_array($fait['resultat'])) ? $fait['resultat'] : array();
        $titre = (isset($resultat['command']) && $resultat['command'] !== '') ? $resultat['command'] : $etape['titre'];
        $detail = (isset($resultat['reason']) && $resultat['reason'] !== '') ? $resultat['reason'] : $etape['detail'];

        return array(
            'etape' => $etape,
            'texte' => trim($titre . ' : ' . $detail),
        );
    }

    /* ======================================================== ENREGISTREMENT */

    /*
     * Le seul point de sortie d'un tour : il enregistre la conversation, tient
     * la frise, écrit le journal et publie les commandes. Un seul endroit, donc
     * aucun chemin où la conversation serait sauvée sans que le journal le
     * sache — ou l'inverse.
     */
    private function enregistrer($_conversation, $_demande, $_resultat, $_utilisateur, $_debut) {
        $duree = round(microtime(true) - $_debut, 2);
        $conversation = $_conversation;

        $tours = self::reglageEntier('memoire_tours', self::MEMOIRE_DEFAUT, self::MEMOIRE_MAX);

        /* Le texte que l'utilisateur lit, et le seul : la frise, la tuile et le
         * journal doivent dire la même chose. Sur une panne, ce texte commence
         * par ce qui a réellement été fait — voir apresCoup(). */
        $texte = ($_resultat['erreur'] === null) ? $_resultat['reponse'] : $_resultat['erreur'];

        $conversation['messages'] = self::limiterMemoire($_resultat['messages'], $tours);
        $conversation['attente'] = $_resultat['attente'];
        $conversation['maj'] = time();

        $frise = $conversation['tours'];
        if (trim((string) $_demande) !== '') {
            $frise[] = array('role' => 'user', 'texte' => $_demande, 'date' => time());
        }
        $frise[] = array(
            'role'   => 'assistant',
            'texte'  => $texte,
            'date'   => time(),
            'etapes' => $_resultat['etapes'],
            'statut' => $_resultat['statut'],
        );
        if (count($frise) > self::TOURS_MAX) {
            $frise = array_slice($frise, -self::TOURS_MAX);
        }
        $conversation['tours'] = array_values($frise);

        try {
            k2000beJournal::enregistrer($this->getId(), $conversation);
        } catch (Throwable $e) {
            self::tracer('error', __('Conversation non enregistrée :', __FILE__) . ' ' . $e->getMessage());
        }

        $actions = self::compterActions($_resultat['etapes']);

        try {
            k2000beJournal::consigner($this->getId(), array(
                'date'        => time(),
                'eq'          => (int) $this->getId(),
                'assistant'   => $this->getName(),
                'utilisateur' => $_utilisateur,
                'demande'     => $_demande,
                'reponse'     => $texte,
                'statut'      => $_resultat['statut'],
                'modele'      => $_resultat['modele'],
                'jetons'      => $_resultat['jetons'],
                'duree'       => $duree,
                'etapes'      => $_resultat['etapes'],
            ));
        } catch (Throwable $e) {
            self::tracer('error', __('Journal non écrit :', __FILE__) . ' ' . $e->getMessage());
        }

        $this->publier('reply', $texte);
        $this->publier('status', $_resultat['statut']);
        $this->publier('actions', $actions);
        $this->publier('jetons', isset($_resultat['jetons']['total']) ? (int) $_resultat['jetons']['total'] : 0);
        $this->publier('lastrun', date('d/m/Y H:i:s'));
        $this->publier('pending', ($_resultat['attente'] === null) ? 0 : 1);
        $this->publier('question', self::resumeAttente($_resultat['attente']));

        return array(
            'reponse' => $_resultat['reponse'],
            'statut'  => $_resultat['statut'],
            'etapes'  => $_resultat['etapes'],
            'attente' => $_resultat['attente'],
            'jetons'  => $_resultat['jetons'],
            'modele'  => $_resultat['modele'],
            'duree'   => $duree,
            'erreur'  => $_resultat['erreur'],
        );
    }

    /*
     * Combien de commandes ont réellement été envoyées à la maison.
     *
     * Le statut « ok » ne suffit pas à le dire : c'est le statut par défaut de
     * toutes les étapes, et donc celui de chaque lecture réussie. Compter sur
     * lui seul ferait publier 3 sur « quelle température dans les chambres ? »,
     * dans une commande historisée et lue par les scénarios : « Actions
     * exécutées > 0 » préviendrait alors qu'on a agi sur la maison alors qu'on
     * a seulement posé une question.
     *
     * Seul execute_command agit ; et en mode simulation il rend « simule », pas
     * « ok », ce qui le laisse hors du compte — une simulation n'envoie rien.
     */
    private static function compterActions($_etapes) {
        $actions = 0;
        foreach ($_etapes as $etape) {
            $outil = isset($etape['outil']) ? $etape['outil'] : '';
            $statut = isset($etape['statut']) ? $etape['statut'] : '';
            if ($outil === 'execute_command' && $statut === 'ok') {
                $actions++;
            }
        }
        return $actions;
    }

    /*
     * La mémoire est coupée sur un début de tour, puis, si cela ne suffit pas,
     * à l'intérieur d'un tour — mais jamais entre un tool_calls et ses tool :
     * l'API rejette en bloc une conversation où un tool_call_id ne répond à
     * aucun tool_calls.
     *
     * Compter les tours ne suffisait pas. Un seul tour de trente outils fait
     * soixante et un messages, que « garder un tour » conservait en entier,
     * quelle que soit la valeur de memoire_tours, et qui repartaient en entier
     * à chaque demande suivante. C'est le chemin vers le 400
     * « context_length_exceeded » — lequel s'enregistre, se rejoue, et ne se
     * guérit qu'au reset.
     */
    private static function limiterMemoire($_messages, $_tours) {
        $messages = array_values($_messages);

        $debuts = array();
        foreach ($messages as $index => $message) {
            if (isset($message['role']) && $message['role'] === 'user') {
                $debuts[] = $index;
            }
        }
        if (count($debuts) > $_tours) {
            $messages = array_slice($messages, $debuts[count($debuts) - $_tours]);
        }

        /*
         * Puis les deux plafonds durs, en avançant de point de coupe en point
         * de coupe. Un point de coupe est un message qui n'est pas un « tool » :
         * commencer par un assistant qui appelle des outils est recevable —
         * ses réponses le suivent — commencer par une réponse tool ne l'est
         * pas. C'est ce qui permet de couper à l'intérieur d'un tour sans
         * jamais séparer un groupe.
         */
        while (count($messages) > 1
            && (count($messages) > self::MEMOIRE_MESSAGES_MAX || self::poids($messages) > self::MEMOIRE_OCTETS_MAX)) {
            $coupe = self::prochaineCoupe($messages);
            if ($coupe <= 0) {
                /* Un seul groupe, plus rien à couper : mieux vaut une mémoire
                 * trop lourde qu'une mémoire irrecevable. */
                break;
            }
            $messages = array_slice($messages, $coupe);
        }

        /* Filet : un fichier de conversation écrit par une version antérieure
         * pourrait commencer par un tool orphelin. */
        while (count($messages) > 0 && isset($messages[0]['role']) && $messages[0]['role'] === 'tool') {
            array_shift($messages);
        }
        return array_values($messages);
    }

    /* Le rang du prochain message par lequel la conversation peut commencer,
     * ou 0 s'il n'y en a plus. */
    private static function prochaineCoupe($_messages) {
        for ($index = 1; $index < count($_messages); $index++) {
            $role = isset($_messages[$index]['role']) ? $_messages[$index]['role'] : '';
            if ($role !== 'tool') {
                return $index;
            }
        }
        return 0;
    }

    /* Le poids de la mémoire tel qu'il partira sur le réseau : c'est le JSON
     * envoyé qui est facturé, pas le nombre de messages. */
    private static function poids($_messages) {
        $json = json_encode($_messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($json === false) ? 0 : strlen($json);
    }

    /*
     * Un incident : ni réponse, ni refus, une panne. Il est journalisé comme
     * les autres tours, sans quoi l'utilisateur chercherait dans l'historique
     * la trace d'une demande qui n'y serait pas — et il laisse une trace dans
     * la frise, pour la même raison, au premier rechargement de la page.
     */
    private function incident($_message, $_demande, $_utilisateur, $_debut, $_statut = self::STATUT_ERROR) {
        $resultat = array(
            'messages' => array(),
            'reponse'  => '',
            'statut'   => $_statut,
            'etapes'   => array(),
            'attente'  => null,
            'jetons'   => array('invite' => 0, 'reponse' => 0, 'total' => 0, 'cache' => 0),
            'modele'   => trim((string) config::byKey('model', __CLASS__, k2000beOpenAI::MODELE_DEFAUT)),
            'erreur'   => $_message,
        );
        $duree = round(microtime(true) - $_debut, 2);

        try {
            k2000beJournal::consigner($this->getId(), array(
                'date'        => time(),
                'eq'          => (int) $this->getId(),
                'assistant'   => $this->getName(),
                'utilisateur' => $_utilisateur,
                'demande'     => $_demande,
                'reponse'     => $_message,
                'statut'      => $_statut,
                'modele'      => $resultat['modele'],
                'jetons'      => $resultat['jetons'],
                'duree'       => $duree,
                'etapes'      => array(),
            ));
        } catch (Throwable $e) {
            self::tracer('error', $e->getMessage());
        }

        /*
         * L'attente telle qu'elle est vraiment, avant d'écrire quoi que ce
         * soit : un jeton d'un autre onglet laisse la confirmation en cours
         * debout, et republier 0 par réflexe ferait disparaître de la tuile une
         * demande à laquelle personne n'a encore répondu. attente() fait au
         * passage le ménage d'une attente périmée, ce qui est exactement ce
         * qu'il faut publier dans les autres cas.
         */
        $attente = null;
        try {
            $attente = $this->attente();
        } catch (Throwable $e) {
            self::tracer('debug', $e->getMessage());
        }

        $this->inscrireIncident($_message, $_demande, $_statut);

        /*
         * Un incident est une demande comme une autre : il est consigné au
         * journal, il s'inscrit dans la frise, et les commandes doivent donc le
         * décrire lui, pas le tour d'avant. Sans lastrun et actions, « Dernière
         * demande » et « Actions exécutées » continuaient de raconter un tour
         * qui n'était plus le dernier — et un scénario branché sur « Actions
         * exécutées > 0 » croyait qu'on venait d'agir sur la maison.
         */
        $this->publier('reply', $_message);
        $this->publier('status', $_statut);
        $this->publier('actions', 0);
        /* Un incident est un tour comme un autre : laisser « Jetons consommés »
         * sur le chiffre du tour précédent ferait compter deux fois une dépense
         * qui n'a pas eu lieu, dans une commande historisée. */
        $this->publier('jetons', isset($resultat['jetons']['total']) ? (int) $resultat['jetons']['total'] : 0);
        $this->publier('lastrun', date('d/m/Y H:i:s'));
        $this->publier('pending', ($attente === null) ? 0 : 1);
        $this->publier('question', self::resumeAttente($attente));

        return array(
            'reponse' => '',
            'statut'  => $_statut,
            'etapes'  => array(),
            'attente' => null,
            'jetons'  => $resultat['jetons'],
            'modele'  => $resultat['modele'],
            'duree'   => $duree,
            'erreur'  => $_message,
        );
    }

    /*
     * La trace d'un incident dans la frise.
     *
     * Sans elle, le message d'erreur ne vit que dans la réponse AJAX : il
     * s'affiche une fois, puis disparaît au premier rechargement de la page.
     * L'utilisateur qui revient chercher pourquoi sa demande n'a rien donné
     * trouve alors un onglet Discussion où sa question n'a jamais existé.
     *
     * Le tour écrit est de la même forme que ceux d'enregistrer() : la demande
     * en « user » quand il y en a une, puis la réponse en « assistant » avec
     * son statut et ses étapes — vides, puisque rien n'a été tenté. C'est ce
     * que desktop/js/k2000be.js attend pour peindre la frise.
     *
     * Deux précautions :
     *  - une demande vide n'écrit pas de tour « user », exactement comme dans
     *    enregistrer() : la frise afficherait une bulle sans texte ;
     *  - un incident qui se répète — une clé API absente, un scénario qui
     *    rejoue le même appel, une confirmation qui n'existe plus — ne s'écrit
     *    qu'une fois. Le même message, à la suite du même message et sans
     *    demande entre les deux, se contente de remonter sa date : sinon dix
     *    essais chasseraient de la frise les dix derniers vrais échanges.
     */
    private function inscrireIncident($_message, $_demande, $_statut) {
        try {
            /* La conversation est relue ici, et non reçue en argument : elle a
             * pu être enregistrée entre-temps par l'appelant — confirm() le
             * fait quand l'attente a expiré — et l'écraser avec une copie
             * antérieure ferait réapparaître la confirmation périmée. */
            $conversation = $this->conversation();
            $frise = $conversation['tours'];

            $demande = trim((string) $_demande);
            if ($demande !== '') {
                $frise[] = array('role' => 'user', 'texte' => $demande, 'date' => time());
            }

            $dernier = count($frise) - 1;
            $repetition = ($demande === '' && $dernier >= 0
                && isset($frise[$dernier]['role']) && $frise[$dernier]['role'] === 'assistant'
                && isset($frise[$dernier]['texte']) && $frise[$dernier]['texte'] === $_message
                && isset($frise[$dernier]['statut']) && $frise[$dernier]['statut'] === $_statut);

            if ($repetition) {
                $frise[$dernier]['date'] = time();
            } else {
                $frise[] = array(
                    'role'   => 'assistant',
                    'texte'  => $_message,
                    'date'   => time(),
                    'etapes' => array(),
                    'statut' => $_statut,
                );
            }

            if (count($frise) > self::TOURS_MAX) {
                $frise = array_slice($frise, -self::TOURS_MAX);
            }
            $conversation['tours'] = array_values($frise);
            $conversation['maj'] = time();

            k2000beJournal::enregistrer($this->getId(), $conversation);
        } catch (Throwable $e) {
            /* Un incident qu'on n'arrive pas à inscrire reste un incident : il
             * est déjà journalisé et déjà rendu à l'appelant. */
            self::tracer('error', __('Incident non inscrit dans la frise :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /* Oublier la conversation, et rien d'autre : le journal, lui, est une
     * archive et ne se vide qu'à la purge. */
    public function reset() {
        try {
            k2000beJournal::effacer($this->getId());
        } catch (Throwable $e) {
            self::tracer('error', __('Conversation non effacée :', __FILE__) . ' ' . $e->getMessage());
        }
        $this->publier('reply', '');
        $this->publier('status', '');
        $this->publier('actions', 0);
        $this->publier('jetons', 0);
        /* La date de la dernière demande partait avec le reste, sauf qu'elle
         * restait affichée : « Dernière demande » datait alors d'une
         * conversation que plus rien ne raconte. */
        $this->publier('lastrun', '');
        $this->publier('pending', 0);
        $this->publier('question', '');
        return true;
    }

    /* ============================================ DONNÉES POUR L'INTERFACE */

    /*
     * Tout ce qu'il faut pour peindre l'onglet Discussion en un seul appel.
     * Aucun accès au réseau : ouvrir un onglet ne doit rien coûter en jetons.
     */
    public function toAjax() {
        $conversation = $this->conversation();

        $compteurs = array('autorisees' => 0, 'confirmation' => 0, 'interdites' => 0, 'lisibles' => 0,
            'sensibles' => 0);
        try {
            $lus = k2000beSecurite::compteurs();
            if (is_array($lus)) {
                $compteurs = array_merge($compteurs, $lus);
            }
        } catch (Throwable $e) {
            /* Un décompte impossible ne doit pas vider la page de discussion. */
            self::tracer('debug', $e->getMessage());
        }

        return array(
            'id'        => (int) $this->getId(),
            'nom'       => $this->getName(),
            'configure' => $this->isConfigured(),
            'mode'      => k2000beSecurite::mode(),
            'modele'    => trim((string) config::byKey('model', __CLASS__, k2000beOpenAI::MODELE_DEFAUT)),
            'tours'     => $conversation['tours'],
            'attente'   => $this->attente(),
            'compteurs' => $compteurs,
            /* Une commande info jamais réglée suit ce défaut : sans lui,
             * l'onglet Autorisations ne saurait pas lequel de ses deux boutons
             * éclairer. */
            'lecture_defaut' => k2000beSecurite::lectureParDefaut(),
        );
    }

    /*
     * L'assistant visé, ou le premier actif. Un scénario et un widget n'ont pas
     * toujours d'identifiant sous la main, et la maison n'a le plus souvent
     * qu'un seul assistant.
     */
    public static function assistant($_id = null) {
        if ($_id !== null && $_id !== '') {
            $eqLogic = eqLogic::byId($_id);
            if (is_object($eqLogic) && $eqLogic->getEqType_name() === __CLASS__) {
                return $eqLogic;
            }
            return null;
        }
        $liste = eqLogic::byType(__CLASS__, true);
        return (count($liste) > 0) ? $liste[0] : null;
    }

    /* Le login de celui qui parle. Un appel venu d'un scénario ou du cron n'a
     * pas de session : l'appelant dit alors lui-même qui il est. */
    private static function utilisateur() {
        if (isset($_SESSION['user']) && is_object($_SESSION['user'])) {
            return $_SESSION['user']->getLogin();
        }
        return 'scenario';
    }

    /* ==================================================== INVITE SYSTÈME */

    /*
     * L'invite est reconstruite à chaque tour, jamais enregistrée : elle porte
     * la date et l'heure, et un résumé de la maison qui change tout le temps.
     * La figer reviendrait à répondre « il est 22 h » le lendemain midi.
     */
    /*
     * La fiche de la maison, prête à être glissée dans l'invite : l'en-tête,
     * puis une ligne par champ rempli, dans l'ordre de MAISON_CHAMPS.
     *
     * Rien du tout si aucun champ n'est rempli, et c'est important : une
     * en-tête orpheline annoncerait au modèle une description de la maison qui
     * ne vient jamais, ce qui vaut moins que le silence.
     */
    private static function ficheMaison() {
        $lignes = array();
        $budget = self::MAISON_FICHE_MAX;

        foreach (self::MAISON_CHAMPS as $cle => $libelle) {
            $valeur = self::aplatir((string) config::byKey($cle, __CLASS__, ''));
            /* Un champ qui ne contient que des espaces ou des retours à la
             * ligne n'est pas un champ rempli : il ne produit aucune ligne. */
            if ($valeur === '') {
                continue;
            }

            /* La place qui reste pour CE champ : jamais plus que la limite par
             * champ, jamais plus que ce que le plafond global laisse encore.
             * En dessous de vingt caractères, une ligne ne dirait plus rien
             * d'utile — on arrête là plutôt que de rendre un moignon. */
            $place = min(self::MAISON_CHAMP_MAX, $budget - mb_strlen($libelle) - 3);
            if ($place < 20) {
                break;
            }

            $ligne = $libelle . ' : ' . self::raccourcir($valeur, $place);
            $budget -= mb_strlen($ligne);
            $lignes[] = $ligne;
        }

        if (count($lignes) === 0) {
            return array();
        }
        array_unshift($lignes, self::MAISON_ENTETE);
        return $lignes;
    }

    /*
     * Un champ de formulaire sur une seule ligne. Les zones de saisie de la
     * page acceptent les retours à la ligne, et un retour au milieu d'un champ
     * casserait le bloc : la suite du champ passerait pour une ligne de la
     * fiche sans libellé, donc pour une information dont on ne sait plus ce
     * qu'elle décrit.
     */
    private static function aplatir($_texte) {
        $plat = preg_replace('/\s+/u', ' ', $_texte);
        /* preg_replace rend null sur une chaîne qui n'est pas de l'UTF-8
         * valide : le même motif sans /u ne juge alors que les octets, et vaut
         * mieux qu'un champ qui disparaît sans explication. */
        if ($plat === null) {
            $plat = preg_replace('/\s+/', ' ', $_texte);
        }
        return trim((string) $plat);
    }

    /*
     * Coupe au dernier mot entier, et le dit par des points de suspension : une
     * phrase amputée en plein milieu d'un mot se lit comme une donnée abîmée,
     * et le modèle essaie de deviner la fin. Le résultat tient toujours dans
     * $_maximum caractères, points de suspension compris.
     */
    private static function raccourcir($_texte, $_maximum) {
        if (mb_strlen($_texte) <= $_maximum) {
            return $_texte;
        }
        $coupe = mb_substr($_texte, 0, $_maximum - 1);
        $espace = mb_strrpos($coupe, ' ');
        /* Sauf si le dernier espace est si tôt qu'il ne resterait qu'un
         * fragment : un champ écrit d'un seul tenant, sans espace, vaut mieux
         * coupé net que réduit à trois lettres. */
        if ($espace !== false && $espace >= (int) ($_maximum / 2)) {
            $coupe = mb_substr($coupe, 0, $espace);
        }
        return rtrim($coupe, " ,;:.") . '…';
    }

    /*
     * Les consignes de cet assistant, bornées et sur une seule ligne.
     *
     * Les retours à la ligne sont remplacés par des espaces comme dans la fiche
     * de la maison, et pour la même raison : l'invite est un tableau de lignes
     * jointes par des retours, et un champ multiligne y ferait passer ses
     * propres lignes pour des consignes séparées, orphelines de leur en-tête.
     *
     * getConfiguration() sur une instance jamais enregistrée rend le défaut :
     * l'aperçu de l'invite, qui travaille parfois sur un assistant neuf, ne
     * lève donc pas.
     */
    private function consignes() {
        $texte = self::aplatir((string) $this->getConfiguration('consignes', ''));
        if ($texte === '') {
            return '';
        }
        return self::raccourcir($texte, self::CONSIGNES_MAX);
    }

    /*
     * Les types génériques décisifs de cet assistant, normalisés.
     *
     * Saisis en clair, séparés par des virgules ou des espaces. On majuscule et
     * on retire les doublons : le cœur écrit ses types génériques en
     * majuscules, et « alarm_state » saisi à la main ne doit pas produire un
     * assistant qui ne trouve jamais rien sans dire pourquoi.
     */
    public function typesDecisifs() {
        $brut = (string) $this->getConfiguration('verdict_types', '');
        $morceaux = preg_split('/[\s,;]+/u', $brut);
        if (!is_array($morceaux)) {
            return array();
        }
        $types = array();
        foreach ($morceaux as $morceau) {
            $type = strtoupper(trim($morceau));
            if ($type === '' || in_array($type, $types, true)) {
                continue;
            }
            $types[] = $type;
            if (count($types) >= self::VERDICT_TYPES_MAX) {
                break;
            }
        }
        return $types;
    }

    /*
     * L'invite système, rangée du plus stable au plus changeant.
     *
     * L'ordre n'est pas seulement une affaire de sens, il est aussi une affaire
     * de prix. OpenAI met de lui-même en cache le DÉBUT commun de deux
     * requêtes — les outils, puis les messages dans l'ordre, dès mille vingt-
     * quatre jetons environ — et facture ce début beaucoup moins cher, et le
     * sert plus vite. Mais le cache s'arrête au premier octet qui diffère :
     * tout ce qui vient après se paie plein pot.
     *
     * L'heure était la deuxième ligne. Elle change chaque minute, et chaque
     * minute elle rendait neuf tout ce qui la suivait — les règles, la fiche,
     * les consignes, puis toute la conversation. Elle passe donc en dernier,
     * avec le résumé de la maison, qui bouge dès qu'on touche à un équipement
     * ou à une autorisation. Ce qui reste devant ne change que lorsque
     * quelqu'un modifie la configuration : le ton, la ville, les règles, le
     * mode, la fiche, les consignes générales, puis celles de l'assistant.
     *
     * Ce qui passe derrière les consignes est fait de constats, pas de
     * consignes : « qui priment sur les précédentes » reste vrai, puisque plus
     * aucune consigne ne les suit.
     *
     * $_maintenant ne sert qu'au rejeu hors ligne, qui doit pouvoir construire
     * deux invites à une minute d'écart sans attendre une minute.
     */
    public function systemPrompt($_maintenant = null) {
        $lignes = array();

        $persona = trim((string) config::byKey('persona', __CLASS__, 'kitt'));
        if ($persona === 'sobre') {
            $lignes[] = 'Tu es l\'assistant domotique de cette maison. Tu réponds de façon neutre, factuelle et brève.';
        } else {
            $lignes[] = 'Tu es KITT, l\'assistant de cette maison, dans l\'esprit de la voiture intelligente de la série K2000 : courtois, direct, un brin formel. Tu n\'es jamais bavard et tu ne fais pas d\'humour appuyé.';
        }

        /* Jeedom connaît la ville de la box depuis sa page d'administration :
         * sans elle, le modèle situerait « il fait nuit » ou « ce soir » au
         * hasard. */
        $ville = trim((string) config::byKey('info::city', 'core', ''));
        if ($ville !== '') {
            $lignes[] = 'La maison se trouve à ' . $ville . '.';
        }

        $lignes[] = 'Tu ne pilotes rien directement. Tu demandes, et le plugin décide : il connaît les règles posées par le propriétaire de la maison, pas toi.';
        $lignes[] = 'Un refus n\'est pas une panne ni une erreur de ta part : c\'est une règle de la maison. Explique-la calmement, en une phrase, sans insister, sans proposer de la contourner et sans redemander la même chose autrement.';
        /*
         * La vérification, et le détail sans lequel elle se retourne contre
         * elle-même : un équipement met quelques secondes à remonter son état,
         * là où l'aller-retour vers le modèle en prend une. Relu tout de suite,
         * l'état est encore celui d'AVANT la commande, et le modèle annonce un
         * échec qui n'a pas eu lieu — puis refait l'action.
         */
        $lignes[] = 'Une commande envoyée n\'est pas une commande aboutie. Avant d\'affirmer qu\'une lumière est éteinte ou qu\'une porte est fermée, vérifie l\'état avec get_states, en lui passant wait (3 à 5 secondes) : la maison met quelques secondes à remonter ce qu\'elle a fait, et lue aussitôt, la valeur est encore celle d\'avant ta commande. Un état rendu avec « refreshed »: false n\'est pas un échec : l\'équipement n\'a simplement rien dit, dis-le ainsi.';
        $lignes[] = 'Découvre progressivement : list_rooms pour situer la maison, puis list_equipments sur la pièce utile, puis get_equipment sur les quelques équipements retenus. Ne demande jamais tout le catalogue d\'un coup : c\'est long, cher, et tu t\'y perds.';
        /*
         * Les appels groupés. Rien ne les bride côté API — aucun
         * parallel_tool_calls: false n'est envoyé — et la boucle exécute déjà
         * tous les appels d'un même message d'assistant. Il ne manquait que de
         * le dire : éteindre six choses une par une coûte six allers-retours
         * facturés et six fois l'attente du réseau, pour le même résultat.
         */
        $lignes[] = 'Quand plusieurs actions ou lectures sont indépendantes, demande-les dans le MÊME message : elles partent ensemble, en un seul aller-retour. N\'attends le résultat d\'un outil que lorsque le suivant en a besoin.';
        $lignes[] = 'Tu ne vois que ce que l\'administrateur t\'a laissé voir. Ce qui n\'apparaît pas dans les outils n\'existe pas pour toi : ne l\'invente pas, dis que tu ne le vois pas.';
        /*
         * L'ambiguïté. L'invite disait de ne pas inventer ce qu'on ne voit pas,
         * et rien de ce qu'il faut faire quand on voit TROIS candidats : trois
         * « Lumière » dans trois pièces, deux volets du même nom. Le modèle
         * choisissait, et une chance sur trois d'ouvrir le volet de la chambre
         * du bébé coûte bien plus cher qu'une question.
         */
        $lignes[] = 'Quand plusieurs équipements peuvent correspondre à ce qu\'on te demande — trois « Lumière » dans trois pièces, deux volets au même nom —, ne tranche pas au hasard : nomme-les et demande lequel. Se tromper de pièce coûte plus cher qu\'une question.';
        $lignes[] = 'Réponds court, en français, en disant ce que tu as fait et ce que tu n\'as pas pu faire. Pas de liste à puces si deux phrases suffisent.';

        /*
         * Le mode de sécurité se dit d'entrée, et non au premier résultat
         * d'outil. En lecture seule comme en simulation, le modèle l'apprenait
         * en s'y cognant : il promettait d'agir, puis devait se dédire au tour
         * suivant. Une ligne ici économise un aller-retour par demande et une
         * réponse qui se contredit. C'est un rappel, pas une garantie : ce qui
         * protège la maison est k2000beSecurite, qui refuse quoi qu'en pense le
         * modèle.
         */
        $mode = k2000beSecurite::mode();
        if ($mode === k2000beSecurite::MODE_LECTURE) {
            $lignes[] = 'La maison est en lecture seule : tu peux tout consulter, mais aucune commande ne partira, quelle qu\'elle soit. N\'en promets aucune, et dis-le simplement si on t\'en demande une.';
        } elseif ($mode === k2000beSecurite::MODE_SIMULATION) {
            $lignes[] = 'La maison est en mode simulation : une commande autorisée te sera rendue comme « simulated », c\'est-à-dire qu\'elle n\'est PAS partie. Annonce-la alors comme une simulation, jamais comme une action faite.';
        }

        /* La fiche de la maison vient avant les consignes libres : ce sont des
         * faits, et les consignes du propriétaire doivent pouvoir les nuancer
         * — l'ordre inverse ferait trancher le fait contre la consigne. */
        foreach (self::ficheMaison() as $ligne) {
            $lignes[] = $ligne;
        }

        /* Les consignes de l'administrateur passent en dernier : elles doivent
         * pouvoir nuancer ce qui précède, jamais l'inverse. */
        $extra = trim((string) config::byKey('prompt_extra', __CLASS__, ''));
        if ($extra !== '') {
            $lignes[] = 'Consignes du propriétaire de la maison : ' . $extra;
        }

        /*
         * Et celles de cet assistant-ci après elles, pour la même raison : le
         * particulier doit pouvoir nuancer le général. Un assistant de levée de
         * doute doit pouvoir être bref et méthodique là où la maison entière
         * demande qu'on soit courtois.
         */
        $propres = $this->consignes();
        if ($propres !== '') {
            $lignes[] = 'Consignes propres à cet assistant, qui priment sur les précédentes : ' . $propres;
        }

        /*
         * Ici commence ce qui change d'une demande à l'autre. Rien de stable ne
         * doit être ajouté en dessous : il perdrait le cache à chaque minute.
         *
         * Le résumé de la maison d'abord : il ne bouge qu'avec les équipements
         * et les autorisations, et deux demandes rapprochées le partagent.
         * C'est un décompte, pas une description : le faire passer après les
         * consignes ne leur retire rien, là où la fiche, elle, doit rester
         * devant elles pour qu'elles puissent la nuancer.
         */
        if ((int) config::byKey('contexte_maison', __CLASS__, 1) === 1) {
            try {
                $resume = trim((string) k2000beOutils::resumeMaison());
                if ($resume !== '') {
                    $lignes[] = 'État de la maison : ' . $resume;
                }
            } catch (Throwable $e) {
                /* Un résumé indisponible n'empêche pas de répondre : le modèle
                 * ira chercher par lui-même avec list_rooms. */
                self::tracer('debug', $e->getMessage());
            }
        }

        /* L'heure tout à la fin, puisqu'elle change chaque minute. Le modèle
         * la lit aussi bien en dernière ligne qu'en deuxième : c'est un fait,
         * et aucune consigne ne dépend de sa place. */
        $maintenant = ($_maintenant === null) ? time() : (int) $_maintenant;
        $date = self::JOURS[(int) date('w', $maintenant)] . ' ' . (int) date('j', $maintenant)
            . ' ' . self::MOIS[(int) date('n', $maintenant)] . ' ' . date('Y', $maintenant);
        $lignes[] = 'Nous sommes le ' . $date . ', il est ' . date('H:i', $maintenant) . '.';

        return implode("\n", $lignes);
    }

    public static function pluginVersion() {
        $path = __DIR__ . '/../../plugin_info/info.json';
        if (!is_readable($path)) {
            return '0';
        }
        $info = json_decode(file_get_contents($path), true);
        return isset($info['pluginVersion']) ? $info['pluginVersion'] : '0';
    }
}

class k2000beCmd extends cmd {

    /*
     * Aucune logique métier ici : la commande délègue à l'équipement.
     *
     * Deux chemins mènent à cette commande, et ils n'ont pas le même auteur :
     * un scénario, qui n'a aucune session, et la tuile du tableau de bord, qui
     * exécute « ask » par core/ajax/cmd.ajax.php sous la session de celui qui
     * clique. Nommer « scenario » dans les deux cas ferait journaliser une
     * ouverture de portail demandée depuis la tuile au nom de la maison
     * elle-même — alors que le journal existe précisément pour répondre, un
     * mois plus tard, à « qui a ouvert le portail ce soir-là ».
     *
     * On laisse donc ask() trancher : il lit la session par utilisateur() et ne
     * retient « scenario » qu'à défaut de session.
     */
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new Exception(__('Assistant introuvable.', __FILE__));
        }

        switch ($this->getLogicalId()) {
            case 'ask':
                /* Le sous-type message rend un titre et un message : c'est le
                 * message qui porte la demande, le titre n'a pas de sens ici et
                 * le widget le désactive. */
                $texte = trim((string) (isset($_options['message']) ? $_options['message'] : ''));
                if ($texte === '') {
                    throw new Exception(__('Aucun texte à transmettre à l\'assistant : renseignez le message.', __FILE__));
                }
                $resultat = $eqLogic->ask($texte);
                if ($resultat['erreur'] !== null) {
                    /* Un scénario doit échouer bruyamment : sans cela il
                     * continuerait comme si l'assistant avait répondu. */
                    throw new Exception($resultat['erreur']);
                }
                return $resultat['reponse'];

            case 'confirm':
            case 'cancel':
                /*
                 * Ces deux commandes prennent le jeton courant toutes seules :
                 * un scénario peut donc approuver une confirmation créée trois
                 * secondes plus tôt par un humain depuis la tuile, et
                 * inversement. C'est assumé — un scénario n'a pas de jeton sous
                 * la main, et lui en demander un le rendrait inutilisable — et
                 * c'est borné par ailleurs : la confirmation ne vaut que cinq
                 * minutes, elle ne porte que sur une commande précise, et les
                 * contrôles de sécurité sont rejoués au moment de l'exécution.
                 * La documentation doit le dire à l'administrateur : « Annuler »
                 * et « Confirmer » dans un scénario répondent à la dernière
                 * question posée, d'où qu'elle vienne.
                 */
                $attente = $eqLogic->attente();
                if ($attente === null) {
                    throw new Exception(__('Aucune confirmation n\'est en attente.', __FILE__));
                }
                $resultat = $eqLogic->confirm($attente['jeton'], $this->getLogicalId() === 'confirm');
                if ($resultat['erreur'] !== null) {
                    throw new Exception($resultat['erreur']);
                }
                return $resultat['reponse'];

            case 'reset':
                $eqLogic->reset();
                return true;
        }
        return true;
    }
}
