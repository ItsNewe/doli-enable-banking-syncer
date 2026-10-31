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
 * \file    htdocs/custom/enablebanking/class/ebaccount.class.php
 * \ingroup enablebanking
 * \brief   Link between a Dolibarr bank account and an Enable Banking account
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');


/**
 * Class EbAccount
 */
class EbAccount extends CommonObject
{
	/**
	 * @var string Module of object
	 */
	public $module = 'enablebanking';

	/**
	 * @var string Id to identify managed objects
	 */
	public $element = 'enablebanking_account';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'enablebanking_account';

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

	const STATUS_PENDING = 0;
	const STATUS_ACTIVE = 1;
	const STATUS_EXPIRED = 2;
	const STATUS_DISCONNECTED = 9;

	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-6,6>|string,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'default' => '1'),
		'fk_bank_account' => array('type' => 'integer:Account:compta/bank/class/account.class.php', 'label' => 'BankAccount', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1),
		'aspsp_name' => array('type' => 'varchar(255)', 'label' => 'EnableBankingBank', 'enabled' => 1, 'position' => 20, 'visible' => 1),
		'aspsp_country' => array('type' => 'varchar(2)', 'label' => 'Country', 'enabled' => 1, 'position' => 21, 'visible' => 1),
		'psu_type' => array('type' => 'varchar(16)', 'label' => 'EnableBankingPsuType', 'enabled' => 1, 'position' => 22, 'visible' => 1),
		'auth_state' => array('type' => 'varchar(64)', 'label' => 'State', 'enabled' => 1, 'position' => 30, 'visible' => 0),
		'session_id' => array('type' => 'varchar(128)', 'label' => 'EnableBankingSessionId', 'enabled' => 1, 'position' => 31, 'visible' => 0),
		'account_uid' => array('type' => 'varchar(128)', 'label' => 'EnableBankingAccountUid', 'enabled' => 1, 'position' => 32, 'visible' => 0),
		'iban' => array('type' => 'varchar(64)', 'label' => 'IBAN', 'enabled' => 1, 'position' => 40, 'visible' => 1),
		'account_name' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'position' => 41, 'visible' => 1),
		'currency' => array('type' => 'varchar(3)', 'label' => 'Currency', 'enabled' => 1, 'position' => 42, 'visible' => 1),
		'valid_until' => array('type' => 'datetime', 'label' => 'EnableBankingConsentValidUntil', 'enabled' => 1, 'position' => 50, 'visible' => 1),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 60, 'notnull' => 1, 'visible' => 1, 'default' => '0'),
		'date_last_sync' => array('type' => 'datetime', 'label' => 'EnableBankingLastSync', 'enabled' => 1, 'position' => 70, 'visible' => 1),
		'last_error' => array('type' => 'text', 'label' => 'Error', 'enabled' => 1, 'position' => 71, 'visible' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 501, 'notnull' => 0, 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 510, 'notnull' => 0, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 511, 'notnull' => -1, 'visible' => -2),
	);

	/** @var int */
	public $rowid;
	/** @var int */
	public $fk_bank_account;
	/** @var string */
	public $aspsp_name;
	/** @var string */
	public $aspsp_country;
	/** @var string */
	public $psu_type;
	/** @var string */
	public $auth_state;
	/** @var string */
	public $session_id;
	/** @var string */
	public $account_uid;
	/** @var string */
	public $iban;
	/** @var string */
	public $account_name;
	/** @var string */
	public $currency;
	/** @var int|string|null */
	public $valid_until;
	/** @var int|string|null */
	public $date_last_sync;
	/** @var string */
	public $last_error;
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
	 * Load the link of a Dolibarr bank account
	 *
	 * @param	int		$fkBankAccount	Id of Dolibarr bank account
	 * @return	int						Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchByBankAccount($fkBankAccount)
	{
		return $this->fetchCommon(0, null, ' AND t.fk_bank_account = '.((int) $fkBankAccount), 1);
	}

	/**
	 * Load the link waiting for the authorisation identified by a state
	 *
	 * @param	string	$state	State sent to /auth
	 * @return	int				Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchByState($state)
	{
		if ($state === '') {
			return 0;
		}
		return $this->fetchCommon(0, null, " AND t.auth_state = '".$this->db->escape($state)."'", 1);
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
	 * Return ids of links that can be synchronised (active and consent not expired)
	 *
	 * @return	int[]|false
	 */
	public function fetchAllActiveIds()
	{
		$ids = array();
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE status = ".self::STATUS_ACTIVE;
		$sql .= " AND entity IN (".getEntity($this->element).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		return $ids;
	}

	/**
	 * Return true if the consent is active and not expired
	 *
	 * @return	bool
	 */
	public function isConsentValid()
	{
		if ((int) $this->status !== self::STATUS_ACTIVE || empty($this->session_id) || empty($this->account_uid)) {
			return false;
		}
		return empty($this->valid_until) || ($this->valid_until > dol_now());
	}

	/**
	 * Return true if an account of the bank is already linked to another Dolibarr bank account
	 *
	 * @param	string	$accountUid		Account uid at Enable Banking
	 * @return	bool
	 */
	public function isRemoteAccountLinkedElsewhere($accountUid)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE account_uid = '".$this->db->escape($accountUid)."' AND rowid <> ".((int) $this->id);
		$sql .= " AND status = ".self::STATUS_ACTIVE;
		$resql = $this->db->query($sql);
		return (!$resql || $this->db->num_rows($resql) > 0);
	}

	/**
	 * Activate the link with an account of a session created after the authorisation
	 *
	 * @param	array<string,mixed>		$session	Session returned by POST /sessions
	 * @param	array<string,mixed>		$account	Chosen account of the session
	 * @param	User					$user		User
	 * @return	int								Return integer <0 if KO, >0 if OK
	 */
	public function linkRemoteAccount($session, $account, User $user)
	{
		global $langs;

		if ($this->isRemoteAccountLinkedElsewhere((string) $account['uid'])) {
			$langs->load("enablebanking@enablebanking");
			$this->error = $langs->trans('EnableBankingErrorAccountAlreadyLinked');
			return -1;
		}
		$validuntil = !empty($session['access']['valid_until']) ? strtotime((string) $session['access']['valid_until']) : false;

		$this->session_id = (string) $session['session_id'];
		$this->account_uid = (string) $account['uid'];
		$this->iban = !empty($account['account_id']['iban']) ? (string) $account['account_id']['iban'] : '';
		$this->account_name = !empty($account['name']) ? (string) $account['name'] : '';
		$this->currency = !empty($account['currency']) ? (string) $account['currency'] : '';
		$this->valid_until = ($validuntil !== false ? $validuntil : '');
		$this->status = self::STATUS_ACTIVE;
		$this->auth_state = '';
		$this->last_error = '';
		return $this->update($user);
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
			self::STATUS_PENDING => array('EnableBankingStatusPending', 'status0'),
			self::STATUS_ACTIVE => array('EnableBankingStatusActive', 'status4'),
			self::STATUS_EXPIRED => array('EnableBankingStatusExpired', 'status8'),
			self::STATUS_DISCONNECTED => array('EnableBankingStatusDisconnected', 'status9'),
		));
	}
}
