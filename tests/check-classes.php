<?php
/* Contrôles par réflexion contre le coeur de Jeedom installé.
 *
 *   php tests/check-classes.php
 *
 * Ces pièges ont ceci de commun qu'ils sont invisibles à la relecture,
 * invisibles à « php -l », et invisibles au rejeu hors ligne : ils ne se
 * manifestent que dans un vrai Jeedom, et leur symptôme ne ressemble jamais à
 * leur cause. Une propriété mal nommée devient « Unknown column », une méthode
 * mal nommée fait disparaître une saisie sans un mot dans le journal, une
 * visibilité réduite met TOUTE l'interface de Jeedom en HTTP 500. Tous se
 * vérifient en quelques lignes de réflexion.
 *
 * Le plugin compte cinq fichiers de classes, et les cinq sont examinés : deux
 * des règles ne concernent que les classes qui descendent d'eqLogic ou de cmd,
 * mais rien n'interdit d'en écrire une dans un fichier de service — c'est même
 * ainsi que la règle serait oubliée.
 */

$core = '/var/www/html/core/php/core.inc.php';
if (!is_readable($core)) {
    echo "Jeedom introuvable : contrôle ignoré.\n";
    exit(0);
}
require_once $core;

$dossier = __DIR__ . '/../core/class/';
$fichiers = array(
    'k2000be.class.php',
    'k2000beJournal.class.php',
    'k2000beSecurite.class.php',
    'k2000beOutils.class.php',
    'k2000beOpenAI.class.php',
);

$problems = array();

/* Découpe un fichier en classes, chacune avec son parent et son corps. Le
 * corps s'arrête à la classe suivante : sans cette découpe, une propriété de
 * k2000beCmd serait reprochée à k2000be, et surtout une propriété d'une classe
 * de service — parfaitement légitime — serait comptée comme une faute. */
function classesDuFichier($_source) {
    $classes = array();
    if (!preg_match_all('/^\s*class\s+(\w+)(?:\s+extends\s+(\w+))?/m', $_source, $trouvees,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return $classes;
    }
    foreach ($trouvees as $rang => $declaration) {
        $debut = $declaration[0][1];
        $fin = isset($trouvees[$rang + 1]) ? $trouvees[$rang + 1][0][1] : strlen($_source);
        $classes[] = array(
            'nom'    => $declaration[1][0],
            'parent' => (isset($declaration[2]) && $declaration[2][1] !== -1) ? $declaration[2][0] : '',
            'corps'  => substr($_source, $debut, $fin - $debut),
        );
    }
    return $classes;
}

$sources = array();
foreach ($fichiers as $fichier) {
    $chemin = $dossier . $fichier;
    if (!is_readable($chemin)) {
        $problems[] = $fichier . ' : fichier introuvable — la classe principale le charge pourtant.';
        continue;
    }
    $sources[$fichier] = file_get_contents($chemin);
}

$rang = array('private' => 0, 'protected' => 1, 'public' => 2);

/* ------------------------------------------------------------------ 2 ---
 * Aucune méthode ne doit s'appeler « set » suivi d'une clé du formulaire, et
 * surtout pas setCmd(). À l'enregistrement, utils::a2o() appelle « set » + clé
 * pour chaque clé reçue, et la page envoie toujours une clé « cmd » : une
 * méthode privée de ce nom tue la sauvegarde sur une erreur fatale, avant toute
 * écriture. La page se rafraîchit, la saisie disparaît, le journal reste muet. */
$interdites = array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                    'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                    'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                    'setTags', 'setCmd');

foreach ($sources as $fichier => $source) {
    foreach (classesDuFichier($source) as $classe) {

        /* Les trois règles qui suivent ne valent que pour ce qui descend
         * d'eqLogic ou de cmd : le coeur ne réfléchit que sur celles-là. */
        if (!in_array($classe['parent'], array('eqLogic', 'cmd'), true)) {
            continue;
        }
        $ou = $fichier . ', classe ' . $classe['nom'];

        /* -------------------------------------------------------------- 1 ---
         * Toute propriété d'une classe eqLogic ou cmd doit commencer par un
         * souligné. DB::save() traite les autres comme des colonnes de la
         * table : une propriété « $refreshError » fait échouer la création d'un
         * équipement sur « Unknown column », sans que le journal du plugin en
         * dise un mot. Les propriétés statiques, elles, ne sont pas
         * enregistrées : DB::save() ne les voit pas. */
        preg_match_all('/^\s*(?:private|protected|public)\s+(?!static|function)\$(\w+)/m',
            $classe['corps'], $proprietes);
        foreach ($proprietes[1] as $nom) {
            if (strpos($nom, '_') !== 0) {
                $problems[] = $ou . ' : propriété sans souligné initial, $' . $nom
                    . ' — DB::save() la prendra pour une colonne de la table.';
            }
        }

        /* Règle 2, appliquée au corps de la classe. */
        foreach ($interdites as $nom) {
            if (preg_match('/function\s+' . $nom . '\s*\(/i', $classe['corps'])) {
                $problems[] = $ou . ' : méthode interdite, ' . $nom . '() — utils::a2o() '
                    . 'l\'appellera à chaque enregistrement et tuera la sauvegarde.';
            }
        }

        /* -------------------------------------------------------------- 3 ---
         * Une méthode héritée ne peut pas voir sa visibilité réduite. eqLogic
         * et cmd exposent publiquement getCache(), setCache(), getStatus(),
         * setStatus() et bien d'autres : les redéclarer en privé est une erreur
         * fatale AU CHARGEMENT de la classe. Or le coeur charge la classe de
         * chaque plugin actif sur chaque page — toute l'interface de Jeedom
         * tombe alors en HTTP 500, pas seulement le plugin. C'est arrivé, sur
         * getCache() et setCache(). */
        preg_match_all('/^\s*(private|protected|public)\s+(?:static\s+)?function\s+(\w+)/m',
            $classe['corps'], $methodes, PREG_SET_ORDER);
        $reference = new ReflectionClass($classe['parent']);
        foreach ($methodes as $declaration) {
            $visibilite = $declaration[1];
            $nom = $declaration[2];
            if (!$reference->hasMethod($nom)) {
                continue;
            }
            $heritee = $reference->getMethod($nom);
            $visibiliteParente = $heritee->isPrivate() ? 'private'
                : ($heritee->isProtected() ? 'protected' : 'public');
            if ($rang[$visibilite] < $rang[$visibiliteParente]) {
                $problems[] = $ou . ' : visibilité réduite sur une méthode héritée, ' . $nom
                    . '() est ' . $visibilite . ' ici et ' . $visibiliteParente . ' dans '
                    . $classe['parent'] . ' — erreur fatale au chargement, Jeedom entier en HTTP 500.';
            }
        }
    }
}

/* ------------------------------------------------------------------ 4 ---
 * La classe de commande est obligatoire, même vide : core/ajax/eqLogic.ajax.php
 * refuse de créer ou d'ouvrir un équipement si elle manque. */
$principale = isset($sources['k2000be.class.php']) ? $sources['k2000be.class.php'] : '';
if (!preg_match('/class\s+k2000beCmd\s+extends\s+cmd/', $principale)) {
    $problems[] = 'k2000be.class.php : classe k2000beCmd absente — impossible de créer un équipement.';
}
if (!preg_match('/class\s+k2000be\s+extends\s+eqLogic/', $principale)) {
    $problems[] = 'k2000be.class.php : classe k2000be absente — le coeur ne trouvera pas le plugin.';
}

/* ------------------------------------------------------------------ 5 ---
 * L'autoload du coeur ne sait charger qu'une classe par plugin, celle qui porte
 * son identifiant (jeedomAutoload, dans core/php/core.inc.php). Les quatre
 * classes de service ne sont donc trouvées que parce que la classe principale
 * les charge nommément : en oublier une ne se voit ni à la relecture ni à
 * l'installation, seulement à la première demande, en « Class not found ».
 *
 * Symétriquement, ces quatre fichiers ne doivent PAS charger core.inc.php :
 * ils sont toujours inclus depuis la classe principale, qui l'a déjà fait,
 * et c'est ce qui permet au rejeu hors ligne de les prendre tels quels. */
foreach ($fichiers as $fichier) {
    if ($fichier === 'k2000be.class.php' || !isset($sources[$fichier])) {
        continue;
    }
    $classe = basename($fichier, '.class.php');
    if (!preg_match('#require_once\s+__DIR__\s*\.\s*[\'"]/' . $classe . '\.class\.php#', $principale)) {
        $problems[] = 'k2000be.class.php : ' . $classe . ' n\'est jamais chargée — l\'autoload du '
            . 'coeur ne la trouvera pas, et la première demande échouera sur « Class not found ».';
    }
    if (preg_match('#^\s*require_once.*core\.inc\.php#m', $sources[$fichier])) {
        $problems[] = $fichier . ' : charge core.inc.php alors qu\'elle est toujours incluse '
            . 'depuis la classe principale — le rejeu hors ligne ne peut plus l\'inclure.';
    }
}

/* ---------------------------------------------------------------- BILAN --- */
if (empty($problems)) {
    echo "Contrôles du coeur : aucun problème sur les " . count($fichiers) . " fichiers de classes.\n";
    exit(0);
}
echo "Contrôles du coeur : " . count($problems) . " problème(s)\n";
foreach ($problems as $problem) {
    echo '  - ' . $problem . "\n";
}
exit(1);
