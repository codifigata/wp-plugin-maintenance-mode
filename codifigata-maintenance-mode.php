<?php
/**
 * Plugin Name:       Codifigata - Maintenance Mode
 * Description:       Puts the site in maintenance mode with a real 503 response, a customizable maintenance page, role/IP bypass, scheduled activation, and a shareable secret preview link.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Codifigata
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codifigata-maintenance-mode
 * Domain Path:       /languages
 *
 * @package Codifigata_Maintenance_Mode
 */

namespace Codifigata\MaintenanceMode;

use DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principale del plugin, racchiusa nel namespace Codifigata\MaintenanceMode.
 *
 * Il namespace elimina il rischio di collisione sul nome della classe con
 * altri plugin. Le stringhe che PHP non può namespacizzare (costanti globali,
 * nome dell'option, hook, cron, cookie) usano invece il prefisso "cdfg_mm_",
 * radice condivisa con gli altri plugin Codifigata ma con suffisso di
 * prodotto dedicato, per evitare collisioni sia con plugin di terzi sia tra
 * i plugin Codifigata stessi.
 */
class Plugin {

	const VERSION = '1.0.0';

	const OPTION_NAME       = 'cdfg_mm_settings';
	const OPTION_GROUP      = 'cdfg_mm_settings_group';
	const SETTINGS_SLUG     = 'cdfg-mm-settings';
	const REGEN_ACTION      = 'cdfg_mm_regenerate_token';
	const REGEN_NONCE       = 'cdfg_mm_regenerate_token_nonce';
	const TOGGLE_ACTION     = 'cdfg_mm_toggle_status';
	const TOGGLE_NONCE      = 'cdfg_mm_toggle_status_nonce';
	const BYPASS_COOKIE     = 'cdfg_mm_bypass_token';
	const PREVIEW_QUERY_ARG = 'cdfg_mm_preview';
	const CRON_HOOK_START   = 'cdfg_mm_cron_start_maintenance';
	const CRON_HOOK_END     = 'cdfg_mm_cron_end_maintenance';

	/** @var array|null Cache locale delle impostazioni, per non richiamare get_option() più volte per richiesta. */
	private $settings = null;

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

		// Bypass via link segreto e gating del front-end.
		add_action( 'init', array( $this, 'maybe_set_bypass_cookie' ) );
		add_action( 'template_redirect', array( $this, 'maybe_show_maintenance_page' ) );

		// Attivazione/disattivazione programmata.
		add_action( self::CRON_HOOK_START, array( $this, 'cron_start_maintenance' ) );
		add_action( self::CRON_HOOK_END, array( $this, 'cron_end_maintenance' ) );
		add_action( 'update_option_' . self::OPTION_NAME, array( $this, 'maybe_reschedule_cron' ), 10, 2 );

		// Impostazioni.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_regenerated_notice' ) );
		add_action( 'admin_post_' . self::REGEN_ACTION, array( $this, 'handle_regenerate_token' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

		// Widget di bacheca per attivare/disattivare rapidamente.
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
	}

	// -------------------------------------------------------------------------
	// Attivazione / disattivazione
	// -------------------------------------------------------------------------

	public function on_activate() {
		$settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_default_settings() );

		if ( '' === $settings['bypass_token'] ) {
			$settings['bypass_token'] = $this->generate_token();
		}

		update_option( self::OPTION_NAME, $settings );
	}

	public function on_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK_START );
		wp_clear_scheduled_hook( self::CRON_HOOK_END );
	}

	private function generate_token() {
		return wp_generate_password( 32, false );
	}

	// -------------------------------------------------------------------------
	// Impostazioni
	// -------------------------------------------------------------------------

	private function get_default_settings() {
		return array(
			'status'           => '0',
			'title'            => __( 'Be right back', 'codifigata-maintenance-mode' ),
			'message'          => __( 'We are currently performing scheduled maintenance. We will be back online shortly, thank you for your patience.', 'codifigata-maintenance-mode' ),
			'bg_color'         => '#1a1a1a',
			'text_color'       => '#ffffff',
			'accent_color'     => '#e0a526',
			'allowed_roles'    => array(),
			'bypass_ips'       => '',
			'bypass_token'     => '',
			'schedule_enabled' => '0',
			'schedule_start'   => '',
			'schedule_end'     => '',
		);
	}

	private function get_settings() {
		if ( null === $this->settings ) {
			$this->settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_default_settings() );
		}

		return $this->settings;
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Maintenance Mode', 'codifigata-maintenance-mode' ),
			__( 'Maintenance Mode', 'codifigata-maintenance-mode' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'codifigata-maintenance-mode' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
			)
		);

		add_settings_section( 'cdfg_mm_main_section', '', '__return_false', self::SETTINGS_SLUG );

		add_settings_field( 'cdfg_mm_status', __( 'Maintenance mode', 'codifigata-maintenance-mode' ), array( $this, 'render_field_status' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_schedule', __( 'Scheduled activation', 'codifigata-maintenance-mode' ), array( $this, 'render_field_schedule' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_title', __( 'Page title', 'codifigata-maintenance-mode' ), array( $this, 'render_field_title' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_message', __( 'Message', 'codifigata-maintenance-mode' ), array( $this, 'render_field_message' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_bg_color', __( 'Background color', 'codifigata-maintenance-mode' ), array( $this, 'render_field_bg_color' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_text_color', __( 'Text color', 'codifigata-maintenance-mode' ), array( $this, 'render_field_text_color' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_accent_color', __( 'Accent color', 'codifigata-maintenance-mode' ), array( $this, 'render_field_accent_color' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_allowed_roles', __( 'Allowed roles', 'codifigata-maintenance-mode' ), array( $this, 'render_field_allowed_roles' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_bypass_ips', __( 'Allowed IP addresses', 'codifigata-maintenance-mode' ), array( $this, 'render_field_bypass_ips' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
		add_settings_field( 'cdfg_mm_bypass_link', __( 'Secret preview link', 'codifigata-maintenance-mode' ), array( $this, 'render_field_bypass_link' ), self::SETTINGS_SLUG, 'cdfg_mm_main_section' );
	}

	/**
	 * Sanifica i dati del form Impostazioni. Qualsiasi valore mancante o non
	 * valido ricade sul default, così l'opzione salvata è sempre coerente.
	 * Il token di bypass non è mai accettato dal form: viene preservato dal
	 * valore già salvato e cambia solo tramite l'azione "Regenerate link".
	 *
	 * @param mixed $input Dati grezzi da $_POST (già passati da register_setting).
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = $this->get_default_settings();
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->get_settings();
		$output   = array();

		$output['status'] = ! empty( $input['status'] ) ? '1' : '0';

		// Titolo e messaggio possono essere lasciati vuoti di proposito (pagina di
		// manutenzione minimale, senza testo): il fallback al default scatta solo
		// se il campo non arriva affatto, non se arriva vuoto.
		$output['title'] = isset( $input['title'] ) && is_string( $input['title'] )
			? sanitize_text_field( wp_unslash( $input['title'] ) )
			: $defaults['title'];

		$output['message'] = isset( $input['message'] ) && is_string( $input['message'] )
			? wp_kses_post( wp_unslash( $input['message'] ) )
			: $defaults['message'];

		$bg_color           = ! empty( $input['bg_color'] ) && is_string( $input['bg_color'] ) ? sanitize_hex_color( wp_unslash( $input['bg_color'] ) ) : '';
		$output['bg_color'] = ! empty( $bg_color ) ? $bg_color : $defaults['bg_color'];

		$text_color           = ! empty( $input['text_color'] ) && is_string( $input['text_color'] ) ? sanitize_hex_color( wp_unslash( $input['text_color'] ) ) : '';
		$output['text_color'] = ! empty( $text_color ) ? $text_color : $defaults['text_color'];

		$accent_color           = ! empty( $input['accent_color'] ) && is_string( $input['accent_color'] ) ? sanitize_hex_color( wp_unslash( $input['accent_color'] ) ) : '';
		$output['accent_color'] = ! empty( $accent_color ) ? $accent_color : $defaults['accent_color'];

		$roles = array();
		if ( ! empty( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] ) ) {
			$roles = array_map( 'sanitize_key', wp_unslash( $input['allowed_roles'] ) );
			$roles = array_values( array_intersect( $roles, array_keys( get_editable_roles() ) ) );
		}
		$output['allowed_roles'] = $roles;

		$output['bypass_ips'] = ! empty( $input['bypass_ips'] ) && is_string( $input['bypass_ips'] )
			? $this->sanitize_ip_list( wp_unslash( $input['bypass_ips'] ) )
			: '';

		// update_option() esegue sempre il filtro sanitize_option_{option}, quindi questo
		// metodo viene invocato anche dalla chiamata diretta in handle_regenerate_token(),
		// non solo dal submit del form (che non include mai un campo bypass_token). Se il
		// valore in ingresso ha il formato di un token valido lo si mantiene, altrimenti si
		// preserva quello già salvato: così il form non lo tocca mai, ma "Regenerate link"
		// riesce comunque a sostituirlo.
		$output['bypass_token'] = ! empty( $input['bypass_token'] ) && is_string( $input['bypass_token'] ) && preg_match( '/^[A-Za-z0-9]{32}$/', $input['bypass_token'] )
			? $input['bypass_token']
			: $current['bypass_token'];

		$output['schedule_enabled'] = ! empty( $input['schedule_enabled'] ) ? '1' : '0';
		$output['schedule_start']   = ! empty( $input['schedule_start'] ) && is_string( $input['schedule_start'] ) ? $this->sanitize_datetime_local( wp_unslash( $input['schedule_start'] ) ) : '';
		$output['schedule_end']     = ! empty( $input['schedule_end'] ) && is_string( $input['schedule_end'] ) ? $this->sanitize_datetime_local( wp_unslash( $input['schedule_end'] ) ) : '';

		return $output;
	}

	/**
	 * Valida un elenco di IP (uno per riga), scartando le righe non valide.
	 */
	private function sanitize_ip_list( $raw ) {
		$lines = preg_split( '/[\r\n]+/', $raw );
		$valid = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && rest_is_ip_address( $line ) ) {
				$valid[] = $line;
			}
		}

		return implode( "\n", $valid );
	}

	/**
	 * Valida il valore di un campo "datetime-local" (formato "Y-m-d\TH:i"),
	 * interpretato nel fuso orario del sito. Torna stringa vuota se non valido.
	 */
	private function sanitize_datetime_local( $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );

		return $dt instanceof DateTime ? $dt->format( 'Y-m-d\TH:i' ) : '';
	}

	public function render_field_status() {
		$settings = $this->get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[status]" value="1" <?php checked( $settings['status'], '1' ); ?> />
			<?php esc_html_e( 'Enable maintenance mode now', 'codifigata-maintenance-mode' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'While enabled, visitors who are not allowed to bypass it will see the maintenance page with a real 503 (temporarily unavailable) response. Administrators always keep access.', 'codifigata-maintenance-mode' ); ?>
		</p>
		<?php
	}

	public function render_field_schedule() {
		$settings = $this->get_settings();
		?>
		<fieldset>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[schedule_enabled]" value="1" <?php checked( $settings['schedule_enabled'], '1' ); ?> />
				<?php esc_html_e( 'Automatically turn maintenance mode on and off on a schedule', 'codifigata-maintenance-mode' ); ?>
			</label>
			<p>
				<label for="cdfg_mm_schedule_start"><?php esc_html_e( 'Start', 'codifigata-maintenance-mode' ); ?></label>
				<input type="datetime-local" id="cdfg_mm_schedule_start" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[schedule_start]" value="<?php echo esc_attr( $settings['schedule_start'] ); ?>" />
				&nbsp;&nbsp;
				<label for="cdfg_mm_schedule_end"><?php esc_html_e( 'End', 'codifigata-maintenance-mode' ); ?></label>
				<input type="datetime-local" id="cdfg_mm_schedule_end" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[schedule_end]" value="<?php echo esc_attr( $settings['schedule_end'] ); ?>" />
			</p>
			<p class="description">
				<?php esc_html_e( 'Times use the site\'s timezone (Settings → General). Leave both empty to only control the mode manually with the toggle above.', 'codifigata-maintenance-mode' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function render_field_title() {
		$settings = $this->get_settings();
		printf(
			'<input type="text" class="regular-text" name="%1$s[title]" value="%2$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['title'] )
		);
		?>
		<p class="description"><?php esc_html_e( 'Leave empty to hide the title — useful for a minimal, text-free maintenance page.', 'codifigata-maintenance-mode' ); ?></p>
		<?php
	}

	public function render_field_message() {
		$settings = $this->get_settings();
		printf(
			'<textarea class="large-text" rows="4" name="%1$s[message]">%2$s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_textarea( $settings['message'] )
		);
		?>
		<p class="description"><?php esc_html_e( 'Leave empty to hide the message.', 'codifigata-maintenance-mode' ); ?></p>
		<?php
	}

	public function render_field_bg_color() {
		$settings = $this->get_settings();
		$default  = $this->get_default_settings();
		printf(
			'<input type="text" class="cdfg-mm-color-field" name="%1$s[bg_color]" value="%2$s" data-default-color="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['bg_color'] ),
			esc_attr( $default['bg_color'] )
		);
	}

	public function render_field_text_color() {
		$settings = $this->get_settings();
		$default  = $this->get_default_settings();
		printf(
			'<input type="text" class="cdfg-mm-color-field" name="%1$s[text_color]" value="%2$s" data-default-color="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['text_color'] ),
			esc_attr( $default['text_color'] )
		);
	}

	public function render_field_accent_color() {
		$settings = $this->get_settings();
		$default  = $this->get_default_settings();
		printf(
			'<input type="text" class="cdfg-mm-color-field" name="%1$s[accent_color]" value="%2$s" data-default-color="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['accent_color'] ),
			esc_attr( $default['accent_color'] )
		);
		?>
		<p class="description"><?php esc_html_e( 'Used for the page title on the maintenance page.', 'codifigata-maintenance-mode' ); ?></p>
		<?php
	}

	public function render_field_allowed_roles() {
		$settings = $this->get_settings();
		$roles    = get_editable_roles();
		?>
		<fieldset>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Administrators always have access. Select any additional role that should also be able to browse the site normally while maintenance mode is on.', 'codifigata-maintenance-mode' ); ?>
			</p>
			<?php foreach ( $roles as $role_key => $role ) : ?>
				<?php if ( 'administrator' === $role_key ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<label style="display:block;">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowed_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $settings['allowed_roles'], true ) ); ?> />
					<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	public function render_field_bypass_ips() {
		$settings = $this->get_settings();
		?>
		<textarea class="large-text code" rows="4" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[bypass_ips]"><?php echo esc_textarea( $settings['bypass_ips'] ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One IP address per line (IPv4 or IPv6). Visitors from these addresses can browse the site normally while maintenance mode is on.', 'codifigata-maintenance-mode' ); ?>
		</p>
		<?php
	}

	public function render_field_bypass_link() {
		$settings  = $this->get_settings();
		$url       = $this->get_bypass_url( $settings['bypass_token'] );
		$regen_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::REGEN_ACTION ), admin_url( 'admin-post.php' ) ),
			self::REGEN_ACTION,
			self::REGEN_NONCE
		);
		?>
		<p>
			<input type="text" class="large-text code" readonly="readonly" onclick="this.select();" value="<?php echo esc_attr( $url ); ?>" />
		</p>
		<p>
			<a href="<?php echo esc_url( $regen_url ); ?>" class="button"><?php esc_html_e( 'Regenerate link', 'codifigata-maintenance-mode' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Anyone who opens this link can browse the site while maintenance mode is on, without logging in — handy for sharing a preview with a client or teammate. Regenerating the link immediately invalidates the previous one.', 'codifigata-maintenance-mode' ); ?>
		</p>
		<?php
	}

	private function get_bypass_url( $token ) {
		return add_query_arg( self::PREVIEW_QUERY_ARG, $token, home_url( '/' ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Maintenance Mode', 'codifigata-maintenance-mode' ); ?></h1>
			<p>
				<?php if ( '1' === $settings['status'] ) : ?>
					<strong style="color:#b32d2e;"><?php esc_html_e( 'Maintenance mode is currently ON.', 'codifigata-maintenance-mode' ); ?></strong>
				<?php else : ?>
					<strong style="color:#2a7a2a;"><?php esc_html_e( 'Maintenance mode is currently OFF.', 'codifigata-maintenance-mode' ); ?></strong>
				<?php endif; ?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SETTINGS_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::SETTINGS_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){ $(".cdfg-mm-color-field").wpColorPicker(); });' );
	}

	public function maybe_show_regenerated_notice() {
		// Solo per decidere se mostrare un avviso di sola lettura, nessuna scrittura: non serve un nonce.
		if ( ! isset( $_GET['page'] ) || self::SETTINGS_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( empty( $_GET['cdfg-mm-regenerated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'A new secret preview link has been generated. The previous link no longer works.', 'codifigata-maintenance-mode' ); ?></p>
		</div>
		<?php
	}

	public function handle_regenerate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'codifigata-maintenance-mode' ) );
		}

		check_admin_referer( self::REGEN_ACTION, self::REGEN_NONCE );

		$settings                 = wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_default_settings() );
		$settings['bypass_token'] = $this->generate_token();

		update_option( self::OPTION_NAME, $settings );

		wp_safe_redirect(
			add_query_arg(
				'cdfg-mm-regenerated',
				'1',
				admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Widget di bacheca
	// -------------------------------------------------------------------------

	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'cdfg_mm_dashboard_widget',
			__( 'Maintenance Mode', 'codifigata-maintenance-mode' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$settings   = $this->get_settings();
		$is_on      = '1' === $settings['status'];
		$toggle_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::TOGGLE_ACTION ), admin_url( 'admin-post.php' ) ),
			self::TOGGLE_ACTION,
			self::TOGGLE_NONCE
		);
		?>
		<p>
			<?php if ( $is_on ) : ?>
				<strong style="color:#b32d2e;"><?php esc_html_e( 'Maintenance mode is ON.', 'codifigata-maintenance-mode' ); ?></strong>
				<?php esc_html_e( 'Visitors currently see the maintenance page.', 'codifigata-maintenance-mode' ); ?>
			<?php else : ?>
				<strong style="color:#2a7a2a;"><?php esc_html_e( 'Maintenance mode is OFF.', 'codifigata-maintenance-mode' ); ?></strong>
				<?php esc_html_e( 'The site is publicly accessible.', 'codifigata-maintenance-mode' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( '1' === $settings['schedule_enabled'] ) : ?>
			<p class="description"><?php esc_html_e( 'Scheduled activation is on — the status above may change automatically.', 'codifigata-maintenance-mode' ); ?></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>" class="button <?php echo $is_on ? '' : 'button-primary'; ?>">
				<?php echo $is_on ? esc_html__( 'Turn off', 'codifigata-maintenance-mode' ) : esc_html__( 'Turn on', 'codifigata-maintenance-mode' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ); ?>">
				<?php esc_html_e( 'Manage settings', 'codifigata-maintenance-mode' ); ?>
			</a>
		</p>
		<?php
	}

	public function handle_toggle_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'codifigata-maintenance-mode' ) );
		}

		check_admin_referer( self::TOGGLE_ACTION, self::TOGGLE_NONCE );

		$settings           = wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_default_settings() );
		$settings['status'] = '1' === $settings['status'] ? '0' : '1';
		update_option( self::OPTION_NAME, $settings );

		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}

	// -------------------------------------------------------------------------
	// Attivazione/disattivazione programmata (wp_cron)
	// -------------------------------------------------------------------------

	/**
	 * Rischedula gli eventi cron di inizio/fine manutenzione quando cambiano i
	 * campi di scheduling. Confronta solo quei campi per non ripianificare ad
	 * ogni salvataggio (incluso quello fatto dai callback cron stessi).
	 */
	public function maybe_reschedule_cron( $old_value, $new_value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		$old_key = implode(
			'|',
			array(
				isset( $old_value['schedule_enabled'] ) ? $old_value['schedule_enabled'] : '0',
				isset( $old_value['schedule_start'] ) ? $old_value['schedule_start'] : '',
				isset( $old_value['schedule_end'] ) ? $old_value['schedule_end'] : '',
			)
		);

		$new_key = implode(
			'|',
			array(
				isset( $new_value['schedule_enabled'] ) ? $new_value['schedule_enabled'] : '0',
				isset( $new_value['schedule_start'] ) ? $new_value['schedule_start'] : '',
				isset( $new_value['schedule_end'] ) ? $new_value['schedule_end'] : '',
			)
		);

		if ( $old_key === $new_key ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK_START );
		wp_clear_scheduled_hook( self::CRON_HOOK_END );

		if ( empty( $new_value['schedule_enabled'] ) || '1' !== $new_value['schedule_enabled'] ) {
			return;
		}

		$start_ts = $this->datetime_local_to_timestamp( isset( $new_value['schedule_start'] ) ? $new_value['schedule_start'] : '' );
		$end_ts   = $this->datetime_local_to_timestamp( isset( $new_value['schedule_end'] ) ? $new_value['schedule_end'] : '' );

		if ( $start_ts && $start_ts > time() ) {
			wp_schedule_single_event( $start_ts, self::CRON_HOOK_START );
		}

		if ( $end_ts && $end_ts > time() ) {
			wp_schedule_single_event( $end_ts, self::CRON_HOOK_END );
		}
	}

	private function datetime_local_to_timestamp( $value ) {
		if ( '' === $value ) {
			return 0;
		}

		$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );

		return $dt instanceof DateTime ? $dt->getTimestamp() : 0;
	}

	public function cron_start_maintenance() {
		$this->update_status_option( '1' );
	}

	public function cron_end_maintenance() {
		$this->update_status_option( '0' );
	}

	private function update_status_option( $status ) {
		$settings           = wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_default_settings() );
		$settings['status'] = $status;

		update_option( self::OPTION_NAME, $settings );

		$this->settings = null;
	}

	// -------------------------------------------------------------------------
	// Bypass e rendering front-end
	// -------------------------------------------------------------------------

	/**
	 * Se l'URL corrente porta un token di bypass valido, lo salva in un cookie
	 * così il visitatore continua a bypassare la manutenzione nelle richieste
	 * successive. Il cookie viene applicato anche alla richiesta corrente,
	 * perché setcookie() aggiorna $_COOKIE solo dal prossimo caricamento.
	 */
	public function maybe_set_bypass_cookie() {
		// Link pubblico per design (va condiviso con chi non ha un account): il token stesso, verificato con hash_equals(), fa le veci del nonce.
		if ( headers_sent() || ! isset( $_GET[ self::PREVIEW_QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = $this->get_settings();

		if ( '' === $settings['bypass_token'] || ! hash_equals( $settings['bypass_token'], $token ) ) {
			return;
		}

		setcookie(
			self::BYPASS_COOKIE,
			$token,
			array(
				'expires'  => time() + ( 7 * DAY_IN_SECONDS ),
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::BYPASS_COOKIE ] = $token;
	}

	public function maybe_show_maintenance_page() {
		$settings = $this->get_settings();

		if ( '1' !== $settings['status'] ) {
			return;
		}

		if ( $this->should_bypass() ) {
			return;
		}

		$this->render_maintenance_page( $settings );
		exit;
	}

	/**
	 * Determina se il visitatore corrente deve vedere il sito normalmente
	 * invece della pagina di manutenzione: richieste tecniche (REST/cron/
	 * admin/login), amministratori, ruoli abilitati, cookie di bypass, IP
	 * consentiti, e infine il filtro 'cdfg_mm_bypass' per estensioni esterne.
	 */
	private function should_bypass() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$settings = $this->get_settings();

		if ( is_user_logged_in() && ! empty( $settings['allowed_roles'] ) ) {
			$user = wp_get_current_user();
			if ( array_intersect( $settings['allowed_roles'], (array) $user->roles ) ) {
				return true;
			}
		}

		if ( '' !== $settings['bypass_token'] && isset( $_COOKIE[ self::BYPASS_COOKIE ] ) ) {
			$cookie_token = sanitize_text_field( wp_unslash( $_COOKIE[ self::BYPASS_COOKIE ] ) );
			if ( hash_equals( $settings['bypass_token'], $cookie_token ) ) {
				return true;
			}
		}

		if ( '' !== $settings['bypass_ips'] ) {
			$visitor_ip = $this->get_visitor_ip();
			$allowed    = array_filter( array_map( 'trim', explode( "\n", $settings['bypass_ips'] ) ) );
			if ( '' !== $visitor_ip && in_array( $visitor_ip, $allowed, true ) ) {
				return true;
			}
		}

		/**
		 * Filtra il risultato finale del controllo di bypass della modalità
		 * manutenzione. Se true, il visitatore corrente vede il sito normalmente.
		 *
		 * @param bool $bypass Risultato calcolato di default (false).
		 */
		return apply_filters( 'cdfg_mm_bypass', false );
	}

	/**
	 * IP del visitatore usato per il controllo bypass. Legge solo REMOTE_ADDR
	 * (non header spoofabili come X-Forwarded-For); i siti dietro proxy/CDN
	 * possono adattarlo con il filtro 'cdfg_mm_visitor_ip'.
	 */
	private function get_visitor_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filtra l'IP del visitatore usato per il controllo bypass.
		 *
		 * @param string $ip IP rilevato di default (REMOTE_ADDR).
		 */
		return apply_filters( 'cdfg_mm_visitor_ip', $ip );
	}

	private function render_maintenance_page( $settings ) {
		status_header( 503 );
		nocache_headers();

		/**
		 * Filtra il valore (in secondi) dell'header Retry-After inviato con la
		 * risposta 503. Restituire 0 per non inviare l'header.
		 *
		 * @param int $retry_after Secondi predefiniti (1 ora).
		 */
		$retry_after = (int) apply_filters( 'cdfg_mm_retry_after', HOUR_IN_SECONDS );
		if ( $retry_after > 0 ) {
			header( 'Retry-After: ' . $retry_after );
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );

		/*
		 * Pagina standalone: non passa mai da wp_head(), quindi il CSS dinamico
		 * viene comunque registrato/enqueued (handle senza src, nessuna richiesta
		 * esterna) e stampato subito con wp_print_styles(), invece di un tag
		 * <style> scritto a mano.
		 */
		$maintenance_css = '
			body {
				margin: 0;
				min-height: 100vh;
				display: flex;
				align-items: center;
				justify-content: center;
				background: ' . esc_html( $settings['bg_color'] ) . ';
				color: ' . esc_html( $settings['text_color'] ) . ';
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				text-align: center;
				padding: 24px;
				box-sizing: border-box;
			}
			.cdfg-mm-wrap {
				max-width: 560px;
			}
			.cdfg-mm-wrap h1 {
				font-size: 28px;
				margin: 0 0 16px;
				color: ' . esc_html( $settings['accent_color'] ) . ';
			}
			.cdfg-mm-wrap p {
				font-size: 16px;
				line-height: 1.6;
				margin: 0;
			}
		';

		wp_register_style( 'cdfg-mm-maintenance', false, array(), self::VERSION );
		wp_enqueue_style( 'cdfg-mm-maintenance' );
		wp_add_inline_style( 'cdfg-mm-maintenance', $maintenance_css );

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( '' !== $settings['title'] ? $settings['title'] : get_bloginfo( 'name' ) ); ?></title>
	<?php wp_print_styles( 'cdfg-mm-maintenance' ); ?>
</head>
<body>
	<div class="cdfg-mm-wrap">
		<?php if ( '' !== $settings['title'] ) : ?>
			<h1><?php echo esc_html( $settings['title'] ); ?></h1>
		<?php endif; ?>
		<?php if ( '' !== $settings['message'] ) : ?>
			<p><?php echo wp_kses_post( $settings['message'] ); ?></p>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
		$html = ob_get_clean();

		/**
		 * Filtra l'HTML completo della pagina di manutenzione prima dell'output.
		 *
		 * @param string $html     Markup HTML generato.
		 * @param array  $settings Impostazioni correnti del plugin.
		 */
		echo apply_filters( 'cdfg_mm_maintenance_page_html', $html, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup già escapato campo per campo sopra.
	}
}

new Plugin();
