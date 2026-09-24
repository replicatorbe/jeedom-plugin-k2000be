# K2000

Ce plugin ajoute à Jeedom un assistant en langage naturel. Vous écrivez « je
vais me coucher » ; il consulte l'état des pièces, décide quoi faire, exécute
les commandes qu'on lui a autorisées, vérifie le résultat et raconte ce qu'il a
fait — y compris ce qu'il n'a pas pu faire.

Il s'appuie sur l'API OpenAI. Une clé API est indispensable, et chaque demande
est facturée par OpenAI.

## Le principe : le modèle demande, le plugin décide

C'est la règle dont tout le reste découle. Le modèle d'OpenAI ne pilote rien.
Il ne reçoit aucun accès à votre installation : il dispose d'outils, et un outil
est une **demande** adressée au plugin. Le plugin la regarde, la compare à ce
que vous avez autorisé, puis l'exécute, la refuse, ou la met en attente d'une
confirmation de votre part.

Conséquence directe : **toute commande d'action est interdite tant que vous ne
l'avez pas autorisée**. Un assistant fraîchement créé ne peut rien allumer,
rien ouvrir, rien couper. Il peut lire, et c'est tout. C'est délibéré : un
modèle de langage se trompe parfois d'équipement, et la liste des commandes
qu'il peut employer doit être une liste que vous avez écrite, pas une liste
qu'il a déduite.

Un refus n'est pas une panne. Il est renvoyé au modèle avec sa raison, rédigée
en français, pour qu'il vous l'explique plutôt que de rester muet ou d'inventer
un succès.

## Mise en route

Dans l'ordre, et cet ordre compte.

### 1. Obtenir une clé API OpenAI

Créez un compte sur `platform.openai.com`, puis une clé dans la section
*API keys*. La clé commence par `sk-`. Elle n'est affichée qu'une fois : copiez-la
tout de suite.

Un compte OpenAI neuf n'a pas forcément de crédit. Sans crédit, chaque demande
échoue avec « quota ou débit dépassé ». Le geste : sur `platform.openai.com`,
section *Settings → Billing*, ajouter un moyen de paiement puis du crédit
(*Add to credit balance*). Rien ne fonctionne avant.

### 2. Saisir la clé

Page du plugin, la clé à molette (*Configuration*). Collez la clé dans **Clé API
OpenAI**, puis cliquez **Enregistrer et tester**, juste en dessous : le bouton
enregistre la configuration, interroge OpenAI avec la clé qui vient d'être
enregistrée, et affiche le verdict à côté de lui. Tant qu'il ne répond pas
favorablement, inutile d'aller plus loin.

Le modèle par défaut est `gpt-4o-mini` : c'est le moins cher des modèles qui
tiennent correctement le mécanisme d'outils. Le bouton **Enregistrer et voir les
modèles**, sous le champ *Modèle*, liste ceux auxquels votre clé donne droit.

### 3. Vérifier le mode de sécurité

Toujours dans la configuration, le **mode de sécurité** doit être sur
`simulation` — c'est la valeur livrée, vous n'avez normalement rien à changer.
Le plugin fait alors tout le travail — lire la maison, décider, journaliser —
mais n'envoie aucune commande à la domotique. C'est le seul moyen d'observer les
décisions de l'assistant avant de lui laisser la main. Vous passerez en
`actions` quand ses réponses ne vous surprendront plus.

### 4. Créer un assistant

Page du plugin, **Ajouter**, un nom (« KITT » fait l'affaire), une pièce,
**Enregistrer**. Les commandes sont créées aussitôt.

### 5. Autoriser quelques commandes

Onglet **Autorisations**. Il affiche toute votre installation, pièce par pièce,
équipement par équipement. Commencez petit : trois ou quatre lampes, le
chauffage d'une pièce. Chaque commande d'action a trois boutons — *Interdite*,
*Autorisée*, *Confirmation* — et le changement part immédiatement, sans bouton
d'enregistrement global : la page traite des milliers de commandes, un
formulaire unique serait ingérable.

### 6. Poser une première demande

Onglet **Discussion**. « Quelles lumières sont allumées ? » est une bonne
première demande : elle ne dépend que de la lecture, elle vous montre ce que
l'assistant voit, et elle ne coûte presque rien.

### 7. Remplir la fiche de la maison

Toujours dans la configuration. Sept champs facultatifs qui disent à
l'assistant qui vit ici, à quelles heures et comment le logement se chauffe :
cinq minutes de saisie, et c'est ce qui améliore le plus la qualité des
réponses, davantage que n'importe quel autre réglage. Voir *La fiche de la
maison*.

## Les trois politiques d'une commande

Elles se règlent commande par commande, dans l'onglet *Autorisations*.

| Politique | Ce que l'assistant peut faire |
|---|---|
| **Interdite** | rien. Il voit la commande et sait qu'elle lui est refusée, ce qui lui permet de vous l'expliquer. C'est la valeur par défaut, y compris pour une commande créée après l'installation du plugin |
| **Autorisée** | l'assistant l'exécute seul, sans vous demander quoi que ce soit |
| **Confirmation** | l'assistant peut la demander, mais rien ne part tant que vous n'avez pas confirmé |

Les commandes **info** relèvent d'un réglage distinct : elles sont lisibles par
défaut (réglage *Lecture des états par défaut*), et vous pouvez en masquer
individuellement. Une commande masquée n'apparaît nulle part pour l'assistant :
il ne voit ni sa valeur, ni son nom, ni son existence.

Un **équipement entier** peut être masqué d'un interrupteur, ce qui retire d'un
coup toutes ses commandes du champ de vision de l'assistant.

Ces réglages sont enregistrés dans la configuration des commandes Jeedom
elles-mêmes, pas dans celle du plugin. Ils suivent donc la commande, et
survivent à une désinstallation du plugin.

Certaines commandes sont dites **sensibles** : déverrouillage de serrure,
ouverture *et fermeture* d'un portail ou d'un garage, armement comme
désarmement d'une alarme, sirène. La fermeture y figure au même titre que
l'ouverture : un portail qui se referme sur un véhicule ou sur quelqu'un est le
risque le plus concret de la liste. L'interface les signale d'un triangle
avant leur nom, dont l'infobulle recommande la confirmation. Elle ne choisit
pas pour vous : les trois boutons sont proposés à égalité, et la commande reste
interdite tant que vous ne cliquez pas. Si vous retenez *Autorisée*, la ligne
porte ensuite une étiquette rouge « autorisée sans confirmation », qui reste là
tant que le réglage n'a pas changé.

Aucune politique ne se règle en masse, sensible ou non : il n'existe pas de
bouton qui autorise une pièce ou un équipement entier d'un coup. Chaque
commande se clique une par une, ce qui est lent au début et délibéré.

## Les trois modes globaux

Le mode s'applique à tout le plugin, par-dessus les politiques individuelles.
Il ne peut que restreindre.

| Mode | Effet |
|---|---|
| `lecture` | aucune exécution, quelles que soient les autorisations. L'assistant consulte et répond |
| `simulation` | le plugin fait tout sauf envoyer la commande. Le modèle reçoit un résultat `simulated`, avec la consigne explicite d'annoncer l'action comme simulée et non comme faite ; le journal note la simulation |
| `actions` | exécution réelle, selon la politique de chaque commande |

**`simulation` est le mode livré**, et il vaut mieux y rester un moment.
Quelques dizaines de demandes en simulation vous montrent exactement quelles
commandes l'assistant aurait employées, et sur quelle interprétation de vos
phrases. C'est là qu'on découvre qu'« éteins tout » ne désigne pas la même
chose pour vous et pour le modèle — et on corrige les autorisations avant que
cela ait des conséquences. Le jour où vous choisissez `actions`, la
configuration vous le rappelle par un avertissement sous le champ : à partir de
cet enregistrement, les commandes autorisées partent sans vous être annoncées,
et seules celles réglées sur *Confirmation* vous demandent encore votre accord.

Le mode `lecture` est utile autrement : il permet de laisser un assistant
répondre à des questions sur la maison sans jamais rien pouvoir toucher.

**Le mode est annoncé au modèle** dans ses consignes, à chaque demande. Auparavant il l'apprenait en s'y cognant : il promettait
d'agir, puis devait se dédire au tour suivant, ce qui coûtait un aller-retour
par demande et une réponse qui se contredisait. C'est un rappel, pas une
garantie — ce qui protège la maison reste le contrôle d'autorisation, qui
refuse quoi qu'en pense le modèle.

En `lecture`, cette cohérence descend jusque dans les outils :
`list_equipments` annonce zéro action par équipement, `get_equipment` et
`search` marquent toutes les actions `forbidden`, et le résumé de la maison
compte « 0 commande exécutable » en disant pourquoi. Sans cela, le modèle
croirait la maison dépourvue de commandes plutôt que fermée. La page de
l'assistant compte de la même façon et peut donc afficher **« 0 autorisées »**
alors que vous venez d'en autoriser douze : c'est la vérité du moment, pas un
réglage perdu. Vos autorisations sont intactes, l'onglet *Autorisations*
continue de les montrer telles que vous les avez posées, et elles redeviennent
effectives dès le retour en `actions`. Une étiquette « lecture seule »
accompagne les compteurs pour le dire.

## Ce dont dispose l'assistant : huit outils

Le modèle ne reçoit pas votre installation. Il reçoit huit outils, qu'il appelle
à sa guise, et dont le plugin filtre systématiquement les résultats selon vos
autorisations. Un neuvième, `check_alert`, n'apparaît que pour un assistant à
qui l'on a déclaré des **états qui décident** : il ne coûte rien aux autres.

| Outil | Ce qu'il envoie | Ce que le plugin lui rend |
|---|---|---|
| `list_rooms` | rien : c'est le point de départ, et le moins cher | la liste des pièces, avec le nombre d'équipements visibles dans chacune |
| `list_equipments` | une pièce (`room`), un texte du nom (`search`), un type générique (`generic_type`), un nombre maximum (`limit`) | les équipements retenus : leurs types génériques, le nombre d'états lisibles et le nombre d'actions autorisées — jamais la liste complète de leurs commandes. Quarante par défaut, cent au plus, et la réponse dit combien correspondaient en tout |
| `get_equipment` | des identifiants d'équipements (`equipment_ids`), dix au plus | pour chacun : ses états avec valeur et unité, ses actions avec la politique qui s'y applique. Vingt états et vingt actions par équipement ; au-delà, `more_states` et `more_actions` disent combien manquent, et `search` les retrouve par leur nom |
| `get_states` | des identifiants d'états (`command_ids`), trente au plus, et une attente facultative (`wait`, en secondes) | leur valeur, leur unité, leur type générique, la date de leur dernière lecture, et `since` pour un état événementiel — la date de son dernier changement. Quand une attente a été demandée, la réponse porte aussi `waited` — le temps réellement attendu — et chaque état un `refreshed` : voir *Envoyée n'est pas aboutie* |
| `search` | un texte (`query`) | jusqu'à vingt-cinq équipements et vingt-cinq commandes dont le **nom** contient ce texte — ou, pour un équipement, dont l'un des **types génériques** le contient. Accents et casse ignorés. Un équipement trouvé porte `types`, qui dit ce qu'il est |
| `get_history` | un identifiant d'état (`command_id`) et une profondeur en heures (`hours` : 24 par défaut, 168 au plus) | cinquante points au plus, et la façon dont ils ont été obtenus — voir plus bas |
| `check_alert` | une profondeur en heures (`hours` : 12 par défaut, 168 au plus) | un verdict calculé par le plugin — `CONFIRMED`, `NOTHING` ou `UNKNOWN` —, les états décisifs qui l'ont produit, et le reste en contexte. **N'existe que si l'assistant déclare des états décisifs** — voir *Quand la réponse ne peut pas être une opinion* |
| `get_events` | une profondeur en heures (`hours` : 12 par défaut, 168 au plus) et une pièce facultative (`room`) | ce qui s'est passé récemment dans toute la maison, du plus récent au plus ancien : chaque ligne dit ce qui est arrivé (`started` ou `ended`) et non la valeur d'après. Vingt-cinq lignes au plus, et `more` dit combien ont été laissées de côté — voir plus bas |
| `execute_command` | un identifiant de commande d'action (`command_id`), la valeur attendue le cas échéant (`value`), et un titre pour les seules commandes de type message (`title`) | le verdict du plugin : `sent`, `simulated`, `confirmation_required` ou `refused`, avec son motif. C'est le seul outil qui puisse modifier quoi que ce soit |

Un identifiant hors de portée reçoit toujours la même réponse — « pas
disponible pour l'assistant » — qu'il soit inventé, masqué, désactivé ou
supprimé. Le modèle ne peut donc rien apprendre en énumérant des identifiants :
c'est ce qui rend inutile tout plafond sur ses tentatives.

### La valeur d'une action, et son titre

Une action décrite par `get_equipment` ou par `search` porte un champ `expects`
quand elle attend quelque chose : `number` pour un curseur — avec son `min` et
son `max` —, `choice` pour une liste, avec les choix possibles, `color`, ou
`message` pour une notification. Ce champ annonce le **type** attendu ; la
valeur elle-même part dans `value`, et le titre d'une notification dans
`title`. Un bouton ordinaire n'a pas de champ `expects` et se passe de valeur.

La distinction n'est pas cosmétique. Ces deux clés se sont appelées `value`
toutes les deux, et un modèle qui recopiait le mot qu'il venait de lire
envoyait `value: "number"` — ce qu'un select sans liste de choix déclarée
acceptait, puis transmettait à la maison.

Le titre ne sert qu'aux commandes de sous-type message, où il devient le titre
de la notification et `value` son corps. Ailleurs il est ignoré. De même, une
valeur jointe à une commande qui n'en prend pas est ignorée — et le modèle en
est averti, sans quoi il rapportait « j'ai réglé le mode sur 30 » à propos
d'une commande partie nue.

### Ce que rend vraiment `get_history`

Jeedom ne conserve pas vos relevés tels quels. Passé `historyArchiveTime` —
deux heures par défaut, c'est un réglage du cœur et non du plugin —, la tâche
d'archivage remplace les relevés par des **moyennes**. Une demande sur
vingt-quatre heures rendait donc cent vingt relevés pour les deux dernières
heures et vingt-deux moyennes pour le reste, mélangés : des valeurs jamais
mesurées présentées comme des mesures, et un axe si peu régulier que
l'essentiel des points décrivait les deux dernières heures.

Le plugin demande donc l'agrégation au cœur dès que la période dépasse cette
fenêtre : moyennes horaires jusqu'à cinquante heures, journalières au-delà — et
dans la fonction choisie pour cette commande (`avg`, `min` ou `max`), pour ne
pas lire en moyenne une courbe que l'administrateur archive en maximum.

La réponse dit ce qu'elle rend, et c'est à lire avant les chiffres :

- `sampling` : `raw` (relevés bruts), `raw_every_N` (un relevé sur N),
  `transitions`, `hourly_avg`, `daily_max`… ;
- `source_points` : le nombre de lignes rendues par le cœur avant réduction ;
- `from` et `to` : la période **réellement** couverte, qui peut être plus
  courte que celle demandée ;
- `note` : présente quand ce sont des moyennes — pour que le modèle n'en cite
  aucune comme une mesure — et quand les points les plus anciens ont été
  écartés pour tenir dans le budget.

Un état **binaire ou textuel n'est jamais lissé**. Le cœur ne lisse que les
états numériques — et seulement quand leur mode d'historisation le permet —, et décimer une porte à pas fixe supprimerait justement les
ouvertures brèves, c'est-à-dire la seule chose qu'on demande à l'historique
d'une porte. Ces états-là sont réduits à leurs **transitions** : un point par
changement de valeur, plus le dernier relevé.

**Le dernier point d'une série agrégée n'est pas la valeur courante.** C'est la
moyenne d'une heure — ou d'une journée — qui n'est pas finie : à 14 h 10, le
dernier point horaire ne résume que dix minutes, et il ne vaudra plus la même
chose à 14 h 55. Une question sur le présent — « quelle température fait-il
maintenant ? », « le chauffage est-il en route ? » — se lit par `get_states`,
jamais par `get_history`. L'historique sert à raconter une tendance, pas un
instant.

Un état que Jeedom n'historise pas n'a pas d'historique du tout, et l'outil le
dit franchement : seule sa valeur courante est lisible, par `get_states`.

### Ce qui s'est passé récemment, et pourquoi la valeur ne suffit pas

Une mesure se lit dans sa valeur : une température de 19 °C dit tout ce qu'il y
a à dire. Un **événement**, non. « Détection humaine : 1 » ne dit pas qu'on
détecte quelqu'un, il dit qu'on a détecté quelqu'un — et sans date, le modèle
en parle au présent.

Le cas n'est pas théorique. Un plugin de vidéosurveillance qui reçoit une
détection ponctuelle lève sa commande à 1, et rien ne la redescend faute
d'événement de fin : trois semaines plus tard, elle vaut toujours 1. Un
assistant qui la lit ainsi annonce un intrus dans le jardin.

Deux choses répondent à cela :

- **`since`**, joint à l'état lui-même par `get_equipment` et `get_states` : la
  date de son dernier **changement**, et non celle de sa dernière lecture. Un
  contact de porte interrogé toutes les minutes est lu à l'instant et ouvert
  depuis hier soir : c'est « hier soir » qui compte.
- **`get_events`**, qui répond en un appel à « est-ce que quelqu'un est passé
  cette nuit ? ». Sans lui, la question coûtait un `get_equipment` par
  équipement puis un `get_history` par capteur — sur une installation à huit
  caméras portant chacune une vingtaine d'états, le plafond d'outils y passait
  entier pour relire cent cinquante zéros.

**Une ligne dit ce qui est arrivé, pas la valeur d'après.** C'est la distinction
qui a manqué le plus cher. Une détection humaine qui s'allume à 17 h 21 et
retombe à 17 h 22 sortait en « Humain détecté : 0, à 17 h 22 » — et l'assistant,
lisant zéro, a répondu que personne n'était passé. C'était le contraire de ce
que la ligne racontait. Chaque ligne porte donc `what` :

- `started` : l'état s'est allumé à ce moment-là, et il l'est toujours ;
- `ended` : il s'est **éteint** à ce moment-là, donc la chose a eu lieu juste
  avant. Une détection terminée reste une détection qui a eu lieu ;
- `changed` : l'état a bougé, sans qu'on puisse dire dans quel sens.

Seul le **dernier** changement de chaque état est listé. Pour les précédents —
« combien de fois cette nuit ? » —, c'est `get_history` sur la commande, qui
rend les transitions.

**Ce qui apparaît dans `get_events` est ce que vous avez choisi d'historiser.**
Le critère est un état **binaire et historisé** : historiser, c'est dire que
l'évolution de cet état mérite d'être conservée, donc que l'instant où il a
basculé compte. Le plugin ne devine rien sur le nom ni sur le type de la
commande, et ne connaît aucun plugin tiers par son nom : une caméra, un contact
d'ouverture, un détecteur de fuite et un capteur de fumée remontent par la même
porte. Un état que vous n'historisez pas n'apparaît jamais ici — c'est le
réglage à changer dans Jeedom si vous voulez l'y voir.

Les autorisations s'appliquent comme partout : un état que l'assistant n'a pas
le droit de lire n'apparaît pas, et un équipement masqué non plus — pas même
sous forme de date. Savoir qu'un capteur masqué a bougé cette nuit en dirait
déjà trop.

Quand la fenêtre ne contient rien, la réponse le dit **et** précise que le
silence ne prouve rien : une maison dont aucun état n'est historisé se lit
exactement comme une maison tranquille, et l'assistant ne doit pas conclure au
calme sur cette base.

### Chercher un genre d'appareil, et non un nom

Vos équipements portent les noms que vous leur avez donnés, et ils ne disent pas
toujours ce qu'ils sont. Huit caméras nommées d'après leur orientation — `EST`,
`NORD`, `SUD`, `OUESTPTZ` — ne répondent à aucune recherche sur « caméra ».

`search` regarde donc aussi les **types génériques** de Jeedom, qui rattachent
un équipement à ce qu'il **est** plutôt qu'au nom qu'on lui a donné :
`CAMERA_URL`, `THERMOSTAT_SETPOINT`, `FLAP_STATE`. Chercher « camera » trouve
alors les caméras, et l'équipement rendu porte `types` pour que l'assistant
puisse dire pourquoi.

Une limite qui n'en est pas une : seuls comptent les types des commandes que
l'assistant a le **droit** de voir. Chercher « lock » ne peut donc pas
apprendre qu'une serrure interdite existe quelque part, ni où.

### Un état jamais relevé n'est pas un état vide

Une commande peut n'avoir **jamais** porté de valeur : son équipement ne l'a pas
renseignée une seule fois depuis que le plugin la regarde. C'est courant sur les
détections rares — une caméra qui n'a jamais été masquée, un capteur de fumée
qui n'a jamais fumé.

Rendue telle quelle, elle partait en `value: ""`, ce qui se lit comme une valeur
vide. Le modèle, ne pouvant rien tirer de la valeur, se rabattait alors sur le
**nom** de la commande — et c'est arrivé en production : huit commandes « Caméra
masquée » jamais renseignées ont donné « toutes vos caméras sont masquées ».

Ces états portent désormais `never_read: true`, et la réponse ajoute la phrase
qui compte : ce n'est ni une valeur vide ni un zéro, la valeur est inconnue, et
il ne faut **jamais** deviner l'état de la maison d'après le nom d'une commande.

### Pourquoi il découvre la maison progressivement

Il serait plus simple d'envoyer au modèle la liste complète des équipements et
de leurs commandes dès le premier message. Sur une installation réelle, cette
liste fait plusieurs milliers de lignes. Elle serait envoyée **à chaque
demande**, facturée à chaque fois, et noyerait la question posée sous un
inventaire dont 99 % est hors sujet — ce qui dégrade la qualité des réponses
autant que la facture.

D'où le parcours en entonnoir : les pièces, puis les équipements d'une pièce,
puis le détail de ceux qui comptent. « Éteins le salon » coûte trois petits
appels au lieu d'un énorme. L'invite système demande explicitement au modèle de
procéder ainsi.

### Plusieurs outils dans un même aller-retour

Le modèle peut demander plusieurs outils dans un seul message, et la boucle les
exécute alors d'un coup : six extinctions demandées ensemble coûtent six
outils, mais **un seul aller-retour** facturé et une seule attente du réseau.
L'invite système le lui demande désormais explicitement — quand plusieurs
lectures ou actions sont indépendantes, elles partent dans le même message ;
il n'attend le résultat d'un outil que lorsque le suivant en a besoin.

C'est ce qui rend le plafond de quinze outils raisonnable, et c'est pourquoi il
est passé de dix à quinze. La demande emblématique donne l'échelle : « je vais
me coucher », sur une maison d'une soixantaine d'équipements, situe la maison,
liste deux ou trois pièces, détaille quelques équipements, éteint une
demi-douzaine de choses puis vérifie ce qui est parti — **douze à dix-huit
outils**.
À dix, la boucle coupait au milieu de l'extinction, et l'assistant concluait,
poliment, sur une maison à moitié éteinte, avec le statut `LIMIT`.

Le plafond compte des **outils**, pas des allers-retours : si le modèle groupe
bien ses appels, quinze outils peuvent tenir en trois ou quatre échanges avec
OpenAI.

### Quand plusieurs équipements peuvent correspondre

Si vous avez trois « Lumière » dans trois pièces, ou deux volets au même nom,
l'assistant ne tranche plus au hasard : l'invite système lui demande de **les
nommer et de vous demander lequel**. Une chance sur trois d'ouvrir le volet de
la chambre du bébé coûte plus cher qu'une question.

La contrepartie est à connaître : votre réponse à cette question est **une
demande de plus**, donc un aller-retour facturé de plus. Nommer la pièce dès la
première phrase — « éteins la lumière de la cuisine » — évite l'échange. Et si
la question revient toujours sur les mêmes équipements, le geste de fond est de
les renommer dans Jeedom : le nom Jeedom est tout ce que le modèle voit.

### Trois plafonds peuvent arrêter un tour

- **Les outils.** Leur nombre pour une seule demande est plafonné (*Outils par
  demande*, 15 par défaut, 30 au plus). Atteint, la boucle rend la parole au
  modèle **sans aucun outil** : c'est la seule façon de l'obliger à conclure
  avec ce qu'il sait au lieu d'en appeler un de plus. C'est un garde-fou contre
  le coût — un modèle qui s'égare enchaîne les appels indéfiniment, et chacun
  est facturé.
- **Le temps.** Un tour reçoit un budget calé sur le `max_execution_time` de
  PHP, moins trente secondes de marge : il faut qu'il reste de quoi enregistrer
  la conversation, écrire le journal et publier les commandes. Le budget est
  vérifié avant chaque appel au modèle, jamais après — la boucle s'arrête donc
  d'elle-même au lieu d'être tuée par PHP au milieu d'une action, ce qui
  laissait des commandes parties sans trace. Depuis un scénario ou une tâche,
  où PHP ne coupe rien, le budget vaut dix minutes.
- **La longueur de la réponse.** *Longueur maximale d'une réponse* peut couper
  la phrase du modèle en plein milieu. Le plugin l'écrit alors dans la réponse
  elle-même, plutôt que de vous rendre une demi-phrase pour une réussite.

Dans les trois cas, la demande se termine avec le statut `LIMIT`.

### Envoyée n'est pas aboutie

Quand le plugin exécute une commande, il dit au modèle qu'elle est **partie**,
jamais qu'elle a **abouti**. La différence est réelle : un module Z-Wave hors
de portée accepte l'ordre sans rien faire. L'invite système demande au modèle
de vérifier par `get_states` avant d'affirmer un résultat. C'est la raison pour
laquelle une demande simple consomme parfois un appel de plus que prévu.

Cette vérification avait un défaut, et il explique des réponses que vous avez
peut-être croisées. Entre l'envoi de la commande et sa relecture, il ne
s'écoulait que l'aller-retour vers OpenAI : une à trois secondes. Un volet en
fin de course, une ampoule Zigbee derrière un routeur, un thermostat qui ne
parle qu'au relevé suivant mettent cinq secondes à remonter leur état. Le
modèle lisait donc **l'ancienne valeur**, annonçait un échec qui n'avait pas eu
lieu — et était tenté de refaire l'action.

`get_states` accepte donc une **attente** : le paramètre `wait`, en secondes,
que l'invite système demande au modèle d'employer (3 à 5) juste après avoir
agi. Le plugin relit alors les commandes par petits pas, jusqu'à ce que leur
date de **relevé** change ou que le délai soit écoulé — c'est la date de relevé
qui est surveillée, et non la valeur : une lampe qu'on rallume alors qu'elle
était déjà allumée ne change pas de valeur, mais son plugin la relève quand
même. La réponse rend `waited`, le temps réellement attendu, et pour chaque
état un `refreshed`.

**`refreshed: false` veut dire « l'équipement n'a rien dit », pas « la commande
a échoué ».** C'est toute la distinction que l'attente apporte : auparavant,
« la valeur n'a pas changé » et « l'équipement est resté muet » se lisaient de
la même façon, c'est-à-dire comme une commande sans effet. Le plugin le
rappelle au modèle dans la réponse elle-même, pour qu'il vous le dise ainsi.

Ce que l'attente coûte, et qu'il vaut mieux savoir :

- elle est bornée à **dix secondes par appel** et à **quinze secondes cumulées
  sur une même demande**, remises à zéro à chaque nouvelle demande. Au-delà, la
  lecture se fait quand même, sans attendre, et la réponse dit au modèle que le
  budget est épuisé ;
- un état que l'assistant n'a pas le droit de lire n'est jamais attendu. Sans
  cela, la durée de la réponse renseignerait sur une commande masquée — « ça a
  mis cinq secondes, donc quelque chose bouge là-bas » ;
- l'attente se prend sur le **budget de temps du tour** : cinq secondes
  attendues sont cinq secondes de moins pour les allers-retours qui font le
  travail ;
- elle se déroule **pendant que le verrou de l'assistant est tenu**. Une autre
  demande posée à ce moment-là est refusée, comme n'importe quelle demande
  concurrente — voir *Une demande à la fois*.

## La confirmation des actions sensibles

Quand l'assistant demande une commande réglée sur *Confirmation*, rien ne part.
La conversation est suspendue en l'état et un bandeau apparaît dans l'onglet
*Discussion* :

> K2000 souhaite ouvrir le portail. **Confirmer** / **Annuler**

Les autres outils que le modèle avait demandés dans le même message ne sont pas
exécutés non plus : ils reçoivent un résultat « en attente de confirmation ».

- **Confirmer** exécute la commande, puis rend la main au modèle, qui vérifie
  l'état et conclut. La conversation reprend exactement où elle s'était arrêtée.
- **Annuler** n'exécute rien et le dit au modèle, qui vous répond en
  conséquence. La demande se termine alors avec le statut `REFUSED`.
- **Ne rien faire** : au bout de **cinq minutes**, la demande est caduque. Le
  jeton de confirmation expire, le bandeau ne vaut plus rien, et une
  confirmation tardive est refusée. Il faut reformuler la demande.
- **Poser une autre question** annule la confirmation en attente. Si vous
  reformulez au lieu de répondre oui ou non, c'est que vous avez changé d'avis :
  le portail ne s'ouvrira pas au *Confirmer* d'un onglet resté ouvert.

Cette expiration n'est pas une commodité d'affichage. Confirmer à retardement
l'ouverture d'un portail demandée une heure plus tôt, dans un contexte qu'on a
oublié, est précisément ce qu'il faut éviter.

**Une confirmation n'est pas un laissez-passer.** Tous les contrôles sont
rejoués au moment du clic : si l'autorisation a été retirée, l'équipement
désactivé ou masqué, ou le plugin basculé en lecture seule entre-temps, la
commande ne part pas. C'est un accord sur une action précise, à cet instant-là.
En mode `simulation`, la confirmation est posée quand même — c'est ce qui
permet d'éprouver le parcours entier sans rien risquer — mais la question le
dit : « même confirmée, elle ne partira pas vers la domotique ».

Une confirmation ne porte jamais que sur **une seule commande**. Dès qu'elle
naît, le tour s'arrête net et les autres outils du même message reçoivent « en
attente de confirmation » : ils ne sont pas refusés, seulement remis à plus
tard.

**Qui peut confirmer.** Pas seulement vous. La page du plugin est réservée aux
administrateurs, mais les commandes `Confirmer` et `Annuler` sont des commandes
Jeedom ordinaires : le widget de discussion les actionne, et Jeedom les autorise
à tout compte connecté qui a le droit d'exécution sur l'équipement — un compte
`user` de la maison, donc, s'il voit l'assistant sur son tableau de bord. C'est
le modèle de droits de Jeedom et non une faille, mais il faut le savoir avant de
partager un tableau de bord : donner la tuile, c'est donner le pouvoir de
confirmer une ouverture de portail. Les droits d'un équipement se règlent dans
sa configuration Jeedom.

**À quoi ces deux commandes répondent.** À la **dernière confirmation posée,
d'où qu'elle vienne**. Elles ne portent aucun jeton : elles prennent celui qui
est en cours. Un scénario peut donc approuver une confirmation née trois
secondes plus tôt d'une phrase tapée par un humain sur la tuile, et
réciproquement.

C'est assumé — un scénario n'a aucun jeton sous la main, et lui en demander un
le rendrait inutilisable — et c'est borné par ailleurs : une confirmation ne
vaut que cinq minutes, ne porte que sur une commande précise, et tous les
contrôles sont rejoués à l'exécution. Il reste qu'un scénario branché sur
*Confirmer* est, en pratique, un accord donné d'avance à ce que l'assistant
demandera dans les cinq minutes qui suivent. N'en écrivez un que si vous
accepteriez de cliquer vous-même sans lire la question — autrement dit :
presque jamais sur une serrure, un portail ou une alarme.

Une confirmation en attente est visible depuis un scénario : la commande
`Confirmation en attente` vaut 1 tant qu'il y en a une, et `Objet de la
confirmation` porte alors le libellé de la commande concernée — c'est elle
qu'il faut annoncer, pas la réponse du modèle.

## Une demande à la fois

Un assistant ne joue qu'un tour de conversation à la fois. Une seconde demande
posée pendant qu'un tour est en cours — la tuile pendant qu'un scénario parle,
deux onglets ouverts, un F5 sur un envoi — est **refusée sur-le-champ**, avec
le statut `REFUSED` et la phrase « une demande est déjà en cours pour cet
assistant : rien n'a été exécuté ». Elle n'est pas mise en file d'attente.

C'est un choix. Le verrou est tenu pendant des appels réseau qui durent des
dizaines de secondes — et, depuis que `get_states` sait attendre, pendant
quelques secondes d'attente délibérée en plus. Faire patienter le second appelant reviendrait à le
laisser derrière une porte close jusqu'à ce que PHP le tue, pour finalement
jouer une demande devenue obsolète. Refuser tout de suite est immédiat,
explicite, et laisse la main à celui qui parle. Sans ce verrou, deux demandes
simultanées se lisaient la même conversation et la seconde écrasait la
première — mémoire, frise et confirmation comprises.

Le même verrou tient la confirmation : deux clics simultanés sur *Confirmer*
n'ouvrent pas le portail deux fois. Le jeton est de toute façon consommé et
écrit sur le disque avant la moindre exécution, de sorte que le second appel ne
trouve plus rien à confirmer.

Le verrou vaut **par assistant** : deux assistants ne partagent ni mémoire ni
conversation, et ne s'attendent donc pas l'un l'autre.

Une seule demande fait exception : l'**alerte automatique**. Elle se joue en
arrière-plan, personne n'attend derrière la porte, et une levée de doute perdue
parce que quelqu'un demandait la température du salon serait la pire des
économies. Elle attend donc la fin du tour en cours, deux minutes au plus ;
au-delà, elle est refusée comme les autres et le journal le dit.

## Les statuts d'une demande

Chaque demande se termine sur l'un de cinq statuts, publié dans la commande
`Statut`, affiché dans la frise de la discussion et conservé dans le journal.
Ils se lisent **dans cet ordre** : le premier qui s'applique l'emporte.

| Statut | Ce qu'il dit |
|---|---|
| `CONFIRMATION` | une confirmation est née : rien n'est parti, la conversation est suspendue en l'état |
| `LIMIT` | un plafond a arrêté le tour — la limite d'outils, le budget de temps, ou une réponse coupée par la longueur maximale. La réponse ne tient compte que de ce que le modèle savait à ce moment-là |
| `ERROR` | rien d'exploitable n'a été obtenu : panne du modèle ou du réseau, réponse vide, ou tous les outils du tour en échec |
| `REFUSED` | rien n'est parti, et au moins un refus explique pourquoi. C'est une règle de la maison, pas un incident |
| `SUCCESS` | le reste |

Deux distinctions méritent d'être connues d'un scénario. Un tour dont tous les
outils ont échoué vaut `ERROR` et non `SUCCESS` : « j'ai répondu » et « rien
n'a marché mais j'ai bavardé » ne doivent pas se ressembler, sans quoi le
scénario enchaîne comme si la maison avait obéi. Et un tour dont tous les
outils ont été **refusés** vaut `REFUSED`, pas `ERROR` : un refus s'explique à
l'utilisateur, une panne se répare par l'administrateur, et les confondre
envoie l'un chercher ce que l'autre doit réparer.

`REFUSED` couvre aussi trois cas où le modèle n'a rien à voir : une
confirmation annulée, une confirmation expirée ou déjà traitée, et une demande
refusée parce qu'une autre était en cours. Dans ces trois cas, rien n'a été
exécuté.

## Depuis un scénario

Chaque assistant expose ses commandes à Jeedom.

| Commande | Type | Contenu |
|---|---|---|
| `Demander` | action | pose une demande : le **message** du bloc est le texte de la demande. Le titre du bloc n'est pas employé |
| `Réponse` | info | la dernière réponse de l'assistant |
| `Statut` | info | `SUCCESS`, `CONFIRMATION`, `REFUSED`, `LIMIT` ou `ERROR` — voir *Les statuts d'une demande* |
| `Actions exécutées` | info | le nombre de commandes réellement envoyées à la maison au dernier tour. Une lecture n'y compte pas, une simulation non plus |
| `Jetons consommés` | info | ce que le dernier tour a coûté, jetons d'invite et de réponse confondus. Historisée : Jeedom en trace la courbe, et un scénario peut prévenir quand elle monte |
| `Dernière demande` | info | la date de la dernière demande, incident compris |
| `Confirmation en attente` | info | 1 tant qu'une confirmation est attendue |
| `Objet de la confirmation` | info | le libellé de la commande dont l'accord est attendu ; chaîne vide quand il n'y a rien à confirmer |
| `Confirmer` / `Annuler` | action | répondent à la **dernière confirmation posée**, quelle qu'en soit l'origine ; échouent bruyamment s'il n'y en a aucune |
| `Nouvelle conversation` | action | vide la mémoire de l'assistant. Le journal, lui, est conservé |

*Demander*, *Confirmer* et *Annuler* lèvent une erreur quand le tour échoue,
au lieu de rendre une réponse vide : un scénario doit s'arrêter bruyamment
plutôt que de continuer comme si l'assistant avait répondu.

Le bloc *Demander* prend le temps que prend le modèle : plusieurs secondes,
parfois une vingtaine quand il enchaîne les outils, et il bloque le scénario
pendant ce temps. Un scénario déclenché toutes les minutes ne devrait pas
l'appeler : chaque passage est facturé, et le second se ferait de toute façon
refuser tant que le premier n'a pas fini — voir *Une demande à la fois*.

### Un exemple complet

Annoncer chaque soir l'état de la maison, en laissant l'assistant formuler la
phrase.

- **Déclencheur** : `22:30` (déclencheur programmé) ;
- **ALORS** :
  1. action **Demander** sur l'assistant, message :
     `Résume en deux phrases ce qui est encore allumé ou ouvert dans la maison.`
  2. bloc **SI** `#[Maison][KITT][Statut]# == "SUCCESS"`
     — **ALORS** : votre commande de notification ou de synthèse vocale, avec
     `#[Maison][KITT][Réponse]#` comme message.

Le bloc *SI* n'est pas décoratif : sans lui, un échec de l'API OpenAI vous
ferait annoncer un message d'erreur à voix haute dans le couloir.

Un second scénario peut surveiller les confirmations restées sans réponse :

- **Déclencheur** : `#[Maison][KITT][Confirmation en attente]#` ;
- **SI** `#[Maison][KITT][Confirmation en attente]# == 1` ;
- **ALORS** : une notification sur votre téléphone, avec
  `#[Maison][KITT][Objet de la confirmation]#` comme message — c'est la
  commande qui dit quoi confirmer. La `Réponse` ne convient pas ici : elle
  porte la phrase du modèle, qui annonce qu'il attend votre accord sans
  toujours redire sur quoi.

Vous avez cinq minutes pour y répondre : depuis la page du plugin, depuis le
widget de discussion, ou depuis un scénario par les commandes *Confirmer* et
*Annuler* — avec les réserves écrites plus haut sur ce dernier chemin.

## Ce que ça coûte

Chaque demande consomme des **jetons**, facturés par OpenAI selon le modèle
employé. Le plugin ne facture rien et ne peut rien plafonner chez OpenAI : le
plafond de dépense se règle dans votre compte OpenAI, et c'est là qu'il faut le
mettre.

Le coût par demande est affiché dans l'onglet *Historique*, jeton par jeton.
Avec `gpt-4o-mini`, une demande ordinaire se compte en fractions de centime.
Ce qui le fait monter :

- **une maison très fournie** : plus il y a de pièces et d'équipements visibles,
  plus les réponses des outils sont volumineuses, et chacune est renvoyée au
  modèle à l'appel suivant ;
- **une mémoire de conversation longue** : le réglage *Échanges gardés en
  mémoire*
  (12 par défaut) détermine combien de tours précédents repartent à chaque
  demande. Le coût d'un échange croît avec la longueur de la conversation, pas
  seulement avec celle de la question ;
- **un plafond d'outils élevé** : quinze outils autorisés, ce sont au pire
  dix-sept allers-retours facturés pour une seule phrase — le plafond, plus
  deux allers de réserve pour conclure. Au pire seulement : un modèle qui
  groupe ses appels dépense ses quinze outils en trois ou quatre échanges ;
- **le résumé de la maison** joint à l'invite système, s'il est activé : il
  repart à chaque demande ;
- **le modèle choisi**, qui pèse plus lourd que tout le reste. Un modèle
  haut de gamme peut coûter vingt fois `gpt-4o-mini` pour un résultat
  comparable sur ce genre de tâche.

Le bouton **Nouvelle conversation** vide la mémoire : c'est aussi un geste
d'économie, à faire dès qu'on change de sujet.

### Le cache d'OpenAI

OpenAI garde de lui-même en mémoire le **début** des requêtes qu'il reçoit, dès
qu'il dépasse un millier de jetons environ, et facture nettement moins cher la
partie qu'il reconnaît. Il ne reconnaît qu'un début strictement identique :
au premier caractère qui diffère, le reste se paie plein pot.

Le plugin est rangé pour en profiter. Chaque requête commence par le catalogue
des outils, qui ne change jamais, puis par l'invite système, construite **du
plus stable au plus changeant** : le ton, la ville, les règles de conduite, le
mode de sécurité, la fiche de la maison, les consignes supplémentaires, les
consignes de l'assistant — puis, tout à la fin, le résumé de la maison et
l'heure, qui change chaque minute. Viennent ensuite les échanges précédents,
renvoyés tels qu'ils ont été écrits. Deux demandes rapprochées partagent ainsi
presque tout leur début.

Ce que le cache a réellement servi se lit dans l'onglet *Historique* : à côté
des jetons d'une demande, « dont N en cache » donne la part de l'invite qu'OpenAI
a reconnue. Elle est **comprise** dans le total, pas ajoutée. Rien ne s'affiche
quand elle est nulle — un modèle ou une passerelle qui ne la déclarent pas, ou
une ligne écrite par une version antérieure du plugin. La mémoire, elle, joue
contre le cache une fois pleine : chaque nouvel échange en chasse le plus
ancien, et le début de la conversation change avec lui.

En tête de l'onglet *Historique*, une ligne totalise ce que les demandes
affichées ont coûté : combien elles étaient, combien de jetons elles ont pris,
et leur durée moyenne. Elle ne porte que sur les lignes sous vos yeux — changer
la limite ou le statut change le total. La commande d'information *Jetons
consommés*, elle, est historisée : Jeedom en trace la courbe, et un scénario
peut s'en servir pour prévenir.

### Le plafond du jour

Le réglage *Demandes par jour* arrête l'emballement. Au-delà du nombre fixé, la
demande suivante est **refusée sur-le-champ, avant tout appel à OpenAI** : elle
ne coûte rien, elle rend le statut `REFUSED`, et elle dit pourquoi. Le compte
repart à minuit.

Il est livré à `0`, c'est-à-dire sans plafond : le plugin n'a pas à décider
combien de fois on a le droit de parler à sa maison. Ce contre quoi il protège
est ailleurs — un scénario branché sur *Demander* et déclenché par un capteur
qui vibre, une boucle qui se rappelle elle-même, un widget resté ouvert sur une
page qui se recharge. Rien, du côté de la box, n'arrêtait cela : le plafond
d'OpenAI est mensuel, se compte en dollars, et se découvre un ou deux jours
plus tard.

Deux précisions qui comptent :

- le compte est **commun à tous les assistants** — la clé API l'est, la facture
  aussi ;
- il ne retient que les demandes qui ont **réellement atteint le modèle**. Un
  refus, une clé absente, un assistant occupé sont journalisés mais ne comptent
  pas : sans cela, une journée plafonnée se nourrirait de ses propres refus et
  ne se rouvrirait jamais.

Une **confirmation en attente n'est jamais bloquée** par le plafond. Elle
reprend un tour déjà payé et à moitié joué sur la maison : couper là laisserait
un volet à mi-course et un assistant muet, ce qui vaut moins que la poignée de
jetons économisés.

## La page Santé

*Analyse → Santé* affiche une ligne par point de contrôle du plugin, sans rien
coûter et sans appeler OpenAI :

- la **clé API**, présente ou non ;
- le **mode de sécurité** et, en lecture seule comme en simulation, le rappel
  que rien ne part vers la maison ;
- le **modèle** employé ;
- le nombre de commandes **autorisées** et **en confirmation** ;
- les commandes **sensibles réglées sur « Autorisée »**, c'est-à-dire celles qui
  ouvrent, déverrouillent ou désarment sans rien demander ;
- le **dossier de travail**, accessible en écriture ou non ;
- la **consommation du jour**, et le plafond s'il y en a un.

Trois lignes seulement peuvent virer au rouge, et c'est délibéré : une page
Santé qui clignote pour des choix licites cesse d'être lue. Ce sont l'absence
de clé — le plugin ne peut alors rien faire —, un dossier de travail qui ne
s'écrit pas — plus de mémoire, plus de journal —, et une commande sensible
autorisée sans confirmation. Cette dernière reste un choix que l'interface
déconseille sans l'interdire ; elle est signalée ici parce que c'est le seul
endroit où on la reverra sans la chercher, et le compteur retombe à zéro dès
que le mode global n'est plus `actions` : ce qui s'allume en rouge peut
vraiment partir.

## La fiche de la maison

Le plugin sait tout de la maison **technique** — les pièces, les équipements,
leurs valeurs, leur historique — et rien de la maison **vécue**. Il ignore
qu'un enfant dort à vingt heures, qu'un chat circule la nuit, que la chambre
est chauffée par un poêle et le bureau par un convecteur. « Prépare la nuit »
ne veut donc rien dire de plus pour lui que ce que les états lui montrent.

La **fiche de la maison**, dans la configuration du plugin (bloc *La maison*),
est l'endroit où le lui dire. Sept champs, tous facultatifs, tous vides à
l'installation. Elle voisine avec le réglage *Résumé de la maison* sans rien
avoir à voir avec lui : celui-ci est un état technique relevé par le plugin à
chaque demande, la fiche est écrite par vous et ne change que quand vous la
changez.

| Champ | Ce qu'il change |
|---|---|
| **Qui vit ici** | des effectifs et des tranches d'âge, pas des prénoms : « deux adultes, un enfant de trois ans », « quelqu'un travaille de nuit ». Préparer la nuit ne veut pas dire la même chose dans une maison qui dort à vingt heures et dans une maison qui se lève à vingt-deux |
| **Animaux** | un chat qui circule la nuit explique des détections de mouvement au lieu de les rendre inquiétantes, et interdit de fermer certaines portes |
| **Logement** | maison ou appartement, nombre d'étages, la pièce mal isolée, la véranda qui monte à trente degrés en juillet |
| **Chauffage et eau chaude** | ce qu'on peut répondre à « j'ai froid ». Une pompe à chaleur, un poêle et des convecteurs n'appellent pas les mêmes gestes, et aucun nom de commande ne le dit |
| **Habitudes et horaires** | coucher, lever, télétravail, absences régulières. C'est le champ le plus rentable de la fiche, celui qui donne un sens à « comme d'habitude » |
| **À ne jamais faire** | des consignes au modèle, et rien d'autre. Ce champ n'a aucune valeur de sécurité, et la section qui suit dit pourquoi |
| **Véhicule électrique** | à ne remplir que si une borne de recharge est pilotée par Jeedom. Sans borne pilotable, il n'y a aucune décision à prendre |

**Seuls les champs remplis partent.** Un champ laissé vide n'apparaît nulle
part dans l'invite système, et une fiche entièrement vide n'y ajoute rien du
tout — pas même l'annonce d'une description qui ne viendrait jamais.

Ce qui part est introduit par une phrase qui dit son autorité, et elle compte
autant que le contenu : ces informations viennent du propriétaire du logement,
elles ne sont pas vérifiables par les outils, et **si un outil dit autre chose,
c'est l'outil qui a raison**. Sans cela, une fiche qui annonce « personne à la
maison en journée » l'emporterait sur un capteur de présence qui dit le
contraire. La fiche décrit l'ordinaire du logement ; les outils décrivent
l'instant.

Le champ **Consignes supplémentaires** existe toujours et n'a pas changé de
rôle : il passe **après** la fiche, après toutes les autres consignes du
plugin, et reste l'endroit
des instructions qui ne sont pas des faits sur la maison — un ton, une manière
de répondre, une règle de conduite.

### On ne renseigne que ce qui change une décision

C'est le critère qui a décidé de ces sept champs, et il vaut aussi pour ce que
vous écrivez dedans. Trois tentations reviennent, et les trois se refusent pour
la même raison.

- **L'adresse complète.** Inutile : la ville réglée dans Jeedom part déjà, et
  elle suffit à situer l'heure, le lever du soleil et la saison. Le numéro de
  rue ne décide de rien.
- **Les prénoms et les âges exacts des enfants.** « Un enfant de trois ans »
  change une décision — on ne baisse pas le volet de sa chambre à l'heure de sa
  sieste. « Léa, trois ans » n'en change pas une de plus : le prénom n'ajoute
  qu'une donnée personnelle.
- **La marque et le modèle du véhicule.** Ils ne décident de rien non plus.
  Seule compte l'existence d'une borne de recharge pilotable par Jeedom, et
  éventuellement l'heure à laquelle la voiture doit être prête.

La raison est la même dans les trois cas, et elle mérite d'être dite en clair :
cette fiche **repart chez OpenAI à chaque demande**, pour toutes les demandes à
venir. Une ligne qui ne change aucune décision est donc une donnée personnelle
exposée en pure perte, payée en jetons à chaque question, et qui dilue les
lignes utiles au milieu desquelles elle se trouve. Une fiche courte et précise
donne de meilleures réponses qu'une fiche exhaustive.

Écrivez-la en phrases ordinaires, comme vous l'expliqueriez à quelqu'un qui
garde la maison une semaine. Il n'y a ni format, ni mots-clés à respecter. Un
champ écrit sur plusieurs lignes est remis à plat sur une seule avant d'être
envoyé : le bloc est fait d'une ligne par champ, et un retour au milieu de l'un
d'eux produirait une ligne sans libellé, dont le modèle ne saurait plus ce
qu'elle décrit.

Le plugin tient la même ligne et borne ce que la fiche peut peser : **300
caractères par champ** — trois ou quatre phrases, de quoi dire ce qui change
une décision, trop peu pour y recopier une vie — et **1500 caractères pour la
fiche entière**. Ce qui dépasse est coupé au dernier mot entier et signalé par
des points de suspension — une phrase amputée en plein milieu d'un mot se lit
comme une donnée abîmée, et le modèle essaie d'en deviner la fin. Les champs
sont pris dans l'ordre du tableau ci-dessus : si la fiche déborde, ce sont les
derniers champs qui sont écourtés, puis abandonnés. Ces plafonds ne sont pas une
avarice de place. Le reste de l'invite système — le ton, les consignes de
conduite, le mode de sécurité, le résumé de la maison — pèse quelque 2500
caractères : une fiche sans limite représenterait la moitié de ce que le modèle
lit à chaque demande, et les consignes s'y noieraient. Si un champ vous paraît
tronqué, c'est cela qui s'est produit, et le bouton d'aperçu le montre.

### « À ne jamais faire » n'est pas une barrière

Ce champ est une **consigne donnée au modèle**, au même titre que les autres.
Il n'est pas un contrôle de sécurité, et le plugin ne l'applique pas : il se
contente de le transmettre, et un modèle de langage suit ses consignes la
plupart du temps, pas toujours.

La barrière, ce sont les **autorisations**. Elles sont vérifiées par le plugin,
à chaque appel d'outil, avant toute exécution, et le modèle n'a aucun moyen de
les contourner.

L'exemple à garder en tête : quelqu'un qui écrit « n'ouvre jamais le portail »
dans la fiche tout en laissant la commande d'ouverture *Autorisée* n'est pas
protégé par ce qu'il a écrit. Il l'est par le réglage de la commande — et s'il
la laisse autorisée, il n'est pas protégé du tout. Ce qui doit rester fermé se
règle dans l'onglet *Autorisations*, sur *Interdite* ou sur *Confirmation*. Le
champ sert à autre chose : « ne propose pas de couper le congélateur », « ne
rallume rien de toi-même après vingt-trois heures » — des préférences, pas des
verrous.

### Voir ce qui part, plutôt qu'en croire la documentation

Sous la fiche, le bouton **Voir ce qui part à chaque demande** enregistre
d'abord la configuration, puis affiche l'invite système complète, telle qu'elle
serait envoyée, avec sa taille en caractères. Il enregistre d'abord parce que
l'invite est construite à partir de la configuration enregistrée : c'est donc
bien ce que vous venez de taper qui s'affiche. C'est le texte réel, pas un
résumé : le ton, les consignes du plugin, la fiche assemblée à
partir des seuls champs remplis, vos consignes supplémentaires, puis le résumé
de la maison et l'heure, placés en dernier parce qu'ils changent d'une demande
à l'autre.

C'est le moyen de vérifier par soi-même ce que cette documentation affirme —
qu'un champ vide ne part pas, que la fiche est bien introduite comme
non vérifiable, que rien d'autre ne s'y est glissé. C'est aussi le moyen de
mesurer ce qu'une fiche bavarde coûte : la taille affichée repart à chaque
demande.

L'aperçu ne coûte rien et n'appelle pas OpenAI : l'invite est assemblée sur la
box, exactement comme au début d'un tour. Ce qui manque au tableau est ce qui
change à chaque fois — votre demande, la mémoire de la conversation et les
résultats des outils, qui s'ajoutent ensuite. Il fonctionne aussi avant que le
premier assistant n'existe, ce qui permet de remplir la fiche en la relisant
d'emblée.

## Ce qui part chez OpenAI

Il faut le dire franchement, parce que c'est une API distante et payante.

**Ce qui part** : le texte de votre demande ; les noms de vos pièces, de vos
équipements et de vos commandes, dès lors qu'ils sont visibles par l'assistant ;
les valeurs de ces commandes quand un outil les lit ; l'heure courante, et la
ville si Jeedom la connaît ; **les champs remplis de la fiche de la maison**,
ainsi que vos *Consignes supplémentaires*.

La fiche mérite une mention à part, parce que c'est le seul endroit du plugin
où vous écrivez vous-même des informations sur votre foyer — qui y vit, à
quelles heures, avec quels animaux. Elle repart intégralement à chaque demande,
et seuls les champs que vous avez remplis en font partie. C'est la raison de la
règle donnée plus haut : **on n'y renseigne que ce qui change une décision**.
Voir *La fiche de la maison*.

Vous n'êtes pas obligé de croire ce paragraphe sur parole. Le bouton **Voir ce
qui part à chaque demande**, sous la fiche dans la configuration, affiche
l'invite système complète, telle qu'elle serait envoyée, avec sa taille : c'est
la liste ci-dessus, mise à l'épreuve de votre propre installation.

**Ce qui ne part jamais** : vos identifiants Jeedom ; les commandes masquées et
les équipements masqués, dont l'assistant ignore l'existence ; les équipements
du plugin lui-même, exclus d'office pour qu'un assistant ne se pilote pas.

Votre clé API, elle, part à chaque appel : c'est elle qui vous identifie auprès
d'OpenAI, et elle voyage dans l'en-tête `Authorization` de chaque requête. Elle
ne part que vers l'adresse réglée dans *Adresse de l'API* — raison de plus pour
ne pas la détourner à la légère — et elle n'est jamais écrite dans les journaux
du plugin, qui la remplacent par un masque partout où elle apparaîtrait.

Si vos équipements portent des noms parlants — et ils en portent, c'est la
raison d'être des noms — alors ces noms partent. « Chambre de Léa », « Volet
bureau de Marie », « Garage voiture d'appoint » disent quelque chose de votre
foyer.

Pour restreindre davantage :

- **masquer un équipement** entier depuis l'onglet *Autorisations* : ni son nom,
  ni ses commandes, ni ses valeurs ne quitteront jamais la box ;
- **refuser la lecture** d'une commande info précise : même effet, à l'échelle
  d'une commande ;
- **couper le résumé de la maison** (*Résumé de la maison*) : moins
  de contexte envoyé d'emblée, l'assistant se débrouille avec ses outils ;
- **renommer** ce qui est trop personnel. Le nom Jeedom est ce que voit le
  modèle ;
- **vider un champ de la fiche de la maison** : il cesse aussitôt de partir, et
  la question suivante se passe de lui.

## Quand la réponse ne peut pas être une opinion

Une consigne écrite en toutes lettres — « ne te fie qu'aux détections
croisées » — reste une consigne. Le modèle la suit le plus souvent ; puis un
jour il additionne trois mouvements isolés et annonce une intrusion, ou lit
« Humain détecté : 0 » et annonce le calme. Quand la réponse engage la sécurité
du logement, « le plus souvent » ne suffit pas.

Le champ **États qui décident**, dans l'onglet *Équipement*, retire cette
latitude au modèle. On y déclare des **types génériques Jeedom**, séparés par
des virgules, huit au plus — `ALARM_STATE`, par exemple. L'assistant reçoit
alors un outil de plus, **`check_alert`**, dont le verdict est calculé par le
plugin : il ne reçoit plus des faits à peser, mais une conclusion à formuler.
C'est la règle du plugin — le modèle demande, le plugin décide — appliquée à la
lecture, là où elle ne valait que pour l'action.

Trois verdicts, et le troisième compte autant que les deux autres :

| Verdict | Ce qu'il veut dire |
|---|---|
| `CONFIRMED` | au moins un état décisif a basculé dans la fenêtre |
| `NOTHING` | aucun, **et** il y avait bien des états décisifs à surveiller : un silence constaté, pas un silence supposé |
| `UNKNOWN` | aucun état décisif lisible. Rien n'était surveillé, donc rien ne peut être conclu — **ce n'est pas le calme**, et la réponse doit le dire |

Tout le reste de ce qui a bougé part sous `context` : cela décrit ce que les
capteurs ont vu, et **ne fait jamais un verdict, quel qu'en soit le nombre**.
C'est précisément l'objet de l'outil. Un état décisif que l'assistant n'a pas le
droit de lire ne décide de rien et n'est pas même compté : les autorisations
priment ici comme partout.

Le choix du critère mérite un mot. Un type générique rattache un état à ce qu'il
**est**, dans le vocabulaire du cœur, et non au nom qu'un plugin ou un
propriétaire lui a donné : `ALARM_STATE` désigne une alarme qu'on ait installé
telle ou telle marque de caméra. C'est ce qui permet à cette mécanique de ne
connaître aucun plugin par son nom.

Reste une limite, et elle est réelle : le plugin garantit que le **verdict** est
calculé sur les seuls états déclarés, et il l'assortit de la consigne de ne pas
le discuter. Il ne peut pas garantir les mots que le modèle écrira ensuite. Pour
une garantie de bout en bout, c'est la commande d'information *Statut* et un
scénario qu'il faut lire, pas une phrase.

### L'assistant qui se réveille tout seul

La case **Alerter tout seul**, sous le champ précédent, retourne la mécanique :
au lieu d'attendre qu'on l'interroge, l'assistant part de lui-même dès qu'un des
états décisifs bascule. Il fait sa levée de doute, publie sa réponse dans la
commande *Réponse*, et un scénario n'a plus qu'à l'envoyer sur un téléphone.

Le filtre est alors en amont, là où il est le meilleur : une règle de détection
croisée ne se déclenche que lorsque deux détections indépendantes se recoupent.
On ne demande plus au modèle de décider qu'il se passe quelque chose — on lui
dit ce qui vient d'arriver.

Le **repos entre deux alertes** (15 minutes par défaut) n'est pas un confort.
Sur l'installation qui a servi à écrire ceci, une seule règle a tiré
**trente-huit fois dans la journée** : sans repos, autant de demandes facturées,
dont la plupart refusées par le verrou « une demande à la fois » puisqu'un tour
dure des dizaines de secondes. Une alerte qui se répète toutes les minutes n'est
pas une alerte, c'est un bruit qu'on finit par couper. Pendant le repos, les
basculements sont couverts par l'alerte précédente et ne ressortent pas à la
fin.

**Le garde-fou d'armement.** Le champ *N'alerter que si* désigne l'état qui dit
que la maison est armée — celui de votre alarme. Tant qu'il ne vaut pas « en
marche », l'alerte automatique ne part pas : une détection de caméra n'a de sens
que maison armée, et elle n'a pas à se payer en appels facturés le reste du
temps.

**Tout ce qui n'est pas un oui franc vaut non** : commande supprimée, valeur
vide, état jamais renseigné, plugin d'alarme pas encore installé. C'est voulu, et
c'est le sens même du champ — désigner ici une alarme qui n'existe pas encore
doit tout arrêter, jamais tout lancer. Le plugin écrit alors la raison dans son
journal.

Deux précisions :

- ce que la maison a fait pendant qu'elle était **désarmée ne ressort pas** au
  moment où on l'arme : le repère avance quand même. C'est la même règle que
  pour le repos ;
- l'état d'armement n'est **pas soumis au droit de lecture** de l'assistant.
  C'est le plugin qui lit sa propre configuration, pas le modèle qui consulte la
  maison : vous pouvez donc masquer l'alarme au modèle tout en vous en servant
  comme condition, et c'est même le réglage souhaitable.

Ce garde-fou ne vaut que pour l'alerte **automatique**. Une question que vous
posez vous-même — `check_alert`, `get_events` — reçoit sa réponse dans tous les
cas : on a le droit de demander si quelqu'un est passé cette nuit sans avoir
armé quoi que ce soit.

Cinq comportements valent d'être connus :

- **le premier passage n'alerte jamais** : il pose son repère et s'en va. Sans
  cela, cocher la case un matin ferait raconter la détection de l'avant-veille
  comme si elle venait d'arriver ;
- **le contrôle a lieu chaque minute.** Un *listener* du cœur serait immédiat,
  mais il s'exécuterait dans le processus qui vient de publier la valeur —
  celui du plugin de vidéosurveillance — et un tour de conversation dure dix à
  trente secondes : ce serait bloquer un démon d'alarme pour gagner une minute ;
- **la levée de doute se joue en arrière-plan.** Le contrôle de la minute ne
  fait que détecter ; la demande au modèle part aussitôt dans une tâche de fond
  du cœur, qui s'efface une fois jouée. Jeedom joue les tâches de la minute de
  tous les plugins l'une après l'autre : une levée de doute tenue là les ferait
  toutes attendre. Si cette tâche ne peut pas être lancée, l'alerte est jouée
  sur place plutôt que perdue, et le journal du plugin le dit ;
- une alerte qui trouve l'assistant **en plein tour** attend qu'il ait fini,
  deux minutes au plus, au lieu d'être refusée comme le serait une demande
  tapée à la main (voir *Une demande à la fois*) ;
- les demandes nées d'un déclenchement sont journalisées sous l'utilisateur
  **`alerte`**, jamais sous un nom d'humain.

Le plafond *Demandes par jour* prend ici tout son sens : c'est le filet sous une
mécanique qui, par construction, parle sans qu'on le lui demande.

## Spécialiser un assistant

Un équipement **est** un assistant : sa conversation, sa mémoire, son journal.
Plusieurs peuvent coexister, et le champ **Consignes de cet assistant**, dans
l'onglet *Équipement*, est ce qui les distingue vraiment.

Ce texte est ajouté à la fin des consignes envoyées au modèle, **après** celles
de la configuration du plugin, et prime donc sur elles. Les autres assistants ne
le voient pas : c'est là toute la différence avec les *Consignes
supplémentaires*, qui sont communes à toute l'installation.

Écrivez-y une **marche à suivre**, pas un portrait. Un assistant de levée de
doute n'a pas la même méthode qu'un assistant de maison : il commence par les
règles de détection croisée, se méfie d'une détection isolée, et conclut par un
verdict. Cela s'écrit en dix lignes, et cela change tout.

Deux limites à garder en tête :

- le texte repart chez OpenAI **à chaque demande de cet assistant**, et il est
  plafonné à 2000 caractères, coupés au dernier mot entier ;
- c'est une consigne au modèle, **jamais une barrière**. Écrire « tu ne pilotes
  rien » ne protège de rien ; ce qui protège est l'onglet *Autorisations*, et le
  mode global.

## Réglages du plugin

Dans *Configuration* (la clé à molette de la page du plugin).

| Réglage | Défaut | À quoi ça sert |
|---|---|---|
| Clé API OpenAI | vide | indispensable. Chiffrée en base, masquée dans les journaux, mais relisible depuis l'interface — voir ci-dessous |
| Modèle | `gpt-4o-mini` | le moins cher qui tienne correctement les outils |
| Délai d'attente | 60 s | par appel HTTP, **entre 5 et 300 secondes**. Une demande complexe en enchaîne plusieurs |
| Longueur maximale d'une réponse | 1200 jetons | au-delà, la réponse est coupée et la demande se termine en `LIMIT`. **`0` veut dire « pas de plafond »** : c'est le seul champ de la page où zéro est un choix et non une saisie de travers |
| Outils par demande | 15 | le garde-fou de coût, **30 au plus**. Plus bas, l'assistant conclut vite et mal — « je vais me coucher » en dépense douze à dix-huit ; plus haut, il fouille et la facture suit |
| Demandes par jour | 0 | le garde-fou d'emballement, **2000 au plus**. `0` veut dire « pas de plafond », comme pour la longueur de réponse. Voir *Le plafond du jour* |
| Mode de sécurité | `simulation` | `lecture`, `simulation` ou `actions`. Voir *Les trois modes globaux* |
| Lecture des états par défaut | oui | si non, chaque commande info doit être autorisée une par une |
| Échanges gardés en mémoire | 12 | la mémoire de la conversation, **50 tours au plus**. Pèse sur le coût de chaque demande |
| Rétention du journal | 30 jours | au-delà, les journaux sont supprimés par la tâche quotidienne. Un jour au minimum |
| Résumé de la maison | oui | évite deux ou trois appels d'outils au démarrage de chaque conversation, contre un peu de contexte à chaque demande |
| Ton | `kitt` | `kitt` pour le ton de la série, `sobre` pour un assistant neutre |
| Fiche de la maison | vide | sept champs facultatifs sur la maison vécue — qui y vit, animaux, logement, chauffage, habitudes, à ne jamais faire, véhicule électrique. Seuls les champs remplis partent. Voir *La fiche de la maison* |
| Consignes supplémentaires | vide | vos propres instructions, ajoutées à l'invite système **après** la fiche et les consignes du plugin. « Ne jamais allumer le salon après 23 h » y a sa place — en sachant que c'est une consigne au modèle, pas une interdiction : celle-là se règle dans *Autorisations* |

### Ce qui arrive à une valeur hors bornes

Trois de ces champs sont bornés des deux côtés : *Délai d'attente*, *Outils par
demande* et *Échanges gardés en mémoire*. La règle est la même pour les trois,
et elle n'est pas silencieuse :

- une valeur au-dessus du plafond est ramenée au plafond ; en dessous du
  plancher, au plancher — et **le journal du plugin (`k2000be`) écrit la ligne
  qui le dit** ;
- un champ vide reprend la valeur livrée, sans bruit : il n'y avait rien à
  retenir ;
- un zéro ou un nombre négatif la reprennent aussi, et le journal le note pour
  *Outils par demande* et *Échanges gardés en mémoire*.

*Longueur maximale d'une réponse* et *Demandes par jour* ont leur convention
propre, écrite à côté d'eux : zéro y est une valeur — « pas de plafond » — et
non une saisie de travers. Un nombre trop grand y est tout de même ramené à la
borne, et le journal le dit comme pour les autres.

C'est le point : un champ ne doit pas laisser croire qu'on a réglé quelque
chose. Un délai à 1 seconde devenait 60 sans que rien ne le dise, et l'on
cherchait ensuite pourquoi le réglage restait sans effet. Si ce que vous avez
tapé n'a pas été retenu, c'est écrit dans le journal.

*Longueur maximale d'une réponse* garde une convention à elle : `0` y veut dire
« pas de plafond », et le paramètre n'est alors pas envoyé du tout. Elle n'a pas
de plafond haut — c'est le modèle, et votre facture, qui décident.

La mémoire, enfin, est coupée plus tôt que les cinquante tours si elle dépasse
cent vingt messages ou soixante kilo-octets. Un seul tour très bavard peut y
suffire : trente outils font soixante et un messages, et une lecture
d'historique pèse à elle seule des dizaines de kilo-octets. Sans ces deux
plafonds, toute la conversation repartait entière à chaque demande, droit vers
le refus « contexte trop long » — lequel s'enregistrait, se rejouait, et ne se
guérissait qu'avec *Nouvelle conversation*.

Deux réglages sont à part, dans le bloc **Avancé** en fin de page : on n'en a pas
besoin pour se servir du plugin, et les changer au jugé dégrade l'assistant.

| Réglage | Défaut | À quoi ça sert |
|---|---|---|
| Adresse de l'API | `https://api.openai.com/v1` | à changer seulement pour une API compatible ou un relais local. La clé part vers l'adresse indiquée ici, et vers elle seule |
| Température | 0.3 | 0 rend les réponses reproductibles. Au-delà de 0.7, l'assistant se met à inventer des équipements qui n'existent pas |

### Où vit la clé API, et qui peut la lire

Autant le dire sans détour, c'est le seul secret que ce plugin détient.

**Ce qui est protégé.** La clé est chiffrée par le cœur de Jeedom dans la base
de données, donc aussi dans les sauvegardes. Elle est masquée dans tout ce que
le plugin journalise : ni le journal `k2000be`, ni l'onglet *Historique*, ni un
message d'erreur d'OpenAI recopié dans une page ne la laissent passer en clair.

**Ce qui ne l'est pas.** La fenêtre de configuration relit les réglages du
plugin à chaque ouverture et repose la clé dans son champ. Le champ est de type
mot de passe, donc affiché en points — mais la valeur est bien là, et
l'inspecteur du navigateur la montre en clair à qui sait l'ouvrir. Surtout, la
requête qui rend cette configuration n'est pas réservée aux administrateurs :
le cœur de Jeedom se contente d'un compte connecté. **Tout compte Jeedom, même
sans profil administrateur, peut donc lire la clé.**

Deux conséquences pratiques. Un compte sur votre Jeedom vaut un accès à votre
clé OpenAI : créez-en avec la même parcimonie. Et si la clé a pu sortir de chez
vous, la révoquer sur `platform.openai.com` est le seul geste qui compte — la
changer ici ne fait rien contre la copie déjà partie.

## Quand ça ne marche pas

- **« Clé API refusée par OpenAI »** : la clé est fausse, révoquée, ou copiée
  avec un espace au bout. Recollez-la, puis cliquez *Enregistrer et tester* : le
  bouton enregistre avant d'interroger OpenAI, c'est donc bien la clé que vous
  venez de coller qui est jugée. Vérifiez aussi que la clé appartient bien à
  l'organisation qui a le crédit.
- **« Quota ou débit dépassé »** : le compte OpenAI n'a plus de crédit, ou vous
  avez enchaîné trop de demandes trop vite. Le premier cas se règle sur
  `platform.openai.com`, section *Settings → Billing* : *Add to credit balance*,
  et la première demande suivante repart. Le second se règle en attendant une
  minute — le plugin relance une fois de lui-même avant d'abandonner.
- **« OpenAI indisponible » ou « pas de réponse en N secondes »** : panne du
  côté d'OpenAI, ou réponse plus lente que le délai d'attente. Si cela se
  reproduit sur des demandes complexes, augmentez le délai d'attente.
- **L'assistant dit ne pas trouver un équipement qui existe** : il est masqué,
  ou désactivé dans Jeedom — dans les deux cas l'assistant en ignore jusqu'à
  l'existence. Ouvrez l'onglet *Autorisations* et cherchez-le : s'il n'y figure
  pas comme masqué, regardez son état dans Jeedom même.
- **L'assistant trouve l'équipement mais refuse d'agir** : la commande est
  restée interdite, ou le plugin est en `lecture` — dans ce second cas, aucune
  commande ne peut partir, quelles que soient les autorisations, et le refus le
  dit. Une commande interdite, elle, reste visible du modèle : c'est ce qui lui
  permet de vous expliquer le refus plutôt que de prétendre ne rien avoir
  trouvé. Les compteurs en tête de l'onglet *Autorisations* disent en un coup
  d'œil combien de commandes sont autorisées.
- **L'assistant annonce avoir agi, mais rien ne bouge** : commencez par le mode
  de sécurité. En `simulation`, rien n'est jamais envoyé : le plugin demande au
  modèle de présenter l'action comme simulée, mais il lui arrive de l'oublier et
  de raconter au passé ce qu'il n'a pas fait. En `actions`, l'ordre est parti
  mais l'équipement ne l'a pas suivi :
  actionnez la même commande à la main depuis Jeedom ; si elle échoue aussi, le
  problème n'est pas ici.
- **L'assistant annonce un échec alors que la commande a bien été exécutée** :
  il a relu l'état avant que l'équipement n'ait eu le temps de le remonter.
  `get_states` sait attendre pour cela, et l'invite système demande au modèle
  de s'en servir, mais l'attente est bornée à dix secondes par lecture et à
  quinze par demande : un équipement plus lent que cela reste muet le temps du
  tour. Reposez la question un instant plus tard, la valeur sera à jour. Voir
  *Envoyée n'est pas aboutie*.
- **L'assistant demande de quelle lumière il s'agit** : plusieurs équipements
  visibles portent un nom qui correspond, et il préfère demander plutôt que de
  tirer au sort. Nommez la pièce dans votre phrase, ou renommez les équipements
  dans Jeedom. Voir *Quand plusieurs équipements peuvent correspondre*.
- **« Une demande est déjà en cours pour cet assistant »** : un autre onglet, un
  scénario ou le widget parle en ce moment même à cet assistant. La seconde
  demande est refusée, jamais mise en attente : rien n'a été exécuté, et il
  suffit d'attendre la réponse en cours. Voir *Une demande à la fois*.
- **La réponse s'arrête au milieu d'une phrase** : le plafond de *Longueur
  maximale d'une réponse* a été atteint. La réponse le dit, et le statut vaut
  `LIMIT`. Augmentez le plafond, ou posez une question plus étroite.
- **« Temps imparti dépassé »** : le tour a consommé son budget de temps avant
  d'aboutir, et s'est arrêté de lui-même pour ne pas être coupé par PHP au
  milieu d'une action. Ce qui avait déjà été exécuté l'a bien été, et les étapes
  le disent. Une demande qui en demande moins à la fois passe.
- **Le journal s'arrête sur une ligne « journal écourté »** : la lecture du
  journal a un budget, et s'interrompt avant d'avoir à relire tout le dossier.
  La ligne dit jusqu'à quelle date la recherche est allée. Les demandes plus
  anciennes sont bien enregistrées : ce n'est pas une perte, c'est une lecture
  partielle.
- **L'assistant ne tient pas compte de ce que j'ai écrit dans la fiche** :
  commencez par le bouton *Voir ce qui part à chaque demande*, qui dit si le
  texte est bien dans l'invite système — un champ mal enregistré s'y voit tout
  de suite. S'il y est, c'est le modèle qui ne l'a pas suivi : une fiche est
  une consigne, pas une règle appliquée par le plugin. Une fiche plus courte et
  plus concrète est mieux suivie qu'un paragraphe long. Et si l'enjeu est
  qu'une commande ne parte jamais, c'est l'onglet *Autorisations* qu'il faut,
  pas la fiche.
- **La confirmation ne fait rien** : elle a probablement expiré. Au-delà de cinq
  minutes, il faut reformuler la demande. Elle peut aussi avoir déjà été traitée
  — par un autre onglet, ou par un scénario branché sur *Confirmer*.
- **Où regarder** : le journal du plugin (*Réglages → Système → Journaux*, ligne
  `k2000be`) porte les appels à l'API, les refus et les erreurs. L'onglet
  *Historique* d'un assistant porte, pour chaque demande, le détail des outils
  appelés, leur statut et leur motif — c'est en général plus parlant que le
  journal. Si une page d'interface se rafraîchit en perdant votre saisie,
  regardez `/var/www/html/log/http.error` avant toute autre hypothèse : le
  journal du plugin, lui, reste muet dans ce cas-là.

## Ce que le modèle ne garantit pas

Un modèle de langage se trompe. Il peut confondre deux équipements aux noms
voisins, comprendre « ferme les volets » comme « ferme tous les volets », ou
affirmer avec aplomb une chose qu'il n'a pas vérifiée. Ce n'est pas un défaut de
réglage, c'est la nature de l'outil. Les consignes décrites plus haut — poser la
question quand plusieurs équipements correspondent, attendre avant de vérifier,
ne pas présenter une moyenne comme une mesure, respecter ce que vous avez écrit
dans *À ne jamais faire* — réduisent ces écarts sans les supprimer : ce sont des instructions données au modèle, pas des garanties tenues
par le plugin.

C'est exactement pourquoi ce plugin est construit ainsi : les autorisations sont
étroites par défaut, le plugin reste seul juge de ce qui s'exécute, les
commandes qui engagent la sécurité du logement demandent une confirmation
humaine, et le mode `simulation` permet d'observer les décisions avant de les
subir. Autorisez donc au compte-gouttes, et gardez la confirmation partout où
une erreur coûterait plus qu'un clic de plus.
