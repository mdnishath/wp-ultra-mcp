<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

add_action('admin_post_wpultra_save_abilities', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_save_abilities')) { wp_die('forbidden'); }
    $disabled = array_map('sanitize_text_field', (array) ($_POST['disabled'] ?? []));
    $rules = [];
    foreach ($disabled as $name) { $rules['wpultra/' . $name] = ['disabled' => true]; }
    update_option('wpultra_ability_rules', $rules);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-abilities&saved=1'));
    exit;
});

function wpultra_abilities_render(): void {
    $rules = (array) get_option('wpultra_ability_rules', []);
    echo '<div class="wrap"><h1>Abilities</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wpultra_save_abilities'); echo '<input type="hidden" name="action" value="wpultra_save_abilities"><ul>';
    foreach (wpultra_ability_files() as $slug) {
        $checked = empty($rules['wpultra/' . $slug]['disabled']) ? '' : 'checked';
        echo '<li><label><input type="checkbox" name="disabled[]" value="' . esc_attr($slug) . '" ' . $checked . '> Disable <code>wpultra/' . esc_html($slug) . '</code></label></li>';
    }
    echo '</ul><button class="button button-primary">Save</button></form></div>';
}
