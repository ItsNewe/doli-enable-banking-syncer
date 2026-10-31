<?php
/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
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
 * \file    htdocs/custom/enablebanking/class/enablebankingclient.class.php
 * \ingroup enablebanking
 * \brief   Client of the Enable Banking REST API (https://enablebanking.com/docs/api/reference/)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';


/**
 * Client of the Enable Banking API. Every call is authenticated with a JWT signed (RS256)
 * with the private key of the application registered in the Enable Banking control panel.
 */
class EnableBankingClient
{
	const API_URL = 'https://api.enablebanking.com';

	/** Lifetime of generated JWT in seconds (the API accepts 86400 at most) */
	const JWT_LIFETIME = 3600;

	/** Safety limit of pages read when following continuation keys */
	const MAX_PAGES = 200;

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var int HTTP code of last call
	 */
	public $lastHttpCode = 0;

	/**
	 * @var string Error code returned by the API on last call (for example ASPSP_RATE_LIMIT_EXCEEDED)
	 */
	public $lastErrorCode = '';

	/**
	 * @var string
	 */
	private $applicationId;

	/**
	 * @var string
	 */
	private $privateKey;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db				Database handler
	 * @param	string	$applicationId	Application id (default: from setup)
	 * @param	string	$privateKey		PEM private key (default: from setup)
	 */
	public function __construct($db, $applicationId = '', $privateKey = '')
	{
		$this->db = $db;
		$this->applicationId = ($applicationId !== '' ? $applicationId : getDolGlobalString('ENABLEBANKING_APPLICATION_ID'));
		$this->privateKey = ($privateKey !== '' ? $privateKey : getDolGlobalString('ENABLEBANKING_PRIVATE_KEY'));
	}

	/**
	 * Return if application id and private key are set
	 *
	 * @return	bool
	 */
	public function isConfigured()
	{
		return ($this->applicationId !== '' && $this->privateKey !== '');
	}

	/**
	 * Encode a string in base64url without padding
	 *
	 * @param	string	$data	Data
	 * @return	string			Encoded data
	 */
	private static function base64UrlEncode($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Build the JWT used as bearer token
	 *
	 * @return	string|false	JWT or false if error
	 */
	public function buildJwt()
	{
		if (!$this->isConfigured()) {
			$this->error = 'EnableBankingErrorNotConfigured';
			return false;
		}
		if (!function_exists('openssl_sign')) {
			$this->error = 'PHP extension openssl is required';
			return false;
		}

		$now = dol_now();
		$header = array('typ' => 'JWT', 'alg' => 'RS256', 'kid' => $this->applicationId);
		$payload = array(
			'iss' => 'enablebanking.com',
			'aud' => 'api.enablebanking.com',
			'iat' => $now,
			'exp' => $now + self::JWT_LIFETIME,
		);
		$signinginput = self::base64UrlEncode(json_encode($header)).'.'.self::base64UrlEncode(json_encode($payload));

		$key = openssl_pkey_get_private($this->privateKey);
		if ($key === false) {
			$this->error = 'EnableBankingErrorBadPrivateKey';
			return false;
		}
		$signature = '';
		if (!openssl_sign($signinginput, $signature, $key, OPENSSL_ALGO_SHA256)) {
			$this->error = 'EnableBankingErrorSignature';
			return false;
		}

		return $signinginput.'.'.self::base64UrlEncode($signature);
	}

	/**
	 * Call the API
	 *
	 * @param	string					$method			'GET', 'POST' or 'DELETE'
	 * @param	string					$path			Path, for example '/aspsps'
	 * @param	array<string,mixed>		$query			Query parameters
	 * @param	array<string,mixed>|null $body			Body, sent as JSON
	 * @param	string[]				$extraheaders	More headers (PSU headers)
	 * @return	array<string,mixed>|false				Decoded response or false if error
	 */
	public function request($method, $path, $query = array(), $body = null, $extraheaders = array())
	{
		$this->error = '';
		$this->lastHttpCode = 0;
		$this->lastErrorCode = '';

		$jwt = $this->buildJwt();
		if ($jwt === false) {
			return false;
		}

		$url = self::API_URL.$path;
		if (!empty($query)) {
			$url .= '?'.http_build_query($query, '', '&');
		}
		$headers = array_merge(array(
			'Authorization: Bearer '.$jwt,
			'Accept: application/json',
			'Content-Type: application/json',
		), $extraheaders);

		$postorget = $method;
		$param = '';
		if ($method == 'POST') {
			$postorget = 'POSTALREADYFORMATED';
			$param = json_encode($body === null ? new stdClass() : $body);
		}

		dol_syslog(__METHOD__.' '.$method.' '.self::API_URL.$path, LOG_DEBUG);
		$result = getURLContent($url, $postorget, $param, 0, $headers, array('https'), 0, 1, 10, 90);

		$this->lastHttpCode = (int) $result['http_code'];
		if (!empty($result['curl_error_no'])) {
			$this->error = 'Curl error '.$result['curl_error_no'].': '.$result['curl_error_msg'];
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		$data = json_decode((string) $result['content'], true);
		if ($this->lastHttpCode < 200 || $this->lastHttpCode >= 300) {
			$message = '';
			if (is_array($data)) {
				$this->lastErrorCode = (string) (isset($data['error']) ? $data['error'] : (isset($data['code']) ? $data['code'] : ''));
				$message = (string) (isset($data['message']) ? $data['message'] : '');
				if (!empty($data['detail'])) {
					$message .= ' '.(is_string($data['detail']) ? $data['detail'] : json_encode($data['detail']));
				}
			}
			$this->error = 'HTTP '.$this->lastHttpCode.($this->lastErrorCode ? ' '.$this->lastErrorCode : '').($message ? ': '.trim($message) : '');
			dol_syslog(__METHOD__.' '.$this->error, LOG_WARNING);
			return false;
		}

		if (!is_array($data)) {
			// DELETE may return an empty body
			return array();
		}
		return $data;
	}

	/**
	 * Call a GET endpoint returning a list under a key of the response
	 *
	 * @param	string					$path		Path
	 * @param	string					$key		Key of the list in the response
	 * @param	array<string,mixed>		$query		Query parameters
	 * @param	string[]				$headers	More headers
	 * @return	array<int,array<string,mixed>>|false	List (empty if missing) or false if error
	 */
	private function requestList($path, $key, $query = array(), $headers = array())
	{
		$data = $this->request('GET', $path, $query, null, $headers);
		if ($data === false) {
			return false;
		}
		return (isset($data[$key]) && is_array($data[$key])) ? $data[$key] : array();
	}

	/**
	 * Return the application linked to the key (used to test the setup)
	 *
	 * @return	array<string,mixed>|false
	 */
	public function getApplication()
	{
		return $this->request('GET', '/application');
	}

	/**
	 * Return the list of banks (ASPSP) of a country
	 *
	 * @param	string	$country	ISO 3166 country code
	 * @param	string	$psuType	'business' or 'personal'
	 * @return	array<int,array<string,mixed>>|false
	 */
	public function getAspsps($country, $psuType = '')
	{
		$query = array('country' => strtoupper($country), 'service' => 'AIS');
		if ($psuType) {
			$query['psu_type'] = $psuType;
		}
		return $this->requestList('/aspsps', 'aspsps', $query);
	}

	/**
	 * Start the authorisation of the user at his bank
	 *
	 * @param	string	$aspspName		Name of bank as returned by getAspsps()
	 * @param	string	$country		Country of bank
	 * @param	string	$psuType		'business' or 'personal'
	 * @param	string	$state			Random value returned to the callback
	 * @param	int		$validUntil		Timestamp of end of consent
	 * @param	string	$redirectUrl	Callback URL
	 * @param	string	$language		Language code (2 letters)
	 * @return	array<string,mixed>|false	Response with 'url' to redirect the user to
	 */
	public function startAuthorization($aspspName, $country, $psuType, $state, $validUntil, $redirectUrl, $language = '')
	{
		$body = array(
			'access' => array('valid_until' => gmdate('Y-m-d\TH:i:s\Z', $validUntil)),
			'aspsp' => array('name' => $aspspName, 'country' => strtoupper($country)),
			'state' => $state,
			'redirect_url' => $redirectUrl,
			'psu_type' => $psuType,
		);
		if ($language) {
			$body['language'] = $language;
		}
		return $this->request('POST', '/auth', array(), $body);
	}

	/**
	 * Create a session from the code received on the callback
	 *
	 * @param	string	$code	Authorisation code
	 * @return	array<string,mixed>|false	Session with 'session_id' and 'accounts'
	 */
	public function createSession($code)
	{
		return $this->request('POST', '/sessions', array(), array('code' => $code));
	}

	/**
	 * Close a session (revokes the consent)
	 *
	 * @param	string	$sessionId	Session id
	 * @return	bool
	 */
	public function deleteSession($sessionId)
	{
		return ($this->request('DELETE', '/sessions/'.rawurlencode($sessionId)) !== false);
	}

	/**
	 * Return balances of an account
	 *
	 * @param	string		$accountUid		Account uid
	 * @param	string[]	$psuHeaders		PSU headers when the user is present
	 * @return	array<int,array<string,mixed>>|false
	 */
	public function getBalances($accountUid, $psuHeaders = array())
	{
		return $this->requestList('/accounts/'.rawurlencode($accountUid).'/balances', 'balances', array(), $psuHeaders);
	}

	/**
	 * Return all booked transactions of an account between two dates, following pagination
	 *
	 * @param	string		$accountUid		Account uid
	 * @param	int			$dateFrom		Timestamp of first day
	 * @param	int|null	$dateTo			Timestamp of last day (null = today)
	 * @param	string[]	$psuHeaders		PSU headers when the user is present
	 * @return	array<int,array<string,mixed>>|false
	 */
	public function getTransactions($accountUid, $dateFrom, $dateTo = null, $psuHeaders = array())
	{
		$query = array(
			'date_from' => dol_print_date($dateFrom, '%Y-%m-%d', 'gmt'),
			'transaction_status' => 'BOOK',
		);
		if ($dateTo) {
			$query['date_to'] = dol_print_date($dateTo, '%Y-%m-%d', 'gmt');
		}

		$transactions = array();
		$page = 0;
		do {
			$data = $this->request('GET', '/accounts/'.rawurlencode($accountUid).'/transactions', $query, null, $psuHeaders);
			if ($data === false) {
				return false;
			}
			if (!empty($data['transactions']) && is_array($data['transactions'])) {
				foreach ($data['transactions'] as $transaction) {
					$transactions[] = $transaction;
				}
			}
			// Other parameters must stay the same when following the continuation key
			$continuationkey = empty($data['continuation_key']) ? '' : (string) $data['continuation_key'];
			$query['continuation_key'] = $continuationkey;
			$page++;
		} while ($continuationkey !== '' && $page < self::MAX_PAGES);

		return $transactions;
	}
}
