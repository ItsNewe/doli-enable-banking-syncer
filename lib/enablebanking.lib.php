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
 * \file    htdocs/custom/enablebanking/lib/enablebanking.lib.php
 * \ingroup enablebanking
 * \brief   Common functions for module EnableBanking
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function enablebankingAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("enablebanking@enablebanking");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/enablebanking/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/enablebanking/admin/rules.php", 1);
	$head[$h][1] = $langs->trans("EnableBankingRules");
	$head[$h][2] = 'rules';
	$h++;

	$head[$h][0] = dol_buildpath("/enablebanking/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'enablebanking@enablebanking');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'enablebanking@enablebanking', 'remove');

	return $head;
}

/**
 * Return a text in uppercase without spaces nor separators, to compare IBANs or references
 *
 * @param	string	$text	Text
 * @return	string			Compacted text
 */
function enablebankingCompactKey($text)
{
	return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $text));
}

/**
 * Normalize an IBAN to compare it
 *
 * @param	string	$iban	IBAN as entered or received
 * @return	string			Normalized IBAN
 */
function enablebankingNormalizeIban($iban)
{
	return enablebankingCompactKey($iban);
}

/**
 * Split a text into words: lower case, no accents, no punctuation
 *
 * @param	string	$text	Text
 * @return	string[]		Words
 */
function enablebankingWords($text)
{
	return preg_split('/[^a-z0-9]+/', strtolower(dol_string_unaccent((string) $text)), -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * Normalize a company or person name to compare it: words without common legal forms
 *
 * @param	string	$name	Name
 * @return	string			Normalized name
 */
function enablebankingNormalizeName($name)
{
	$legalforms = array('sas', 'sasu', 'sarl', 'eurl', 'sa', 'sci', 'snc', 'scop', 'ste', 'societe', 'ets', 'gmbh', 'ag', 'ltd', 'limited', 'inc', 'llc', 'srl', 'spa', 'bv', 'nv', 'sl', 'mr', 'mme', 'm', 'monsieur', 'madame');
	return implode(' ', array_diff(enablebankingWords($name), $legalforms));
}

/**
 * Return true if two amounts are equal at the cent
 *
 * @param	float|string	$a	Amount
 * @param	float|string	$b	Amount
 * @return	bool
 */
function enablebankingSameAmount($a, $b)
{
	return abs((float) $a - (float) $b) < 0.005;
}

/**
 * Error properties shared by the services of the module
 */
trait EnableBankingErrors
{
	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var string[] Error messages
	 */
	public $errors = array();

	/**
	 * Copy the errors of an object that failed
	 *
	 * @param	object	$object		Object with properties error and errors
	 * @return	int					-1, to return it directly
	 */
	protected function setErrorFrom($object)
	{
		$this->error = isset($object->error) ? (string) $object->error : '';
		$this->errors = (isset($object->errors) && is_array($object->errors)) ? $object->errors : array();
		return -1;
	}
}

/**
 * Return the label of a status from a definition array
 *
 * @param	int									$status			Status
 * @param	int									$mode			Mode of dolGetStatus()
 * @param	array<int,array{0:string,1:string}>	$definitions	Status => array(translation key, status type like 'status4')
 * @return	string
 */
function enablebankingStatusLabel($status, $mode, $definitions)
{
	global $langs;
	$langs->load("enablebanking@enablebanking");

	$definition = isset($definitions[$status]) ? $definitions[$status] : array('Unknown', 'status0');
	$label = $langs->transnoentitiesnoconv($definition[0]);
	return dolGetStatus($label, $label, '', $definition[1], $mode);
}

/**
 * Return the PSU headers of the current HTTP request. They tell the bank the user is present,
 * which avoids the limit of 4 background fetches per day. Never use them in a cron context.
 *
 * @return	string[]	List of headers like 'Psu-Ip-Address: 1.2.3.4'
 */
function enablebankingGetPsuHeaders()
{
	$headers = array();
	$ip = getUserRemoteIP();
	if (empty($ip)) {
		return $headers;
	}
	$headers[] = 'Psu-Ip-Address: '.$ip;
	$map = array(
		'HTTP_USER_AGENT' => 'Psu-User-Agent',
		'HTTP_REFERER' => 'Psu-Referer',
		'HTTP_ACCEPT' => 'Psu-Accept',
		'HTTP_ACCEPT_CHARSET' => 'Psu-Accept-Charset',
		'HTTP_ACCEPT_ENCODING' => 'Psu-Accept-Encoding',
		'HTTP_ACCEPT_LANGUAGE' => 'Psu-Accept-Language',
	);
	foreach ($map as $serverkey => $headername) {
		if (!empty($_SERVER[$serverkey])) {
			$headers[] = $headername.': '.preg_replace('/[\r\n]/', '', (string) $_SERVER[$serverkey]);
		}
	}
	return $headers;
}

/**
 * Return the URL of the authorisation callback page, to register in the Enable Banking control panel
 *
 * @return	string	Absolute URL
 */
function enablebankingGetCallbackUrl()
{
	return dol_buildpath('/enablebanking/callback.php', 2);
}

/**
 * Return the statement number used when reconciling a line booked at a given date
 *
 * @param	int		$date	Timestamp of booking date
 * @return	string			Statement number
 */
function enablebankingGetStatementNumber($date)
{
	return dol_print_date($date, getDolGlobalString('ENABLEBANKING_STATEMENT_FORMAT', '%Y%m'));
}

/**
 * Return the next run of a schedule: first date not in the past at the chosen time of the user, on the chosen day
 * of week for a weekly schedule, otherwise stepping by the interval
 *
 * @param	int		$interval	Interval between runs in seconds
 * @param	int		$hour		Hour of first run (user time zone)
 * @param	int		$minute		Minute of first run
 * @param	int		$weekday	Day of week of weekly runs (0 = Sunday), ignored for shorter intervals
 * @param	int		$now		Current timestamp
 * @return	int					Timestamp
 */
function enablebankingNextRun($interval, $hour, $minute, $weekday, $now)
{
	// Year, month, day and day of week of today for the user
	$today = array_map('intval', explode('-', dol_print_date($now, '%Y-%m-%d-%w', 'tzuserrel')));
	$next = (int) dol_mktime($hour, $minute, 0, $today[1], $today[2], $today[0], 'tzuserrel');
	if ($interval >= 7 * 86400) {
		$next += (($weekday - $today[3] + 7) % 7) * 86400;
	}
	while ($next < $now) {
		$next += max(3600, $interval);
	}
	return $next;
}

/**
 * Return the scheduled job of the synchronisation, declared by the module descriptor
 *
 * @param	DoliDB	$db		Database handler
 * @return	Cronjob|null	Job, null if not found (module to enable again)
 */
function enablebankingGetCronJob($db)
{
	require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
	$cronjob = new Cronjob($db);
	return ($cronjob->fetch(0, 'EnableBankingSync', 'doScheduledJob') > 0 && $cronjob->id > 0) ? $cronjob : null;
}

/**
 * Return the types of tax a debit can pay: VAT and the social or fiscal contributions of the company country
 *
 * @param	DoliDB				$db		Database handler
 * @param	array<string,int>	$codes	Filled with code of contribution => id
 * @return	array<string,string>		Type (EnableBankingReconciler::TAX_VAT or id of llx_c_chargesociales) => label
 */
function enablebankingTaxTypeOptions($db, &$codes = array())
{
	global $langs, $mysoc;

	$options = array('vat' => $langs->trans('VAT'));
	$sql = "SELECT id, code, libelle FROM ".$db->prefix()."c_chargesociales WHERE active = 1 AND fk_pays = ".((int) $mysoc->country_id)." ORDER BY libelle";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$options[$obj->id] = $obj->libelle;
		$codes[$obj->code] = (int) $obj->id;
	}
	return $options;
}
