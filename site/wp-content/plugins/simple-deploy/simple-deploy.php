<?php
/**
 * Plugin Name: Simple Deploy Button
 * Description: Trigger Simply Static export and hand off deployment to a host script or trigger file.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SIMPLE_DEPLOY_STATIC_DIR')) {
    define('SIMPLE_DEPLOY_STATIC_DIR', getenv('SIMPLE_DEPLOY_STATIC_DIR') ?: '/var/www/html/static-output');
}

if (!defined('SIMPLE_DEPLOY_TRIGGER_FILE')) {
    define('SIMPLE_DEPLOY_TRIGGER_FILE', getenv('SIMPLE_DEPLOY_TRIGGER_FILE') ?: ABSPATH . 'simple-deploy.trigger');
}

$simple_deploy_script_env = getenv('SIMPLE_DEPLOY_SCRIPT') ?: '';
if (!defined('SIMPLE_DEPLOY_SCRIPT')) {
    define('SIMPLE_DEPLOY_SCRIPT', $simple_deploy_script_env);
}

add_action('admin_menu', 'simple_deploy_register_menu');
add_action('admin_post_simple_deploy_trigger', 'simple_deploy_trigger');

// Hook into Simply Static completion (different action names across versions).
add_action('ss_after_static_site_generation', 'simple_deploy_after_generation', 10, 1);
add_action('simply_static_after_generate', 'simple_deploy_after_generation', 10, 1);
add_action('simplystatic.afterGenerate', 'simple_deploy_after_generation', 10, 1);

function simple_deploy_register_menu(): void
{
    add_menu_page(
        'Deploy Site',
        'Deploy Site',
        'manage_options',
        'simple-deploy',
        'simple_deploy_page',
        'dashicons-cloud-upload',
        3
    );
}

function simple_deploy_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'simple-deploy'));
    }

    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $trigger_info = simple_deploy_trigger_info();
    $script_status = simple_deploy_script_status();
    ?>
    <div class="wrap">
        <h1>Deploy Your Website</h1>
        <p>Generate static files with Simply Static and push them to Cloudflare Pages via GitHub.</p>

        <?php if ($status === 'queued') : ?>
            <div class="notice notice-info">
                <p><strong>Deployment requested.</strong> Simply Static export has been triggered.</p>
            </div>
        <?php elseif ($status === 'done') : ?>
            <div class="notice notice-success">
                <p><strong>Static export finished.</strong> Deploy script/trigger updated.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="simple_deploy_trigger" />
            <?php wp_nonce_field('simple_deploy_nonce', 'simple_deploy_nonce'); ?>
            <p>
                <button type="submit" class="button button-primary button-hero">
                    Deploy to Live Site
                </button>
            </p>
        </form>

        <hr>
        <h2>Paths & Status</h2>
        <ul>
            <li>Static output directory (configure in Simply Static): <code><?php echo esc_html(SIMPLE_DEPLOY_STATIC_DIR); ?></code></li>
            <li>Trigger file (host can watch): <code><?php echo esc_html(SIMPLE_DEPLOY_TRIGGER_FILE); ?></code>
                <?php if ($trigger_info['exists']) : ?>
                    <em>— updated <?php echo esc_html($trigger_info['age']); ?> ago</em>
                <?php else : ?>
                    <em>— not written yet</em>
                <?php endif; ?>
            </li>
            <li>Deploy script (optional, must be executable inside container): 
                <code><?php echo esc_html($script_status['path'] ?: '(not set)'); ?></code>
                <em>— <?php echo esc_html($script_status['message']); ?></em>
            </li>
        </ul>

        <p>Workflow: click deploy → Simply Static exports → trigger file updates → host runs <code>scripts/deploy-to-github.sh</code> (manual or watcher) to push to GitHub → Cloudflare Pages redeploys.</p>
    </div>
    <?php
}

function simple_deploy_trigger(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'simple-deploy'));
    }

    check_admin_referer('simple_deploy_nonce', 'simple_deploy_nonce');

    // Kick off Simply Static generation (supports both action names used by the plugin).
    do_action('simply_static_generate');
    do_action('simplystatic.generate');

    wp_redirect(admin_url('admin.php?page=simple-deploy&status=queued'));
    exit;
}

/**
 * Callback after Simply Static finishes.
 *
 * @param string|false $archive_dir Path provided by Simply Static (may be false).
 */
function simple_deploy_after_generation($archive_dir = ''): void
{
    simple_deploy_write_trigger($archive_dir);
    $ran = simple_deploy_maybe_run_script();

    if ($ran === 'started') {
        error_log('Simple Deploy: deploy script started.');
    } elseif ($ran === 'skipped') {
        error_log('Simple Deploy: no deploy script configured; trigger written.');
    } else {
        error_log('Simple Deploy: deploy script not executed (' . $ran . ').');
    }
}

/**
 * Write trigger metadata for host-side watcher.
 *
 * @param string|false $archive_dir
 */
function simple_deploy_write_trigger($archive_dir = ''): void
{
    $payload = array(
        'generated_at_utc' => gmdate('c'),
        'static_dir'       => SIMPLE_DEPLOY_STATIC_DIR,
        'archive_dir'      => $archive_dir ?: '',
    );

    $bytes = @file_put_contents(SIMPLE_DEPLOY_TRIGGER_FILE, wp_json_encode($payload, JSON_PRETTY_PRINT));
    if ($bytes === false) {
        error_log('Simple Deploy: failed to write trigger file at ' . SIMPLE_DEPLOY_TRIGGER_FILE);
    }
}

/**
 * Attempt to run the deploy script if one is configured and executable inside the container.
 *
 * @return string status
 */
function simple_deploy_maybe_run_script(): string
{
    $script = SIMPLE_DEPLOY_SCRIPT;
    if (empty($script)) {
        return 'skipped';
    }

    if (!file_exists($script)) {
        return 'missing';
    }

    if (!is_executable($script)) {
        return 'not_executable';
    }

    $command = escapeshellcmd($script) . ' > /dev/null 2>&1 &';
    shell_exec($command);

    return 'started';
}

/**
 * Human-friendly trigger info for UI.
 *
 * @return array{exists:bool, age:string}
 */
function simple_deploy_trigger_info(): array
{
    if (!file_exists(SIMPLE_DEPLOY_TRIGGER_FILE)) {
        return array(
            'exists' => false,
            'age'    => '',
        );
    }

    $age_seconds = time() - filemtime(SIMPLE_DEPLOY_TRIGGER_FILE);
    $age = human_time_diff(time() - $age_seconds, time());

    return array(
        'exists' => true,
        'age'    => $age,
    );
}

/**
 * Status info for the optional deploy script.
 *
 * @return array{path:string, message:string}
 */
function simple_deploy_script_status(): array
{
    $path = SIMPLE_DEPLOY_SCRIPT;
    if (empty($path)) {
        return array(
            'path'    => '',
            'message' => 'not set (host watcher recommended)',
        );
    }

    if (!file_exists($path)) {
        return array(
            'path'    => $path,
            'message' => 'missing inside container',
        );
    }

    if (!is_executable($path)) {
        return array(
            'path'    => $path,
            'message' => 'found but not executable',
        );
    }

    return array(
        'path'    => $path,
        'message' => 'will be executed after export',
    );
}

