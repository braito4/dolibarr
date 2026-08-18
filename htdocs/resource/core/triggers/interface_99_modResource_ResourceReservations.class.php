<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/core/triggers/interface_99_modResource_ResourceReservations.class.php
 * \ingroup resource
 * \brief Synchronize service-line resource reservations.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

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

		$isProposal = strpos($action, 'LINEPROPAL_') === 0;
		$isContract = strpos($action, 'LINECONTRACT_') === 0;
		if (!$isProposal && !$isContract) {
			return 0;
		}

		$lineId = 0;
		if (!empty($object->context['line_id'])) {
			$lineId = (int) $object->context['line_id'];
		} elseif (!empty($object->id)) {
			$lineId = (int) $object->id;
		} elseif (!empty($object->rowid)) {
			$lineId = (int) $object->rowid;
		}
		if ($lineId <= 0) {
			return 0;
		}

		$elementType = $isProposal ? 'propaldet' : 'contratdet';
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
	 * Fetch normalized service line data.
	 *
	 * @param string $elementType propaldet or contratdet
	 * @param int $lineId Line id
	 * @return object|null
	 */
	private function fetchLine($elementType, $lineId)
	{
		if ($elementType === 'propaldet') {
			$sql = 'SELECT d.rowid, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
		} else {
			$sql = 'SELECT d.rowid, d.fk_product, d.product_type, d.qty,';
			$sql .= ' d.date_ouverture_prevue as date_start, d.date_fin_validite as date_end, p.duration as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'contratdet d';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
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
	 * Assign the first preferred resource with enough confirmed capacity.
	 *
	 * @param string $elementType Source element type
	 * @param object $line Normalized line
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @return int<-1,1>
	 */
	private function synchronizeLineReservation($elementType, $line, User $user, Translate $langs)
	{
		if ($this->deleteLineReservation($elementType, (int) $line->rowid) < 0) {
			return -1;
		}

		$sql = 'SELECT er.resource_id, er.position, er.users_per_service_unit, r.max_users';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid = er.resource_id';
		$sql .= " WHERE er.element_type = 'product'";
		$sql .= ' AND er.element_id = '.((int) $line->fk_product);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= ' AND r.fk_statut = 1';
		$sql .= ' ORDER BY er.position, er.rowid';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->num_rows($resql)) {
			return 1;
		}

		$isConfirmed = ($elementType === 'contratdet');
		$selected = null;
		while ($preference = $this->db->fetch_object($resql)) {
			$perUnit = (float) $preference->users_per_service_unit;
			$capacityUsed = abs((float) $line->qty) * $perUnit;
			if (!$isConfirmed || $this->hasCapacity($preference, $capacityUsed, $line->date_start, $line->date_end)) {
				$selected = $preference;
				$selected->capacity_used = $capacityUsed;
				break;
			}
		}

		if (!$selected) {
			$langs->load('resource');
			$this->errors[] = $langs->trans('NoResourceAvailableForServiceLine');
			return -1;
		}

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'element_resources (';
		$sql .= 'element_id, element_type, resource_id, resource_type, busy, mandatory, position,';
		$sql .= ' users_per_service_unit, service_quantity, service_duration, capacity_used, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $line->rowid).", '".$this->db->escape($elementType)."', ".((int) $selected->resource_id).", 'dolresource', 0, 0, ".((int) $selected->position).', ';
		$sql .= price2num($selected->users_per_service_unit, 'MS').', '.price2num($line->qty, 'MS').', ';
		$sql .= (!empty($line->service_duration) ? "'".$this->db->escape($line->service_duration)."'" : 'NULL').', '.price2num($selected->capacity_used, 'MS').', ';
		$sql .= (!empty($line->date_start) ? "'".$this->db->escape($line->date_start)."'" : 'NULL').', ';
		$sql .= (!empty($line->date_end) ? "'".$this->db->escape($line->date_end)."'" : 'NULL').", '".($isConfirmed ? 'confirmed' : 'provisional')."', ".((int) $user->id).')';

		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Check confirmed overlapping capacity.
	 *
	 * @param object $resource Preference and resource capacity
	 * @param float $capacityUsed Requested capacity
	 * @param string|null $dateStart Start date
	 * @param string|null $dateEnd End date
	 * @return bool
	 */
	private function hasCapacity($resource, $capacityUsed, $dateStart, $dateEnd)
	{
		$maximum = (float) $resource->max_users;
		if ($maximum <= 0 || $capacityUsed > $maximum) {
			return false;
		}

		$sql = 'SELECT COALESCE(SUM(capacity_used), 0) as occupied';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id = '.((int) $resource->resource_id);
		$sql .= " AND resource_type = 'dolresource'";
		$sql .= " AND reservation_status = 'confirmed'";
		if (!empty($dateStart)) {
			$sql .= " AND (date_end IS NULL OR date_end >= '".$this->db->escape($dateStart)."')";
		}
		if (!empty($dateEnd)) {
			$sql .= " AND (date_start IS NULL OR date_start <= '".$this->db->escape($dateEnd)."')";
		}
		$resql = $this->db->query($sql);
		$occupied = 0.0;
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$occupied = (float) $obj->occupied;
		}

		return ($occupied + $capacityUsed) <= $maximum;
	}
}
