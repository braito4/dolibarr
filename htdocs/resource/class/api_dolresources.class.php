<?php
/* Copyright (C) 2026 Dolibarr contributors */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/resource/class/dolresource.class.php';

/**
 * API class for resources.
 *
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class Dolresources extends DolibarrApi
{
	/** @var Dolresource Resource object. */
	public $resource;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
		$this->resource = new Dolresource($this->db);
	}

	/**
	 * Get a resource.
	 *
	 * @param int $id Resource ID
	 * @return Dolresource
	 * @throws RestException
	 */
	public function get($id)
	{
		$this->checkReadPermission();
		if ($this->resource->fetch($id) <= 0) {
			throw new RestException(404, 'Resource not found');
		}

		return $this->_cleanObjectDatas($this->resource);
	}

	/**
	 * List resources.
	 *
	 * @param string $sortfield Sort field
	 * @param string $sortorder Sort order
	 * @param int $limit Maximum result count
	 * @param int $page Page number
	 * @param string $sqlfilters Additional universal search filters
	 * @param string $properties Comma-separated properties to return
	 * @return Dolresource[]
	 * @throws RestException
	 */
	public function index($sortfield = 't.ref', $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $properties = '')
	{
		$this->checkReadPermission();
		$resources = array();
		$sql = 'SELECT t.rowid FROM '.$this->db->prefix().'resource AS t';
		$sql .= ' WHERE t.entity IN ('.getEntity('resource').')';
		if ($sqlfilters) {
			$errorMessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errorMessage);
			if ($errorMessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errorMessage);
			}
		}
		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			$page = max(0, (int) $page);
			$sql .= $this->db->plimit($limit + 1, $limit * $page);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, 'Error retrieving resource list: '.$this->db->lasterror());
		}
		$count = min($this->db->num_rows($resql), ($limit > 0 ? $limit : $this->db->num_rows($resql)));
		for ($i = 0; $i < $count; $i++) {
			$row = $this->db->fetch_object($resql);
			$resource = new Dolresource($this->db);
			if ($resource->fetch($row->rowid) > 0) {
				$resources[] = $this->_filterObjectProperties($this->_cleanObjectDatas($resource), $properties);
			}
		}

		return $resources;
	}

	/**
	 * Create a resource.
	 *
	 * @param array $request_data Request data
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return int Resource ID
	 * @url POST /
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('resource', 'write')) {
			throw new RestException(403);
		}
		if (empty($request_data['ref'])) {
			throw new RestException(400, 'Reference is required');
		}

		$this->setRequestData($this->resource, $request_data, true);
		if ($this->resource->create(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(500, 'Error creating resource', array_merge(array($this->resource->error), $this->resource->errors));
		}

		return $this->resource->id;
	}

	/**
	 * Update a resource.
	 *
	 * @param int $id Resource ID
	 * @param array $request_data Request data
	 * @phan-param ?array<string,mixed> $request_data
	 * @phpstan-param ?array<string,mixed> $request_data
	 * @return Dolresource
	 * @url PUT {id}
	 * @throws RestException
	 */
	public function put($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('resource', 'write')) {
			throw new RestException(403);
		}
		if ($this->resource->fetch($id) <= 0) {
			throw new RestException(404, 'Resource not found');
		}

		$this->setRequestData($this->resource, $request_data, false);
		if ($this->resource->status === Dolresource::STATUS_UNKNOWN && !$this->resource->hasStatusProvider()) {
			throw new RestException(409, 'A person in charge is required for unknown resource status');
		}
		if ($this->resource->update(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(500, 'Error updating resource', array_merge(array($this->resource->error), $this->resource->errors));
		}

		return $this->get($id);
	}

	/**
	 * Delete a resource.
	 *
	 * @param int $id Resource ID
	 * @return array{success:array{code:int,message:string}}
	 * @url DELETE {id}
	 * @throws RestException
	 */
	public function delete($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('resource', 'delete')) {
			throw new RestException(403);
		}
		if ($this->resource->fetch($id) <= 0) {
			throw new RestException(404, 'Resource not found');
		}
		if ($this->resource->delete(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(500, 'Error deleting resource', array_merge(array($this->resource->error), $this->resource->errors));
		}

		return array('success' => array('code' => 200, 'message' => 'Resource deleted'));
	}

	/**
	 * Apply supported API fields to a resource.
	 *
	 * @param Dolresource $resource Resource object
	 * @param ?array<string,mixed> $requestData Request data
	 * @param bool $creating Whether a resource is being created
	 * @return void
	 * @throws RestException
	 */
	private function setRequestData(Dolresource $resource, $requestData, $creating)
	{
		if (!is_array($requestData)) {
			throw new RestException(400, 'Invalid request body');
		}
		$allowedFields = array(
			'ref', 'address', 'zip', 'town', 'country_id', 'state_id', 'description', 'phone', 'email',
			'max_users', 'allow_overflow', 'metric_value', 'cooldown_minutes', 'url', 'fk_code_type_resource', 'status', 'note_public', 'note_private', 'array_options'
		);
		foreach ($requestData as $field => $value) {
			if (!in_array($field, $allowedFields, true)) {
				throw new RestException(400, 'Unsupported resource field: '.$field);
			}
			if ($field === 'allow_overflow') {
				$resource->allow_overflow = $this->normalizeBoolean($value, $field);
				continue;
			}
			if ($field === 'status') {
				$status = (int) $value;
				if (!array_key_exists($status, Dolresource::getStatusArray()) || ($creating && $status === Dolresource::STATUS_UNKNOWN)) {
					throw new RestException(400, 'Invalid resource status');
				}
				$resource->status = $status;
				continue;
			}
			if ($field === 'array_options' && is_array($value)) {
				foreach ($value as $key => $optionValue) {
					$resource->array_options[$key] = $this->_checkValExtrafieldsForAPI($key, $optionValue, $resource);
				}
				continue;
			}
			$resource->$field = $this->_checkValForAPI($field, $value, $resource);
		}
	}

	/**
	 * Normalize a REST boolean.
	 *
	 * @param mixed $value Input value
	 * @param string $field Field name
	 * @return int<0,1>
	 * @throws RestException
	 */
	private function normalizeBoolean($value, $field)
	{
		if ($value === true || $value === 1 || $value === '1') {
			return 1;
		}
		if ($value === false || $value === 0 || $value === '0') {
			return 0;
		}

		throw new RestException(400, 'Invalid boolean value for '.$field);
	}

	/**
	 * Check resource read permission.
	 *
	 * @return void
	 * @throws RestException
	 */
	private function checkReadPermission()
	{
		if (!DolibarrApiAccess::$user->hasRight('resource', 'read')) {
			throw new RestException(403);
		}
	}
}
