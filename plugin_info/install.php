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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/k2000be.class.php';

function k2000be_install() {
    k2000be_update();
}

function k2000be_update() {
    /* Les dossiers de travail appartiennent à l'installation, pas au dépôt :
     * ils sont créés ici, et le déploiement ne les touche jamais. */
    k2000beJournal::prepare();

    /*
     * Un assistant créé avant une mise à jour n'a pas les commandes ajoutées
     * depuis. Sans ce passage, une nouveauté serait réservée aux assistants
     * créés après elle, et l'utilisateur la croirait absente.
     */
    foreach (eqLogic::byType('k2000be') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('k2000be', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function k2000be_remove() {
    /*
     * Les autorisations vivent dans la configuration des commandes surveillées,
     * pas dans celle du plugin : elles survivent volontairement à une
     * désinstallation, pour qu'une réinstallation ne reparte pas d'une page
     * blanche. Rien à nettoyer ici hormis les messages du centre de
     * notifications.
     */
    message::removeAll('k2000be');
}
