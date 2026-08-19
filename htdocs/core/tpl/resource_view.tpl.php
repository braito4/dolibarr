<!-- BEGIN TEMPLATE resource_view.tpl.php -->
<?php
/* Copyright (C) 2024		MDW	                    <mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
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
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 *
 * @var string $element
 * @var int $element_id
 * @var string $mode
 * @var string $resource_type
 * @var array<array{rowid:int,resource_id:int,resource_type:string,busy:int<0,1>,mandatory:int<0,1>,position:int,users_per_service_unit:float,relation_kind:string,resource_role:string,requirement_group:?string,quantity_required:float,duration_base:int,duration_per_unit:int,setup_duration:int,cleanup_duration:int,scheduling_mode:string,start_input_mode:string,end_input_mode:string,time_precision:string,simultaneous:int<0,1>,allow_split:int<0,1>,context_scope:string,demand_source:string,capacity_metrics:string,selection_policy:string}> $linked_resources
 */
// Protection to avoid direct call of template
if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

'
@phan-var-force string $element
@phan-var-force int $element_id
@phan-var-force string $mode
@phan-var-force string $resource_type
@phan-var-force Translate $langs
@phan-var-force array<array{rowid:int,resource_id:int,resource_type:string,busy:int<0,1>,mandatory:int<0,1>,position:int,users_per_service_unit:float,relation_kind:string,resource_role:string,requirement_group:?string,quantity_required:float,duration_base:int,duration_per_unit:int,setup_duration:int,cleanup_duration:int,scheduling_mode:string,start_input_mode:string,end_input_mode:string,time_precision:string,simultaneous:int<0,1>,allow_split:int<0,1>,context_scope:string,demand_source:string,capacity_metrics:string,selection_policy:string}> $linked_resources
';

global $langs;

$form = new Form($db);
$isProductResourceList = ($element == 'product' || $element == 'service');
$resourceHelpLabel = function (string $labelKey) use ($form, $langs): string {
	return $form->textwithpicto($langs->trans($labelKey), $langs->trans($labelKey.'Help'));
};


print '<div class="tagtable centpercent noborder allwidth">';

print '<form method="POST" class="tagtable centpercent noborder borderbottom allwidth">';

print '<div class="tagtr liste_titre">';
if ($isProductResourceList) {
	print '<div class="tagtd liste_titre center">'.$langs->trans('Priority').'</div>';
}
print '<div class="tagtd liste_titre">'.$langs->trans('Resource').'</div>';
print '<div class="tagtd liste_titre">'.$langs->trans('Type').'</div>';
if ($isProductResourceList) {
	print '<div class="tagtd liste_titre">'.$langs->trans('ResourceRequirement').'</div>';
} else {
	print '<div class="tagtd liste_titre center">'.$langs->trans('Busy').'</div>';
	print '<div class="tagtd liste_titre center">'.$langs->trans('Mandatory').'</div>';
}
print '<div class="tagtd liste_titre"></div>';
print '</div>';

print '<input type="hidden" name="token" value="'.newToken().'" />';
print '<input type="hidden" name="id" value="'.$element_id.'" />';
print '<input type="hidden" name="action" value="update_linked_resource" />';
print '<input type="hidden" name="resource_type" value="'.$resource_type.'" />';

if ((array) $linked_resources && count($linked_resources) > 0) {
	$resourceIndex = 0;
	$resourceCount = count($linked_resources);
	foreach ($linked_resources as $linked_resource) {
		$resourceIndex++;
		$object_resource = fetchObjectByElement($linked_resource['resource_id'], $linked_resource['resource_type']);

		//$element_id = $linked_resource['rowid'];

		if ($mode == 'edit' && $linked_resource['rowid'] == GETPOSTINT('lineid')) {
			print '<div class="tagtr oddeven">';
			print '<input type="hidden" name="lineid" value="'.$linked_resource['rowid'].'" />';
			print '<input type="hidden" name="element" value="'.$element.'" />';
			print '<input type="hidden" name="element_id" value="'.$element_id.'" />';

			if ($isProductResourceList) {
				print '<div class="tagtd center">'.$resourceIndex.'</div>';
			}
			print '<div class="tagtd">'.$object_resource->getNomUrl(1).'</div>';
			print '<div class="tagtd">'.$object_resource->type_label.'</div>';
			if ($isProductResourceList) {
				$roleOptions = array('capacity' => $langs->trans('ResourceRoleCapacity'), 'production' => $langs->trans('ResourceRoleProduction'), 'delivery' => $langs->trans('ResourceRoleDelivery'), 'equipment' => $langs->trans('ResourceRoleEquipment'), 'operator' => $langs->trans('ResourceRoleOperator'));
				$schedulingOptions = array('same_as_parent' => $langs->trans('SchedulingSameAsParent'), 'fixed' => $langs->trans('SchedulingFixed'), 'next_available' => $langs->trans('SchedulingNextAvailable'), 'within_window' => $langs->trans('SchedulingWithinWindow'), 'manual' => $langs->trans('SchedulingManual'));
				$startInputOptions = array('none' => $langs->trans('TimeInputNone'), 'date' => $langs->trans('TimeInputDate'), 'datetime' => $langs->trans('TimeInputDatetime'));
				$endInputOptions = $startInputOptions + array('calculated' => $langs->trans('TimeInputCalculated'));
				$precisionOptions = array('day' => $langs->trans('TimePrecisionDay'), 'hour' => $langs->trans('TimePrecisionHour'), 'minute' => $langs->trans('TimePrecisionMinute'), 'second' => $langs->trans('TimePrecisionSecond'));
				$contextScopeOptions = array('service_line' => $langs->trans('CapacityContextServiceLine'), 'same_proposal' => $langs->trans('CapacityContextSameProposal'));
				$demandSourceOptions = array('service_quantity' => $langs->trans('DemandSourceServiceQuantity'), 'product_lines' => $langs->trans('DemandSourceProductLines'));
				$capacityMetricOptions = array('units' => $langs->trans('CapacityMetricUnits'), 'volume' => $langs->trans('CapacityMetricVolume'));
				$selectionPolicyOptions = array('preference_order' => $langs->trans('SelectionPolicyPreferenceOrder'), 'smallest_sufficient' => $langs->trans('SelectionPolicySmallestSufficient'));
				print '<div class="tagtd">';
				print $resourceHelpLabel('ResourceRole').' '.$form->selectarray('resource_role', $roleOptions, $linked_resource['resource_role']).' ';
				print $resourceHelpLabel('SchedulingMode').' '.$form->selectarray('scheduling_mode', $schedulingOptions, $linked_resource['scheduling_mode']).'<br>';
				print $resourceHelpLabel('StartInputMode').' '.$form->selectarray('start_input_mode', $startInputOptions, $linked_resource['start_input_mode']).' ';
				print $resourceHelpLabel('EndInputMode').' '.$form->selectarray('end_input_mode', $endInputOptions, $linked_resource['end_input_mode']).' ';
				print $resourceHelpLabel('TimePrecision').' '.$form->selectarray('time_precision', $precisionOptions, $linked_resource['time_precision']).'<br>';
				print $resourceHelpLabel('UsersPerServiceUnit').' <input type="text" class="width50 right" name="users_per_service_unit" value="'.price($linked_resource['users_per_service_unit']).'" required> ';
				print $resourceHelpLabel('ResourceQuantityRequired').' <input type="text" class="width50 right" name="quantity_required" value="'.price($linked_resource['quantity_required']).'"> ';
				print $resourceHelpLabel('DurationBaseMinutes').' <input type="number" min="0" class="width50" name="duration_base" value="'.$linked_resource['duration_base'].'"> ';
				print $resourceHelpLabel('DurationPerUnitMinutes').' <input type="number" min="0" class="width50" name="duration_per_unit" value="'.$linked_resource['duration_per_unit'].'"><br>';
				print $resourceHelpLabel('SetupDurationMinutes').' <input type="number" min="0" class="width50" name="setup_duration" value="'.$linked_resource['setup_duration'].'"> ';
				print $resourceHelpLabel('CleanupDurationMinutes').' <input type="number" min="0" class="width50" name="cleanup_duration" value="'.$linked_resource['cleanup_duration'].'"> ';
				print $resourceHelpLabel('RequirementGroup').' <input type="text" class="width75" name="requirement_group" value="'.dol_escape_htmltag($linked_resource['requirement_group'] ?? '').'"> ';
				print '<label>'.$resourceHelpLabel('Mandatory').' <input type="checkbox" name="mandatory" value="1"'.($linked_resource['mandatory'] ? ' checked' : '').'></label> ';
				print '<label>'.$resourceHelpLabel('SimultaneousRequirement').' <input type="checkbox" name="simultaneous" value="1"'.($linked_resource['simultaneous'] ? ' checked' : '').'></label> ';
				print '<label>'.$resourceHelpLabel('AllowSplitRequirement').' <input type="checkbox" name="allow_split" value="1"'.($linked_resource['allow_split'] ? ' checked' : '').'></label>';
				if ($object_resource instanceof Dolresource && $object_resource->capacity_mode === 'volume') {
					print '<br>'.$resourceHelpLabel('CapacityContextScope').' '.$form->selectarray('context_scope', $contextScopeOptions, $linked_resource['context_scope']).' ';
					print $resourceHelpLabel('CapacityDemandSource').' '.$form->selectarray('demand_source', $demandSourceOptions, $linked_resource['demand_source']).' ';
					print $resourceHelpLabel('CapacityMetrics').' '.$form->selectarray('capacity_metrics', $capacityMetricOptions, $linked_resource['capacity_metrics']).' ';
					print $resourceHelpLabel('ResourceSelectionPolicy').' '.$form->selectarray('selection_policy', $selectionPolicyOptions, $linked_resource['selection_policy']);
				}
				print '</div>';
			} else {
				print '<div class="tagtd center">'.$form->selectyesno('busy', $linked_resource['busy'] ? 1 : 0, 1).'</div>';
				print '<div class="tagtd center">'.$form->selectyesno('mandatory', $linked_resource['mandatory'] ? 1 : 0, 1).'</div>';
			}
			print '<div class="tagtd right"><input type="submit" class="button" value="'.$langs->trans("Update").'"></div>';
			print '</div>';
		} else {
			$class = '';
			if ($linked_resource['rowid'] == GETPOSTINT('lineid')) {
				$class = 'highlight';
			}

			print '<div class="tagtr oddeven'.($class ? ' '.$class : '').'">';

			if ($isProductResourceList) {
				print '<div class="tagtd center">'.$resourceIndex.'</div>';
			}

			print '<div class="tagtd">';
			print $object_resource->getNomUrl(1);
			print '</div>';

			print '<div class="tagtd">';
			print $object_resource->type_label;
			print '</div>';

			if (!$isProductResourceList) {
				print '<div class="tagtd center">';
				print yn($linked_resource['busy']);
				print '</div>';

				print '<div class="tagtd center">';
				print yn($linked_resource['mandatory']);
				print '</div>';
			} else {
				print '<div class="tagtd">';
				print $langs->trans('ResourceRole'.ucfirst($linked_resource['resource_role'])).' · '.$langs->trans('Scheduling'.str_replace(' ', '', ucwords(str_replace('_', ' ', $linked_resource['scheduling_mode']))));
				print '<br>'.$langs->trans('UsersPerServiceUnit').': '.price($linked_resource['users_per_service_unit']);
				print '<br>'.$langs->trans('StartInputMode').': '.$langs->trans('TimeInput'.ucfirst($linked_resource['start_input_mode']));
				print ' · '.$langs->trans('EndInputMode').': '.$langs->trans('TimeInput'.ucfirst($linked_resource['end_input_mode']));
				print ' · '.$langs->trans('TimePrecision').': '.$langs->trans('TimePrecision'.ucfirst($linked_resource['time_precision']));
				print ' · '.$langs->trans('ResourceQuantityRequired').': '.price($linked_resource['quantity_required']);
				if (!empty($linked_resource['requirement_group'])) {
					print ' · '.$langs->trans('RequirementGroup').': <strong>'.dol_escape_htmltag($linked_resource['requirement_group']).'</strong>';
				}
				print ' · '.$langs->trans('Mandatory').': '.yn($linked_resource['mandatory']);
				print ' · '.$langs->trans('SimultaneousRequirement').': '.yn($linked_resource['simultaneous']);
				if ($object_resource instanceof Dolresource && $object_resource->capacity_mode === 'volume') {
					print '<br>'.$langs->trans('CapacityContextScope').': '.$langs->trans($linked_resource['context_scope'] === 'same_proposal' ? 'CapacityContextSameProposal' : 'CapacityContextServiceLine');
					print ' · '.$langs->trans('CapacityDemandSource').': '.$langs->trans($linked_resource['demand_source'] === 'product_lines' ? 'DemandSourceProductLines' : 'DemandSourceServiceQuantity');
					print ' · '.$langs->trans('CapacityMetrics').': '.$langs->trans($linked_resource['capacity_metrics'] === 'volume' ? 'CapacityMetricVolume' : 'CapacityMetricUnits');
					print ' · '.$langs->trans('ResourceSelectionPolicy').': '.$langs->trans($linked_resource['selection_policy'] === 'smallest_sufficient' ? 'SelectionPolicySmallestSufficient' : 'SelectionPolicyPreferenceOrder');
				}
				print ' · '.$langs->trans('DurationOfRange').': '.((int) $linked_resource['duration_base']).' + '.((int) $linked_resource['duration_per_unit']).' × '.$langs->trans('Unit');
				if ($linked_resource['setup_duration'] || $linked_resource['cleanup_duration']) print ' · +'.((int) $linked_resource['setup_duration']).'/+'.((int) $linked_resource['cleanup_duration']).' min';
				print '</div>';
			}

			print '<div class="tagtd right">';
			if ($isProductResourceList) {
				print '<a class="editfielda marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?mode=edit&token='.newToken().'&resource_type='.$linked_resource['resource_type'].'&element='.$element.'&element_id='.$element_id.'&lineid='.$linked_resource['rowid'].'">'.img_edit().'</a>';
				if ($resourceIndex > 1) {
					print '<a class="lineupdown reposition marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=move_resource&direction=up&token='.newToken().'&resource_type='.$linked_resource['resource_type'].'&element='.$element.'&element_id='.$element_id.'&lineid='.$linked_resource['rowid'].'">'.img_up('default', 0, 'imgupforline').'</a>';
				}
				if ($resourceIndex < $resourceCount) {
					print '<a class="lineupdown reposition marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=move_resource&direction=down&token='.newToken().'&resource_type='.$linked_resource['resource_type'].'&element='.$element.'&element_id='.$element_id.'&lineid='.$linked_resource['rowid'].'">'.img_down('default', 0, 'imgdownforline').'</a>';
				}
			} else {
				print '<a class="editfielda marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?mode=edit&token='.newToken().'&resource_type='.$linked_resource['resource_type'].'&element='.$element.'&element_id='.$element_id.'&lineid='.$linked_resource['rowid'].'">';
				print img_edit();
				print '</a>';
			}
			print '&nbsp;';
			print '<a class="marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=delete_resource&token='.newToken().'&id='.$linked_resource['resource_id'].'&element='.$element.'&element_id='.$element_id.'&lineid='.$linked_resource['rowid'].'">';
			print img_picto($langs->trans("Unlink"), 'unlink');
			print '</a>';
			print '</div>';

			print '</div>';
		}
	}
} else {
	print '<div class="tagtr oddeven">';
	if ($isProductResourceList) {
		print '<div class="tagtd opacitymedium"></div>';
	}
	print '<div class="tagtd opacitymedium">'.$langs->trans('NoResourceLinked').'</div>';
	print '<div class="tagtd opacitymedium"></div>';
	print '<div class="tagtd opacitymedium"></div>';
	print '<div class="tagtd opacitymedium"></div>';
	if (!$isProductResourceList) {
		print '<div class="tagtd opacitymedium"></div>';
	}
	print '</div>';
}

print '</form>';

print '</div>';

?>
<!-- END TEMPLATE resource_view.tpl.php -->
