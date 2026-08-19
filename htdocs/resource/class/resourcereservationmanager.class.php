<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/class/resourcereservationmanager.class.php
 * \ingroup resource
 * \brief Preview and apply resource reservation reassignment.
 */

/**
 * Manage the impact of manual resource availability changes.
 */
class ResourceReservationManager
{
	const STATUS_PROVISIONAL = 'provisional';
	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_CANCELED = 'canceled';
	const STATUS_UNAVAILABLE = 'unavailable';
	const STATUS_AWAITING_SUPPLY = 'awaiting_supply';

	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return resource requirements configured on an element.
	 *
	 * Empty requirement_group values form independent groups. Equal non-empty
	 * values define ordered alternatives.
	 *
	 * @param string $elementType Requirement source type
	 * @param int $elementId Requirement source id
	 * @return array<int,array<string,mixed>>
	 */
	public function getRequirements($elementType, $elementId)
	{
		$sql = 'SELECT er.* FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " WHERE er.element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND er.element_id = '.((int) $elementId);
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' ORDER BY er.requirement_group, er.position, er.rowid';
		$resql = $this->db->query($sql);
		$requirements = array();
		if ($resql) {
			while ($row = $this->db->fetch_array($resql)) {
				$requirements[] = $row;
			}
		}
		return $requirements;
	}

	/**
	 * Combine resource requirements into the effective line time-input policy.
	 *
	 * @param string $elementType product or service
	 * @param int $elementId Product/service id
	 * @return array<string,mixed>
	 */
	public function getTemporalPolicy($elementType, $elementId)
	{
		$requirements = $this->getRequirements($elementType, $elementId);
		$policy = array('has_requirements' => !empty($requirements), 'start_input_mode' => 'none', 'end_input_mode' => 'none', 'time_precision' => 'day', 'show_start' => false, 'show_end' => false, 'calculate_end' => false, 'automatic' => false);
		$startRank = array('none' => 0, 'date' => 1, 'datetime' => 2);
		$endRank = array('none' => 0, 'calculated' => 1, 'date' => 2, 'datetime' => 3);
		$precisionRank = array('day' => 0, 'hour' => 1, 'minute' => 2, 'second' => 3);
		foreach ($requirements as $requirement) {
			$start = isset($startRank[$requirement['start_input_mode']]) ? $requirement['start_input_mode'] : 'none';
			$end = isset($endRank[$requirement['end_input_mode']]) ? $requirement['end_input_mode'] : 'none';
			$precision = isset($precisionRank[$requirement['time_precision']]) ? $requirement['time_precision'] : 'minute';
			if ($startRank[$start] > $startRank[$policy['start_input_mode']]) $policy['start_input_mode'] = $start;
			if ($endRank[$end] > $endRank[$policy['end_input_mode']]) $policy['end_input_mode'] = $end;
			if ($precisionRank[$precision] > $precisionRank[$policy['time_precision']]) $policy['time_precision'] = $precision;
			if ($requirement['scheduling_mode'] === 'next_available') $policy['automatic'] = true;
		}
		$policy['show_start'] = $policy['start_input_mode'] !== 'none';
		$policy['show_end'] = in_array($policy['end_input_mode'], array('date', 'datetime'), true);
		$policy['calculate_end'] = $policy['end_input_mode'] === 'calculated';
		return $policy;
	}

	/**
	 * Calculate occupied minutes from requirement metadata.
	 *
	 * @param array<string,mixed> $requirement Requirement row
	 * @param float $quantity Sold or produced quantity
	 * @return int
	 */
	public function calculateDuration(array $requirement, $quantity)
	{
		return (int) (max(0, (int) $requirement['setup_duration'])
			+ max(0, (int) $requirement['duration_base'])
			+ (max(0, (int) $requirement['duration_per_unit']) * abs((float) $quantity))
			+ max(0, (int) $requirement['cleanup_duration']));
	}

	/** @param string $duration Dolibarr duration such as 30i, 2h or 1d @return int Minutes */
	public function durationStringToMinutes($duration)
	{
		if (!preg_match('/^([0-9]+)([a-z])$/i', (string) $duration, $matches)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		return (int) round(convertDurationtoHour((int) $matches[1], strtolower($matches[2])) * 60);
	}

	/**
	 * Find the first available interval for a resource.
	 *
	 * Resource time-slot rules are optional. With no rules, the resource is
	 * considered continuously open. The search uses 15-minute boundaries.
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param int $earliestStart Earliest timestamp
	 * @param int $latestEnd Latest timestamp
	 * @param int $durationMinutes Required duration
	 * @param float $capacity Required capacity
	 * @param float|null $maximumCapacity Explicit capacity
	 * @return array{date_start:string,date_end:string}|null
	 */
	public function findNextAvailable($resourceType, $resourceId, $earliestStart, $latestEnd, $durationMinutes, $capacity = 1.0, $maximumCapacity = null)
	{
		if ($durationMinutes <= 0 || $earliestStart <= 0 || $latestEnd <= $earliestStart) {
			return null;
		}
		$rules = array();
		if ($resourceType === 'dolresource') {
			$sql = 'SELECT slot_type, date_start, date_end, weekday, time_start, time_end';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'resource_time_slot';
			$sql .= ' WHERE fk_resource = '.((int) $resourceId).' AND active = 1';
			$resql = $this->db->query($sql);
			while ($resql && ($rule = $this->db->fetch_array($resql))) {
				$rules[] = $rule;
			}
		}
		$cursor = (int) (ceil($earliestStart / 900) * 900);
		$durationSeconds = $durationMinutes * 60;
		while ($cursor + $durationSeconds <= $latestEnd) {
			$end = $cursor + $durationSeconds;
			if ($this->matchesOpeningRule($cursor, $end, $rules)
				&& $this->canReserve($resourceType, $resourceId, $this->db->idate($cursor), $this->db->idate($end), $capacity, $maximumCapacity)) {
				return array('date_start' => $this->db->idate($cursor), 'date_end' => $this->db->idate($end));
			}
			$cursor += 900;
		}
		return null;
	}

	/** @param int $start Start timestamp @param int $end End timestamp @param array<int,array<string,mixed>> $rules Rules @return bool */
	private function matchesOpeningRule($start, $end, array $rules)
	{
		if (empty($rules)) {
			return true;
		}
		$date = dol_getdate($start);
		$dayStart = dol_mktime(0, 0, 0, $date['mon'], $date['mday'], $date['year'], 'tzuserrel');
		$weekday = (int) date('N', $start);
		foreach ($rules as $rule) {
			if ($rule['slot_type'] === 'absolute') {
				$ruleStart = $this->db->jdate($rule['date_start']);
				$ruleEnd = $this->db->jdate($rule['date_end']);
				if ($start >= $ruleStart && $end <= $ruleEnd) {
					return true;
				}
			} elseif ($rule['slot_type'] === 'weekly' && (int) $rule['weekday'] === $weekday) {
				if ($start >= $dayStart + (int) $rule['time_start'] && $end <= $dayStart + (int) $rule['time_end']) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return occupied capacity for a half-open interval [start, end).
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param string $dateStart Database date start
	 * @param string $dateEnd Database date end
	 * @param int $excludeRowId Assignment to ignore
	 * @return float
	 */
	public function getOccupiedCapacity($resourceType, $resourceId, $dateStart, $dateEnd, $excludeRowId = 0)
	{
		$sql = 'SELECT COALESCE(SUM(CASE WHEN capacity_used IS NULL THEN 1 ELSE capacity_used END), 0) as occupied';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id = '.((int) $resourceId);
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= " AND reservation_status = 'confirmed'";
		$sql .= " AND (relation_kind = 'assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (date_start IS NULL OR date_start < '".$this->db->escape($dateEnd)."')";
		if ($excludeRowId > 0) {
			$sql .= ' AND rowid <> '.((int) $excludeRowId);
		}
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (float) $obj->occupied : 0.0;
	}

	/**
	 * Check a resource capacity using the common assignment ledger.
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param string $dateStart Database date start
	 * @param string $dateEnd Database date end
	 * @param float $requiredCapacity Requested capacity
	 * @param float|null $maximumCapacity Explicit capacity for virtual resources
	 * @return bool
	 */
	public function canReserve($resourceType, $resourceId, $dateStart, $dateEnd, $requiredCapacity = 1.0, $maximumCapacity = null)
	{
		if (empty($dateStart) || empty($dateEnd) || $dateStart >= $dateEnd || $requiredCapacity <= 0) {
			return false;
		}
		if ($maximumCapacity === null && $resourceType === 'dolresource') {
			$sql = 'SELECT r.max_users, r.fk_statut, ty.capacity_mode FROM '.MAIN_DB_PREFIX.'resource r';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
			$sql .= ' WHERE r.rowid = '.((int) $resourceId);
			$obj = $this->db->fetch_object($this->db->query($sql));
			if (!$obj || (int) $obj->fk_statut !== 1) {
				return false;
			}
			$maximumCapacity = ($obj->capacity_mode === 'users') ? (float) $obj->max_users : 1.0;
		}
		if ($maximumCapacity === null) {
			$maximumCapacity = 1.0;
		}
		if ($maximumCapacity <= 0 || $requiredCapacity > $maximumCapacity) {
			return false;
		}
		return ($this->getOccupiedCapacity($resourceType, $resourceId, $dateStart, $dateEnd) + $requiredCapacity) <= $maximumCapacity;
	}

	/**
	 * Create one assignment after rechecking capacity.
	 *
	 * @param array<string,mixed> $assignment Normalized assignment
	 * @param User $user Current user
	 * @return int Assignment id, -1 on conflict or database error
	 */
	public function createAssignment(array $assignment, User $user)
	{
		$resourceType = $assignment['resource_type'];
		$resourceId = (int) $assignment['resource_id'];
		$capacity = isset($assignment['capacity_used']) ? (float) $assignment['capacity_used'] : 1.0;
		$maximum = isset($assignment['maximum_capacity']) ? (float) $assignment['maximum_capacity'] : null;
		$lockSql = '';
		if ($resourceType === 'dolresource') {
			$lockSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'resource WHERE rowid = '.$resourceId.' FOR UPDATE';
		} elseif ($resourceType === 'bookcal_calendar') {
			$lockSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'bookcal_calendar WHERE rowid = '.$resourceId.' FOR UPDATE';
		}
		if ($lockSql && !$this->db->query($lockSql)) {
			return -1;
		}
		if (!$this->canReserve($resourceType, $resourceId, $assignment['date_start'], $assignment['date_end'], $capacity, $maximum)) {
			return -1;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'element_resources (';
		$sql .= 'element_id, element_type, resource_id, resource_type, busy, mandatory, position, relation_kind, resource_role,';
		$sql .= ' service_quantity, capacity_used, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $assignment['element_id']).", '".$this->db->escape($assignment['element_type'])."', ".$resourceId.", '".$this->db->escape($resourceType)."', 1, ";
		$sql .= (!empty($assignment['mandatory']) ? 1 : 0).', '.((int) (!empty($assignment['position']) ? $assignment['position'] : 0));
		$sql .= ", 'assignment', '".$this->db->escape(!empty($assignment['resource_role']) ? $assignment['resource_role'] : 'capacity')."', ";
		$sql .= price2num(isset($assignment['service_quantity']) ? $assignment['service_quantity'] : 1, 'MS').', '.price2num($capacity, 'MS').', ';
		$sql .= "'".$this->db->escape($assignment['date_start'])."', '".$this->db->escape($assignment['date_end'])."', '";
		$sql .= $this->db->escape(!empty($assignment['reservation_status']) ? $assignment['reservation_status'] : self::STATUS_CONFIRMED)."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'element_resources');
	}

	/**
	 * Remove assignments generated by a source element.
	 *
	 * @param string $elementType Source type
	 * @param int $elementId Source id
	 * @return int
	 */
	public function deleteAssignments($elementType, $elementId)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."' AND element_id = ".((int) $elementId);
		$sql .= " AND (relation_kind = 'assignment' OR reservation_status IS NOT NULL)";
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Preview reassignment of current and future reservations.
	 *
	 * @param int $resourceId Resource becoming unavailable
	 * @return array<int,array<string,mixed>>
	 */
	public function previewOutOfService($resourceId)
	{
		$reservations = $this->fetchAffectedReservations($resourceId);
		$planned = array();
		foreach ($reservations as &$reservation) {
			$reservation['replacement'] = $this->findReplacement($reservation, $planned);
			if (!empty($reservation['replacement']) && $reservation['reservation_status'] === 'confirmed') {
				$planned[] = array(
					'resource_id' => $reservation['replacement']['resource_id'],
					'capacity_used' => $reservation['replacement']['capacity_used'],
					'date_start' => $reservation['date_start'],
					'date_end' => $reservation['date_end'],
				);
			}
		}
		unset($reservation);
		return $reservations;
	}

	/**
	 * Apply a previously previewed impact.
	 *
	 * @param int $resourceId Resource becoming unavailable
	 * @param array<int,array<string,mixed>> $impact Preview result
	 * @return int Number of processed reservations, -1 on error
	 */
	public function applyOutOfService($resourceId, array $impact)
	{
		$this->db->begin();
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET fk_statut=2 WHERE rowid='.((int) $resourceId);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return -1;
		}
		foreach ($impact as $reservation) {
			if (!empty($reservation['replacement'])) {
				$replacement = $reservation['replacement'];
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET';
				$sql .= ' resource_id = '.((int) $replacement['resource_id']).',';
				$sql .= ' position = '.((int) $replacement['position']).',';
				$sql .= ' users_per_service_unit = '.price2num($replacement['users_per_service_unit'], 'MS').',';
				$sql .= ' capacity_used = '.price2num($replacement['capacity_used'], 'MS');
				$sql .= ' WHERE rowid = '.((int) $reservation['rowid']);
			} else {
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources';
				$sql .= " SET reservation_status = 'unavailable'";
				$sql .= ' WHERE rowid = '.((int) $reservation['rowid']);
			}
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return count($impact);
	}

	/** @return array<int,array<string,mixed>> */
	private function fetchAffectedReservations($resourceId)
	{
		$sql = "SELECT er.*, pd.fk_product, p.ref as document_ref, prod.ref as service_ref";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."propaldet pd ON pd.rowid=er.element_id AND er.element_type='propaldet'";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'propal p ON p.rowid=pd.fk_propal';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product prod ON prod.rowid=pd.fk_product';
		$sql .= ' WHERE er.resource_id='.((int) $resourceId)." AND er.reservation_status='provisional'";
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->idate(dol_now())."')";
		$sql .= ' UNION ALL ';
		$sql .= "SELECT er.*, cd.fk_product, c.ref as document_ref, prod.ref as service_ref";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."contratdet cd ON cd.rowid=er.element_id AND er.element_type='contratdet'";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'contrat c ON c.rowid=cd.fk_contrat';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product prod ON prod.rowid=cd.fk_product';
		$sql .= ' WHERE er.resource_id='.((int) $resourceId)." AND er.reservation_status='confirmed'";
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->idate(dol_now())."')";
		$sql .= ' ORDER BY date_start, rowid';
		$resql = $this->db->query($sql);
		$result = array();
		if ($resql) {
			while ($row = $this->db->fetch_array($resql)) {
				$result[] = $row;
			}
		}
		return $result;
	}

	/** @return array<string,mixed>|null */
	private function findReplacement(array $reservation, array $planned)
	{
		$sql = 'SELECT er.resource_id, er.position, er.users_per_service_unit, r.ref, r.max_users, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid=er.resource_id';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code=r.fk_code_type_resource';
		$sql .= " WHERE er.element_type='product' AND er.resource_type='dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind='requirement')";
		$sql .= ' AND er.element_id='.((int) $reservation['fk_product']);
		$sql .= ' AND er.position>'.((int) $reservation['position']);
		$sql .= ' AND r.fk_statut=1 ORDER BY er.position,er.rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		while ($candidate = $this->db->fetch_object($resql)) {
			$capacityUsed = abs((float) $reservation['service_quantity']) * (float) $candidate->users_per_service_unit;
			if ($reservation['reservation_status'] !== 'confirmed' || $this->candidateHasCapacity($candidate, $capacityUsed, $reservation, $planned)) {
				return array(
					'resource_id' => (int) $candidate->resource_id,
					'ref' => $candidate->ref,
					'position' => (int) $candidate->position,
					'users_per_service_unit' => (float) $candidate->users_per_service_unit,
					'capacity_used' => $capacityUsed,
				);
			}
		}
		return null;
	}

	/** @return bool */
	private function candidateHasCapacity($candidate, $required, array $reservation, array $planned)
	{
		$maximumCapacity = ($candidate->capacity_mode === 'users') ? (float) $candidate->max_users : 1.0;
		if ($maximumCapacity <= 0 || $required > $maximumCapacity) {
			return false;
		}
		$sql = 'SELECT COALESCE(SUM(capacity_used),0) as occupied FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id='.((int) $candidate->resource_id)." AND reservation_status='confirmed'";
		if (!empty($reservation['date_start'])) {
			$sql .= " AND (date_end IS NULL OR date_end >= '".$this->db->escape($reservation['date_start'])."')";
		}
		if (!empty($reservation['date_end'])) {
			$sql .= " AND (date_start IS NULL OR date_start <= '".$this->db->escape($reservation['date_end'])."')";
		}
		$obj = $this->db->fetch_object($this->db->query($sql));
		$occupied = $obj ? (float) $obj->occupied : 0.0;
		foreach ($planned as $item) {
			if ((int) $item['resource_id'] === (int) $candidate->resource_id && $this->overlaps($reservation, $item)) {
				$occupied += (float) $item['capacity_used'];
			}
		}
		return ($occupied + $required) <= $maximumCapacity;
	}

	/** @return bool */
	private function overlaps(array $left, array $right)
	{
		return (empty($left['date_end']) || empty($right['date_start']) || $right['date_start'] <= $left['date_end'])
			&& (empty($right['date_end']) || empty($left['date_start']) || $right['date_end'] >= $left['date_start']);
	}
}
