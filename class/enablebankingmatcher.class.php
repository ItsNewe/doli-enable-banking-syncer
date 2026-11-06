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
 * \file    htdocs/custom/enablebanking/class/enablebankingmatcher.class.php
 * \ingroup enablebanking
 * \brief   Suggest matches between imported transactions and invoices or bank lines
 */

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
dol_include_once('/enablebanking/class/ebtransaction.class.php');
dol_include_once('/enablebanking/class/ebrule.class.php');
dol_include_once('/enablebanking/lib/enablebanking.lib.php');


/**
 * Compute and store the candidates of imported transactions
 */
class EnableBankingMatcher
{
	use EnableBankingErrors;

	/** Types of candidates */
	const TYPE_INVOICE = 'facture';
	const TYPE_SUPPLIER_INVOICE = 'invoice_supplier';
	const TYPE_BANKLINE = 'bankline';
	const TYPE_BANKLINE_REPLACE = 'bankline_replace';
	const TYPE_RULE = 'rule';

	/** Scores of the criteria */
	const SCORE_INVOICE_REF = 50;
	const SCORE_INVOICE_EXTREF = 40;
	const SCORE_IBAN = 40;
	const SCORE_NAME = 25;
	const SCORE_NAME_PARTIAL = 15;
	const SCORE_AMOUNT = 10;
	const SCORE_LINE_LABEL = 20;
	const SCORE_BANKLINE = 60;
	const SCORE_BANKLINE_SAMEDAY = 20;
	const SCORE_GROUPED_BONUS = 5;
	const SCORE_RULE = 70;
	const SCORE_RULE_SOC = 40;

	/** Max number of candidates stored per transaction */
	const MAX_CANDIDATES = 5;

	/** Max number of candidates found on amount only (no third party nor reference) */
	const MAX_AMOUNT_ONLY = 3;

	/** Tables and fields of each type of invoice */
	const INVOICE_TYPES = array(
		self::TYPE_INVOICE => array(
			'module' => 'invoice',
			'class' => 'Facture',
			'table' => 'facture',
			'extref' => 'ref_client',
			'paytable' => 'paiement_facture',
			'payfield' => 'fk_facture',
			'discountfield' => 'fk_facture',
			'types' => array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_DEPOSIT, Facture::TYPE_SITUATION),
			'lines' => array('table' => 'facturedet', 'fk' => 'fk_facture', 'ref' => ''),
			'url' => '/compta/facture/card.php?facid=',
		),
		self::TYPE_SUPPLIER_INVOICE => array(
			'module' => 'supplier_invoice',
			'class' => 'FactureFournisseur',
			'table' => 'facture_fourn',
			'extref' => 'ref_supplier',
			'paytable' => 'paiementfourn_facturefourn',
			'payfield' => 'fk_facturefourn',
			'discountfield' => 'fk_invoice_supplier',
			'types' => array(FactureFournisseur::TYPE_STANDARD, FactureFournisseur::TYPE_REPLACEMENT, FactureFournisseur::TYPE_DEPOSIT),
			'lines' => array('table' => 'facture_fourn_det', 'fk' => 'fk_facture_fourn', 'ref' => 'd.ref'),
			'url' => '/fourn/facture/card.php?facid=',
		),
	);

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
	 * Return the type of invoice paid by a transaction: customer invoice for a credit, supplier invoice for a debit
	 *
	 * @param	float	$amount		Signed amount of the transaction
	 * @return	string				TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 */
	public static function invoiceTypeOf($amount)
	{
		return ($amount > 0) ? self::TYPE_INVOICE : self::TYPE_SUPPLIER_INVOICE;
	}

	/**
	 * Return a new empty invoice object of a type
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$type	TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 * @return	Facture|FactureFournisseur
	 */
	public static function newInvoice($db, $type)
	{
		$class = self::INVOICE_TYPES[$type]['class'];
		return new $class($db);
	}

	/**
	 * Return true if a type of candidate is an existing bank line (to link or to replace)
	 *
	 * @param	string	$type	Type of candidate
	 * @return	bool
	 */
	public static function isBankLineType($type)
	{
		return in_array($type, array(self::TYPE_BANKLINE, self::TYPE_BANKLINE_REPLACE));
	}

	/**
	 * Return the URL of the card of a candidate element
	 *
	 * @param	string	$type	Type of candidate
	 * @param	int		$id		Id of element
	 * @return	string			URL
	 */
	public static function getElementUrl($type, $id)
	{
		if ($type == self::TYPE_RULE) {
			return dol_buildpath('/enablebanking/admin/rules.php', 1).'?action=edit&id='.((int) $id);
		}
		$url = self::isBankLineType($type) ? '/compta/bank/line.php?rowid=' : self::INVOICE_TYPES[$type]['url'];
		return DOL_URL_ROOT.$url.((int) $id);
	}

	/**
	 * Return the map normalized IBAN => third party id, from the bank accounts of third parties.
	 * IBANs are stored encrypted, so they can't be searched with SQL.
	 *
	 * @return	array<string,int>|false
	 */
	public function loadIbanMap()
	{
		global $conf;

		if (isset($conf->cache['enablebanking_ibanmap'])) {
			return $conf->cache['enablebanking_ibanmap'];
		}

		$map = array();
		$sql = "SELECT sr.fk_soc, sr.iban_prefix FROM ".$this->db->prefix()."societe_rib as sr";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = sr.fk_soc";
		$sql .= " WHERE sr.type = 'ban' AND sr.iban_prefix IS NOT NULL AND sr.iban_prefix <> ''";
		$sql .= " AND s.entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$iban = enablebankingNormalizeIban(dolDecrypt((string) $obj->iban_prefix));
			if ($iban !== '') {
				$map[$iban] = (int) $obj->fk_soc;
			}
		}
		$this->db->free($resql);

		$conf->cache['enablebanking_ibanmap'] = $map;
		return $map;
	}

	/**
	 * Return open invoices of one type with their approximate remain to pay
	 *
	 * @param	string	$type	TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 * @return	array<int,array{id:int,ref:string,extref:string,fk_soc:int,socname:string,socalias:string,remain:float,date:int|string,keywords:string[][]}>|false
	 */
	public function loadOpenInvoices($type)
	{
		$def = self::INVOICE_TYPES[$type];
		if (!isModEnabled($def['module'])) {
			return array();
		}

		$sql = "SELECT f.rowid, f.ref, f.".$def['extref']." as extref, f.fk_soc, f.total_ttc, f.datef, s.nom as socname, s.name_alias,";
		$sql .= " (SELECT SUM(p.amount) FROM ".$this->db->prefix().$def['paytable']." as p WHERE p.".$def['payfield']." = f.rowid) as paid,";
		$sql .= " (SELECT SUM(re.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as re WHERE re.".$def['discountfield']." = f.rowid) as discounts";
		$sql .= " FROM ".$this->db->prefix().$def['table']." as f";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity IN (".getEntity($def['module']).")";
		$sql .= " AND f.fk_statut = 1 AND f.paye = 0";
		$sql .= " AND f.type IN (".$this->db->sanitize(implode(',', $def['types'])).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$invoices = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$remain = (float) price2num((float) $obj->total_ttc - (float) $obj->paid - (float) $obj->discounts, 'MT');
			if ($remain <= 0) {
				continue;
			}
			$invoices[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'extref' => (string) $obj->extref,
				'fk_soc' => (int) $obj->fk_soc,
				'socname' => (string) $obj->socname,
				'socalias' => (string) $obj->name_alias,
				'remain' => $remain,
				'date' => $this->db->jdate($obj->datef),
				'keywords' => array(),
			);
		}
		$this->db->free($resql);

		if (!empty($invoices) && $this->loadInvoiceKeywords($type, $invoices) < 0) {
			return false;
		}
		return $invoices;
	}

	/**
	 * Add to each invoice the significant words of its lines (label, description, product ref and label)
	 *
	 * @param	string	$type		TYPE_INVOICE or TYPE_SUPPLIER_INVOICE
	 * @param	array<int,array<string,mixed>>	$invoices	Invoices indexed by id, completed with key 'keywords'
	 * @return	int					Return integer <0 if KO, >0 if OK
	 */
	private function loadInvoiceKeywords($type, &$invoices)
	{
		$lines = self::INVOICE_TYPES[$type]['lines'];
		foreach (array_chunk(array_keys($invoices), 500) as $chunk) {
			$sql = "SELECT d.".$lines['fk']." as fk_invoice, d.label, d.description, p.ref as productref, p.label as productlabel";
			$sql .= ($lines['ref'] ? ", ".$lines['ref']." as lineref" : "");
			$sql .= " FROM ".$this->db->prefix().$lines['table']." as d";
			$sql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = d.fk_product";
			$sql .= " WHERE d.".$lines['fk']." IN (".$this->db->sanitize(implode(',', $chunk)).")";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$texts = array($obj->label, dol_trunc(dol_string_nohtmltag((string) $obj->description), 255, 'right', 'UTF-8', 1), $obj->productref, $obj->productlabel);
				if (!empty($obj->lineref)) {
					$texts[] = $obj->lineref;
				}
				foreach ($texts as $text) {
					$words = self::significantWords((string) $text);
					if (!empty($words)) {
						$invoices[(int) $obj->fk_invoice]['keywords'][] = $words;
					}
				}
			}
			$this->db->free($resql);
		}
		return 1;
	}

	/**
	 * Return the significant words of a text: at least 4 characters, without generic words
	 *
	 * @param	string	$text	Text
	 * @param	int		$max	Max number of words returned (0 = all)
	 * @return	string[]		Words
	 */
	public static function significantWords($text, $max = 4)
	{
		$generic = array('facture', 'factures', 'invoice', 'invoices', 'paiement', 'payment', 'reglement', 'virement', 'transfer', 'service', 'services', 'prestation', 'prestations', 'produit', 'produits', 'product', 'products', 'achat', 'vente', 'avoir', 'acompte', 'mois', 'month', 'total', 'frais', 'pour', 'avec', 'from', 'with', 'votre', 'your');
		$words = array();
		foreach (enablebankingWords($text) as $word) {
			if (strlen($word) >= 4 && !in_array($word, $generic) && !in_array($word, $words)) {
				$words[] = $word;
				if ($max && count($words) >= $max) {
					break;
				}
			}
		}
		return $words;
	}

	/**
	 * Return true if all significant words of one line of the invoice are in the remittance information
	 *
	 * @param	array<string,int>	$remittanceWords	Words of the remittance (as keys)
	 * @param	string[][]			$keywords			Significant words of each text of the invoice lines
	 * @return	bool
	 */
	private static function lineInRemittance($remittanceWords, $keywords)
	{
		foreach ($keywords as $words) {
			if (!empty($remittanceWords) && !array_diff($words, array_keys($remittanceWords))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the lines of a bank account between two dates that are not yet linked to a transaction.
	 * Reconciled lines are included: they may come from another import (CSV statement...).
	 *
	 * @param	int		$fkAccount	Bank account
	 * @param	int		$dateFrom	Timestamp
	 * @param	int		$dateTo		Timestamp
	 * @return	array<int,array{id:int,date:int|string,amount:float,label:string,rappro:int,num_releve:string}>|false
	 */
	public function loadOpenBankLines($fkAccount, $dateFrom, $dateTo)
	{
		$sql = "SELECT b.rowid, b.dateo, b.amount, b.label, b.rappro, b.num_releve FROM ".$this->db->prefix()."bank as b";
		$sql .= " WHERE b.fk_account = ".((int) $fkAccount);
		$sql .= " AND b.dateo >= '".$this->db->idate($dateFrom)."' AND b.dateo <= '".$this->db->idate($dateTo)."'";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$this->db->prefix()."enablebanking_transaction as t WHERE t.fk_bank = b.rowid)";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$lines = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lines[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'date' => $this->db->jdate($obj->dateo),
				'amount' => (float) $obj->amount,
				'label' => (string) $obj->label,
				'rappro' => (int) $obj->rappro,
				'num_releve' => (string) $obj->num_releve,
			);
		}
		$this->db->free($resql);
		return $lines;
	}

	/**
	 * Score how much a counterparty name looks like a third party name
	 *
	 * @param	string	$normalizedName		Normalized counterparty name
	 * @param	string	$socName			Third party name
	 * @param	string	$socAlias			Third party alias
	 * @return	int							Score
	 */
	private static function scoreName($normalizedName, $socName, $socAlias)
	{
		if ($normalizedName === '') {
			return 0;
		}
		$best = 0;
		foreach (array($socName, $socAlias) as $candidate) {
			$candidate = enablebankingNormalizeName($candidate);
			if ($candidate === '') {
				continue;
			}
			if ($candidate === $normalizedName) {
				return self::SCORE_NAME;
			}
			$shortest = min(strlen($candidate), strlen($normalizedName));
			if ($shortest >= 4 && (strpos($normalizedName, $candidate) !== false || strpos($candidate, $normalizedName) !== false)) {
				$best = self::SCORE_NAME_PARTIAL;
			}
		}
		return $best;
	}

	/**
	 * Return true if a reference is written in the remittance information
	 *
	 * @param	string	$compactRemittance	Remittance compacted with enablebankingCompactKey()
	 * @param	string	$ref				Reference
	 * @return	bool
	 */
	private static function refInRemittance($compactRemittance, $ref)
	{
		$ref = enablebankingCompactKey($ref);
		return (strlen($ref) >= 4 && $compactRemittance !== '' && strpos($compactRemittance, $ref) !== false);
	}

	/**
	 * Compute and store the candidates of transactions not yet matched
	 *
	 * @param	int[]	$ids	Ids of transactions
	 * @param	User	$user	User
	 * @return	int				Number of transactions with at least one candidate, <0 if KO
	 */
	public function suggest($ids, User $user)
	{
		$txstatic = new EbTransaction($this->db);
		$transactions = $txstatic->fetchByIds($ids, array(EbTransaction::STATUS_NEW, EbTransaction::STATUS_SUGGESTED));
		if ($transactions === false) {
			return $this->setErrorFrom($txstatic);
		}
		if (empty($transactions)) {
			return 0;
		}

		$ibanmap = $this->loadIbanMap();
		$invoices = array(
			self::TYPE_INVOICE => $this->loadOpenInvoices(self::TYPE_INVOICE),
			self::TYPE_SUPPLIER_INVOICE => $this->loadOpenInvoices(self::TYPE_SUPPLIER_INVOICE),
		);
		$tolerance = max(0, getDolGlobalInt('ENABLEBANKING_MATCH_DAYS', 5)) * 86400;
		$accounts = $this->loadAccountContexts($transactions, $tolerance);
		$rulestatic = new EbRule($this->db);
		$rules = $rulestatic->fetchAllRules();
		if ($rules === false) {
			$this->error = $rulestatic->error;
		}
		if ($ibanmap === false || in_array(false, $invoices, true) || $accounts === false || $rules === false) {
			return -1;
		}

		$nbsuggested = 0;
		$this->db->begin();
		foreach ($transactions as $tx) {
			$context = $accounts[(int) $tx->fk_bank_account];
			$candidates = $this->computeCandidates($tx, $ibanmap, $invoices[self::invoiceTypeOf($tx->amount)], $context['lines'], $tolerance, $context['payable'], $rules);
			if ($this->storeCandidates($tx, $candidates, $user) < 0) {
				$this->db->rollback();
				return -1;
			}
			$nbsuggested += count($candidates) ? 1 : 0;
		}
		$this->db->commit();

		return $nbsuggested;
	}

	/**
	 * Load, for each bank account of the transactions, its open lines around their dates and if payments are allowed
	 *
	 * @param	EbTransaction[]		$transactions	Transactions
	 * @param	int					$tolerance		Tolerance on dates in seconds
	 * @return	array<int,array{lines:array<int,array<string,mixed>>,payable:bool}>|false
	 */
	private function loadAccountContexts($transactions, $tolerance)
	{
		global $conf;

		$bounds = array();
		foreach ($transactions as $tx) {
			$a = (int) $tx->fk_bank_account;
			$d = (int) $tx->booking_date;
			$bounds[$a] = isset($bounds[$a]) ? array(min($bounds[$a][0], $d), max($bounds[$a][1], $d)) : array($d, $d);
		}
		$contexts = array();
		foreach ($bounds as $fkaccount => $bound) {
			$lines = $this->loadOpenBankLines($fkaccount, $bound[0] - $tolerance, $bound[1] + $tolerance);
			if ($lines === false) {
				return false;
			}
			$account = new Account($this->db);
			$account->fetch($fkaccount);
			// Payments are only created on accounts in the company currency
			$contexts[$fkaccount] = array('lines' => $lines, 'payable' => ($account->currency_code == $conf->currency));
		}
		return $contexts;
	}

	/**
	 * Replace the stored candidates of a transaction and update its status
	 *
	 * @param	EbTransaction	$tx				Transaction
	 * @param	array<int,array{element_type:string,element_ids:int[],fk_soc:int,score:int,label:string}>	$candidates	Candidates, best first
	 * @param	User			$user			User
	 * @return	int								Return integer <0 if KO, >0 if OK
	 */
	private function storeCandidates(EbTransaction $tx, $candidates, User $user)
	{
		if ($tx->deleteMatches() < 0) {
			return $this->setErrorFrom($tx);
		}
		foreach ($candidates as $candidate) {
			if ($tx->addMatch($candidate['element_type'], $candidate['element_ids'], $candidate['fk_soc'], $candidate['score'], $candidate['label']) < 0) {
				return $this->setErrorFrom($tx);
			}
		}
		$tx->status = count($candidates) ? EbTransaction::STATUS_SUGGESTED : EbTransaction::STATUS_NEW;
		if (empty($tx->fk_soc) && !empty($candidates[0]['fk_soc'])) {
			$tx->fk_soc = $candidates[0]['fk_soc'];
		}
		if ($tx->update($user) < 0) {
			return $this->setErrorFrom($tx);
		}
		return 1;
	}

	/**
	 * Compute the candidates of one transaction, best first
	 *
	 * @param	EbTransaction				$tx				Transaction
	 * @param	array<string,int>			$ibanmap		IBAN => third party
	 * @param	array<int,array<string,mixed>>	$invoices	Open invoices of the type paid by the transaction
	 * @param	array<int,array<string,mixed>>	$banklines	Open bank lines of the account
	 * @param	int							$tolerance		Tolerance on dates in seconds
	 * @param	bool						$allowPayments	False if payments can't be created (foreign currency)
	 * @param	EbRule[]					$rules			Active rules
	 * @return	array<int,array{element_type:string,element_ids:int[],fk_soc:int,score:int,label:string}>
	 */
	public function computeCandidates(EbTransaction $tx, $ibanmap, $invoices, $banklines, $tolerance, $allowPayments = true, $rules = array())
	{
		global $langs;
		$langs->load("enablebanking@enablebanking");

		$candidates = $this->bankLineCandidates($tx, $banklines, $tolerance);

		$socrules = array();
		foreach ($rules as $rule) {
			if (!$rule->matches($tx)) {
				continue;
			}
			if ($rule->action == EbRule::ACTION_THIRDPARTY) {
				$socrules[(int) $rule->fk_soc] = self::SCORE_RULE_SOC;
			} elseif ($rule->action == EbRule::ACTION_BANKLINE || $allowPayments) {
				$candidates[] = array(
					'element_type' => self::TYPE_RULE,
					'element_ids' => array($rule->id),
					'fk_soc' => (int) $rule->fk_soc,
					'score' => self::SCORE_RULE,
					'label' => $rule->label,
				);
			}
		}

		if ($allowPayments && !empty($invoices)) {
			$socscores = $this->thirdPartyScores($tx, $ibanmap, $invoices);
			// Third parties designated by rules count like a known IBAN
			foreach ($socrules as $socid => $score) {
				$socscores[$socid] = max(isset($socscores[$socid]) ? $socscores[$socid] : 0, $score);
			}
			$refscores = $this->invoiceTextScores($tx, $invoices);
			$candidates = array_merge(
				$candidates,
				$this->invoiceCandidates($tx, $invoices, $socscores, $refscores),
				$this->groupedInvoiceCandidates($tx, $invoices, $socscores)
			);

			// Keep the third party found from IBAN or name even without matching invoice
			if (empty($tx->fk_soc) && !empty($socscores)) {
				arsort($socscores);
				$tx->fk_soc = (int) key($socscores);
			}
		}
		if (empty($tx->fk_soc) && !empty($socrules)) {
			$tx->fk_soc = (int) key($socrules);
		}

		usort($candidates, function ($a, $b) {
			return $b['score'] - $a['score'];
		});
		return $this->keepPayable(array_slice($candidates, 0, self::MAX_CANDIDATES), abs((float) $tx->amount));
	}

	/**
	 * Candidates among lines already entered in Dolibarr (avoids duplicates)
	 *
	 * @param	EbTransaction					$tx			Transaction
	 * @param	array<int,array<string,mixed>>	$banklines	Open bank lines of the account
	 * @param	int								$tolerance	Tolerance on dates in seconds
	 * @return	array<int,array<string,mixed>>
	 */
	private function bankLineCandidates(EbTransaction $tx, $banklines, $tolerance)
	{
		global $langs;

		$candidates = array();
		$bookingdate = (int) $tx->booking_date;
		foreach ($banklines as $line) {
			if (!enablebankingSameAmount($line['amount'], $tx->amount) || abs((int) $line['date'] - $bookingdate) > $tolerance) {
				continue;
			}
			$sameday = (dol_print_date($line['date'], 'day') == dol_print_date($bookingdate, 'day'));
			$candidate = array(
				'element_type' => self::TYPE_BANKLINE,
				'element_ids' => array($line['id']),
				'fk_soc' => 0,
				'score' => self::SCORE_BANKLINE + ($sameday ? self::SCORE_BANKLINE_SAMEDAY : 0),
				'label' => $langs->transnoentitiesnoconv('EnableBankingExistingBankLine', $line['id'], dol_print_date($line['date'], 'day'), $line['label']),
			);
			if ($line['rappro']) {
				// Line reconciled by another import: keep it (default) or replace its data with the bank ones
				$candidate['label'] = $langs->transnoentitiesnoconv('EnableBankingExistingReconciledLine', $line['id'], dol_print_date($line['date'], 'day'), $line['label'], $line['num_releve']);
				$candidates[] = array_merge($candidate, array('element_type' => self::TYPE_BANKLINE_REPLACE, 'score' => $candidate['score'] - 1));
			}
			$candidates[] = $candidate;
		}
		return $candidates;
	}

	/**
	 * Score the third parties of the invoices from the IBAN and the name of the counterparty
	 *
	 * @param	EbTransaction					$tx			Transaction
	 * @param	array<string,int>				$ibanmap	IBAN => third party
	 * @param	array<int,array<string,mixed>>	$invoices	Open invoices
	 * @return	array<int,int>								Third party id => score
	 */
	private function thirdPartyScores(EbTransaction $tx, $ibanmap, $invoices)
	{
		$socscores = array();
		$iban = enablebankingNormalizeIban($tx->counterparty_iban);
		if ($iban !== '' && isset($ibanmap[$iban])) {
			$socscores[$ibanmap[$iban]] = self::SCORE_IBAN;
		}
		$name = enablebankingNormalizeName($tx->counterparty_name);
		$scored = array();
		foreach ($invoices as $invoice) {
			$socid = $invoice['fk_soc'];
			if (isset($scored[$socid])) {
				continue;
			}
			$scored[$socid] = true;
			$namescore = self::scoreName($name, $invoice['socname'], $invoice['socalias']);
			if ($namescore) {
				$socscores[$socid] = (isset($socscores[$socid]) ? $socscores[$socid] : 0) + $namescore;
			}
		}
		return $socscores;
	}

	/**
	 * Score the invoices whose reference or product is written in the remittance information
	 *
	 * @param	EbTransaction					$tx			Transaction
	 * @param	array<int,array<string,mixed>>	$invoices	Open invoices
	 * @return	array<int,int>								Invoice id => score
	 */
	private function invoiceTextScores(EbTransaction $tx, $invoices)
	{
		$remittance = enablebankingCompactKey($tx->remittance);
		$remittancewords = array_flip(self::significantWords((string) $tx->remittance, 0));
		$scores = array();
		foreach ($invoices as $invoice) {
			$score = 0;
			if (self::refInRemittance($remittance, $invoice['ref'])) {
				$score = self::SCORE_INVOICE_REF;
			} elseif (self::refInRemittance($remittance, $invoice['extref'])) {
				$score = self::SCORE_INVOICE_EXTREF;
			}
			if (self::lineInRemittance($remittancewords, $invoice['keywords'])) {
				$score += self::SCORE_LINE_LABEL;
			}
			if ($score) {
				$scores[$invoice['id']] = $score;
			}
		}
		return $scores;
	}

	/**
	 * Candidates among single invoices whose remain to pay is the amount of the transaction
	 *
	 * @param	EbTransaction					$tx			Transaction
	 * @param	array<int,array<string,mixed>>	$invoices	Open invoices
	 * @param	array<int,int>					$socscores	Third party id => score
	 * @param	array<int,int>					$refscores	Invoice id => score
	 * @return	array<int,array<string,mixed>>
	 */
	private function invoiceCandidates(EbTransaction $tx, $invoices, $socscores, $refscores)
	{
		$candidates = array();
		$amountonly = array();
		foreach ($invoices as $invoice) {
			if (!enablebankingSameAmount($invoice['remain'], abs((float) $tx->amount))) {
				continue;
			}
			$clues = (isset($socscores[$invoice['fk_soc']]) ? $socscores[$invoice['fk_soc']] : 0) + (isset($refscores[$invoice['id']]) ? $refscores[$invoice['id']] : 0);
			$candidate = array(
				'element_type' => self::invoiceTypeOf($tx->amount),
				'element_ids' => array($invoice['id']),
				'fk_soc' => $invoice['fk_soc'],
				'score' => $clues + self::SCORE_AMOUNT,
				'label' => $invoice['ref'].' - '.$invoice['socname'].' ('.price($invoice['remain']).')',
			);
			if ($clues) {
				$candidates[] = $candidate;
			} else {
				$amountonly[] = $candidate;
			}
		}
		// Amount alone is a weak clue: keep it only when it designates few invoices
		return (count($amountonly) <= self::MAX_AMOUNT_ONLY) ? array_merge($candidates, $amountonly) : $candidates;
	}

	/**
	 * Candidates paying at once all open invoices of an identified third party
	 *
	 * @param	EbTransaction					$tx			Transaction
	 * @param	array<int,array<string,mixed>>	$invoices	Open invoices
	 * @param	array<int,int>					$socscores	Third party id => score
	 * @return	array<int,array<string,mixed>>
	 */
	private function groupedInvoiceCandidates(EbTransaction $tx, $invoices, $socscores)
	{
		$bysoc = array();
		foreach ($invoices as $invoice) {
			$bysoc[$invoice['fk_soc']][] = $invoice;
		}
		$candidates = array();
		foreach ($socscores as $socid => $socscore) {
			if (empty($bysoc[$socid]) || count($bysoc[$socid]) < 2) {
				continue;
			}
			$sum = array_sum(array_column($bysoc[$socid], 'remain'));
			if (enablebankingSameAmount($sum, abs((float) $tx->amount))) {
				$candidates[] = array(
					'element_type' => self::invoiceTypeOf($tx->amount),
					'element_ids' => array_column($bysoc[$socid], 'id'),
					'fk_soc' => $socid,
					'score' => $socscore + self::SCORE_GROUPED_BONUS,
					'label' => implode(', ', array_column($bysoc[$socid], 'ref')).' - '.$bysoc[$socid][0]['socname'],
				);
			}
		}
		return $candidates;
	}

	/**
	 * Keep the candidates whose remain to pay, checked with the core method, is the amount.
	 * The SQL preselection is an approximation.
	 *
	 * @param	array<int,array<string,mixed>>	$candidates		Candidates
	 * @param	float							$amount			Absolute amount of the transaction
	 * @return	array<int,array<string,mixed>>
	 */
	private function keepPayable($candidates, $amount)
	{
		$checked = array();
		foreach ($candidates as $candidate) {
			$sum = 0;
			$isinvoice = isset(self::INVOICE_TYPES[$candidate['element_type']]);
			if ($isinvoice) {
				foreach ($candidate['element_ids'] as $invoiceid) {
					$invoice = self::newInvoice($this->db, $candidate['element_type']);
					if ($invoice->fetch($invoiceid) > 0) {
						$sum += (float) $invoice->getRemainToPay();
					}
				}
			}
			if (!$isinvoice || enablebankingSameAmount($sum, $amount)) {
				$checked[] = $candidate;
			}
		}
		return $checked;
	}

	/**
	 * Return true if the best candidate is sure enough to be preselected for mass validation
	 *
	 * @param	array<int,array{score:int}>	$matches	Candidates, best first
	 * @return	bool
	 */
	public static function isSure($matches)
	{
		if (count($matches) != 1) {
			return false;
		}
		$match = reset($matches);
		return ($match['score'] >= getDolGlobalInt('ENABLEBANKING_SURE_SCORE', 65));
	}
}
