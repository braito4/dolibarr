<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * Read and combine resource requirements attached to business elements.
 */
class ResourceRequirementManager
{
	/** @var DoliDB */
	protected $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the default alternative group for a resource role.
	 *
	 * Resources with the same group are ordered alternatives. Using the role in
	 * the default keeps capacity, equipment and operator requirements separate.
	 *
	 * @param string $resourceRole Resource role
	 * @return string Default alternative group
	 */
	public static function getDefaultRequirementGroup($resourceRole)
	{
		$allowedResourceRoles = array('capacity', 'production', 'delivery', 'equipment', 'operator');
		if (!in_array($resourceRole, $allowedResourceRoles, true)) {
			$resourceRole = 'capacity';
		}

		return 'preferred_'.$resourceRole;
	}

	/**
	 * Return requirements configured on an element.
	 *
	 * @param string $elementType Requirement source type
	 * @param int    $elementId   Requirement source id
	 * @return array<int,array<string,mixed>>
	 */
	public function getRequirements($elementType, $elementId)
	{
		$sql = 'SELECT er.* FROM '.$this->db->prefix().'element_resources er';
		$sql .= " WHERE er.element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND er.element_id = '.((int) $elementId);
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' ORDER BY er.requirement_group, er.position, er.rowid';
		$resql = $this->db->query($sql);
		$requirements = array();
		while ($resql && ($row = $this->db->fetch_array($resql))) {
			$requirements[] = $row;
		}
		return $requirements;
	}

	/**
	 * Combine requirements into the effective line time-input policy.
	 *
	 * @param string $elementType Requirement source type
	 * @param int    $elementId   Requirement source id
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
			if ($startRank[$start] > $startRank[$policy['start_input_mode']]) {
				$policy['start_input_mode'] = $start;
			}
			if ($endRank[$end] > $endRank[$policy['end_input_mode']]) {
				$policy['end_input_mode'] = $end;
			}
			if ($precisionRank[$precision] > $precisionRank[$policy['time_precision']]) {
				$policy['time_precision'] = $precision;
			}
			if ($requirement['scheduling_mode'] === 'next_available') {
				$policy['automatic'] = true;
			}
		}
		$policy['show_start'] = $policy['start_input_mode'] !== 'none';
		$policy['show_end'] = in_array($policy['end_input_mode'], array('date', 'datetime'), true);
		$policy['calculate_end'] = $policy['end_input_mode'] === 'calculated';
		return $policy;
	}
}
