<?php
/* Copyright (C) 2013-2014	Jean-François Ferry		<jfefe@aternatik.fr>
 * Copyright (C) 2023-2024	William Mead			<william.mead@manchenumerique.fr>
 * Copyright (C) 2024-2025	MDW						<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024		Frédéric France			<frederic.france@free.fr>
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
 *   	\file       resource/card.php
 *		\ingroup    resource
 *		\brief      Page to manage resource object
 */


// Load Dolibarr environment
require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/dolresource.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/html.formresource.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/resource.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('resource', 'companies', 'other', 'main'));

// Get parameters
$action					= GETPOST('action', 'aZ09');
$cancel					= GETPOST('cancel', 'alpha');
$backtopage				= GETPOST('backtopage', 'alpha');

$id						= GETPOSTINT('id');
$ref					= GETPOST('ref', 'alpha');
$address				= GETPOST('address', 'alpha');
$zip					= GETPOST('zipcode', 'alpha');
$town					= GETPOST('town', 'alpha');
$country_id				= GETPOSTINT('country_id');
$state_id				= GETPOSTINT('state_id');
$description			= GETPOST('description', 'restricthtml');
$phone					= GETPOST('phone', 'alpha');
$email					= GETPOST('email', 'alpha');
$max_users				= GETPOSTINT('max_users');
$allow_overflow			= GETPOSTINT('allow_overflow');
$metric_value			= GETPOST('metric_value', 'alpha');
$cooldown_minutes		= GETPOSTINT('cooldown_minutes');
$url					= GETPOST('url', 'alpha');
$confirm				= GETPOST('confirm', 'aZ09');
$fk_code_type_resource	= GETPOST('fk_code_type_resource', 'aZ09');
$status                 = GETPOSTISSET('status') ? GETPOSTINT('status') : Dolresource::STATUS_FREE;

// Protection if external user
if ($user->socid > 0) {
	accessforbidden();
}

$object = new Dolresource($db);
$extrafields = new ExtraFields($db);

// fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be 'include', not 'include_once'.

$hookmanager->initHooks(array('resource', 'resource_card', 'globalcard'));

$result = restrictedArea($user, 'resource', $object->id, 'resource');

$permissiontoadd = $user->hasRight('resource', 'write'); // Used by the include of actions_addupdatedelete.inc.php and actions_lineupdown.inc.php
$permissiontodelete = $user->hasRight('resource', 'delete');
$formconfirm = '';
$form = new Form($db);


/*
 * Actions
 */

$parameters = array('resource_id' => $id);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if ($cancel) {
		if (!empty($backtopage)) {
			header("Location: ".$backtopage);
			exit;
		}
		if ($action == 'add') {	// Test on permission not required here
			header("Location: ".DOL_URL_ROOT.'/resource/list.php');
			exit;
		}
		$action = '';
	}

	if ($action == 'add' && $permissiontoadd) {
		if (!$cancel) {
			$error = '';

			if (empty($ref)) {
				setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentities("Ref")), null, 'errors');
				$action = 'create';
			} else {
				$object->ref                    = $ref;
				$object->address				= $address;
				$object->zip					= $zip;
				$object->town					= $town;
				$object->country_id				= $country_id;
				$object->state_id				= $state_id;
				$object->description			= $description;
				$object->phone					= $phone;
				$object->email					= $email;
				$object->max_users				= $max_users;
				$object->allow_overflow			= $allow_overflow ? 1 : 0;
				$object->metric_value			= ($metric_value !== '' ? (float) price2num($metric_value, 'MS') : null);
				$object->cooldown_minutes		= max(0, $cooldown_minutes);
				$object->url					= $url;
				$object->fk_code_type_resource	= $fk_code_type_resource;
				$object->status                 = $status;

				// Fill array 'array_options' with data from add form
				$ret = $extrafields->setOptionalsFromPost(null, $object);
				if ($ret < 0) {
					$error++;
				}

				$result = $object->create($user);
				if ($result > 0) {
					// Creation OK
					setEventMessages($langs->trans('ResourceCreatedWithSuccess'), null);
					header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
					exit;
				} else {
					// Creation KO
					setEventMessages($object->error, $object->errors, 'errors');
					$action = 'create';
				}
			}
		} else {
			header("Location: list.php");
			exit;
		}
	}

	if ($action == 'confirm_out_of_service' && $confirm === 'yes' && $permissiontoadd) {
		$manager = new ResourceReservationManager($db);
		$impact = $manager->previewOutOfService($id);
		$result = $manager->applyOutOfService($id, $impact);
		if ($result >= 0) {
			setEventMessages($langs->trans('ResourceOutOfServiceApplied', $result), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
			exit;
		}
		setEventMessages($langs->trans('Error'), null, 'errors');
	}

	if ($action == 'update' && !$cancel && $permissiontoadd) {
		$error = 0;

		if (empty($ref)) {
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentities("Ref")), null, 'errors');
			$error++;
		}

		if (!$error) {
			$res = $object->fetch($id);
			if ($res > 0) {
				$oldref = $object->ref;

				if ($object->status === Dolresource::STATUS_FREE && $status === Dolresource::STATUS_OUT_OF_SERVICE) {
					$manager = new ResourceReservationManager($db);
					$impact = $manager->previewOutOfService($object->id);
					$message = $langs->trans('ResourceOutOfServiceImpact', count($impact));
					if ($impact) {
						$message .= '<br><br><ul class="left">';
						foreach ($impact as $reservation) {
							$message .= '<li>'.dol_escape_htmltag($reservation['document_ref'].' - '.$reservation['service_ref']).': ';
							if (!empty($reservation['replacement'])) {
								$message .= $langs->trans('ResourceWillBeReassignedTo', dol_escape_htmltag($reservation['replacement']['ref']));
							} else {
								$message .= '<span class="error">'.$langs->trans('ResourceServiceWillBeUnavailable').'</span>';
							}
							$message .= '</li>';
						}
						$message .= '</ul>';
					}
					$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SetResourceOutOfService'), $message, 'confirm_out_of_service', array(), 0, 1);
					$action = '';
					$error++;
				}

				$object->ref          			= $ref;
				$object->address				= $address;
				$object->zip					= $zip;
				$object->town					= $town;
				$object->country_id             = $country_id;
				$object->state_id				= $state_id;
				$object->description  			= $description;
				$object->phone					= $phone;
				$object->email					= $email;
				$object->max_users				= $max_users;
				$object->allow_overflow			= $allow_overflow ? 1 : 0;
				$object->metric_value			= ($metric_value !== '' ? (float) price2num($metric_value, 'MS') : null);
				$object->cooldown_minutes		= max(0, $cooldown_minutes);
				$object->url					= $url;
				$object->fk_code_type_resource  = $fk_code_type_resource;
				if ($status === Dolresource::STATUS_UNKNOWN && !$object->hasStatusProvider()) {
					setEventMessages($langs->trans('ResourceStatusProviderRequired'), null, 'errors');
					$error++;
				} else {
					$object->status = $status;
				}

				// Fill array 'array_options' with data from add form
				$ret = $extrafields->setOptionalsFromPost(null, $object, '@GETPOSTISSET');
				if ($ret < 0) {
					$error++;
				}

				$result = !$error ? $object->update($user) : -1;
				if ($result > 0) {
					if ($oldref != $ref) {
						// We renamed the ref so we must change the directory too
						include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
						$srcdir = $conf->resource->dir_output.'/'.dol_sanitizeFileName($oldref);
						$destdir = $conf->resource->dir_output.'/'.dol_sanitizeFileName($ref);
						dol_move_dir($srcdir, $destdir);
					}

					header("Location: ".$_SERVER['PHP_SELF']."?id=".$object->id);
					exit;
				} else {
					setEventMessages($object->error, $object->errors, 'errors');
					$error++;
				}
			} else {
				setEventMessages($object->error, $object->errors, 'errors');
				$error++;
			}
		}

		if ($error) {
			$action = empty($formconfirm) ? 'edit' : '';
		}
	}

	if ($action == 'confirm_delete_resource' && $permissiontodelete && $confirm === 'yes') {
		$res = $object->fetch($id);
		if ($res > 0) {
			$result = $object->delete($user);

			if ($result >= 0) {
				setEventMessages($langs->trans('RessourceSuccessfullyDeleted'), null);
				header('Location: '.DOL_URL_ROOT.'/resource/list.php');
				exit;
			} else {
				setEventMessages($object->error, $object->errors, 'errors');
			}
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
}


/*
 * View
 */

// A resource card without an id is necessarily a creation form. This also
// protects country/state auto-submits from trying to fetch an empty object.
if ($id <= 0 && empty($ref) && $action !== 'create' && $action !== 'add') {
	$action = 'create';
}

$title = $langs->trans($action == 'create' ? 'AddResource' : 'ResourceSingular');
$help_url = '';
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-resource page-card');

$formresource = new FormResource($db);

if ($action == 'create' || $object->fetch($id, $ref) > 0) {
	if ($action == 'create') {
		print load_fiche_titre($title, '', 'object_resource');
		print dol_get_fiche_head();
	} else {
		$head = resource_prepare_head($object);
		print dol_get_fiche_head($head, 'resource', $title, -1, 'resource');
	}

	if ($action == 'create' || $action == 'edit') {
		if (!$user->hasRight('resource', 'write')) {
			accessforbidden('', 0);
		}

		if (!empty($conf->use_javascript_ajax)) {
			print '<script type="text/javascript">';
			print '$(document).ready(function () {
                        $("#selectcountry_id").change(function() {
							console.log("selectcountry_id change");
							document.formresource.elements["action"].value="' . ($action == 'create' ? 'create' : 'edit') . '";
                        	document.formresource.submit();
                        });
                     });';
			print '</script>'."\n";
		}


		// Resource-type capabilities drive the fields displayed below.
		$typeModels = array();
		$resqlModels = $db->query('SELECT code, capacity_mode, metric_label, metric_unit, supports_cooldown FROM '.MAIN_DB_PREFIX.'c_type_resource WHERE active = 1');
		while ($resqlModels && ($typeModel = $db->fetch_object($resqlModels))) {
			$typeModels[$typeModel->code] = array('capacity_mode' => $typeModel->capacity_mode, 'metric_label' => $typeModel->metric_label, 'metric_unit' => $typeModel->metric_unit, 'supports_cooldown' => (int) $typeModel->supports_cooldown);
		}

		$formAction = $_SERVER["PHP_SELF"].($action === 'edit' && $id > 0 ? '?id='.((int) $id) : '');
		print '<form enctype="multipart/form-data" action="'.$formAction.'" method="POST" name="formresource">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.($action == "create" ? "add" : "update").'">';

		print '<table class="border centpercent">';

		// Ref
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("ResourceFormLabel_ref").'</td>';
		print '<td><input class="minwidth200" name="ref" value="'.($ref ?: $object->ref).'" autofocus="autofocus" spellcheck="false"></td></tr>';

		// Type
		print '<tr><td>'.$langs->trans("ResourceType").'</td>';
		print '<td>';
		$formresource->select_types_resource($object->fk_code_type_resource, 'fk_code_type_resource', '', 2, 0, 0, 0, 1, 'minwidth200');
		print '</td></tr>';

		// Manual availability status. Busy is calculated from reservations and capacity.
		$statusOptions = Dolresource::getStatusArray();
		if ($action == 'create') {
			unset($statusOptions[Dolresource::STATUS_UNKNOWN]);
		}
		print '<tr><td>'.$langs->trans('Status').'</td><td>';
		print $form->selectarray('status', $statusOptions, GETPOSTISSET('status') ? $status : $object->status, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print '</td></tr>';

		// Description
		print '<tr><td class="tdtop">'.$langs->trans("Description").'</td>';
		print '<td>';
		require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
		$doleditor = new DolEditor('description', ($description ?: $object->description), '', 200, 'dolibarr_notes');
		$doleditor->Create();
		print '</td></tr>';

		// Address
		print '<tr><td class="tdtop">'.$form->editfieldkey('Address', 'address', '', $object, 0).'</td>';
		print '<td><textarea name="address" id="address" class="quatrevingtpercent" rows="3" wrap="soft">';
		print dol_escape_htmltag(GETPOSTISSET('address') ? GETPOST('address') : $object->address, 0, 1);
		print '</textarea>';
		print $form->widgetForTranslation("address", $object, (bool) $permissiontoadd, 'textarea', 'alphanohtml', 'quatrevingtpercent');
		print '</td></tr>';

		// Zip
		print '<tr><td>'.$form->editfieldkey('Zip', 'zipcode', '', $object, 0).'</td><td>';
		print $formresource->select_ziptown(GETPOSTISSET('zipcode') ? GETPOST('zipcode') : $object->zip, 'zipcode', array('town', 'selectcountry_id', 'state_id'), 0, 0, '', 'maxwidth100');
		print '</td>';
		print '</tr>';

		// Town
		print '<tr>';
		print '<td>'.$form->editfieldkey('Town', 'town', '', $object, 0).'</td><td>';
		print $formresource->select_ziptown(GETPOSTISSET('town') ? GETPOST('town') : $object->town, 'town', array('zipcode', 'selectcountry_id', 'state_id'));
		print $form->widgetForTranslation("town", $object, (bool) $permissiontoadd, 'string', 'alphanohtml', 'maxwidth100 quatrevingtpercent');
		print '</td></tr>';

		// Origin country
		print '<tr><td>'.$langs->trans("CountryOrigin").'</td><td>';
		print $form->select_country(GETPOSTISSET('country_id') ? (string) GETPOSTINT('country_id') : (string) $object->country_id, 'country_id');
		if ($user->admin) {
			print info_admin($langs->trans("YouCanChangeValuesForThisListFromDictionarySetup"), 1);
		}
		print '</td></tr>';

		// State
		$countryid = GETPOSTISSET('country_id') ? GETPOSTINT('country_id') : $object->country_id;
		if (!getDolGlobalString('SOCIETE_DISABLE_STATE') && $countryid > 0) {
			if ((getDolGlobalInt('MAIN_SHOW_REGION_IN_STATE_SELECT') == 1 || getDolGlobalInt('MAIN_SHOW_REGION_IN_STATE_SELECT') == 2)) {
				print '<tr><td>'.$form->editfieldkey('Region-State', 'state_id', '', $object, 0).'</td><td class="maxwidthonsmartphone">';
			} else {
				print '<tr><td>'.$form->editfieldkey('State', 'state_id', '', $object, 0).'</td><td class="maxwidthonsmartphone">';
			}

			if ($country_id > 0) {
				print img_picto('', 'state', 'class="pictofixedwidth"');
				print $formresource->select_state($countryid, $country_id);
			} else {
				print '<span class="opacitymedium">'.$langs->trans("ErrorSetACountryFirst").' ('.$langs->trans("SeeAbove").')</span>';
			}
			print '</td></tr>';
		}

		// Phone
		print '<td>'.$form->editfieldkey('Phone', 'phone', '', $object, 0).'</td>';
		print '<td>';
		print img_picto('', 'object_phoning', 'class="pictofixedwidth"');
		print '<input type="tel" name="phone" id="phone" value="'.(GETPOSTISSET('phone') ? GETPOST('phone', 'alpha') : $object->phone).'"></td>';
		print '</tr>';

		// Email
		print '<tr><td>'.$form->editfieldkey('EMail', 'email', '', $object, 0).'</td>';
		print '<td>';
		print img_picto('', 'object_email', 'class="pictofixedwidth"');
		print '<input type="email" name="email" id="email" value="'.(GETPOSTISSET('email') ? GETPOST('email', 'alpha') : $object->email).'" spellcheck="false"></td>';
		print '</tr>';

		// Max users
		print '<tr class="resource-model-users"><td>'.$form->editfieldkey('MaxUsers', 'max_users', '', $object, 0, 'string', '', 0, 0, 'id', $langs->trans('MaxUsersResourceDesc')).'</td>';
		print '<td>';
		print img_picto('', 'object_user', 'class="pictofixedwidth"');
		print '<input type="text" class="width75 right" name="max_users" id="max_users" value="'.(GETPOSTISSET('max_users') ? GETPOST('max_users', 'int') : ($object->max_users > 0 ? $object->max_users : '')).'"></td>';
		print '</tr>';

		print '<tr class="resource-model-users"><td>'.$form->editfieldkey('AllowResourceOverflow', 'allow_overflow', '', $object, 0, 'string', '', 0, 0, 'id', $langs->trans('AllowResourceOverflowHelp')).'</td>';
		print '<td>'.$form->selectyesno('allow_overflow', GETPOSTISSET('allow_overflow') ? $allow_overflow : $object->allow_overflow, 1).'</td>';
		print '</tr>';

		print '<tr class="resource-model-custom"><td><span id="resource_metric_label">'.$langs->trans('ResourceMetricValue').'</span></td><td>';
		print '<input type="text" class="width100 right" name="metric_value" value="'.dol_escape_htmltag(GETPOSTISSET('metric_value') ? $metric_value : $object->metric_value).'"> <span id="resource_metric_unit"></span></td></tr>';
		print '<tr class="resource-model-cooldown"><td>'.$langs->trans('ResourceCooldownMinutes').'</td><td>';
	print '<input type="number" min="0" class="width75" name="cooldown_minutes" value="'.(GETPOSTISSET('cooldown_minutes') ? $cooldown_minutes : (int) $object->cooldown_minutes).'"> '.$langs->trans('Minutes').'</td></tr>';
		print '<script>jQuery(function(){var models='.json_encode($typeModels).'; function toggleModelFields(selector, visible){jQuery(selector).toggle(visible).find(":input").prop("disabled", !visible);} function applyResourceModel(){var model=models[jQuery("#selectfk_code_type_resource").val()] || {capacity_mode:"none",supports_cooldown:0}; toggleModelFields(".resource-model-users", model.capacity_mode === "users"); toggleModelFields(".resource-model-custom", model.capacity_mode === "custom"); toggleModelFields(".resource-model-cooldown", !!model.supports_cooldown); jQuery("#resource_metric_label").text(model.metric_label || '.json_encode($langs->transnoentities('ResourceMetricValue')).'); jQuery("#resource_metric_unit").text(model.metric_unit || "");} jQuery("#selectfk_code_type_resource").on("change", applyResourceModel); applyResourceModel();});</script>';

		// URL
		print '<tr><td>'.$form->editfieldkey('URL', 'url', '', $object, 0).'</td>';
		print '<td>';
		print img_picto('', 'object_url', 'class="pictofixedwidth"');
		print '<input type="url" class="minwidth300" name="url" id="url" value="'.(GETPOSTISSET('url') ? GETPOST('url', 'alpha') : $object->url).'" spellcheck="false"></td>';
		print '</tr>';

		// Other attributes
		$parameters = array();
		$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;
		if (empty($reshook)) {
			print $object->showOptionals($extrafields, 'edit');
		}

		print '</table>';

		print dol_get_fiche_end();

		$button_label = ($action == "create" ? "Create" : "Modify");
		print $form->buttonsSaveCancel($button_label);

		print '</div>';

		print '</form>';
	} else {
		// Confirm deleting resource line
		if ($action == 'delete' || ($conf->use_javascript_ajax && empty($conf->dol_use_jmobile))) {
			$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"]."?id=".$object->id, $langs->trans("DeleteResource"), $langs->trans("ConfirmDeleteResource"), "confirm_delete_resource", '', 0, "action-delete");
		}

		// Print form confirm
		print $formconfirm;


		$linkback = '<a href="'.DOL_URL_ROOT.'/resource/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';

		dol_banner_tab($object, 'ref', $linkback, 1, 'ref');


		print '<div class="fichecenter">';
		print '<div class="underbanner clearboth"></div>';

		print '<table class="border tableforfield centpercent">';

		// Resource type
		print '<tr>';
		print '<td class="titlefield">'.$langs->trans("ResourceType").'</td>';
		print '<td>';
		print $object->type_label;
		print '</td>';
		print '</tr>';

		// Manual availability status
		print '<tr>';
		print '<td>'.$langs->trans('Status').'</td>';
		print '<td>'.$object->getLibStatut(4).'</td>';
		print '</tr>';

		// Description
		print '<tr>';
		print '<td>'.$langs->trans("ResourceFormLabel_description").'</td>';
		print '<td>';
		print $object->description;
		print '</td>';
		print '</tr>';

		if ($object->capacity_mode === 'users') {
			print '<tr><td>'.$langs->trans("MaxUsers").'</td><td>'.($object->max_users > 0 ? $object->max_users : '').'</td></tr>';
			print '<tr><td>'.$langs->trans('AllowResourceOverflow').'</td><td>'.yn($object->allow_overflow).'</td></tr>';
		}

		if ($object->metric_value !== null) {
			print '<tr><td>'.dol_escape_htmltag($object->metric_label ?: $langs->trans('ResourceMetricValue')).'</td><td>'.price($object->metric_value).' '.dol_escape_htmltag($object->metric_unit).'</td></tr>';
		}
		if ($object->cooldown_minutes > 0) {
	print '<tr><td>'.$langs->trans('ResourceCooldownMinutes').'</td><td>'.((int) $object->cooldown_minutes).' '.$langs->trans('Minutes').'</td></tr>';
		}

		// Other attributes
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

		print '</tr>';

		print '</table>';

		print '</div>';

		print '<div class="clearboth"></div><br>';

		print dol_get_fiche_end();
	}


	/*
	 * Boutons actions
	 */
	print '<div class="tabsAction">';
	$parameters = array();
	$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been
	// modified by hook
	if (empty($reshook)) {
		if ($action != "create" && $action != "edit") {
			// Edit resource
			if ($user->hasRight('resource', 'write')) {
				print '<div class="inline-block divButAction">';
				print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit&token='.newToken().'" class="butAction">'.$langs->trans('Modify').'</a>';
				print '</div>';
			}
		}
		if ($action != "create" && $action != "edit") {
			$deleteUrl = $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken();
			$buttonId = 'action-delete-no-ajax';
			if ($conf->use_javascript_ajax && empty($conf->dol_use_jmobile)) {	// We can't use preloaded confirm form with jmobile
				$deleteUrl = '';
				$buttonId = 'action-delete';
			}
			print dolGetButtonAction('', $langs->trans("Delete"), 'delete', $deleteUrl, $buttonId, $permissiontodelete);
		}
	}
	print '</div>';
} else {
	dol_print_error();
}

// End of page
llxFooter();
$db->close();
