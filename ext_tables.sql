#
# OAuth dynamically registered public clients (RFC 7591)
#
CREATE TABLE tx_mcpserver_oauth_clients (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

	client_id varchar(128) DEFAULT '' NOT NULL,
	client_name varchar(255) DEFAULT '' NOT NULL,
	redirect_uris text,
	grant_types text,
	response_types text,
	scope text,
	token_endpoint_auth_method varchar(64) DEFAULT 'none' NOT NULL,
	client_id_issued_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY parent (pid),
	UNIQUE KEY client_id (client_id)
);

#
# Used refresh-token hashes for token-family replay detection
#
CREATE TABLE tx_mcpserver_oauth_refresh_replay (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

	token_hash varchar(255) DEFAULT '' NOT NULL,
	family_id varchar(64) DEFAULT '' NOT NULL,
	expires int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY parent (pid),
	UNIQUE KEY token_hash (token_hash),
	KEY family_id (family_id),
	KEY expires (expires)
);

#
# OAuth authorization codes table (temporary codes for OAuth flow)
#
CREATE TABLE tx_mcpserver_oauth_codes (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	code varchar(255) DEFAULT '' NOT NULL,
	be_user_uid int(11) unsigned DEFAULT '0' NOT NULL,
	client_id varchar(128) DEFAULT '' NOT NULL,
	client_name varchar(255) DEFAULT '' NOT NULL,
	pkce_challenge varchar(255) DEFAULT '' NOT NULL,
	pkce_challenge_method varchar(10) DEFAULT 'S256' NOT NULL,
	redirect_uri text,
	resource text,
	scope text,
	expires int(11) unsigned DEFAULT '0' NOT NULL,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY code (code),
	KEY be_user_uid (be_user_uid),
	KEY expires (expires)
);

#
# OAuth access tokens table (long-lived tokens for MCP clients)
#
CREATE TABLE tx_mcpserver_access_tokens (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	token varchar(255) DEFAULT '' NOT NULL,
	refresh_token varchar(255) DEFAULT '' NOT NULL,
	refresh_family_id varchar(64) DEFAULT '' NOT NULL,
	refresh_family_expires int(11) unsigned DEFAULT '0' NOT NULL,
	be_user_uid int(11) unsigned DEFAULT '0' NOT NULL,
	client_id varchar(128) DEFAULT '' NOT NULL,
	client_name varchar(255) DEFAULT '' NOT NULL,
	resource text,
	scope text,
	expires int(11) unsigned DEFAULT '0' NOT NULL,
	refresh_expires int(11) unsigned DEFAULT '0' NOT NULL,
	last_used int(11) unsigned DEFAULT '0' NOT NULL,
	created_ip varchar(45) DEFAULT '' NOT NULL,
	last_used_ip varchar(45) DEFAULT '' NOT NULL,
	token_version tinyint(1) DEFAULT '0' NOT NULL,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY token (token),
	KEY refresh_token (refresh_token),
	KEY be_user_uid (be_user_uid),
	KEY expires (expires),
	KEY refresh_expires (refresh_expires)
);
