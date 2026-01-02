<?php
/**
 * Plugin Name: Simple Deploy Button
 * Description: One-click static export via Simply Static and direct upload to Cloudflare Pages.
 * Version: 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Configuration from environment
define('SIMPLE_DEPLOY_STATIC_DIR', getenv('SIMPLE_DEPLOY_STATIC_DIR') ?: '/var/www/html/static-output');
define('CLOUDFLARE_ACCOUNT_ID', getenv('CLOUDFLARE_ACCOUNT_ID') ?: '');
define('CLOUDFLARE_API_TOKEN', getenv('CLOUDFLARE_API_TOKEN') ?: '');
define('CLOUDFLARE_PAGES_PROJECT', getenv('CLOUDFLARE_PAGES_PROJECT') ?: '');

add_action('admin_menu', 'simple_deploy_register_menu');
add_action('admin_post_simple_deploy_trigger', 'simple_deploy_trigger');

// Hook into Simply Static completion
add_action('ss_completed', 'simple_deploy_after_generation', 10, 1);

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
    $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
    $cf_configured = !empty(CLOUDFLARE_ACCOUNT_ID) && !empty(CLOUDFLARE_API_TOKEN) && !empty(CLOUDFLARE_PAGES_PROJECT);
    ?>
    <div class="wrap">
        <h1>Deploy Your Website</h1>
        <p>Generate static files with Simply Static and upload directly to Cloudflare Pages.</p>

        <?php if ($status === 'queued') : ?>
            <div class="notice notice-info">
                <p><strong>Export started.</strong> Simply Static is generating files. Cloudflare upload will happen automatically when done.</p>
            </div>
        <?php elseif ($status === 'error') : ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> <?php echo esc_html($message ?: 'Export did not start.'); ?></p>
            </div>
        <?php elseif ($status === 'deployed') : ?>
            <div class="notice notice-success">
                <p><strong>Deployed!</strong> Site uploaded to Cloudflare Pages successfully.</p>
            </div>
        <?php endif; ?>

        <?php if (!$cf_configured) : ?>
            <div class="notice notice-warning">
                <p><strong>Cloudflare not configured.</strong> Set these environment variables:</p>
                <ul>
                    <li><code>CLOUDFLARE_ACCOUNT_ID</code> — from Cloudflare dashboard URL</li>
                    <li><code>CLOUDFLARE_API_TOKEN</code> — with "Cloudflare Pages: Edit" permission</li>
                    <li><code>CLOUDFLARE_PAGES_PROJECT</code> — your Pages project name</li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="simple_deploy_trigger" />
            <?php wp_nonce_field('simple_deploy_nonce', 'simple_deploy_nonce'); ?>
            <p>
                <button type="submit" class="button button-primary button-hero" <?php echo $cf_configured ? '' : 'disabled'; ?>>
                    Deploy to Cloudflare Pages
                </button>
            </p>
        </form>

        <hr>
        <h2>Configuration</h2>
        <table class="form-table">
            <tr>
                <th>Static output directory</th>
                <td><code><?php echo esc_html(SIMPLE_DEPLOY_STATIC_DIR); ?></code></td>
            </tr>
            <tr>
                <th>Cloudflare Account ID</th>
                <td><code><?php echo CLOUDFLARE_ACCOUNT_ID ? '✓ Set' : '✗ Not set'; ?></code></td>
            </tr>
            <tr>
                <th>Cloudflare API Token</th>
                <td><code><?php echo CLOUDFLARE_API_TOKEN ? '✓ Set' : '✗ Not set'; ?></code></td>
            </tr>
            <tr>
                <th>Cloudflare Pages Project</th>
                <td><code><?php echo esc_html(CLOUDFLARE_PAGES_PROJECT ?: '✗ Not set'); ?></code></td>
            </tr>
        </table>
        
        <p><strong>Note:</strong> Configure Simply Static to export into a folder (this directory) rather than ZIP.</p>
    </div>
    <?php
}

function simple_deploy_trigger(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'simple-deploy'));
    }

    check_admin_referer('simple_deploy_nonce', 'simple_deploy_nonce');

    if (empty(CLOUDFLARE_ACCOUNT_ID) || empty(CLOUDFLARE_API_TOKEN) || empty(CLOUDFLARE_PAGES_PROJECT)) {
        wp_redirect(admin_url('admin.php?page=simple-deploy&status=error&message=' . urlencode('Cloudflare not configured')));
        exit;
    }

    $started = false;
    if (class_exists('\\Simply_Static\\Plugin')) {
        $started = \Simply_Static\Plugin::instance()->run_static_export(0, 'export');
    }

    $status = $started ? 'queued' : 'error';
    $message = $started ? '' : 'Simply Static export did not start';
    wp_redirect(admin_url('admin.php?page=simple-deploy&status=' . $status . ($message ? '&message=' . urlencode($message) : '')));
    exit;
}

/**
 * After Simply Static finishes, upload folder output to Cloudflare Pages.
 */
function simple_deploy_after_generation($status = ''): void
{
    error_log('Simple Deploy: Hook fired! Status: ' . var_export($status, true));
    
    if ($status !== 'success' && $status !== '') {
        error_log('Simple Deploy: Simply Static finished with non-success status: ' . $status);
        return;
    }
    
    error_log('Simple Deploy: Proceeding with upload...');

    if (empty(CLOUDFLARE_ACCOUNT_ID) || empty(CLOUDFLARE_API_TOKEN) || empty(CLOUDFLARE_PAGES_PROJECT)) {
        error_log('Simple Deploy: Cloudflare not configured, skipping upload');
        return;
    }

    $static_dir = SIMPLE_DEPLOY_STATIC_DIR;
    if (!is_dir($static_dir)) {
        error_log('Simple Deploy: Static output dir not found: ' . $static_dir);
        return;
    }

    if (!is_file($static_dir . '/index.html')) {
        error_log('Simple Deploy: index.html not found in static output dir: ' . $static_dir);
        return;
    }

    error_log('Simple Deploy: Using static output dir: ' . $static_dir);
    error_log('Simple Deploy: Starting Cloudflare Pages upload...');

    $result = simple_deploy_upload_to_cloudflare($static_dir);
    
    if (is_wp_error($result)) {
        error_log('Simple Deploy: Upload failed - ' . $result->get_error_message());
    } else {
        error_log('Simple Deploy: Upload successful! Deployment URL: ' . ($result['url'] ?? 'unknown'));
    }
}

/**
 * Upload files to Cloudflare Pages Direct Upload API.
 */
function simple_deploy_upload_to_cloudflare(string $dir)
{
    $account_id = CLOUDFLARE_ACCOUNT_ID;
    $api_token = CLOUDFLARE_API_TOKEN;
    $project = CLOUDFLARE_PAGES_PROJECT;

    $script = __DIR__ . '/bin/deploy-pages.sh';
    if (!file_exists($script)) {
        return new WP_Error('script_missing', 'Wrangler deploy script not found: ' . $script);
    }

    if (!is_executable($script)) {
        return new WP_Error('script_not_executable', 'Wrangler deploy script is not executable: ' . $script);
    }

    $cmd = escapeshellcmd($script) . ' '
        . escapeshellarg($dir) . ' '
        . escapeshellarg($account_id) . ' '
        . escapeshellarg($api_token) . ' '
        . escapeshellarg($project);

    $descriptor = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($cmd, $descriptor, $pipes, ABSPATH);
    if (!is_resource($process)) {
        return new WP_Error('proc_open_failed', 'Failed to execute deploy script.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    $combined_output = trim($stdout . "\n" . $stderr);
    error_log('Simple Deploy: Wrangler output: ' . $combined_output);

    if ($exit_code !== 0) {
        return new WP_Error('wrangler_failed', 'Wrangler deploy failed (exit ' . $exit_code . '): ' . $combined_output);
    }

    if (preg_match('/DEPLOYMENT_URL=(\S+)/', $combined_output, $match)) {
        return [
            'url' => $match[1],
            'id' => basename(parse_url($match[1], PHP_URL_HOST)),
        ];
    }

    return new WP_Error('wrangler_no_url', 'Wrangler completed but did not output DEPLOYMENT_URL.');
}


