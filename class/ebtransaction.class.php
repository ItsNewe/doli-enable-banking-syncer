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
 * \file    htdocs/custom/enablebanking/class/ebtransaction.class.php
 * \ingroup enablebanking
 * \brief   Bank transaction imported from Enable Banking
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');


/**
 * Class EbTransaction
 */
class EbTransaction extends CommonObject
{
	/**
	 * @var string Module of object
	 */
	public $module = 'enablebanking';

	/**
	 * @var string Id to identify managed objects
	 */
	public $element = 'enablebanking_transaction';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'enablebanking_transaction';

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

	const STATUS_NEW = 0;
	const STATUS_SUGGESTED = 1;
	const STATUS_MATCHED = 2;
	const STATUS_IGNORED = 3;

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'default' => '1'),
		'fk_eb_account' => array('type' => 'integer', 'label' => 'EnableBankingAccount', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0),
		'fk_bank_account' => array('type' => 'integer:Account:compta/bank/class/account.class.php', 'label' => 'BankAccount', 'enabled' => 1, 'position' => 11, 'notnull' => 1, 'visible' => 1),
		'entry_reference' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1),
		'booking_date' => array('type' => 'date', 'label' => 'DateOperation', 'enabled' => 1, 'position' => 30, 'visible' => 1),
		'value_date' => array('type' => 'date', 'label' => 'DateValue', 'enabled' => 1, 'position' => 31, 'visible' => 1),
		'amount' => array('type' => 'double(24,8)', 'label' => 'Amount', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1),
		'currency' => array('type' => 'varchar(3)', 'label' => 'Currency', 'enabled' => 1, 'position' => 41, 'visible' => 1),
		'counterparty_name' => array('type' => 'varchar(255)', 'label' => 'EnableBankingCounterparty', 'enabled' => 1, 'position' => 50, 'visible' => 1),
		'counterparty_iban' => array('type' => 'varchar(64)', 'label' => 'IBAN', 'enabled' => 1, 'position' => 51, 'visible' => 1),
		'remittance' => array('type' => 'text', 'label' => 'Label', 'enabled' => 1, 'position' => 52, 'visible' => 1),
		'bank_tx_code' => array('type' => 'varchar(64)', 'label' => 'Code', 'enabled' => 1, 'position' => 53, 'visible' => -1),
		'raw_json' => array('type' => 'text', 'label' => 'RawData', 'enabled' => 1, 'position' => 60, 'visible' => 0),
		'fk_soc' => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'ThirdParty', 'enabled' => 1, 'position' => 70, 'notnull' => -1, 'visible' => 1),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 80, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'fk_bank' => array('type' => 'integer', 'label' => 'BankLine', 'enabled' => 1, 'position' => 90, 'notnull' => -1, 'visible' => 1),
		'date_valid' => array('type' => 'datetime', 'label' => 'DateValidation', 'enabled' => 1, 'position' => 100, 'visible' => -2),
		'fk_user_valid' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserValidation', 'enabled' => 1, 'position' => 101, 'notnull' => -1, 'visible' => -2),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 501, 'notnull' => 0, 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 510, 'notnull' => 0, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 511, 'notnull' => -1, 'visible' => -2),
	);

	/** @var int */
	public $rowid;
	/** @var int */
	public $fk_eb_account;
	/** @var int */
	public $fk_bank_account;
	/** @var string */
	public $entry_reference;
	/** @var int|string|null */
	public $booking_date;
	/** @var int|string|null */
	public $value_date;
	/** @var float */
	public $amount;
	/** @var string */
	public $currency;
	/** @var string */
	public $counterparty_name;
	/** @var string */
	public $counterparty_iban;
	/** @var string */
	public $remittance;
	/** @var string */
	public $bank_tx_code;
	/** @var string */
	public $raw_json;
	/** @var int|null */
	public $fk_soc;
	/** @var int|null */
	public $fk_bank;
	/** @var int|string|null */
	public $date_valid;
	/** @var int|null */
	public $fk_user_valid;
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
		return $this->createCommon($user, $notrigger);
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
	 * Load several transactions of the current entities in one query
	 *
	 * @param	int[]	$ids		Ids of transactions
	 * @param	int[]	$statuses	Keep only these statuses (empty = all)
	 * @return	EbTransaction[]|false
	 */
	public function fetchByIds($ids, $statuses = array())
	{
		$ids = array_filter(array_map('intval', $ids));
		if (empty($ids)) {
			return array();
		}
		$sql = "SELECT ".$this->getFieldList('t')." FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND t.entity IN (".getEntity($this->element).")";
		if (!empty($statuses)) {
			$sql .= " AND t.status IN (".$this->db->sanitize(implode(',', array_map('intval', $statuses))).")";
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$transactions = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tx = new EbTransaction($this->db);
			$tx->setVarsFromFetchObj($obj);
			$transactions[] = $tx;
		}
		$this->db->free($resql);
		return $transactions;
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
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Return the candidates of this transaction, best score first
	 *
	 * @return	array<int,array{id:int,element_type:string,element_ids:int[],fk_soc:int,score:int,label:string}>|false
	 */
	public function getMatches()
	{
		$matches = array();
		$sql = "SELECT rowid, element_type, element_ids, fk_soc, score, label";
		$sql .= " FROM ".$this->db->prefix()."enablebanking_match";
		$sql .= " WHERE fk_transaction = ".((int) $this->id);
		$sql .= " ORDER BY score DESC, rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$matches[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'element_type' => (string) $obj->element_type,
				'element_ids' => array_map('intval', explode(',', (string) $obj->element_ids)),
				'fk_soc' => (int) $obj->fk_soc,
				'score' => (int) $obj->score,
				'label' => (string) $obj->label,
			);
		}
		$this->db->free($resql);
		return $matches;
	}

	/**
	 * Remove all candidates of this transaction
	 *
	 * @return	int		Return integer <0 if KO, >0 if OK
	 */
	public function deleteMatches()
	{
		$sql = "DELETE FROM ".$this->db->prefix()."enablebanking_match WHERE fk_transaction = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Add a candidate to this transaction
	 *
	 * @param	string	$elementType	'facture', 'invoice_supplier' or 'bankline'
	 * @param	int[]	$elementIds		Ids of invoices or of the bank line
	 * @param	int		$fkSoc			Third party
	 * @param	int		$score			Score
	 * @param	string	$label			Short description
	 * @return	int						Return integer <0 if KO, >0 if OK
	 */
	public function addMatch($elementType, $elementIds, $fkSoc, $score, $label)
	{
		$sql = "INSERT INTO ".$this->db->prefix()."enablebanking_match (fk_transaction, element_type, element_ids, fk_soc, score, label)";
		$sql .= " VALUES (".((int) $this->id).", '".$this->db->escape($elementType)."'";
		$sql .= ", '".$this->db->escape(implode(',', array_map('intval', $elementIds)))."'";
		$sql .= ", ".($fkSoc > 0 ? ((int) $fkSoc) : "NULL").", ".((int) $score);
		$sql .= ", '".$this->db->escape(dol_trunc($label, 250, 'right', 'UTF-8', 1))."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Return label of status
	 *
	 * @param	int		$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 * @return	string			Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut((int) $this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Return the label of a given status
	 *
	 * @param	int		$status	Id status
	 * @param	int		$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 * @return	string			Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		return enablebankingStatusLabel($status, $mode, array(
			self::STATUS_NEW => array('EnableBankingTxStatusNew', 'status0'),
			self::STATUS_SUGGESTED => array('EnableBankingTxStatusSuggested', 'status1'),
			self::STATUS_MATCHED => array('EnableBankingTxStatusMatched', 'status4'),
			self::STATUS_IGNORED => array('EnableBankingTxStatusIgnored', 'status9'),
		));
	}
}
