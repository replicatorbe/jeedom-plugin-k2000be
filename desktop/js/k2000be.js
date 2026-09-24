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

/* ================================================================ CONSTANTES */

/* Délai d'un appel ordinaire : lire l'arbre ou le journal ne sort pas de la
   box, vingt secondes sont déjà une éternité. */
var K2000BE_DELAI_COURT = 20000

/*
 * Délai d'une demande. Une demande n'est pas UN appel au modèle mais une
 * boucle : le plugin envoie la question, exécute les outils demandés, renvoie
 * les résultats, recommence — jusqu'à douze allers-retours, chacun avec le
 * délai réglé dans la configuration (soixante secondes par défaut). Le délai
 * par défaut d'une requête AJAX transformerait une demande qui aboutit en
 * erreur réseau incompréhensible.
 */
var K2000BE_DELAI_LONG = 900000

/* Au-delà, on cesse de laisser croire que c'est rapide et on explique. */
var K2000BE_ATTENTE_LONGUE = 15

/* La recherche est retardée : sur une installation qui compte des milliers de
   commandes, reconstruire l'arbre à chaque touche rendrait la saisie poussive. */
var K2000BE_DELAI_RECHERCHE = 250

/*
 * Longueur minimale d'une recherche.
 *
 * Une seule lettre correspond à peu près partout : l'arbre déplie alors toutes
 * les pièces et fabrique d'un coup le millier et demi de lignes que la
 * construction paresseuse existe précisément pour éviter — et recommence à la
 * frappe suivante. Deux caractères suffisent à ramener le résultat à une
 * taille que le navigateur construit sans broncher.
 */
var K2000BE_RECHERCHE_MIN = 2

/* Au-delà de ce nombre de pièces trouvées, un filtre cesse de déplier d'office :
   déplier six pièces, c'est déjà construire tout ce qu'on voulait éviter. */
var K2000BE_DEPLIAGE_MAX = 4

/* ==================================================================== ÉTAT */

/* L'arbre des autorisations vaut pour toute l'installation, pas pour un
   assistant : il est chargé une fois et survit au passage d'un assistant à
   l'autre. Le bouton « Recharger » est là pour le cas où il a vieilli. */
var k2000beArbreData = null
var k2000beArbreCharge = false

/* Une seule demande à la fois : deux boucles concurrentes sur la même
   conversation écriraient l'une par-dessus l'autre. */
var k2000beEnCours = false
var k2000beMinuteur = null
var k2000beDebut = 0

/* La requête en cours, gardée pour pouvoir l'abandonner : sans elle, cesser
   d'attendre voudrait dire recharger la page. */
var k2000beRequete = null

/* La confirmation en attente telle que le serveur l'a décrite. Le jeton qu'elle
   porte est la seule chose qui rattache la réponse à la conversation suspendue. */
var k2000beAttente = null

/* L'horloge du décompte de la confirmation. Elle vit tant que le bandeau vit. */
var k2000beAttenteMinuteur = null

/* La dernière demande écrite par l'utilisateur, pour que le bandeau de
   confirmation puisse dire à la suite de quoi il s'est ouvert. */
var k2000beDerniereDemande = ''

/* Le jour de la dernière bulle affichée : la date n'est écrite que lorsqu'elle
   change, sinon elle se répète à chaque tour sans rien apprendre. */
var k2000beDernierJour = ''

var k2000beRechercheTimer = null

/* Les pièces que l'utilisateur a ouvertes, par nom. Une recherche effacée doit
   rendre l'écran tel qu'il était, pas tout refermer.

   Les clés sont préfixées : un nom de pièce est saisi par l'utilisateur, et
   rien n'empêche d'en baptiser une « constructor » ou « __proto__ ». */
var k2000bePiecesOuvertes = {}

function k2000bePieceCle(_nom) {
  return 'piece:' + String(init(_nom, ''))
}

/* Les deux filtres de l'onglet Autorisations. Ils ne sont pas enregistrés :
   ils servent le temps d'un réglage, pas d'une installation. */
var k2000beFiltreActions = false
var k2000beFiltreAutorisees = false

/* Les lignes du journal telles que le serveur les a rendues. Le filtre par
   statut travaille dessus sans redemander : la donnée est déjà là. */
var k2000beJournalLignes = []

/* Le sort d'une commande info jamais réglée. Il vient de toAjax() ; en son
   absence on suppose la valeur par défaut du plugin, la plus permissive, pour
   ne pas afficher « masquée » sur une installation qui ne l'est pas. */
var k2000beLectureDefaut = true

/* Le mode de sécurité tel que le serveur l'a rendu. Il est retenu parce que
   deux affichages en dépendent en dehors du moment où il arrive : le bandeau
   « rien n'est autorisé » et les compteurs d'autorisations. Vide tant que le
   serveur n'a pas répondu — on ne suppose alors aucun mode. */
var k2000beMode = ''

/* ================================================================== OUTILS */

function k2000beEl(_id) {
  return document.getElementById(_id)
}

function k2000beCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

/*
 * Appel AJAX du plugin.
 *
 * _options accepte 'timeout' et 'failure'. Le rappel d'échec sert à rendre la
 * main à l'écran (rallumer un bouton, éteindre le balayage) : sans lui, une
 * erreur laisserait la page figée sur « en cours ».
 *
 * Rend la requête : c'est la seule prise qui permette ensuite de l'abandonner.
 */
function k2000beAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }

  return $.ajax({
    type: 'POST',
    url: 'plugins/k2000be/core/ajax/k2000be.ajax.php',
    data: payload,
    dataType: 'json',
    timeout: isset(options.timeout) ? options.timeout : K2000BE_DELAI_COURT,
    error: function (_request, _status, _error) {
      /* Abandon volontaire : celui qui a cliqué sait déjà ce qui se passe, et
         l'écran lui a déjà répondu. Rien à signaler, rien à défaire. */
      if (_status === 'abort') { return }
      if (typeof options.failure === 'function') { options.failure() }
      if (_status === 'timeout') {
        /* Le message du cœur parlerait d'erreur réseau : c'en est une, mais la
           cause est ici toujours la même, et la dire épargne une enquête. */
        jeedomUtils.showAlert({
          message: '{{L\'assistant n\'a pas répondu dans le temps imparti. La demande peut malgré tout s\'être exécutée : ouvrez l\'historique avant de la relancer.}}',
          level: 'danger'
        })
        return
      }
      domUtils.handleAjaxError(_request, _status, _error)
    },
    success: function (_data) {
      if (_data.state != 'ok') {
        if (typeof options.failure === 'function') { options.failure() }
        jeedomUtils.showAlert({ message: _data.result, level: 'danger' })
        return
      }
      if (typeof _success === 'function') { _success(init(_data.result, null)) }
    }
  })
}

/* Les dates du plugin sont des horodatages en secondes ; Date en veut en
   millisecondes, et l'oublier affiche 1970. */
function k2000beDate(_timestamp) {
  var valeur = parseInt(_timestamp, 10)
  if (isNaN(valeur) || valeur <= 0) { return '' }
  return new Date(valeur * 1000).toLocaleString()
}

function k2000beHeure(_timestamp) {
  var valeur = parseInt(_timestamp, 10)
  if (isNaN(valeur) || valeur <= 0) { return '' }
  return new Date(valeur * 1000).toLocaleTimeString()
}

/* La date seule, sans l'heure : c'est ce qu'on écrit entre deux bulles quand le
   jour a changé. */
function k2000beJourTexte(_timestamp) {
  var valeur = parseInt(_timestamp, 10)
  if (isNaN(valeur) || valeur <= 0) { return '' }
  return new Date(valeur * 1000).toLocaleDateString()
}

function k2000beMaintenant() {
  return Math.floor(Date.now() / 1000)
}

/* Un décompte se lit en minutes et en secondes, pas en secondes seules : « 252 s »
   demande un calcul mental pour une décision qui doit être immédiate. */
function k2000beDureeTexte(_secondes) {
  var restant = Math.max(0, parseInt(_secondes, 10) || 0)
  var minutes = Math.floor(restant / 60)
  var secondes = restant % 60
  if (minutes === 0) { return secondes + ' {{s}}' }
  return minutes + ' {{min}} ' + (secondes < 10 ? '0' : '') + secondes + ' {{s}}'
}

/* Texte, jamais balisage : ces chaînes viennent du modèle, donc d'Internet, et
   un nom d'équipement est saisi par l'utilisateur. */
function k2000beTexte(_parent, _classe, _valeur) {
  var span = document.createElement('span')
  if (_classe !== '') { span.className = _classe }
  span.textContent = String(init(_valeur, ''))
  _parent.appendChild(span)
  return span
}

function k2000beBadge(_parent, _texte, _niveau, _titre) {
  var badge = document.createElement('span')
  badge.className = 'label label-' + _niveau
  badge.textContent = _texte
  if (isset(_titre)) { badge.title = _titre }
  _parent.appendChild(badge)
  _parent.appendChild(document.createTextNode(' '))
  return badge
}

/* ======================================================= STATUTS ET ÉTAPES */

/* Le mot est toujours écrit à côté de la couleur : l'écran doit rester lisible
   par quelqu'un qui ne distingue pas le vert du rouge.

   « TRONQUE » n'est pas un statut de demande : c'est la ligne de service que le
   journal ajoute quand il a été parcouru jusqu'à son budget de lecture. Sans
   libellé, elle s'affichait telle quelle, en majuscules et en gris — donc comme
   une demande au statut inconnu, alors qu'elle dit seulement où la recherche
   s'est arrêtée. Rien n'a échoué : le ton est informatif, jamais alarmant. */
function k2000beStatutLabel(_statut) {
  if (_statut === 'SUCCESS') { return '{{fait}}' }
  if (_statut === 'CONFIRMATION') { return '{{confirmation demandée}}' }
  if (_statut === 'REFUSED') { return '{{refusé}}' }
  if (_statut === 'LIMIT') { return '{{limite atteinte}}' }
  if (_statut === 'ERROR') { return '{{erreur}}' }
  if (_statut === 'TRONQUE') { return '{{journal écourté}}' }
  return String(init(_statut, ''))
}

function k2000beStatutNiveau(_statut) {
  if (_statut === 'SUCCESS') { return 'success' }
  if (_statut === 'CONFIRMATION') { return 'warning' }
  if (_statut === 'REFUSED') { return 'danger' }
  if (_statut === 'LIMIT') { return 'warning' }
  if (_statut === 'ERROR') { return 'danger' }
  if (_statut === 'TRONQUE') { return 'info' }
  return 'default'
}

function k2000beEtapeIcone(_statut) {
  if (_statut === 'ok') { return 'fas fa-check' }
  if (_statut === 'refus') { return 'fas fa-ban' }
  if (_statut === 'confirmation') { return 'fas fa-question-circle' }
  if (_statut === 'simule') { return 'fas fa-flask' }
  if (_statut === 'erreur') { return 'fas fa-exclamation-triangle' }
  return 'fas fa-circle'
}

function k2000beEtapeCouleur(_statut) {
  if (_statut === 'ok') { return 'var(--al-success-color)' }
  if (_statut === 'refus') { return 'var(--al-danger-color)' }
  if (_statut === 'confirmation') { return 'var(--al-warning-color)' }
  if (_statut === 'simule') { return 'var(--al-info-color)' }
  if (_statut === 'erreur') { return 'var(--al-danger-color)' }
  return 'var(--txt-color)'
}

function k2000beEtapeMot(_statut) {
  if (_statut === 'ok') { return '{{exécuté}}' }
  if (_statut === 'refus') { return '{{refusé}}' }
  if (_statut === 'confirmation') { return '{{en attente}}' }
  if (_statut === 'simule') { return '{{simulé}}' }
  if (_statut === 'erreur') { return '{{erreur}}' }
  return String(init(_statut, ''))
}

/*
 * Une ligne par outil appelé. C'est le seul endroit où l'on voit ce que
 * l'assistant a réellement tenté : une réponse qui dit « c'est fait » et une
 * étape qui dit « refusée » ne racontent pas la même histoire, et c'est
 * l'étape qui a raison.
 *
 * Elles sont repliées derrière une puce quand le tour a réussi : huit lignes
 * d'outils enterrent la phrase qu'on venait lire, et une conversation de trente
 * échanges devient un mur. Quand le statut n'est PAS « fait », elles s'ouvrent
 * d'office : c'est le cas — refus, limite, erreur — où elles sont la réponse.
 */
function k2000beEtapesNode(_etapes, _statut) {
  if (!is_array(_etapes) || _etapes.length === 0) { return null }

  var box = document.createElement('div')
  box.className = 'k2000beEtapes'

  /* Ouvert d'office dès que le tour n'a pas simplement abouti. Un statut absent
     — le cas de la frise enregistrée — vaut « fait » : on ne déplie pas trente
     échanges au rechargement d'une conversation. */
  var statut = String(init(_statut, 'SUCCESS'))
  var ouvert = (statut !== '' && statut !== 'SUCCESS')

  var anormales = 0
  for (var a = 0; a < _etapes.length; a++) {
    var mot = String(init(_etapes[a].statut, ''))
    if (mot === 'refus' || mot === 'erreur') { anormales++ }
  }

  var resume = document.createElement('a')
  resume.className = 'k2000beEtapesResume'
  resume.setAttribute('role', 'button')
  resume.setAttribute('tabindex', '0')
  resume.setAttribute('aria-expanded', ouvert ? 'true' : 'false')
  resume.title = '{{Ce que l\'assistant a réellement tenté, outil par outil.}}'

  var caret = document.createElement('i')
  caret.className = ouvert ? 'fas fa-angle-down' : 'fas fa-angle-right'
  resume.appendChild(caret)
  resume.appendChild(document.createTextNode(' ' + _etapes.length + ' '
    + (_etapes.length > 1 ? '{{étapes}}' : '{{étape}}')))
  /* Le nombre seul ne dit pas s'il faut ouvrir : un refus caché derrière une
     puce refermée serait exactement l'information qu'on cherchait. */
  if (anormales > 0) {
    resume.appendChild(document.createTextNode(' — '))
    k2000beTexte(resume, 'k2000beEtapesAlerte', anormales + ' {{sans effet}}')
  }
  box.appendChild(resume)

  /* Nommé « contenu » et non « corps » : la boucle ci-dessous emploie déjà
     « corps » pour le corps d'une ÉTAPE, et var ne connaît pas le bloc — les
     deux ne feraient qu'une seule variable, et les lignes s'empileraient les
     unes dans les autres au lieu de se ranger ici. */
  var contenu = document.createElement('div')
  contenu.className = 'k2000beEtapesCorps'
  contenu.style.display = ouvert ? '' : 'none'
  box.appendChild(contenu)

  for (var i = 0; i < _etapes.length; i++) {
    var etape = _etapes[i]
    var ligne = document.createElement('div')
    ligne.className = 'k2000beEtape'

    var icone = document.createElement('i')
    icone.className = k2000beEtapeIcone(etape.statut) + ' k2000beEtapeIcone'
    icone.style.color = k2000beEtapeCouleur(etape.statut)
    icone.title = k2000beEtapeMot(etape.statut)
    ligne.appendChild(icone)

    var corps = document.createElement('span')
    k2000beTexte(corps, 'k2000beEtapeTitre', init(etape.titre, ''))
    corps.appendChild(document.createTextNode(' '))
    k2000beTexte(corps, 'k2000beEtapeMot', '(' + k2000beEtapeMot(etape.statut) + ')')
    if (String(init(etape.detail, '')) !== '') {
      corps.appendChild(document.createElement('br'))
      k2000beTexte(corps, 'k2000beEtapeDetail', etape.detail)
    }
    ligne.appendChild(corps)

    /* Le nom de l'outil est technique : il sert au dépannage, pas à la lecture
       courante — il reste donc discret, en fin de ligne. */
    if (String(init(etape.outil, '')) !== '') {
      k2000beTexte(ligne, 'k2000beEtapeOutil', etape.outil)
    }

    contenu.appendChild(ligne)
  }

  return box
}

/* Ouvre ou referme une liste d'étapes. Le nombre reste visible : c'est lui qui
   dit qu'il y a quelque chose à ouvrir. */
function k2000beBasculerEtapes(_resume) {
  var box = _resume.parentNode
  if (box === null) { return }
  var corps = box.querySelector('.k2000beEtapesCorps')
  if (corps === null) { return }
  var ouvert = (corps.style.display === 'none')
  corps.style.display = ouvert ? '' : 'none'
  _resume.setAttribute('aria-expanded', ouvert ? 'true' : 'false')
  var caret = _resume.querySelector('i')
  if (caret !== null) { caret.className = ouvert ? 'fas fa-angle-down' : 'fas fa-angle-right' }
}

/* ============================================================== DISCUSSION */

function k2000beScanner(_actif) {
  var scanner = k2000beEl('div_k2000beScanner')
  if (scanner === null) { return }
  if (_actif) {
    scanner.classList.add('k2000beScanOn')
    return
  }
  scanner.classList.remove('k2000beScanOn')
}

/*
 * Ce qui se passe pendant l'attente.
 *
 * Une demande peut durer une minute, et une page qui ne dit rien pendant une
 * minute est une page en panne, du point de vue de celui qui la regarde. Le
 * compteur monte, et passé quelques secondes il explique pourquoi c'est long.
 */
/*
 * Le champ et le bouton pendant le travail.
 *
 * Un bouton vert et un champ actif invitent à recommencer ; recommencer valait
 * jusqu'ici une alerte rouge qui reprochait le geste que l'écran venait de
 * proposer. Éteindre les deux dit la même chose, sans reproche et avant le clic.
 */
function k2000beVerrouillerSaisie(_verrou) {
  var bouton = k2000beEl('bt_k2000beAsk')
  if (bouton !== null) {
    bouton.classList.toggle('disabled', _verrou)
    bouton.setAttribute('aria-disabled', _verrou ? 'true' : 'false')
    bouton.title = _verrou ? '{{Une demande est en cours : attendez sa réponse, ou cessez d\'attendre.}}' : ''
  }
  var champ = k2000beEl('in_k2000beDemande')
  if (champ !== null) {
    /* L'exemple d'origine est mis de côté plutôt que réécrit ici : deux copies
       d'une même phrase finissent toujours par diverger. Et il n'est rendu que
       s'il a été mis de côté — sans cette garde, le déverrouillage du premier
       chargement, qui n'a rien verrouillé, effacerait l'exemple de la page. */
    if (_verrou) {
      if (!champ.hasAttribute('data-placeholder')) {
        champ.setAttribute('data-placeholder', champ.placeholder)
      }
      champ.placeholder = '{{Demande en cours…}}'
    } else if (champ.hasAttribute('data-placeholder')) {
      champ.placeholder = champ.getAttribute('data-placeholder')
    }
    champ.readOnly = _verrou
  }
  var abandon = k2000beEl('bt_k2000beAbandonner')
  if (abandon !== null) { abandon.style.display = _verrou ? '' : 'none' }
}

/*
 * Cesser d'attendre.
 *
 * Le délai du navigateur est de quinze minutes : sans ce bouton, une demande
 * partie de travers immobilise l'écran tout ce temps. Abandonner la requête
 * n'arrête pas la demande — elle tourne dans la box, et son résultat ira dans
 * le journal comme tous les autres. Le dire est la seule façon de ne pas
 * laisser croire qu'on vient d'annuler quelque chose.
 */
function k2000beAbandonner() {
  if (!k2000beEnCours) { return }
  if (k2000beRequete !== null && typeof k2000beRequete.abort === 'function') {
    k2000beRequete.abort()
  }
  k2000beRequete = null
  k2000beTravailArreter('{{Vous n\'attendez plus la réponse. La demande, elle, continue dans la box : son résultat apparaîtra dans l\'Historique.}}')
}

/*
 * La phrase et le compteur sont deux éléments, et c'est voulu.
 *
 * La phrase est la zone annoncée au lecteur d'écran : sans elle, la page reste
 * muette une minute entière pour qui ne la voit pas. Le compteur, lui, change
 * chaque seconde ; annoncé, il couvrirait tout le reste de la page. Il est donc
 * écrit à côté, hors du champ de l'annonce.
 */
function k2000beTravailDemarrer() {
  k2000beVerrouillerSaisie(true)
  var zone = k2000beEl('span_k2000beTravail')
  if (zone === null) { return }
  k2000beDebut = Date.now()
  zone.textContent = '{{L\'assistant réfléchit…}}'

  var compteur = k2000beEl('span_k2000beTravailCompteur')
  if (compteur !== null) { compteur.textContent = '' }

  if (k2000beMinuteur !== null) { clearInterval(k2000beMinuteur) }
  k2000beMinuteur = setInterval(function () {
    var zoneCourante = k2000beEl('span_k2000beTravailCompteur')
    /* L'écran a changé : on s'arrête plutôt que d'écrire dans une page qui
       parle d'autre chose. */
    if (zoneCourante === null) {
      clearInterval(k2000beMinuteur)
      k2000beMinuteur = null
      return
    }
    var secondes = Math.floor((Date.now() - k2000beDebut) / 1000)
    var texte = ' ' + secondes + ' {{s}}'
    if (secondes >= K2000BE_ATTENTE_LONGUE) {
      texte += ' — {{il consulte l\'état de la maison, décide, exécute, puis vérifie : une minute est normale.}}'
    }
    zoneCourante.textContent = texte
  }, 1000)
}

function k2000beTravailArreter(_message) {
  if (k2000beMinuteur !== null) {
    clearInterval(k2000beMinuteur)
    k2000beMinuteur = null
  }
  var zone = k2000beEl('span_k2000beTravail')
  if (zone !== null) { zone.textContent = isset(_message) ? _message : '' }
  var compteur = k2000beEl('span_k2000beTravailCompteur')
  if (compteur !== null) { compteur.textContent = '' }
  k2000beScanner(false)
  k2000beEnCours = false
  k2000beRequete = null
  k2000beVerrouillerSaisie(false)
}

function k2000beTourNode(_tour) {
  var bloc = document.createElement('div')
  bloc.className = 'k2000beTour ' + (_tour.role === 'user' ? 'k2000beUser' : 'k2000beBot')

  /* L'heure seule suffit tant qu'on reste dans la même journée. Deux bulles à
     vingt-quatre heures d'écart qui affichent « 21:14 » et « 21:16 » laissent
     croire à deux minutes d'intervalle : la date n'apparaît donc qu'au moment
     où elle change, et alors elle est indispensable. */
  var jour = k2000beJourTexte(_tour.date)
  var horodatage = k2000beHeure(_tour.date)
  if (jour !== '' && jour !== k2000beDernierJour) {
    horodatage = jour + ' ' + horodatage
    k2000beDernierJour = jour
  }

  var entete = document.createElement('span')
  entete.className = 'k2000beTourDate'
  entete.textContent = (_tour.role === 'user' ? '{{Vous}}' : '{{Assistant}}')
    + (horodatage !== '' ? ' · ' + horodatage : '')
  bloc.appendChild(entete)

  var bulle = document.createElement('div')
  bulle.className = 'k2000beBulle' + (_tour.erreur === true ? ' k2000beBulleErreur' : '')
  bulle.textContent = String(init(_tour.texte, ''))
  bloc.appendChild(bulle)

  if (_tour.role !== 'user') {
    var etapes = k2000beEtapesNode(_tour.etapes, _tour.statut)
    if (etapes !== null) { bloc.appendChild(etapes) }

    var pied = document.createElement('div')
    pied.style.marginTop = '3px'
    pied.style.fontSize = '.8em'

    if (String(init(_tour.statut, '')) !== '') {
      k2000beBadge(pied, k2000beStatutLabel(_tour.statut), k2000beStatutNiveau(_tour.statut))
    }
    /* Le coût et la durée ne sont connus que du tour qui vient d'avoir lieu :
       la frise enregistrée ne les porte pas. Les afficher quand ils sont là
       vaut mieux que de ne jamais les montrer. */
    if (is_numeric(_tour.duree)) {
      k2000beTexte(pied, '', ' ' + Math.round(parseFloat(_tour.duree) * 10) / 10 + ' {{s}}')
    }
    if (isset(_tour.jetons) && isset(_tour.jetons.total)) {
      k2000beTexte(pied, '', ' · ' + _tour.jetons.total + ' {{jetons}}' + k2000beCache(_tour.jetons.cache))
    }
    if (pied.childNodes.length > 0) { bloc.appendChild(pied) }
  }

  return bloc
}

function k2000beChatVide() {
  var aide = document.createElement('div')
  aide.className = 'help-block'
  aide.style.margin = '6px'
  aide.textContent = '{{Aucun échange pour le moment. Écrivez ce que vous attendez, en français, comme vous le diriez à quelqu\'un : l\'assistant cherchera lui-même les équipements concernés.}}'
  return aide
}

function k2000beRenderTours(_tours) {
  var box = k2000beEl('div_k2000beChat')
  if (box === null) { return }
  box.innerHTML = ''

  /* La frise repart de zéro : le jour de référence aussi, faute de quoi la
     première bulle d'une conversation rechargée n'aurait pas de date. */
  k2000beDernierJour = ''

  var tours = is_array(_tours) ? _tours : []
  if (tours.length === 0) {
    box.appendChild(k2000beChatVide())
    return
  }

  /* Un fragment : une conversation longue insérée nœud par nœud ferait
     recalculer la mise en page à chaque tour. */
  var fragment = document.createDocumentFragment()
  for (var i = 0; i < tours.length; i++) {
    /* La dernière phrase écrite par l'utilisateur est reprise de la frise :
       après un rechargement de page, c'est le seul moyen que le bandeau de
       confirmation sache à la suite de quoi il s'est ouvert — et c'est
       précisément le cas où on l'a oublié. */
    if (tours[i].role === 'user') { k2000beDerniereDemande = String(init(tours[i].texte, '')) }
    fragment.appendChild(k2000beTourNode(tours[i]))
  }
  box.appendChild(fragment)
  box.scrollTop = box.scrollHeight
}

function k2000beAjouterTour(_tour) {
  var box = k2000beEl('div_k2000beChat')
  if (box === null) { return }
  var aide = box.querySelector('.help-block')
  if (aide !== null) { box.innerHTML = '' }
  box.appendChild(k2000beTourNode(_tour))
  box.scrollTop = box.scrollHeight
}

/*
 * Le bandeau de confirmation.
 *
 * C'est le seul endroit de l'interface où l'utilisateur décide à la place de
 * l'assistant : il doit dire ce qui va partir, en toutes lettres, et ne jamais
 * partir tout seul. Passé cinq minutes la demande est caduque — le dire évite
 * de confirmer dans le vide l'ouverture d'un portail décidée un quart d'heure
 * plus tôt.
 *
 * L'échéance se lit en décompte et non en heure absolue : « valable jusqu'à
 * 21:14 » écrit une fois pour toutes ne bouge plus, et laisse un bouton rouge
 * cliquable sur une demande morte. Le décompte, lui, éteint le bouton tout seul
 * au moment exact où le serveur cessera d'accepter le jeton.
 */

/* La pièce d'une commande, quand l'arbre des autorisations a déjà été chargé.
   Le titre de la demande nomme l'équipement, jamais la pièce : « Portail —
   Ouvrir » ne dit pas lequel des deux portails. */
function k2000bePieceDeCmd(_cmdId) {
  if (k2000beArbreData === null) { return '' }
  var cible = String(_cmdId)
  if (cible === '' || cible === '0') { return '' }
  for (var p = 0; p < k2000beArbreData.length; p++) {
    var equipements = k2000beArbreData[p].equipements
    for (var e = 0; e < equipements.length; e++) {
      var commandes = equipements[e].commandes
      for (var c = 0; c < commandes.length; c++) {
        if (String(commandes[c].id) === cible) { return String(init(k2000beArbreData[p].objet, '')) }
      }
    }
  }
  return ''
}

function k2000beAttenteArreterHorloge() {
  if (k2000beAttenteMinuteur !== null) {
    clearInterval(k2000beAttenteMinuteur)
    k2000beAttenteMinuteur = null
  }
}

/*
 * Un tour d'horloge du bandeau.
 *
 * L'horloge s'arrête d'elle-même quand le bandeau disparaît — changement
 * d'assistant, confirmation cliquée, onglet refermé — exactement comme le
 * compteur du balayage : écrire dans un écran qui parle d'autre chose est pire
 * que de ne rien écrire.
 */
function k2000beAttenteDecompte() {
  var zone = k2000beEl('span_k2000beAttenteDecompte')
  if (zone === null || k2000beAttente === null) {
    k2000beAttenteArreterHorloge()
    return
  }

  var expire = parseInt(init(k2000beAttente.expire, 0), 10)
  if (isNaN(expire) || expire <= 0) {
    zone.textContent = ''
    k2000beAttenteArreterHorloge()
    return
  }

  var restant = expire - k2000beMaintenant()
  if (restant > 0) {
    zone.textContent = '{{valable encore}} ' + k2000beDureeTexte(restant)
    return
  }

  /* Échéance atteinte. Le serveur refusera le jeton ; le bouton doit cesser de
     promettre le contraire avant qu'on ne clique dessus. Le rôle est posé avant
     le texte : c'est le changement de contenu d'une zone déjà « alert » qui
     déclenche l'annonce, pas l'inverse. */
  zone.setAttribute('role', 'alert')
  zone.textContent = '{{Cette demande a expiré : rien n\'a été envoyé. Reformulez-la plutôt que de la confirmer, l\'état de la maison a pu changer depuis.}}'
  var confirmer = k2000beEl('bt_k2000beConfirmer')
  if (confirmer !== null) {
    confirmer.classList.add('disabled')
    confirmer.setAttribute('aria-disabled', 'true')
  }
  k2000beAttenteArreterHorloge()
}

function k2000beRenderAttente(_attente) {
  /* init() du cœur rend la chaîne vide, jamais null, quand la valeur manque :
     une attente absente doit malgré tout valoir null ici, faute de quoi le
     bandeau s'afficherait vide. */
  k2000beAttente = is_object(_attente) ? _attente : null
  k2000beAttenteArreterHorloge()

  var box = k2000beEl('div_k2000beAttente')
  if (box === null) { return }
  box.innerHTML = ''

  if (k2000beAttente === null) {
    box.style.display = 'none'
    return
  }
  box.style.display = ''

  var alerte = document.createElement('div')
  alerte.className = 'alert alert-warning'
  alerte.style.marginBottom = '0'

  var titre = document.createElement('b')
  titre.textContent = '{{K2000 souhaite :}}'
  alerte.appendChild(titre)

  var liste = document.createElement('ul')
  var demandes = is_array(k2000beAttente.demandes) ? k2000beAttente.demandes : []
  for (var i = 0; i < demandes.length; i++) {
    var item = document.createElement('li')
    var libelle = String(init(demandes[i].titre, ''))
    k2000beTexte(item, 'k2000beEtapeTitre', libelle)
    if (isset(demandes[i].valeur) && demandes[i].valeur !== null && String(demandes[i].valeur) !== '') {
      k2000beTexte(item, '', ' → ' + demandes[i].valeur)
    }

    /* La pièce, et non le motif. Le motif du serveur est « "Portail — Ouvrir"
       demande une confirmation humaine avant de partir » : sous un titre qui
       dit déjà « Portail — Ouvrir », cette ligne ne porte rien, et sur un écran
       où l'on doit trancher vite elle occupe la place de ce qui manquait. Ce
       qui manquait, c'est laquelle des deux portes, et à la suite de quoi. */
    var piece = k2000bePieceDeCmd(init(demandes[i].command_id, 0))
    if (piece !== '') {
      k2000beTexte(item, 'k2000beEtapeOutil', ' · ' + piece)
    }

    /* Un motif qui n'est pas la paraphrase du titre dit, lui, quelque chose :
       il est gardé. C'est le cas le jour où le serveur en écrira un autre. */
    var motif = String(init(demandes[i].motif, ''))
    if (motif !== '' && (libelle === '' || motif.indexOf(libelle) === -1)) {
      item.appendChild(document.createElement('br'))
      k2000beTexte(item, 'k2000beEtapeDetail', motif)
    }
    liste.appendChild(item)
  }
  alerte.appendChild(liste)

  /* Ce qui a déclenché la demande. Une confirmation qui arrive après un long
     silence, ou qu'on retrouve en revenant sur l'onglet, n'a plus de contexte :
     la phrase qu'on a soi-même écrite est le meilleur moyen de savoir si cette
     ouverture de portail est bien celle qu'on avait en tête. */
  if (k2000beDerniereDemande !== '') {
    var origine = document.createElement('div')
    origine.className = 'help-block'
    origine.style.margin = '0 0 6px 0'
    origine.textContent = '{{À la suite de votre demande :}} « ' + k2000beDerniereDemande + ' »'
    alerte.appendChild(origine)
  }

  var boutons = document.createElement('div')

  var confirmer = document.createElement('a')
  confirmer.className = 'btn btn-sm btn-danger'
  confirmer.id = 'bt_k2000beConfirmer'
  confirmer.setAttribute('role', 'button')
  confirmer.setAttribute('tabindex', '0')
  confirmer.innerHTML = '<i class="fas fa-check"></i> '
  confirmer.appendChild(document.createTextNode('{{Confirmer}}'))
  boutons.appendChild(confirmer)
  boutons.appendChild(document.createTextNode(' '))

  var annuler = document.createElement('a')
  annuler.className = 'btn btn-sm btn-default'
  annuler.id = 'bt_k2000beAnnuler'
  annuler.setAttribute('role', 'button')
  annuler.setAttribute('tabindex', '0')
  annuler.innerHTML = '<i class="fas fa-times"></i> '
  annuler.appendChild(document.createTextNode('{{Annuler}}'))
  boutons.appendChild(annuler)

  /* Le décompte n'est PAS une zone vivante tant qu'il décompte : une seconde
     annoncée chaque seconde couvrirait tout le reste de la page. Il le devient
     à l'échéance, où il a enfin quelque chose à dire — et le dit une fois. */
  var decompte = document.createElement('span')
  decompte.id = 'span_k2000beAttenteDecompte'
  decompte.className = 'help-block'
  boutons.appendChild(decompte)

  alerte.appendChild(boutons)
  box.appendChild(alerte)

  /* Le premier tour tout de suite : attendre une seconde afficherait un bandeau
     sans échéance, puis la ferait apparaître, ce qui se lit comme un défaut. */
  k2000beAttenteDecompte()

  /* L'horloge ne tourne que s'il reste quelque chose à décompter : le serveur
     ne rend plus d'attente périmée, mais un bandeau rouvert juste avant
     l'échéance y arrive, et il l'a alors déjà affichée. */
  var echeance = parseInt(init(k2000beAttente.expire, 0), 10)
  if (!isNaN(echeance) && echeance > k2000beMaintenant()) {
    k2000beAttenteMinuteur = setInterval(k2000beAttenteDecompte, 1000)
  }
}

/* Applique une réponse de ask() ou de confirm() : la réponse, ses étapes, et
   la confirmation qu'elle attend éventuellement. */
function k2000beAppliquerReponse(_resultat) {
  /* Une réponse vide n'arrive que si le serveur a répondu « ok » sans corps :
     mieux vaut ne rien afficher que de planter sur une propriété absente. */
  if (!is_object(_resultat)) { return }

  var erreur = init(_resultat.erreur, '')
  var texte = String(init(_resultat.reponse, ''))
  if (texte === '' && erreur !== '') { texte = String(erreur) }

  k2000beAjouterTour({
    role: 'assistant',
    texte: texte,
    date: k2000beMaintenant(),
    etapes: init(_resultat.etapes, []),
    statut: init(_resultat.statut, ''),
    duree: _resultat.duree,
    jetons: _resultat.jetons,
    /* La bulle porte l'erreur, et elle seule : la même phrase répétée dans une
       alerte rouge par-dessus l'écran se ferme, et emporte avec elle la seule
       trace visible de ce qui vient de se passer. La bulle, elle, reste. */
    erreur: (erreur !== '')
  })

  k2000beRenderAttente(_resultat.attente)
}

function k2000beAsk(_texte) {
  var id = k2000beCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez l\'assistant avant de lui parler.}}', level: 'warning' })
    return
  }
  var texte = String(_texte).trim()
  if (texte === '') { return }
  /* Le bouton est éteint et le champ en lecture seule pendant le travail : si
     l'on arrive malgré tout ici, c'est par un raccourci clavier, et il n'y a
     rien à reprocher — on ne fait rien. */
  if (k2000beEnCours) { return }

  k2000beEnCours = true
  k2000beScanner(true)
  k2000beTravailDemarrer()

  /* La demande est posée dans la frise tout de suite : voir son texte partir
     est la première chose qu'on attend d'une zone de saisie, et l'attente qui
     suit peut durer une minute. */
  k2000beDerniereDemande = texte
  k2000beAjouterTour({ role: 'user', texte: texte, date: k2000beMaintenant() })
  var champ = k2000beEl('in_k2000beDemande')
  if (champ !== null) { champ.value = '' }

  k2000beRequete = k2000beAjax('ask', { id: id, texte: texte }, function (result) {
    /* Même garde que k2000beCharger() : quarante secondes suffisent à revenir à
       la liste et à ouvrir un autre assistant. Sans elle, la réponse de KITT se
       peint sous « Garage », bandeau de confirmation compris — et ce bandeau se
       confirme, depuis le mauvais écran, sur le jeton d'une autre conversation. */
    if (k2000beCurrentId() !== String(id)) { return }
    k2000beTravailArreter('')
    k2000beAppliquerReponse(result)
  }, {
    timeout: K2000BE_DELAI_LONG,
    failure: function () {
      if (k2000beCurrentId() !== String(id)) { return }
      k2000beTravailArreter('{{La demande n\'a pas abouti.}}')
    }
  })
}

function k2000beConfirmer(_accepte) {
  var id = k2000beCurrentId()
  if (id === '' || k2000beAttente === null) { return }
  if (k2000beEnCours) { return }

  var jeton = String(init(k2000beAttente.jeton, ''))
  if (jeton === '') { return }

  k2000beEnCours = true
  k2000beScanner(true)
  k2000beTravailDemarrer()
  /* Le bandeau disparaît dès le clic : deux confirmations pour un seul jeton
     n'auraient pas le même effet la seconde fois. */
  k2000beRenderAttente(null)

  k2000beRequete = k2000beAjax('confirm', { id: id, jeton: jeton, accepte: _accepte ? 1 : 0 }, function (result) {
    /* Même garde que pour une demande : la suite d'une confirmation est une
       réponse d'assistant comme une autre, et elle peut rapporter un nouveau
       bandeau de confirmation. */
    if (k2000beCurrentId() !== String(id)) { return }
    k2000beTravailArreter('')
    k2000beAppliquerReponse(result)
  }, {
    timeout: K2000BE_DELAI_LONG,
    failure: function () {
      if (k2000beCurrentId() !== String(id)) { return }
      k2000beTravailArreter('{{La confirmation n\'a pas abouti.}}')
    }
  })
}

function k2000beReset() {
  var id = k2000beCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez l\'assistant avant de vider sa mémoire.}}', level: 'warning' })
    return
  }
  bootbox.confirm('{{Vider la mémoire de cet assistant ? La conversation en cours sera oubliée, y compris une confirmation en attente. Le journal, lui, est conservé.}}', function (_reponse) {
    if (!_reponse) { return }
    k2000beAjax('reset', { id: id }, function () {
      k2000beDerniereDemande = ''
      k2000beRenderTours([])
      k2000beRenderAttente(null)
      jeedomUtils.showAlert({ message: '{{Mémoire vidée : la prochaine demande repart d\'une page blanche.}}', level: 'success' })
    })
  })
}

/* =========================================================== AUTORISATIONS */

function k2000bePolitique(_cmd) {
  var valeur = String(init(_cmd.politique, 'deny'))
  return (valeur === 'allow' || valeur === 'confirm') ? valeur : 'deny'
}

/*
 * La lecture EFFECTIVE d'une commande d'information.
 *
 * arbre() la calcule déjà, réglage global et masquage compris : l'écran s'en
 * sert telle quelle plutôt que de refaire le calcul et de diverger du serveur
 * le jour où la règle change. Seul le repli, quand la donnée manque, retombe
 * sur le défaut connu.
 */
function k2000beLecture(_cmd) {
  var valeur = String(init(_cmd.lecture, ''))
  if (valeur === 'allow' || valeur === 'deny') { return valeur }
  return k2000beLectureDefaut ? 'allow' : 'deny'
}

/* Vrai quand ce choix n'a jamais été fait sur cette commande : il suit le
   réglage général, et changer celui-ci le changera aussi. */
function k2000beHeritee(_cmd) {
  return (_cmd.heritee === true || _cmd.heritee == 1)
}

/*
 * Les compteurs, calculés sur place.
 *
 * Ils sont recalculés à chaque clic plutôt que redemandés au serveur : sur une
 * installation qui compte des milliers de commandes, un aller-retour par clic
 * rendrait le réglage pénible, alors que la page connaît déjà la réponse.
 */
function k2000beCompteursListe(_equipements) {
  var compteurs = { autorisees: 0, confirmation: 0, interdites: 0, lisibles: 0, equipements: 0, sensibles: 0 }
  var equipements = is_array(_equipements) ? _equipements : []

  for (var e = 0; e < equipements.length; e++) {
    if (equipements[e].masque) { compteurs.equipements++ }

    /* Même règle que k2000beSecurite::compteurs() : un équipement masqué ou
       désactivé ne compte aucune commande autorisée ni lisible, quels que
       soient les réglages de ses commandes. Sans cela, cocher « masquer »
       laisserait l'en-tête annoncer des autorisations qui ne s'appliquent
       plus. */
    var visible = (equipements[e].actif !== false) && !equipements[e].masque

    var commandes = equipements[e].commandes
    for (var c = 0; c < commandes.length; c++) {
      if (commandes[c].type === 'action') {
        var politique = visible ? k2000bePolitique(commandes[c]) : 'deny'
        if (politique === 'allow') {
          compteurs.autorisees++
          /* Même compte que k2000beSecurite::compteurs() : ce qui ouvre,
             déverrouille ou désarme, et qui part sans rien demander. La ligne
             porte déjà son avertissement, mais il faut dérouler l'arbre pour le
             voir — ce compteur, lui, se lit sans chercher. */
          if (commandes[c].sensible) { compteurs.sensibles++ }
        }
        else if (politique === 'confirm') { compteurs.confirmation++ }
        else { compteurs.interdites++ }
        continue
      }
      if (visible && k2000beLecture(commandes[c]) === 'allow') { compteurs.lisibles++ }
    }
  }
  return compteurs
}

function k2000beCompteurs() {
  var total = { autorisees: 0, confirmation: 0, interdites: 0, lisibles: 0, equipements: 0, sensibles: 0 }
  if (k2000beArbreData === null) { return total }

  for (var p = 0; p < k2000beArbreData.length; p++) {
    var piece = k2000beCompteursListe(k2000beArbreData[p].equipements)
    total.autorisees += piece.autorisees
    total.confirmation += piece.confirmation
    total.interdites += piece.interdites
    total.lisibles += piece.lisibles
    total.equipements += piece.equipements
    total.sensibles += piece.sensibles
  }
  return total
}

/*
 * La phrase qui dit ce qu'il advient d'une commande d'information jamais
 * réglée. Sans elle, une liste entièrement en « Lisible » sans qu'on ait rien
 * cliqué ressemble à une erreur.
 */
function k2000beRenderLectureDefaut() {
  var zone = k2000beEl('span_k2000beLectureDefaut')
  if (zone === null) { return }
  zone.textContent = k2000beLectureDefaut
    ? '{{Réglage général : une commande d\'information jamais réglée ici est lisible par l\'assistant. Masquez au cas par cas ce qui ne doit pas sortir de chez vous.}}'
    : '{{Réglage général : une commande d\'information jamais réglée ici est masquée. L\'assistant ne voit que les états que vous passez explicitement en « Lisible ».}}'
}

/*
 * Le résumé de l'onglet Équipement.
 *
 * Il affichait les chiffres rendus par le serveur à l'ouverture, et plus jamais
 * rien : après douze commandes autorisées, un onglet annonçait 12 et l'autre 0,
 * sur la même installation et à la même seconde. Les deux écrans passent
 * désormais par ici, et l'onglet Autorisations y repasse à chaque clic.
 */
function k2000beRenderResume(_compteurs) {
  var resume = k2000beEl('span_k2000beResume')
  if (resume === null) { return }
  resume.innerHTML = ''
  if (!isset(_compteurs)) {
    resume.textContent = '-'
    return
  }
  k2000beBadge(resume, init(_compteurs.autorisees, 0) + ' {{autorisées}}', 'success')
  k2000beBadge(resume, init(_compteurs.confirmation, 0) + ' {{en confirmation}}', 'warning')
  k2000beBadge(resume, init(_compteurs.interdites, 0) + ' {{interdites}}', 'danger')
  k2000beBadge(resume, init(_compteurs.lisibles, 0) + ' {{états lisibles}}', 'default')

  /* « 0 autorisées » sur une installation où l'on vient d'autoriser douze
     commandes ressemble à un réglage perdu. C'en est l'inverse : en lecture
     seule le serveur compte ce qui peut partir maintenant, c'est-à-dire rien.
     L'étiquette dit laquelle des deux questions ces chiffres viennent de
     trancher. */
  if (k2000beMode === 'lecture') {
    k2000beBadge(resume, '{{lecture seule}}', 'info',
      '{{Le mode « lecture seule » est actif : aucune commande ne partira, quelles que soient les autorisations posées. Elles sont conservées et redeviennent effectives dès le retour en « Actions réelles ».}}')
  }
}

/*
 * Le bandeau « rien n'est autorisé » de l'onglet Discussion.
 *
 * Sur une installation neuve, aucune commande d'action n'est autorisée : toute
 * demande d'action est refusée, et l'assistant l'explique poliment sans que
 * l'on sache où porter remède. Ce fait n'était écrit que dans le bloc d'accueil
 * de la liste, qui disparaît dès qu'un assistant existe — c'est-à-dire juste
 * avant le moment où l'on en a besoin.
 */
function k2000beRenderAucuneAutorisation(_compteurs) {
  var bandeau = k2000beEl('div_k2000beAucuneAutorisation')
  if (bandeau === null) { return }
  if (!isset(_compteurs)) {
    bandeau.style.display = 'none'
    return
  }

  /* En lecture seule, aucune commande n'est exécutable : les compteurs rendus
     par le serveur annoncent donc zéro action autorisée, et c'est la vérité du
     moment. Ce bandeau-ci dirait alors « aucune commande n'est encore
     autorisée » et proposerait d'aller en régler, c'est-à-dire d'aller corriger
     ce qui n'est pas en cause — le bandeau du mode, juste au-dessus, dit déjà
     ce qu'il faut savoir. On se tait plutôt que de présenter un réglage
     délibéré comme un oubli. */
  if (k2000beMode === 'lecture') {
    bandeau.style.display = 'none'
    return
  }

  var actions = parseInt(init(_compteurs.autorisees, 0), 10) + parseInt(init(_compteurs.confirmation, 0), 10)
  bandeau.style.display = (actions > 0) ? 'none' : ''
}

function k2000beRenderCompteurs() {
  var compteurs = k2000beCompteurs()
  /* L'onglet Équipement et le bandeau de la Discussion se règlent sur le même
     calcul, au même instant : c'est la seule façon qu'ils ne divergent pas. */
  if (k2000beArbreData !== null) {
    k2000beRenderResume(compteurs)
    k2000beRenderAucuneAutorisation(compteurs)
  }

  var zone = k2000beEl('span_k2000beCompteurs')
  if (zone === null) { return }
  zone.innerHTML = ''

  k2000beBadge(zone, compteurs.autorisees + ' {{autorisées}}', 'success', '{{Commandes d\'action que l\'assistant peut exécuter sans rien demander.}}')
  k2000beBadge(zone, compteurs.confirmation + ' {{en confirmation}}', 'warning', '{{Commandes d\'action que l\'assistant peut demander, et que vous validez au cas par cas.}}')
  k2000beBadge(zone, compteurs.interdites + ' {{interdites}}', 'danger', '{{Commandes d\'action que l\'assistant ne peut pas exécuter. C\'est l\'état par défaut.}}')
  k2000beBadge(zone, compteurs.lisibles + ' {{états lisibles}}', 'default', '{{Commandes d\'information que l\'assistant peut consulter. Les autres ne lui sont pas même nommées.}}')
  if (compteurs.equipements > 0) {
    k2000beBadge(zone, compteurs.equipements + ' {{équipements masqués}}', 'default', '{{Équipements entièrement retirés du champ de vision de l\'assistant.}}')
  }
  /* Le seul compteur qui n'apparaît que s'il n'est pas nul : à zéro, il
     n'apprendrait rien, et une pastille rouge permanente cesse d'être lue. */
  if (compteurs.sensibles > 0) {
    k2000beBadge(zone, compteurs.sensibles + ' {{sensibles sans confirmation}}', 'danger', '{{Commandes qui ouvrent, déverrouillent ou désarment, réglées sur « Autorisée » : l\'assistant les actionne sans rien demander. « Confirmation » est le réglage recommandé.}}')
  }
}

/*
 * Trois apparences, parce qu'il y a trois états et non deux.
 *
 * Un état hérité et un état fixé se peignaient pareil : sur une installation
 * dont le réglage général est « Lisible », le bouton Lisible était DÉJÀ vert
 * sur les treize cents commandes d'information, cliquées ou non. Cliquer ne
 * changeait donc rien à l'écran — sauf une mention grise de note de bas de page
 * —, le clic passait pour perdu, et le réflexe « ça n'a pas pris, je reclique »
 * rendait la commande à l'héritage sans le dire, puisque recliquer sur un choix
 * fixé est précisément le moyen de l'annuler.
 *
 * Enfoncé et plein : vous l'avez choisi. Enfoncé, gris et pointillé : c'est ce
 * qui s'applique, mais cela vient du réglage général et suivra ses changements.
 */
function k2000beBoutonEtat(_bouton, _actif, _classeActive, _herite) {
  var classes = 'btn btn-xs k2000bePol '
  if (!_actif) {
    classes += 'btn-default'
  } else if (_herite) {
    classes += 'btn-default k2000beHerite active'
  } else {
    classes += _classeActive + ' active'
  }
  _bouton.className = classes
  /* Le bouton est un interrupteur, pas un lien : un lecteur d'écran doit
     entendre lequel des trois est enfoncé, la couleur ne lui disant rien. */
  _bouton.setAttribute('aria-pressed', _actif ? 'true' : 'false')
}

/* Rafraîchit les trois (ou deux) boutons d'une ligne sans la reconstruire :
   reconstruire ferait sauter la ligne sous le curseur au moment du clic. */
function k2000beMajLigne(_ligne, _cmd) {
  var boutons = _ligne.querySelectorAll('.k2000bePol')
  var i
  if (_cmd.type === 'action') {
    var politique = k2000bePolitique(_cmd)
    for (i = 0; i < boutons.length; i++) {
      var valeur = boutons[i].getAttribute('data-politique')
      var classe = (valeur === 'allow') ? 'btn-success' : ((valeur === 'confirm') ? 'btn-warning' : 'btn-danger')
      k2000beBoutonEtat(boutons[i], valeur === politique, classe)
    }
    var alerte = _ligne.querySelector('.k2000beAlerteSensible')
    if (alerte !== null) {
      alerte.style.display = (_cmd.sensible && politique === 'allow') ? '' : 'none'
    }
    return
  }

  var lecture = k2000beLecture(_cmd)
  var heritee = k2000beHeritee(_cmd)
  for (i = 0; i < boutons.length; i++) {
    var val = boutons[i].getAttribute('data-lecture')
    var allume = (val === lecture)
    k2000beBoutonEtat(boutons[i], allume, (val === 'allow') ? 'btn-success' : 'btn-danger', heritee)
    /* Le bouton allumé ne dit pas la même chose selon qu'on l'a cliqué ou
       qu'il suit le réglage général : sans cette infobulle, recliquer dessus
       produirait un effet qu'on n'attendait pas. */
    if (!allume) {
      boutons[i].title = boutons[i].getAttribute('data-titre')
    } else if (heritee) {
      boutons[i].title = '{{Hérité du réglage général du plugin. Cliquez pour fixer ce choix sur cette commande seule.}}'
    } else {
      boutons[i].title = '{{Choisi pour cette commande. Cliquez de nouveau pour revenir au réglage général.}}'
    }
  }
  /*
   * Le même repère dit les deux états, au lieu d'apparaître et de disparaître.
   * Une mention qui s'efface au clic est le retour le plus faible qui soit :
   * on ne remarque pas ce qui n'est plus là. « Fixé ici » se voit.
   */
  var defaut = _ligne.querySelector('.k2000beDefaut')
  if (defaut !== null) {
    defaut.className = 'label k2000beDefaut ' + (heritee ? 'label-default' : 'label-info')
    defaut.textContent = heritee ? '{{réglage général}}' : '{{fixé ici}}'
    defaut.title = heritee
      ? '{{Cette commande suit le réglage « Lecture des états par défaut » de la configuration du plugin, et suivra ses changements.}}'
      : '{{Ce choix est enregistré sur cette commande : il ne bougera plus si vous changez le réglage général. Recliquez sur le bouton allumé pour revenir au réglage général.}}'
  }
}

function k2000beCmdNode(_cmd) {
  var ligne = document.createElement('div')
  ligne.className = 'k2000beCmdLigne'
  ligne.setAttribute('data-cmd-id', _cmd.id)

  var nom = document.createElement('span')
  nom.className = 'k2000beCmdNom'
  if (_cmd.type === 'action' && _cmd.sensible) {
    /* Une serrure, un portail, une alarme : ces commandes ne sont pas des
       commandes comme les autres, et l'écran doit le dire avant le clic. */
    var avertissement = document.createElement('i')
    avertissement.className = 'fas fa-exclamation-triangle k2000beSensible'
    avertissement.title = '{{Commande sensible : elle ouvre, déverrouille ou désarme. « Confirmation » est le réglage recommandé.}}'
    nom.appendChild(avertissement)
    nom.appendChild(document.createTextNode(' '))
  }
  nom.appendChild(document.createTextNode(String(init(_cmd.nom, ''))))
  nom.title = String(init(_cmd.nom, '')) + ' — ' + String(init(_cmd.generique, '')) + ' (' + String(init(_cmd.sousType, '')) + ')'
  ligne.appendChild(nom)

  if (_cmd.type === 'action') {
    var groupe = document.createElement('div')
    groupe.className = 'btn-group btn-group-xs'
    var politiques = [
      { valeur: 'deny', libelle: '{{Interdite}}', titre: '{{L\'assistant ne peut pas exécuter cette commande. Il l\'expliquera calmement s\'il la demande.}}' },
      { valeur: 'allow', libelle: '{{Autorisée}}', titre: '{{L\'assistant exécute cette commande de lui-même, sans rien vous demander.}}' },
      { valeur: 'confirm', libelle: '{{Confirmation}}', titre: '{{L\'assistant demande votre accord avant d\'exécuter cette commande. Rien ne part tant que vous n\'avez pas confirmé.}}' }
    ]
    for (var p = 0; p < politiques.length; p++) {
      var bouton = document.createElement('a')
      bouton.className = 'btn btn-xs btn-default k2000bePol'
      /* Un <a> sans href n'est pas focalisable : sans ces deux attributs, le
         seul réglage qui décide de ce que l'assistant peut faire chez vous est
         hors de portée de quiconque n'a pas de souris. */
      bouton.setAttribute('role', 'button')
      bouton.setAttribute('tabindex', '0')
      bouton.setAttribute('data-politique', politiques[p].valeur)
      bouton.title = politiques[p].titre
      bouton.textContent = politiques[p].libelle
      groupe.appendChild(bouton)
    }
    ligne.appendChild(groupe)

    var alerte = document.createElement('span')
    alerte.className = 'label label-danger k2000beAlerteSensible'
    alerte.style.display = 'none'
    alerte.textContent = '{{autorisée sans confirmation}}'
    alerte.title = '{{Cette commande engage la sécurité du logement et l\'assistant peut l\'exécuter seul, sur une simple phrase mal comprise.}}'
    ligne.appendChild(alerte)
  } else {
    var groupeInfo = document.createElement('div')
    groupeInfo.className = 'btn-group btn-group-xs'
    var lectures = [
      { valeur: 'allow', libelle: '{{Lisible}}', titre: '{{L\'assistant peut lire cette valeur, quel que soit le réglage général.}}' },
      { valeur: 'deny', libelle: '{{Masquée}}', titre: '{{L\'assistant ne voit pas cette commande, pas même son nom.}}' }
    ]
    for (var l = 0; l < lectures.length; l++) {
      var boutonInfo = document.createElement('a')
      boutonInfo.className = 'btn btn-xs btn-default k2000bePol'
      boutonInfo.setAttribute('role', 'button')
      boutonInfo.setAttribute('tabindex', '0')
      boutonInfo.setAttribute('data-lecture', lectures[l].valeur)
      /* Le libellé de base est gardé sur le bouton : son infobulle change selon
         que le choix est hérité ou fixé, et il faut pouvoir y revenir. */
      boutonInfo.setAttribute('data-titre', lectures[l].titre)
      boutonInfo.title = lectures[l].titre
      boutonInfo.textContent = lectures[l].libelle
      groupeInfo.appendChild(boutonInfo)
    }
    ligne.appendChild(groupeInfo)

    /* Le troisième état — aucun choix explicite — n'a pas de bouton à lui :
       c'est l'absence de choix. Ce repère dit lequel des deux on est, et
       k2000beMajLigne l'écrit ; il n'y a rien à peindre ici. */
    var defaut = document.createElement('span')
    defaut.className = 'label label-default k2000beDefaut'
    ligne.appendChild(defaut)

    if (String(init(_cmd.valeur, '')) !== '') {
      k2000beTexte(ligne, 'k2000beCmdValeur', String(_cmd.valeur) + ' ' + String(init(_cmd.unite, '')))
    }
  }

  k2000beMajLigne(ligne, _cmd)
  return ligne
}

function k2000beEquipementNode(_equipement) {
  var bloc = document.createElement('div')
  bloc.className = 'k2000beEquipement' + (_equipement.masque ? ' k2000beMasque' : '')
  bloc.setAttribute('data-eq-id', _equipement.id)

  var titre = document.createElement('div')
  titre.className = 'k2000beEqTitre'
  k2000beTexte(titre, 'k2000beEqNom', init(_equipement.nom, ''))
  k2000beTexte(titre, 'k2000beEqType', init(_equipement.type, ''))

  if (!_equipement.actif) {
    k2000beBadge(titre, '{{désactivé}}', 'default', '{{Équipement désactivé dans Jeedom : l\'assistant ne le voit pas, quelles que soient les autorisations ci-dessous.}}')
  }

  var masque = document.createElement('label')
  masque.className = 'checkbox-inline'
  masque.title = '{{Retire tout l\'équipement du champ de vision de l\'assistant : ni ses états, ni ses actions, ni son nom.}}'
  var case_ = document.createElement('input')
  case_.type = 'checkbox'
  case_.className = 'k2000beMasquer'
  case_.checked = (_equipement.masque === true || _equipement.masque == 1)
  masque.appendChild(case_)
  masque.appendChild(document.createTextNode('{{Masquer de l\'assistant}}'))
  titre.appendChild(masque)

  bloc.appendChild(titre)

  var commandes = document.createElement('div')
  commandes.className = 'k2000beCmds'
  var liste = is_array(_equipement.commandes) ? _equipement.commandes : []
  for (var c = 0; c < liste.length; c++) {
    commandes.appendChild(k2000beCmdNode(liste[c]))
  }
  bloc.appendChild(commandes)

  return bloc
}

/*
 * Les deux bascules.
 *
 * Sur une installation réelle — cent seize équipements, deux cent cinquante
 * actions et près de treize cents états — les états noient les actions, et rien
 * ne dit où sont les autorisations déjà posées. « Seulement les actions » rend
 * l'onglet lisible ; « seulement ce qui est autorisé » répond à la question
 * qu'on vient y poser le plus souvent : qu'ai-je déjà ouvert ?
 *
 * « Autorisé » veut dire réglé à la main : une action en « Autorisée » ou en
 * « Confirmation », un état explicitement mis en « Lisible ». Les états qui
 * suivent simplement le réglage général n'en sont pas : sur une installation
 * où ce réglage est permissif, ils sont treize cents et noieraient à nouveau
 * exactement ce qu'on cherchait.
 */
function k2000beCmdRetenue(_cmd) {
  if (k2000beFiltreActions && _cmd.type !== 'action') { return false }
  if (!k2000beFiltreAutorisees) { return true }

  if (_cmd.type === 'action') {
    var politique = k2000bePolitique(_cmd)
    return (politique === 'allow' || politique === 'confirm')
  }
  return (k2000beLecture(_cmd) === 'allow' && !k2000beHeritee(_cmd))
}

function k2000beFiltresActifs() {
  return (k2000beFiltreActions || k2000beFiltreAutorisees)
}

/* Ne garde que ce qui correspond à la recherche et aux bascules. Un équipement
   dont le NOM correspond garde toutes ses commandes : chercher « portail » doit
   montrer le portail en entier, pas seulement ses commandes qui portent ce mot. */
function k2000beFiltrerPiece(_piece, _filtre) {
  if (_filtre === '' && !k2000beFiltresActifs()) { return _piece.equipements }

  var gardes = []
  var pieceCorrespond = (_filtre === '')
    || String(init(_piece.objet, '')).toLowerCase().indexOf(_filtre) !== -1

  for (var e = 0; e < _piece.equipements.length; e++) {
    var equipement = _piece.equipements[e]
    var eqCorrespond = pieceCorrespond
      || String(init(equipement.nom, '')).toLowerCase().indexOf(_filtre) !== -1
      || String(init(equipement.type, '')).toLowerCase().indexOf(_filtre) !== -1

    var commandes = []
    for (var c = 0; c < equipement.commandes.length; c++) {
      var cmd = equipement.commandes[c]
      if (!k2000beCmdRetenue(cmd)) { continue }
      if (eqCorrespond
        || String(init(cmd.nom, '')).toLowerCase().indexOf(_filtre) !== -1
        || String(init(cmd.generique, '')).toLowerCase().indexOf(_filtre) !== -1) {
        commandes.push(cmd)
      }
    }
    if (commandes.length === 0) { continue }

    /* Copie de surface : le filtre ne doit pas amputer la donnée d'origine,
       sinon effacer la recherche perdrait les commandes écartées. Les clics,
       eux, retrouvent toujours la vraie commande par son identifiant. */
    gardes.push({
      id: equipement.id, nom: equipement.nom, type: equipement.type,
      masque: equipement.masque, actif: equipement.actif, commandes: commandes
    })
  }
  return gardes
}

function k2000beConstruirePiece(_section) {
  if (_section.getAttribute('data-construit') === '1') { return }
  var corps = _section.querySelector('.k2000bePieceCorps')
  if (corps === null) { return }

  var equipements = _section.k2000beEquipements || []
  var fragment = document.createDocumentFragment()
  for (var e = 0; e < equipements.length; e++) {
    fragment.appendChild(k2000beEquipementNode(equipements[e]))
  }
  corps.appendChild(fragment)
  _section.setAttribute('data-construit', '1')
}

/*
 * Une pièce.
 *
 * Son contenu n'est construit qu'à l'ouverture : une installation ordinaire
 * dépasse le millier de commandes, et fabriquer d'emblée toutes leurs lignes
 * fige le navigateur plusieurs secondes pour un écran dont on ne regardera
 * qu'une pièce.
 */
function k2000bePieceNode(_piece, _equipements, _ouvert) {
  var section = document.createElement('div')
  section.className = 'k2000bePiece'
  section.setAttribute('data-construit', '0')
  section.setAttribute('data-piece', String(init(_piece.objet, '')))
  section.k2000beEquipements = _equipements

  /* L'en-tête est un bouton : on l'atteint à la tabulation, on l'ouvre à
     l'Entrée ou à l'Espace, et Échap le referme. Un <div> cliquable n'existe
     pas pour qui n'a pas de souris. */
  var titre = document.createElement('div')
  titre.className = 'k2000bePieceTitre'
  titre.setAttribute('role', 'button')
  titre.setAttribute('tabindex', '0')
  titre.setAttribute('aria-expanded', _ouvert ? 'true' : 'false')

  var caret = document.createElement('i')
  caret.className = _ouvert ? 'fas fa-angle-down' : 'fas fa-angle-right'
  titre.appendChild(caret)

  k2000beTexte(titre, '', init(_piece.objet, ''))

  var nbCommandes = 0
  for (var e = 0; e < _equipements.length; e++) { nbCommandes += _equipements[e].commandes.length }
  k2000beBadge(titre, _equipements.length + ' {{équip.}} · ' + nbCommandes + ' {{cmd.}}', 'default')

  /*
   * Ce que la pièce contient déjà d'autorisé, sur l'en-tête et sans l'ouvrir.
   * Sans cela, retrouver les douze commandes qu'on a autorisées la semaine
   * dernière demande d'ouvrir les pièces une à une. Le compte porte sur la
   * pièce entière, filtres compris ou non : il dit ce qu'elle CONTIENT, pas ce
   * que l'écran montre en ce moment.
   *
   * Rien n'est affiché à zéro : une rangée de « 0 » sur cent seize équipements
   * serait un bruit de plus, et c'est précisément le contraire du but.
   */
  var compteurs = k2000beCompteursListe(_piece.equipements)
  if (compteurs.autorisees > 0) {
    k2000beBadge(titre, compteurs.autorisees + ' {{autorisées}}', 'success',
      '{{Commandes d\'action que l\'assistant peut exécuter seul dans cette pièce.}}')
  }
  if (compteurs.confirmation > 0) {
    k2000beBadge(titre, compteurs.confirmation + ' {{en confirmation}}', 'warning',
      '{{Commandes d\'action que l\'assistant peut demander dans cette pièce, et que vous validez au cas par cas.}}')
  }
  if (compteurs.equipements > 0) {
    k2000beBadge(titre, compteurs.equipements + ' {{masqués}}', 'default',
      '{{Équipements de cette pièce entièrement retirés du champ de vision de l\'assistant.}}')
  }

  section.appendChild(titre)

  var corps = document.createElement('div')
  corps.className = 'k2000bePieceCorps'
  corps.style.display = _ouvert ? '' : 'none'
  section.appendChild(corps)

  if (_ouvert) { k2000beConstruirePiece(section) }
  return section
}

function k2000beRenderArbre() {
  var box = k2000beEl('div_k2000beArbre')
  if (box === null) { return }
  box.innerHTML = ''

  if (k2000beArbreData === null) {
    var attente = document.createElement('div')
    attente.className = 'help-block'
    attente.textContent = '{{Chargement des autorisations…}}'
    box.appendChild(attente)
    return
  }

  var champ = k2000beEl('in_k2000beRecherche')
  var saisie = (champ === null) ? '' : champ.value.trim()
  var filtre = saisie.toLowerCase()

  /*
   * Une seule lettre correspond à peu près partout : l'arbre dépliait alors
   * toutes les pièces et fabriquait d'un coup les quinze cents lignes que la
   * construction paresseuse existe pour éviter — puis recommençait à la frappe
   * suivante. En deçà de deux caractères la recherche ne s'applique pas, et
   * l'écran dit pourquoi plutôt que de rester inerte.
   */
  var tropCourt = (filtre !== '' && saisie.length < K2000BE_RECHERCHE_MIN)
  if (tropCourt) {
    filtre = ''
    var court = document.createElement('div')
    court.className = 'help-block'
    court.textContent = '{{Recherche : tapez au moins deux caractères. Une seule lettre correspond presque partout, et déplierait toute l\'installation.}}'
    box.appendChild(court)
  }

  var resultats = []
  for (var p = 0; p < k2000beArbreData.length; p++) {
    var equipements = k2000beFiltrerPiece(k2000beArbreData[p], filtre)
    if (equipements.length === 0) { continue }
    resultats.push({ piece: k2000beArbreData[p], equipements: equipements })
  }

  if (resultats.length === 0) {
    var vide = document.createElement('div')
    vide.className = 'help-block'
    if (filtre === '' && !k2000beFiltresActifs()) {
      vide.textContent = '{{Aucun équipement à autoriser : créez d\'abord des équipements dans Jeedom.}}'
    } else if (filtre === '') {
      vide.textContent = '{{Aucune commande ne répond à ces filtres. Rien n\'est encore autorisé, ou la pièce que vous cherchez n\'a que des états.}}'
    } else {
      vide.textContent = '{{Aucun résultat pour cette recherche.}}'
    }
    box.appendChild(vide)
    k2000beRenderCompteurs()
    k2000beRenderLectureDefaut()
    return
  }

  /*
   * Un filtre ouvre ce qu'il trouve — chercher puis devoir déplier serait
   * chercher deux fois — mais seulement tant que cela reste quelques pièces.
   * Au-delà, déplier d'office, c'est construire tout l'arbre, ce que le filtre
   * était censé épargner.
   */
  var filtreActif = (filtre !== '' || k2000beFiltresActifs())
  var deplier = (filtreActif && resultats.length <= K2000BE_DEPLIAGE_MAX)

  var fragment = document.createDocumentFragment()
  for (var r = 0; r < resultats.length; r++) {
    var nom = String(init(resultats[r].piece.objet, ''))
    /* La pièce sur laquelle on travaillait reste ouverte : effacer la
       recherche rendait jusqu'ici un écran entièrement replié, et il fallait
       retrouver à la main où l'on en était. */
    var ouvert = deplier || (k2000bePiecesOuvertes[k2000bePieceCle(nom)] === true)
    fragment.appendChild(k2000bePieceNode(resultats[r].piece, resultats[r].equipements, ouvert))
  }
  box.appendChild(fragment)

  k2000beRenderCompteurs()
  k2000beRenderLectureDefaut()
}

/* Ouvre ou referme une pièce, et s'en souvient. */
function k2000beBasculerPiece(_titre, _ouvrir) {
  var section = _titre.closest('.k2000bePiece')
  if (section === null) { return }
  var corps = section.querySelector('.k2000bePieceCorps')
  if (corps === null) { return }

  var ouvert = isset(_ouvrir) ? _ouvrir : (corps.style.display === 'none')
  if (ouvert) { k2000beConstruirePiece(section) }
  corps.style.display = ouvert ? '' : 'none'
  _titre.setAttribute('aria-expanded', ouvert ? 'true' : 'false')

  var caret = _titre.querySelector('i')
  if (caret !== null) { caret.className = ouvert ? 'fas fa-angle-down' : 'fas fa-angle-right' }

  var nom = section.getAttribute('data-piece')
  if (nom !== null) { k2000bePiecesOuvertes[k2000bePieceCle(nom)] = ouvert }
}

/* L'état des deux bascules, porté par le bouton lui-même : le bouton enfoncé
   est la seule chose qui dise que la liste est incomplète à dessein. */
function k2000beRenderFiltres() {
  var actions = k2000beEl('bt_k2000beFiltreActions')
  if (actions !== null) {
    actions.className = 'btn btn-xs ' + (k2000beFiltreActions ? 'btn-primary active' : 'btn-default')
    actions.setAttribute('aria-pressed', k2000beFiltreActions ? 'true' : 'false')
  }
  var autorisees = k2000beEl('bt_k2000beFiltreAutorisees')
  if (autorisees !== null) {
    autorisees.className = 'btn btn-xs ' + (k2000beFiltreAutorisees ? 'btn-primary active' : 'btn-default')
    autorisees.setAttribute('aria-pressed', k2000beFiltreAutorisees ? 'true' : 'false')
  }
}

function k2000beChargerArbre(_force) {
  if (k2000beArbreCharge && _force !== true) {
    k2000beRenderArbre()
    return
  }
  k2000beArbreData = null
  k2000beRenderArbre()
  k2000beAjax('arbre', {}, function (result) {
    k2000beArbreData = is_array(result) ? result : []
    k2000beArbreCharge = true
    k2000beRenderArbre()
  })
}

/* Retrouve la commande dans la donnée à partir de son identifiant : c'est elle
   qui fait foi, la ligne du DOM n'est que son reflet. */
function k2000beChercherCmd(_cmdId) {
  if (k2000beArbreData === null) { return null }
  for (var p = 0; p < k2000beArbreData.length; p++) {
    var equipements = k2000beArbreData[p].equipements
    for (var e = 0; e < equipements.length; e++) {
      var commandes = equipements[e].commandes
      for (var c = 0; c < commandes.length; c++) {
        if (String(commandes[c].id) === String(_cmdId)) { return commandes[c] }
      }
    }
  }
  return null
}

function k2000beChercherEquipement(_eqId) {
  if (k2000beArbreData === null) { return null }
  for (var p = 0; p < k2000beArbreData.length; p++) {
    var equipements = k2000beArbreData[p].equipements
    for (var e = 0; e < equipements.length; e++) {
      if (String(equipements[e].id) === String(_eqId)) { return equipements[e] }
    }
  }
  return null
}

function k2000beDefinirPolitique(_ligne, _politique) {
  var cmdId = _ligne.getAttribute('data-cmd-id')
  var cmd = k2000beChercherCmd(cmdId)
  if (cmd === null) { return }

  var ancienne = k2000bePolitique(cmd)
  if (ancienne === _politique) { return }

  /* L'écran change d'abord, le serveur ensuite : sur une liste où l'on règle
     cinquante commandes à la suite, attendre la réponse avant d'allumer le
     bouton donnerait une interface qui traîne. L'échec, lui, remet en place. */
  cmd.politique = _politique
  k2000beMajLigne(_ligne, cmd)
  k2000beRenderCompteurs()

  k2000beAjax('politique', { cmd_id: cmdId, politique: _politique }, function () {}, {
    failure: function () {
      cmd.politique = ancienne
      k2000beMajLigne(_ligne, cmd)
      k2000beRenderCompteurs()
    }
  })
}

function k2000beDefinirLecture(_ligne, _lecture) {
  var cmdId = _ligne.getAttribute('data-cmd-id')
  var cmd = k2000beChercherCmd(cmdId)
  if (cmd === null) { return }

  var ancienne = k2000beLecture(cmd)
  var ancienHeritage = k2000beHeritee(cmd)

  /*
   * Trois états pour deux boutons. Recliquer sur le bouton DÉJÀ FIXÉ rend la
   * commande au réglage général — c'est le seul moyen de revenir à l'héritage.
   * Recliquer sur un bouton simplement hérité, lui, fige ce choix sur la
   * commande : il survivra alors à un changement du réglage général.
   */
  var envoi = (!ancienHeritage && ancienne === _lecture) ? '' : _lecture
  var effective = (envoi === '') ? (k2000beLectureDefaut ? 'allow' : 'deny') : envoi

  cmd.lecture = effective
  cmd.heritee = (envoi === '')
  k2000beMajLigne(_ligne, cmd)
  k2000beRenderCompteurs()

  k2000beAjax('lecture', { cmd_id: cmdId, lecture: envoi }, function () {}, {
    failure: function () {
      cmd.lecture = ancienne
      cmd.heritee = ancienHeritage
      k2000beMajLigne(_ligne, cmd)
      k2000beRenderCompteurs()
    }
  })
}

function k2000beDefinirMasque(_bloc, _masque) {
  var eqId = _bloc.getAttribute('data-eq-id')
  var equipement = k2000beChercherEquipement(eqId)
  if (equipement === null) { return }

  equipement.masque = _masque
  _bloc.classList.toggle('k2000beMasque', _masque)
  k2000beRenderCompteurs()

  k2000beAjax('masquer', { eqLogic_id: eqId, masque: _masque ? 1 : 0 }, function () {}, {
    failure: function () {
      equipement.masque = !_masque
      _bloc.classList.toggle('k2000beMasque', !_masque)
      var case_ = _bloc.querySelector('.k2000beMasquer')
      if (case_ !== null) { case_.checked = !_masque }
      k2000beRenderCompteurs()
    }
  })
}

/* ============================================================== HISTORIQUE */

function k2000beLigneJournalNode(_ligne) {
  var bloc = document.createElement('div')
  bloc.className = 'k2000beLigneJournal'

  var entete = document.createElement('div')
  entete.className = 'k2000beJournalEntete'
  k2000beBadge(entete, k2000beStatutLabel(_ligne.statut), k2000beStatutNiveau(_ligne.statut))
  k2000beTexte(entete, '', k2000beDate(_ligne.date))
  if (String(init(_ligne.utilisateur, '')) !== '') {
    k2000beTexte(entete, '', ' · ' + _ligne.utilisateur)
  }
  if (String(init(_ligne.modele, '')) !== '') {
    k2000beTexte(entete, '', ' · ' + _ligne.modele)
  }
  if (is_numeric(_ligne.duree)) {
    k2000beTexte(entete, '', ' · ' + Math.round(parseFloat(_ligne.duree) * 10) / 10 + ' {{s}}')
  }
  if (isset(_ligne.jetons) && isset(_ligne.jetons.total)) {
    var invite = init(_ligne.jetons.invite, 0)
    var reponse = init(_ligne.jetons.reponse, 0)
    var total = init(_ligne.jetons.total, 0)
    k2000beTexte(entete, '', ' · ' + total + ' {{jetons}}' + k2000beCache(_ligne.jetons.cache)).title =
      '{{Invite}} : ' + invite + ' · {{réponse}} : ' + reponse
  }
  bloc.appendChild(entete)

  k2000beTexte(bloc, 'k2000beJournalDemande', init(_ligne.demande, '')).style.display = 'block'
  k2000beTexte(bloc, 'k2000beJournalReponse', init(_ligne.reponse, '')).style.display = 'block'

  /* Mêmes étapes, même règle que dans la frise : repliées quand le tour a
     abouti, ouvertes quand il a échoué — et deux cents lignes de journal sans
     cela sont illisibles. */
  var etapes = k2000beEtapesNode(_ligne.etapes, _ligne.statut)
  if (etapes !== null) { bloc.appendChild(etapes) }

  return bloc
}

/*
 * La part des jetons d'invite servie depuis le cache d'OpenAI, à accoler au
 * total : « (dont 1200 en cache) ». Elle est déjà comprise dans le total, d'où
 * le « dont » — l'additionner serait compter deux fois. Rien quand elle est
 * nulle ou absente : les lignes écrites avant qu'on la relève n'en portent pas,
 * et un « dont 0 » sur chaque demande n'apprendrait rien à personne.
 */
function k2000beCache(_cache) {
  var cache = parseInt(init(_cache, 0), 10) || 0
  if (cache <= 0) { return '' }
  return ' ({{dont}} ' + cache + ' {{en cache}})'
}

/*
 * Le bilan des lignes affichées : combien de demandes, ce qu'elles ont coûté,
 * ce qu'elles ont duré en moyenne.
 *
 * Le coût était écrit demande par demande, et nulle part additionné : personne
 * ne fait la somme de deux cents lignes pour savoir si le mois dérape. Les
 * lignes de service — un journal écourté n'est pas une demande — sont laissées
 * de côté, sans quoi le compte annoncerait une demande de plus que la réalité.
 */
function k2000beBilanNode(_lignes) {
  var demandes = 0
  var jetons = 0
  var cache = 0
  var duree = 0
  for (var i = 0; i < _lignes.length; i++) {
    if (String(init(_lignes[i].statut, '')) === 'TRONQUE') { continue }
    demandes++
    if (isset(_lignes[i].jetons) && isset(_lignes[i].jetons.total)) {
      jetons += parseInt(init(_lignes[i].jetons.total, 0), 10) || 0
      cache += parseInt(init(_lignes[i].jetons.cache, 0), 10) || 0
    }
    if (is_numeric(_lignes[i].duree)) { duree += parseFloat(_lignes[i].duree) }
  }
  if (demandes === 0) { return null }

  var bilan = document.createElement('div')
  bilan.className = 'help-block'
  bilan.style.marginBottom = '8px'
  var moyenne = Math.round((duree / demandes) * 10) / 10
  bilan.textContent = demandes + ' {{demandes}} · ' + jetons + ' {{jetons}}' + k2000beCache(cache) +
    ' · ' + moyenne + ' {{s en moyenne}}'
  bilan.title = '{{Sur les seules lignes affichées ici, et non sur tout le journal.}}'
  return bilan
}

/*
 * Le journal, tel que le serveur l'a rendu.
 *
 * Le filtre de statut n'est plus appliqué ici : il l'était, sur les seules
 * lignes déjà reçues, et « montre-moi les erreurs » répondait « aucune » à une
 * installation qui en avait — la dernière datait d'avant-hier, hors des
 * cinquante lignes chargées. C'est le journal qu'il faut filtrer, pas l'écran,
 * et c'est donc k2000beJournal::historique() qui le fait.
 */
function k2000beRenderHistorique() {
  var box = k2000beEl('div_k2000beHistorique')
  if (box === null) { return }
  box.innerHTML = ''

  var selecteur = k2000beEl('sel_k2000beStatut')
  var statut = (selecteur === null) ? '' : String(selecteur.value)

  if (k2000beJournalLignes.length === 0) {
    var vide = document.createElement('div')
    vide.className = 'help-block'
    vide.textContent = (statut === '')
      ? '{{Aucune demande enregistrée pour cet assistant.}}'
      : '{{Aucune demande de ce statut dans le journal. La recherche est allée aussi loin que la rétention le permet.}}'
    box.appendChild(vide)
    return
  }

  var bilan = k2000beBilanNode(k2000beJournalLignes)
  if (bilan !== null) { box.appendChild(bilan) }

  var fragment = document.createDocumentFragment()
  for (var l = 0; l < k2000beJournalLignes.length; l++) {
    fragment.appendChild(k2000beLigneJournalNode(k2000beJournalLignes[l]))
  }
  box.appendChild(fragment)
}

function k2000beChargerHistorique() {
  var id = k2000beCurrentId()
  var box = k2000beEl('div_k2000beHistorique')
  if (box === null) { return }
  box.innerHTML = ''
  k2000beJournalLignes = []

  if (id === '') {
    var aide = document.createElement('div')
    aide.className = 'help-block'
    aide.textContent = '{{Enregistrez l\'assistant pour qu\'il ait un journal.}}'
    box.appendChild(aide)
    return
  }

  var limite = k2000beEl('sel_k2000beLimite')
  var statut = k2000beEl('sel_k2000beStatut')
  k2000beAjax('historique', {
    id: id,
    limite: (limite === null) ? 50 : limite.value,
    statut: (statut === null) ? '' : statut.value
  }, function (result) {
    /* Le journal d'un autre assistant n'a rien à faire ici : la même garde que
       partout ailleurs, pour la même raison. */
    if (k2000beCurrentId() !== String(id)) { return }
    k2000beJournalLignes = is_array(result) ? result : []
    k2000beRenderHistorique()
  })
}

/* ============================================================== CHARGEMENT */

/*
 * Le mode de sécurité, dit là où l'on parle à l'assistant.
 *
 * Il ne vivait que dans l'onglet Équipement, sous une pastille qu'on ne
 * regarde pas en écrivant. Or en « simulation » — le réglage livré par défaut —
 * rien ne part vers la maison : l'assistant répond « c'est fait », et c'est
 * faux. Un écran qui laisse croire le contraire ferait chercher une panne de
 * domotique là où il n'y a qu'un interrupteur logiciel.
 */
function k2000beRenderMode(_mode) {
  var bandeau = k2000beEl('div_k2000beModeBandeau')
  if (bandeau === null) { return }
  bandeau.innerHTML = ''

  var mode = String(init(_mode, ''))
  if (mode !== 'simulation' && mode !== 'lecture') {
    bandeau.style.display = 'none'
    return
  }
  bandeau.style.display = ''

  var alerte = document.createElement('div')
  alerte.className = 'alert alert-info'
  alerte.style.marginBottom = '0'

  var icone = document.createElement('i')
  icone.className = (mode === 'simulation') ? 'fas fa-flask' : 'fas fa-glasses'
  alerte.appendChild(icone)
  alerte.appendChild(document.createTextNode(' '))

  var fort = document.createElement('b')
  fort.textContent = (mode === 'simulation') ? '{{Mode simulation.}}' : '{{Mode lecture seule.}}'
  alerte.appendChild(fort)
  alerte.appendChild(document.createTextNode(' '))

  k2000beTexte(alerte, '', (mode === 'simulation')
    ? '{{Rien ne part vers la maison : l\'assistant raconte ce qu\'il aurait fait, et le journal en garde la trace. Le mode se change dans la configuration du plugin.}}'
    : '{{Aucune action ne partira : l\'assistant peut consulter l\'état de la maison, rien de plus, quelles que soient les autorisations. Le mode se change dans la configuration du plugin.}}')

  bandeau.appendChild(alerte)
}

function k2000beRenderEtat(_data) {
  var configure = k2000beEl('span_k2000beConfigure')
  if (configure !== null) {
    var ok = (_data.configure === true || _data.configure == 1)
    configure.className = 'label label-' + (ok ? 'success' : 'danger')
    configure.textContent = ok ? '{{renseignée}}' : '{{absente}}'
  }

  var valeur = String(init(_data.mode, ''))
  /* Retenu AVANT les bandeaux et les compteurs, qui le lisent tous les trois. */
  k2000beMode = valeur
  var mode = k2000beEl('span_k2000beMode')
  if (mode !== null) {
    var libelle = '{{inconnu}}'
    var niveau = 'default'
    if (valeur === 'lecture') { libelle = '{{Lecture seule}}'; niveau = 'info' }
    if (valeur === 'simulation') { libelle = '{{Simulation}}'; niveau = 'warning' }
    if (valeur === 'actions') { libelle = '{{Actions réelles}}'; niveau = 'success' }
    mode.className = 'label label-' + niveau
    mode.textContent = libelle
  }
  k2000beRenderMode(valeur)

  var modele = k2000beEl('span_k2000beModele')
  if (modele !== null) { modele.textContent = String(init(_data.modele, '-')) }

  if (isset(_data.lecture_defaut)) {
    var nouveau = (_data.lecture_defaut === true || _data.lecture_defaut == 1)
    var change = (nouveau !== k2000beLectureDefaut)
    k2000beLectureDefaut = nouveau
    k2000beRenderLectureDefaut()
    /* Le réglage a bougé depuis le dernier chargement : les lignes déjà
       affichées éclairent le mauvais bouton sur tout ce qui est hérité. */
    if (change && k2000beArbreData !== null) { k2000beRenderArbre() }
  }

  /* L'arbre, quand il a été chargé, est plus frais que ces chiffres : il porte
     les clics qui viennent d'être faits et que le serveur, lui, n'a pas
     renvoyés. Les compteurs locaux priment donc. */
  if (k2000beArbreData !== null) {
    k2000beRenderCompteurs()
    return
  }
  k2000beRenderResume(_data.compteurs)
  k2000beRenderAucuneAutorisation(_data.compteurs)
}

function k2000beCharger(_id) {
  if (_id === '') { return }
  k2000beAjax('data', { id: _id }, function (result) {
    /* L'écran a pu changer d'assistant pendant l'appel : écrire la réponse
       d'un autre dans la page en cours serait pire que ne rien écrire. */
    if (k2000beCurrentId() !== String(_id)) { return }
    k2000beRenderEtat(result)
    k2000beRenderTours(init(result.tours, []))
    k2000beRenderAttente(result.attente)
  })
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  /* Le cœur ne réinitialise que les .eqLogicAttr : sans cela, tout le reste de
     l'écran garderait l'état de l'assistant précédemment ouvert — y compris
     une confirmation en attente qui ne concerne plus personne. */
  k2000beTravailArreter('')
  k2000beRenderAttente(null)
  k2000beRenderTours([])

  var historique = k2000beEl('div_k2000beHistorique')
  if (historique !== null) { historique.innerHTML = '' }
  k2000beJournalLignes = []

  /* Les pastilles d'état reviennent à « inconnu » plutôt qu'à « absente » :
     tant que le serveur n'a pas répondu, on ne sait rien, et annoncer une clé
     API manquante qu'on n'a pas vérifiée enverrait chercher une panne
     imaginaire. */
  var configure = k2000beEl('span_k2000beConfigure')
  if (configure !== null) {
    configure.className = 'label label-default'
    configure.textContent = '{{inconnue}}'
  }
  var mode = k2000beEl('span_k2000beMode')
  if (mode !== null) {
    mode.className = 'label label-default'
    mode.textContent = '-'
  }
  var modele = k2000beEl('span_k2000beModele')
  if (modele !== null) { modele.textContent = '-' }
  var resume = k2000beEl('span_k2000beResume')
  if (resume !== null) { resume.textContent = '-' }

  /* Les bandeaux de la Discussion disent quelque chose de vrai ou ne disent
     rien : tant que le serveur n'a pas répondu, ils se taisent. Le mode retenu
     repart vide pour la même raison — celui de l'assistant précédent ne dit
     rien de celui-ci. */
  k2000beMode = ''
  k2000beRenderMode('')
  k2000beRenderAucuneAutorisation(null)
  k2000beDerniereDemande = ''

  if (isset(_eqLogic) && isset(_eqLogic.id) && _eqLogic.id !== '') {
    k2000beCharger(String(_eqLogic.id))
  }
}

function addCmdToTable(_cmd) {
  var table = k2000beEl('table_cmd')
  if (table === null) { return }
  if (!isset(_cmd)) { _cmd = { configuration: {} } }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {} }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure" title="{{Configuration avancée de la commande}}"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table génère un <tbody> par
     insertion, et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  table.querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
   quand ce script s'exécute. Les écouteurs sont donc posés par délégation sur
   un conteneur qui, lui, existe déjà. */
var k2000beContainer = document.getElementById('div_pageContainer') || document.body

k2000beContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null) { return }

  /* ------------------------------------------------------- discussion */
  var envoyer = target.closest('#bt_k2000beAsk')
  if (envoyer !== null) {
    _event.preventDefault()
    /* Le bouton est éteint pendant le travail : il ne reproche rien, il ne
       répond simplement pas. */
    if (envoyer.classList.contains('disabled')) { return }
    var champ = k2000beEl('in_k2000beDemande')
    if (champ !== null) { k2000beAsk(champ.value) }
    return
  }
  if (target.closest('#bt_k2000beAbandonner') !== null) {
    _event.preventDefault()
    k2000beAbandonner()
    return
  }
  if (target.closest('#bt_k2000beVersDroits') !== null) {
    _event.preventDefault()
    /* Un clic natif sur l'onglet : le cœur y a posé son propre écouteur, et
       c'est lui qui sait basculer. Le nôtre, plus bas, chargera l'arbre. */
    var onglet = k2000beEl('bt_k2000beTabDroits')
    if (onglet !== null) { onglet.click() }
    return
  }
  var resumeEtapes = target.closest('.k2000beEtapesResume')
  if (resumeEtapes !== null) {
    _event.preventDefault()
    k2000beBasculerEtapes(resumeEtapes)
    return
  }
  var exemple = target.closest('.k2000beExemple')
  if (exemple !== null) {
    _event.preventDefault()
    var saisie = k2000beEl('in_k2000beDemande')
    /* L'exemple remplit la zone de saisie plutôt que de partir tout seul :
       une demande coûte des jetons, et un clic curieux ne doit pas éteindre
       la maison. */
    if (saisie !== null) {
      saisie.value = exemple.textContent.trim()
      saisie.focus()
    }
    return
  }
  if (target.closest('#bt_k2000beConfirmer') !== null) {
    _event.preventDefault()
    if (target.closest('#bt_k2000beConfirmer').classList.contains('disabled')) { return }
    k2000beConfirmer(true)
    return
  }
  if (target.closest('#bt_k2000beAnnuler') !== null) {
    _event.preventDefault()
    k2000beConfirmer(false)
    return
  }
  if (target.closest('#bt_k2000beReset') !== null) {
    _event.preventDefault()
    k2000beReset()
    return
  }

  /* ---------------------------------------------------- autorisations */
  var piece = target.closest('.k2000bePieceTitre')
  if (piece !== null) {
    _event.preventDefault()
    k2000beBasculerPiece(piece)
    return
  }
  var bouton = target.closest('.k2000bePol')
  if (bouton !== null) {
    _event.preventDefault()
    var ligne = bouton.closest('.k2000beCmdLigne')
    if (ligne === null) { return }
    if (bouton.hasAttribute('data-politique')) {
      k2000beDefinirPolitique(ligne, bouton.getAttribute('data-politique'))
    } else {
      k2000beDefinirLecture(ligne, bouton.getAttribute('data-lecture'))
    }
    return
  }
  /* Le sélecteur de commande du cœur plutôt qu'un identifiant à recopier à la
     main : personne ne connaît par cœur le numéro de l'état de son alarme. */
  if (target.closest('#bt_k2000beChoisirArmement') !== null) {
    _event.preventDefault()
    if (typeof jeedom === 'undefined' || !isset(jeedom.cmd) || !isset(jeedom.cmd.getSelectModal)) { return }
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(result.cmd) || !isset(result.cmd.id) || result.cmd.id === '') { return }
      var id = parseInt(String(result.cmd.id).replace('#', ''), 10)
      if (isNaN(id) || id <= 0) { return }
      var champ = document.querySelector('.eqLogicAttr[data-l2key="alerte_si"]')
      if (champ === null) { return }
      champ.value = String(id)
      /* Le cœur écoute « change » pour savoir qu'il reste quelque chose à
         sauvegarder : une valeur posée par un script ne l'émet pas. */
      champ.dispatchEvent(new Event('change', { bubbles: true }))
    })
    return
  }
  if (target.closest('#bt_k2000beArbreRecharger') !== null) {
    _event.preventDefault()
    k2000beChargerArbre(true)
    return
  }
  if (target.closest('#bt_k2000beRechercheReset') !== null) {
    _event.preventDefault()
    var recherche = k2000beEl('in_k2000beRecherche')
    if (recherche !== null) { recherche.value = '' }
    k2000beRenderArbre()
    return
  }
  if (target.closest('#bt_k2000beFiltreActions') !== null) {
    _event.preventDefault()
    k2000beFiltreActions = !k2000beFiltreActions
    k2000beRenderFiltres()
    k2000beRenderArbre()
    return
  }
  if (target.closest('#bt_k2000beFiltreAutorisees') !== null) {
    _event.preventDefault()
    k2000beFiltreAutorisees = !k2000beFiltreAutorisees
    k2000beRenderFiltres()
    k2000beRenderArbre()
    return
  }
  if (target.closest('#bt_k2000beDeplier') !== null || target.closest('#bt_k2000beReplier') !== null) {
    _event.preventDefault()
    var deplier = (target.closest('#bt_k2000beDeplier') !== null)
    var titres = document.querySelectorAll('#div_k2000beArbre .k2000bePieceTitre')
    for (var s = 0; s < titres.length; s++) {
      k2000beBasculerPiece(titres[s], deplier)
    }
    return
  }

  /* ------------------------------------------------------- historique */
  if (target.closest('#bt_k2000beHistoriqueRecharger') !== null) {
    _event.preventDefault()
    k2000beChargerHistorique()
    return
  }

  /* --------------------------------------------- chargement à l'ouverture */
  /* L'arbre et le journal ne sont demandés qu'au moment où on les regarde :
     l'arbre peut peser plusieurs milliers d'entrées, et ouvrir l'onglet
     « Équipement » n'a aucune raison de les payer. */
  if (target.closest('#bt_k2000beTabDroits') !== null) {
    k2000beRenderFiltres()
    k2000beChargerArbre(false)
    return
  }
  if (target.closest('#bt_k2000beTabHistorique') !== null) {
    k2000beChargerHistorique()
    return
  }
  if (target.closest('#bt_k2000beTabDiscussion') !== null) {
    var saisieOnglet = k2000beEl('in_k2000beDemande')
    if (saisieOnglet !== null) { saisieOnglet.focus() }
  }
})

k2000beContainer.addEventListener('change', function (_event) {
  if (_event.target === null) { return }

  var masquer = _event.target.closest('.k2000beMasquer')
  if (masquer !== null) {
    var bloc = masquer.closest('.k2000beEquipement')
    if (bloc !== null) { k2000beDefinirMasque(bloc, masquer.checked) }
    return
  }
  if (_event.target.id === 'sel_k2000beLimite') {
    k2000beChargerHistorique()
    return
  }
  /* Le statut est une question posée au journal, pas un tri de l'écran : il se
     redemande au serveur, comme la limite. */
  if (_event.target.id === 'sel_k2000beStatut') {
    k2000beChargerHistorique()
  }
})

k2000beContainer.addEventListener('input', function (_event) {
  if (_event.target === null || _event.target.id !== 'in_k2000beRecherche') { return }
  if (k2000beRechercheTimer !== null) { clearTimeout(k2000beRechercheTimer) }
  k2000beRechercheTimer = setTimeout(function () {
    k2000beRechercheTimer = null
    k2000beRenderArbre()
  }, K2000BE_DELAI_RECHERCHE)
})

/*
 * Le clavier.
 *
 * Tout ce que l'écran offre au clic doit s'atteindre à la tabulation et se
 * déclencher à l'Entrée ou à l'Espace : les en-têtes de pièce et les boutons de
 * politique sont des <div> et des <a> sans href, que le navigateur ignore
 * autrement. Échap referme ce qu'on vient d'ouvrir, et vide la recherche.
 */
k2000beContainer.addEventListener('keydown', function (_event) {
  var cible = _event.target
  if (cible === null) { return }

  if (cible.id === 'in_k2000beDemande') {
    if (_event.key === 'Enter') {
      _event.preventDefault()
      /* Rien à reprocher : pendant une demande, la touche ne fait rien. */
      if (k2000beEnCours) { return }
      k2000beAsk(cible.value)
    }
    return
  }

  if (cible.id === 'in_k2000beRecherche') {
    if (_event.key === 'Escape' || _event.key === 'Esc') {
      _event.preventDefault()
      cible.value = ''
      k2000beRenderArbre()
    }
    return
  }

  var actionne = (_event.key === 'Enter' || _event.key === ' ' || _event.key === 'Spacebar')
  var ferme = (_event.key === 'Escape' || _event.key === 'Esc')

  var titre = cible.closest('.k2000bePieceTitre')
  if (titre !== null) {
    if (actionne) {
      /* L'Espace ferait défiler la page sous l'en-tête qu'on vient d'ouvrir. */
      _event.preventDefault()
      k2000beBasculerPiece(titre)
      return
    }
    if (ferme) {
      _event.preventDefault()
      k2000beBasculerPiece(titre, false)
    }
    return
  }

  var etapes = cible.closest('.k2000beEtapesResume')
  if (etapes !== null && actionne) {
    _event.preventDefault()
    k2000beBasculerEtapes(etapes)
    return
  }

  /* Confirmer et Annuler sont les deux boutons qui engagent la maison : ils
     doivent s'atteindre au clavier comme les autres. */
  var confirmation = cible.closest('#bt_k2000beConfirmer')
  if (confirmation !== null && actionne) {
    _event.preventDefault()
    if (confirmation.classList.contains('disabled')) { return }
    k2000beConfirmer(true)
    return
  }
  if (cible.closest('#bt_k2000beAnnuler') !== null && actionne) {
    _event.preventDefault()
    k2000beConfirmer(false)
    return
  }

  var politique = cible.closest('.k2000bePol')
  if (politique !== null && actionne) {
    _event.preventDefault()
    var ligne = politique.closest('.k2000beCmdLigne')
    if (ligne === null) { return }
    if (politique.hasAttribute('data-politique')) {
      k2000beDefinirPolitique(ligne, politique.getAttribute('data-politique'))
    } else {
      k2000beDefinirLecture(ligne, politique.getAttribute('data-lecture'))
    }
  }
})
