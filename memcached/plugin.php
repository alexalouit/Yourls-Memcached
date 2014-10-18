<?php
/*
Plugin Name: Memcached
Plugin URI: https://github.com/alexalouit
Description: Memcached support (Inspired by Ian Barber <ian.barber@gmail.com> "APC Cache plugin")
Version: 0.2
Author: Alexandre Alouit <alexandre.alouit@gmail.com>
Author URI: http://www.alouit-multimedia.com/
*/

if(!class_exists('Memcached')) {
	yourls_die( 'This plugin requires the Memcached extension: http://pecl.php.net/package/memcached' );
}

$memcached = new Memcached();

if(!defined('MEMCACHED_IP')) { define("MEMCACHED_IP", '127.0.0.1'); }
if(!defined('MEMCACHED_PORT')) { define("MEMCACHED_PORT", '11211'); }
if(!defined('MEMCACHED_SALT')) { define("MEMCACHED_SALT", $_SERVER["HTTP_HOST"]); }

if(!$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT)) {
	yourls_die( 'Unable to connect to Memcached server ('. MEMCACHED_IP . ':' . MEMCACHED_PORT .' )' );
}

if(!defined('MEMCACHED_WRITE_CACHE_TIMEOUT')) { define('MEMCACHED_WRITE_CACHE_TIMEOUT', 120); }
if(!defined('MEMCACHED_READ_CACHE_TIMEOUT')) { define('MEMCACHED_READ_CACHE_TIMEOUT', 360); }
define('MEMCACHED_CACHE_LOG_INDEX', 'cachelogindex');
define('MEMCACHED_CACHE_LOG_TIMER', 'cachelogtimer');
define('MEMCACHED_CACHE_ALL_OPTIONS', 'cache-get_all_options');

if(!defined('MEMCACHED_CACHE_LONG_TIMEOUT')) { define('MEMCACHED_CACHE_LONG_TIMEOUT', 86400); }

yourls_add_action( 'pre_get_keyword', 'memcached_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'memcached_get_keyword_infos' );

if(!defined('MEMCACHED_CACHE_SKIP_CLICKTRACK')) {
	yourls_add_filter( 'shunt_update_clicks', 'memcached_cache_shunt_update_clicks' );
	yourls_add_filter( 'shunt_log_redirect', 'memcached_cache_shunt_log_redirect' );
}

yourls_add_filter( 'shunt_all_options', 'memcached_cache_shunt_all_options' );
yourls_add_filter( 'get_all_options', 'memcached_cache_get_all_options' );
yourls_add_filter( 'activated_plugin', 'memcached_cache_plugin_statechange' );
yourls_add_filter( 'deactivated_plugin', 'memcached_cache_plugin_statechange' );
yourls_add_filter( 'edit_link', 'memcached_cache_edit_link' );

/**
 * Return cached options is available
 *
 * @param bool $false 
 * @return bool true
 */
function memcached_cache_shunt_all_options($false) {
	global $ydb; 
	
	$key = MEMCACHED_SALT . "." . MEMCACHED_CACHE_ALL_OPTIONS;
	
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT); 
	if($memcached->get($key)) {
		$ydb->option = $memcached->get($key);
		return true;
	} 
	
	return false;
}

/**
 * Cache all_options data. 
 *
 * @param array $options 
 * @return array options
 */
function memcached_cache_get_all_options($option) {
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	$memcached->add(MEMCACHED_CACHE_ALL_OPTIONS, $option, MEMCACHED_READ_CACHE_TIMEOUT);
	return $option;
}

/**
 * Clear the options cache if a plugin is activated or deactivated
 *
 * @param string $plugin 
 */
function memcached_cache_plugin_statechange($plugin) {
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	$memcached->delete(MEMCACHED_SALT . "." . MEMCACHED_CACHE_ALL_OPTIONS);
}

/**
 * If the URL data is in the cache, stick it back into the global DB object. 
 * 
 * @param string $args
 */
function memcached_pre_get_keyword($args) {
	global $ydb;
	$keyword = MEMCACHED_SALT . "." . $args[0];
	$use_cache = isset($args[1]) ? $args[1] : true;
	
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	// Lookup in cache
	if($use_cache && $memcached->get($keyword)) {
		$ydb->infos[$keyword] = $memcached->get($keyword);
	}
}

/**
 * Store the keyword info in the cache
 * 
 * @param array $info
 * @param string $keyword
 */
function memcached_get_keyword_infos($info, $keyword) {
	// Store in cache
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	$memcached->add(MEMCACHED_SALT . "." . $keyword, $info, MEMCACHED_READ_CACHE_TIMEOUT);
	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 * 
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function memcached_cache_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if($return['status'] != 'fail') {
		$memcached = new Memcached();
		$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
		$keyword = MEMCACHED_SALT . "." . $keyword;
		$memcached->delete($keyword);
	}
	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks. 
 * 
 * @param string $keyword
 */
function memcached_cache_shunt_update_clicks($false, $keyword) {
	global $ydb;
	
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	if(defined('MEMCACHED_CACHE_STATS_SHUNT')) {
		if(MEMCACHED_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(MEMCACHED_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	} 
	
	$keyword = yourls_sanitize_string( $keyword );
	$timer = $keyword . "-=-timer";
	$key = MEMCACHED_SALT . "." . $keyword . "-=-clicks";
	
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	if($memcached->set($timer, time(), MEMCACHED_WRITE_CACHE_TIMEOUT)) {
		// Can add, so write right away
		$value = 1;
		if($memcached->get($key)) {
			$value += memcached_cache_key_zero($key);
		}
		// Write value to DB
		$ydb->query("UPDATE `" . 
						YOURLS_DB_TABLE_URL. 
					"` SET `clicks` = clicks + " . $value . 
					" WHERE `keyword` = '" . $keyword . "'");
		
	} else {
		// Store in cache
		$added = false; 
		if(!$memcached->get($key)) {
			$added = $memcached->set($key, 1);
		}
		
		if(!$added) {
			memcached_cache_key_increment($key);
		}
	}
	
	return true;
}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries. 
 * This is a good trade off for us, but may not be for everyone. 
 *
 * @param string $keyword
 */
function memcached_cache_shunt_log_redirect($false, $keyword) {
	global $ydb;
	
	if(defined('MEMCACHED_CACHE_STATS_SHUNT')) {
		if(MEMCACHED_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(MEMCACHED_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	}
	
	$args = array(
		yourls_sanitize_string( $keyword ),
		( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' ),
		yourls_get_user_agent(),
		yourls_get_IP(),
		yourls_geo_ip_to_countrycode( $ip )
	);
	
	// Separated out the calls to make a bit more readable here
	$key = MEMCACHED_SALT . "." . MEMCACHED_CACHE_LOG_INDEX;
	$logindex = 0;
	$added = false;
	
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	if(!$memcached->get($key)) {
		$added = $memcached->set($key, 0);
	} 
	
	if(!$added) {
		$logindex = memcached_cache_key_increment($key);
	}
	
	// We now have a reserved logindex, so lets cache
	$memcached->add(memcached_get_logindex($logindex), $args, MEMCACHED_CACHE_LONG_TIMEOUT);
	
	// If we've been caching for over a certain amount do write
	if($memcached->set(MEMCACHED_CACHE_LOG_TIMER, time(), MEMCACHED_WRITE_CACHE_TIMEOUT)) {
		// We can add, so lets flush the log cache
		$key = MEMCACHED_SALT . "." . MEMCACHED_CACHE_LOG_INDEX;
		$index = $memcached->get($key);
		$fetched = -1;
		$loop = true;
		$values = array();
		
		// Retrieve all items and reset the counter
		while($loop) {
			for($i = $fetched+1; $i <= $index; $i++) {
				$values[] = $memcached->get(memcached_get_logindex($i));
			}
			
			$fetched = $index;
			
			if($memcached->replace($key, $index, 0)) {
				$loop = false;
			} else {
				usleep(500);
			}
		}

		// Insert all log message - we're assuming input filtering happened earlier
		$query = "";

		foreach($values as $value) {
			if(strlen($query)) {
				$query .= ",";
			}
			$query .= "(NOW(), '" . 
				$value[0] . "', '" . 
				$value[1] . "', '" . 
				$value[2] . "', '" . 
				$value[3] . "', '" . 
				$value[4] . "')";
		}

		$ydb->query( "INSERT INTO `" . YOURLS_DB_TABLE_LOG . "` 
					(click_time, shorturl, referrer, user_agent, ip_address, country_code)
					VALUES " . $query);
	} 
	
	return true;
}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key 
 * @return string
 */
function memcached_get_logindex($key) {
	return MEMCACHED_SALT . "." . MEMCACHED_CACHE_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to do an atomic increment to a variable, 
 * 
 *
 * @param string $key 
 * @return void
 */
function memcached_cache_key_increment($key) {
	$key = MEMCACHED_SALT . "." . $key;
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	do {
		$result = $memcached->increment($key);
	} while(!$result && usleep(500));
	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key 
 * @return old value before the reset
 */
function memcached_cache_key_zero($key) {
	$key = MEMCACHED_SALT . "." . $key;
	$memcached = new Memcached();
	$memcached->addServer(MEMCACHED_IP, MEMCACHED_PORT);
	$old = 0;
	do {
		$old = $memcached->get($key);
		if($old == 0) {
			return $old;
		}
		$result = $memcached->replace($key, $old, 0);
	} while(!$result && usleep(500));
	return $old;
}