<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wrap scm-wrap">
    <h1>Import / Export</h1>
    <?php if ( ! empty( $_GET['imported'] ) ) : ?><div class="notice notice-success"><p>Import completed.</p></div><?php endif; ?>
    <?php if ( ! empty( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p></div><?php endif; ?>
    <div class="scm-grid">
        <div class="scm-card">
            <h2>Export all rules</h2>
            <p>Downloads all rules and schemas as a JSON file.</p>
            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=scm_import_export&scm_export=all' ), 'scm_export_all' ) ); ?>">Export</a>
        </div>
        <div class="scm-card">
            <h2>Import file</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'scm_import_payload' ); ?>
                <input type="file" name="scm_import_file" accept="application/json">
                <p><button class="button button-primary" name="scm_import_payload" value="1">Import</button></p>
            </form>
        </div>
    </div>
</div>
