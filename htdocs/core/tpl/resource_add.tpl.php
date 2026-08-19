<!-- BEGIN TEMPLATE resource_add.tpl.php -->
<?php
/* Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
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
 *       \file       htdocs/core/tpl/resource_add.tpl.php
 *       \brief      Include file to add a resource
 */

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 *
 * @var string $element
 * @var int $element_id
 * @var string $element_ref
 */
'
@phan-var-force string $element
@phan-var-force int $element_id
@phan-var-force string $element_ref
@phan-var-force Translate $langs
';
// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}


require_once DOL_DOCUMENT_ROOT.'/resource/class/html.formresource.class.php';

global $langs;

$form = new Form($db);
$formresources = new FormResource($db);
$resourceHelpLabel = function (string $labelKey) use ($form, $langs): string {
	return $form->textwithpicto($langs->trans($labelKey), $langs->trans($labelKey.'Help'));
};
$resourceCapacityModes = array();
$sqlResourceCapacityModes = 'SELECT r.rowid, ty.capacity_mode FROM '.$db->prefix().'resource r';
$sqlResourceCapacityModes .= ' LEFT JOIN '.$db->prefix().'c_type_resource ty ON ty.code = r.fk_code_type_resource';
$resqlResourceCapacityModes = $db->query($sqlResourceCapacityModes);
while ($resqlResourceCapacityModes && ($resourceCapacityMode = $db->fetch_object($resqlResourceCapacityModes))) {
	$resourceCapacityModes[(int) $resourceCapacityMode->rowid] = $resourceCapacityMode->capacity_mode ?: 'none';
}

$out = '';

$out .= '<div class="centpercent allwidth nohover">';

$out .= '<form class="nohover '.(!empty($var) && $var == true ? 'pair' : 'impair').'" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
$out .= '<input type="hidden" name="token" value="'.newToken().'">';
$out .= '<input type="hidden" name="action" value="add_element_resource">';
$out .= '<input type="hidden" name="element" value="'.$element.'">';
$out .= '<input type="hidden" name="element_id" value="'.$element_id.'">';
$out .= '<input type="hidden" name="ref" value="'.$element_ref.'">';
$out .= '<input type="hidden" name="resource_type" value="'.(empty($resource_type) ? 'dolresource' : $resource_type).'">';

$out .= '<div class="noborder borderbottom">';

// Place
$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block">'.$resourceHelpLabel('SelectResource').'</div>';
$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block">';
$events = array();
$out .= img_picto('', 'resource', 'class="pictofixedwidth"');
$out .= $formresources->select_resource_list(0, 'fk_resource', '', 1, 1, 0, $events, '', 2, 0);
$out .= '</div>';

if ($element != 'product' && $element != 'service') {
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block marginleftonly"><label for="resbusy">'.$langs->trans('Busy').'</label> ';
	$out .= '<input type="checkbox" id="resbusy" name="busy" value="1"'.(GETPOSTISSET('fk_resource') ? (GETPOST('busy') ? ' checked' : '') : ' checked').'>';
	$out .= '</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block marginleftonly"><label for="resmandatory">'.$langs->trans('Mandatory').'</label> ';
	$out .= '<input type="checkbox" id="resmandatory" name="mandatory" value="1"'.(GETPOSTISSET('fk_resource') ? (GETPOST('mandatory') ? ' checked' : '') : ' checked').'>';
	$out .= '</div>';
} else {
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block marginleftonly"><label class="fieldrequired" for="users_per_service_unit">'.$resourceHelpLabel('UsersPerServiceUnit').'</label> ';
	$out .= '<input type="text" class="width75 right" id="users_per_service_unit" name="users_per_service_unit" value="'.dol_escape_htmltag(GETPOST('users_per_service_unit', 'alpha')).'" required>';
	$out .= '</div>';
	$roleOptions = array('capacity' => $langs->trans('ResourceRoleCapacity'), 'production' => $langs->trans('ResourceRoleProduction'), 'delivery' => $langs->trans('ResourceRoleDelivery'), 'equipment' => $langs->trans('ResourceRoleEquipment'), 'operator' => $langs->trans('ResourceRoleOperator'));
	$schedulingOptions = array('same_as_parent' => $langs->trans('SchedulingSameAsParent'), 'fixed' => $langs->trans('SchedulingFixed'), 'next_available' => $langs->trans('SchedulingNextAvailable'), 'within_window' => $langs->trans('SchedulingWithinWindow'), 'manual' => $langs->trans('SchedulingManual'));
	$startInputOptions = array('none' => $langs->trans('TimeInputNone'), 'date' => $langs->trans('TimeInputDate'), 'datetime' => $langs->trans('TimeInputDatetime'));
	$endInputOptions = $startInputOptions + array('calculated' => $langs->trans('TimeInputCalculated'));
	$precisionOptions = array('day' => $langs->trans('TimePrecisionDay'), 'hour' => $langs->trans('TimePrecisionHour'), 'minute' => $langs->trans('TimePrecisionMinute'), 'second' => $langs->trans('TimePrecisionSecond'));
	$contextScopeOptions = array('service_line' => $langs->trans('CapacityContextServiceLine'), 'same_proposal' => $langs->trans('CapacityContextSameProposal'));
	$demandSourceOptions = array('service_quantity' => $langs->trans('DemandSourceServiceQuantity'), 'product_lines' => $langs->trans('DemandSourceProductLines'));
	$capacityMetricOptions = array('units' => $langs->trans('CapacityMetricUnits'), 'volume' => $langs->trans('CapacityMetricVolume'), 'volume_weight' => $langs->trans('CapacityMetricVolumeWeight'));
	$selectionPolicyOptions = array('preference_order' => $langs->trans('SelectionPolicyPreferenceOrder'), 'smallest_sufficient' => $langs->trans('SelectionPolicySmallestSufficient'));
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="resource_role">'.$resourceHelpLabel('ResourceRole').'</label> '.$form->selectarray('resource_role', $roleOptions, GETPOST('resource_role', 'alpha') ?: 'capacity').'</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="scheduling_mode">'.$resourceHelpLabel('SchedulingMode').'</label> '.$form->selectarray('scheduling_mode', $schedulingOptions, GETPOST('scheduling_mode', 'alpha') ?: 'same_as_parent').'</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="start_input_mode">'.$resourceHelpLabel('StartInputMode').'</label> '.$form->selectarray('start_input_mode', $startInputOptions, GETPOST('start_input_mode', 'alpha') ?: 'none').'</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="end_input_mode">'.$resourceHelpLabel('EndInputMode').'</label> '.$form->selectarray('end_input_mode', $endInputOptions, GETPOST('end_input_mode', 'alpha') ?: 'none').'</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="time_precision">'.$resourceHelpLabel('TimePrecision').'</label> '.$form->selectarray('time_precision', $precisionOptions, GETPOST('time_precision', 'alpha') ?: 'minute').'</div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="quantity_required">'.$resourceHelpLabel('ResourceQuantityRequired').'</label> <input type="text" class="width50 right" name="quantity_required" value="'.dol_escape_htmltag(GETPOST('quantity_required', 'alpha') ?: '1').'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="duration_base">'.$resourceHelpLabel('DurationBaseMinutes').'</label> <input type="number" min="0" class="width50" name="duration_base" value="'.GETPOSTINT('duration_base').'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="duration_per_unit">'.$resourceHelpLabel('DurationPerUnitMinutes').'</label> <input type="number" min="0" class="width50" name="duration_per_unit" value="'.GETPOSTINT('duration_per_unit').'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="setup_duration">'.$resourceHelpLabel('SetupDurationMinutes').'</label> <input type="number" min="0" class="width50" name="setup_duration" value="'.GETPOSTINT('setup_duration').'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="cleanup_duration">'.$resourceHelpLabel('CleanupDurationMinutes').'</label> <input type="number" min="0" class="width50" name="cleanup_duration" value="'.GETPOSTINT('cleanup_duration').'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="requirement_group">'.$resourceHelpLabel('RequirementGroup').'</label> <input type="text" class="width75" name="requirement_group" value="'.dol_escape_htmltag(GETPOST('requirement_group', 'alphanohtml')).'"></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label>'.$resourceHelpLabel('Mandatory').'</label> <input type="checkbox" name="mandatory" value="1" checked></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label>'.$resourceHelpLabel('SimultaneousRequirement').'</label> <input type="checkbox" name="simultaneous" value="1" checked></div>';
	$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label>'.$resourceHelpLabel('AllowSplitRequirement').'</label> <input type="checkbox" name="allow_split" value="1"></div>';
	$out .= '<div class="resource-volume-awareness divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="context_scope">'.$resourceHelpLabel('CapacityContextScope').'</label> '.$form->selectarray('context_scope', $contextScopeOptions, GETPOST('context_scope', 'alpha') ?: 'same_proposal').'</div>';
	$out .= '<div class="resource-volume-awareness divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="demand_source">'.$resourceHelpLabel('CapacityDemandSource').'</label> '.$form->selectarray('demand_source', $demandSourceOptions, GETPOST('demand_source', 'alpha') ?: 'product_lines').'</div>';
	$out .= '<div class="resource-volume-awareness divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="capacity_metrics">'.$resourceHelpLabel('CapacityMetrics').'</label> '.$form->selectarray('capacity_metrics', $capacityMetricOptions, GETPOST('capacity_metrics', 'alpha') ?: 'volume_weight').'</div>';
	$out .= '<div class="resource-volume-awareness divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="required_location">'.$resourceHelpLabel('ResourceRequiredLocation').'</label> <input type="text" name="required_location" value="'.dol_escape_htmltag(GETPOST('required_location', 'alphanohtml')).'"></div>';
	$out .= '<div class="resource-volume-awareness divsearchfield paddingtop paddingbottom valignmiddle inline-block"><label for="selection_policy">'.$resourceHelpLabel('ResourceSelectionPolicy').'</label> '.$form->selectarray('selection_policy', $selectionPolicyOptions, GETPOST('selection_policy', 'alpha') ?: 'smallest_sufficient').'</div>';
}

$out .= '<div class="divsearchfield paddingtop paddingbottom valignmiddle inline-block right">';
$out .= '<input type="submit" id="add-resource-place" class="button button-add small" value="'.$langs->trans("Add").'"/>';
$out .= '</div>';

$out .= '</div>';

$out .= '</form>';

if ($element == 'product' || $element == 'service') {
	$out .= '<script>jQuery(function(){var capacityModes='.json_encode($resourceCapacityModes).'; function toggleVolumeAwareness(){var visible=capacityModes[jQuery("#fk_resource").val()] === "volume"; jQuery(".resource-volume-awareness").toggle(visible).find(":input").prop("disabled", !visible);} jQuery("#fk_resource").on("change", toggleVolumeAwareness); toggleVolumeAwareness();});</script>';
}

$out .= '</div>';
$out .= '<br>';

print $out;
?>
<!-- END TEMPLATE resource_add.tpl.php -->
