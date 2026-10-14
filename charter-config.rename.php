<?php
/**
 * Charter configuration.
 *
 * Keep this file in the same directory as charter.php.
 * Do not publish the real database password or administrator password.
 */

// Database configuration
$dbHost = 'localhost'; 
$dbName = 'DATABASE_NAME';
$dbUser = 'DATABASE_USER_NAME';
$dbPassword = 'DATABASE_USER_PASSWORD'; 
$adminPassword = 'APPLICATION_USER_PASSWORD'; 
$urlRoot = 'APPLICATION_URL_ROOT'; 

/*
 * Optional absolute directory URL when Charter sits behind a proxy or the
 * automatically detected URL is not correct. Leave blank in most installs.
 * Example: https://example.com/tools
 */
$urlRoot = '';


?>