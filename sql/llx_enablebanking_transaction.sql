-- Copyright (C) 2026	Newe	<lobo@arcafin.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.


-- Bank transaction imported from Enable Banking, waiting to be matched or already matched
CREATE TABLE llx_enablebanking_transaction(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_eb_account INTEGER NOT NULL,
	fk_bank_account INTEGER NOT NULL,
	entry_reference VARCHAR(128) NOT NULL,
	booking_date DATE,
	value_date DATE,
	amount DOUBLE(24,8) NOT NULL,				-- signed: positive for credit (CRDT), negative for debit (DBIT)
	currency VARCHAR(3),
	counterparty_name VARCHAR(255),
	counterparty_iban VARCHAR(64),
	remittance TEXT,
	bank_tx_code VARCHAR(64),
	raw_json TEXT,
	fk_soc INTEGER,								-- identified third party
	status INTEGER DEFAULT 0 NOT NULL,			-- 0=new, 1=suggested, 2=matched, 3=ignored
	fk_bank INTEGER,							-- linked line of llx_bank
	date_valid DATETIME,
	fk_user_valid INTEGER,
	date_creation DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER,
	fk_user_modif INTEGER
) ENGINE=innodb;
