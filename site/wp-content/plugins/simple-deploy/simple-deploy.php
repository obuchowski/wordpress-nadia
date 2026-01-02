<?php
/**
 * Plugin Name: Simple Deploy Button
 * Description: One-click static export via Simply Static and direct upload to Cloudflare Pages.
 * Version: 2.0.0
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
    </div>
    <?php
}

function simple_deploy_trigger(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'simple-deploy'));
    }

    check_admin_referer('simple_deploy_nonce', 'simple_deploy_nonce');

    // Check Cloudflare config
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
 * After Simply Static finishes, upload to Cloudflare Pages.
 */
function simple_deploy_after_generation($status = ''): void
{
    error_log('Simple Deploy: Hook fired! Status: ' . var_export($status, true));
    
    // Only proceed on success
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
    if (!is_dir($static_dir) || count(scandir($static_dir)) <= 2) {
        error_log('Simple Deploy: Static directory empty or not found: ' . $static_dir);
        return;
    }

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
 *
 * @param string $dir Directory containing static files
 * @return array|WP_Error Result with deployment info or error
 */
function simple_deploy_upload_to_cloudflare(string $dir)
{
    $account_id = CLOUDFLARE_ACCOUNT_ID;
    $api_token = CLOUDFLARE_API_TOKEN;
    $project = CLOUDFLARE_PAGES_PROJECT;
    
    // Collect all files with their hashes
    $files = simple_deploy_get_files_recursive($dir);
    if (empty($files)) {
        return new WP_Error('no_files', 'No files to upload');
    }
    
    error_log('Simple Deploy: Found ' . count($files) . ' files to upload');

    // Build manifest (path -> hash)
    $manifest = [];
    $file_contents = [];
    foreach ($files as $file_path => $full_path) {
        $content = file_get_contents($full_path);
        if ($content === false) continue;
        
        $normalized_path = '/' . ltrim(str_replace('\\', '/', $file_path), '/');
        $hash = hash('sha256', $content);
        $manifest[$normalized_path] = $hash;
        $file_contents[$hash] = [
            'path' => $normalized_path,
            'content' => $content,
            'full_path' => $full_path,
        ];
    }

    // Step 1: Create deployment with manifest
    $create_url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/pages/projects/{$project}/deployments";
    
    $boundary = wp_generate_password(24, false);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"manifest\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= json_encode($manifest) . "\r\n";
    $body .= "--{$boundary}--\r\n";
    
    $response = wp_remote_post($create_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $data = json_decode($resp_body, true);
    
    if ($code !== 200 || empty($data['success'])) {
        $error_msg = $data['errors'][0]['message'] ?? $resp_body;
        return new WP_Error('cf_error', "Cloudflare API error (create): {$error_msg} (HTTP {$code})");
    }
    
    $deployment_id = $data['result']['id'] ?? '';
    $missing_hashes = $data['result']['missing_hashes'] ?? [];
    
    error_log('Simple Deploy: Deployment created: ' . $deployment_id . ', need to upload ' . count($missing_hashes) . ' files');
    
    // Step 2: Upload missing files
    if (!empty($missing_hashes)) {
        $upload_url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/pages/projects/{$project}/deployments/{$deployment_id}/files";
        
        $boundary2 = wp_generate_password(24, false);
        $upload_body = '';
        
        foreach ($missing_hashes as $hash) {
            if (!isset($file_contents[$hash])) continue;
            
            $file_info = $file_contents[$hash];
            $upload_body .= "--{$boundary2}\r\n";
            $upload_body .= "Content-Disposition: form-data; name=\"files\"; filename=\"{$hash}\"\r\n";
            $upload_body .= "Content-Type: " . simple_deploy_get_mime_type($file_info['full_path']) . "\r\n\r\n";
            $upload_body .= $file_info['content'] . "\r\n";
        }
        $upload_body .= "--{$boundary2}--\r\n";
        
        $upload_response = wp_remote_post($upload_url, [
            'timeout' => 300,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary2,
            ],
            'body' => $upload_body,
        ]);
        
        if (is_wp_error($upload_response)) {
            return $upload_response;
        }
        
        $upload_code = wp_remote_retrieve_response_code($upload_response);
        $upload_resp_body = wp_remote_retrieve_body($upload_response);
        $upload_data = json_decode($upload_resp_body, true);
        
        if ($upload_code !== 200 || empty($upload_data['success'])) {
            $error_msg = $upload_data['errors'][0]['message'] ?? $upload_resp_body;
            return new WP_Error('cf_error', "Cloudflare API error (upload): {$error_msg} (HTTP {$upload_code})");
        }
        
        error_log('Simple Deploy: Files uploaded successfully');
    }
    
    $deploy_url = $data['result']['url'] ?? "https://{$project}.pages.dev";
    error_log('Simple Deploy: Deployment complete! URL: ' . $deploy_url);
    
    return [
        'url' => $deploy_url,
        'id' => $deployment_id,
    ];
}

/**
 * Recursively get all files in a directory.
 *
 * @param string $dir Base directory
 * @param string $prefix Path prefix for recursion
 * @return array [relative_path => full_path]
 */
function simple_deploy_get_files_recursive(string $dir, string $prefix = ''): array
{
    $files = [];
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $full_path = $dir . DIRECTORY_SEPARATOR . $item;
        $relative_path = $prefix ? $prefix . '/' . $item : $item;
        
        if (is_dir($full_path)) {
            $files = array_merge($files, simple_deploy_get_files_recursive($full_path, $relative_path));
        } else {
            $files[$relative_path] = $full_path;
        }
    }
    
    return $files;
}

/**
 * Get MIME type for a file.
 */
function simple_deploy_get_mime_type(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf',
    ];
    return $types[$ext] ?? 'application/octet-stream';
}

