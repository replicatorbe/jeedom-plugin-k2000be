# K2000 — plugin Jeedom

Un assistant en langage naturel pour la maison, adossé à l'API OpenAI et à son
mécanisme d'outils. Vous dites « je vais me coucher » ; l'assistant consulte
l'état des pièces, décide, exécute les commandes qu'on lui a autorisées et
raconte ce qu'il a fait. Le plugin ne lui annonce jamais qu'une commande a
abouti, seulement qu'elle est partie : c'est à lui de relire l'état avant de
l'affirmer — et l'outil de lecture sait attendre quelques secondes que
l'équipement ait parlé, faute de quoi il relit la valeur d'avant la commande et
annonce un échec qui n'a pas eu lieu.

Plugin privé : il n'est pas destiné au Market.

- **Le modèle demande, le plugin décide.** Le modèle ne reçoit aucun accès à
  l'installation : il appelle des outils, et le plugin exécute, refuse, ou
  demande confirmation.
- **Toute commande d'action est interdite par défaut**, y compris celles créées
  après l'installation. Trois politiques par commande — interdite, autorisée,
  autorisée avec confirmation — stockées dans la configuration de la commande
  Jeedom, donc indépendantes du plugin.
- **Trois modes globaux** : `lecture`, `simulation` (tout est joué et
  journalisé, rien n'est envoyé à la domotique) et `actions`. Le mode est
  annoncé au modèle, et en lecture seule les outils n'annoncent plus aucune
  action exécutable.
- Les commandes réglées sur *Confirmation* attendent un accord humain, caduc au
  bout de cinq minutes ; les contrôles sont rejoués au moment du clic. Les
  commandes qui engagent la sécurité du logement — serrure, portail, alarme,
  sirène — sont signalées comme telles dans l'interface, qui recommande ce
  réglage sans l'imposer.
- **Un tour de conversation à la fois par assistant.** Une seconde demande
  pendant qu'un tour est en cours est refusée sur-le-champ, et non mise en
  file : le verrou est tenu pendant des appels réseau de plusieurs dizaines de
  secondes.
- **Les appels indépendants partent ensemble.** Plusieurs outils demandés dans
  le même message s'exécutent en un seul aller-retour. Le plafond par défaut
  est de quinze outils pour une demande, trente au plus : « je vais me
  coucher », sur une maison d'une soixantaine d'équipements, en dépense douze à
  dix-huit.
- Quand plusieurs équipements peuvent correspondre — trois « Lumière » dans
  trois pièces —, l'assistant les nomme et demande lequel au lieu de trancher.
  C'est une consigne donnée au modèle, pas une garantie du plugin.
- Trois plafonds peuvent arrêter un tour — le nombre d'outils, un budget de
  temps calé sur `max_execution_time`, une réponse coupée par sa longueur
  maximale — et les trois se traduisent par le statut `LIMIT`.
- **Une fiche de la maison** en sept champs facultatifs — qui vit ici, animaux,
  logement, chauffage, habitudes, à ne jamais faire, véhicule électrique — pour
  ce que l'installation ne dit pas. Seuls les champs remplis partent, et le
  modèle est prévenu qu'un outil qui le contredit a raison. On n'y renseigne
  que ce qui change une décision : la fiche repart chez OpenAI à chaque
  demande. *À ne jamais faire* est une consigne au modèle, jamais une barrière
  — la barrière, ce sont les autorisations. Un bouton affiche l'invite système
  complète, telle qu'elle part.
- Utilisable depuis la page du plugin, depuis un widget, et depuis un scénario.

- **Il sait ce qui s'est passé**, et pas seulement ce qui est. Un outil rend en
  un appel les états événementiels de la maison qui ont basculé ces dernières
  heures — détections, ouvertures, fuites —, et chaque état événementiel porte
  la date de son dernier changement. Sans elle, une détection restée à 1 faute
  d'événement de fin se lisait au présent trois semaines plus tard. Le critère
  est ce que vous historisez, pas le nom d'un plugin.
- **Le coût se voit, et peut s'arrêter.** Le journal totalise ce que les
  demandes affichées ont coûté, une commande d'information historisée en trace
  la courbe, et le réglage *Demandes par jour* refuse au-delà d'un plafond,
  avant tout appel réseau — le garde-fou du scénario qui s'emballe, le plafond
  d'OpenAI étant mensuel et libellé en dollars.
- **Une page Santé** dit en un coup d'œil ce qui manque, et signale les
  commandes qui ouvrent, déverrouillent ou désarment sans demander.

Une clé API OpenAI est indispensable, et chaque demande est facturée par
OpenAI. Elle est chiffrée en base et masquée dans les journaux, mais reste
lisible depuis l'interface de Jeedom par tout compte connecté : la
documentation détaille ce point.

Un compte Jeedom non administrateur ne peut pas ouvrir la page du plugin, mais
peut actionner les commandes *Confirmer* et *Annuler* d'un assistant visible
sur son tableau de bord. Ces deux commandes ne portent aucun jeton : elles
répondent à la dernière confirmation posée, d'où qu'elle vienne — un scénario
branché sur *Confirmer* approuve donc ce que l'assistant demandera, sans le
lire. La documentation y revient.

La documentation est dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # rejeu hors ligne, sans réseau ni Jeedom
php tests/check-classes.php  # contrôles par réflexion contre le cœur installé
```
