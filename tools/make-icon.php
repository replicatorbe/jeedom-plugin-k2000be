<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : quatre
 * couleurs et une dizaine de formes, qu'on peut relire et refaire.
 *
 * Le sujet est le balayage rouge de K2000 : une barre horizontale au centre
 * d'une calandre sombre, avec le point lumineux et sa traîne. C'est la seule
 * image que tout le monde reconnaît de la série, et elle reste lisible à la
 * taille du menu de Jeedom, où l'icône ne fait qu'une trentaine de pixels.
 *
 * Le dessin est fait en 1024 puis réduit en 256 : GD n'anticrénèle ni les
 * polygones ni les rectangles, et la réduction fait ce travail à sa place.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

$noir      = imagecolorallocate($grand, 0x10, 0x12, 0x16);
$anthracite = imagecolorallocate($grand, 0x1E, 0x23, 0x2B);
$acier     = imagecolorallocate($grand, 0x39, 0x41, 0x4D);
$rouge     = imagecolorallocate($grand, 0xE1, 0x1D, 0x1D);
$blanc     = imagecolorallocate($grand, 0xFF, 0xF0, 0xEC);

/* Le fond : un carré aux angles arrondis, comme les icônes des autres plugins
 * du dossier. imagefilledarc aux quatre coins, deux rectangles au milieu. */
$rayon = 44;
imagefilledrectangle($grand, $e($rayon), 0, $e(TAILLE - $rayon), $e(TAILLE), $noir);
imagefilledrectangle($grand, 0, $e($rayon), $e(TAILLE), $e(TAILLE - $rayon), $noir);
foreach (array(array($rayon, $rayon), array(TAILLE - $rayon, $rayon),
               array($rayon, TAILLE - $rayon), array(TAILLE - $rayon, TAILLE - $rayon)) as $centre) {
    imagefilledellipse($grand, $e($centre[0]), $e($centre[1]), $e($rayon * 2), $e($rayon * 2), $noir);
}

/* La calandre : trois lamelles sombres au-dessus et au-dessous de la barre.
 * Elles donnent l'échelle et évitent que l'icône ne soit qu'un trait rouge sur
 * du noir, ce qui ne se lirait pas sur un thème sombre. */
foreach (array(60, 84, 172, 196) as $y) {
    imagefilledrectangle($grand, $e(44), $e($y), $e(TAILLE - 44), $e($y + 9), $anthracite);
}
foreach (array(72, 184) as $y) {
    imagefilledrectangle($grand, $e(60), $e($y), $e(TAILLE - 60), $e($y + 5), $acier);
}

/* Le logement de la barre : un creux plus clair, pour que le rouge paraisse
 * posé dedans et non peint par-dessus. */
imagefilledrectangle($grand, $e(30), $e(110), $e(TAILLE - 30), $e(146), $anthracite);
imagefilledrectangle($grand, $e(34), $e(114), $e(TAILLE - 34), $e(142), $noir);

/*
 * Le balayage lui-même. Le point est à droite du centre, la traîne s'éteint
 * vers la gauche : une barre uniformément rouge ne dirait pas qu'elle bouge.
 * L'extinction est calculée colonne par colonne plutôt que par quelques blocs,
 * sans quoi la réduction en 256 fait apparaître des marches.
 */
$gauche = 40;
$droite = TAILLE - 40;
$point = 168;
for ($x = $gauche; $x <= $droite; $x++) {
    $distance = abs($x - $point) / ($point - $gauche);
    /* Traîne longue à gauche du point, coupure nette à sa droite : c'est le
     * sens de déplacement qui se lit ainsi. */
    $intensite = ($x > $point) ? max(0, 1 - $distance * 4) : max(0, 1 - $distance);
    if ($intensite <= 0.02) {
        continue;
    }
    $alpha = (int) round(127 - 127 * $intensite);
    $couleur = imagecolorallocatealpha($grand, 0xE1, 0x1D, 0x1D, $alpha);
    imagefilledrectangle($grand, $e($x), $e(118), $e($x + 1), $e(138), $couleur);
}

/* Le cœur du point, presque blanc, et son halo : c'est lui qui donne
 * l'impression de lumière, le rouge seul paraît mat. Une ellipse plutôt qu'un
 * rectangle, sinon le point ressemble à une touche de clavier. */
imagefilledellipse($grand, $e($point), $e(128), $e(40), $e(30), imagecolorallocatealpha($grand, 0xFF, 0x50, 0x40, 70));
imagefilledellipse($grand, $e($point), $e(128), $e(26), $e(22), $rouge);
imagefilledellipse($grand, $e($point), $e(128), $e(15), $e(13), $blanc);

/* Réduction finale : c'est elle qui lisse tout le dessin. */
$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/k2000be_icon.png';
imagepng($icone, $cible);
echo 'Icône écrite : ' . realpath($cible) . "\n";
