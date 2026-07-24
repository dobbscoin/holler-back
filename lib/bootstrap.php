<?php
/** Common bootstrap for public pages, admin, worker, and ws-server. */

if ( function_exists( 'mb_internal_encoding' ) ) { mb_internal_encoding( 'UTF-8' ); }

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Workspace.php';

Config::load();

$GLOBALS['store'] = new Store( (string) Config::get( 'db_path', dirname( __DIR__ ) . '/data/holler.sqlite' ) );

function store() { return $GLOBALS['store']; }
