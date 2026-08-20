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
 * Synchronize provisional and confirmed reservations for commercial document lines.
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
		$this->description = 'Synchronize resources used by proposal, order and contract service lines.';
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
		if ($action === 'ACTION_DELETE') {
			return $this->deleteLegacyActionResourceLinks((int) $object->id);
		}
		if ($action === 'ACTION_ADD_RESOURCE') {
			return $this->validateLegacyActionResources($object, false);
		}
		if ($action === 'ACTION_MODIFY') {
			return $this->validateLegacyActionResources($object, true);
		}

		if (in_array($action, array('PROPAL_DELETE', 'PROPAL_CANCEL', 'PROPAL_CLOSE_REFUSED', 'PROPAL_CLOSE_SIGNED'), true)) {
			return $this->deleteDocumentReservations('propaldet', (int) $object->id);
		}
		if (in_array($action, array('ORDER_DELETE', 'ORDER_CANCEL', 'ORDER_CLOSE'), true)) {
			return $this->deleteDocumentReservations('commandedet', (int) $object->id);
		}
		if ($action === 'CONTRACT_DELETE') {
			return $this->deleteDocumentReservations('contratdet', (int) $object->id);
		}
		if ($action === 'CONTRACT_VALIDATE') {
			return $this->synchronizeContractReservations((int) $object->id, $user, $langs, true);
		}
		if ($action === 'CONTRACT_REOPEN') {
			return $this->synchronizeContractReservations((int) $object->id, $user, $langs, false);
		}
		if ($action === 'PROPAL_VALIDATE' || $action === 'PROPAL_REOPEN') {
			return $this->synchronizeProposalReservations((int) $object->id, $user, $langs);
		}
		if ($action === 'ORDER_VALIDATE' || $action === 'ORDER_REOPEN') {
			return $this->synchronizeOrderReservations((int) $object->id, $user, $langs, true);
		}
		if ($action === 'ORDER_UNVALIDATE') {
			return $this->synchronizeOrderReservations((int) $object->id, $user, $langs, false);
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
		if (substr($action, -7) === '_DELETE' || $action === 'LINECONTRACT_CLOSE') {
			return $this->deleteLineReservation($elementType, $lineId);
		}

		$line = $this->fetchLine($elementType, $lineId);
		if ($line === false) {
			return -1;
		}
		if (!$line) {
			return 1;
		}
		if (empty($line->fk_product) || (int) $line->product_type !== 1) {
			return $this->deleteLineReservation($elementType, $lineId);
		}

		$forceAvailability = ($isContract || $isOrder) && !empty($line->parent_status);
		return $this->synchronizeLineReservation($elementType, $line, $user, $langs, null, $forceAvailability);
	}

	/**
	 * Revalidate physical Agenda links when an event is moved or resized.
	 *
	 * @param CommonObject $object                ActionComm object
	 * @param bool         $onlyIfIntervalChanged Skip metadata-only updates when oldcopy is available
	 * @return int<-1,1>
	 */
	private function validateLegacyActionResources($object, $onlyIfIntervalChanged = false)
	{
		$actionId = (int) $object->id;
		if ($actionId <= 0 || !getDolGlobalString('RESOURCE_USED_IN_EVENT_CHECK')) {
			return 1;
		}
		if ($onlyIfIntervalChanged && !empty($object->oldcopy)
			&& (int) $object->oldcopy->datep === (int) $object->datep
			&& (int) $object->oldcopy->datef === (int) $object->datef
			&& (int) $object->oldcopy->fulldayevent === (int) $object->fulldayevent) {
			return 1;
		}
		$dateStartTimestamp = !empty($object->datep) ? (int) $object->datep : 0;
		$dateEndTimestamp = !empty($object->datef) ? (int) $object->datef : 0;
		if ($dateStartTimestamp <= 0) {
			return 1;
		}
		if ($dateEndTimestamp <= 0) {
			if (!empty($object->fulldayevent)) {
				$startParts = dol_getdate($dateStartTimestamp);
				$dateStartTimestamp = dol_mktime(0, 0, 0, $startParts['mon'], $startParts['mday'], $startParts['year']);
				$dateEndTimestamp = dol_mktime(0, 0, 0, $startParts['mon'], $startParts['mday'] + 1, $startParts['year']);
			} else {
				$dateEndTimestamp = $dateStartTimestamp + 1;
			}
		}
		if ($dateEndTimestamp <= dol_now() || $dateEndTimestamp <= $dateStartTimestamp) {
			return 1;
		}

		$sql = 'SELECT DISTINCT resource_id FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = 'action' AND element_id = ".$actionId;
		$sql .= " AND resource_type = 'dolresource' AND busy = 1";
		$sql .= " AND (relation_kind = 'link' OR relation_kind IS NULL) AND reservation_status IS NULL";
		$sql .= ' ORDER BY resource_id';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$resourceIds = array();
		while ($row = $this->db->fetch_object($resql)) {
			$resourceIds[] = (int) $row->resource_id;
		}
		$resourceIds = array_values(array_unique(array_filter($resourceIds)));
		if (empty($resourceIds)) {
			return 1;
		}
		sort($resourceIds, SORT_NUMERIC);
		$manager = new ResourceReservationManager($this->db);
		$dateStart = $this->db->idate($dateStartTimestamp);
		$dateEnd = $this->db->idate($dateEndTimestamp);
		foreach ($resourceIds as $resourceId) {
			if (!$manager->canReserveExclusiveAction($resourceId, $actionId, $dateStart, $dateEnd)) {
				$this->errors[] = 'A linked resource is unavailable for the new Agenda interval.';
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Delete generic resource links owned by an Agenda action being removed.
	 *
	 * @param int $actionId Action id
	 * @return int<-1,1>
	 */
	private function deleteLegacyActionResourceLinks($actionId)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = 'action' AND element_id = ".((int) $actionId);
		$sql .= " AND resource_type = 'dolresource'";
		$sql .= " AND (relation_kind IS NULL OR relation_kind = 'link') AND reservation_status IS NULL";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Delete generated assignments without touching generic element-resource links.
	 *
	 * @param string $elementType Source element type
	 * @param int    $elementId   Source element id
	 * @param string $resourceType Resource implementation type
	 * @return int<-1,1>
	 */
	private function deleteSourceAssignments($elementType, $elementId, $resourceType)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND element_id = '.((int) $elementId);
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= " AND (relation_kind = 'assignment' OR reservation_status IS NOT NULL)";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Delete generated assignments for every line of a parent document.
	 *
	 * @param string $elementType Source line element type
	 * @param int    $parentId    Parent object id
	 * @return int<-1,1>
	 */
	private function deleteDocumentReservations($elementType, $parentId)
	{
		$configuration = $this->getDocumentConfiguration($elementType);
		if (!$configuration || $parentId <= 0 || !$this->db->begin()) {
			$this->errors[] = $configuration ? $this->db->lasterror() : 'Invalid resource reservation document context.';
			return -1;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['parent_table'];
		$sql .= ' WHERE rowid = '.((int) $parentId);
		$sql .= ' AND entity IN ('.getEntity($configuration['entity']).')'.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			$this->errors[] = $resql ? 'Resource reservation document not found in the current entity scope.' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['line_table'];
		$sql .= ' WHERE '.$configuration['parent_field'].' = '.((int) $parentId);
		$sql .= ' ORDER BY rang, rowid'.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$lineIds = array();
		$resourceIds = array();
		while ($line = $this->db->fetch_object($resql)) {
			$lineId = (int) $line->rowid;
			$lineIds[] = $lineId;
			$lineResourceIds = $this->collectAssignmentResourceIds($elementType, $lineId);
			if ($lineResourceIds === false) {
				$this->db->rollback();
				return -1;
			}
			$resourceIds = array_merge($resourceIds, $lineResourceIds);
		}
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		sort($resourceIds, SORT_NUMERIC);
		if ($this->lockResources($resourceIds) < 0) {
			$this->db->rollback();
			return -1;
		}
		foreach ($lineIds as $lineId) {
			if ($this->lockAndDeleteLineAssignments($elementType, $lineId, $resourceIds) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		if (!$this->db->commit()) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		return 1;
	}

	/**
	 * Recheck and confirm every resource assignment when a contract is validated.
	 *
	 * @param int $contractId Contract id
	 * @param User $user Current user
	 * @param Translate $langs Translation handler
	 * @param bool      $confirmed Whether assignments consume final capacity
	 * @return int<-1,1>
	 */
	private function synchronizeContractReservations($contractId, User $user, Translate $langs, $confirmed)
	{
		return $this->synchronizeDocumentReservations('contratdet', $contractId, $user, $langs, (bool) $confirmed, (bool) $confirmed, false);
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
		return $this->synchronizeDocumentReservations('propaldet', $proposalId, $user, $langs, false, true, true);
	}

	/**
	 * Synchronize every resource assignment of an order.
	 *
	 * @param int       $orderId   Order id
	 * @param User      $user      Current user
	 * @param Translate $langs     Translation handler
	 * @param bool      $confirmed Whether assignments consume final capacity
	 * @return int<-1,1>
	 */
	private function synchronizeOrderReservations($orderId, User $user, Translate $langs, $confirmed)
	{
		return $this->synchronizeDocumentReservations('commandedet', $orderId, $user, $langs, (bool) $confirmed, (bool) $confirmed, false);
	}

	/**
	 * Atomically synchronize every service line of one commercial document.
	 *
	 * @param string    $elementType       Source line element type
	 * @param int       $parentId          Parent document id
	 * @param User      $user              Current user
	 * @param Translate $langs             Translation handler
	 * @param bool      $confirmed         Whether assignments consume final capacity
	 * @param bool      $forceAvailability Recheck confirmed occupancy
	 * @param bool      $carryPlanned      Include earlier provisional lines in availability checks
	 * @return int<-1,1>
	 */
	private function synchronizeDocumentReservations($elementType, $parentId, User $user, Translate $langs, $confirmed, $forceAvailability, $carryPlanned)
	{
		$configuration = $this->getDocumentConfiguration($elementType);
		if (!$configuration || $parentId <= 0) {
			$this->errors[] = 'Invalid resource reservation document context.';
			return -1;
		}
		if (!$this->db->begin()) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}

		$lockSuffix = $this->getLockSuffix();
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['parent_table'];
		$sql .= ' WHERE rowid = '.((int) $parentId);
		$sql .= ' AND entity IN ('.getEntity($configuration['entity']).')'.$lockSuffix;
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			$this->errors[] = $resql ? 'Resource reservation document not found in the current entity scope.' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['line_table'];
		$sql .= ' WHERE '.$configuration['parent_field'].' = '.((int) $parentId);
		$sql .= ' ORDER BY rang, rowid'.$lockSuffix;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$lines = array();
		while ($row = $this->db->fetch_object($resql)) {
			$line = $this->fetchLine($elementType, (int) $row->rowid, true);
			if ($line === false || !$line) {
				$this->errors[] = $line === false ? $this->db->lasterror() : 'A locked resource reservation line could not be reloaded.';
				$this->db->rollback();
				return -1;
			}
			$lines[] = $line;
		}

		$resourceIds = array();
		foreach ($lines as $line) {
			$lineResourceIds = $this->collectLineResourceIds($elementType, $line);
			if ($lineResourceIds === false) {
				$this->db->rollback();
				return -1;
			}
			$resourceIds = array_merge($resourceIds, $lineResourceIds);
		}
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		sort($resourceIds, SORT_NUMERIC);
		if ($this->lockResources($resourceIds) < 0) {
			$this->db->rollback();
			return -1;
		}

		$documentPlannedAssignments = array();
		foreach ($lines as $line) {
			if (empty($line->fk_product) || (int) $line->product_type !== 1) {
				if ($this->lockAndDeleteLineAssignments($elementType, (int) $line->rowid, $resourceIds) < 0) {
					$this->db->rollback();
					return -1;
				}
				continue;
			}
			$linePlannedAssignments = array();
			if ($carryPlanned) {
				$result = $this->synchronizeLockedLineReservation($elementType, $line, $user, $langs, $confirmed, $forceAvailability, $resourceIds, $documentPlannedAssignments);
			} else {
				$result = $this->synchronizeLockedLineReservation($elementType, $line, $user, $langs, $confirmed, $forceAvailability, $resourceIds, $linePlannedAssignments);
			}
			if ($result < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		if (!$this->db->commit()) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		return 1;
	}

	/**
	 * Return immutable SQL metadata for a supported document line type.
	 *
	 * @param string $elementType Source line element type
	 * @return array{line_table:string,parent_table:string,parent_field:string,entity:string}|null
	 */
	private function getDocumentConfiguration($elementType)
	{
		$configurations = array(
			'propaldet' => array('line_table' => 'propaldet', 'parent_table' => 'propal', 'parent_field' => 'fk_propal', 'entity' => 'propal'),
			'commandedet' => array('line_table' => 'commandedet', 'parent_table' => 'commande', 'parent_field' => 'fk_commande', 'entity' => 'commande'),
			'contratdet' => array('line_table' => 'contratdet', 'parent_table' => 'contrat', 'parent_field' => 'fk_contrat', 'entity' => 'contract'),
		);
		return $configurations[$elementType] ?? null;
	}

	/**
	 * Return a portable row-lock suffix.
	 *
	 * @return string
	 */
	private function getLockSuffix()
	{
		return in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
	}

	/**
	 * Fetch normalized service line data.
	 *
	 * @param string $elementType propaldet, commandedet or contratdet
	 * @param int  $lineId  Line id
	 * @param bool $locking Use a current locking read
	 * @return object|null|false False on SQL error
	 */
	private function fetchLine($elementType, $lineId, $locking = false)
	{
		if ($lineId <= 0 || !$this->getDocumentConfiguration($elementType)) {
			$this->errors[] = 'Invalid resource reservation line context.';
			return false;
		}
		if ($elementType === 'propaldet') {
			$sql = 'SELECT d.rowid, d.fk_propal as parent_id, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end,';
			$sql .= ' (SELECT p.duration FROM '.MAIN_DB_PREFIX.'product p WHERE p.rowid = d.fk_product) as service_duration';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'propal parent ON parent.rowid = d.fk_propal';
			$entity = 'propal';
		} elseif ($elementType === 'commandedet') {
			$sql = 'SELECT d.rowid, d.fk_commande as parent_id, d.fk_product, d.product_type, d.qty, d.date_start, d.date_end,';
			$sql .= ' (SELECT p.duration FROM '.MAIN_DB_PREFIX.'product p WHERE p.rowid = d.fk_product) as service_duration, c.fk_statut as parent_status';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'commandedet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commande c ON c.rowid = d.fk_commande';
			$entity = 'commande';
		} elseif ($elementType === 'contratdet') {
			$sql = 'SELECT d.rowid, d.fk_contrat as parent_id, d.fk_product, d.product_type, d.qty,';
			$sql .= ' d.date_ouverture_prevue as date_start, d.date_fin_validite as date_end,';
			$sql .= ' (SELECT p.duration FROM '.MAIN_DB_PREFIX.'product p WHERE p.rowid = d.fk_product) as service_duration, c.statut as parent_status';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'contratdet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'contrat c ON c.rowid = d.fk_contrat';
			$entity = 'contract';
		} else {
			$this->errors[] = 'Unsupported resource reservation line type.';
			return false;
		}
		$sql .= ' WHERE d.rowid = '.((int) $lineId);
		$sql .= ' AND '.($elementType === 'propaldet' ? 'parent' : 'c').'.entity IN ('.getEntity($entity).')';
		if ($locking) {
			$sql .= $this->getLockSuffix();
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}
		$line = $this->db->fetch_object($resql);
		return $line ?: null;
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
		if (!$this->getDocumentConfiguration($elementType) || $lineId <= 0) {
			$this->errors[] = 'Invalid resource reservation line context.';
			return -1;
		}
		if (!$this->db->begin()) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$lockResult = $this->lockDocumentLine($elementType, $lineId);
		if ($lockResult < 0) {
			$this->db->rollback();
			return -1;
		}
		$resourceIds = $this->collectAssignmentResourceIds($elementType, $lineId);
		if ($resourceIds === false || $this->lockResources($resourceIds) < 0
			|| $this->lockAndDeleteLineAssignments($elementType, $lineId, $resourceIds) < 0) {
			$this->db->rollback();
			return -1;
		}
		if (!$this->db->commit()) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		return 1;
	}

	/**
	 * Lock a document parent and one line in a deterministic order.
	 *
	 * @param string $elementType Source line element type
	 * @param int    $lineId      Source line id
	 * @return int<-1,1> -1 on error, 0 when the line no longer exists, 1 when locked
	 */
	private function lockDocumentLine($elementType, $lineId)
	{
		$configuration = $this->getDocumentConfiguration($elementType);
		if (!$configuration || $lineId <= 0) {
			$this->errors[] = 'Invalid resource reservation line context.';
			return -1;
		}
		$sql = 'SELECT d.'.$configuration['parent_field'].' as parent_id';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$configuration['line_table'].' d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.$configuration['parent_table'].' parent ON parent.rowid = d.'.$configuration['parent_field'];
		$sql .= ' WHERE d.rowid = '.((int) $lineId);
		$sql .= ' AND parent.entity IN ('.getEntity($configuration['entity']).')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$lineSnapshot = $this->db->fetch_object($resql);
		if (!$lineSnapshot) {
			return 0;
		}
		$parentId = (int) $lineSnapshot->parent_id;
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['parent_table'];
		$sql .= ' WHERE rowid = '.$parentId;
		$sql .= ' AND entity IN ('.getEntity($configuration['entity']).')'.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			$this->errors[] = $resql ? 'Resource reservation document changed while it was being locked.' : $this->db->lasterror();
			return -1;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$configuration['line_table'];
		$sql .= ' WHERE rowid = '.((int) $lineId).' AND '.$configuration['parent_field'].' = '.$parentId.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			$this->errors[] = $resql ? 'Resource reservation line changed while it was being locked.' : $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Collect candidate and existing assignment resource ids from an unlocked snapshot.
	 *
	 * The refreshed rows are checked against this set after the resource locks are
	 * acquired. A newly introduced resource therefore causes a closed failure.
	 *
	 * @param string $elementType Source line element type
	 * @param object $line        Normalized source line
	 * @return array<int,int>|false
	 */
	private function collectLineResourceIds($elementType, $line)
	{
		$resourceIds = $this->collectAssignmentResourceIds($elementType, (int) $line->rowid);
		if ($resourceIds === false) {
			return false;
		}
		if (empty($line->fk_product) || (int) $line->product_type !== 1) {
			return $resourceIds;
		}
		$resql = $this->db->query($this->getAllRequirementsSql((int) $line->fk_product));
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}
		while ($requirement = $this->db->fetch_object($resql)) {
			if ((int) $requirement->resource_id > 0) {
				$resourceIds[] = (int) $requirement->resource_id;
			}
		}
		$resourceIds = array_values(array_unique($resourceIds));
		sort($resourceIds, SORT_NUMERIC);
		return $resourceIds;
	}

	/**
	 * Collect generated assignment resource ids for one source line.
	 *
	 * @param string $elementType Source line element type
	 * @param int    $lineId      Source line id
	 * @return array<int,int>|false
	 */
	private function collectAssignmentResourceIds($elementType, $lineId)
	{
		$sql = 'SELECT DISTINCT resource_id FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND element_id = '.((int) $lineId);
		$sql .= " AND resource_type = 'dolresource'";
		$sql .= " AND (relation_kind = 'assignment' OR reservation_status IS NOT NULL)";
		$sql .= ' ORDER BY resource_id';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}
		$resourceIds = array();
		while ($assignment = $this->db->fetch_object($resql)) {
			if ((int) $assignment->resource_id > 0) {
				$resourceIds[] = (int) $assignment->resource_id;
			}
		}
		return array_values(array_unique($resourceIds));
	}

	/**
	 * Lock an exact current-entity resource set in ascending order.
	 *
	 * @param array<int,int> $resourceIds Resource ids
	 * @return int<-1,1>
	 */
	private function lockResources(array $resourceIds)
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		if (empty($resourceIds)) {
			return 1;
		}
		sort($resourceIds, SORT_NUMERIC);
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'resource';
		$sql .= ' WHERE rowid IN ('.$this->db->sanitize(implode(',', $resourceIds)).')';
		$sql .= ' AND entity IN ('.getEntity('resource').')';
		$sql .= ' ORDER BY rowid'.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== count($resourceIds)) {
			$this->errors[] = $resql ? 'A resource disappeared or left the current entity scope while it was being locked.' : $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Lock, verify and delete generated assignments for one line.
	 *
	 * @param string         $elementType      Source line element type
	 * @param int            $lineId           Source line id
	 * @param array<int,int> $lockedResourceIds Exact resource snapshot already locked
	 * @return int<-1,1>
	 */
	private function lockAndDeleteLineAssignments($elementType, $lineId, array $lockedResourceIds)
	{
		$lockedResources = array_fill_keys(array_map('intval', $lockedResourceIds), true);
		$sql = 'SELECT rowid, resource_id FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND element_id = '.((int) $lineId);
		$sql .= " AND resource_type = 'dolresource'";
		$sql .= " AND (relation_kind = 'assignment' OR reservation_status IS NOT NULL)";
		$sql .= ' ORDER BY resource_id, rowid'.$this->getLockSuffix();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		while ($assignment = $this->db->fetch_object($resql)) {
			if ((int) $assignment->resource_id <= 0 || !isset($lockedResources[(int) $assignment->resource_id])) {
				$this->errors[] = 'Resource assignments changed while their resource locks were being acquired; retry the operation.';
				return -1;
			}
		}
		return $this->deleteSourceAssignments($elementType, $lineId, 'dolresource');
	}

	/**
	 * SQL used to snapshot every current-entity requirement, including unavailable ones.
	 *
	 * @param int $productId Service product id
	 * @return string
	 */
	private function getAllRequirementsSql($productId)
	{
		$sql = 'SELECT er.rowid, er.resource_id, er.mandatory, er.resource_role, er.requirement_group';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid = er.resource_id';
		$sql .= " WHERE er.element_type IN ('product', 'service')";
		$sql .= ' AND er.element_id = '.((int) $productId);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' AND r.entity IN ('.getEntity('resource').')';
		$sql .= ' ORDER BY er.resource_id, er.rowid';
		return $sql;
	}

	/**
	 * SQL used to identify every semantic requirement, including inaccessible resources.
	 *
	 * @param int $productId Service product id
	 * @return string
	 */
	private function getRawRequirementsSql($productId)
	{
		$sql = 'SELECT er.rowid, er.resource_id, er.mandatory, er.resource_role, er.requirement_group';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " WHERE er.element_type IN ('product', 'service')";
		$sql .= ' AND er.element_id = '.((int) $productId);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' ORDER BY er.rowid';
		return $sql;
	}

	/**
	 * SQL used to load allocatable requirement alternatives.
	 *
	 * @param int  $productId                 Service product id
	 * @param bool $unknownAvailabilityEnabled Whether unknown resources may be selected
	 * @return string
	 */
	private function getCandidateRequirementsSql($productId, $unknownAvailabilityEnabled)
	{
		$sql = 'SELECT er.*, r.fk_statut as resource_status, r.max_users, r.available_units, r.allow_overflow as resource_allow_overflow,';
		$sql .= ' r.metric_value, r.max_payload_weight, r.operational_location, r.cooldown_minutes, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid = er.resource_id';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
		$sql .= " WHERE er.element_type IN ('product', 'service')";
		$sql .= ' AND er.element_id = '.((int) $productId);
		$sql .= " AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'requirement')";
		$sql .= ' AND r.entity IN ('.getEntity('resource').')';
		$sql .= $unknownAvailabilityEnabled ? ' AND r.fk_statut IN (0, 1)' : ' AND r.fk_statut = 1';
		$sql .= ' AND ty.active = 1';
		$sql .= ' ORDER BY er.resource_role, er.requirement_group, er.position, er.rowid';
		return $sql;
	}

	/**
	 * Group alternatives by both role and requirement group.
	 *
	 * A NULL requirement group is deliberately converted to a row-specific group,
	 * because each such row represents an independent obligation.
	 *
	 * @param mixed $resql Database result
	 * @return array<string,array<int,object>>
	 */
	private function buildRequirementGroups($resql)
	{
		$groups = array();
		while ($preference = $this->db->fetch_object($resql)) {
			$groups[$this->getRequirementGroupKey($preference)][] = $preference;
		}
		return $groups;
	}

	/**
	 * Build the stable semantic key used for alternatives.
	 *
	 * @param object $requirement Requirement row
	 * @return string
	 */
	private function getRequirementGroupKey($requirement)
	{
		$role = !empty($requirement->resource_role) ? (string) $requirement->resource_role : 'capacity';
		$group = !empty($requirement->requirement_group) ? 'group:'.(string) $requirement->requirement_group : 'row:'.((int) $requirement->rowid);
		return $role.'|'.$group;
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
		if (!$this->getDocumentConfiguration($elementType) || empty($line->rowid)) {
			$this->errors[] = 'Invalid resource reservation line context.';
			return -1;
		}
		if (!$this->db->begin()) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		if ($this->lockDocumentLine($elementType, (int) $line->rowid) !== 1) {
			$this->db->rollback();
			return -1;
		}
		$currentLine = $this->fetchLine($elementType, (int) $line->rowid, true);
		if ($currentLine === false || !$currentLine) {
			$this->errors[] = $currentLine === false ? $this->db->lasterror() : 'A locked resource reservation line could not be reloaded.';
			$this->db->rollback();
			return -1;
		}
		$resourceIds = $this->collectLineResourceIds($elementType, $currentLine);
		if ($resourceIds === false || $this->lockResources($resourceIds) < 0) {
			$this->db->rollback();
			return -1;
		}
		$plannedAssignments = array();
		if (empty($currentLine->fk_product) || (int) $currentLine->product_type !== 1) {
			$result = $this->lockAndDeleteLineAssignments($elementType, (int) $currentLine->rowid, $resourceIds);
		} else {
			$result = $this->synchronizeLockedLineReservation($elementType, $currentLine, $user, $langs, $forceConfirmed, $forceAvailability, $resourceIds, $plannedAssignments);
		}
		if ($result < 0) {
			$this->db->rollback();
			return -1;
		}
		if (!$this->db->commit()) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		return 1;
	}

	/**
	 * Synchronize one already locked service line using an exact resource snapshot.
	 *
	 * @param string         $elementType       Source element type
	 * @param object         $line              Locked normalized line
	 * @param User           $user              Current user
	 * @param Translate      $langs             Translation handler
	 * @param bool|null      $forceConfirmed    Force final capacity allocation
	 * @param bool           $forceAvailability Recheck confirmed occupancy
	 * @param array<int,int> $lockedResourceIds Exact resource snapshot already locked
	 * @param array<int,array{resource_id:int,date_start:string,date_end:string,capacity:float,units:int}> $plannedAssignments Earlier staged assignments
	 * @return int<-1,1>
	 */
	private function synchronizeLockedLineReservation($elementType, $line, User $user, Translate $langs, $forceConfirmed, $forceAvailability, array $lockedResourceIds, array &$plannedAssignments)
	{
		$lockedResources = array_fill_keys(array_map('intval', $lockedResourceIds), true);
		$rawRequirementsResult = $this->db->query($this->getRawRequirementsSql((int) $line->fk_product).$this->getLockSuffix());
		if (!$rawRequirementsResult) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$requiredGroups = array();
		while ($requirement = $this->db->fetch_object($rawRequirementsResult)) {
			if (!empty($requirement->mandatory)) {
				$requiredGroups[$this->getRequirementGroupKey($requirement)] = true;
			}
		}
		$allRequirementsSql = $this->getAllRequirementsSql((int) $line->fk_product);
		$allRequirementsResult = $this->db->query($allRequirementsSql.$this->getLockSuffix());
		if (!$allRequirementsResult) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		while ($requirement = $this->db->fetch_object($allRequirementsResult)) {
			$resourceId = (int) $requirement->resource_id;
			if ($resourceId <= 0 || !isset($lockedResources[$resourceId])) {
				$this->errors[] = 'Service resource requirements changed while their resource locks were being acquired; retry the operation.';
				return -1;
			}
		}

		$unknownAvailabilityEnabled = (bool) getDolGlobalInt('RESOURCE_ENABLE_UNKNOWN_AVAILABILITY');
		$candidateResult = $this->db->query($this->getCandidateRequirementsSql((int) $line->fk_product, $unknownAvailabilityEnabled).$this->getLockSuffix());
		if (!$candidateResult) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$groups = $this->buildRequirementGroups($candidateResult);
		foreach ($requiredGroups as $requiredGroup => $unused) {
			if (!isset($groups[$requiredGroup])) {
				$langs->load('resource');
				$this->errors[] = $langs->trans('NoResourceAvailableForServiceLine');
				return -1;
			}
		}
		$resourceIds = array();
		$maximumCooldown = 0;
		foreach ($groups as $availabilityAlternatives) {
			foreach ($availabilityAlternatives as $availabilityRequirement) {
				$resourceId = (int) $availabilityRequirement->resource_id;
				if ($resourceId <= 0 || !isset($lockedResources[$resourceId])) {
					$this->errors[] = 'An allocatable resource appeared after the lock snapshot; retry the operation.';
					return -1;
				}
				$resourceIds[] = $resourceId;
				$maximumCooldown = max($maximumCooldown, (int) $availabilityRequirement->cooldown_minutes);
			}
		}
		$resourceIds = array_values(array_unique($resourceIds));
		sort($resourceIds, SORT_NUMERIC);
		if ($this->lockAndDeleteLineAssignments($elementType, (int) $line->rowid, $lockedResourceIds) < 0) {
			return -1;
		}
		if (empty($groups)) {
			return 1;
		}

		$isConfirmed = $forceConfirmed !== null
			? (bool) $forceConfirmed
			: (in_array($elementType, array('contratdet', 'commandedet'), true) && !empty($line->parent_status));
		$checkAvailability = $isConfirmed || $forceAvailability;
		$manager = new ResourceReservationManager($this->db);
		$commonStart = !empty($line->date_start) ? $line->date_start : null;
		$commonEnd = !empty($line->date_end) ? $line->date_end : null;
		$currentRequirements = array();
		foreach ($groups as $temporalAlternatives) {
			foreach ($temporalAlternatives as $temporalRequirement) {
				$currentRequirements[] = (array) $temporalRequirement;
			}
		}
		$temporalPolicy = $manager->buildTemporalPolicy($currentRequirements);
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
			if ($duration <= 0 && !empty($line->service_duration)) {
				$duration = $manager->durationStringToMinutes($line->service_duration);
			}
			if ($duration <= 0) {
				$this->errors[] = $langs->trans('ResourceDurationRequiredForCalculatedEnd');
				return -1;
			}
			$commonEnd = $this->db->idate($this->db->jdate($commonStart) + ($duration * 60));
		}
		$confirmedAssignments = array();
		$bulkAvailabilityLoaded = false;
		if ($checkAvailability) {
			if (!empty($commonStart) && !empty($commonEnd)) {
				$loadEnd = $this->db->idate($this->db->jdate($commonEnd) + ($maximumCooldown * 60));
				$confirmedAssignments = $manager->loadConfirmedAssignments('dolresource', $resourceIds, $commonStart, $loadEnd, true);
				$bulkAvailabilityLoaded = true;
			}
		}
		foreach ($groups as $requirementGroupKey => $alternatives) {
			// The same physical resource cannot be two alternatives of one semantic
			// requirement. Keep the first preference so legacy duplicate rows cannot
			// make a split allocator count one unit twice.
			$uniqueAlternatives = array();
			$seenAlternativeResources = array();
			foreach ($alternatives as $alternative) {
				$alternativeResourceId = (int) $alternative->resource_id;
				if (isset($seenAlternativeResources[$alternativeResourceId])) {
					continue;
				}
				$seenAlternativeResources[$alternativeResourceId] = true;
				$uniqueAlternatives[] = $alternative;
			}
			$alternatives = $uniqueAlternatives;
			$groupMandatory = isset($requiredGroups[$requirementGroupKey]);
			$groupCapacityMetrics = array();
			foreach ($alternatives as $alternative) {
				$groupMandatory = $groupMandatory || !empty($alternative->mandatory);
				$groupCapacityMetrics[(string) $alternative->capacity_metrics] = true;
			}
			if (!empty($alternatives[0]->selection_policy) && $alternatives[0]->selection_policy === 'smallest_sufficient') {
				usort($alternatives, static function (stdClass $left, stdClass $right) use ($manager) {
					$capacityComparison = $manager->getResourceMaximumCapacity($left) <=> $manager->getResourceMaximumCapacity($right);
					if ($capacityComparison !== 0) {
						return $capacityComparison;
					}
					$positionComparison = (int) $left->position <=> (int) $right->position;
					return $positionComparison !== 0 ? $positionComparison : ((int) $left->rowid <=> (int) $right->rowid);
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
				$requiredUnits = max(1, (int) ceil(abs((float) $line->qty) * max(0.0, (float) $preference->quantity_required)));
				$capacityPerUnit = $manager->getResourceMaximumCapacity($preference);
				if ($capacityPerUnit > 0) {
					$requiredUnits = max($requiredUnits, (int) ceil($capacityUsed / $capacityPerUnit));
				}
				if ($payloadWeightUsed > 0 && (float) $preference->max_payload_weight > 0) {
					$requiredUnits = max($requiredUnits, (int) ceil($payloadWeightUsed / (float) $preference->max_payload_weight));
				}
				$maximumCapacity = $capacityPerUnit * $availableUnits;
				if (!empty($dateEnd) && $cooldown > 0) {
					$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
				}
				$needsAutomaticSlot = (empty($dateStart) || empty($dateEnd)) && $preference->scheduling_mode === 'next_available';
				if ($needsAutomaticSlot) {
					$duration = $manager->calculateDuration((array) $preference, (float) $line->qty);
					if ($duration <= 0 && !empty($line->service_duration)) {
						$duration = $manager->durationStringToMinutes($line->service_duration);
					}
					$duration += $cooldown;
					$slot = $manager->findNextAvailable('dolresource', (int) $preference->resource_id, dol_now(), dol_time_plus_duree(dol_now(), 90, 'd'), $duration, $capacityUsed, $maximumCapacity, $requiredUnits, $availableUnits, true);
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
				$fitsSchedule = !$checkAvailability || (!empty($dateStart) && !empty($dateEnd)
					&& $manager->isAvailableInterval('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd));
				$fitsResourceCapacity = $capacityUsed > 0 && $capacityUsed <= ($capacityPerUnit * $requiredUnits) && $requiredUnits <= $availableUnits
					&& ($requiredUnits <= 1 || !empty($preference->allow_split)) && $fitsPayload && $fitsLocation;
				$occupied = 0.0;
				$occupiedUnits = 0;
				if ($checkAvailability && !empty($dateStart) && !empty($dateEnd)) {
					$plannedUsage = $this->getPlannedUsage($plannedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd);
					$occupied = $bulkAvailabilityLoaded
						? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd)
						: $manager->getOccupiedCapacity('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd, 0, true);
					$occupied += $plannedUsage['capacity'];
					$occupiedUnits = $bulkAvailabilityLoaded
						? $manager->getOccupiedResourceUnitsFromAssignments($confirmedAssignments, (int) $preference->resource_id, $dateStart, $dateEnd)
						: $manager->getOccupiedResourceUnits('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd, true);
					$occupiedUnits += $plannedUsage['units'];
				}
				$canAllocate = !$checkAvailability ? $fitsResourceCapacity : ($fitsResourceCapacity && $fitsSchedule && !empty($dateStart) && !empty($dateEnd) && ($occupied + $capacityUsed) <= $maximumCapacity && ($occupiedUnits + $requiredUnits) <= $availableUnits);
				if ($canAllocate) {
					$selected = $preference;
					$selected->load_volume_used = $preference->capacity_mode === 'volume' ? $capacityUsed : 0.0;
					$selected->capacity_used = $preference->capacity_mode === 'volume' ? ($capacityPerUnit * $requiredUnits) : $capacityUsed;
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
			if (!$selected && count($groupCapacityMetrics) === 1 && in_array($alternatives[0]->capacity_metrics, array('volume', 'volume_weight'), true)) {
				$remainingVolume = $this->calculateContextCapacityDemand($manager, $elementType, $line, $alternatives[0], 1.0);
				$remainingWeight = $alternatives[0]->capacity_metrics === 'volume_weight'
					? $manager->calculateDocumentProductWeight($elementType, (int) $line->parent_id)
					: 0.0;
				foreach ($alternatives as $preference) {
					if (empty($preference->allow_split) || empty($preference->resource_allow_overflow) || empty($commonStart) || empty($commonEnd)) {
						continue;
					}
					$dateEnd = $commonEnd;
					$cooldown = max(0, (int) $preference->cooldown_minutes);
					if ($cooldown > 0) {
						$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
					}
					if ($checkAvailability && !$manager->isAvailableInterval('dolresource', (int) $preference->resource_id, $commonStart, $dateEnd)) {
						continue;
					}
					if (!empty($preference->required_location)
						&& strcasecmp(trim((string) $preference->required_location), trim((string) $preference->operational_location)) !== 0) {
						continue;
					}
					$resourceId = (int) $preference->resource_id;
					$plannedUsage = $this->getPlannedUsage($plannedAssignments, $resourceId, $commonStart, $dateEnd);
					$occupied = 0.0;
					$occupiedUnits = 0;
					if ($checkAvailability) {
						$occupied = $bulkAvailabilityLoaded
							? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, $resourceId, $commonStart, $dateEnd)
							: $manager->getOccupiedCapacity('dolresource', $resourceId, $commonStart, $dateEnd, 0, true);
						$occupiedUnits = $bulkAvailabilityLoaded
							? $manager->getOccupiedResourceUnitsFromAssignments($confirmedAssignments, $resourceId, $commonStart, $dateEnd)
							: $manager->getOccupiedResourceUnits('dolresource', $resourceId, $commonStart, $dateEnd, true);
					}
					$freeUnits = max(0, (int) $preference->available_units - $occupiedUnits - $plannedUsage['units']);
					if ($freeUnits <= 0) {
						continue;
					}
					$maximumVolumePerUnit = (float) $preference->metric_value;
					$maximumWeightPerUnit = (float) $preference->max_payload_weight;
					$maximumVolume = $maximumVolumePerUnit * $freeUnits;
					$maximumWeight = $maximumWeightPerUnit * $freeUnits;
					$volumeRatio = $remainingVolume > 0 ? min(1.0, $maximumVolume / $remainingVolume) : 0.0;
					$weightRatio = $remainingWeight > 0 ? min(1.0, $maximumWeight / $remainingWeight) : 1.0;
					$allocationRatio = min($volumeRatio, $weightRatio);
					if ($allocationRatio <= 0) {
						continue;
					}
					$allocatedVolume = $remainingVolume * $allocationRatio;
					$allocatedWeight = $remainingWeight * $allocationRatio;
					$allocatedUnits = max(1, (int) ceil($allocatedVolume / max($maximumVolumePerUnit, 0.000001)));
					if ($allocatedWeight > 0) {
						if ($maximumWeightPerUnit <= 0) {
							continue;
						}
						$allocatedUnits = max($allocatedUnits, (int) ceil($allocatedWeight / $maximumWeightPerUnit));
					}
					if ($allocatedUnits > $freeUnits || ($occupied + $plannedUsage['capacity'] + ($maximumVolumePerUnit * $allocatedUnits)) > ($maximumVolumePerUnit * (int) $preference->available_units)) {
						continue;
					}
					$overflowSelection = clone $preference;
					$overflowSelection->load_volume_used = $allocatedVolume;
					$overflowSelection->payload_weight_used = $allocatedWeight;
					$overflowSelection->capacity_used = $maximumVolumePerUnit * $allocatedUnits;
					$overflowSelection->resource_units_used = $allocatedUnits;
					$overflowSelection->assignment_start = $commonStart;
					$overflowSelection->assignment_end = $dateEnd;
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
			if (!$selected && empty($splitAssignments) && $wholeQuantity > 1 && (float) $wholeQuantity === abs((float) $line->qty)
				&& count($groupCapacityMetrics) === 1 && !in_array($alternatives[0]->capacity_metrics, array('volume', 'volume_weight'), true)) {
				$stagedCapacity = array();
				$stagedUnits = array();
				for ($unit = 0; $unit < $wholeQuantity; $unit++) {
					$unitSelection = null;
					foreach ($alternatives as $preference) {
						if (empty($preference->allow_split)) {
							continue;
						}
						$capacityUsed = (float) $preference->users_per_service_unit;
						$dateStart = $commonStart;
						$dateEnd = $commonEnd;
						$cooldown = max(0, (int) $preference->cooldown_minutes);
						$availableUnits = max(1, (int) $preference->available_units);
						$capacityPerUnit = $manager->getResourceMaximumCapacity($preference);
						$maximumCapacity = $capacityPerUnit * $availableUnits;
						if (!empty($dateEnd) && $cooldown > 0) {
							$dateEnd = $this->db->idate($this->db->jdate($dateEnd) + ($cooldown * 60));
						}
						if (empty($dateStart) || empty($dateEnd) || $capacityUsed <= 0 || $capacityUsed > $maximumCapacity) {
							continue;
						}
						if ($checkAvailability && !$manager->isAvailableInterval('dolresource', (int) $preference->resource_id, $dateStart, $dateEnd)) {
							continue;
						}
						$resourceId = (int) $preference->resource_id;
						$capacityKey = $resourceId.'|'.$dateStart.'|'.$dateEnd;
						$alreadyStaged = isset($stagedCapacity[$capacityKey]) ? $stagedCapacity[$capacityKey] : 0.0;
						$unitsAlreadyStaged = $stagedUnits[$capacityKey] ?? 0;
						$occupied = 0.0;
						$occupiedUnits = 0;
						if ($checkAvailability) {
							$plannedUsage = $this->getPlannedUsage($plannedAssignments, $resourceId, $dateStart, $dateEnd);
							$occupied = $bulkAvailabilityLoaded
								? $manager->getOccupiedCapacityFromAssignments($confirmedAssignments, $resourceId, $dateStart, $dateEnd)
								: $manager->getOccupiedCapacity('dolresource', $resourceId, $dateStart, $dateEnd, 0, true);
							$occupied += $plannedUsage['capacity'];
							$occupiedUnits = $bulkAvailabilityLoaded
								? $manager->getOccupiedResourceUnitsFromAssignments($confirmedAssignments, $resourceId, $dateStart, $dateEnd)
								: $manager->getOccupiedResourceUnits('dolresource', $resourceId, $dateStart, $dateEnd, true);
							$occupiedUnits += $plannedUsage['units'];
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
						return -1;
					}
					$plannedAssignments[] = array(
						'resource_id' => (int) $splitAssignment->resource_id,
						'date_start' => $splitAssignment->assignment_start,
						'date_end' => $splitAssignment->assignment_end,
						'capacity' => (float) $splitAssignment->capacity_used,
						'units' => (int) $splitAssignment->resource_units_used,
					);
				}
				continue;
			}
			if (!$selected) {
				if (!$groupMandatory) {
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
			$plannedAssignments[] = array(
				'resource_id' => (int) $selected->resource_id,
				'date_start' => $selected->assignment_start,
				'date_end' => $selected->assignment_end,
				'capacity' => (float) $selected->capacity_used,
				'units' => (int) $selected->resource_units_used,
			);
		}
		return 1;
	}

	/**
	 * Sum assignments staged earlier in this synchronization that overlap an interval.
	 *
	 * @param array<int,array{resource_id:int,date_start:string,date_end:string,capacity:float,units:int}> $plannedAssignments Staged assignments
	 * @param int    $resourceId Resource id
	 * @param string $dateStart  Interval start
	 * @param string $dateEnd    Interval end
	 * @return array{capacity:float,units:int}
	 */
	private function getPlannedUsage(array $plannedAssignments, $resourceId, $dateStart, $dateEnd)
	{
		$usage = array('capacity' => 0.0, 'units' => 0);
		foreach ($plannedAssignments as $assignment) {
			if ((int) $assignment['resource_id'] !== (int) $resourceId
				|| empty($assignment['date_start']) || empty($assignment['date_end'])
				|| $assignment['date_start'] >= $dateEnd || $assignment['date_end'] <= $dateStart) {
				continue;
			}
			$usage['capacity'] += (float) $assignment['capacity'];
			$usage['units'] += max(1, (int) $assignment['units']);
		}
		return $usage;
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
		$sql .= ' users_per_service_unit, service_quantity, service_duration, capacity_used, resource_units_used, cooldown_minutes_applied, load_volume_used, payload_weight_used, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $line->rowid).", '".$this->db->escape($elementType)."', ".((int) $selected->resource_id).", 'dolresource', 1, ".(!empty($selected->mandatory) ? 1 : 0).", ".((int) $selected->position).", 'assignment', '";
		$sql .= $this->db->escape($selected->resource_role ?: 'capacity')."', ".(!empty($selected->requirement_group) ? "'".$this->db->escape($selected->requirement_group)."'" : 'NULL').', ';
		$sql .= price2num($selected->users_per_service_unit, 'MS').', '.price2num($serviceQuantity, 'MS').', ';
		$sql .= (!empty($line->service_duration) ? "'".$this->db->escape($line->service_duration)."'" : 'NULL').', '.price2num($selected->capacity_used, 'MS').', '.max(1, (int) $selected->resource_units_used).', '.max(0, (int) $selected->cooldown_minutes).', ';
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
