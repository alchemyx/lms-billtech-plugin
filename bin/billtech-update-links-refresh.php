#!/usr/bin/env php
<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2020 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

ini_set('error_reporting', E_ALL & ~E_NOTICE);

$parameters = array(
	'config-file:' => 'C:',
	'quiet' => 'q',
	'help' => 'h',
	'version' => 'v'
);

$long_to_shorts = array();
foreach ($parameters as $long => $short) {
	$long = str_replace(':', '', $long);
	if (isset($short)) {
		$short = str_replace(':', '', $short);
	}
	$long_to_shorts[$long] = $short;
}

$options = getopt(
	implode(
		'',
		array_filter(
			array_values($parameters),
			function ($value) {
				return isset($value);
			}
		)
	),
	array_keys($parameters)
);

foreach (array_flip(array_filter($long_to_shorts, function ($value) {
	return isset($value);
})) as $short => $long) {
	if (array_key_exists($short, $options)) {
		$options[$long] = $options[$short];
		unset($options[$short]);
	}
}

if (array_key_exists('version', $options)) {
	print <<<EOF
billtech-update.php

EOF;
	exit(0);
}

if (array_key_exists('help', $options)) {
	print <<<EOF
billtech-update.php

-C, --config-file=/etc/lms/lms.ini      alternate config file (default: /etc/lms/lms.ini);
-h, --help                      print this help and exit;
-v, --version                   print version info and exit;
-q, --quiet                     suppress any output, except errors;

EOF;
	exit(0);
}

$quiet = array_key_exists('quiet', $options);
if (!$quiet) {
	print <<<EOF
billtech-update.php

EOF;
}

if (array_key_exists('config-file', $options)) {
	$CONFIG_FILE = $options['config-file'];
} else {
	$CONFIG_FILE = '/etc/lms/lms.ini';
}

if (!$quiet) {
	echo "Using file " . $CONFIG_FILE . " as config." . PHP_EOL;
}

if (!is_readable($CONFIG_FILE)) {
	die("Unable to read configuration file [" . $CONFIG_FILE . "]!" . PHP_EOL);
}

define('CONFIG_FILE', $CONFIG_FILE);

$CONFIG = (array)parse_ini_file($CONFIG_FILE, true);

// Check for configuration vars and set default values
$CONFIG['directories']['sys_dir'] = (!isset($CONFIG['directories']['sys_dir']) ? getcwd() : $CONFIG['directories']['sys_dir']);
$CONFIG['directories']['lib_dir'] = (!isset($CONFIG['directories']['lib_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'lib' : $CONFIG['directories']['lib_dir']);
$CONFIG['directories']['modules_dir'] = (!isset($CONFIG['directories']['modules_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'modules' : $CONFIG['directories']['modules_dir']);
$CONFIG['directories']['plugin_dir'] = (!isset($CONFIG['directories']['plugin_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'plugins' : $CONFIG['directories']['plugin_dir']);
$CONFIG['directories']['plugins_dir'] = $CONFIG['directories']['plugin_dir'];

define('SYS_DIR', $CONFIG['directories']['sys_dir']);
define('LIB_DIR', $CONFIG['directories']['lib_dir']);
define('MODULES_DIR', $CONFIG['directories']['modules_dir']);
define('PLUGIN_DIR', $CONFIG['directories']['plugin_dir']);
define('PLUGINS_DIR', $CONFIG['directories']['plugin_dir']);



// Load autoloader
$composer_autoload_path = SYS_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($composer_autoload_path)) {
	require_once $composer_autoload_path;
} else {
	die("Composer autoload not found. Run 'composer install' command from LMS directory and try again. More informations at https://getcomposer.org/" . PHP_EOL);
}

// Init database

$DB = null;
// Init database

$_DBTYPE = $CONFIG['database']['type'];
$_DBHOST = $CONFIG['database']['host'];
$_DBUSER = $CONFIG['database']['user'];
$_DBPASS = $CONFIG['database']['password'];
$_DBNAME = $CONFIG['database']['database'];

require(LIB_DIR.'/LMSDB.php');

$DB = DBInit($_DBTYPE, $_DBHOST, $_DBUSER, $_DBPASS, $_DBNAME);

if(!$DB)
{
    // can't working without database
    die();
}

// Include required files (including sequence is important)

require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'common.php');
require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'language.php');
include_once(LIB_DIR . DIRECTORY_SEPARATOR . 'definitions.php');

// Read configuration of LMS-UI from database

if($cfg = $DB->GetAll('SELECT section, var, value FROM uiconfig WHERE disabled=0'))
    foreach($cfg as $row)
        $CONFIG[$row['section']][$row['var']] = $row['value'];

// Initialize Session, Auth and LMS classes

$AUTH = null;

$LMS = new LMS($DB, $AUTH, $CONFIG);

$linksManager = new BillTechLinksManager(!$quiet);

BillTech::measureTime(function () use ($linksManager) {
	global $DB;
	$DB->Execute("update billtech_customer_info set balance_update_time = 0;");

	$linksManager->updateForAll();
}, !$quiet);
