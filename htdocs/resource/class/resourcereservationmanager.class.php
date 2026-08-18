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
	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
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
		$sql = 'SELECT er.resource_id, er.position, er.users_per_service_unit, r.ref, r.max_users';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid=er.resource_id';
		$sql .= " WHERE er.element_type='product' AND er.resource_type='dolresource'";
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
		if ((float) $candidate->max_users <= 0 || $required > (float) $candidate->max_users) {
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
		return ($occupied + $required) <= (float) $candidate->max_users;
	}

	/** @return bool */
	private function overlaps(array $left, array $right)
	{
		return (empty($left['date_end']) || empty($right['date_start']) || $right['date_start'] <= $left['date_end'])
			&& (empty($right['date_end']) || empty($left['date_start']) || $right['date_end'] >= $left['date_start']);
	}
}
