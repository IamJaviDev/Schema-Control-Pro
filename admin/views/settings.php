<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap scm-wrap">
    <h1><?php esc_html_e( 'Settings', 'schema-control-pro' ); ?></h1>
    <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'schema-control-pro' ); ?></p></div><?php endif; ?>
    <div class="scm-card scm-card-full">
        <form method="post">
            <?php wp_nonce_field( 'scm_save_settings' ); ?>
            <table class="form-table">
                <tr><th>AIOSEO integration</th><td><label><input type="checkbox" name="aioseo_integration_enabled" value="1" <?php checked( ! empty( $settings['aioseo_integration_enabled'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Pretty print JSON</th><td><label><input type="checkbox" name="pretty_print_json" value="1" <?php checked( ! empty( $settings['pretty_print_json'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Strip empty values</th><td><label><input type="checkbox" name="strip_empty_values" value="1" <?php checked( ! empty( $settings['strip_empty_values'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Auto add @context</th><td><label><input type="checkbox" name="auto_add_context" value="1" <?php checked( ! empty( $settings['auto_add_context'] ) ); ?>> Enabled</label><p class="description">Allows partial node input and always normalizes output to JSON-LD.</p></td></tr>
                <tr><th>Auto wrap with @graph</th><td><label><input type="checkbox" name="auto_wrap_graph" value="1" <?php checked( ! empty( $settings['auto_wrap_graph'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Warn on structural node without @id</th><td><label><input type="checkbox" name="warn_on_structural_without_id" value="1" <?php checked( ! empty( $settings['warn_on_structural_without_id'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Enable graph diagnostics</th><td><label><input type="checkbox" name="enable_graph_diagnostics" value="1" <?php checked( ! empty( $settings['enable_graph_diagnostics'] ) ); ?>> Enabled</label></td></tr>
                <tr><th>Debug mode</th><td><label><input type="checkbox" name="debug_mode" value="1" <?php checked( ! empty( $settings['debug_mode'] ) ); ?>> Enabled</label></td></tr>
                <tr>
                    <th><?php esc_html_e( 'Delete data on uninstall', 'schema-control-pro' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Enabled', 'schema-control-pro' ); ?></label>
                        <p class="description"><?php esc_html_e( 'When checked, all rules, schemas, and settings are permanently deleted when the plugin is uninstalled. Disabled by default to preserve data.', 'schema-control-pro' ); ?></p>
                    </td>
                </tr>
                <tr><th><label for="conflict_types_default">Conflict types</label></th><td><input class="large-text" type="text" id="conflict_types_default" name="conflict_types_default" value="<?php echo esc_attr( implode( ', ', (array) ( $settings['conflict_types_default'] ?? array() ) ) ); ?>"><p class="description">Comma separated list used in the rule editor.</p></td></tr>
                <tr>
                    <th><label for="preview_language">Preview language</label></th>
                    <td>
                        <select name="preview_language" id="preview_language">
                            <option value="en" <?php selected( ( $settings['preview_language'] ?? 'en' ), 'en' ); ?>>English</option>
                            <option value="es" <?php selected( ( $settings['preview_language'] ?? 'en' ), 'es' ); ?>>Español</option>
                        </select>
                        <p class="description">Language for the Final Graph Preview panel labels in the rule editor.</p>
                    </td>
                </tr>
            </table>
            <p><button class="button button-primary" name="scm_save_settings" value="1">Save Settings</button></p>
        </form>
    </div>
</div>
