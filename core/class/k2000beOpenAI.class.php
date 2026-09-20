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
 * Le client HTTP de l'API OpenAI, réduit à ce dont l'assistant a besoin : un
 * appel de chat avec outils, la liste des modèles, un essai de clé.
 *
 * Ce fichier ne charge pas core.inc.php : il est toujours inclus depuis
 * k2000be.class.php, qui l'a déjà chargé. C'est ce qui permet au rejeu hors
 * ligne de l'inclure sans Jeedom.
 *
 * Deux contraintes dictent sa forme :
 *  - la clé API ne doit apparaître nulle part ailleurs que dans l'en-tête HTTP.
 *    Tout ce qui est journalisé ou renvoyé passe par k2000beJournal::masquer() ;
 *  - les messages d'erreur remontent tels quels jusqu'à l'utilisateur, dans la
 *    page du plugin comme dans un scénario. Ils sont donc écrits pour lui, en
 *    français, et disent ce qu'il faut faire.
 */
class k2000beOpenAI {

    /* La configuration vit sous l'identifiant du plugin, pas sous le nom de
     * cette classe : __CLASS__ vaudrait « k2000beOpenAI » et ne trouverait
     * rien. */
    const PLUGIN = 'k2000be';

    const BASE_URL_DEFAUT = 'https://api.openai.com/v1';
    const MODELE_DEFAUT = 'gpt-4o-mini';
    const MAX_TOKENS_DEFAUT = 1200;
    const TEMPERATURE_DEFAUT = 0.3;
    const TIMEOUT_DEFAUT = 60;

    /*
     * Les bornes du délai. Sous cinq secondes, aucun modèle n'a le temps de
     * répondre et le champ ne servirait qu'à produire des pannes ; au-dessus de
     * trois cents, une seule tentative dépasserait à elle seule le budget de
     * temps d'un tour, et PHP couperait le processus au lieu du plugin. Une
     * valeur hors bornes est remplacée, et le journal le dit : le champ ne doit
     * pas faire croire qu'on a réglé quelque chose.
     */
    const TIMEOUT_MIN = 5;
    const TIMEOUT_MAX = 300;

    /*
     * Le délai d'établissement de la connexion. Il ne s'ajoute pas au délai
     * global : CURLOPT_TIMEOUT couvre toute la requête, connexion comprise, et
     * c'est lui qui dit quand on abandonne. Celui-ci ne fait qu'une chose,
     * utile : constater plus tôt qu'une box n'a plus de réseau, en dix secondes
     * au lieu d'une minute d'attente vide.
     */
    const CONNECT_TIMEOUT = 10;

    /*
     * Une seule relance, après une seconde. Un 429 ou un 500 qui persiste une
     * seconde plus tard est une panne qui dure : insister ferait patienter
     * l'utilisateur devant une page figée sans rien changer au résultat.
     */
    const ATTENTE_RELANCE = 1;

    /*
     * Deux corrections de charge au plus : max_tokens, puis temperature. Le
     * détail est dans corriger() ; le chiffre est ici parce qu'il borne la
     * boucle et donc le nombre d'appels facturés.
     */
    const CORRECTIONS_MAX = 2;

    /*
     * Les modèles de raisonnement dépensent des jetons avant de répondre : un
     * plafond trop bas rendrait une réponse vide et ferait croire la clé
     * mauvaise alors qu'elle est bonne.
     */
    const ESSAI_MAX_TOKENS = 64;

    /*
     * /models rend tout le catalogue du compte, images et transcription
     * comprises. Proposer ces modèles-là dans la liste des modèles de
     * conversation, c'est proposer une panne : ils ne répondent pas à
     * /chat/completions.
     */
    const MODELES_EXCLUS = array(
        'embedding', 'whisper', 'tts', 'dall-e', 'moderation', 'audio',
        'image', 'transcribe', 'realtime', 'sora', 'davinci', 'babbage',
    );

    /*
     * Point d'injection du rejeu hors ligne : quand ce transport est fourni, il
     * remplace curl. Signature : function($_chemin, $_charge, $_options) et
     * rend le corps déjà décodé, ou lève. Il n'a aucun autre usage.
     *
     * Un corps rendu avec une clé « error » est traité comme une erreur de
     * l'API, son « status » faisant office de code HTTP : c'est ainsi que le
     * rejeu hors ligne peut éprouver le rattrapage de max_tokens.
     */
    public static $transport = null;

    /*
     * Code et détail bruts de la dernière erreur rendue par l'API. Ils servent
     * au rattrapage automatique de la charge : le message destiné à
     * l'utilisateur, lui, ne dit pas quel paramètre a déplu.
     */
    private static $code = 0;
    private static $detail = '';

    /* ============================================================== CHAT */

    /*
     * Un appel de chat. Rend le message brut du modèle, la raison d'arrêt, le
     * coût en jetons et le modèle réellement employé. Lève une Exception au
     * message français explicite sur échec.
     */
    public static function chat($_messages, $_outils = array(), $_options = array()) {
        $modele = trim((string) (isset($_options['modele']) ? $_options['modele'] : config::byKey('model', self::PLUGIN, self::MODELE_DEFAUT)));
        if ($modele === '') {
            $modele = self::MODELE_DEFAUT;
        }

        $charge = array(
            'model'    => $modele,
            'messages' => array_values($_messages),
        );

        /* Un tableau « tools » vide est refusé par l'API : il faut l'omettre.
         * C'est ce qui permet au moteur de rendre la main au modèle sans outil
         * pour lui faire conclure. */
        if (is_array($_outils) && count($_outils) > 0) {
            $charge['tools'] = array_values($_outils);
            $charge['tool_choice'] = 'auto';
        }

        /*
         * max_tokens garde une convention à lui : 0 veut dire « pas de
         * plafond », et le paramètre est alors omis de la charge. C'est la
         * seule des trois valeurs voisines qui ne soit pas une saisie de
         * travers mais un choix — d'où l'exception assumée, là où le délai et
         * le nombre d'outils sont ramenés dans leurs bornes en le disant. La
         * page de configuration doit l'écrire à côté du champ : « laisser à 0
         * pour ne pas plafonner la réponse ».
         */
        $maxTokens = (int) (isset($_options['max_tokens']) ? $_options['max_tokens'] : config::byKey('max_tokens', self::PLUGIN, self::MAX_TOKENS_DEFAUT));
        if ($maxTokens > 0) {
            $charge['max_tokens'] = $maxTokens;
        }
        $charge['temperature'] = (float) (isset($_options['temperature']) ? $_options['temperature'] : config::byKey('temperature', self::PLUGIN, self::TEMPERATURE_DEFAUT));

        $corps = null;
        $derniere = null;
        for ($essai = 0; $essai <= self::CORRECTIONS_MAX; $essai++) {
            try {
                $corps = self::appel('/chat/completions', $charge);
                $derniere = null;
                break;
            } catch (Throwable $e) {
                /* Un refus que l'on sait corriger ne remonte pas : le plugin se
                 * corrige tout seul, sans réglage de l'utilisateur. Les autres
                 * partent tels quels. */
                if (self::$code !== 400 || !self::corriger($charge)) {
                    throw $e;
                }
                $derniere = $e;
                self::journaliser('Charge corrigée après un refus de l\'API, nouvel essai.');
            }
        }

        /*
         * La boucle pouvait sortir sans break ni throw — trois corrections
         * acceptées d'affilée — et laissait alors l'utilisateur devant
         * « réponse sans message exploitable » au lieu du vrai refus de l'API.
         * C'est une sortie que le code actuel n'atteint pas (corriger() ne sait
         * réparer que deux choses, pour deux corrections autorisées), mais une
         * sortie muette n'a pas à attendre d'être atteinte pour être fermée.
         */
        if ($derniere !== null) {
            throw $derniere;
        }

        if (!is_array($corps) || !isset($corps['choices'][0]['message'])) {
            throw new Exception(__('OpenAI a répondu sans message exploitable. Réessayez, et changez de modèle si cela se reproduit.', __FILE__));
        }

        $choix = $corps['choices'][0];
        $usage = isset($corps['usage']) && is_array($corps['usage']) ? $corps['usage'] : array();

        return array(
            'message' => $choix['message'],
            'finish'  => isset($choix['finish_reason']) ? $choix['finish_reason'] : 'stop',
            'usage'   => array(
                'invite'  => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0,
                'reponse' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0,
                'total'   => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : 0,
            ),
            'modele'  => isset($corps['model']) ? $corps['model'] : $modele,
        );
    }

    /*
     * Rattrape les deux refus que les modèles récents opposent à une charge
     * pourtant valide pour les précédents. Rend vrai si la charge a changé et
     * mérite un nouvel essai.
     *
     * Le nom du modèle ne permet pas de deviner lequel des deux s'applique — la
     * liste change tous les mois, et un nom personnalisé ne dit rien. On envoie
     * donc la charge classique, et on corrige sur refus.
     */
    private static function corriger(&$_charge) {
        $detail = strtolower(self::$detail);
        if ($detail === '') {
            return false;
        }

        /* « Unsupported parameter: 'max_tokens' is not supported with this
         * model. Use 'max_completion_tokens' instead. » */
        if (isset($_charge['max_tokens']) && strpos($detail, 'max_tokens') !== false) {
            $_charge['max_completion_tokens'] = $_charge['max_tokens'];
            unset($_charge['max_tokens']);
            return true;
        }

        /* « Unsupported value: 'temperature' does not support 0.3 with this
         * model. Only the default (1) is supported. » La retirer vaut mieux que
         * la forcer à 1 : on obtient la même chose sans prétendre choisir. */
        if (isset($_charge['temperature']) && strpos($detail, 'temperature') !== false) {
            unset($_charge['temperature']);
            return true;
        }

        return false;
    }

    /* ============================================================ MODÈLES */

    /* Les identifiants de modèles du compte, triés, débarrassés de ceux qui ne
     * savent pas tenir une conversation. */
    public static function modeles() {
        $corps = self::appel('/models');
        $sortie = array();

        $liste = isset($corps['data']) && is_array($corps['data']) ? $corps['data'] : array();
        foreach ($liste as $modele) {
            $id = '';
            if (is_array($modele) && isset($modele['id'])) {
                $id = (string) $modele['id'];
            } elseif (is_string($modele)) {
                $id = $modele;
            }
            if ($id === '') {
                continue;
            }
            $minuscule = strtolower($id);
            $exclu = false;
            foreach (self::MODELES_EXCLUS as $motif) {
                if (strpos($minuscule, $motif) !== false) {
                    $exclu = true;
                    break;
                }
            }
            if (!$exclu) {
                $sortie[] = $id;
            }
        }

        $sortie = array_values(array_unique($sortie));
        sort($sortie);
        return $sortie;
    }

    /*
     * L'essai du bouton « Tester la clé ». Ne lève jamais : un essai qui échoue
     * est un résultat, pas une panne, et l'interface doit pouvoir l'afficher.
     */
    public static function essai() {
        $cle = trim((string) config::byKey('apikey', self::PLUGIN, ''));
        if ($cle === '' && self::$transport === null) {
            return array(
                'ok'      => false,
                'message' => __('Aucune clé API n\'est renseignée dans la configuration du plugin.', __FILE__),
                'modele'  => '',
            );
        }

        try {
            $retour = self::chat(
                array(array('role' => 'user', 'content' => 'Reponds uniquement par OK.')),
                array(),
                array('max_tokens' => self::ESSAI_MAX_TOKENS)
            );
            return array(
                'ok'      => true,
                'message' => __('Clé acceptée, le modèle a répondu.', __FILE__),
                'modele'  => $retour['modele'],
            );
        } catch (Throwable $e) {
            return array(
                'ok'      => false,
                'message' => $e->getMessage(),
                'modele'  => '',
            );
        }
    }

    /* =============================================================== HTTP */

    /*
     * Le délai d'une tentative, borné et dit.
     *
     * Publique parce que la boucle de conversation en a besoin : c'est le coût
     * minimal d'un aller-retour, et donc ce qu'elle doit comparer à son budget
     * de temps avant d'en commencer un de plus.
     *
     * Un champ vide ou absurde reprend le défaut, une valeur hors bornes est
     * ramenée dans les bornes, et le journal le note. Auparavant « timeout = 1 »
     * devenait 60 sans que rien ne le dise : trois champs voisins avaient trois
     * conventions muettes, et l'utilisateur croyait avoir réglé un délai d'une
     * seconde.
     */
    public static function delai() {
        $brut = config::byKey('timeout', self::PLUGIN, self::TIMEOUT_DEFAUT);
        $timeout = (int) $brut;

        if ($timeout < 1) {
            return self::TIMEOUT_DEFAUT;
        }
        if ($timeout < self::TIMEOUT_MIN) {
            self::journaliser('Délai « ' . $timeout . ' » trop court : ' . self::TIMEOUT_MIN . ' s est employé.');
            return self::TIMEOUT_MIN;
        }
        if ($timeout > self::TIMEOUT_MAX) {
            self::journaliser('Délai « ' . $timeout . ' » trop long : ' . self::TIMEOUT_MAX . ' s est employé.');
            return self::TIMEOUT_MAX;
        }
        return $timeout;
    }

    /*
     * L'unique porte de sortie vers le réseau. Rend le corps décodé, ou lève
     * une Exception dont le message est destiné à l'utilisateur final.
     */
    protected static function appel($_chemin, $_charge = null, $_timeout = null) {
        self::$code = 0;
        self::$detail = '';

        $timeout = ($_timeout === null) ? self::delai() : (int) $_timeout;
        $options = array(
            'timeout' => $timeout,
            'methode' => ($_charge === null) ? 'GET' : 'POST',
        );

        if (self::$transport !== null) {
            $corps = call_user_func(self::$transport, $_chemin, $_charge, $options);
            if (!is_array($corps)) {
                throw new Exception(__('Le transport de rejeu n\'a rien rendu d\'exploitable.', __FILE__));
            }
            if (isset($corps['error'])) {
                $code = isset($corps['error']['status']) ? (int) $corps['error']['status'] : 400;
                self::echec($code, $corps, $timeout);
            }
            return $corps;
        }

        $cle = trim((string) config::byKey('apikey', self::PLUGIN, ''));
        if ($cle === '') {
            throw new Exception(__('Aucune clé API n\'est renseignée dans la configuration du plugin.', __FILE__));
        }

        $base = trim((string) config::byKey('base_url', self::PLUGIN, self::BASE_URL_DEFAUT));
        if ($base === '') {
            $base = self::BASE_URL_DEFAUT;
        }
        $url = rtrim($base, '/') . $_chemin;
        $json = ($_charge === null) ? null : json_encode($_charge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /*
         * Les causes d'échec, dans l'ordre, et pas seulement la dernière : un
         * 429 suivi d'une réponse illisible faisait disparaître le quota du
         * message rendu à l'utilisateur, qui partait alors vérifier l'adresse
         * de l'API quand c'était son crédit qui manquait.
         */
        $causes = array(__('OpenAI n\'a pas répondu.', __FILE__));

        for ($essai = 0; $essai < 2; $essai++) {
            if ($essai > 0) {
                static::patienter(self::ATTENTE_RELANCE);
            }

            $entetes = array('Content-Type: application/json');
            /* Le seul endroit du plugin où la clé est écrite. Elle ne doit
             * jamais entrer dans une variable journalisée. */
            $entetes[] = 'Authorization: Bearer ' . $cle;

            $curl = curl_init();
            $reglages = array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $entetes,
                CURLOPT_USERAGENT      => 'JeedomK2000/' . self::PLUGIN,
            );
            if ($json !== null) {
                $reglages[CURLOPT_POST] = true;
                $reglages[CURLOPT_POSTFIELDS] = $json;
            }
            curl_setopt_array($curl, $reglages);

            $reponse = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($curl);
            $erreur = curl_error($curl);
            curl_close($curl);

            if ($reponse === false) {
                /* Le délai dépassé ne se relance pas : on vient déjà d'attendre
                 * le temps imparti, et recommencer le doublerait pour rien. */
                if ($errno === 28) {
                    throw new Exception(sprintf(__('Pas de réponse d\'OpenAI en %s secondes. Augmentez le délai dans la configuration du plugin, ou choisissez un modèle plus rapide.', __FILE__), $timeout));
                }
                self::journaliser('Requête en échec : ' . $erreur);
                $causes[$essai] = __('Impossible de joindre OpenAI : vérifiez l\'accès à Internet de la box.', __FILE__);
                continue;
            }

            $corps = json_decode($reponse, true);
            if (!is_array($corps)) {
                self::journaliser('Réponse illisible (HTTP ' . $code . ') : ' . substr((string) $reponse, 0, 400));
                $causes[$essai] = __('Réponse illisible d\'OpenAI : ce n\'est pas du JSON. Vérifiez l\'adresse de l\'API dans la configuration du plugin.', __FILE__);
                continue;
            }

            if ($code >= 200 && $code < 300) {
                return $corps;
            }

            /* Une seule relance, et seulement sur ce qui a une chance de passer
             * au coup suivant. Un 400 ou un 401 se reproduirait à l'identique. */
            if (($code === 429 || $code >= 500) && $essai === 0) {
                self::journaliser('Réponse HTTP ' . $code . ', une relance dans ' . self::ATTENTE_RELANCE . ' s.');
                $cause = '';
                self::echecMessage($code, $corps, $cause);
                $causes[$essai] = $cause;
                continue;
            }

            self::echec($code, $corps, $timeout);
        }

        throw new Exception(self::causes($causes));
    }

    /*
     * La phrase finale d'un appel qui a échoué deux fois. La première cause
     * vient d'abord : c'est elle qui explique pourquoi on a relancé, et c'est
     * souvent la seule qui dise quoi faire.
     */
    private static function causes($_causes) {
        $causes = array();
        foreach ($_causes as $cause) {
            $cause = trim((string) $cause);
            if ($cause !== '' && !in_array($cause, $causes, true)) {
                $causes[] = $cause;
            }
        }
        if (count($causes) === 0) {
            return __('OpenAI n\'a pas répondu.', __FILE__);
        }
        if (count($causes) === 1) {
            return $causes[0];
        }
        return $causes[0] . ' ' . sprintf(__('La relance a échoué à son tour : %s', __FILE__), $causes[1]);
    }

    /* Note le refus pour le rattrapage, puis lève le message destiné à
     * l'utilisateur. Ne rend jamais la main. */
    private static function echec($_code, $_corps, $_timeout) {
        self::$code = (int) $_code;
        self::$detail = self::detail($_corps);

        self::journaliser('Appel refusé (HTTP ' . self::$code . ') : ' . self::$detail);

        $message = '';
        self::echecMessage(self::$code, $_corps, $message);
        throw new Exception($message);
    }

    /* La phrase que lira l'utilisateur, par code HTTP. Elle dit ce qui se passe
     * et ce qu'il peut faire ; le détail technique n'y entre que lorsqu'il est
     * la seule information utile, et masqué. */
    private static function echecMessage($_code, $_corps, &$_message) {
        $detail = self::detail($_corps);

        if ($_code === 401 || $_code === 403) {
            $_message = __('Clé API refusée par OpenAI. Vérifiez la clé dans la configuration du plugin.', __FILE__);
        } elseif ($_code === 404) {
            /* Deux causes, et la seconde est la plus fréquente derrière une
             * passerelle : une base_url à laquelle il manque « /v1 » rend un
             * 404 qui n'a rien à voir avec le modèle. Ne nommer que le modèle
             * envoyait l'utilisateur corriger la seule chose qui allait bien. */
            $_message = __('OpenAI répond « introuvable » (404). Deux causes possibles : le nom du modèle est inconnu du compte, ou l\'adresse de l\'API est incomplète — elle doit contenir le chemin de version, par exemple https://api.openai.com/v1. Vérifiez les deux dans la configuration du plugin.', __FILE__);
        } elseif ($_code === 429) {
            $_message = __('Quota ou débit OpenAI dépassé : le compte n\'a plus de crédit, ou trop de demandes ont été envoyées en peu de temps.', __FILE__);
        } elseif ($_code >= 500) {
            $_message = sprintf(__('OpenAI est indisponible (HTTP %s). Réessayez dans quelques minutes.', __FILE__), $_code);
        } elseif ($_code === 400) {
            $_message = __('OpenAI a refusé la demande :', __FILE__) . ' ' . k2000beJournal::masquer($detail);
        } else {
            $_message = sprintf(__('OpenAI a répondu HTTP %s :', __FILE__), $_code) . ' ' . k2000beJournal::masquer($detail);
        }
    }

    /* Le texte brut de l'erreur API, param et code compris : c'est « param »
     * qui nomme le paramètre refusé, et donc lui qui permet le rattrapage. */
    private static function detail($_corps) {
        if (!is_array($_corps) || !isset($_corps['error'])) {
            return '';
        }
        $erreur = $_corps['error'];
        if (is_string($erreur)) {
            return $erreur;
        }
        if (!is_array($erreur)) {
            return '';
        }
        $morceaux = array();
        foreach (array('message', 'param', 'code') as $cle) {
            if (isset($erreur[$cle]) && is_string($erreur[$cle]) && $erreur[$cle] !== '') {
                $morceaux[] = $erreur[$cle];
            }
        }
        return implode(' ', $morceaux);
    }

    /* Tout ce qui part au journal passe par là : masquer() y retire la clé API,
     * qu'un corps d'erreur ou une trace curl pourrait recopier. */
    private static function journaliser($_texte) {
        try {
            log::add(self::PLUGIN, 'debug', k2000beJournal::masquer($_texte));
        } catch (Throwable $e) {
            /* Un journal indisponible ne doit pas faire échouer une demande. */
        }
    }

    /* Seconde porte de sortie, l'horloge : le rejeu hors ligne la remplace pour
     * éprouver la relance sans attendre. */
    protected static function patienter($_secondes) {
        sleep($_secondes);
    }
}
