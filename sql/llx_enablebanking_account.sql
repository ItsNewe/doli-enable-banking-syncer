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


-- Link between a Dolibarr bank account and an account authorised through Enable Banking
CREATE TABLE llx_enablebanking_account(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_bank_account INTEGER NOT NULL,
	aspsp_name VARCHAR(255),
	aspsp_country VARCHAR(2),
	psu_type VARCHAR(16) DEFAULT 'business',
	auth_state VARCHAR(64),
	session_id VARCHAR(128),
	account_uid VARCHAR(128),
	iban VARCHAR(64),
	account_name VARCHAR(255),
	currency VARCHAR(3),
	valid_until DATETIME,
	status INTEGER DEFAULT 0 NOT NULL,			-- 0=authorisation pending, 1=active, 2=expired, 9=disconnected
	date_last_sync DATETIME,
	last_error TEXT,
	date_creation DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER,
	fk_user_modif INTEGER
) ENGINE=innodb;
