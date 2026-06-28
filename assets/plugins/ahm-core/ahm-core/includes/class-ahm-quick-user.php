<?php
/**
 * Quick User Create tab.
 *
 * Extracted from AHM Core v1 — logic is identical,
 * now rendered inside the tabbed admin page.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_Quick_User
{
    private static ?self $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        // Render tab content.
        add_action('ahm_tab_content_quick-user', [$this, 'render_tab']);

        // Handle form submission.
        add_action('admin_post_ahm_core_create_user', [$this, 'handle_create_user']);
    }

    /*--------------------------------------------------------------
     * Tab Renderer
     *------------------------------------------------------------*/

    public function render_tab(): void
    {
        if (! current_user_can('create_users')) {
            echo '<p>' . esc_html__('You do not have permission to create users.', 'ahm-core') . '</p>';
            return;
        }

        // Notices.
        if (isset($_GET['ahm_msg'])) {
            $msg     = sanitize_text_field(wp_unslash($_GET['ahm_msg']));
            $notices = [
                'created'     => ['success', __('User created. Credentials have been emailed to the user.', 'ahm-core')],
                'missing'     => ['error',   __('Username and email are both required.', 'ahm-core')],
                'bademail'    => ['error',   __('Please enter a valid email address.', 'ahm-core')],
                'userexists'  => ['error',   __('That username already exists.', 'ahm-core')],
                'emailexists' => ['error',   __('That email address is already registered.', 'ahm-core')],
                'error'       => ['error',   __('The user could not be created. Please try again.', 'ahm-core')],
            ];

            if (isset($notices[$msg])) {
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr($notices[$msg][0]),
                    esc_html($notices[$msg][1])
                );
            }
        }

        $default_role = get_option('default_role', 'subscriber');
        ?>
        <div class="ahm-card">
            <h2><?php esc_html_e('Quick User Create', 'ahm-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Create a new user quickly. First name is set to the username, last name is set to "ahm", a secure password is generated automatically, and the credentials are emailed to the user.', 'ahm-core'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ahm_core_create_user" />
                <?php wp_nonce_field('ahm_core_create_user', 'ahm_core_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ahm_username"><?php esc_html_e('Username', 'ahm-core'); ?></label></th>
                        <td><input name="ahm_username" id="ahm_username" type="text" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ahm_email"><?php esc_html_e('Email', 'ahm-core'); ?></label></th>
                        <td><input name="ahm_email" id="ahm_email" type="email" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ahm_role"><?php esc_html_e('Role', 'ahm-core'); ?></label></th>
                        <td>
                            <select name="ahm_role" id="ahm_role">
                                <?php wp_dropdown_roles($default_role); ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Create User', 'ahm-core')); ?>
            </form>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * Form Handler
     *------------------------------------------------------------*/

    public function handle_create_user(): void
    {
        if (! current_user_can('create_users')) {
            wp_die(esc_html__('You do not have permission to create users.', 'ahm-core'));
        }

        check_admin_referer('ahm_core_create_user', 'ahm_core_nonce');

        $redirect = admin_url('admin.php?page=ahm-core&tab=quick-user');

        $username = isset($_POST['ahm_username'])
            ? sanitize_user(wp_unslash($_POST['ahm_username']), true) : '';
        $email    = isset($_POST['ahm_email'])
            ? sanitize_email(wp_unslash($_POST['ahm_email'])) : '';
        $role     = isset($_POST['ahm_role'])
            ? sanitize_text_field(wp_unslash($_POST['ahm_role'])) : '';

        // Validate role.
        $valid_roles = array_keys(wp_roles()->get_names());
        if (! in_array($role, $valid_roles, true)) {
            $role = get_option('default_role', 'subscriber');
        }

        if ('' === $username || '' === $email) {
            $this->redirect_with('missing', $redirect);
        }
        if (! is_email($email)) {
            $this->redirect_with('bademail', $redirect);
        }
        if (username_exists($username)) {
            $this->redirect_with('userexists', $redirect);
        }
        if (email_exists($email)) {
            $this->redirect_with('emailexists', $redirect);
        }

        $password = wp_generate_password(16, true, false);

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'first_name' => $username,
            'last_name'  => 'ahm',
            'role'       => $role,
        ]);

        if (is_wp_error($user_id)) {
            $this->redirect_with('error', $redirect);
        }

        $this->send_credentials($email, $username, $password);
        $this->redirect_with('created', $redirect);
    }

    /*--------------------------------------------------------------
     * Helpers
     *------------------------------------------------------------*/

    private function redirect_with(string $code, string $redirect): void
    {
        wp_safe_redirect(add_query_arg('ahm_msg', $code, $redirect));
        exit;
    }

    private function send_credentials(string $email, string $username, string $password): bool
    {
        $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $login_url = wp_login_url();

        $subject = sprintf(__('[%s] Your new account details', 'ahm-core'), $site_name);

        $message  = sprintf(__('An account has been created for you on %s.', 'ahm-core'), $site_name) . "\r\n\r\n";
        $message .= sprintf(__('Username: %s', 'ahm-core'), $username) . "\r\n";
        $message .= sprintf(__('Password: %s', 'ahm-core'), $password) . "\r\n\r\n";
        $message .= sprintf(__('Log in here: %s', 'ahm-core'), $login_url) . "\r\n\r\n";
        $message .= __('For your security, please change your password after your first login.', 'ahm-core') . "\r\n";

        return wp_mail($email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
