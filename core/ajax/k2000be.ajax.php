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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil, pas une hiérarchie :
     * isConnect('user') serait faux pour un administrateur. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    /* Depuis la 4.4, ajax::getToken() est déprécié et rend une chaîne vide :
     * contrôler un jeton ici casserait l'appel. L'authentification repose sur
     * la session et sur credentials: same-origin. */
    ajax::init();

    /* L'autoload du cœur ne connaît que la classe qui porte l'identifiant du
     * plugin : k2000beSecurite, k2000beJournal et k2000beOpenAI, appelées plus
     * bas, ne seraient jamais trouvées sans ce require. La classe principale,
     * elle, charge les quatre fichiers de service. */
    require_once __DIR__ . '/../class/k2000be.class.php';

    function k2000beAssistant($_id) {
        $eqLogic = eqLogic::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'k2000be') {
            throw new Exception(__('Assistant introuvable :', __FILE__) . ' ' . $_id);
        }
        return $eqLogic;
    }

    /*
     * Tout ce que l'écran d'un assistant a besoin de savoir : la frise des
     * échanges, la confirmation en attente s'il y en a une, le mode et les
     * compteurs. Lecture seule, rien ne part chez OpenAI : ouvrir un onglet ne
     * doit ni durer, ni coûter un jeton.
     */
    if (init('action') == 'data') {
        ajax::success(k2000beAssistant(init('id'))->toAjax());
    }

    /*
     * Une demande. C'est le seul appel qui peut durer : la boucle enchaîne
     * plusieurs allers-retours avec le modèle, chacun avec son propre délai.
     * Le JS relève son délai en conséquence et le dit à l'utilisateur.
     */
    if (init('action') == 'ask') {
        /* Le texte de la demande part chez OpenAI : cela n'a rien à faire dans
         * une démonstration publique. */
        unautorizedInDemo();
        $texte = trim(init('texte'));
        if ($texte === '') {
            throw new Exception(__('Écrivez d\'abord ce que vous attendez de l\'assistant.', __FILE__));
        }
        /* Le login vient de la session, jamais du formulaire : le journal doit
         * dire qui a réellement parlé, et une valeur envoyée par le navigateur
         * se choisit. */
        $utilisateur = (isset($_SESSION['user']) && is_object($_SESSION['user'])) ? $_SESSION['user']->getLogin() : '';
        ajax::success(k2000beAssistant(init('id'))->ask($texte, array('utilisateur' => $utilisateur)));
    }

    /*
     * La réponse à une confirmation. Le jeton vient du serveur et n'est valable
     * que cinq minutes : il est renvoyé tel quel, c'est lui qui rattache la
     * réponse à la conversation suspendue.
     */
    if (init('action') == 'confirm') {
        unautorizedInDemo();
        $accepte = (init('accepte') == 1);
        ajax::success(k2000beAssistant(init('id'))->confirm(init('jeton'), $accepte));
    }

    /* Vider la mémoire n'appelle personne, mais efface : c'est une action. */
    if (init('action') == 'reset') {
        unautorizedInDemo();
        k2000beAssistant(init('id'))->reset();
        ajax::success();
    }

    /*
     * L'arbre des autorisations : toutes les pièces, tous les équipements,
     * toutes leurs commandes. Il peut peser plusieurs milliers d'entrées — il
     * est donc demandé une seule fois, et l'écran travaille ensuite dessus.
     */
    if (init('action') == 'arbre') {
        ajax::success(k2000beSecurite::arbre());
    }

    /*
     * Politique d'une commande d'action. Les valeurs sont contrôlées ici :
     * une politique inconnue écrite dans la configuration d'une commande
     * vaudrait refus au prochain contrôle, sans que rien ne le dise.
     */
    if (init('action') == 'politique') {
        unautorizedInDemo();
        $politique = init('politique');
        $connues = array(
            k2000beSecurite::POLITIQUE_REFUS,
            k2000beSecurite::POLITIQUE_AUTORISE,
            k2000beSecurite::POLITIQUE_CONFIRMATION,
        );
        if (!in_array($politique, $connues, true)) {
            throw new Exception(__('Politique inconnue :', __FILE__) . ' ' . $politique);
        }
        k2000beSecurite::definirPolitique(init('cmd_id'), $politique);
        ajax::success();
    }

    /*
     * Lecture d'une commande d'information. La chaîne vide est une valeur à
     * part entière : elle rend la commande au réglage global « les états sont
     * lisibles sauf refus explicite », ce qui n'est ni « autorisée » ni
     * « masquée ».
     */
    if (init('action') == 'lecture') {
        unautorizedInDemo();
        $lecture = init('lecture');
        if (!in_array($lecture, array('', 'allow', 'deny'), true)) {
            throw new Exception(__('Valeur de lecture inconnue :', __FILE__) . ' ' . $lecture);
        }
        k2000beSecurite::definirLecture(init('cmd_id'), $lecture);
        ajax::success();
    }

    /* Masquer un équipement le retire entièrement du champ de vision de
     * l'assistant, lecture comprise. */
    if (init('action') == 'masquer') {
        unautorizedInDemo();
        k2000beSecurite::masquerEquipement(init('eqLogic_id'), (init('masque') == 1) ? 1 : 0);
        ajax::success();
    }

    /* Le journal, plus récent d'abord. La limite est bornée : demander dix
     * mille lignes ne renseignerait personne et chargerait la page pour rien.
     *
     * Le filtre de statut est appliqué par le journal, et non par la page : la
     * page ne verrait que les lignes déjà chargées, et répondrait « aucune
     * erreur » sur la foi des cinquante dernières demandes. Un statut inconnu
     * est écarté plutôt que transmis — il ne retiendrait rien, et la liste
     * vide passerait pour une réponse. */
    if (init('action') == 'historique') {
        $limite = (int) init('limite', 50);
        if ($limite < 1) {
            $limite = 1;
        }
        if ($limite > 500) {
            $limite = 500;
        }
        $statut = trim((string) init('statut', ''));
        $statuts = array(
            k2000be::STATUT_SUCCESS,
            k2000be::STATUT_CONFIRMATION,
            k2000be::STATUT_REFUSED,
            k2000be::STATUT_LIMIT,
            k2000be::STATUT_ERROR,
        );
        if (!in_array($statut, $statuts, true)) {
            $statut = '';
        }
        ajax::success(k2000beJournal::historique(init('id'), $limite, $statut));
    }

    /*
     * L'invite système telle qu'elle partirait, à l'instant. Le cahier des
     * charges demande que l'administrateur puisse voir ce qu'il envoie : la
     * page de configuration décrit champ par champ ce qui part, mais personne
     * n'avait jamais le texte complet sous les yeux.
     *
     * Rien ne sort vers OpenAI ici : l'invite est construite localement, comme
     * au début de chaque tour. C'est une lecture, pas une action — d'où
     * l'absence d'unautorizedInDemo(), et d'où l'absence de coût.
     *
     * L'assistant visé n'existe pas forcément : systemPrompt() est une méthode
     * d'instance par héritage, mais ne lit rien de l'équipement — uniquement la
     * configuration du plugin, le mode de sécurité et le résumé de la maison.
     * Une instance neuve suffit donc, et c'est indispensable : on remplit cette
     * page AVANT de créer le premier assistant.
     */
    if (init('action') == 'invite') {
        $assistant = k2000be::assistant();
        if (!is_object($assistant)) {
            $assistant = new k2000be();
        }
        $invite = $assistant->systemPrompt();
        ajax::success(array(
            'invite' => $invite,
            'taille' => mb_strlen($invite),
        ));
    }

    /* La liste des modèles vient d'OpenAI : c'est un appel réseau, donc une
     * action, même si elle ne change rien. */
    if (init('action') == 'modeles') {
        unautorizedInDemo();
        ajax::success(k2000beOpenAI::modeles());
    }

    /* L'essai de clé ne rend jamais la clé, seulement un verdict. */
    if (init('action') == 'essai') {
        unautorizedInDemo();
        ajax::success(k2000beOpenAI::essai());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/*
 * Throwable et non Exception : en PHP 8, une Error n'hérite pas d'Exception et
 * produirait un HTTP 500 sans corps JSON. Le JS du cœur réessaierait alors
 * trois fois avant d'afficher une erreur réseau incompréhensible.
 */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
