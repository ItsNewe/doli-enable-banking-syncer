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
 * \file    htdocs/custom/enablebanking/class/ebrule.class.php
 * \ingroup enablebanking
 * \brief   Rule proposing a match for imported transactions, defined in setup or learned from a manual match
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/enablebanking/class/ebtransaction.class.php');
dol_include_once('/enablebanking/class/enablebankingmatcher.class.php');
dol_include_once('/enablebanking/lib/enablebanking.lib.php');


/**
 * Class EbRule
 */
class EbRule extends CommonObject
{
	/**
	 * @var string Module of object
	 */
	public $module = 'enablebanking';

	/**
	 * @var string Id to identify managed objects
	 */
	public $element = 'enablebanking_rule';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'enablebanking_rule';

	/**
	 * @var int<0,1>|string
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int<0,1>
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string
	 */
	public $picto = 'bank_account';

	/** Actions: propose the invoices of a third party, create a bank line or a paid tax */
	const ACTION_THIRDPARTY = 'thirdparty';
	const ACTION_BANKLINE = 'bankline';
	const ACTION_TAX = 'tax';

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'default' => '1'),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'css' => 'minwidth300'),
		'active' => array('type' => 'integer', 'label' => 'Enabled', 'enabled' => 1, 'position' => 11, 'notnull' => 1, 'visible' => 1, 'default' => '1'),
		'fk_bank_account' => array('type' => 'integer:Account:compta/bank/class/account.class.php', 'label' => 'BankAccount', 'enabled' => 1, 'position' => 20, 'notnull' => -1, 'visible' => 1),
		'direction' => array('type' => 'select', 'label' => 'EnableBankingDirection', 'enabled' => 1, 'position' => 21, 'notnull' => -1, 'visible' => 1, 'arrayofkeyval' => array('in' => 'Credit', 'out' => 'Debit')),
		'counterparty' => array('type' => 'varchar(255)', 'label' => 'EnableBankingCounterparty', 'enabled' => 1, 'position' => 22, 'visible' => 1, 'css' => 'minwidth300', 'help' => 'EnableBankingRuleCounterpartyHelp'),
		'iban' => array('type' => 'varchar(64)', 'label' => 'IBAN', 'enabled' => 1, 'position' => 23, 'visible' => 1, 'css' => 'minwidth300'),
		'remittance' => array('type' => 'varchar(255)', 'label' => 'EnableBankingRuleRemittance', 'enabled' => 1, 'position' => 24, 'visible' => 1, 'css' => 'minwidth300', 'help' => 'EnableBankingRuleRemittanceHelp'),
		'amount_min' => array('type' => 'double(24,8)', 'label' => 'EnableBankingRuleAmountMin', 'enabled' => 1, 'position' => 25, 'visible' => 1, 'css' => 'width75'),
		'amount_max' => array('type' => 'double(24,8)', 'label' => 'EnableBankingRuleAmountMax', 'enabled' => 1, 'position' => 26, 'visible' => 1, 'css' => 'width75'),
		'action' => array('type' => 'select', 'label' => 'EnableBankingRuleAction', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1, 'arrayofkeyval' => array(self::ACTION_THIRDPARTY => 'EnableBankingRuleAction_thirdparty', self::ACTION_BANKLINE => 'EnableBankingRuleAction_bankline', self::ACTION_TAX => 'EnableBankingRuleAction_tax')),
		'fk_soc' => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'ThirdParty', 'enabled' => 1, 'position' => 31, 'notnull' => -1, 'visible' => 1),
		'fk_bank_categ' => array('type' => 'integer', 'label' => 'Rubrique', 'enabled' => 1, 'position' => 32, 'notnull' => -1, 'visible' => 1),
		'payment_code' => array('type' => 'varchar(16)', 'label' => 'PaymentMode', 'enabled' => 1, 'position' => 33, 'visible' => 1),
		'tax_type' => array('type' => 'varchar(16)', 'label' => 'Type', 'enabled' => 1, 'position' => 34, 'visible' => 1),
		'line_label' => array('type' => 'varchar(255)', 'label' => 'EnableBankingRuleLineLabel', 'enabled' => 1, 'position' => 35, 'visible' => 1, 'css' => 'minwidth300', 'help' => 'EnableBankingRuleLineLabelHelp'),
		'auto_validate' => array('type' => 'integer', 'label' => 'EnableBankingRuleAutoValidate', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'default' => '0', 'help' => 'EnableBankingRuleAutoValidateHelp'),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 501, 'notnull' => 0, 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 510, 'notnull' => 0, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 511, 'notnull' => -1, 'visible' => -2),
	);

	/** @var int */
	public $rowid;
	/** @var string */
	public $label;
	/** @var int */
	public $active = 1;
	/** @var int|null */
	public $fk_bank_account;
	/** @var string|null */
	public $direction;
	/** @var string|null */
	public $counterparty;
	/** @var string|null */
	public $iban;
	/** @var string|null */
	public $remittance;
	/** @var float|string|null */
	public $amount_min;
	/** @var float|string|null */
	public $amount_max;
	/** @var string */
	public $action;
	/** @var int|null */
	public $fk_soc;
	/** @var int|null */
	public $fk_bank_categ;
	/** @var string|null */
	public $payment_code;
	/** @var string|null */
	public $tax_type;
	/** @var string|null */
	public $line_label;
	/** @var int */
	public $auto_validate = 0;
	/** @var int */
	public $fk_user_creat;
	/** @var int */
	public $fk_user_modif;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create object into database
	 *
	 * @param	User	$user		User that creates
	 * @param	int		$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int					Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 1)
	{
		return $this->check() ? $this->createCommon($user, $notrigger) : -1;
	}

	/**
	 * Load object in memory from the database (only in the current entities)
	 *
	 * @param	int		$id		Id object
	 * @return	int				Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		return $this->fetchCommon($id, null, ' AND t.entity IN ('.getEntity($this->element).')', 1);
	}

	/**
	 * Update object into database
	 *
	 * @param	User	$user		User that modifies
	 * @param	int		$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int					Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 1)
	{
		return $this->check() ? $this->updateCommon($user, $notrigger) : -1;
	}

	/**
	 * Delete object in database
	 *
	 * @param	User	$user		User that deletes
	 * @param	int		$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int					Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Load the rules of the current entities, active ones only by default
	 *
	 * @param	bool	$activeOnly		False to load disabled rules too
	 * @return	EbRule[]|false			Rules indexed by id
	 */
	public function fetchAllRules($activeOnly = true)
	{
		$sql = "SELECT ".$this->getFieldList('t')." FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.entity IN (".getEntity($this->element).")";
		if ($activeOnly) {
			$sql .= " AND t.active = 1";
		}
		$sql .= $this->db->order('t.label', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$rules = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rule = new EbRule($this->db);
			$rule->setVarsFromFetchObj($obj);
			$rule->id = (int) $obj->rowid;
			$rules[$rule->id] = $rule;
		}
		$this->db->free($resql);
		return $rules;
	}

	/**
	 * Check the rule can be saved: one condition on the counterparty or the label at least, and what the action needs
	 *
	 * @return	bool	True if OK, false with error set if KO
	 */
	public function check()
	{
		global $langs;
		$langs->load("enablebanking@enablebanking");

		$this->errors = array();
		if (trim((string) $this->label) === '') {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Label'));
		}
		if (trim($this->counterparty.$this->iban.$this->remittance) === '') {
			$this->errors[] = $langs->trans('EnableBankingErrorRuleNoCondition');
		}
		if (!in_array($this->action, array(self::ACTION_THIRDPARTY, self::ACTION_BANKLINE, self::ACTION_TAX), true)) {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('EnableBankingRuleAction'));
		} elseif ($this->action == self::ACTION_THIRDPARTY && empty($this->fk_soc)) {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('ThirdParty'));
		} elseif ($this->action == self::ACTION_TAX && (string) $this->tax_type === '') {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Type'));
		}
		if ($this->action == self::ACTION_TAX) {
			// Taxes are only paid by debits
			$this->direction = 'out';
		}
		$this->iban = enablebankingNormalizeIban($this->iban);
		$this->error = implode(', ', $this->errors);
		return empty($this->errors);
	}

	/**
	 * Return true if a transaction meets all conditions of the rule
	 *
	 * @param	EbTransaction	$tx		Transaction
	 * @return	bool
	 */
	public function matches(EbTransaction $tx)
	{
		$amount = abs((float) $tx->amount);
		if (empty($this->active)
			|| ($this->fk_bank_account > 0 && (int) $this->fk_bank_account !== (int) $tx->fk_bank_account)
			|| ($this->direction == 'in' && $tx->amount <= 0)
			|| ($this->direction == 'out' && $tx->amount >= 0)
			|| ((string) $this->amount_min !== '' && $amount < (float) $this->amount_min - 0.005)
			|| ((string) $this->amount_max !== '' && $amount > (float) $this->amount_max + 0.005)
			|| ((string) $this->iban !== '' && enablebankingNormalizeIban($this->iban) !== enablebankingNormalizeIban($tx->counterparty_iban))) {
			return false;
		}
		$name = enablebankingNormalizeName($this->counterparty);
		if ($name !== '' && strpos(enablebankingNormalizeName($tx->counterparty_name), $name) === false) {
			return false;
		}
		return !array_diff(enablebankingWords($this->remittance), enablebankingWords($tx->remittance));
	}

	/**
	 * Init a rule recognising the transactions similar to one: same direction and same counterparty IBAN,
	 * or same counterparty name, or the first significant words of its label
	 *
	 * @param	EbTransaction	$tx			Transaction matched by hand
	 * @param	string			$action		ACTION_THIRDPARTY, ACTION_BANKLINE or ACTION_TAX
	 * @return	void
	 */
	public function initFromTransaction(EbTransaction $tx, $action)
	{
		$this->action = $action;
		$this->active = 1;
		$this->auto_validate = 0;
		$this->direction = ($tx->amount > 0) ? 'in' : 'out';
		if ((string) $tx->counterparty_iban !== '') {
			$this->iban = $tx->counterparty_iban;
		} elseif (enablebankingNormalizeName($tx->counterparty_name) !== '') {
			$this->counterparty = $tx->counterparty_name;
		} else {
			// Words with digits are dates or references that change on each transaction
			$words = array_filter(EnableBankingMatcher::significantWords((string) $tx->remittance, 0), function ($word) {
				return !preg_match('/\d/', $word);
			});
			$this->remittance = implode(' ', array_slice($words, 0, 2));
		}
		$this->label = dol_trunc(trim($tx->counterparty_name !== '' ? $tx->counterparty_name : $tx->remittance), 100, 'right', 'UTF-8', 1);
	}

	/**
	 * Return the link to edit the rule
	 *
	 * @param	int		$withpicto	Add picto
	 * @return	string				HTML link
	 */
	public function getNomUrl($withpicto = 0)
	{
		$url = EnableBankingMatcher::getElementUrl(EnableBankingMatcher::TYPE_RULE, $this->id);
		return '<a href="'.$url.'">'.($withpicto ? img_picto('', $this->picto, 'class="pictofixedwidth"') : '').dolPrintHTML($this->label).'</a>';
	}
}
