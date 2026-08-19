<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/core/triggers/interface_99_modResource_ResourceReservations.class.php
 * \ingroup resource
 * \brief Synchronize service-line resource reservations.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcesupplyrequestmanager.class.php';

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
		if (strpos($action, 'ORDER_SUPPLIER_') === 0) {
			$manager = new ResourceSupplyRequestManager($this->db);
			return $manager->handleSupplierOrderTrigger($action, (int) $object->id, $user);
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

		return $this->synchronizeLineReservation($elementType, $line, $user, $langs);
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
				&& $this->synchronizeLineReservation('contratdet', $line, $user, $langs, true) < 0) {
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
			$sql = 'SELECT d.rowid, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
		} elseif ($elementType === 'commandedet') {
			$sql = 'SELECT d.rowid, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'commandedet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
		} else {
			$sql = 'SELECT d.rowid, d.fk_product, d.product_type, d.qty,';
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
		if ($this->deleteLineReservation($elementType, (int) $line->rowid) < 0) {
			return -1;
		}

		$sql = 'SELECT er.*, r.max_users, r.cooldown_minutes, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid = er.resource_id';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
		$sql .= " WHERE er.element_type = 'product'";
		$sql .= ' AND er.element_id = '.((int) $line->fk_product);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' AND r.fk_statut = 1';
		$sql .= ' ORDER BY er.requirement_group, er.position, er.rowid';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->num_rows($resql)) {
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
			$selected = null;
			foreach ($alternatives as $preference) {
				$perUnit = (float) $preference->users_per_service_unit;
				$capacityUsed = abs((float) $line->qty) * $perUnit;
				$dateStart = $commonStart;
				$dateEnd = $commonEnd;
				$cooldown = max(0, (int) $preference->cooldown_minutes);
				$maximumCapacity = ($preference->capacity_mode === 'users') ? (float) $preference->max_users : 1.0;
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
				$fitsResourceCapacity = $capacityUsed > 0 && $capacityUsed <= $maximumCapacity;
				$capacityKey = ((int) $preference->resource_id).'|'.$dateStart.'|'.$dateEnd;
				$occupied = 0.0;
				if ($checkAvailability && !empty($dateStart) && !empty($dateEnd)) {
					$occupied = $bulkAvailabilityLoaded
						? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd)
						: $manager->getOccupiedCapacity('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd);
					$occupied += $plannedCapacity[$capacityKey] ?? 0.0;
				}
				$canAllocate = !$checkAvailability ? $fitsResourceCapacity : ($fitsResourceCapacity && !empty($dateStart) && !empty($dateEnd) && ($occupied + $capacityUsed) <= $maximumCapacity);
				if ($canAllocate) {
					$selected = $preference;
					$selected->capacity_used = $capacityUsed;
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
			$wholeQuantity = (int) abs((float) $line->qty);
			if (!$selected && $wholeQuantity > 1 && (float) $wholeQuantity === abs((float) $line->qty)) {
				$stagedCapacity = array();
				for ($unit = 0; $unit < $wholeQuantity; $unit++) {
					$unitSelection = null;
					foreach ($alternatives as $preference) {
						$capacityUsed = (float) $preference->users_per_service_unit;
						$dateStart = $commonStart;
						$dateEnd = $commonEnd;
						$cooldown = max(0, (int) $preference->cooldown_minutes);
						$maximumCapacity = ($preference->capacity_mode === 'users') ? (float) $preference->max_users : 1.0;
						if (!empty($dateEnd) && $cooldown > 0) {
							$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
						}
						if (empty($dateStart) || empty($dateEnd) || $capacityUsed <= 0 || $capacityUsed > $maximumCapacity) {
							continue;
						}
						$resourceId = (int) $preference->resource_id;
						$capacityKey = $resourceId.'|'.$dateStart.'|'.$dateEnd;
						$alreadyStaged = isset($stagedCapacity[$capacityKey]) ? $stagedCapacity[$capacityKey] : 0.0;
						$occupied = 0.0;
						if ($checkAvailability) {
							$occupied = $bulkAvailabilityLoaded
								? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, $resourceId, $dateStart, $dateEnd)
								: $manager->getOccupiedCapacity('dolresource', $resourceId, $dateStart, $dateEnd);
							$occupied += $plannedCapacity[$capacityKey] ?? 0.0;
						}
						if (($occupied + $alreadyStaged + $capacityUsed) > $maximumCapacity) {
							continue;
						}
						$unitSelection = clone $preference;
						$unitSelection->capacity_used = $capacityUsed;
						$unitSelection->assignment_start = $dateStart;
						$unitSelection->assignment_end = $dateEnd;
						$stagedCapacity[$capacityKey] = $alreadyStaged + $capacityUsed;
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
					if (!$this->insertAssignment($elementType, $line, $splitAssignment, 1.0, $isConfirmed, $user)) {
						$this->deleteLineReservation($elementType, (int) $line->rowid);
						return -1;
					}
					$capacityKey = ((int) $splitAssignment->resource_id).'|'.$splitAssignment->assignment_start.'|'.$splitAssignment->assignment_end;
					$plannedCapacity[$capacityKey] = ($plannedCapacity[$capacityKey] ?? 0.0) + (float) $splitAssignment->capacity_used;
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
			if (!$this->insertAssignment($elementType, $line, $selected, (float) $line->qty, $isConfirmed, $user)) {
				return -1;
			}
			$capacityKey = ((int) $selected->resource_id).'|'.$selected->assignment_start.'|'.$selected->assignment_end;
			$plannedCapacity[$capacityKey] = ($plannedCapacity[$capacityKey] ?? 0.0) + (float) $selected->capacity_used;
		}
		return 1;
	}

	/**
	 * Insert one normalized assignment row.
	 *
	 * @param string   $elementType    Source element type
	 * @param object   $line           Source line
	 * @param object   $selected       Selected requirement
	 * @param float    $serviceQuantity Service quantity
	 * @param bool     $isConfirmed    Whether the assignment is confirmed
	 * @param User     $user           Acting user
	 * @return bool
	 */
	private function insertAssignment($elementType, $line, $selected, $serviceQuantity, $isConfirmed, User $user)
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'element_resources (';
		$sql .= 'element_id, element_type, resource_id, resource_type, busy, mandatory, position, relation_kind, resource_role, requirement_group,';
		$sql .= ' users_per_service_unit, service_quantity, service_duration, capacity_used, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $line->rowid).", '".$this->db->escape($elementType)."', ".((int) $selected->resource_id).", 'dolresource', 1, ".(!empty($selected->mandatory) ? 1 : 0).", ".((int) $selected->position).", 'assignment', '";
		$sql .= $this->db->escape($selected->resource_role ?: 'capacity')."', ".(!empty($selected->requirement_group) ? "'".$this->db->escape($selected->requirement_group)."'" : 'NULL').', ';
		$sql .= price2num($selected->users_per_service_unit, 'MS').', '.price2num($serviceQuantity, 'MS').', ';
		$sql .= (!empty($line->service_duration) ? "'".$this->db->escape($line->service_duration)."'" : 'NULL').', '.price2num($selected->capacity_used, 'MS').', ';
		$sql .= (!empty($selected->assignment_start) ? "'".$this->db->escape($selected->assignment_start)."'" : 'NULL').', ';
		$sql .= (!empty($selected->assignment_end) ? "'".$this->db->escape($selected->assignment_end)."'" : 'NULL').", '".($isConfirmed ? 'confirmed' : 'provisional')."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}
		return true;
	}
}
