# Changelog

## 0.1 — 20/09/2026

Première version.

- Un assistant = un équipement, adossé à l'API OpenAI et à son mécanisme
  d'outils. Le modèle demande, le plugin décide : aucune exécution ne part sans
  passer par le contrôle de sécurité.
- Toute commande d'action est interdite par défaut, y compris celles créées
  après l'installation. Trois politiques par commande — interdite, autorisée,
  autorisée avec confirmation — enregistrées dans la configuration de la
  commande Jeedom, et non dans celle du plugin : elles suivent la commande et
  survivent à une désinstallation.
- Trois modes globaux : `lecture` (aucune exécution), `simulation` (tout est
  joué et journalisé, rien n'est envoyé à la domotique) et `actions`. Le mode
  est annoncé au modèle dans ses consignes : en lecture seule comme en
  simulation, il l'apprenait auparavant en s'y cognant, promettait d'agir, puis
  devait se dédire au tour suivant. En lecture seule, les outils n'annoncent
  plus aucune action exécutable, et le résumé de la maison dit pourquoi.
- Lecture des états autorisée par défaut, refusable commande par commande ;
  masquage d'un équipement entier, dont l'assistant ignore alors jusqu'à
  l'existence. Les assistants eux-mêmes sont exclus d'office de leur champ de
  vision. Un identifiant inexistant, masqué, désactivé ou appartenant à un
  assistant reçoit la même réponse indistincte : énumérer les identifiants ne
  rapporte rien.
- Sept outils, conçus pour une découverte progressive de la maison — les
  pièces, puis les équipements d'une pièce, puis leur détail — plutôt que
  l'envoi d'un inventaire complet à chaque demande. Chacun borne ce qu'il rend
  et dit ce qu'il a laissé de côté.
- `get_states` accepte une attente (`wait`, en secondes). Le plugin demande au
  modèle de vérifier l'effet d'une commande, mais entre l'envoi et la lecture
  il ne s'écoulait que l'aller-retour vers OpenAI, une à trois secondes : un
  équipement qui remonte son état en cinq faisait lire l'ancienne valeur, et
  l'assistant annonçait un échec qui n'avait pas eu lieu — avec la tentation de
  refaire l'action. La commande est désormais relue par pas de 300 ms jusqu'à
  ce que sa date de relevé change, ou jusqu'au délai. La réponse rend `waited`,
  le temps réellement attendu, et par état un `refreshed` : `false` y veut dire
  « l'équipement n'a rien dit », et non « la commande a échoué » — les deux se
  lisaient jusqu'ici de la même façon. C'est la date de relevé qui est
  surveillée et non la valeur, sans quoi une lampe rallumée alors qu'elle était
  déjà allumée ferait attendre en vain. L'attente est bornée à dix secondes par
  appel et quinze secondes cumulées par demande, remises à zéro à chaque tour ;
  elle ne s'applique jamais à un état que l'assistant n'a pas le droit de lire,
  faute de quoi la durée de la réponse renseignerait sur la maison ; elle se
  prend sur le budget de temps du tour et se déroule pendant que le verrou de
  l'assistant est tenu, donc une demande concurrente est refusée pendant ce
  temps.
- L'invite système demande au modèle de grouper ses appels : plusieurs actions
  ou lectures indépendantes partent dans le même message et s'exécutent en un
  seul aller-retour. Rien ne les bridait côté API et la boucle savait déjà les
  exécuter ensemble ; il ne manquait que de le dire. Éteindre six choses une
  par une coûtait six allers-retours facturés et six attentes du réseau, pour
  le même résultat.
- Le plafond par défaut d'*Outils par demande* passe de 10 à 15. « Je vais me
  coucher », sur une maison d'une soixantaine d'équipements, demande douze à
  dix-huit outils : à dix, la boucle coupait au milieu de l'extinction et
  l'assistant concluait, poliment, sur une maison à moitié éteinte, en statut
  `LIMIT`. Le plafond haut reste à 30, et il compte des outils, non des
  allers-retours.
- L'invite système traite l'ambiguïté. Elle disait de ne pas inventer ce qu'on
  ne voit pas, et rien de ce qu'il faut faire quand on en voit trois : le
  modèle choisissait. Il doit maintenant nommer les candidats et demander
  lequel — trois « Lumière » dans trois pièces, deux volets au même nom. Une
  chance sur trois d'ouvrir le volet de la chambre du bébé coûte plus cher
  qu'une question, et la réponse à cette question est une demande de plus.
- `get_history` suit l'archivage du cœur. Passé `historyArchiveTime` (deux
  heures par défaut), Jeedom ne conserve plus des relevés mais des moyennes :
  l'outil demande donc l'agrégation au cœur — moyennes horaires, journalières
  au-delà de cinquante heures, dans la fonction retenue pour la commande — et
  annonce ce qu'il rend : mode d'échantillonnage, nombre de points d'origine,
  période réellement couverte, et une note quand ce sont des moyennes. Un état
  binaire ou textuel n'est jamais lissé : il est réduit à ses transitions, pour
  qu'une ouverture d'une minute ne disparaisse pas. Corollaire à garder en
  tête : le dernier point d'une série agrégée est la moyenne d'une heure — ou
  d'une journée — qui n'est pas finie, jamais la valeur courante. « Où en
  est-on maintenant » se lit par `get_states`.
- `execute_command` accepte un titre en plus de la valeur, pour les seules
  commandes de sous-type message : une notification a un titre et un corps, que
  le schéma ne savait pas transporter séparément.
- Une action annoncée au modèle nomme `expects` le type de valeur attendu.
  Cette clé s'appelait `value`, comme le paramètre qui porte la valeur : un
  modèle qui recopiait ce qu'il venait de lire envoyait `value: "number"`, ce
  qu'un select sans liste de choix acceptait et transmettait à la maison.
- Confirmation des commandes réglées sur *Confirmation* : la conversation est
  suspendue en l'état, les outils suivants du même message ne sont pas
  exécutés, et la demande devient caduque au bout de cinq minutes. Une reprise
  après confirmation rejoue tous les contrôles de sécurité, puis rend la main
  au modèle pour qu'il vérifie et conclue. Les commandes `Confirmer` et
  `Annuler` répondent à la dernière confirmation posée, d'où qu'elle vienne :
  un scénario peut donc approuver une confirmation créée depuis la tuile, et
  inversement.
- Un tour de conversation à la fois par assistant. Une seconde demande pendant
  qu'un tour est en cours est refusée sur-le-champ, et non mise en file : le
  verrou est tenu pendant des appels réseau de plusieurs dizaines de secondes.
  Sans lui, deux demandes simultanées s'écrasaient l'une l'autre, et deux clics
  sur *Confirmer* ouvraient le portail deux fois.
- Cinq statuts de demande, lus dans cet ordre : `CONFIRMATION`, `LIMIT`,
  `ERROR`, `REFUSED`, `SUCCESS`. `LIMIT` couvre les trois plafonds qui peuvent
  arrêter un tour — la limite d'outils, le budget de temps calé sur
  `max_execution_time`, et une réponse coupée par la longueur maximale ; dans
  ce dernier cas la réponse le dit, au lieu de rendre une demi-phrase pour une
  réussite.
- Réglages bornés des deux côtés, et qui le disent : *Outils par demande* (30
  au plus), *Échanges gardés en mémoire* (50 tours au plus) et *Délai
  d'attente* (de 5 à 300 secondes). Une valeur hors bornes est remplacée et le
  journal du plugin l'écrit. *Longueur maximale d'une réponse* garde sa
  convention propre : `0` y veut dire « pas de plafond ».
- Onglet *Autorisations* : l'installation entière en arbre pièce → équipement →
  commandes, avec recherche, compteurs, avertissement sur les commandes qui
  engagent la sécurité du logement, et enregistrement immédiat à chaque clic.
- Onglet *Historique* : pour chaque demande, les outils appelés, leur statut,
  leur motif, la durée et le coût en jetons. Rétention réglable, purgée par la
  tâche quotidienne. La lecture du journal a un budget : quand il s'épuise, une
  dernière ligne « journal écourté » dit jusqu'à quelle date la recherche est
  allée, plutôt que de laisser croire à l'absence d'une demande enregistrée
  plus loin.
- Utilisable depuis la page du plugin, depuis un widget de discussion sur le
  dashboard, et depuis un scénario par la commande *Demander*, avec les
  commandes d'état `Réponse`, `Statut`, `Actions exécutées`, `Dernière
  demande`, `Confirmation en attente` et `Objet de la confirmation`, cette
  dernière portant le libellé de la commande dont l'accord est attendu — il n'y
  en a jamais qu'une à la fois.
- Une **fiche de la maison** dans la configuration du plugin : sept champs
  facultatifs — qui vit ici, animaux, logement, chauffage et eau chaude,
  habitudes et horaires, à ne jamais faire, véhicule électrique — qui disent au
  modèle ce que l'installation ne contient pas. Le plugin connaissait la maison
  technique dans le moindre détail et ignorait tout de la maison vécue : « je
  vais me coucher » ne pouvait pas tenir compte d'un enfant qui dort déjà, ni
  « j'ai froid » du fait que la chambre est chauffée par un poêle qu'aucune
  commande ne pilote. Seuls les champs remplis partent, précédés d'une phrase
  qui dit leur autorité : ces informations viennent du propriétaire, elles ne
  sont pas vérifiables par les outils, et si un outil dit autre chose, c'est
  l'outil qui a raison — sans quoi une fiche qui annonce « personne en journée »
  l'emporterait sur un capteur de présence. Les *Consignes supplémentaires*
  restent en place et passent après la fiche, pour qu'une consigne puisse
  nuancer un fait et non l'inverse.
- La fiche est bornée : 300 caractères par champ, 1500 pour l'ensemble, coupés
  au dernier mot entier et les champs pris dans l'ordre. Le reste de l'invite
  système pèse environ 2500 caractères : sans plafond, sept champs bavards en
  auraient représenté la moitié et les consignes de conduite s'y seraient
  noyées.
- Les sept champs ont été choisis sur un critère unique : **on ne renseigne que
  ce qui change une décision**. L'adresse complète, les prénoms des enfants et
  la marque du véhicule ont été écartés à ce titre — la ville de la box suffit
  à situer l'heure et la saison et part déjà, « un enfant de trois ans » change
  une décision quand « Léa, trois ans » n'en change aucune de plus, et seule
  l'existence d'une borne pilotable compte pour une voiture. La fiche repart
  chez OpenAI à chaque demande et pour toujours : une ligne inutile est une
  donnée personnelle exposée en pure perte, facturée à chaque question, qui
  dilue les lignes utiles.
- Le champ *À ne jamais faire* est une consigne au modèle et **n'a aucune
  valeur de sécurité** : le plugin le transmet, il ne l'applique pas. Ce qui
  protège reste l'autorisation de la commande, vérifiée à chaque appel d'outil.
  Écrire « n'ouvre jamais le portail » tout en laissant la commande autorisée
  ne protège de rien ; la régler sur *Interdite* ou *Confirmation*, si. La
  documentation le dit à l'endroit du champ et dans la section sur les limites
  du modèle.
- Bouton **Voir ce qui part à chaque demande**, sous la fiche : il affiche
  l'invite système complète, telle qu'elle serait envoyée, avec sa taille. La
  documentation décrit ce qui part chez OpenAI ; ce bouton permet de le
  vérifier sur sa propre installation plutôt que de la croire sur parole, et de
  mesurer ce qu'une fiche bavarde ajoute à chaque demande. L'invite est
  assemblée sur la box : l'aperçu n'appelle pas OpenAI, ne coûte rien, et
  fonctionne avant la création du premier assistant — c'est-à-dire au moment
  où l'on remplit la fiche.
- **`get_events` dit ce qui est arrivé, et non la valeur d'après** (`what` :
  `started`, `ended` ou `changed`, à la place de `value`). Une détection humaine
  allumée à 17 h 21 et retombée à 17 h 22 sortait en « Humain détecté : 0, à
  17 h 22 » : l'assistant, lisant zéro, a répondu au propriétaire que personne
  n'était passé — le contraire exact de ce que la ligne racontait. Une valeur
  d'état répond à « où en est-on », un événement à « que s'est-il passé » ; les
  mélanger dans la même clé produit la pire des réponses, fausse et argumentée.
  La réponse porte désormais la phrase qui manquait : une détection terminée est
  une détection qui a eu lieu, et ne se lit jamais comme « rien ne s'est passé ».
  Elle rappelle aussi que seul le dernier changement de chaque état est listé, et
  que `get_history` rend les précédents.
- **Un assistant qui se réveille tout seul** : la case *Alerter tout seul* et le
  *Repos entre deux alertes* de l'onglet *Équipement*. Dès qu'un des états
  décisifs bascule, l'assistant fait sa levée de doute sans qu'on l'interroge et
  publie sa réponse, qu'un scénario n'a plus qu'à relayer. Le filtre est alors
  en amont, là où il est le meilleur — une détection croisée ne tire que sur
  deux détections qui se recoupent —, et le modèle n'a même plus à décider qu'il
  se passe quelque chose. Le repos (15 minutes par défaut) n'est pas un
  confort : une seule règle a tiré trente-huit fois en une journée sur
  l'installation qui a servi à l'écrire. Le contrôle a lieu chaque minute et non
  par un listener du cœur : un listener s'exécuterait dans le processus du
  plugin qui vient de publier la valeur, et un tour dure des dizaines de
  secondes — ce serait bloquer un démon d'alarme pour gagner une minute. Le
  premier passage n'alerte jamais, il pose son repère : sans cela, cocher la
  case ferait raconter la détection de l'avant-veille. Les demandes nées d'un
  déclenchement sont journalisées sous l'utilisateur `alerte`.
- **Un garde-fou d'armement sur l'alerte automatique** : le champ *N'alerter que
  si*, qui désigne l'état disant que la maison est armée. Tant qu'il ne vaut pas
  « en marche », rien ne part — une détection de caméra n'a de sens que maison
  armée, et n'a pas à se payer en appels facturés le reste du temps. Tout ce qui
  n'est pas un oui franc vaut non : commande supprimée, valeur vide, état jamais
  renseigné, plugin d'alarme pas encore installé. C'est le sens même du champ,
  puisqu'on le renseigne souvent AVANT d'avoir posé l'alarme. Ce qui s'est passé
  maison désarmée ne ressort pas au moment où on l'arme, le repère ayant avancé.
  L'état d'armement échappe au droit de lecture de l'assistant : c'est le plugin
  qui lit sa configuration, pas le modèle qui consulte la maison — on peut donc
  masquer son alarme au modèle et s'en servir quand même. Le garde-fou ne
  concerne que l'alerte automatique : une question posée par un humain reçoit sa
  réponse dans tous les cas.
- **Les dates d'événement se comparent entières, et s'affichent tronquées.** La
  surveillance comparait des dates coupées à la minute : un déclenchement
  survenu trente secondes après le précédent disparaissait sans laisser de
  trace, et le repère n'avançait pas — si bien que le repos se terminait sur un
  événement déjà périmé. Trouvé par un test, pas en production.
- **Un verdict calculé par le plugin, et non par le modèle** : le champ *États
  qui décident* de l'onglet *Équipement*, et l'outil `check_alert` qu'il fait
  apparaître. On y déclare des types génériques du cœur — `ALARM_STATE` pour des
  détections croisées, qui ne se déclenchent que lorsque deux détections
  indépendantes se recoupent —, et le plugin rend `CONFIRMED`, `NOTHING` ou
  `UNKNOWN` en ne regardant que ces états-là. Tout le reste part en contexte et
  ne peut plus faire conclure à quoi que ce soit, quel qu'en soit le nombre.
  Écrire la consigne en toutes lettres ne suffisait pas : le modèle la suit le
  plus souvent, puis un jour additionne trois mouvements isolés et annonce une
  intrusion. C'est la règle du plugin — le modèle demande, le plugin décide —
  appliquée à la lecture, là où elle ne valait que pour l'action. `UNKNOWN` est
  un verdict à part entière : rien n'était surveillé, donc rien ne peut être
  conclu, et ce n'est pas le calme. Un état décisif que l'assistant n'a pas le
  droit de lire ne décide de rien et n'est pas même compté. L'outil n'est
  annoncé qu'aux assistants concernés : il ne coûte pas un jeton aux autres.
- **Des consignes propres à chaque assistant**, dans l'onglet *Équipement*. Le
  plugin affirmait depuis le début qu'un équipement est un assistant et que
  plusieurs peuvent coexister — « un bavard pour le salon, un sobre pour les
  scénarios » — mais c'était faux sur le seul point qui les distinguerait
  vraiment : les consignes vivaient dans la configuration du PLUGIN, donc les
  mêmes pour tous, et spécialiser un assistant revenait à spécialiser tous les
  autres, présents et à venir. Ce texte est ajouté après les consignes générales
  et prime sur elles ; il est plafonné à 2000 caractères, aplati sur une ligne
  comme la fiche de la maison — un champ multiligne ferait passer ses propres
  lignes pour des consignes séparées, orphelines de leur en-tête. Comme les
  consignes générales, c'est une instruction au modèle et non une barrière.
- **`search` cherche aussi par type générique.** Un équipement porte le nom que
  son propriétaire lui a donné, qui ne dit pas toujours ce qu'il est : huit
  caméras nommées d'après leur orientation — `EST`, `NORD`, `SUD`, `OUESTPTZ` —
  ne répondaient à aucune recherche sur « caméra ». Une seule commande
  répondait, « Caméra masquée », et c'était la pire : jamais renseignée, faute
  d'avoir jamais été masquée. L'assistant a répondu là-dessus que toutes les
  caméras l'étaient, alors que les détections de mouvement et les
  franchissements de ligne étaient bien là, sous les noms « Mouvement » et
  « Ligne franchie ». La recherche regarde désormais les types génériques du
  cœur — `CAMERA_URL`, `THERMOSTAT_SETPOINT`, `FLAP_STATE` — et l'équipement
  rendu porte `types`. Seuls comptent les types des commandes que l'assistant a
  le droit de voir : chercher « lock » ne peut pas apprendre qu'une serrure
  interdite existe quelque part.
- **Un état jamais relevé le dit** (`never_read: true`), au lieu d'être rendu
  comme une valeur vide. Une commande qu'aucun événement n'a jamais renseignée
  partait en `value: ""`, indistinguable d'une valeur réellement vide ; le
  modèle, n'en tirant rien, se rabattait sur le NOM de la commande. La réponse
  ajoute la phrase qui manquait : ce n'est ni une valeur vide ni un zéro, la
  valeur est inconnue, et il ne faut jamais deviner l'état de la maison d'après
  le nom d'une commande. `get_states` cesse par ailleurs de rendre une date
  vide — un champ vide se lit comme un champ qu'on aurait dû remplir.
- **Un huitième outil, `get_events`** : ce qui s'est passé récemment dans la
  maison, du plus récent au plus ancien, sur une fenêtre de douze heures par
  défaut et d'une semaine au plus, filtrable par pièce. La question « est-ce
  que quelqu'un est passé cette nuit ? » coûtait jusqu'ici un `get_equipment`
  par équipement puis un `get_history` par capteur : sur une installation à
  huit caméras portant chacune une vingtaine d'états, le plafond d'outils y
  passait entier pour relire cent cinquante zéros. Le critère est un état
  **binaire et historisé** — historiser, c'est dire que l'instant du basculement
  compte —, et non le nom d'un plugin : une caméra, un contact d'ouverture, un
  détecteur de fuite et un capteur de fumée remontent par la même porte. Les
  autorisations s'appliquent comme partout, et le droit de lecture est vérifié
  **avant** la date : savoir qu'un capteur masqué a bougé cette nuit en dirait
  déjà trop. Une fenêtre vide le dit, et ajoute que le silence ne prouve rien —
  une maison dont rien n'est historisé se lit sinon comme une maison tranquille.
- **`since` : la date du dernier changement d'un état événementiel**, jointe
  par `get_equipment` et `get_states`. Un état n'était daté qu'au-delà de
  vingt-quatre heures, ce qui est la bonne règle pour une mesure et l'exacte
  mauvaise pour un événement : « ligne franchie : 1 » ne dit pas qu'on franchit
  la ligne, il dit qu'on l'a franchie. Un plugin de vidéosurveillance qui reçoit
  une détection ponctuelle lève sa commande à 1 et rien ne la redescend, faute
  d'événement de fin ; trois semaines plus tard, l'assistant annonçait encore un
  intrus dans le jardin. `since` porte la date du dernier **changement** et non
  celle de la dernière lecture : un contact de porte interrogé toutes les
  minutes est lu à l'instant et ouvert depuis hier soir.
- **Page Santé** (*Analyse → Santé*) : clé API, mode de sécurité, modèle,
  commandes autorisées et en confirmation, dossier de travail, consommation du
  jour. Et une ligne qui n'existait nulle part ailleurs : le nombre de
  commandes **sensibles réglées sur « Autorisée »** — celles qui ouvrent,
  déverrouillent ou désarment sans rien demander. Sur cent seize équipements et
  deux cent cinquante actions, ce réglage se perd dans l'arbre des
  autorisations, et personne ne le déroule pour vérifier qu'aucune ne traîne.
  Trois lignes seulement peuvent virer au rouge — clé absente, dossier
  inaccessible, commande sensible sans confirmation : une page Santé qui
  clignote pour des choix licites cesse d'être lue. Le même compteur apparaît
  en pastille dans l'onglet *Autorisations*, et seulement s'il n'est pas nul.
- **Le filtre de statut de l'onglet *Historique* interroge le journal**, et non
  plus les seules lignes déjà affichées. « Montre-moi les erreurs » répondait
  « aucune » à une installation qui en avait : la dernière datait d'avant-hier,
  hors des cinquante lignes chargées. C'est précisément la question qu'on vient
  poser à cet écran. La lecture garde son budget, et la ligne de service qui
  dit jusqu'où le journal a été parcouru prend tout son sens ici — il faut lire
  beaucoup pour retenir peu.
- **Le coût, additionné.** Une ligne en tête de l'onglet *Historique* totalise
  les demandes affichées, leurs jetons et leur durée moyenne. Une commande
  d'information **`Jetons consommés`**, historisée comme `Actions exécutées`,
  porte le coût du dernier tour : Jeedom en trace la courbe, et un scénario
  peut prévenir quand elle monte. Le chiffre était journalisé demande par
  demande depuis toujours, et additionné nulle part.
- **Réglage *Demandes par jour*** (`0` par défaut, c'est-à-dire pas de
  plafond ; 2000 au plus). Au-delà, une demande est refusée avant tout appel
  réseau : elle ne coûte rien et rend `REFUSED`. Rien du côté de la box ne
  bornait la dépense — un scénario branché sur *Demander* et déclenché par un
  capteur qui vibre facture jusqu'à ce que quelqu'un regarde, un ou deux jours
  plus tard, sur un relevé OpenAI mensuel et libellé en dollars. Le compte est
  commun à tous les assistants, la clé API l'étant aussi, repart à minuit, et
  ne retient que les demandes ayant réellement atteint le modèle : un refus qui
  se compterait lui-même empêcherait la journée de se rouvrir. Une confirmation
  en attente n'est jamais bloquée — elle reprend un tour déjà payé et à moitié
  joué sur la maison.
- La clé API est chiffrée en base — donc aussi dans les sauvegardes Jeedom — et
  masquée dans tout texte journalisé par le plugin. Elle reste en revanche
  lisible depuis l'interface : la fenêtre de configuration la recharge dans son
  champ à chaque ouverture, et le cœur de Jeedom rend la configuration d'un
  plugin à tout compte connecté, administrateur ou non. Un compte Jeedom vaut
  donc un accès à la clé, et une clé compromise se révoque chez OpenAI.
