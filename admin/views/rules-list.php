<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap scm-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Schema Rules', 'schema-control-pro' ); ?></h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scm_rule_edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'schema-control-pro' ); ?></a>
    <hr class="wp-header-end">

    <?php if ( ! empty( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success"><p><?php esc_html_e( 'Rule deleted.', 'schema-control-pro' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! empty( $orphan_count ) ) : ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Data integrity warning', 'schema-control-pro' ); ?></strong> &mdash;
                <?php printf(
                    /* translators: %d: number of orphan schemas */
                    esc_html( _n(
                        '%d schema is referencing a rule that no longer exists.',
                        '%d schemas are referencing rules that no longer exist.',
                        $orphan_count,
                        'schema-control-pro'
                    ) ),
                    (int) $orphan_count
                ); ?>
                <?php esc_html_e( 'These schemas cannot be rendered and should be deleted or reassigned.', 'schema-control-pro' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="get" class="scm-filters">
        <input type="hidden" name="page" value="scm_rules">
        <input type="search" name="s" placeholder="<?php esc_attr_e( 'Search label or target', 'schema-control-pro' ); ?>" value="<?php echo esc_attr( $_GET['s'] ?? '' ); ?>">
        <select name="target_type">
            <option value=""><?php esc_html_e( 'All targets', 'schema-control-pro' ); ?></option>
            <?php foreach ( array( 'home' => 'Home', 'exact_url' => 'Exact URL', 'exact_slug' => 'Exact slug', 'author' => 'Author page' ) as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $_GET['target_type'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="is_active">
            <option value=""><?php esc_html_e( 'Any status', 'schema-control-pro' ); ?></option>
            <option value="1" <?php selected( $_GET['is_active'] ?? '', '1' ); ?>><?php esc_html_e( 'Active', 'schema-control-pro' ); ?></option>
            <option value="0" <?php selected( $_GET['is_active'] ?? '', '0' ); ?>><?php esc_html_e( 'Inactive', 'schema-control-pro' ); ?></option>
        </select>
        <button class="button"><?php esc_html_e( 'Filter', 'schema-control-pro' ); ?></button>
    </form>

    <table class="widefat striped scm-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Label', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Target', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Mode', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Replaced types', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Status', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Updated', 'schema-control-pro' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'schema-control-pro' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rules ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No rules found.', 'schema-control-pro' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rules as $rule ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $rule['label'] ); ?></strong></td>
                        <td>
                            <div><?php echo esc_html( $rule['target_type'] ); ?></div>
                            <code><?php echo esc_html( $rule['target_value'] ); ?></code>
                        </td>
                        <td><?php echo esc_html( $rule['mode'] ); ?></td>
                        <td><?php echo esc_html( implode( ', ', json_decode( $rule['replaced_types'], true ) ?: array() ) ); ?></td>
                        <td><?php echo esc_html( $rule['priority'] ?? 100 ); ?></td>
                        <td><?php echo ! empty( $rule['is_active'] ) ? '<span class="scm-status active">Active</span>' : '<span class="scm-status inactive">Inactive</span>'; ?></td>
                        <td><?php echo esc_html( $rule['updated_at'] ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . (int) $rule['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'schema-control-pro' ); ?></a>
                            <a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=scm_rules&scm_delete_rule=' . (int) $rule['id'] ), 'scm_delete_rule_' . (int) $rule['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'schema-control-pro' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
