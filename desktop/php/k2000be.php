<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('k2000be');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<style>
	/*
	 * Aucune couleur en dur : la page doit rester lisible sur le thème clair
	 * comme sur le thème sombre, et le rouge de K2000 est le seul accent qu'on
	 * s'autorise — encore faut-il qu'il ne soit pas le seul porteur de sens.
	 */

	/* ------------------------------------------------------------ BALAYAGE */

	/*
	 * Au repos, le balayage n'occupe rien.
	 *
	 * Une gouttière grise de six pixels affichée en permanence se lit comme une
	 * barre de progression restée à zéro : elle annonce une attente là où il
	 * n'y en a aucune, et le jour où elle s'anime vraiment, plus personne ne la
	 * regarde. Elle n'apparaît donc qu'avec le travail qu'elle décrit.
	 */
	#div_k2000beScanner {
		position: relative;
		height: 0;
		margin: 0;
		border-radius: var(--border-radius);
		background: var(--btnEq-default-color);
		overflow: hidden;
	}

	#div_k2000beScanner.k2000beScanOn {
		height: 6px;
		margin: 0 0 10px 0;
	}

	#div_k2000beScanner .k2000beScanBar {
		position: absolute;
		top: 0;
		left: 0;
		width: 22%;
		height: 100%;
		border-radius: var(--border-radius);
		background: linear-gradient(90deg, rgba(200, 30, 30, 0) 0%, var(--al-danger-color) 50%, rgba(200, 30, 30, 0) 100%);
		opacity: 0;
	}

	/* L'animation n'existe que pendant l'attente : une barre qui balaie en
	   permanence finit par devenir un bruit de fond qu'on ne voit plus, et
	   qu'on ne croit plus le jour où elle dit quelque chose. */
	#div_k2000beScanner.k2000beScanOn .k2000beScanBar {
		opacity: 1;
		animation: k2000beSweep 1.4s ease-in-out infinite alternate;
	}

	@keyframes k2000beSweep {
		from { left: -22%; }
		to   { left: 100%; }
	}

	/* Un utilisateur qui a demandé moins d'animations est pris au mot : le
	   balayage reste alors allumé, sans se déplacer. */
	@media (prefers-reduced-motion: reduce) {
		#div_k2000beScanner.k2000beScanOn .k2000beScanBar {
			animation: none;
			left: 39%;
		}
	}

	/* ----------------------------------------------------------- DISCUSSION */
	#div_k2000beChat {
		max-height: 46vh;
		overflow-y: auto;
		padding: 6px;
		border: 1px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
		background: var(--form-bg-color);
	}

	.k2000beTour {
		margin-bottom: 10px;
	}

	.k2000beBulle {
		display: inline-block;
		max-width: 88%;
		padding: 6px 10px;
		border-radius: var(--border-radius);
		background: var(--btnEq-default-color);
		white-space: pre-wrap;
		word-break: break-word;
	}

	.k2000beTour.k2000beUser {
		text-align: right;
	}

	/*
	 * Ce qui distingue les deux bulles est une bordure, pas un aplat.
	 *
	 * Le blanc écrit en dur sur --al-info-color tenait par accident : cette
	 * variable est claire sur le thème clair, et la bulle devenait du blanc sur
	 * du blanc. Une couleur de texte ne peut être décidée ici que si la couleur
	 * de fond l'est aussi, et le commentaire en tête de cette feuille dit
	 * justement qu'on ne les décide pas. La bordure, elle, tient sur les deux
	 * thèmes sans rien imposer au texte.
	 */
	.k2000beTour.k2000beUser .k2000beBulle {
		text-align: left;
		border-left: 3px solid var(--al-info-color);
	}

	.k2000beTour.k2000beBot .k2000beBulle {
		border-left: 3px solid var(--btnEq-default-color);
	}

	/* Une réponse qui est en réalité une panne se lit sur la bulle : elle est la
	   seule trace qui reste une fois l'écran quitté. Le sélecteur est plus
	   précis que celui de la bulle ordinaire juste au-dessus, sans quoi la
	   bordure grise l'emporterait et l'erreur ne se verrait plus. */
	.k2000beTour.k2000beBot .k2000beBulle.k2000beBulleErreur {
		border-left: 3px solid var(--al-danger-color);
	}

	.k2000beTourDate {
		display: block;
		font-size: .78em;
		opacity: .6;
		margin-bottom: 2px;
	}

	/* ------------------------------------------------------------- ÉTAPES */
	.k2000beEtapes {
		margin: 4px 0 0 0;
		padding: 4px 8px;
		border-left: 3px solid var(--btnEq-default-color);
		font-size: .85em;
	}

	.k2000beEtape {
		display: flex;
		align-items: baseline;
		gap: 6px;
		line-height: 1.5;
	}

	.k2000beEtape .k2000beEtapeIcone {
		flex: 0 0 auto;
		width: 14px;
		text-align: center;
	}

	.k2000beEtape .k2000beEtapeTitre {
		font-weight: 600;
	}

	.k2000beEtape .k2000beEtapeDetail {
		opacity: .75;
	}

	.k2000beEtape .k2000beEtapeMot {
		opacity: .7;
	}

	.k2000beEtape .k2000beEtapeOutil {
		opacity: .5;
		font-size: .9em;
	}

	/* La puce qui replie les étapes. Elle doit se voir comme une commande, et
	   porter un contour au clavier : sans lui, la tabulation traverse la page
	   sans qu'on sache où l'on est. */
	.k2000beEtapesResume {
		display: inline-block;
		cursor: pointer;
		opacity: .75;
	}

	.k2000beEtapesResume:hover,
	.k2000beEtapesResume:focus {
		opacity: 1;
		text-decoration: none;
		outline: 1px dotted var(--txt-color);
	}

	.k2000beEtapesResume .k2000beEtapesAlerte {
		color: var(--al-danger-color);
	}

	.k2000beEtapesCorps {
		margin-top: 2px;
	}

	/* --------------------------------------------------------- CONFIRMATION */
	#div_k2000beAttente {
		margin-bottom: 10px;
	}

	#div_k2000beAttente ul {
		margin: 6px 0 8px 0;
		padding-left: 20px;
	}

	/* Le décompte est la seule chose du bandeau qui bouge : il se lit sous les
	   boutons, sans peser plus que ce qu'il dit. */
	#span_k2000beAttenteDecompte {
		margin: 6px 0 0 0;
	}

	/* -------------------------------------------------------------- CLAVIER */

	/* Rien de ce qui se clique ne doit être invisible au clavier. Le cœur ne
	   dessine pas de contour sur un <a> sans href ni sur un <div> rendu
	   focalisable : c'est donc ici. */
	.k2000bePieceTitre:focus,
	.k2000bePol:focus {
		outline: 2px solid var(--al-info-color);
		outline-offset: 1px;
	}

	/* -------------------------------------------------------- AUTORISATIONS */
	.k2000bePiece {
		margin-bottom: 6px;
		border: 1px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
	}

	.k2000bePieceTitre {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 5px 8px;
		cursor: pointer;
		background: var(--btnEq-default-color);
		border-radius: var(--border-radius);
		font-weight: 600;
	}

	.k2000bePieceCorps {
		padding: 4px 8px 8px 8px;
	}

	.k2000beEquipement {
		padding: 5px 0;
		border-top: 1px solid var(--btnEq-default-color);
	}

	.k2000beEquipement:first-child {
		border-top: 0;
	}

	.k2000beEqTitre {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
	}

	.k2000beEqNom {
		font-weight: 600;
	}

	.k2000beEqType {
		opacity: .55;
		font-size: .85em;
	}

	.k2000beCmds {
		margin: 4px 0 0 14px;
	}

	.k2000beCmdLigne {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
		padding: 2px 0;
	}

	.k2000beCmdNom {
		flex: 1 1 220px;
		min-width: 160px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.k2000beCmdValeur {
		opacity: .6;
		font-size: .85em;
	}

	/* Un équipement masqué l'est entièrement : ses commandes n'ont plus de
	   politique utile tant qu'il l'est, et l'écran doit le montrer plutôt que
	   de laisser croire qu'un « Autorisée » y change encore quelque chose. */
	.k2000beEquipement.k2000beMasque .k2000beCmds {
		opacity: .4;
	}

	.k2000beSensible {
		color: var(--al-warning-color);
	}

	/*
	 * Le bouton enfoncé qu'on n'a pas choisi.
	 *
	 * Sur une installation dont le réglage général est « Lisible », le bouton
	 * Lisible était déjà vert sur les treize cents commandes d'information,
	 * cliquées ou non : cliquer ne changeait rien à l'écran, le clic passait
	 * pour perdu, et recliquer — le réflexe — rendait la commande à l'héritage
	 * sans le dire. Enfoncé et plein veut désormais dire « vous l'avez
	 * choisi » ; enfoncé, gris et pointillé, « c'est ce qui s'applique, mais
	 * cela vient du réglage général ».
	 */
	.k2000bePol.k2000beHerite {
		border-style: dashed;
		opacity: .8;
	}

	/*
	 * Trois classes du JS n'ont volontairement aucune règle ici :
	 * .k2000beMasquer, .k2000beDefaut et .k2000beAlerteSensible. Elles ne
	 * portent pas d'apparence — leurs éléments sont déjà une case à cocher, un
	 * label du cœur et un label-danger — mais servent de prise aux sélecteurs
	 * du script. Les chercher ici et ne rien trouver est normal.
	 */

	/* ------------------------------------------------------------ JOURNAL */
	.k2000beLigneJournal {
		margin-bottom: 8px;
		padding: 6px 8px;
		border: 1px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
	}

	.k2000beLigneJournal .k2000beJournalEntete {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
		font-size: .85em;
		opacity: .8;
		margin-bottom: 4px;
	}

	.k2000beLigneJournal .k2000beJournalDemande {
		font-weight: 600;
		white-space: pre-wrap;
	}

	.k2000beLigneJournal .k2000beJournalReponse {
		white-space: pre-wrap;
		margin-top: 2px;
	}
</style>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un assistant}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-robot"></i> {{Mes assistants}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun assistant pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Renseignez votre clé API OpenAI dans « Configuration » : sans elle, l\'assistant ne peut rien demander au modèle.}}</li>';
			echo '<li>{{Cliquez sur « Ajouter un assistant » et donnez-lui un nom, par exemple « KITT ».}}</li>';
			echo '<li>{{Dans l\'onglet « Autorisations », autorisez les quelques commandes que l\'assistant a le droit d\'employer. Tout est interdit tant que vous ne l\'avez pas dit.}}</li>';
			echo '<li>{{Dans l\'onglet « Discussion », écrivez votre première demande.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Ce plugin envoie le texte de vos demandes à OpenAI, ainsi que les noms et les valeurs des équipements que vous rendez visibles. Il n\'est ni affilié à OpenAI, ni parrainé par elle.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-robot" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#discussiontab" id="bt_k2000beTabDiscussion" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-comments"></i><span class="hidden-xs"> {{Discussion}}</span></a></li>
			<li role="presentation"><a href="#droitstab" id="bt_k2000beTabDroits" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-user-shield"></i><span class="hidden-xs"> {{Autorisations}}</span></a></li>
			<li role="presentation"><a href="#historiquetab" id="bt_k2000beTabHistorique" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-history"></i><span class="hidden-xs"> {{Historique}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{KITT}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Consignes de cet assistant}}</label>
								<div class="col-sm-8">
									<textarea class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="consignes" rows="6" placeholder="{{Quand on te demande une levée de doute : commence par…}}"></textarea>
									<span class="help-block" style="margin:6px 0 0 0;">{{Ce que CET assistant doit faire, et lui seul. Les autres assistants ne le voient pas. C'est ici qu'on spécialise : un assistant de levée de doute n'a pas la même marche à suivre qu'un assistant de maison. Ce texte est ajouté à la fin des consignes envoyées au modèle, après celles de la configuration du plugin, et prime donc sur elles. Il repart chez OpenAI à chaque demande de cet assistant : écrivez une marche à suivre, pas un roman. 2000 caractères au plus, coupés au dernier mot entier. Comme les consignes générales, c'est une instruction au modèle et non une barrière : ce que l'assistant a le droit de faire se règle dans l'onglet « Autorisations ».}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{États qui décident}}</label>
								<div class="col-sm-8">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="verdict_types" placeholder="ALARM_STATE">
									<span class="help-block" style="margin:6px 0 0 0;">{{Types génériques Jeedom, séparés par des virgules, huit au plus. Renseignés, ils donnent à CET assistant un outil de plus — « levée de doute » — dont le verdict est calculé par le plugin et non par le modèle : celui-ci ne reçoit plus des faits à peser, mais une conclusion à formuler. C'est la même règle que pour les actions, appliquée à la lecture. Exemple : ALARM_STATE ne retient que les détections croisées, celles qui ne se déclenchent que lorsque deux détections indépendantes se recoupent — un mouvement isolé devient alors du contexte, et ne peut plus faire conclure à une intrusion, quel qu'en soit le nombre. Trois verdicts : quelque chose est avéré, rien ne l'est, ou rien n'était surveillé — ce dernier n'est pas le calme, et l'assistant doit le dire. Laissé vide, l'outil n'existe pas et ne coûte rien.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Alerter tout seul}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="alerte_auto">
									<span class="help-block" style="margin:6px 0 0 0;">{{Réveille cet assistant dès qu'un des « états qui décident » ci-dessus bascule, sans scénario à écrire : il fait sa levée de doute seul et publie sa réponse, qu'un scénario n'a plus qu'à envoyer sur un téléphone. Le contrôle a lieu chaque minute — un listener du cœur serait immédiat, mais il s'exécuterait dans le processus du plugin qui vient de publier la valeur, et un tour de conversation dure des dizaines de secondes : ce serait bloquer un démon d'alarme pour gagner une minute. Sans état décisif déclaré, cette case ne fait rien.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{N'alerter que si}}</label>
								<div class="col-sm-8">
									<div class="input-group">
										<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="alerte_si" placeholder="{{aucune condition}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_k2000beChoisirArmement"><i class="fas fa-list"></i> {{Choisir}}</a>
										</span>
									</div>
									<span class="help-block" style="margin:6px 0 0 0;">{{L'état qui dit que la maison est armée — celui de votre alarme. Tant qu'il ne vaut pas « en marche », l'alerte automatique ne part pas : une détection de caméra n'a de sens que maison armée, et elle ne doit pas se payer en appels facturés le reste du temps. Tout ce qui n'est pas un oui franc vaut non : commande supprimée, jamais renseignée, valeur vide, plugin d'alarme pas encore installé. C'est voulu — désigner ici une alarme qui n'existe pas encore doit tout arrêter, pas tout lancer. Ce que la maison a fait pendant qu'elle était désarmée ne ressort pas au moment où on l'arme. Laissé vide, l'alerte part à toute heure. Ce garde-fou ne concerne QUE l'alerte automatique : une question que vous posez vous-même reçoit sa réponse dans tous les cas.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Repos entre deux alertes}}</label>
								<div class="col-sm-3">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="alerte_repos" placeholder="15">
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:6px 0 0 0;">{{Minutes, 15 par défaut, 1440 au plus. C'est ce qui rend l'alerte automatique utilisable : une seule règle de détection croisée peut tirer trente-huit fois dans une journée, et autant de demandes seraient facturées puis refusées par le verrou « une demande à la fois ». Pendant le repos, les basculements sont couverts par l'alerte précédente et ne ressortent pas à la fin.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État}}</legend>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Clé API}}</label>
								<div class="col-sm-8">
									<span id="span_k2000beConfigure" class="label label-default">{{inconnue}}</span>
									<a class="btn btn-default btn-xs eqLogicAction" data-action="gotoPluginConf" style="margin-left:8px;"><i class="fas fa-wrench"></i> {{Configuration du plugin}}</a>
									<span class="help-block" style="margin:6px 0 0 0;">{{Sans clé API, l'assistant répond qu'il ne peut rien faire : la clé est commune à tous les assistants et se règle une seule fois.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Mode de sécurité}}</label>
								<div class="col-sm-8">
									<span id="span_k2000beMode" class="label label-default">-</span>
									<span class="help-block" style="margin:6px 0 0 0;">{{« Lecture seule » : l'assistant consulte et ne fait rien. « Simulation » : il raconte ce qu'il aurait fait, sans rien envoyer. « Actions réelles » : il exécute ce que vous lui avez autorisé.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Modèle}}</label>
								<div class="col-sm-8">
									<span id="span_k2000beModele">-</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Autorisations}}</label>
								<div class="col-sm-8">
									<span id="span_k2000beResume">-</span>
									<span class="help-block" style="margin:6px 0 0 0;">{{Ces compteurs portent sur toute l'installation : les autorisations vivent dans les commandes surveillées, elles sont donc les mêmes pour tous les assistants. Ils suivent l'onglet « Autorisations » au clic près.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label"></label>
								<div class="col-sm-8">
									<a class="btn btn-default btn-sm" id="bt_k2000beReset"><i class="fas fa-eraser"></i> {{Nouvelle conversation}}</a>
									<span class="help-block" style="margin:6px 0 0 0;">{{Vide la mémoire de l'assistant. Utile quand il s'entête sur un contresens : une conversation neuve repart de l'état réel de la maison. Le journal, lui, est conservé.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DISCUSSION ======================== -->
			<div role="tabpanel" class="tab-pane" id="discussiontab">
				<br>
				<div class="col-xs-12">
					<span class="help-block" style="margin:0 0 8px 0;">{{Ici vit la mémoire de l'assistant : il se souvient de ce qui précède dans cette conversation, et « Nouvelle conversation » l'efface. Ce n'est pas la trace de ce qu'il a fait — celle-là est dans l'onglet « Historique », et elle survit à l'oubli.}}</span>

					<!--
						Le mode de sécurité, dit là où l'on parle. En « simulation » — le mode
						livré par défaut — rien ne part vers la maison : l'assistant répond
						« c'est fait », et c'est faux. Le dire ici évite de chercher une panne
						de domotique là où il n'y a qu'un interrupteur logiciel.
					-->
					<div id="div_k2000beModeBandeau" style="display:none;margin-bottom:10px;"></div>

					<!--
						Sur une installation neuve, aucune action n'est autorisée : toute
						demande d'action sera refusée, poliment, sans qu'on sache où porter
						remède. Ce fait n'était écrit que dans le bloc d'accueil de la liste,
						qui disparaît dès qu'un assistant existe — c'est-à-dire juste avant
						le moment où l'on en a besoin.
					-->
					<div id="div_k2000beAucuneAutorisation" style="display:none;margin-bottom:10px;">
						<div class="alert alert-warning" style="margin-bottom:0;">
							<i class="fas fa-user-shield"></i>
							<b>{{Aucune commande n'est encore autorisée.}}</b>
							{{L'assistant peut répondre à des questions, mais toute demande d'action sera refusée tant que vous n'aurez pas dit ce qu'il a le droit d'employer. « Confirmation » est le bon réglage pour tout ce qui ouvre, déverrouille ou désarme.}}
							<a class="btn btn-default btn-xs" id="bt_k2000beVersDroits" style="margin-left:6px;"><i class="fas fa-user-shield"></i> {{Ouvrir les autorisations}}</a>
						</div>
					</div>

					<!-- Décoratif : ce qu'il annonce est écrit en toutes lettres plus bas. -->
					<div id="div_k2000beScanner" aria-hidden="true"><div class="k2000beScanBar"></div></div>

					<div id="div_k2000beAttente" style="display:none;"></div>

					<div id="div_k2000beChat"></div>

					<div class="input-group" style="margin-top:10px;">
						<input type="text" class="form-control roundedLeft" id="in_k2000beDemande" placeholder="{{Quelle est la température dans les chambres ?}}" autocomplete="off">
						<span class="input-group-btn">
							<a class="btn btn-success roundedRight" id="bt_k2000beAsk"><i class="fas fa-paper-plane"></i> {{Demander}}</a>
						</span>
					</div>

					<!--
						Ce que fait l'assistant pendant qu'on attend. Zone vivante : sans
						aria-live, la page reste muette une minute entière pour qui ne la
						voit pas. Le bouton d'à côté rend la main sans attendre les quinze
						minutes du délai réseau.
					-->
					<div style="margin-top:6px;">
						<span id="span_k2000beTravail" class="help-block" style="margin:0;display:inline;" role="status" aria-live="polite"></span>
						<span id="span_k2000beTravailCompteur" class="help-block" style="margin:0;display:inline;" aria-hidden="true"></span>
						<a class="btn btn-default btn-xs" id="bt_k2000beAbandonner" style="display:none;margin-left:8px;" title="{{Cesse d'attendre la réponse dans cet écran. La demande, elle, continue dans la box.}}"><i class="fas fa-hourglass-end"></i> {{Cesser d'attendre}}</a>
					</div>

					<!--
						Les demandes de lecture en tête : sur une installation neuve rien
						n'est autorisé, et proposer d'abord « éteins tout » revient à offrir
						un bouton qui ne peut que se faire refuser.
					-->
					<div style="margin-top:8px;">
						<span class="help-block" style="margin:0 0 4px 0;">{{Pour commencer — ces demandes ne font que consulter :}}</span>
						<a class="btn btn-default btn-xs k2000beExemple">{{Quelle est la température dans les chambres ?}}</a>
						<a class="btn btn-default btn-xs k2000beExemple">{{Qu'est-ce qui est resté allumé ?}}</a>
						<a class="btn btn-default btn-xs k2000beExemple">{{Vérifie que tout est fermé.}}</a>
						<span class="help-block" style="margin:8px 0 4px 0;">{{Et celles-ci agissent, si vous les avez autorisées :}}</span>
						<a class="btn btn-default btn-xs k2000beExemple">{{Éteins tout au rez-de-chaussée.}}</a>
						<a class="btn btn-default btn-xs k2000beExemple">{{Je vais me coucher.}}</a>
					</div>

					<span class="help-block" style="margin-top:10px;">{{Une demande peut demander une minute : l'assistant consulte l'état de la maison, décide, exécute, puis vérifie. Chaque demande consomme des jetons OpenAI, et donc de l'argent. Les étapes repliées sous chaque réponse disent exactement ce qui a été tenté, ce qui a été refusé, et pourquoi ; elles s'ouvrent d'elles-mêmes quand le tour n'a pas abouti.}}</span>
				</div>
			</div>

			<!-- ======================== AUTORISATIONS ======================= -->
			<div role="tabpanel" class="tab-pane" id="droitstab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Cliquez sur une pièce pour l'ouvrir}}</b>{{, puis sur le réglage voulu à droite de chaque commande. C'est enregistré au clic : il n'y a rien à sauvegarder.}}
						<br>
						{{Toute commande d'action est interdite tant que vous ne l'avez pas autorisée : c'est le seul réglage qui décide vraiment de ce que l'assistant peut faire chez vous. « Confirmation » est le bon choix pour tout ce qui ouvre, déverrouille ou désarme — l'assistant demandera, vous trancherez.}}
						<br>
						{{Les états, eux, sont déjà lisibles : ils suivent le réglage général du plugin, ce que dit l'étiquette « réglage général » sur chaque ligne. Vous n'avez donc rien à faire pour que l'assistant les lise — ces deux boutons servent surtout à en MASQUER, et une commande masquée n'est pas même nommée au modèle. Cliquer « Lisible » ne fait que figer ce choix sur la commande, pour qu'il survive à un changement du réglage général ; recliquer dessus la rend au réglage général.}}
					</div>

					<div class="input-group" style="margin-bottom:8px;">
						<input class="form-control roundedLeft" id="in_k2000beRecherche" placeholder="{{Rechercher une pièce, un équipement, une commande}}" autocomplete="off">
						<div class="input-group-btn">
							<a class="btn" id="bt_k2000beRechercheReset" style="width:30px" title="{{Effacer la recherche}}"><i class="fas fa-times"></i></a>
							<a class="btn" id="bt_k2000beDeplier" title="{{Tout déplier}}"><i class="fas fa-angle-double-down"></i></a>
							<a class="btn roundedRight" id="bt_k2000beReplier" title="{{Tout replier}}"><i class="fas fa-angle-double-up"></i></a>
						</div>
					</div>

					<!--
						Les deux bascules. Sur une installation réelle — cent seize
						équipements, deux cent cinquante actions et près de treize cents
						états — les états noient les actions, et rien ne dit où sont les
						autorisations déjà posées. Ce sont elles qui rendent cet onglet
						praticable ; la recherche seule ne suffit pas, encore faut-il
						savoir quoi chercher.
					-->
					<div style="margin-bottom:8px;">
						<a class="btn btn-xs btn-default" id="bt_k2000beFiltreActions" role="button" tabindex="0" aria-pressed="false" title="{{Masque les commandes d'information. Ce sont elles qui sont les plus nombreuses, et ce ne sont pas elles qui décident de ce que l'assistant peut faire.}}"><i class="fas fa-bolt"></i> {{Seulement les actions}}</a>
						<a class="btn btn-xs btn-default" id="bt_k2000beFiltreAutorisees" role="button" tabindex="0" aria-pressed="false" title="{{Ne montre que ce que vous avez réglé à la main : les actions autorisées ou sous confirmation, et les états explicitement rendus lisibles. C'est la réponse à « qu'ai-je déjà ouvert ? ».}}"><i class="fas fa-check"></i> {{Seulement ce qui est autorisé}}</a>
					</div>

					<div style="margin-bottom:8px;">
						<span id="span_k2000beLectureDefaut" class="help-block" style="margin:0 0 6px 0;"></span>
						<span id="span_k2000beCompteurs"></span>
						<a class="btn btn-default btn-xs" id="bt_k2000beArbreRecharger" style="margin-left:8px;"><i class="fas fa-sync"></i> {{Recharger}}</a>
					</div>

					<div id="div_k2000beArbre"></div>

					<span class="help-block" style="margin-top:8px;">{{Chaque clic est enregistré tout de suite, dans la configuration de la commande concernée : il n'y a rien à sauvegarder, et ces réglages survivent à une désinstallation du plugin.}}</span>
				</div>
			</div>

			<!-- ========================== HISTORIQUE ======================== -->
			<div role="tabpanel" class="tab-pane" id="historiquetab">
				<br>
				<div class="col-xs-12">
					<span class="help-block" style="margin:0 0 8px 0;">{{La trace de tout ce qui a été demandé à cet assistant, y compris par un scénario, y compris ce qui a été refusé, avec le coût en jetons. Elle ne s'efface pas quand on vide la mémoire de la Discussion : celle-ci oublie, celle-là se souvient.}}</span>

					<div style="margin-bottom:8px;">
						<select class="form-control input-sm" id="sel_k2000beLimite" style="width:auto;display:inline-block;">
							<option value="25">{{25 dernières demandes}}</option>
							<option value="50" selected>{{50 dernières demandes}}</option>
							<option value="200">{{200 dernières demandes}}</option>
						</select>
						<!-- « La fois où ça a été refusé » est la question qu'on vient poser ici. -->
						<select class="form-control input-sm" id="sel_k2000beStatut" style="width:auto;display:inline-block;" title="{{Ne montrer que les demandes qui se sont terminées ainsi. La recherche porte sur tout le journal, et non sur les seules demandes déjà affichées.}}">
							<option value="">{{Tous les statuts}}</option>
							<option value="SUCCESS">{{fait}}</option>
							<option value="CONFIRMATION">{{confirmation demandée}}</option>
							<option value="REFUSED">{{refusé}}</option>
							<option value="LIMIT">{{limite atteinte}}</option>
							<option value="ERROR">{{erreur}}</option>
						</select>
						<a class="btn btn-default btn-sm" id="bt_k2000beHistoriqueRecharger"><i class="fas fa-sync"></i> {{Recharger}}</a>
					</div>

					<div id="div_k2000beHistorique"></div>

					<span class="help-block" style="margin-top:8px;">{{Le journal conserve chaque demande, même refusée, avec ce que l'assistant a tenté et ce qu'elle a coûté en jetons. Sa durée de rétention se règle dans la configuration du plugin ; les lignes plus anciennes sont supprimées chaque nuit.}}</span>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================= -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:250px;">{{Nom}}</th>
								<th style="width:160px;">{{Type}}</th>
								<th>{{Options}}</th>
								<th style="width:180px;">{{Valeur}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<span class="help-block">{{Ces commandes sont créées et entretenues par le plugin : elles sont ce qu'un scénario peut appeler ou surveiller. Quatre actions : « Demander » prend le texte de la demande comme message, « Confirmer » et « Annuler » répondent à une confirmation en attente, « Nouvelle conversation » vide la mémoire de l'assistant.}}</span>
					<span class="help-block">{{Sept informations portent le résultat du dernier tour : « Réponse », la phrase de l'assistant ; « Statut », comment le tour s'est terminé (fait, confirmation demandée, refusé, limite atteinte, erreur) ; « Actions exécutées », le nombre de commandes réellement envoyées, historisé ; « Jetons consommés », ce que le tour a coûté, historisé lui aussi — c'est la courbe de consommation du plugin, et de quoi prévenir quand un mois dérape ; « Dernière demande », la date du dernier échange ; « Confirmation en attente », à 1 quand l'assistant attend votre accord ; et « Objet de la confirmation », la phrase qui dit SUR QUOI il l'attend. C'est cette dernière qu'un scénario de notification doit lire : annoncer « K2000 attend votre accord » sans dire sur quoi pousse à confirmer sans savoir, exactement ce que la confirmation doit empêcher.}}</span>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'k2000be', 'js', 'k2000be'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
