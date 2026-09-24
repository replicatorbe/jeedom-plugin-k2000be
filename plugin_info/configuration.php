<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">

	<fieldset>
		<legend><i class="fas fa-key"></i> {{Accès à OpenAI}}</legend>

		<div class="alert alert-warning">
			<b>{{Ce qui part chez OpenAI, et rien d'autre}}</b><br>
			{{À chaque demande, le plugin envoie à l'API OpenAI : le texte que vous écrivez, les noms de vos pièces, les noms et types des équipements et des commandes que l'assistant a le droit de voir, et la valeur des états qu'il consulte. Il n'envoie ni votre clé Jeedom, ni vos identifiants, ni vos journaux, ni les équipements masqués, ni les commandes dont la lecture est refusée : celles-là ne sont pas même nommées. Les échanges sont conservés chez OpenAI selon les conditions de votre compte — si un équipement ou une pièce porte un nom que vous ne voudriez pas voir sortir de chez vous, masquez-le dans l'onglet « Autorisations ».}}
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Clé API OpenAI}}</label>
			<div class="col-md-3">
				<input type="password" class="configKey form-control" data-l1key="apikey" autocomplete="new-password" placeholder="sk-…">
			</div>
			<div class="col-md-5">
				<a class="btn btn-default btn-sm" id="bt_k2000beEssai"><i class="fas fa-plug"></i> {{Enregistrer et tester}}</a>
				<span id="span_k2000beEssai" style="margin-left:8px;"></span>
				<span class="help-block" style="margin:6px 0 0 0;">{{Le bouton enregistre toute la configuration, puis demande à OpenAI ce qu'il pense de cette clé : le verdict s'affiche à côté. Sans clé, le plugin ne fait rien — chaque demande est refusée avant même de partir. Elle se crée sur platform.openai.com, et l'usage de l'API est facturé à la consommation : ce n'est pas l'abonnement ChatGPT. Elle est chiffrée en base et dans les sauvegardes Jeedom, et masquée partout où le plugin journalise. Elle n'est pas pour autant cachée à l'interface : cette page la recharge dans le champ à chaque ouverture, où l'inspecteur du navigateur la lit en clair, et le cœur de Jeedom rend la configuration d'un plugin à tout compte connecté, administrateur ou non. Un compte Jeedom vaut donc un accès à cette clé. Si elle a pu sortir de chez vous, révoquez-la sur platform.openai.com et collez-en une neuve ici : une clé révoquée ne vaut plus rien, où qu'elle traîne.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Modèle}}</label>
			<div class="col-md-3">
				<input class="configKey form-control" data-l1key="model" placeholder="gpt-6-luna">
			</div>
			<div class="col-md-5">
				<a class="btn btn-default btn-sm" id="bt_k2000beModeles"><i class="fas fa-list"></i> {{Enregistrer et voir les modèles}}</a>
				<span id="span_k2000beModeles" style="margin-left:8px;"></span>
				<span class="help-block" style="margin:6px 0 0 0;">{{Un petit modèle répond vite et coûte peu, mais se trompe davantage sur une maison compliquée : il oublie une pièce, ou confond deux lampes. Un grand modèle raisonne mieux et coûte dix fois plus. Commencez petit, et changez si l'assistant vous déçoit. Laissé vide, le champ vaut gpt-6-luna : récent, et moins cher que gpt-4o-mini. Le plugin règle seul ce que chaque famille exige — le nom du plafond de jetons, la réflexion coupée, la température — et se rattrape si un modèle refuse quand même un paramètre, au prix d'un aller-retour refusé. Un modèle qui n'appelle d'outils qu'en réfléchissant d'abord (gpt-6-astra, les variantes codex et pro) ne convient pas : sur ce plugin, il échoue dès la première demande. Le bouton enregistre la configuration, puis demande à OpenAI la liste des modèles auxquels votre clé donne droit.}}</span>
				<div id="div_k2000beModeles" style="margin-top:6px;"></div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="timeout" placeholder="60">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Secondes par appel au modèle. Une demande en enchaîne plusieurs : une valeur trop courte transforme une réflexion qui aboutissait en « pas de réponse en N secondes », juste avant la réponse. Ce champ est borné : en dessous de 5 secondes c'est 5 qui s'applique, au-dessus de 300 c'est 300, et le journal du plugin écrit la ligne qui le dit — un champ ne doit pas laisser croire qu'on a réglé quelque chose. Un champ vide, ou un nombre nul ou négatif, reprend les 60 secondes livrées.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Longueur maximale d'une réponse}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="max_tokens" placeholder="1200">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{En jetons — environ trois quarts d'un mot chacun. Trop bas, la réponse est coupée en plein milieu d'une phrase : le statut vaut alors « limite atteinte » et la réponse le dit elle-même, plutôt que de rendre une demi-phrase pour une réussite. Mille deux cents suffisent largement à un assistant qui doit rester bref. Ce champ est le seul de cette page où zéro veut dire quelque chose : mettez-le à 0 pour ne pas plafonner du tout la réponse. Il n'a pas de plafond haut — c'est alors le modèle, et votre facture, qui décident.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-user-shield"></i> {{Sécurité}}</legend>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Mode de sécurité}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="securite">
					<option value="lecture">{{Lecture seule}}</option>
					<option value="simulation">{{Simulation}}</option>
					<option value="actions">{{Actions réelles}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{« Simulation » est le mode livré : l'assistant raconte ce qu'il aurait fait, le journal en garde la trace, mais aucune commande ne part — c'est le mode pour éprouver ses réglages sans réveiller la maison. « Lecture seule » : il répond à des questions et n'exécute rien, quoi qu'on lui demande et quelles que soient les autorisations. « Actions réelles » : il exécute ce que vous lui avez explicitement autorisé, et rien d'autre.}}</span>
				<div id="div_k2000beAvertissementActions" class="alert alert-warning" style="display:none;margin:6px 0 0 0;padding:6px 10px;">
					{{Dès l'enregistrement, l'assistant agira pour de bon sur la maison : les commandes que vous avez autorisées partiront sans vous être annoncées, sur sa seule interprétation de vos phrases. Seules les commandes réglées sur « Confirmation » vous demanderont encore votre accord. Quelques dizaines de demandes en simulation valent mieux qu'un volet qui se ferme sur un chat.}}
				</div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Lecture des états par défaut}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="lecture_defaut">
					<option value="1">{{Les états sont lisibles sauf refus explicite}}</option>
					<option value="0">{{Rien n'est lisible sans autorisation explicite}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Le premier choix rend l'assistant utile tout de suite : il voit les températures, les ouvertures, les consommations, et vous masquez au cas par cas ce qui vous gêne. Le second est plus strict mais laisse un assistant aveugle tant que vous n'avez pas ouvert les états un à un — il ne sait alors répondre à rien.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Outils par demande}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="max_tool_calls" placeholder="15">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Nombre maximum de consultations et d'exécutions pour une seule demande. C'est un garde-fou contre la boucle et contre la facture : atteint, l'assistant s'arrête, conclut avec ce qu'il sait, et le statut vaut « limite atteinte ». Quinze, parce que c'est l'échelle d'une demande large : « je vais me coucher », sur une maison d'une soixantaine d'équipements, dépense douze à dix-huit outils entre situer la maison, détailler quelques équipements, éteindre une demi-douzaine de choses et vérifier ce qui est parti. Trop bas, « éteins tout » s'arrête au milieu du salon. Ce sont des outils et non des allers-retours : plusieurs outils demandés dans le même message partent ensemble, en un seul appel facturé. Le champ est plafonné à 30 : au-delà, c'est 30 qui s'applique, et le journal du plugin écrit la ligne qui le dit. Un champ vide, ou un nombre nul ou négatif, reprend les 15 livrés.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Demandes par jour}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="max_demandes_jour" placeholder="0">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Nombre maximum de demandes facturées dans une journée, tous assistants confondus — la clé API est commune, la facture aussi. Au-delà, une demande est refusée sur-le-champ, avant tout appel à OpenAI : elle ne coûte rien. Le compte repart à minuit, et ne retient que les demandes qui ont réellement atteint le modèle : un refus ne se compte pas lui-même, faute de quoi la journée ne se rouvrirait jamais. Ce plafond n'est pas là pour vous rationner, mais pour arrêter l'emballement — un scénario branché sur « Demander » et déclenché par un capteur qui vibre facture jusqu'à ce que quelqu'un regarde, un ou deux jours plus tard. Le plafond d'OpenAI, lui, est mensuel et se compte en dollars. 0 — la valeur livrée — veut dire « pas de plafond » ; le champ est borné à 2000. Une confirmation en attente n'est jamais bloquée : elle reprend un tour déjà payé et à moitié joué sur la maison.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-brain"></i> {{Mémoire et journal}}</legend>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Échanges gardés en mémoire}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="memoire_tours" placeholder="12">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{C'est ce qui permet de dire « et dans la chambre ? » après une première question. Tout cet historique est renvoyé au modèle à chaque demande : le doubler double le coût de chaque phrase. Au-delà, les plus anciens échanges sont oubliés. Le champ est plafonné à 50 échanges : au-delà, ce sont 50 qui s'appliquent, et le journal du plugin écrit la ligne qui le dit. Un champ vide, ou un nombre nul ou négatif, reprend les 12 livrés. La mémoire est de toute façon coupée plus tôt si elle dépasse cent vingt messages ou soixante kilo-octets — un seul tour très bavard peut y suffire.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Rétention du journal}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="historique_jours" placeholder="30">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Jours. Le journal garde chaque demande, chaque refus et chaque exécution : c'est la seule trace de ce que l'assistant a fait chez vous, et le seul moyen de comprendre après coup pourquoi une lumière s'est éteinte. Les fichiers plus vieux sont supprimés chaque nuit. Une valeur nulle ou négative est ramenée à un jour : à zéro, la purge emporterait le journal du jour même, y compris la demande en cours d'écriture.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Résumé de la maison}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="contexte_maison">
					<option value="1">{{Joindre un résumé à chaque demande}}</option>
					<option value="0">{{Ne rien joindre}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Joint la liste des pièces et le nombre d'équipements visibles. L'assistant trouve alors du premier coup au lieu de chercher pièce par pièce : moins d'allers-retours, une réponse plus rapide, mais quelques jetons de plus sur chaque demande, même les plus simples.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-home"></i> {{La maison}}</legend>

		<div class="alert alert-info">
			{{Le plugin connaît vos équipements et leurs états ; il ne sait rien de la maison telle qu'on y vit. Sans ces quelques lignes, « je vais me coucher » et « j'ai froid » n'ont pas de réponse intelligente. Ne remplissez que ce qui change une décision : ces informations repartent chez OpenAI à chaque demande, pour toujours. Pas d'adresse — la ville de la box part déjà et suffit —, pas de prénoms ni d'âges nominatifs, pas de marque de véhicule : une ligne inutile est une donnée personnelle exposée en pure perte, et elle dilue les lignes utiles. Tout est facultatif, et chaque champ est limité à 300 caractères : au-delà, la suite est coupée au dernier mot. La fiche entière est par ailleurs plafonnée à 1500 caractères — les champs sont servis dans l'ordre de la page, et si vous remplissez les sept généreusement, les derniers seront écourtés puis abandonnés. Le bouton ci-dessous montre ce qui part réellement.}}
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Qui vit ici}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_foyer" maxlength="300" rows="2" placeholder="{{Deux adultes et un enfant de trois ans. L'un des deux travaille de nuit une semaine sur deux.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Des effectifs et des tranches d'âge, pas des prénoms. Cela change ce que veut dire préparer la nuit, et quelles pièces comptent : un enfant en bas âge, quelqu'un qui dort le jour, personne le mercredi.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Animaux}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_animaux" maxlength="300" rows="2" placeholder="{{Un chat, qui circule la nuit entre le salon et la cuisine.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Un chat qui circule la nuit explique des détections de mouvement que l'assistant prendrait autrement pour une présence, et interdit de fermer certaines portes.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Logement}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_logement" maxlength="300" rows="2" placeholder="{{Maison de deux étages. Véranda au sud, très chaude l'été. La chambre du fond est mal isolée.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Maison ou appartement, nombre d'étages, particularités qui expliquent un comportement : une véranda, une pièce mal isolée, un sous-sol froid.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Chauffage et eau chaude}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_chauffage" maxlength="300" rows="2" placeholder="{{Pompe à chaleur air-eau avec plancher chauffant, poêle à bois au salon. Ballon d'eau chaude en heures creuses.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{C'est ce qui décide de la réponse à « j'ai froid » : une pompe à chaleur, un poêle et des convecteurs n'appellent pas les mêmes gestes — l'une met des heures à monter, l'autre se recharge à la main.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Habitudes et horaires}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_habitudes" maxlength="300" rows="3" placeholder="{{Coucher vers 23 h, lever à 6 h 30 en semaine. Télétravail les mardis et jeudis. Maison vide du lundi au vendredi de 8 h à 17 h.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Heure de coucher et de lever, télétravail, absences régulières. C'est le champ le plus rentable de la fiche : il donne un sens à « je vais me coucher », « je pars » et « c'est bientôt l'heure ».}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{À ne jamais faire}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_interdits" maxlength="300" rows="2" placeholder="{{Ne jamais couper le congélateur du garage. Ne rien allumer dans la chambre des enfants après 21 h.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Les garde-fous de bon sens, dits une fois pour toutes. Ce sont des consignes au modèle, pas des règles de sécurité : elles n'ont aucune valeur contraignante, seules les autorisations de l'onglet « Autorisations » en ont une. Ce qui ne doit jamais partir se refuse là-bas, pas ici.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Véhicule électrique}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="maison_vehicule" maxlength="300" rows="2" placeholder="{{Une voiture électrique, rechargée la nuit en heures creuses, branchée en général vers 19 h.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{À ne remplir que si une borne de recharge est pilotée par Jeedom. La marque et le modèle ne décident de rien : ce qui compte est quand on recharge, et jusqu'où.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Ce qui part à chaque demande}}</label>
			<div class="col-md-8">
				<a class="btn btn-default btn-sm" id="bt_k2000beInvite"><i class="fas fa-eye"></i> {{Voir ce qui part à chaque demande}}</a>
				<span id="span_k2000beInvite" style="margin-left:8px;"></span>
				<span class="help-block" style="margin:6px 0 0 0;">{{Le bouton enregistre la configuration, puis affiche les consignes envoyées au modèle au début de chaque demande, telles quelles : la personnalité, la date, la ville de la box, les règles de conduite, le mode de sécurité, le résumé de la maison, la fiche ci-dessus et vos consignes supplémentaires. Ce texte part en entier à chaque phrase que vous écrivez — c'est le moyen de vérifier ce que vous envoyez, et ce qu'il en coûte. Le texte de votre demande, lui, et les résultats des outils, s'y ajoutent ensuite.}}</span>
				<pre id="div_k2000beInvite" style="display:none;margin-top:6px;max-height:340px;overflow:auto;white-space:pre-wrap;word-break:break-word;"></pre>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-comment-dots"></i> {{Personnalité}}</legend>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Ton}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="persona">
					<option value="kitt">{{KITT}}</option>
					<option value="sobre">{{Sobre}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{« KITT » répond en majordome courtois, direct, un brin formel — c'est agréable à lire et un peu plus long, donc un peu plus cher. « Sobre » répond en une phrase sèche, ce qui vaut mieux pour une annonce vocale ou une notification.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Consignes supplémentaires}}</label>
			<div class="col-md-5">
				<textarea class="configKey form-control" data-l1key="prompt_extra" rows="4" placeholder="{{La chambre du fond est celle des enfants : n'y allume jamais rien après 21 h.}}"></textarea>
			</div>
			<div class="col-md-3">
				<span class="help-block" style="margin:0;">{{Ajoutées telles quelles à la toute fin des consignes du modèle, après la fiche de la maison — elles peuvent donc la nuancer. C'est l'endroit de ce que la fiche ne prévoit pas : les pièges de vos noms d'équipements, une manière de répondre, un cas particulier. Ce sont des consignes, pas des règles : elles n'ont aucune valeur de sécurité, seules les autorisations en ont une.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-sliders-h"></i> {{Avancé}}</legend>

		<div class="alert alert-info">
			{{Ces deux réglages ne servent pas à l'usage courant : les valeurs livrées conviennent à une installation ordinaire, et les changer au jugé dégrade l'assistant plus sûrement qu'il ne l'améliore.}}
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresse de l'API}}</label>
			<div class="col-md-3">
				<input class="configKey form-control" data-l1key="base_url" placeholder="https://api.openai.com/v1">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{À ne changer que pour viser un service compatible avec l'API d'OpenAI — un relais d'entreprise, ou un modèle hébergé chez vous. La clé ci-dessus part vers l'adresse indiquée ici, et vers elle seule : la détourner revient à confier votre clé OpenAI à cet hôte, alors ne visez que des machines auxquelles vous la confieriez en toutes lettres. Une adresse fausse ne se voit qu'à la première demande, sous la forme d'un « OpenAI indisponible ».}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Température}}</label>
			<div class="col-md-2">
				<input type="number" step="0.1" min="0" max="2" class="configKey form-control" data-l1key="temperature" placeholder="0.3">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{0 rend le modèle prévisible : la même phrase donne le même geste, ce qui est exactement ce qu'on veut d'une domotique. Plus haut, il varie ses formulations — et ses décisions. Au-delà de 0,7, il se met à inventer des équipements qui n'existent pas.}}</span>
			</div>
		</div>
	</fieldset>
</form>

<script>
	/*
	 * Le JS de la page du plugin n'est pas chargé dans la fenêtre de
	 * configuration : ces deux boutons ont donc leur code ici, au plus près des
	 * champs qu'ils servent.
	 */
	(function () {
		/*
		 * L'avertissement du mode « Actions réelles ».
		 *
		 * Le cœur insère cette fenêtre d'abord et la remplit ensuite, par une
		 * requête dont il n'offre aucun rappel à un plugin : la valeur du champ
		 * n'est pas encore là quand ce script s'exécute. L'avertissement est donc
		 * revérifié quelques secondes, puis la surveillance s'arrête — le reste du
		 * temps, c'est le changement de choix qui la déclenche. Ce bloc est hors
		 * du verrou qui suit : il doit être réglé à chaque ouverture.
		 */
		window.k2000beAvertirSecurite = function () {
			var champ = document.querySelector('.configKey[data-l1key="securite"]')
			var boite = document.getElementById('div_k2000beAvertissementActions')
			if (champ === null || boite === null) { return }
			boite.style.display = (champ.value === 'actions') ? 'block' : 'none'
		}

		var k2000beGuets = 0
		var k2000beGuet = setInterval(function () {
			k2000beGuets++
			window.k2000beAvertirSecurite()
			if (k2000beGuets >= 20) { clearInterval(k2000beGuet) }
		}, 250)

		/*
		 * Les écouteurs sont posés sur le document, jamais sur les boutons : la
		 * fenêtre est reconstruite à chaque ouverture, et un écouteur posé sur un
		 * bouton disparaîtrait avec lui. Ce verrou, lui, empêche un second passage
		 * du script de doubler chaque clic — donc chaque appel facturé.
		 */
		if (window.k2000beConfigLie) { return }
		window.k2000beConfigLie = true

		/* Une zone de verdict par bouton : le message doit s'afficher là où l'on
		   vient de cliquer, pas à l'autre bout du formulaire. */
		function k2000beZone(_id) {
			return function (_texte, _niveau) {
				var zone = document.getElementById(_id)
				if (zone === null) { return }
				zone.textContent = _texte
				zone.className = _niveau ? 'label label-' + _niveau : ''
			}
		}

		var k2000beDireEssai = k2000beZone('span_k2000beEssai')
		var k2000beDireModeles = k2000beZone('span_k2000beModeles')
		var k2000beDireInvite = k2000beZone('span_k2000beInvite')

		function k2000beBloquer(_id, _bloque) {
			var bouton = document.getElementById(_id)
			if (bouton === null) { return }
			if (_bloque) {
				bouton.classList.add('disabled')
				return
			}
			bouton.classList.remove('disabled')
		}

		/*
		 * Un bouton qui sort vers OpenAI se ferme le temps de l'appel : chaque
		 * clic est une requête facturée, et rien à l'écran ne dit qu'une réponse
		 * est déjà en route. Le minuteur est le filet : si l'enregistrement
		 * échoue, le cœur affiche son propre message mais ne rappelle personne, et
		 * sans lui le bouton resterait fermé jusqu'au rechargement de la page.
		 */
		function k2000beFermer(_id, _dire) {
			k2000beBloquer(_id, true)
			var minuteur = setTimeout(function () {
				k2000beBloquer(_id, false)
				_dire('{{Rien n\'est revenu : la configuration n\'a peut-être pas pu être enregistrée.}}', 'warning')
			}, 30000)
			return function () {
				clearTimeout(minuteur)
				k2000beBloquer(_id, false)
			}
		}

		/*
		 * Enregistrer, puis appeler.
		 *
		 * Les deux boutons interrogent OpenAI avec la clé ENREGISTRÉE, jamais avec
		 * celle qui est affichée : une clé fraîchement collée et pas encore
		 * enregistrée répondait « Aucune clé API n'est renseignée » au tout premier
		 * essai. Ils enregistrent donc la configuration d'abord, par la fonction du
		 * cœur — celle-là même que le bouton « Sauvegarder » appelle.
		 */
		function k2000beEnregistrerPuis(_suite) {
			if (typeof jeeFrontEnd === 'undefined' || !is_object(jeeFrontEnd.plugin) || typeof jeeFrontEnd.plugin.savePluginConfig !== 'function') {
				/* Cœur où cette fonction n'existe pas : mieux vaut essayer avec ce
				   qui est déjà enregistré que de ne rien faire du tout. */
				_suite()
				return
			}
			jeeFrontEnd.plugin.savePluginConfig({ success: _suite })
		}

		/* L'essai et la liste des modèles sortent tous deux vers OpenAI : vingt
		   secondes suffisent, et un délai plus long ferait croire à une page
		   figée. */
		function k2000beConfigAjax(_action, _dire, _succes, _echec) {
			$.ajax({
				type: 'POST',
				url: 'plugins/k2000be/core/ajax/k2000be.ajax.php',
				data: { action: _action },
				dataType: 'json',
				timeout: 20000,
				error: function (_request, _status, _error) {
					if (typeof _echec === 'function') { _echec() }
					domUtils.handleAjaxError(_request, _status, _error)
				},
				success: function (_data) {
					if (_data.state != 'ok') {
						if (typeof _echec === 'function') { _echec() }
						_dire(_data.result, 'danger')
						return
					}
					_succes(_data.result)
				}
			})
		}

		$(document).on('click', '#bt_k2000beEssai', function () {
			if (this.classList.contains('disabled')) { return }
			var relacher = k2000beFermer('bt_k2000beEssai', k2000beDireEssai)
			k2000beDireEssai('{{Enregistrement, puis essai…}}', 'info')

			k2000beEnregistrerPuis(function () {
				k2000beConfigAjax('essai', k2000beDireEssai, function (_resultat) {
					relacher()
					var ok = (_resultat.ok === true || _resultat.ok == 1)
					k2000beDireEssai(_resultat.message, ok ? 'success' : 'danger')
				}, function () {
					relacher()
					k2000beDireEssai('{{Essai impossible}}', 'danger')
				})
			})
		})

		$(document).on('click', '#bt_k2000beModeles', function () {
			if (this.classList.contains('disabled')) { return }
			var boite = document.getElementById('div_k2000beModeles')
			if (boite === null) { return }
			boite.innerHTML = ''
			var relacher = k2000beFermer('bt_k2000beModeles', k2000beDireModeles)
			k2000beDireModeles('{{Enregistrement, puis lecture de la liste…}}', 'info')

			k2000beEnregistrerPuis(function () {
				k2000beConfigAjax('modeles', k2000beDireModeles, function (_modeles) {
					relacher()
					k2000beDireModeles('', '')
					boite.innerHTML = ''
					if (!is_array(_modeles) || _modeles.length === 0) {
						var vide = document.createElement('span')
						vide.className = 'help-block'
						vide.style.margin = '0'
						vide.textContent = '{{OpenAI n\'a renvoyé aucun modèle pour cette clé.}}'
						boite.appendChild(vide)
						return
					}

					/* Les noms viennent du réseau : ils sont posés en texte, et
					   cliquer sur l'un d'eux remplit le champ plutôt que d'obliger
					   à le recopier sans faute. */
					for (var i = 0; i < _modeles.length; i++) {
						var bouton = document.createElement('a')
						bouton.className = 'btn btn-default btn-xs k2000beModele'
						bouton.style.margin = '0 3px 3px 0'
						bouton.textContent = String(_modeles[i])
						boite.appendChild(bouton)
					}

					var aide = document.createElement('div')
					aide.className = 'help-block'
					aide.style.margin = '4px 0 0 0'
					aide.textContent = '{{Cliquez sur un modèle pour le reprendre dans le champ. Tous ne savent pas appeler des outils : un modèle qui ne le sait pas répondra poliment sans jamais rien faire.}}'
					boite.appendChild(aide)
				}, function () {
					relacher()
					k2000beDireModeles('{{Liste indisponible}}', 'danger')
				})
			})
		})

		/*
		 * L'aperçu de l'invite. Comme les deux autres, il enregistre d'abord :
		 * l'invite est construite à partir de la configuration ENREGISTRÉE, et
		 * un aperçu qui ignorerait la ligne qu'on vient d'écrire ne servirait
		 * à rien — c'est justement pour la relire qu'on clique.
		 *
		 * Le texte est posé par textContent dans un <pre> : il vient de la
		 * configuration, donc de la saisie d'un administrateur, et innerHTML
		 * exécuterait ce qu'un jour quelqu'un y aura collé.
		 */
		$(document).on('click', '#bt_k2000beInvite', function () {
			if (this.classList.contains('disabled')) { return }
			var boite = document.getElementById('div_k2000beInvite')
			if (boite === null) { return }
			var relacher = k2000beFermer('bt_k2000beInvite', k2000beDireInvite)
			k2000beDireInvite('{{Enregistrement, puis lecture…}}', 'info')

			k2000beEnregistrerPuis(function () {
				k2000beConfigAjax('invite', k2000beDireInvite, function (_resultat) {
					relacher()
					boite.textContent = String(_resultat.invite)
					boite.style.display = 'block'
					k2000beDireInvite('{{Ce texte part à chaque demande}}' + ' — ' + _resultat.taille + ' {{caractères}}', 'info')
				}, function () {
					relacher()
					boite.style.display = 'none'
					k2000beDireInvite('{{Aperçu indisponible}}', 'danger')
				})
			})
		})

		$(document).on('click', '.k2000beModele', function () {
			var champ = document.querySelector('.configKey[data-l1key="model"]')
			if (champ === null) { return }
			champ.value = this.textContent
			/* Le cœur écoute « change » pour savoir que la configuration est
			   modifiée : poser la valeur ne suffit pas, il faut le dire. */
			champ.dispatchEvent(new Event('change', { bubbles: true }))
		})

		$(document).on('change', '.configKey[data-l1key="securite"]', function () {
			window.k2000beAvertirSecurite()
		})
	})()
</script>
