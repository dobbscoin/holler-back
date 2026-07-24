<?php
require_once __DIR__ . '/inc.php';
Auth::logout();
redirect( 'login.php' );
