<?php
/**
 * Uninstall handler.
 *
 * Rimuove le impostazioni e gli eventi cron pianificati creati dal plugin
 * quando questo viene disinstallato (non alla semplice disattivazione).
 *
 * @package Codifigata_Maintenance_Mode
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'cmm_cron_start_maintenance' );
wp_clear_scheduled_hook( 'cmm_cron_end_maintenance' );
delete_option( 'cmm_settings' );
