<?php
/**
 * Settings → AI assistants: turn MCP on (WPBP\Mcp\Abilities), what it needs, and the setup of each assistant.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\SettingsPage;
use WPBP\Core\Settings;
use WPBP\Mcp\Abilities;

defined( 'ABSPATH' ) || exit;

final class McpSettings {

	/** The local bridge every assistant runs: it speaks MCP to the assistant and HTTP to this site. */
	const BRIDGE = '@automattic/mcp-wordpress-remote@latest';

	public static function render(): void {
		$settings = Settings::get_all();

		if ( ! Abilities::supported() ) {
			?>
			<div class="wpbp-card">
				<h2><?php esc_html_e( 'AI assistants (MCP)', 'wrensafe' ); ?></h2>
				<p>
					<?php
					/* translators: %s: this site's WordPress version */
					echo esc_html( sprintf( __( 'Connecting AI assistants needs WordPress 6.9 or newer, which brought the Abilities API. This site runs WordPress %s. Everything else in Wrensafe works as before.', 'wrensafe' ), get_bloginfo( 'version' ) ) );
					?>
				</p>
			</div>
			<?php
			return;
		}

		if ( empty( $settings['mcp_enabled'] ) ) {
			self::render_intro();
			return;
		}

		self::render_connect();
		self::render_permissions( $settings );
	}

	/** Off: what assistants can do, and one button that turns it on (the Connection steps follow). */
	private static function render_intro(): void {
		$levels = array(
			array( 'search', __( 'Read', 'wrensafe' ), __( 'Your backup status, the backups, recent activity and updates waiting. On as soon as you turn this on.', 'wrensafe' ) ),
			array( 'archive', __( 'Actions', 'wrensafe' ), __( 'Make, verify and test backups, and check links. You decide next, with one switch.', 'wrensafe' ) ),
			array( 'restore', __( 'Requests', 'wrensafe' ), __( 'Ask you for a restore, a rollback or a Search & Replace. You approve it and run it yourself.', 'wrensafe' ) ),
		);
		?>
		<form method="post" action="options.php" class="wpbp-card wpbp-ai-intro" id="wpbp-ai-intro">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[_tab]" value="ai" />
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[mcp_enabled]" value="1" />
			<div class="wpbp-ai-intro-main">
				<p class="wpbp-ai-kicker"><?php esc_html_e( 'AI assistants · MCP', 'wrensafe' ); ?></p>
				<h2><?php esc_html_e( 'Let AI assistants work with Wrensafe', 'wrensafe' ); ?></h2>
				<p class="wpbp-ai-lead"><?php esc_html_e( 'Ask Claude, Cursor, Codex or Gemini about your backups in plain words: “Is my site backed up?”, “Test that my latest backup restores.” They work with the permissions of the account they sign in with.', 'wrensafe' ); ?></p>
				<ul class="wpbp-ai-levels">
					<?php foreach ( $levels as list( $icon, $name, $text ) ) : ?>
						<li>
							<span class="wpbp-ai-level-icon" aria-hidden="true"><?php Icons::render( $icon, 18 ); ?></span>
							<span><strong><?php echo esc_html( $name ); ?></strong> <?php echo esc_html( $text ); ?></span>
						</li>
					<?php endforeach; ?>
					<li class="is-never">
						<span class="wpbp-ai-level-icon" aria-hidden="true"><?php Icons::render( 'lock', 18 ); ?></span>
						<span><strong><?php esc_html_e( 'Never', 'wrensafe' ); ?></strong> <?php esc_html_e( 'Deleting backups. And every call is recorded in Activity Logs.', 'wrensafe' ); ?></span>
					</li>
				</ul>
				<p class="wpbp-ai-go">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Turn on AI assistants', 'wrensafe' ); ?></button>
					<span class="description"><?php esc_html_e( 'Next, connect your assistant in four steps. You can turn it off any time.', 'wrensafe' ); ?></span>
				</p>
			</div>
			<div class="wpbp-ai-intro-art" aria-hidden="true">
				<?php foreach ( array( 'claude', 'cursor', 'googlegemini', 'githubcopilot', 'windsurf', 'terminal' ) as $icon ) : ?>
					<span class="wpbp-ai-orb"><?php echo wp_kses( McpClients::icon( $icon ), Kses::allowed() ); ?></span>
				<?php endforeach; ?>
				<span class="wpbp-ai-core"><?php echo wp_kses( \WPBP\Admin\Pages\AbstractPage::BRAND_MARK_SVG, Kses::allowed() ); ?></span>
			</div>
		</form>
		<?php
	}

	/** On: the three switches, under the Connection steps. */
	private static function render_permissions( array $settings ): void {
		$name = Settings::OPTION_NAME;
		?>
		<form method="post" action="options.php" class="wpbp-card wpbp-ai-perms">
			<?php settings_fields( Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_tab]" value="ai" />
			<h2><?php esc_html_e( 'What assistants may do', 'wrensafe' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'AI assistants', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[mcp_enabled]" value="1" <?php checked( ! empty( $settings['mcp_enabled'] ) ); ?> />
							<?php esc_html_e( 'Let AI assistants use Wrensafe on this site', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'They can read the backup status, the backups, recent activity and the updates waiting. Untick and save to turn everything off. An Application Password you made for an assistant still signs in to WordPress (its whole REST API) until you revoke it under Users → Profile.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[mcp_actions]" value="1" <?php checked( ! empty( $settings['mcp_actions'] ) ); ?> />
							<?php esc_html_e( 'Also let them create and verify backups, test restores and check links', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'None of these change your site’s content. Encrypted backups stay in wp-admin: their passwords are never shared with an assistant.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Requests', 'wrensafe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[mcp_requests]" value="1" <?php checked( ! empty( $settings['mcp_requests'] ) ); ?> />
							<?php esc_html_e( 'Also let them ask for a restore, a rollback or a Search & Replace', 'wrensafe' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'You see each request on Wrensafe’s screens, approve it, and run it yourself on the usual screen. An assistant can never run these itself.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Never', 'wrensafe' ); ?></th>
					<td><p class="wpbp-mcp-never"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Deleting backups is never available to AI assistants. Every call is recorded in Activity Logs.', 'wrensafe' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** The Connection steps' script, on Settings → AI assistants only. */
	public static function enqueue( string $hook_suffix ): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which tab is shown.

		if ( false === strpos( $hook_suffix, 'wrensafe-settings' ) || 'ai' !== $tab || ! Abilities::supported() ) {
			return;
		}

		wp_enqueue_style( 'wpbp-mcp-connect', WPBP_PLUGIN_URL . 'assets/admin/css/mcp-connect.css', array( 'wpbp-admin' ), WPBP_VERSION );
		wp_style_add_data( 'wpbp-mcp-connect', 'rtl', 'replace' );

		if ( ! Abilities::enabled() ) {
			return; // Only the introduction is shown.
		}

		$clients = array();

		foreach ( McpClients::all() as $key => $client ) {
			$clients[ $key ] = array_intersect_key( $client, array_flip( array( 'name', 'setup', 'http', 'where', 'after', 'later' ) ) );
		}

		wp_enqueue_script( 'wpbp-mcp-connect', WPBP_PLUGIN_URL . 'assets/admin/js/mcp-connect.js', array( 'jquery', 'wpbp-admin' ), WPBP_VERSION, true );
		wp_localize_script(
			'wpbp-mcp-connect',
			'wpbpMcp',
			array(
				'url'     => rest_url( 'mcp/mcp-adapter-default-server' ),
				'user'    => wp_get_current_user()->user_login,
				'path'    => untrailingslashit( ABSPATH ),
				'bridge'  => self::BRIDGE,
				'adapter' => Abilities::adapter_active(),
				'clients' => $clients,
				'lastLog' => \WPBP\Core\Database\LogsRepository::max_id(),
				'i18n'    => array(
					'placeholder' => 'YOUR-APPLICATION-PASSWORD',
					/* translators: %s: AI client name */
					'create'      => __( 'Create a password for %s', 'wrensafe' ),
					'creating'    => __( 'Creating…', 'wrensafe' ),
					'created'     => __( 'Created. WordPress shows it only now: it goes into your client in the next step.', 'wrensafe' ),
					'continue'    => __( 'Continue', 'wrensafe' ),
					'change'      => __( 'Change', 'wrensafe' ),
					'password'    => __( 'Application Password', 'wrensafe' ),
					'cli'         => __( 'WP-CLI on this computer', 'wrensafe' ),
					'existing'    => __( 'Your Application Password', 'wrensafe' ),
					'direct'      => __( 'Your client connects to this site itself, with the password in the setup.', 'wrensafe' ),
					'bridge'      => __( 'A small program (run with npx, part of Node.js) connects for your client. Use it if your client can’t connect directly.', 'wrensafe' ),
					/* translators: %s: AI client name */
					'addTo'       => __( 'Add Wrensafe to %s', 'wrensafe' ),
					'testing'     => __( 'Testing…', 'wrensafe' ),
					'needPass'    => __( 'Paste the Application Password to test with (it isn’t saved).', 'wrensafe' ),
					'answers'     => __( 'This site’s MCP server answers', 'wrensafe' ),
					/* translators: %s: number of abilities */
					'abilities'   => __( '%s Wrensafe abilities are offered to your assistant', 'wrensafe' ),
					'signIn'      => __( 'The password signs in', 'wrensafe' ),
					'failed'      => __( 'It didn’t answer as expected:', 'wrensafe' ),
					'waiting'     => __( 'Waiting for your assistant…', 'wrensafe' ),
					/* translators: 1: what the assistant did, 2: time */
					'connected'   => __( 'Connected: %1$s (%2$s).', 'wrensafe' ),
				),
			)
		);
	}

	private static function render_connect(): void {
		$app     = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
		$adapter = Abilities::adapter_active();
		$steps   = array(
			1 => __( 'Choose your AI client', 'wrensafe' ),
			2 => __( 'Pick how it signs in', 'wrensafe' ),
			3 => __( 'Add Wrensafe to your client', 'wrensafe' ),
			4 => __( 'Test the connection', 'wrensafe' ),
		);
		?>
		<div class="wpbp-card wpbp-mcpc" id="wpbp-mcp-connect">
			<div class="wpbp-mcpc-head">
				<div>
					<h2><?php esc_html_e( 'Connection', 'wrensafe' ); ?></h2>
					<p><?php esc_html_e( 'Connect an AI client to this site in four steps.', 'wrensafe' ); ?></p>
				</div>
				<ul class="wpbp-mcpc-ready">
					<li class="is-ok">
						<?php
						/* translators: %s: WordPress version */
						echo esc_html( sprintf( __( 'WordPress %s', 'wrensafe' ), get_bloginfo( 'version' ) ) );
						?>
					</li>
					<li class="<?php echo $adapter ? 'is-ok' : 'is-todo'; ?>">
						<?php if ( $adapter ) : ?>
							<?php esc_html_e( 'MCP Adapter active', 'wrensafe' ); ?>
						<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) ); ?>"><?php esc_html_e( 'Install the MCP Adapter plugin', 'wrensafe' ); ?></a>
						<?php endif; ?>
					</li>
				</ul>
			</div>

			<ol class="wpbp-mcpc-steps">
				<?php foreach ( $steps as $number => $title ) : ?>
					<li class="wpbp-mcpc-step<?php echo 1 === $number ? ' is-active' : ''; ?>" data-step="<?php echo esc_attr( (string) $number ); ?>">
						<button type="button" class="wpbp-mcpc-step-head" aria-expanded="<?php echo 1 === $number ? 'true' : 'false'; ?>"<?php echo 1 === $number ? '' : ' disabled'; ?>>
							<span class="wpbp-mcpc-num" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span>
							<span class="wpbp-mcpc-title"><?php echo esc_html( $title ); ?></span>
							<span class="wpbp-mcpc-summary"></span>
							<span class="wpbp-mcpc-count">
								<?php
								/* translators: 1: step number, 2: number of steps */
								echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'wrensafe' ), $number, count( $steps ) ) );
								?>
							</span>
						</button>
						<div class="wpbp-mcpc-body"<?php echo 1 === $number ? '' : ' hidden'; ?>>
							<?php self::render_step( $number, $app, $adapter ); ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	private static function render_step( int $number, bool $app, bool $adapter ): void {
		switch ( $number ) {
			case 1:
				?>
				<div class="wpbp-mcpc-clients" role="radiogroup" aria-label="<?php esc_attr_e( 'AI client', 'wrensafe' ); ?>">
					<?php foreach ( McpClients::all() as $key => $client ) : ?>
						<button type="button" class="wpbp-mcpc-client<?php echo isset( $client['later'] ) ? ' is-later' : ''; ?>" role="radio" aria-checked="false" data-client="<?php echo esc_attr( $key ); ?>">
							<span class="wpbp-mcpc-logo"><?php echo wp_kses( McpClients::icon( $client['icon'] ), Kses::allowed() ); ?></span>
							<span><?php echo esc_html( $client['name'] ); ?></span>
							<?php if ( isset( $client['later'] ) ) : ?>
								<span class="wpbp-mcpc-tag"><?php esc_html_e( 'Later', 'wrensafe' ); ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
				<p class="wpbp-mcpc-later" role="status" hidden></p>
				<?php
				break;

			case 2:
				?>
				<?php
				$who     = wp_get_current_user()->user_login;
				$methods = array(
					'new'      => array(
						__( 'Create a new Application Password', 'wrensafe' ),
						/* translators: %s: username */
						sprintf( __( 'One click makes a password just for this client. It signs in as %s with everything that account may do in WordPress, and you can revoke it any time. Safer: sign in as a separate user with only the Wrensafe permissions it needs (Settings → Team Access).', 'wrensafe' ), $who ),
						$app,
						true,
					),
					'existing' => array(
						__( 'Use one I already have', 'wrensafe' ),
						__( 'Paste an Application Password you made before (Users → Profile). It stays in this page and isn’t saved.', 'wrensafe' ),
						$app,
						false,
					),
					'cli'      => array(
						__( 'WP-CLI on this computer', 'wrensafe' ),
						__( 'For a site on the same computer as your client (Local, MAMP, a development server) with WP-CLI installed. No password needed.', 'wrensafe' ),
						true,
						false,
					),
				);
				?>
				<div class="wpbp-mcpc-methods" role="radiogroup" aria-label="<?php esc_attr_e( 'How it signs in', 'wrensafe' ); ?>">
					<?php foreach ( $methods as $key => list( $title, $text, $available, $best ) ) : ?>
						<button type="button" class="wpbp-mcpc-method" role="radio" aria-checked="false" data-method="<?php echo esc_attr( $key ); ?>"<?php echo $available ? '' : ' disabled'; ?>>
							<span class="wpbp-mcpc-method-title"><?php echo esc_html( $title ); ?>
							<?php
							if ( $best ) :
								?>
								<span class="wpbp-mcpc-tag is-brand"><?php esc_html_e( 'Recommended', 'wrensafe' ); ?></span><?php endif; ?></span>
							<span class="wpbp-mcpc-method-text"><?php echo esc_html( $available ? $text : __( 'Not available on this site: WordPress turns Application Passwords on over HTTPS (or on a local development site).', 'wrensafe' ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="wpbp-mcpc-password" data-for="new" hidden>
					<button type="button" class="button button-primary wpbp-mcpc-create"></button>
					<div class="wpbp-mcpc-secret" hidden>
						<code id="wpbp-mcpc-secret"></code>
						<button type="button" class="button button-small wpbp-copy" data-copy-target="wpbp-mcpc-secret"><?php esc_html_e( 'Copy', 'wrensafe' ); ?></button>
						<p class="description wpbp-mcpc-secret-note"></p>
					</div>
				</div>
				<div class="wpbp-mcpc-password" data-for="existing" hidden>
					<label for="wpbp-mcpc-existing" class="wpbp-mcpc-label"><?php esc_html_e( 'Application Password', 'wrensafe' ); ?></label>
					<input type="password" id="wpbp-mcpc-existing" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
					<p class="description"><a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Manage Application Passwords', 'wrensafe' ); ?></a></p>
				</div>
				<p class="wpbp-mcpc-next" hidden><button type="button" class="button button-primary wpbp-mcpc-continue"><?php esc_html_e( 'Continue', 'wrensafe' ); ?></button></p>
				<?php
				break;

			case 3:
				?>
				<?php if ( ! $adapter ) : ?>
					<div class="wpbp-mcpc-warn">
						<?php esc_html_e( 'First install and activate the MCP Adapter plugin (by WordPress.org): it is what your client talks to.', 'wrensafe' ); ?>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) ); ?>"><?php esc_html_e( 'Find MCP Adapter', 'wrensafe' ); ?></a>
					</div>
				<?php endif; ?>
				<div class="wpbp-mcpc-transport" role="radiogroup" aria-label="<?php esc_attr_e( 'How it connects', 'wrensafe' ); ?>" hidden>
					<button type="button" role="radio" aria-checked="true" data-transport="direct"><strong><?php esc_html_e( 'Direct', 'wrensafe' ); ?></strong> <span><?php esc_html_e( 'No Node.js needed', 'wrensafe' ); ?></span></button>
					<button type="button" role="radio" aria-checked="false" data-transport="bridge"><strong><?php esc_html_e( 'Through a bridge', 'wrensafe' ); ?></strong> <span><?php esc_html_e( 'Needs Node.js', 'wrensafe' ); ?></span></button>
				</div>
				<p class="wpbp-mcpc-where"></p>
				<div class="wpbp-mcpc-code">
					<pre id="wpbp-mcpc-snippet"></pre>
					<button type="button" class="button button-small wpbp-copy" data-copy-target="wpbp-mcpc-snippet"><?php esc_html_e( 'Copy', 'wrensafe' ); ?></button>
				</div>
				<p class="wpbp-mcpc-after"></p>
				<p class="wpbp-mcpc-next"><button type="button" class="button button-primary wpbp-mcpc-continue"><?php esc_html_e( 'I’ve added it', 'wrensafe' ); ?></button></p>
				<?php
				break;

			case 4:
				?>
				<div class="wpbp-mcpc-test">
					<div class="wpbp-mcpc-test-box" data-for="password">
						<p class="wpbp-mcpc-test-title"><?php esc_html_e( 'Check from this browser', 'wrensafe' ); ?></p>
						<p class="wpbp-mcpc-test-pass" hidden>
							<label for="wpbp-mcpc-test-pass" class="description"></label>
							<input type="password" id="wpbp-mcpc-test-pass" class="regular-text" autocomplete="off" />
						</p>
						<button type="button" class="button wpbp-mcpc-run"><?php esc_html_e( 'Test the connection', 'wrensafe' ); ?></button>
						<ul class="wpbp-mcpc-results" aria-live="polite"></ul>
					</div>
					<div class="wpbp-mcpc-test-box">
						<p class="wpbp-mcpc-test-title"><?php esc_html_e( 'Then ask your assistant', 'wrensafe' ); ?></p>
						<ul class="wpbp-mcpc-prompts">
							<li><?php esc_html_e( '“Is my site backed up?”', 'wrensafe' ); ?></li>
							<li><?php esc_html_e( '“List my last five backups.”', 'wrensafe' ); ?></li>
							<li><?php esc_html_e( '“Test that my latest backup restores.”', 'wrensafe' ); ?></li>
						</ul>
						<p class="wpbp-mcpc-live" role="status"><span class="wpbp-mcpc-pulse" aria-hidden="true"></span><span class="wpbp-mcpc-live-text"></span></p>
					</div>
				</div>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe-logs&s=AI+assistant' ) ); ?>"><?php esc_html_e( 'See what assistants did in Activity Logs', 'wrensafe' ); ?></a></p>
				<?php
				break;
		}
	}
}
