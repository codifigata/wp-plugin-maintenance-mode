=== Codifigata - Maintenance Mode ===
Contributors: codifigata
Tags: maintenance mode, coming soon, 503, under construction, site offline
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, lightweight maintenance mode with a real 503 response, role/IP bypass, scheduled activation, and a shareable secret preview link.

== Description ==

Codifigata - Maintenance Mode puts your site into maintenance mode with a single toggle: logged-out visitors see a clean, customizable maintenance page while you keep working on the site.

It is built to be simple and lightweight: no page builder, no bundled font/tracking scripts, no external requests. Everything needed to render the maintenance page is self-contained in the plugin.

**Core features**

* Real `503 Service Temporarily Unavailable` HTTP response with a `Retry-After` header, so search engines understand the site is only temporarily down instead of treating it as permanently gone.
* `X-Robots-Tag: noindex` header on the maintenance page.
* Customizable title, message, background color, text color, and accent color, with a native color picker. Title and message can also be left empty for a minimal, text-free maintenance page.
* Administrators always keep access; additional roles can be allowed from the settings.
* Bypass by IP address (one per line).
* Scheduled activation: set a start and end date/time and the plugin turns maintenance mode on and off automatically via WP-Cron.
* Secret preview link: a unique URL that lets anyone (client, teammate) browse the site normally while maintenance mode is on, without logging in. It can be regenerated at any time to instantly invalidate the previous link.
* Dashboard widget to see the current status and turn maintenance mode on/off with one click, without opening the settings page.
* REST API, WP-Cron, AJAX and the login page are always excluded, so the rest of the site keeps working normally behind the scenes.

**Developer filters**

`cdfg_mm_bypass` (bool $bypass)
Final filter over the bypass decision. Return `true` to let the current visitor browse the site normally.

`cdfg_mm_visitor_ip` (string $ip)
Filters the IP address used for the IP bypass check. Useful if the site is behind a proxy/CDN and `REMOTE_ADDR` does not reflect the real visitor IP.

`cdfg_mm_retry_after` (int $seconds)
Filters the value of the `Retry-After` header (default: 1 hour). Return `0` to omit the header.

`cdfg_mm_maintenance_page_html` (string $html, array $settings)
Filters the full HTML markup of the maintenance page before it is sent.

Example:

`
add_filter( 'cdfg_mm_visitor_ip', function( $ip ) {
    return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : $ip;
} );
`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the WordPress "Plugins" screen.
2. Activate the plugin from the WordPress "Plugins" menu.
3. Go to Settings → Maintenance Mode to customize the page, allowed roles/IPs, and (optionally) the schedule.
4. Enable the "Enable maintenance mode now" checkbox, or set a schedule, and save.

== Frequently Asked Questions ==

= Will this hurt my SEO? =

No, as long as maintenance windows are short. The plugin sends a real `503` HTTP status with a `Retry-After` header, which tells search engines the site is temporarily unavailable and to check back later, instead of dropping the pages from the index as it would with a normal `200` response.

= Can I let a client see the site while it's in maintenance mode, without giving them a login? =

Yes — copy the "Secret preview link" from the settings page and share it. Anyone who opens it can browse the site normally until you regenerate the link.

= What happens if I get locked out? =

It cannot happen: administrators (any user with the `manage_options` capability) always bypass maintenance mode, regardless of the other settings.

= Does the scheduled activation require a visitor to trigger it? =

No. It uses WP-Cron, which is triggered by site traffic like most WordPress scheduled tasks. On very low-traffic sites, the actual start/end time may be delayed by a few minutes until the next visit.

= What happens to the plugin's data on uninstall? =

On uninstall (not on simple deactivation), the plugin removes its settings and any pending scheduled activation/deactivation event. No database tables are created.

== Screenshots ==

1. Settings page: status, scheduling, and page content.
2. Settings page: colors, allowed roles/IPs, and secret preview link.
3. The maintenance page shown to visitors.

== Changelog ==

= 1.0.0 =
* Maintenance toggle with real 503 response and Retry-After header.
* Customizable title and message — both can be left empty for a minimal, text-free maintenance page — plus colors.
* Bypass by role, IP address, and secret preview link.
* Scheduled activation/deactivation via WP-Cron.
* Dashboard widget to check the current status and toggle maintenance mode on/off with one click.
* Developer filters: `cdfg_mm_bypass`, `cdfg_mm_visitor_ip`, `cdfg_mm_retry_after`, `cdfg_mm_maintenance_page_html`.
