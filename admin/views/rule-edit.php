<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap scm-wrap">
    <h1><?php echo $rule['id'] ? esc_html__( 'Edit Rule', 'schema-control-manager' ) : esc_html__( 'Add Rule', 'schema-control-manager' ); ?></h1>

    <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Rule saved.', 'schema-control-manager' ); ?></p></div><?php endif; ?>
    <?php if ( ! empty( $_GET['schema_updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Schema saved.', 'schema-control-manager' ); ?></p></div><?php endif; ?>
    <?php if ( ! empty( $_GET['schema_deleted'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Schema deleted.', 'schema-control-manager' ); ?></p></div><?php endif; ?>
    <?php if ( ! empty( $_GET['schema_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['schema_error'] ) ); ?></p></div><?php endif; ?>

    <?php // ── Runtime notices from last frontend page load ───────────────── ?>
    <?php if ( ! empty( $runtime_notices ) ) : ?>
        <div class="notice scm-notice-runtime">
            <p><strong><?php esc_html_e( 'Runtime issue detected on last frontend render', 'schema-control-manager' ); ?></strong>
            <?php if ( ! empty( $runtime_notices['rule_label'] ) ) : ?>
                (<?php echo esc_html( $runtime_notices['rule_label'] ); ?>)
            <?php endif; ?>
            <?php if ( ! empty( $runtime_notices['time'] ) ) : ?>
                &mdash; <?php echo esc_html( $runtime_notices['time'] ); ?>
            <?php endif; ?>
            </p>
            <?php if ( ! empty( $runtime_notices['errors'] ) ) : ?>
                <ul class="scm-warning-list scm-error-list">
                    <?php foreach ( $runtime_notices['errors'] as $err ) : ?>
                        <li><?php echo esc_html( $err ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ( ! empty( $runtime_notices['warnings'] ) ) : ?>
                <ul class="scm-warning-list scm-structural-warning-list">
                    <?php foreach ( $runtime_notices['warnings'] as $w ) : ?>
                        <li><?php echo esc_html( $w ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // ── Rule-level diagnostics (all schemas combined) ─────────────── ?>
    <?php if ( ! empty( $rule_diagnostics ) ) : ?>
        <?php $has_rule_issues = ! empty( $rule_diagnostics['errors'] ) || ! empty( $rule_diagnostics['warnings'] ); ?>
        <?php if ( $has_rule_issues ) : ?>
            <div class="notice <?php echo ! empty( $rule_diagnostics['errors'] ) ? 'notice-error' : 'notice-warning'; ?> scm-notice-rule-diag">
                <p><strong><?php esc_html_e( 'Rule-level diagnostics (all schemas combined)', 'schema-control-manager' ); ?></strong></p>
                <?php if ( ! empty( $rule_diagnostics['errors'] ) ) : ?>
                    <ul class="scm-warning-list scm-error-list">
                        <?php foreach ( $rule_diagnostics['errors'] as $err ) : ?>
                            <li><?php echo esc_html( $err ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( ! empty( $rule_diagnostics['structural_warnings'] ) ) : ?>
                    <ul class="scm-warning-list scm-structural-warning-list">
                        <?php foreach ( $rule_diagnostics['structural_warnings'] as $w ) : ?>
                            <li><?php echo esc_html( $w ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( ! empty( $rule_diagnostics['semantic_warnings'] ) ) : ?>
                    <ul class="scm-warning-list scm-semantic-warning-list">
                        <?php foreach ( $rule_diagnostics['semantic_warnings'] as $w ) : ?>
                            <li><?php echo esc_html( $w ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="scm-grid scm-grid-top">
        <div class="scm-card">
            <h2><?php esc_html_e( 'Rule', 'schema-control-manager' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'scm_save_rule' ); ?>
                <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="label"><?php esc_html_e( 'Label', 'schema-control-manager' ); ?></label></th>
                        <td><input class="regular-text" type="text" name="label" id="label" value="<?php echo esc_attr( $rule['label'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="target_type"><?php esc_html_e( 'Target type', 'schema-control-manager' ); ?></label></th>
                        <td>
                            <select name="target_type" id="target_type">
                                <option value="home" <?php selected( $rule['target_type'], 'home' ); ?>>Home</option>
                                <option value="exact_url" <?php selected( $rule['target_type'], 'exact_url' ); ?>>Exact URL</option>
                                <option value="exact_slug" <?php selected( $rule['target_type'], 'exact_slug' ); ?>>Exact slug</option>
                                <option value="author" <?php selected( $rule['target_type'], 'author' ); ?>>Author page</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="scm-target-value-row">
                        <th><label for="target_value"><?php esc_html_e( 'Target value', 'schema-control-manager' ); ?></label></th>
                        <td>
                            <input class="regular-text" type="text" name="target_value" id="target_value" value="<?php echo esc_attr( $rule['target_value'] ); ?>">
                            <p class="description" id="scm-target-help"><?php esc_html_e( 'Home does not need a target value. For author pages, use the user_nicename.', 'schema-control-manager' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mode"><?php esc_html_e( 'Mode', 'schema-control-manager' ); ?></label></th>
                        <td>
                            <select name="mode" id="mode">
                                <option value="aioseo_only" <?php selected( $rule['mode'], 'aioseo_only' ); ?>>AIOSEO only</option>
                                <option value="aioseo_plus_custom" <?php selected( $rule['mode'], 'aioseo_plus_custom' ); ?>>AIOSEO + Custom</option>
                                <option value="custom_override_selected" <?php selected( $rule['mode'], 'custom_override_selected' ); ?>>Custom override selected types</option>
                                <option value="custom_only" <?php selected( $rule['mode'], 'custom_only' ); ?>>Custom only</option>
                            </select>
                            <p class="description" id="scm-mode-help"></p>
                        </td>
                    </tr>
                    <tr class="scm-replaced-types-row">
                        <th><?php esc_html_e( 'Replaced types', 'schema-control-manager' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Use this only for structural replacements or specific overrides. Structural types such as BreadcrumbList, Person and Organization may require @id rewiring.', 'schema-control-manager' ); ?></p>
                            <?php $types = $settings['conflict_types_default'] ?? array(); ?>
                            <?php foreach ( $types as $type ) : ?>
                                <label class="scm-inline"><input type="checkbox" name="replaced_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $rule['replaced_types'], true ) ); ?>> <?php echo esc_html( $type ); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'schema-control-manager' ); ?></th>
                        <td><label><input type="checkbox" name="is_active" value="1" <?php checked( ! empty( $rule['is_active'] ) ); ?>> <?php esc_html_e( 'Active', 'schema-control-manager' ); ?></label></td>
                    </tr>
                </table>
                <p><button class="button button-primary" name="scm_save_rule" value="1"><?php esc_html_e( 'Save Rule', 'schema-control-manager' ); ?></button></p>
            </form>
        </div>

        <div class="scm-card">
            <h2><?php esc_html_e( 'Rule summary', 'schema-control-manager' ); ?></h2>
            <ul class="scm-summary-list">
                <li><strong><?php esc_html_e( 'AIOSEO', 'schema-control-manager' ); ?>:</strong> <span id="scm-summary-aioseo"><?php echo esc_html( $rule_summary['aioseo_status'] ); ?></span></li>
                <li><strong><?php esc_html_e( 'Custom schemas', 'schema-control-manager' ); ?>:</strong> <span id="scm-summary-custom-count"><?php echo esc_html( $rule_summary['custom_count'] ); ?></span></li>
                <li><strong><?php esc_html_e( 'Replacements', 'schema-control-manager' ); ?>:</strong> <span id="scm-summary-replacements"><?php echo esc_html( empty( $rule_summary['replacements'] ) ? '—' : implode( ', ', $rule_summary['replacements'] ) ); ?></span></li>
                <li><strong><?php esc_html_e( 'Expected output', 'schema-control-manager' ); ?>:</strong> <span id="scm-summary-output"><?php echo esc_html( $rule_summary['output'] ); ?></span></li>
            </ul>
            <div class="scm-help-box">
                <p><strong><?php esc_html_e( 'Usage hints', 'schema-control-manager' ); ?></strong></p>
                <ul>
                    <li><?php esc_html_e( 'Use AIOSEO + Custom for additive schemas like FAQPage, HowTo or Service.', 'schema-control-manager' ); ?></li>
                    <li><?php esc_html_e( 'Use Override when replacing structural nodes such as BreadcrumbList, Person or Organization.', 'schema-control-manager' ); ?></li>
                    <li><?php esc_html_e( 'Use Custom only when you want full control of the page graph.', 'schema-control-manager' ); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="scm-card">
        <h2><?php esc_html_e( 'Schemas attached to this rule', 'schema-control-manager' ); ?></h2>
        <?php if ( ! $rule['id'] ) : ?>
            <p><?php esc_html_e( 'Save the rule first to attach schemas.', 'schema-control-manager' ); ?></p>
        <?php elseif ( empty( $schemas ) ) : ?>
            <p><?php esc_html_e( 'No schemas yet.', 'schema-control-manager' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Label</th><th>Type</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $schemas as $schema ) : ?>
                    <tr>
                        <td><?php echo esc_html( $schema['label'] ); ?></td>
                        <td><?php echo esc_html( $schema['schema_type'] ); ?></td>
                        <td><?php echo esc_html( $schema['priority'] ); ?></td>
                        <td><?php echo ! empty( $schema['is_active'] ) ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . (int) $rule['id'] . '&schema_id=' . (int) $schema['id'] ) ); ?>">Edit</a>
                            <a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . (int) $rule['id'] . '&scm_delete_schema=' . (int) $schema['id'] ), 'scm_delete_schema_' . (int) $schema['id'] ) ); ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ( $rule['id'] ) : ?>
    <div class="scm-card scm-card-full">
        <h2><?php echo $edit_schema['id'] ? esc_html__( 'Edit Schema', 'schema-control-manager' ) : esc_html__( 'Add Schema', 'schema-control-manager' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'scm_save_schema' ); ?>
            <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>">
            <input type="hidden" name="schema_id" value="<?php echo esc_attr( $edit_schema['id'] ); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="schema_label">Label</label></th>
                    <td><input class="regular-text" type="text" name="schema_label" id="schema_label" value="<?php echo esc_attr( $edit_schema['label'] ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="schema_type">Schema type</label></th>
                    <td>
                        <input class="regular-text" type="text" name="schema_type" id="schema_type" value="<?php echo esc_attr( $edit_schema['schema_type'] ); ?>">
                        <?php if ( 'author' === $rule['target_type'] ) : ?>
                            <p class="description"><?php esc_html_e( 'For author pages, Person usually behaves as a structural node and should keep a stable @id when possible.', 'schema-control-manager' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="priority">Priority</label></th>
                    <td><input class="small-text" type="number" name="priority" id="priority" value="<?php echo esc_attr( $edit_schema['priority'] ); ?>"></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><label><input type="checkbox" name="schema_is_active" value="1" <?php checked( ! empty( $edit_schema['is_active'] ) ); ?>> Active</label></td>
                </tr>
                <tr>
                    <th><label for="schema_json">Schema JSON-LD</label></th>
                    <td>
                        <textarea class="large-text code scm-json-editor" rows="18" name="schema_json" id="schema_json" required><?php echo esc_textarea( $edit_schema['schema_json'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'You can paste a full JSON-LD object, a list of nodes, or an object with @graph. The plugin normalizes to a final @context + @graph structure.', 'schema-control-manager' ); ?></p>
                        <p><button type="button" class="button" id="scm-validate-json">Validate JSON</button> <span id="scm-json-status"></span></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Normalized preview', 'schema-control-manager' ); ?></th>
                    <td><pre class="scm-preview" id="scm-json-preview"><?php echo esc_html( ! empty( $diagnostics['normalized'] ) ? wp_json_encode( $diagnostics['normalized'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '' ); ?></pre></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Diagnostics', 'schema-control-manager' ); ?></th>
                    <td>
                        <div class="scm-diagnostics">
                            <p><strong><?php esc_html_e( 'Node count:', 'schema-control-manager' ); ?></strong> <?php echo esc_html( $diagnostics['node_count'] ); ?></p>
                            <p><strong><?php esc_html_e( 'Detected types:', 'schema-control-manager' ); ?></strong> <?php echo esc_html( empty( $diagnostics['types'] ) ? '—' : implode( ', ', $diagnostics['types'] ) ); ?></p>
                            <p><strong><?php esc_html_e( 'Domains in @id:', 'schema-control-manager' ); ?></strong> <?php echo esc_html( empty( $diagnostics['domains'] ) ? '—' : implode( ', ', $diagnostics['domains'] ) ); ?></p>

                            <?php // ── Critical errors ───────────────────────────────────────── ?>
                            <?php if ( ! empty( $diagnostics['errors'] ) ) : ?>
                                <p class="scm-severity-label scm-severity-critical"><?php esc_html_e( 'Critical errors', 'schema-control-manager' ); ?></p>
                                <ul class="scm-warning-list scm-error-list">
                                    <?php foreach ( $diagnostics['errors'] as $error ) : ?>
                                        <li><?php echo esc_html( $error ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php // ── Structural warnings ───────────────────────────────────── ?>
                            <?php if ( ! empty( $diagnostics['structural_warnings'] ) ) : ?>
                                <p class="scm-severity-label scm-severity-structural"><?php esc_html_e( 'Structural warnings', 'schema-control-manager' ); ?></p>
                                <ul class="scm-warning-list scm-structural-warning-list">
                                    <?php foreach ( $diagnostics['structural_warnings'] as $warning ) : ?>
                                        <li><?php echo esc_html( $warning ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php // ── Semantic warnings ─────────────────────────────────────── ?>
                            <?php if ( ! empty( $diagnostics['semantic_warnings'] ) ) : ?>
                                <p class="scm-severity-label scm-severity-semantic"><?php esc_html_e( 'Semantic warnings', 'schema-control-manager' ); ?></p>
                                <ul class="scm-warning-list scm-semantic-warning-list">
                                    <?php foreach ( $diagnostics['semantic_warnings'] as $warning ) : ?>
                                        <li><?php echo esc_html( $warning ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php // ── All clear ─────────────────────────────────────────────── ?>
                            <?php if ( empty( $diagnostics['errors'] ) && empty( $diagnostics['warnings'] ) ) : ?>
                                <p class="scm-ok"><?php esc_html_e( 'No graph issues detected in this schema.', 'schema-control-manager' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
            <p><button class="button button-primary" name="scm_save_schema" value="1">Save Schema</button></p>
        </form>
    </div>
    <?php endif; ?>
</div>
