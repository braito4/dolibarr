<?php
/*
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
 *	\file       /htdocs/public/bookcal/bookcalAjax.php
 *	\brief      File to make Ajax action on Book cal
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Disables token renewal
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
// If there is no need to load and show top and left menu
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined("NOLOGIN")) {
	define("NOLOGIN", '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/bookcal/class/bookcalavailabilityprovider.class.php';
/**
 * @var DoliDB $db
 */
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$datetocheckbooking = GETPOSTINT('datetocheck');
$error = 0;

// Security check
/*if (!defined("NOLOGIN")) {	// No need of restrictedArea if not logged: Later the select will filter on public articles only if not logged.
	restrictedArea($user, 'knowledgemanagement', 0, 'knowledgemanagement_knowledgerecord', 'knowledgerecord');
}*/

$result = "{}";


/*
 * Actions
 */

// None


/*
 * View
 */

top_httphead('application/json');

if ($action == 'verifyavailability') {		// Test on permission not required here (anonymous action protected by mitigation of /public/... urls)
	$response = array();
	if (empty($id)) {
		$error++;
		$response["code"] = "MISSING_ID";
		$response["message"] = "Missing parameter id";
		header('HTTP/1.0 400 Bad Request');
		echo json_encode($response);
		exit;
	}
	if (empty($datetocheckbooking)) {
		$error++;
		$response["code"] = "MISSING_DATE_AVAILABILITY";
		$response["message"] = "Missing parameter datetocheck";
		header('HTTP/1.0 400 Bad Request');
		echo json_encode($response);
		exit;
	}

	if (!$error) {
		$provider = new BookCalAvailabilityProvider($db);
		$response['availability'] = $provider->getSlots($id, $datetocheckbooking);
		$response['code'] = 'SUCCESS';
	}
	$result = $response;
}


echo json_encode($result);
