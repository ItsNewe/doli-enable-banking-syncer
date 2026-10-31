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
 * \file    htdocs/custom/enablebanking/class/enablebankingsync.class.php
 * \ingroup enablebanking
 * \brief   Import of bank transactions from Enable Banking
 */

dol_include_once('/enablebanking/class/enablebankingclient.class.php');
dol_include_once('/enablebanking/class/ebaccount.class.php');
dol_include_once('/enablebanking/class/ebtransaction.class.php');
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');
dol_include_once('/enablebanking/lib/enablebanking.lib.php');


/**
 * Import transactions of linked accounts and compute match suggestions
 */
class EnableBankingSync
{
	use EnableBankingErrors;

	/** Days fetched again before the last synchronisation, to catch late bookings */
	const OVERLAP_DAYS = 7;

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Output of cron job
	 */
	public $output = '';

	/**
	 * @var int Number of transactions created by last call to syncAccount()
	 */
	public $nbImported = 0;

	/**
	 * @var EnableBankingClient
	 */
	public $client;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->client = new EnableBankingClient($db);
	}

	/**
	 * Convert a 'YYYY-MM-DD' date of the API into a timestamp (noon, to avoid timezone shifts)
	 *
	 * @param	string|null		$date	Date
	 * @return	int|string				Timestamp or '' if empty
	 */
	public static function parseDate($date)
	{
		if (empty($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $reg)) {
			return '';
		}
		return dol_mktime(12, 0, 0, (int) $reg[2], (int) $reg[3], (int) $reg[1]);
	}

	/**
	 * Extract the fields used by the module from a transaction of the API
	 *
	 * @param	array<string,mixed>		$tx		Transaction as returned by the API
	 * @return	array<string,mixed>				Normalized fields
	 */
	public static function parseTransaction($tx)
	{
		$isdebit = (isset($tx['credit_debit_indicator']) && $tx['credit_debit_indicator'] == 'DBIT');
		$amount = abs((float) (isset($tx['transaction_amount']['amount']) ? $tx['transaction_amount']['amount'] : 0));
		$amount = (float) price2num($isdebit ? -$amount : $amount, 'MT');

		// Counterparty is the one who paid us (debtor) for a credit, the one we paid (creditor) for a debit
		$party = $isdebit ? (isset($tx['creditor']) ? $tx['creditor'] : array()) : (isset($tx['debtor']) ? $tx['debtor'] : array());
		$partyaccount = $isdebit ? (isset($tx['creditor_account']) ? $tx['creditor_account'] : array()) : (isset($tx['debtor_account']) ? $tx['debtor_account'] : array());
		$name = is_array($party) && !empty($party['name']) ? (string) $party['name'] : '';
		$iban = '';
		if (is_array($partyaccount)) {
			if (!empty($partyaccount['iban'])) {
				$iban = (string) $partyaccount['iban'];
			} elseif (!empty($partyaccount['other']['identification'])) {
				$iban = (string) $partyaccount['other']['identification'];
			}
		}

		$remittance = array();
		if (!empty($tx['remittance_information']) && is_array($tx['remittance_information'])) {
			foreach ($tx['remittance_information'] as $line) {
				$remittance[] = trim((string) $line);
			}
		}
		if (!empty($tx['reference_number']) && !in_array($tx['reference_number'], $remittance)) {
			$remittance[] = (string) $tx['reference_number'];
		}
		$remittancetext = trim(implode(' ', array_filter($remittance)));

		$code = '';
		if (!empty($tx['bank_transaction_code']) && is_array($tx['bank_transaction_code'])) {
			$btc = $tx['bank_transaction_code'];
			$code = trim((!empty($btc['code']) ? $btc['code'] : '').(!empty($btc['sub_code']) ? '-'.$btc['sub_code'] : ''));
			if ($code === '' && !empty($btc['description'])) {
				$code = (string) $btc['description'];
			}
		}

		$bookingdate = !empty($tx['booking_date']) ? $tx['booking_date'] : (!empty($tx['transaction_date']) ? $tx['transaction_date'] : (isset($tx['value_date']) ? $tx['value_date'] : ''));

		// entry_reference is the stable id. Fallback to a hash when the bank does not provide one.
		$ref = !empty($tx['entry_reference']) ? (string) $tx['entry_reference'] : '';
		if ($ref === '') {
			$ref = 'H'.sha1($bookingdate.'|'.$amount.'|'.$remittancetext.'|'.$name.'|'.$iban);
		}

		return array(
			'entry_reference' => dol_trunc($ref, 128, 'right', 'UTF-8', 1),
			'api_status' => isset($tx['status']) ? (string) $tx['status'] : 'BOOK',
			'booking_date' => self::parseDate($bookingdate),
			'value_date' => self::parseDate(isset($tx['value_date']) ? $tx['value_date'] : ''),
			'amount' => $amount,
			'currency' => isset($tx['transaction_amount']['currency']) ? (string) $tx['transaction_amount']['currency'] : '',
			'counterparty_name' => dol_trunc($name, 255, 'right', 'UTF-8', 1),
			'counterparty_iban' => enablebankingNormalizeIban($iban),
			'remittance' => $remittancetext,
			'bank_tx_code' => dol_trunc($code, 64, 'right', 'UTF-8', 1),
		);
	}

	/**
	 * Return the entry references already imported for a link, among a list
	 *
	 * @param	int			$fkEbAccount	Id of link
	 * @param	string[]	$refs			References to test
	 * @return	array<string,bool>|false	Map of existing references
	 */
	private function getExistingRefs($fkEbAccount, $refs)
	{
		$existing = array();
		foreach (array_chunk(array_unique($refs), 500) as $chunk) {
			$escaped = array();
			foreach ($chunk as $ref) {
				$escaped[] = "'".$this->db->escape($ref)."'";
			}
			$sql = "SELECT entry_reference FROM ".$this->db->prefix()."enablebanking_transaction";
			$sql .= " WHERE fk_eb_account = ".((int) $fkEbAccount);
			$sql .= " AND entry_reference IN (".implode(',', $escaped).")";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return false;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$existing[(string) $obj->entry_reference] = true;
			}
			$this->db->free($resql);
		}
		return $existing;
	}

	/**
	 * Import the new booked transactions of a linked account, then compute suggestions
	 *
	 * @param	EbAccount	$ebAccount		Link to synchronise
	 * @param	User		$user			User doing the synchronisation
	 * @param	string[]	$psuHeaders		PSU headers when the user is present (never in cron)
	 * @return	int							Number of imported transactions, <0 if KO
	 */
	public function syncAccount(EbAccount $ebAccount, User $user, $psuHeaders = array())
	{
		global $langs;
		$langs->load("enablebanking@enablebanking");

		$this->nbImported = 0;
		$this->error = '';

		if (!$ebAccount->isConsentValid()) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingConsentExpired');
			if ((int) $ebAccount->status === EbAccount::STATUS_ACTIVE) {
				$ebAccount->status = EbAccount::STATUS_EXPIRED;
				$ebAccount->last_error = $this->error;
				$ebAccount->update($user);
			}
			return -1;
		}

		$transactions = $this->client->getTransactions($ebAccount->account_uid, $this->computeDateFrom($ebAccount), null, $psuHeaders);
		if ($transactions === false) {
			$this->error = $this->client->error;
			$ebAccount->last_error = dol_trunc($this->client->error, 2000);
			// Consent revoked or expired at the bank
			if (in_array($this->client->lastHttpCode, array(401, 403)) || preg_match('/SESSION|EXPIRED|REVOKED/', $this->client->lastErrorCode)) {
				$ebAccount->status = EbAccount::STATUS_EXPIRED;
			}
			$ebAccount->update($user);
			return -1;
		}

		$newids = $this->insertTransactions($ebAccount, $this->parseBooked($transactions), $user);
		if ($newids === false) {
			return -1;
		}
		$this->nbImported = count($newids);

		if (!empty($newids)) {
			$matcher = new EnableBankingMatcher($this->db);
			if ($matcher->suggest($newids, $user) < 0) {
				// Transactions stay imported, suggestions can be computed again from the list
				$this->error = $matcher->error;
				dol_syslog(__METHOD__.' suggestions failed: '.$matcher->error, LOG_WARNING);
			}
		}

		return $this->nbImported;
	}

	/**
	 * Return the first day to fetch: a few days before the last synchronisation, or the setup history on first run
	 *
	 * @param	EbAccount	$ebAccount	Link
	 * @return	int						Timestamp
	 */
	private function computeDateFrom(EbAccount $ebAccount)
	{
		if (!empty($ebAccount->date_last_sync)) {
			return (int) $ebAccount->date_last_sync - self::OVERLAP_DAYS * 86400;
		}
		return dol_now() - max(1, getDolGlobalInt('ENABLEBANKING_INITIAL_DAYS', 90)) * 86400;
	}

	/**
	 * Parse the transactions of the API and keep the booked ones, indexed by entry reference
	 *
	 * @param	array<int,array<string,mixed>>	$transactions	Transactions of the API
	 * @return	array<string,array<string,mixed>>				Fields of EbTransaction
	 */
	private function parseBooked($transactions)
	{
		$parsed = array();
		foreach ($transactions as $tx) {
			$fields = self::parseTransaction($tx);
			if ($fields['api_status'] !== 'BOOK' || empty($fields['booking_date'])) {
				continue;
			}
			unset($fields['api_status']);
			$fields['raw_json'] = json_encode($tx);
			$parsed[$fields['entry_reference']] = $fields;
		}
		return $parsed;
	}

	/**
	 * Create the transactions not imported yet and save the date of synchronisation
	 *
	 * @param	EbAccount							$ebAccount	Link
	 * @param	array<string,array<string,mixed>>	$parsed		Transactions indexed by entry reference
	 * @param	User								$user		User
	 * @return	int[]|false										Ids of created transactions
	 */
	private function insertTransactions(EbAccount $ebAccount, $parsed, User $user)
	{
		$existing = empty($parsed) ? array() : $this->getExistingRefs($ebAccount->id, array_keys($parsed));
		if ($existing === false) {
			return false;
		}

		$newids = array();
		$this->db->begin();
		foreach (array_diff_key($parsed, $existing) as $fields) {
			$ebtx = new EbTransaction($this->db);
			foreach ($fields as $key => $value) {
				$ebtx->$key = $value;
			}
			$ebtx->entity = $ebAccount->entity;
			$ebtx->fk_eb_account = $ebAccount->id;
			$ebtx->fk_bank_account = $ebAccount->fk_bank_account;
			$ebtx->status = EbTransaction::STATUS_NEW;
			$result = $ebtx->create($user);
			if ($result < 0) {
				$this->db->rollback();
				$this->setErrorFrom($ebtx);
				return false;
			}
			$newids[] = $result;
		}

		$ebAccount->date_last_sync = dol_now();
		$ebAccount->last_error = '';
		if ($ebAccount->update($user) < 0) {
			$this->db->rollback();
			$this->setErrorFrom($ebAccount);
			return false;
		}
		$this->db->commit();
		return $newids;
	}

	/**
	 * Cron job: synchronise all active links of the current entity
	 *
	 * @return	int		0 if OK, number of accounts in error otherwise
	 */
	public function doScheduledJob()
	{
		global $user, $langs;
		$langs->load("enablebanking@enablebanking");

		$this->output = '';
		$this->error = '';

		if (!$this->client->isConfigured()) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorNotConfigured');
			return 1;
		}

		$ebAccount = new EbAccount($this->db);
		$ids = $ebAccount->fetchAllActiveIds();
		if ($ids === false) {
			$this->error = $ebAccount->error;
			return 1;
		}

		$nberrors = 0;
		$lines = array();
		foreach ($ids as $id) {
			$eb = new EbAccount($this->db);
			if ($eb->fetch($id) <= 0) {
				continue;
			}
			$result = $this->syncAccount($eb, $user);
			if ($result < 0) {
				$nberrors++;
				$lines[] = $eb->iban.': '.$this->error;
			} else {
				$lines[] = $eb->iban.': '.$langs->transnoentitiesnoconv('EnableBankingNbImported', $result);
			}
		}

		$this->output = count($ids) ? implode("\n", $lines) : $langs->transnoentitiesnoconv('EnableBankingNoActiveAccount');
		if ($nberrors) {
			$this->error = $this->output;
		}
		return $nberrors;
	}
}
