<?php
/**
 * A polite "enjoying this plugin?" review request.
 *
 * Read-only by design: it only reads options and one indexed scan row, and
 * never writes anything except its own state option.
 *
 * @package JanitorixMediaAudit
 */

declare(strict_types=1);

namespace JanitorixMediaAudit\Admin;

use JanitorixMediaAudit\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Review prompts, confined to the plugin's own admin screens:
 *
 * - A small, always-visible rating link in the admin footer.
 * - A notice that first appears after ~two weeks of real use, and returns
 *   once a month until the user actually rates the plugin.
 * - Once "Rate it" is clicked, every prompt (notice and footer) disappears
 *   for good.
 */
final class ReviewNotice {

	public const OPTION     = 'janitorix_review';
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/janitorix-media-audit/reviews/#new-post';

	/**
	 * Days of use before the notice first appears.
	 */
	public const WAIT_DAYS = 15;

	/**
	 * Days between repeat appearances (once a month).
	 */
	public const SNOOZE_DAYS = 30;

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'start_clock' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
	}

	/**
	 * A small, permanent rating link in the admin footer — only on this
	 * plugin's own screens, and only until the user has rated.
	 *
	 * @param string $text Default footer text.
	 */
	public function footer_text( string $text ): string {
		if ( ! $this->on_own_screen() ) {
			return $text;
		}

		$state = (array) get_option( self::OPTION, array() );
		if ( ! empty( $state['rated'] ) ) {
			return $text;
		}

		$link = '<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">';

		return sprintf(
			/* translators: 1: opening link tag to the WordPress.org review form, 2: closing link tag. */
			esc_html__( 'Enjoying Janitorix? Leave us a %1$s&#9733;&#9733;&#9733;&#9733;&#9733; review%2$s — it keeps development going.', 'janitorix-media-audit' ),
			$link,
			'</a>'
		);
	}

	/**
	 * Record when the plugin was first seen in the admin, so existing installs
	 * also wait a full period after updating to a version with this notice.
	 */
	public function start_clock(): void {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) || empty( $state['since'] ) ) {
			update_option( self::OPTION, array( 'since' => time() ), false );
		}
	}

	/**
	 * Process the notice's action links.
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked immediately below, once we know this is our request.
		if ( empty( $_GET['janitorix_review'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'janitorix_review_notice' );

		$state  = (array) get_option( self::OPTION, array() );
		$action = sanitize_key( wp_unslash( $_GET['janitorix_review'] ) );

		if ( 'rated' === $action ) {
			$state['rated'] = true;
		} elseif ( 'rate' === $action ) {
			// Opened the review form: ask for confirmation on the next visit.
			$state['asked'] = true;
			unset( $state['snooze_until'] );
		} else {
			$state['snooze_until'] = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		}
		update_option( self::OPTION, $state, false );

		wp_safe_redirect( remove_query_arg( array( 'janitorix_review', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Render the notice when every polite condition is met.
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->should_show() ) {
			return;
		}

		$state = (array) get_option( self::OPTION, array() );
		$later = wp_nonce_url( add_query_arg( 'janitorix_review', 'later' ), 'janitorix_review_notice' );
		$rate  = wp_nonce_url( add_query_arg( 'janitorix_review', 'rate' ), 'janitorix_review_notice' );
		$rated = wp_nonce_url( add_query_arg( 'janitorix_review', 'rated' ), 'janitorix_review_notice' );

		if ( empty( $state['asked'] ) ) {
			$message = '<strong>' . esc_html__( 'Enjoying Janitorix?', 'janitorix-media-audit' ) . '</strong> '
				. esc_html__( 'A quick 5-star review helps other people find the plugin and keeps development going. Thank you!', 'janitorix-media-audit' );
		} else {
			$message = '<strong>' . esc_html__( 'Did you get a chance to leave that review?', 'janitorix-media-audit' ) . '</strong> '
				. esc_html__( 'If you did — thank you! Confirm below and we will never ask again.', 'janitorix-media-audit' );
		}
		?>
		<div class="notice notice-info" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
			<p style="margin:0;"><?php echo wp_kses( $message, array( 'strong' => array() ) ); ?></p>
			<p style="margin:0;white-space:nowrap;">
				<?php if ( ! empty( $state['asked'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $rated ); ?>" style="margin-right:6px;">
						<?php esc_html_e( 'Yes, I left a review', 'janitorix-media-audit' ); ?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $later ); ?>" style="margin-right:6px;">
					<?php esc_html_e( 'Maybe later', 'janitorix-media-audit' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer" onclick="window.location='<?php echo esc_js( $rate ); ?>';return true;">
					<?php esc_html_e( 'Rate it ★★★★★', 'janitorix-media-audit' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * All the polite conditions in one place.
	 */
	private function should_show(): bool {
		if ( ! $this->on_own_screen() ) {
			return false;
		}

		$state = (array) get_option( self::OPTION, array() );
		if ( ! empty( $state['rated'] ) ) {
			return false;
		}
		if ( ! empty( $state['snooze_until'] ) && time() < (int) $state['snooze_until'] ) {
			return false;
		}
		if ( empty( $state['since'] ) || time() < (int) $state['since'] + self::WAIT_DAYS * DAY_IN_SECONDS ) {
			return false;
		}

		// Only ask people who actually use the plugin: at least one
		// completed scan. One indexed row, read-only.
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1',
				Tables::scans(),
				'completed'
			)
		);

		return null !== $found && false !== $found;
	}

	/**
	 * Whether the current admin screen belongs to this plugin — every one of
	 * its page slugs starts with the menu slug.
	 */
	private function on_own_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}

		return false !== strpos( (string) $screen->id, Menu::SLUG );
	}
}
