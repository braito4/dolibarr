<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/core/triggers/interface_99_modResource_ResourceReservations.class.php
 * \ingroup resource
 * \brief Synchronize service-line resource reservations.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/dolresource.class.php';

/**
 * Synchronize provisional proposal reservations and confirmed contract reservations.
 */
class InterfaceResourceReservations extends DolibarrTriggers
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'resource';
		$this->description = 'Synchronize resources used by proposal and contract service lines.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'resource';
	}

	/**
	 * Run trigger.
	 *
	 * @param string $action Trigger action
	 * @param CommonObject $object Business object
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @param Conf $conf Configuration
	 * @return int<-1,1> -1 on error, 0 when ignored, 1 when handled
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('resource')) {
			return 0;
		}
		if ($action === 'CONTRACT_VALIDATE') {
			return $this->synchronizeContractReservations((int) $object->id, $user, $langs);
		}
		if ($action === 'PROPAL_VALIDATE') {
			return $this->synchronizeProposalReservations((int) $object->id, $user, $langs);
		}

		$isProposal = strpos($action, 'LINEPROPAL_') === 0;
		$isOrder = strpos($action, 'LINEORDER_') === 0;
		$isContract = strpos($action, 'LINECONTRACT_') === 0;
		if (!$isProposal && !$isOrder && !$isContract) {
			return 0;
		}

		$lineId = 0;
		if (!empty($object->context['line_id'])) {
			$lineId = (int) $object->context['line_id'];
		} elseif (!empty($object->id)) {
			$lineId = (int) $object->id;
		} elseif (!empty(get_object_vars($object)['rowid'])) {
			$lineId = (int) get_object_vars($object)['rowid'];
		}
		if ($lineId <= 0) {
			return 0;
		}

		$elementType = $isProposal ? 'propaldet' : ($isOrder ? 'commandedet' : 'contratdet');
		if (substr($action, -7) === '_DELETE') {
			return $this->deleteLineReservation($elementType, $lineId);
		}

		$line = $this->fetchLine($elementType, $lineId);
		if (!$line || empty($line->fk_product) || (int) $line->product_type !== 1) {
			return $this->deleteLineReservation($elementType, $lineId);
		}

		$forceAvailability = $isContract && !empty($line->parent_status);
		return $this->synchronizeLineReservation($elementType, $line, $user, $langs, null, $forceAvailability);
	}

	/**
	 * Recheck and confirm every resource assignment when a contract is validated.
	 *
	 * @param int $contractId Contract id
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @return int<-1,1>
	 */
	private function synchronizeContractReservations($contractId, User $user, Translate $langs)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'contratdet WHERE fk_contrat = '.((int) $contractId).' ORDER BY rang, rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		while ($row = $this->db->fetch_object($resql)) {
			$line = $this->fetchLine('contratdet', (int) $row->rowid);
			if ($line && !empty($line->fk_product) && (int) $line->product_type === 1
				&& $this->synchronizeLineReservation('contratdet', $line, $user, $langs, true, true) < 0) {
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Recheck proposal capacity on validation while keeping assignments provisional.
	 *
	 * @param int $proposalId Proposal id
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @return int<-1,1>
	 */
	private function synchronizeProposalReservations($proposalId, User $user, Translate $langs)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'propaldet WHERE fk_propal = '.((int) $proposalId).' ORDER BY rang, rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		while ($row = $this->db->fetch_object($resql)) {
			$line = $this->fetchLine('propaldet', (int) $row->rowid);
			if ($line && !empty($line->fk_product) && (int) $line->product_type === 1
				&& $this->synchronizeLineReservation('propaldet', $line, $user, $langs, false, true) < 0) {
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Fetch normalized service line data.
	 *
	 * @param string $elementType propaldet, commandedet or contratdet
	 * @param int $lineId Line id
	 * @return object|null
	 */
	private function fetchLine($elementType, $lineId)
	{
		if ($elementType === 'propaldet') {
			$sql = 'SELECT d.rowid, d.fk_propal as parent_id, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
		} elseif ($elementType === 'commandedet') {
			$sql = 'SELECT d.rowid, d.fk_commande as parent_id, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'commandedet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
		} else {
			$sql = 'SELECT d.rowid, d.fk_contrat as parent_id, d.fk_product, d.product_type, d.qty,';
			$sql .= ' d.date_ouverture_prevue as date_start, d.date_fin_validite as date_end, p.duration as service_duration, c.statut as parent_status';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'contratdet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'contrat c ON c.rowid = d.fk_contrat';
		}
		$sql .= ' WHERE d.rowid = '.((int) $lineId);
		$resql = $this->db->query($sql);
		return $resql ? $this->db->fetch_object($resql) : null;
	}

	/**
	 * Delete assignments for a source line.
	 *
	 * @param string $elementType Source element type
	 * @param int $lineId Source line id
	 * @return int<-1,1>
	 */
	private function deleteLineReservation($elementType, $lineId)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND element_id = '.((int) $lineId);
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Assign every independent requirement, using grouped rows as alternatives.
	 *
	 * @param string $elementType Source element type
	 * @param object $line Normalized line
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @param bool|null $forceConfirmed Force final capacity allocation during validation
	 * @param bool $forceAvailability Recheck confirmed occupancy without confirming the assignment
	 * @return int<-1,1>
	 */
	private function synchronizeLineReservation($elementType, $line, User $user, Translate $langs, $forceConfirmed = null, $forceAvailability = false)
	{
		$unknownAvailabilityEnabled = (bool) getDolGlobalInt('RESOURCE_ENABLE_UNKNOWN_AVAILABILITY');
		if ($this->deleteLineReservation($elementType, (int) $line->rowid) < 0) {
			return -1;
		}

		$sql = 'SELECT er.*, r.fk_statut as resource_status, r.max_users, r.available_units, r.allow_overflow as resource_allow_overflow, r.metric_value, r.max_payload_weight, r.operational_location, r.cooldown_minutes, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid = er.resource_id';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
		$sql .= " WHERE er.element_type = 'product'";
		$sql .= ' AND er.element_id = '.((int) $line->fk_product);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= $unknownAvailabilityEnabled ? ' AND r.fk_statut IN (0, 1)' : ' AND r.fk_statut = 1';
		$sql .= ' ORDER BY er.requirement_group, er.position, er.rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		if (!$this->db->num_rows($resql)) {
			$sql = 'SELECT COUNT(*) as mandatory_count FROM '.MAIN_DB_PREFIX.'element_resources';
			$sql .= " WHERE element_type='product' AND element_id=".((int) $line->fk_product);
			$sql .= " AND resource_type='dolresource'";
			$sql .= " AND (relation_kind IS NULL OR relation_kind='requirement') AND mandatory=1";
			$mandatoryResult = $this->db->query($sql);
			$mandatoryRequirements = $mandatoryResult ? $this->db->fetch_object($mandatoryResult) : null;
			if (!$mandatoryResult) {
				$this->errors[] = $this->db->lasterror();
				return -1;
			}
			if ($mandatoryRequirements && (int) $mandatoryRequirements->mandatory_count > 0) {
				$langs->load('resource');
				$this->errors[] = $langs->trans('NoResourceAvailableForServiceLine');
				return -1;
			}
			return 1;
		}

		$isConfirmed = $forceConfirmed !== null
			? (bool) $forceConfirmed
			: ($elementType === 'contratdet' && !empty($line->parent_status));
		$checkAvailability = $isConfirmed || $forceAvailability;
		$groups = array();
		while ($preference = $this->db->fetch_object($resql)) {
			$group = !empty($preference->requirement_group) ? $preference->requirement_group : 'row_'.$preference->rowid;
			$groups[$group][] = $preference;
		}
		$manager = new ResourceReservationManager($this->db);
		$commonStart = !empty($line->date_start) ? $line->date_start : null;
		$commonEnd = !empty($line->date_end) ? $line->date_end : null;
		$temporalPolicy = $manager->getTemporalPolicy('product', (int) $line->fk_product);
		$langs->load('resource');
		if ($temporalPolicy['show_start'] && empty($commonStart)) {
			$this->errors[] = $langs->trans('ResourceStartTimeRequired');
			return -1;
		}
		if ($temporalPolicy['show_end'] && empty($commonEnd)) {
			$this->errors[] = $langs->trans('ResourceEndTimeRequired');
			return -1;
		}
		if ($temporalPolicy['calculate_end'] && !empty($commonStart) && empty($commonEnd)) {
			$duration = 0;
			foreach ($groups as $durationAlternatives) {
				foreach ($durationAlternatives as $durationRequirement) {
					$duration = max($duration, $manager->calculateDuration((array) $durationRequirement, (float) $line->qty));
				}
			}
			if ($duration <= 0 && !empty($line->service_duration)) $duration = $manager->durationStringToMinutes($line->service_duration);
			if ($duration <= 0) {
				$this->errors[] = $langs->trans('ResourceDurationRequiredForCalculatedEnd');
				return -1;
			}
			$commonEnd = $this->db->idate($this->db->jdate($commonStart) + ($duration * 60));
		}
		$confirmedAssignments = array();
		$plannedCapacity = array();
		$plannedUnits = array();
		$bulkAvailabilityLoaded = false;
		if ($checkAvailability && !empty($commonStart) && !empty($commonEnd)) {
			$resourceIds = array();
			$maximumCooldown = 0;
			foreach ($groups as $availabilityAlternatives) {
				foreach ($availabilityAlternatives as $availabilityRequirement) {
					$resourceIds[] = (int) $availabilityRequirement->resource_id;
					$maximumCooldown = max($maximumCooldown, (int) $availabilityRequirement->cooldown_minutes);
				}
			}
			$loadEnd = $this->db->idate($this->db->jdate($commonEnd) + ($maximumCooldown * 60));
			$confirmedAssignments = $manager->loadConfirmedAssignments('dolresource', $resourceIds, $commonStart, $loadEnd);
			$bulkAvailabilityLoaded = true;
		}
		foreach ($groups as $alternatives) {
			if (!empty($alternatives[0]->selection_policy) && $alternatives[0]->selection_policy === 'smallest_sufficient') {
				usort($alternatives, static function (stdClass $left, stdClass $right) {
					return (float) $left->metric_value <=> (float) $right->metric_value;
				});
			}
			$selected = null;
			foreach ($alternatives as $preference) {
				$perUnit = (float) $preference->users_per_service_unit;
				$capacityUsed = $this->calculateContextCapacityDemand($manager, $elementType, $line, $preference, $perUnit);
				$payloadWeightUsed = $preference->capacity_metrics === 'volume_weight'
					? $manager->calculateDocumentProductWeight($elementType, (int) $line->parent_id)
					: 0.0;
				$dateStart = $commonStart;
				$dateEnd = $commonEnd;
				$cooldown = max(0, (int) $preference->cooldown_minutes);
				$availableUnits = max(1, (int) $preference->available_units);
				$requiredUnits = max(1, (int) ceil(abs((float) $line->qty) * max(1.0, (float) $preference->quantity_required)));
				$capacityPerUnit = $preference->capacity_mode === 'users'
					? (float) $preference->max_users
					: ($preference->capacity_mode === 'volume' ? (float) $preference->metric_value : 1.0);
				$maximumCapacity = $capacityPerUnit * $availableUnits;
				if (!empty($dateEnd) && $cooldown > 0) {
					$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
				}
				$needsAutomaticSlot = (empty($dateStart) || empty($dateEnd)) && $preference->scheduling_mode === 'next_available';
				if ($needsAutomaticSlot) {
					$duration = $manager->calculateDuration((array) $preference, (float) $line->qty) + $cooldown;
					if ($duration <= 0 && !empty($line->service_duration)) {
						$duration = $manager->durationStringToMinutes($line->service_duration);
					}
					$slot = $manager->findNextAvailable('dolresource', (int) $preference->resource_id, dol_now(), dol_time_plus_duree(dol_now(), 90, 'd'), $duration, $capacityUsed, $maximumCapacity);
					if ($slot) {
						$dateStart = $slot['date_start'];
						$dateEnd = $slot['date_end'];
					}
				}
				if ($needsAutomaticSlot && (empty($dateStart) || empty($dateEnd))) {
					continue;
				}
				$maximumPayload = (float) $preference->max_payload_weight * $requiredUnits;
				$fitsPayload = $payloadWeightUsed <= 0 || ($maximumPayload > 0 && $payloadWeightUsed <= $maximumPayload);
				$fitsLocation = empty($preference->required_location)
					|| strcasecmp(trim((string) $preference->required_location), trim((string) $preference->operational_location)) === 0;
				$fitsResourceCapacity = $capacityUsed > 0 && $capacityUsed <= ($capacityPerUnit * $requiredUnits) && $requiredUnits <= $availableUnits && $fitsPayload && $fitsLocation;
				$capacityKey = ((int) $preference->resource_id).'|'.$dateStart.'|'.$dateEnd;
				$occupied = 0.0;
				$occupiedUnits = 0;
				if ($checkAvailability && !empty($dateStart) && !empty($dateEnd)) {
					$occupied = $bulkAvailabilityLoaded
						? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd)
						: $manager->getOccupiedCapacity('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd);
					$occupied += $plannedCapacity[$capacityKey] ?? 0.0;
					$occupiedUnits = $bulkAvailabilityLoaded
						? $manager->getOccupiedResourceUnitsFromAssignments($confirmedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd)
						: 0;
					$occupiedUnits += $plannedUnits[$capacityKey] ?? 0;
				}
				$canAllocate = !$checkAvailability ? $fitsResourceCapacity : ($fitsResourceCapacity && !empty($dateStart) && !empty($dateEnd) && ($occupied + $capacityUsed) <= $maximumCapacity && ($occupiedUnits + $requiredUnits) <= $availableUnits);
				if ($canAllocate) {
					$selected = $preference;
					$selected->load_volume_used = $preference->capacity_mode === 'volume' ? $capacityUsed : 0.0;
					$selected->capacity_used = $preference->capacity_mode === 'volume' ? $maximumCapacity : $capacityUsed;
					$selected->resource_units_used = $requiredUnits;
					$selected->payload_weight_used = $payloadWeightUsed;
					$selected->assignment_start = $dateStart;
					$selected->assignment_end = $dateEnd;
					break;
				}
			}
			// When the complete quantity cannot fit in one alternative, allocate
			// whole service units independently.  This is the usual hotel-room
			// case: quantity 2 must reserve two rooms of capacity 2, not consume
			// four places from a single room.
			$splitAssignments = array();
			if (!$selected && in_array($alternatives[0]->capacity_metrics, array('volume', 'volume_weight'), true)) {
				$remainingVolume = $this->calculateContextCapacityDemand($manager, $elementType, $line, $alternatives[0], 1.0);
				$remainingWeight = $alternatives[0]->capacity_metrics === 'volume_weight'
					? $manager->calculateDocumentProductWeight($elementType, (int) $line->parent_id)
					: 0.0;
				foreach ($alternatives as $preference) {
					if (empty($preference->resource_allow_overflow) || empty($commonStart) || empty($commonEnd)) {
						continue;
					}
					if (!empty($preference->required_location)
						&& strcasecmp(trim((string) $preference->required_location), trim((string) $preference->operational_location)) !== 0) {
						continue;
					}
					$maximumVolume = (float) $preference->metric_value;
					$maximumWeight = (float) $preference->max_payload_weight;
					$volumeRatio = $remainingVolume > 0 ? min(1.0, $maximumVolume / $remainingVolume) : 0.0;
					$weightRatio = $remainingWeight > 0 ? min(1.0, $maximumWeight / $remainingWeight) : 1.0;
					$allocationRatio = min($volumeRatio, $weightRatio);
					if ($allocationRatio <= 0) {
						continue;
					}
					$occupied = $checkAvailability
						? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, (int) $preference->resource_id, $commonStart, $commonEnd)
						: 0.0;
					if ($occupied > 0) {
						continue;
					}
					$overflowSelection = clone $preference;
					$overflowSelection->load_volume_used = $remainingVolume * $allocationRatio;
					$overflowSelection->payload_weight_used = $remainingWeight * $allocationRatio;
					$overflowSelection->capacity_used = $maximumVolume;
					$overflowSelection->resource_units_used = 1;
					$overflowSelection->assignment_start = $commonStart;
					$overflowSelection->assignment_end = $commonEnd;
					$splitAssignments[] = $overflowSelection;
					$remainingVolume -= $overflowSelection->load_volume_used;
					$remainingWeight -= $overflowSelection->payload_weight_used;
					if ($remainingVolume <= 0.000001 && $remainingWeight <= 0.000001) {
						break;
					}
				}
				if ($remainingVolume > 0.000001 || $remainingWeight > 0.000001) {
					$splitAssignments = array();
				}
			}
			$wholeQuantity = (int) abs((float) $line->qty);
			if (!$selected && empty($splitAssignments) && $wholeQuantity > 1 && (float) $wholeQuantity === abs((float) $line->qty) && !in_array($alternatives[0]->capacity_metrics, array('volume', 'volume_weight'), true)) {
				$stagedCapacity = array();
				$stagedUnits = array();
				for ($unit = 0; $unit < $wholeQuantity; $unit++) {
					$unitSelection = null;
					foreach ($alternatives as $preference) {
						$capacityUsed = (float) $preference->users_per_service_unit;
						$dateStart = $commonStart;
						$dateEnd = $commonEnd;
						$cooldown = max(0, (int) $preference->cooldown_minutes);
						$availableUnits = max(1, (int) $preference->available_units);
						$capacityPerUnit = ($preference->capacity_mode === 'users') ? (float) $preference->max_users : 1.0;
						$maximumCapacity = $capacityPerUnit * $availableUnits;
						if (!empty($dateEnd) && $cooldown > 0) {
							$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
						}
						if (empty($dateStart) || empty($dateEnd) || $capacityUsed <= 0 || $capacityUsed > $maximumCapacity) {
							continue;
						}
						$resourceId = (int) $preference->resource_id;
						$capacityKey = $resourceId.'|'.$dateStart.'|'.$dateEnd;
						$alreadyStaged = isset($stagedCapacity[$capacityKey]) ? $stagedCapacity[$capacityKey] : 0.0;
						$unitsAlreadyStaged = $stagedUnits[$capacityKey] ?? 0;
						$occupied = 0.0;
						$occupiedUnits = 0;
						if ($checkAvailability) {
							$occupied = $bulkAvailabilityLoaded
								? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, $resourceId, $dateStart, $dateEnd)
								: $manager->getOccupiedCapacity('dolresource', $resourceId, $dateStart, $dateEnd);
							$occupied += $plannedCapacity[$capacityKey] ?? 0.0;
							$occupiedUnits = $manager->getOccupiedResourceUnitsFromAssignments($confirmedAssignments, $resourceId, $dateStart, $dateEnd);
							$occupiedUnits += $plannedUnits[$capacityKey] ?? 0;
						}
						if (($occupied + $alreadyStaged + $capacityUsed) > $maximumCapacity || ($occupiedUnits + $unitsAlreadyStaged + 1) > $availableUnits) {
							continue;
						}
						$unitSelection = clone $preference;
						$unitSelection->capacity_used = $capacityUsed;
						$unitSelection->resource_units_used = 1;
						$unitSelection->assignment_start = $dateStart;
						$unitSelection->assignment_end = $dateEnd;
						$stagedCapacity[$capacityKey] = $alreadyStaged + $capacityUsed;
						$stagedUnits[$capacityKey] = $unitsAlreadyStaged + 1;
						break;
					}
					if (!$unitSelection) {
						$splitAssignments = array();
						break;
					}
					$splitAssignments[] = $unitSelection;
				}
			}
			if (!empty($splitAssignments)) {
				foreach ($splitAssignments as $splitAssignment) {
					$reservationStatus = $isConfirmed ? ResourceReservationManager::STATUS_CONFIRMED : ResourceReservationManager::STATUS_PROVISIONAL;
					$assignmentId = $this->insertAssignment($elementType, $line, $splitAssignment, 1.0, $reservationStatus, $user);
					if (!$assignmentId) {
						$this->deleteLineReservation($elementType, (int) $line->rowid);
						return -1;
					}
					$capacityKey = ((int) $splitAssignment->resource_id).'|'.$splitAssignment->assignment_start.'|'.$splitAssignment->assignment_end;
					$plannedCapacity[$capacityKey] = ($plannedCapacity[$capacityKey] ?? 0.0) + (float) $splitAssignment->capacity_used;
					$plannedUnits[$capacityKey] = ($plannedUnits[$capacityKey] ?? 0) + (int) $splitAssignment->resource_units_used;
				}
				continue;
			}
			if (!$selected) {
				if (empty($alternatives[0]->mandatory)) {
					continue;
				}
				$langs->load('resource');
				$this->errors[] = $langs->trans('NoResourceAvailableForServiceLine');
				return -1;
			}
			if (!empty($selected->simultaneous) && empty($commonStart) && !empty($selected->assignment_start)) {
				$commonStart = $selected->assignment_start;
				$commonEnd = $selected->assignment_end;
			}
			$reservationStatus = $isConfirmed ? ResourceReservationManager::STATUS_CONFIRMED : ResourceReservationManager::STATUS_PROVISIONAL;
			$assignmentId = $this->insertAssignment($elementType, $line, $selected, (float) $line->qty, $reservationStatus, $user);
			if (!$assignmentId) {
				return -1;
			}
			$capacityKey = ((int) $selected->resource_id).'|'.$selected->assignment_start.'|'.$selected->assignment_end;
			$plannedCapacity[$capacityKey] = ($plannedCapacity[$capacityKey] ?? 0.0) + (float) $selected->capacity_used;
			$plannedUnits[$capacityKey] = ($plannedUnits[$capacityKey] ?? 0) + (int) $selected->resource_units_used;
		}
		return 1;
	}

	/**
	 * Calculate the demand represented by a service requirement.
	 *
	 * @param ResourceReservationManager $manager     Reservation manager
	 * @param string                     $elementType Source line type
	 * @param object                     $line        Normalized service line
	 * @param object                     $preference  Requirement options
	 * @param float                      $perUnit     Default capacity per service unit
	 * @return float
	 */
	private function calculateContextCapacityDemand(ResourceReservationManager $manager, $elementType, $line, $preference, $perUnit)
	{
		if ($preference->context_scope === 'same_proposal'
			&& $preference->demand_source === 'product_lines'
			&& in_array($preference->capacity_metrics, array('volume', 'volume_weight'), true)) {
			return $manager->calculateDocumentProductVolume($elementType, (int) $line->parent_id);
		}

		return abs((float) $line->qty) * $perUnit;
	}

	/**
	 * Insert one normalized assignment row.
	 *
	 * @param string   $elementType    Source element type
	 * @param object   $line           Source line
	 * @param object   $selected       Selected requirement
	 * @param float    $serviceQuantity Service quantity
	 * @param string   $reservationStatus Reservation status
	 * @param User     $user           Acting user
	 * @return int|false Assignment row id, or false on error
	 */
	private function insertAssignment($elementType, $line, $selected, $serviceQuantity, $reservationStatus, User $user)
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'element_resources (';
		$sql .= 'element_id, element_type, resource_id, resource_type, busy, mandatory, position, relation_kind, resource_role, requirement_group,';
		$sql .= ' users_per_service_unit, service_quantity, service_duration, capacity_used, resource_units_used, load_volume_used, payload_weight_used, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $line->rowid).", '".$this->db->escape($elementType)."', ".((int) $selected->resource_id).", 'dolresource', 1, ".(!empty($selected->mandatory) ? 1 : 0).", ".((int) $selected->position).", 'assignment', '";
		$sql .= $this->db->escape($selected->resource_role ?: 'capacity')."', ".(!empty($selected->requirement_group) ? "'".$this->db->escape($selected->requirement_group)."'" : 'NULL').', ';
		$sql .= price2num($selected->users_per_service_unit, 'MS').', '.price2num($serviceQuantity, 'MS').', ';
		$sql .= (!empty($line->service_duration) ? "'".$this->db->escape($line->service_duration)."'" : 'NULL').', '.price2num($selected->capacity_used, 'MS').', '.max(1, (int) $selected->resource_units_used).', ';
		$sql .= price2num(!empty($selected->load_volume_used) ? $selected->load_volume_used : 0, 'MS').', ';
		$sql .= price2num(!empty($selected->payload_weight_used) ? $selected->payload_weight_used : 0, 'MS').', ';
		$sql .= (!empty($selected->assignment_start) ? "'".$this->db->escape($selected->assignment_start)."'" : 'NULL').', ';
		$sql .= (!empty($selected->assignment_end) ? "'".$this->db->escape($selected->assignment_end)."'" : 'NULL').", '".$this->db->escape($reservationStatus)."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'element_resources');
	}
}
