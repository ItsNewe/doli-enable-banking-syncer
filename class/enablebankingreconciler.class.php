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
 * \file    htdocs/custom/enablebanking/class/enablebankingreconciler.class.php
 * \ingroup enablebanking
 * \brief   Validate matches: create payments or bank lines, link and reconcile them
 */

require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');


/**
 * Validation of the matches of imported transactions
 */
class EnableBankingReconciler
{
	use EnableBankingErrors;

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Check the transaction can be processed by the user
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @param	User			$user	User
	 * @return	bool
	 */
	private function checkCanMatch(EbTransaction $tx, User $user)
	{
		global $langs;
		$langs->load("enablebanking@enablebanking");

		$this->error = '';
		$this->errors = array();
		if (!$user->hasRight('enablebanking', 'match') || !$user->hasRight('banque', 'modifier')) {
			$this->error = $langs->transnoentitiesnoconv('NotEnoughPermissions');
			return false;
		}
		if ((int) $tx->status === EbTransaction::STATUS_MATCHED || !empty($tx->fk_bank)) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorAlreadyMatched');
			return false;
		}
		return true;
	}

	/**
	 * Return true if the user can create the payment of this type of invoice for this transaction
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @param	string			$type	Type of invoice
	 * @param	User			$user	User
	 * @return	bool
	 */
	private function canPay(EbTransaction $tx, $type, User $user)
	{
		if ($type !== EnableBankingMatcher::invoiceTypeOf($tx->amount) || empty($tx->amount)) {
			return false;
		}
		if ($type == EnableBankingMatcher::TYPE_INVOICE) {
			return (bool) $user->hasRight('facture', 'paiement');
		}
		return ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));
	}

	/**
	 * Validate a stored candidate
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$matchId	Id of candidate
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function confirm(EbTransaction $tx, $matchId, User $user)
	{
		$matches = $tx->getMatches();
		if ($matches === false || !isset($matches[(int) $matchId])) {
			$this->error = 'Candidate not found';
			return -1;
		}
		$match = $matches[(int) $matchId];
		if ($match['element_type'] == EnableBankingMatcher::TYPE_BANKLINE) {
			return $this->linkBankLine($tx, $match['element_ids'][0], $user);
		}
		return $this->payInvoices($tx, $match['element_type'], $match['element_ids'], $user);
	}

	/**
	 * Create the payment of invoices from a transaction, then reconcile the created bank line
	 *
	 * @param	EbTransaction	$tx				Transaction
	 * @param	string			$type			TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 * @param	int[]			$invoiceIds		Invoices paid, the oldest are paid first
	 * @param	User			$user			User
	 * @return	int								Return integer <0 if KO, >0 if OK
	 */
	public function payInvoices(EbTransaction $tx, $type, $invoiceIds, User $user)
	{
		global $conf, $langs;
		$langs->loadLangs(array("bills", "banks", "enablebanking@enablebanking"));

		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}
		if (!$this->canPay($tx, $type, $user)) {
			$this->error = $langs->transnoentitiesnoconv('NotEnoughPermissions');
			return -1;
		}

		$account = new Account($this->db);
		if ($account->fetch($tx->fk_bank_account) <= 0) {
			return $this->setErrorFrom($account);
		}
		if ($account->currency_code != $conf->currency) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorForeignCurrency');
			return -1;
		}

		$invoices = $this->loadPayableInvoices($type, $invoiceIds);
		$amounts = ($invoices === false) ? false : $this->dispatchAmount($invoices, abs((float) $tx->amount));
		if ($amounts === false) {
			return -1;
		}
		$socid = (int) $invoices[0]->socid;
		$thirdparty = new Societe($this->db);
		$thirdparty->fetch($socid);
		$iscustomer = ($type == EnableBankingMatcher::TYPE_INVOICE);
		$paymentcode = $this->getPaymentCode($tx);

		$this->db->begin();

		$payment = $iscustomer ? new Paiement($this->db) : new PaiementFourn($this->db);
		$payment->datepaye = (int) $tx->booking_date;
		$payment->amounts = $amounts;
		$payment->multicurrency_amounts = array();
		$payment->paiementcode = $paymentcode;
		$payment->paiementid = dol_getIdFromCode($this->db, $paymentcode, 'c_paiement', 'code', 'id', 1);
		$payment->num_payment = dol_trunc($tx->entry_reference, 50, 'right', 'UTF-8', 1);
		$payment->note_private = $tx->remittance;
		$payment->fk_account = $tx->fk_bank_account;
		$payment->ext_payment_site = 'enablebanking';
		$payment->ext_payment_id = dol_trunc($tx->entry_reference, 128, 'right', 'UTF-8', 1);

		$banklineid = -1;
		if ($payment->create($user, 1, $thirdparty) > 0) {
			$label = $iscustomer ? '(CustomerInvoicePayment)' : '(SupplierInvoicePayment)';
			$banklineid = $payment->addPaymentToBank($user, $iscustomer ? 'payment' : 'payment_supplier', $label, $tx->fk_bank_account, $tx->counterparty_name, '');
		}
		if ($banklineid <= 0) {
			$this->db->rollback();
			return $this->setErrorFrom($payment);
		}

		$tx->fk_soc = $socid;
		if ($this->finalize($tx, $banklineid, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Load the invoices to pay, oldest first, and check they can receive a payment
	 *
	 * @param	string	$type			TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 * @param	int[]	$invoiceIds		Ids of invoices
	 * @return	array<int,Facture|FactureFournisseur>|false
	 */
	private function loadPayableInvoices($type, $invoiceIds)
	{
		global $conf, $langs;

		$entities = explode(',', getEntity(EnableBankingMatcher::INVOICE_TYPES[$type]['module']));
		$invoices = array();
		foreach ($invoiceIds as $invoiceid) {
			$invoice = EnableBankingMatcher::newInvoice($this->db, $type);
			if ($invoice->fetch((int) $invoiceid) <= 0 || !in_array($invoice->entity, $entities)) {
				$this->error = $invoice->error ? $invoice->error : 'Invoice not found';
				return false;
			}
			if ($invoice->status != $invoice::STATUS_VALIDATED || (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code != $conf->currency)) {
				$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorInvoiceNotPayable', $invoice->ref);
				return false;
			}
			if (!empty($invoices) && (int) $invoice->socid !== (int) $invoices[0]->socid) {
				$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorSeveralThirdParties');
				return false;
			}
			$invoices[] = $invoice;
		}
		if (empty($invoices)) {
			$this->error = 'No invoice';
			return false;
		}
		usort($invoices, function ($a, $b) {
			return (int) $a->date - (int) $b->date;
		});
		return $invoices;
	}

	/**
	 * Dispatch an amount on invoices, oldest first, up to their remain to pay
	 *
	 * @param	array<int,Facture|FactureFournisseur>	$invoices	Invoices, oldest first
	 * @param	float									$amount		Amount to dispatch
	 * @return	array<int,float>|false								Invoice id => amount, false if the amount is too high
	 */
	private function dispatchAmount($invoices, $amount)
	{
		global $conf, $langs;

		$amounts = array();
		foreach ($invoices as $invoice) {
			$part = (float) price2num(min((float) $invoice->getRemainToPay(), $amount), 'MT');
			if ($part > 0) {
				$amounts[$invoice->id] = $part;
				$amount = (float) price2num($amount - $part, 'MT');
			}
		}
		if ($amount > 0) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorAmountTooHigh', price($amount, 0, $langs, 1, -1, -1, $conf->currency));
			return false;
		}
		return $amounts;
	}

	/**
	 * Link a transaction to a line already entered in Dolibarr, then reconcile it
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$lineId		Id of bank line
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function linkBankLine(EbTransaction $tx, $lineId, User $user)
	{
		global $langs;

		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}

		$line = new AccountLine($this->db);
		if ($line->fetch((int) $lineId) <= 0 || (int) $line->fk_account !== (int) $tx->fk_bank_account) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineNotFound');
			return -1;
		}
		if (!enablebankingSameAmount($line->amount, $tx->amount)) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineAmount');
			return -1;
		}
		$sql = "SELECT rowid FROM ".$this->db->prefix()."enablebanking_transaction WHERE fk_bank = ".((int) $line->id);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) > 0) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineAlreadyLinked');
			return -1;
		}

		$this->db->begin();
		if ($this->finalize($tx, $line->id, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Create a simple bank line (no payment) for a transaction without match, then reconcile it
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	string			$label		Label of line
	 * @param	int				$fkSoc		Third party to link (0 = none)
	 * @param	int				$catId		Bank category (0 = none)
	 * @param	string			$typeCode	Payment mode code (default from transaction)
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function createBankLine(EbTransaction $tx, $label, $fkSoc, $catId, $typeCode, User $user)
	{
		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}

		$account = new Account($this->db);
		if ($account->fetch($tx->fk_bank_account) <= 0) {
			return $this->setErrorFrom($account);
		}
		$thirdparty = new Societe($this->db);
		if ($fkSoc > 0 && $thirdparty->fetch($fkSoc) <= 0) {
			return $this->setErrorFrom($thirdparty);
		}
		$label = trim($label) !== '' ? trim($label) : dol_trunc(trim($tx->counterparty_name.' '.$tx->remittance), 250, 'right', 'UTF-8', 1);
		$typeCode = $typeCode ? $typeCode : $this->getPaymentCode($tx);

		$this->db->begin();

		$lineid = $account->addline((int) $tx->booking_date, $typeCode, $label, (float) $tx->amount, '', (int) $catId, $user, $tx->counterparty_name, '', '', ($tx->value_date ? (int) $tx->value_date : null));
		$error = ($lineid <= 0);
		if (!$error && $thirdparty->id > 0) {
			$url = DOL_URL_ROOT.($tx->amount > 0 ? '/comm/card.php?socid=' : '/fourn/card.php?socid=');
			$error = ($account->add_url_line($lineid, $thirdparty->id, $url, $thirdparty->name, 'company') <= 0);
			$tx->fk_soc = $thirdparty->id;
		}
		if ($error) {
			$this->db->rollback();
			return $this->setErrorFrom($account);
		}

		if ($this->finalize($tx, $lineid, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Set or remove the ignored status of a transaction
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	bool			$ignore		True to ignore, false to set back to process (suggestions are computed again)
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function setIgnored(EbTransaction $tx, $ignore, User $user)
	{
		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}
		$tx->status = $ignore ? EbTransaction::STATUS_IGNORED : EbTransaction::STATUS_NEW;
		if ($tx->update($user) < 0) {
			return $this->setErrorFrom($tx);
		}
		if (!$ignore) {
			$matcher = new EnableBankingMatcher($this->db);
			if ($matcher->suggest(array($tx->id), $user) < 0) {
				return $this->setErrorFrom($matcher);
			}
		}
		return 1;
	}

	/**
	 * Reconcile the bank line of a transaction with the statement of its booking date
	 *
	 * @param	EbTransaction	$tx		Transaction with fk_bank set
	 * @param	User			$user	User
	 * @return	int						Return integer <0 if KO, 0 if not allowed, >0 if OK
	 */
	public function reconcile(EbTransaction $tx, User $user)
	{
		if (empty($tx->fk_bank) || !$user->hasRight('banque', 'consolidate')) {
			return 0;
		}
		$line = new AccountLine($this->db);
		if ($line->fetch((int) $tx->fk_bank) <= 0) {
			return $this->setErrorFrom($line);
		}
		if (!empty($line->rappro)) {
			return 1;
		}
		$line->num_releve = enablebankingGetStatementNumber((int) $tx->booking_date);
		if ($line->update_conciliation($user, 0, 1) < 0) {
			return $this->setErrorFrom($line);
		}
		return 1;
	}

	/**
	 * Store the link with the bank line, mark the transaction as matched and reconcile the line
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$lineId		Bank line
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	private function finalize(EbTransaction $tx, $lineId, User $user)
	{
		global $langs;

		$tx->fk_bank = (int) $lineId;
		$tx->status = EbTransaction::STATUS_MATCHED;
		$tx->date_valid = dol_now();
		$tx->fk_user_valid = $user->id;
		if ($tx->update($user) < 0 || $tx->deleteMatches() < 0) {
			return $this->setErrorFrom($tx);
		}

		// A reconciliation error (statement regex rule...) must not cancel the match: the line stays to reconcile by hand.
		if ($this->reconcile($tx, $user) < 0) {
			$langs->load("enablebanking@enablebanking");
			setEventMessages($langs->trans('EnableBankingWarningNotReconciled'), $this->errors, 'warnings');
			$this->error = '';
			$this->errors = array();
		}
		return 1;
	}

	/**
	 * Return the payment mode code to use for a transaction
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @return	string					Code of llx_c_paiement
	 */
	public function getPaymentCode(EbTransaction $tx)
	{
		// ISO 20022 family codes of direct debits (RDDT, ESDD, BBDD...) or label containing a SEPA direct debit marker
		if (preg_match('/(DDT|ESDD|BBDD|PRLV|DIRECT ?DEBIT)/i', $tx->bank_tx_code.' '.$tx->remittance)) {
			return 'PRE';
		}
		// Card payments (ISO 20022 sub family CCRD/POSD/SMRT)
		if (preg_match('/(CCRD|POSD|SMRT)/i', $tx->bank_tx_code)) {
			return 'CB';
		}
		return getDolGlobalString('ENABLEBANKING_PAYMENT_MODE', 'VIR');
	}
}
