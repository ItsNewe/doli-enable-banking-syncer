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
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/paymentsocialcontribution.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/paymentvat.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');


/**
 * Validation of the matches of imported transactions
 */
class EnableBankingReconciler
{
	use EnableBankingErrors;

	/**
	 * Tax type of a VAT payment (other tax types are ids of llx_c_chargesociales)
	 */
	const TAX_VAT = 'vat';

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
		if ($match['element_type'] == EnableBankingMatcher::TYPE_BANKLINE_REPLACE) {
			return $this->replaceBankLine($tx, $match['element_ids'][0], $user);
		}
		if ($match['element_type'] == EnableBankingMatcher::TYPE_RULE) {
			return $this->applyRule($tx, $match['element_ids'][0], $user);
		}
		return $this->payInvoices($tx, $match['element_type'], $match['element_ids'], $user);
	}

	/**
	 * Apply a rule creating a bank line or a paid tax to a transaction
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$ruleId		Id of rule
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function applyRule(EbTransaction $tx, $ruleId, User $user)
	{
		$rule = new EbRule($this->db);
		if ($rule->fetch((int) $ruleId) <= 0 || !$rule->matches($tx)) {
			$this->error = $rule->error ? $rule->error : 'Rule not found or not matching';
			return -1;
		}
		if ($rule->action == EbRule::ACTION_TAX) {
			$period = $this->guessTaxPeriod($tx, $rule->tax_type === self::TAX_VAT);
			return $this->payTax($tx, (string) $rule->tax_type, (string) $rule->line_label, $period, $user);
		}
		if ($rule->action == EbRule::ACTION_BANKLINE) {
			return $this->createBankLine($tx, (string) $rule->line_label, (int) $rule->fk_soc, (int) $rule->fk_bank_categ, (string) $rule->payment_code, $user);
		}
		$this->error = 'Rule without bank line to create';
		return -1;
	}

	/**
	 * Return the period of a tax paid by a transaction: VAT is paid for the previous month (end of period),
	 * contributions for the month of payment
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @param	bool			$isVat	True for VAT
	 * @return	int						Timestamp
	 */
	public function guessTaxPeriod(EbTransaction $tx, $isVat)
	{
		$year = (int) dol_print_date((int) $tx->booking_date, '%Y');
		$month = (int) dol_print_date((int) $tx->booking_date, '%m');
		if ($isVat) {
			$prev = dol_get_prev_month($month, $year);
			return dol_get_last_day($prev['year'], $prev['month']);
		}
		return dol_get_first_day($year, $month);
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
		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}
		$line = $this->fetchLinkableLine($tx, $lineId);
		if (!$line) {
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
	 * Replace the label and dates of a line already entered in Dolibarr (CSV import...) with the data
	 * of the transaction, then link it. Amount, statement, category and links of the line are kept.
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$lineId		Id of bank line
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function replaceBankLine(EbTransaction $tx, $lineId, User $user)
	{
		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}
		$line = $this->fetchLinkableLine($tx, $lineId);
		if (!$line) {
			return -1;
		}
		$line->dateo = (int) $tx->booking_date;
		$line->datev = $tx->value_date ? (int) $tx->value_date : (int) $tx->booking_date;
		$line->label = $this->getDefaultLabel($tx);

		$this->db->begin();
		if ($line->update($user) < 0 || $line->updateLabel() < 0) {
			$this->db->rollback();
			return $this->setErrorFrom($line);
		}
		if ($this->finalize($tx, $line->id, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Fetch a bank line that can be linked to a transaction: same account, same amount, not linked yet
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	int				$lineId		Id of bank line
	 * @return	AccountLine|null			Line, null if KO (error set)
	 */
	private function fetchLinkableLine(EbTransaction $tx, $lineId)
	{
		global $langs;

		$line = new AccountLine($this->db);
		if ($line->fetch((int) $lineId) <= 0 || (int) $line->fk_account !== (int) $tx->fk_bank_account) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineNotFound');
			return null;
		}
		if (!enablebankingSameAmount($line->amount, $tx->amount)) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineAmount');
			return null;
		}
		$sql = "SELECT rowid FROM ".$this->db->prefix()."enablebanking_transaction WHERE fk_bank = ".((int) $line->id);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) > 0) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineAlreadyLinked');
			return null;
		}
		return $line;
	}

	/**
	 * Return the default label of a bank line created or replaced from a transaction
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @return	string					Label
	 */
	public function getDefaultLabel(EbTransaction $tx)
	{
		return dol_trunc(trim($tx->counterparty_name.' '.$tx->remittance), 250, 'right', 'UTF-8', 1);
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
		$label = trim($label) !== '' ? trim($label) : $this->getDefaultLabel($tx);
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
	 * Create a paid social or fiscal contribution (or a VAT declaration) for a debit transaction, then reconcile
	 * the created bank line
	 *
	 * @param	EbTransaction	$tx			Transaction
	 * @param	string			$taxType	TAX_VAT or id of social contribution type
	 * @param	string			$label		Label of the contribution
	 * @param	int				$period		Period of the contribution (end of period for VAT)
	 * @param	User			$user		User
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	public function payTax(EbTransaction $tx, $taxType, $label, $period, User $user)
	{
		global $langs;

		if (!$this->checkCanMatch($tx, $user)) {
			return -1;
		}
		if ((float) $tx->amount >= 0 || !$user->hasRight('tax', 'charges', 'creer')) {
			$this->error = $langs->transnoentitiesnoconv('NotEnoughPermissions');
			return -1;
		}
		if ($taxType !== self::TAX_VAT && (int) $taxType <= 0) {
			$this->error = $langs->transnoentitiesnoconv('ErrorFieldRequired', $langs->transnoentitiesnoconv('Type'));
			return -1;
		}
		$label = trim($label) !== '' ? trim($label) : $this->getDefaultLabel($tx);

		$this->db->begin();
		$lineid = $this->createTaxPayment($tx->fk_bank_account, (int) $tx->booking_date, abs((float) $tx->amount), $this->getPaymentCode($tx), $taxType, $label, $period, $user, 0, dol_trunc($tx->entry_reference, 50, 'right', 'UTF-8', 1));
		if ($lineid <= 0 || $this->finalize($tx, $lineid, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Create a paid social or fiscal contribution (or a VAT declaration) and its payment, with the core classes.
	 * The payment creates a new bank line, or is attached to an existing line not linked yet ($lineId).
	 *
	 * @param	int		$accountId	Bank account
	 * @param	int		$date		Date of payment (also due date of a contribution)
	 * @param	float	$amount		Amount paid (positive)
	 * @param	string	$payCode	Payment mode code of llx_c_paiement
	 * @param	string	$taxType	TAX_VAT or id of social contribution type
	 * @param	string	$label		Label of the contribution
	 * @param	int		$period		Period of the contribution (end of period for VAT)
	 * @param	User	$user		User
	 * @param	int		$lineId		Existing bank line to attach (0 = create a new line)
	 * @param	string	$numPayment	Payment number
	 * @return	int					Id of bank line if OK, <0 if KO
	 */
	public function createTaxPayment($accountId, $date, $amount, $payCode, $taxType, $label, $period, User $user, $lineId = 0, $numPayment = '')
	{
		$isvat = ($taxType === self::TAX_VAT);
		$paytypeid = dol_getIdFromCode($this->db, $payCode, 'c_paiement', 'code', 'id', 1);
		$amount = (float) price2num($amount, 'MT');

		if ($isvat) {
			$tax = new Tva($this->db);
			$tax->datep = $date;
			$tax->datev = $period;
			$tax->amount = $amount;
			$tax->label = $label;
			$tax->type_payment = $paytypeid;
			$tax->num_payment = $numPayment;
			$tax->fk_account = $accountId;
			$payment = new PaymentVAT($this->db);
			$mode = 'payment_vat';
		} else {
			$tax = new ChargeSociales($this->db);
			$tax->type = (int) $taxType;
			$tax->label = $label;
			$tax->date_ech = $date;
			$tax->period = $period;
			$tax->amount = $amount;
			$tax->mode_reglement_id = $paytypeid;
			$tax->fk_account = $accountId;
			$payment = new PaymentSocialContribution($this->db);
			$mode = 'payment_sc';
		}

		$this->db->begin();
		$error = ($tax->create($user) <= 0);
		if (!$error) {
			$payment->chid = $tax->id;
			$payment->datepaye = $date;
			$payment->amounts = array($tax->id => $amount);
			$payment->paiementtype = $paytypeid;
			$payment->num_payment = $numPayment;
			$error = ($payment->create($user, 1) <= 0);
		}
		if ($error) {
			$this->db->rollback();
			return $this->setErrorFrom($tax->id > 0 ? $payment : $tax);
		}

		if ($lineId > 0) {
			$error = ($this->attachTaxPaymentToLine($payment, $tax, $mode, $lineId) <= 0);
		} else {
			$error = ($payment->addPaymentToBank($user, $mode, $isvat ? '(VATPayment)' : '(SocialContributionPayment)', $accountId, '', '') <= 0);
			if (!$error) {
				$payment->fetch($payment->id);
				$lineId = (int) $payment->fk_bank;
			}
		}
		if ($error || $lineId <= 0) {
			$this->db->rollback();
			return $this->error ? -1 : $this->setErrorFrom($payment);
		}
		$this->db->commit();
		return $lineId;
	}

	/**
	 * Attach a tax payment to an existing bank line, with the same links addPaymentToBank() creates for a new line
	 *
	 * @param	PaymentSocialContribution|PaymentVAT	$payment	Payment
	 * @param	ChargeSociales|Tva						$tax		Contribution or VAT paid
	 * @param	string									$mode		'payment_sc' or 'payment_vat'
	 * @param	int										$lineId		Bank line
	 * @return	int														Return integer <0 if KO, >0 if OK
	 */
	private function attachTaxPaymentToLine($payment, $tax, $mode, $lineId)
	{
		global $langs;

		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."bank_url WHERE fk_bank = ".((int) $lineId);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj || (int) $obj->nb > 0) {
			$this->error = $langs->transnoentitiesnoconv('EnableBankingErrorBankLineAlreadyLinked');
			return -1;
		}
		if ($payment->update_fk_bank($lineId) <= 0) {
			return $this->setErrorFrom($payment);
		}

		$account = new Account($this->db);
		if ($mode == 'payment_vat') {
			$ok = $account->add_url_line($lineId, $payment->id, DOL_URL_ROOT.'/compta/payment_vat/card.php?id=', '(paiement)', $mode) > 0
				&& $account->add_url_line($lineId, $tax->id, DOL_URL_ROOT.'/compta/tva/card.php?id=', '('.$tax->label.')', 'vat') > 0;
		} else {
			$tax->fetch($tax->id);
			$ok = $account->add_url_line($lineId, $payment->id, DOL_URL_ROOT.'/compta/payment_sc/card.php?id=', '(paiement)', $mode) > 0
				&& $account->add_url_line($lineId, $tax->id, DOL_URL_ROOT.'/compta/charges.php?id=', $tax->type_label.(($tax->lib && $tax->lib != $tax->type_label) ? ' ('.$tax->lib.')' : ''), 'sc') > 0;
		}
		return $ok ? 1 : $this->setErrorFrom($account);
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
