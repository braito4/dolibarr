<?php
/* Copyright (C) 2001-2007  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2005       Brice Davoleau          <brice.davoleau@gmail.com>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2006-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2007       Patrick Raguin  		<patrick.raguin@gmail.com>
 * Copyright (C) 2010       Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2015       Marcos García           <marcosgdf@gmail.com>
 * Copyright (C) 2018       Florian Henry           <florian.henry@open-concept.pro
 * Copyright (C) 2024-2026  Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       htdocs/resource/agenda.php
 *  \ingroup    resource
 *  \brief      Page of resource events
 */

// Load Dolibarr environment
require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/resource.lib.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/dolresource.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('companies', 'resource'));

// Get parameters
$id         = GETPOSTINT('id');
$ref        = GETPOST('ref', 'alpha');
$action     = GETPOST('action', 'aZ09');
$cancel     = GETPOST('cancel');
$backtopage = GETPOST('backtopage', 'alpha');

if (GETPOST('actioncode', 'array')) {
	$actioncode = GETPOST('actioncode', 'array', 3);
	if (!count($actioncode)) {
		$actioncode = '0';
	}
} else {
	$actioncode = GETPOST("actioncode", "alpha", 3) ? GETPOST("actioncode", "alpha", 3) : (GETPOST("actioncode") == '0' ? '0' : getDolGlobalString('AGENDA_DEFAULT_FILTER_TYPE_FOR_OBJECT'));
}

$search_rowid = GETPOST('search_rowid');
$search_agenda_label = GETPOST('search_agenda_label');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page == -1) {
	$page = 0;
}     // If $page is not defined, or '' or -1
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortfield) {
	$sortfield = 'a.datep,a.id';
}
if (!$sortorder) {
	$sortorder = 'DESC,DESC';
}

// Initialize a technical objects

$hookmanager->initHooks(array('agendaresource'));

$object = new Dolresource($db);

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'.

$result = restrictedArea($user, 'resource', $object->id, 'resource');

// Security check
if (!$user->hasRight('resource', 'read')) {
	accessforbidden();
}


/*
 *	Actions
 */

$parameters = array('id'=>$id);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);    // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Cancel
	if (GETPOST('cancel', 'alpha') && !empty($backtopage)) {
		header("Location: ".$backtopage);
		exit;
	}

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		$actioncode = '';
		$search_agenda_label = '';
	}
}



/*
 *	View
 */

$contactstatic = new Contact($db);
$form = new Form($db);

if ($object->id > 0) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$picto = 'resource';

	$title = $langs->trans("Agenda");
	if (getDolGlobalString('MAIN_HTML_TITLE') && preg_match('/productnameonly/', getDolGlobalString('MAIN_HTML_TITLE')) && $object->name) {
		$title = $object->ref." - ".$title;
	}
	$help_url = '';
	llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-resource page-card_agenda');

	if (isModEnabled('notification')) {
		$langs->load("mails");
	}
	$type = $langs->trans('ResourceSingular');

	$head = resource_prepare_head($object);

	$titre = $langs->trans("ResourceSingular");
	print dol_get_fiche_head($head, 'agenda', $titre, -1, $picto);

	$linkback = '<a href="'.DOL_URL_ROOT.'/resource/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<div class="refidno">';
	$morehtmlref .= '</div>';

	$shownav = 1;
	if ($user->socid && !in_array('resource', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
		$shownav = 0;
	}

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';

	print '</div>';

	print dol_get_fiche_end();

	// Reservations inherited from proposal, order and contract service lines.
	$sql = "SELECT er.rowid, er.element_type, er.element_id, er.service_quantity, er.service_duration, er.capacity_used,";
	$sql .= " er.date_start, er.date_end, er.reservation_status,";
	$sql .= " p.rowid as document_id, p.ref as document_ref, pd.fk_product, prod.ref as service_ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."element_resources er";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."propaldet pd ON pd.rowid = er.element_id AND er.element_type = 'propaldet'";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."propal p ON p.rowid = pd.fk_propal";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product prod ON prod.rowid = pd.fk_product";
	$sql .= " WHERE er.resource_id = ".((int) $object->id)." AND er.resource_type = 'dolresource'";
	$sql .= " AND er.relation_kind = 'assignment'";
	$sql .= $user->hasRight('propale', 'lire') ? ' AND p.entity IN ('.getEntity('propal').')' : ' AND 1 = 0';
	$sql .= " UNION ALL ";
	$sql .= "SELECT er.rowid, er.element_type, er.element_id, er.service_quantity, er.service_duration, er.capacity_used,";
	$sql .= " er.date_start, er.date_end, er.reservation_status,";
	$sql .= " o.rowid as document_id, o.ref as document_ref, od.fk_product, prod.ref as service_ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."element_resources er";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commandedet od ON od.rowid = er.element_id AND er.element_type = 'commandedet'";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande o ON o.rowid = od.fk_commande";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product prod ON prod.rowid = od.fk_product";
	$sql .= " WHERE er.resource_id = ".((int) $object->id)." AND er.resource_type = 'dolresource'";
	$sql .= " AND er.relation_kind = 'assignment'";
	$sql .= $user->hasRight('commande', 'lire') ? ' AND o.entity IN ('.getEntity('commande').')' : ' AND 1 = 0';
	$sql .= " UNION ALL ";
	$sql .= "SELECT er.rowid, er.element_type, er.element_id, er.service_quantity, er.service_duration, er.capacity_used,";
	$sql .= " er.date_start, er.date_end, er.reservation_status,";
	$sql .= " c.rowid as document_id, c.ref as document_ref, cd.fk_product, prod.ref as service_ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."element_resources er";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."contratdet cd ON cd.rowid = er.element_id AND er.element_type = 'contratdet'";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."contrat c ON c.rowid = cd.fk_contrat";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product prod ON prod.rowid = cd.fk_product";
	$sql .= " WHERE er.resource_id = ".((int) $object->id)." AND er.resource_type = 'dolresource'";
	$sql .= " AND er.relation_kind = 'assignment'";
	$sql .= $user->hasRight('contrat', 'lire') ? ' AND c.entity IN ('.getEntity('contract').')' : ' AND 1 = 0';
	$sql .= " ORDER BY date_start DESC, rowid DESC";
	$resql = $db->query($sql);

	print_barre_liste($langs->trans('ResourceReservations'), 0, $_SERVER['PHP_SELF'], '', '', '', '', 0, -1, '', 0, '', '', 0, 1, 1);
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Type').'</th>';
	print '<th>'.$langs->trans('Ref').'</th>';
	print '<th>'.$langs->trans('Service').'</th>';
	print '<th class="right">'.$langs->trans('Qty').'</th>';
	print '<th>'.$langs->trans('ServiceDuration').'</th>';
	print '<th class="right">'.$langs->trans('ResourceRoleCapacity').'</th>';
	print '<th>'.$langs->trans('DateStart').'</th>';
	print '<th>'.$langs->trans('DateEnd').'</th>';
	print '<th>'.$langs->trans('Status').'</th>';
	print '</tr>';
	$reservationCount = 0;
	if ($resql) {
		while ($reservation = $db->fetch_object($resql)) {
			$reservationCount++;
			$isProposalReservation = ($reservation->element_type === 'propaldet');
			$isOrderReservation = ($reservation->element_type === 'commandedet');
			$documentUrl = $isProposalReservation ? '/comm/propal/card.php?id=' : ($isOrderReservation ? '/commande/card.php?id=' : '/contrat/card.php?id=');
			$documentPicto = $isProposalReservation ? 'propal' : ($isOrderReservation ? 'order' : 'contract');
			$documentLabel = $isProposalReservation ? 'Proposal' : ($isOrderReservation ? 'Order' : 'Contract');
			$isUnavailable = ($reservation->reservation_status === 'unavailable');
			print '<tr class="oddeven'.($isUnavailable ? ' error' : '').'">';
			print '<td>'.img_picto('', $documentPicto, 'class="pictofixedwidth"').$langs->trans($documentLabel).'</td>';
			print '<td><a href="'.DOL_URL_ROOT.$documentUrl.((int) $reservation->document_id).'">'.dol_escape_htmltag($reservation->document_ref).'</a></td>';
			print '<td>'.dol_escape_htmltag($reservation->service_ref).'</td>';
			print '<td class="right">'.price($reservation->service_quantity).'</td>';
			print '<td>'.dol_escape_htmltag($reservation->service_duration).'</td>';
			print '<td class="right">'.price($reservation->capacity_used).'</td>';
			print '<td>'.(!empty($reservation->date_start) ? dol_print_date($db->jdate($reservation->date_start), 'dayhour') : '').'</td>';
			print '<td>'.(!empty($reservation->date_end) ? dol_print_date($db->jdate($reservation->date_end), 'dayhour') : '').'</td>';
			$statusClass = $isUnavailable ? '8' : ($reservation->reservation_status === 'confirmed' ? '3' : '1');
			$statusLabel = $isUnavailable ? 'UnavailableReservation' : ($reservation->reservation_status === 'confirmed' ? 'ResourceStatusOccupied' : 'ProvisionalReservation');
			print '<td><span class="badge badge-status'.$statusClass.'">'.$langs->trans($statusLabel).'</span></td>';
			print '</tr>';
		}
	}
	if (!$reservationCount) {
		print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '</div>';

	if (isModEnabled('agenda') && ($user->hasRight('agenda', 'myactions', 'read') || $user->hasRight('agenda', 'allactions', 'read'))) {
		$param = '&id='.$object->id;
		if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
			$param .= '&contextpage='.urlencode($contextpage);
		}
		if ($limit > 0 && $limit != $conf->liste_limit) {
			$param .= '&limit='.((int) $limit);
		}

		print_barre_liste($langs->trans("ActionsOnResource"), 0, $_SERVER["PHP_SELF"], '', $sortfield, $sortorder, '', 0, -1, '', 0, '', '', 0, 1, 1);

		// List of all actions
		$filters = array();
		$filters['search_agenda_label'] = $search_agenda_label;
		$filters['search_rowid'] = $search_rowid;

		// TODO Replace this with same code than into list.php
		show_actions_done($conf, $langs, $db, $object, null, 0, $actioncode, '', $filters, $sortfield, $sortorder);
	}
}

// End of page
llxFooter();
$db->close();
