<?php
/**
 * Tests: export_all() and download_export_rule() return valid payloads.
 *
 * Database interactions are replaced by lightweight stubs so no DB is needed.
 */

use PHPUnit\Framework\TestCase;

// ── Stubs ─────────────────────────────────────────────────────────────────────

class Stub_Rules_For_Export extends SCM_Rules {
    private array $rows;
    public function __construct( array $rows ) {
        parent::__construct( new SCM_DB() );
        $this->rows = $rows;
    }
    public function get_all( $args = array() ) { return $this->rows; }
    public function get( $id ): ?array {
        foreach ( $this->rows as $row ) {
            if ( (int) $row['id'] === (int) $id ) { return $row; }
        }
        return null;
    }
}

class Stub_Schemas_For_Export extends SCM_Schemas {
    private array $map; // rule_id => schemas[]
    public function __construct( array $map ) {
        parent::__construct( new SCM_DB(), new SCM_Validator() );
        $this->map = $map;
    }
    public function get_by_rule( $rule_id ) { return $this->map[ (int) $rule_id ] ?? []; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function make_rule_row( int $id, string $label = 'Test rule' ): array {
    return [
        'id'             => $id,
        'label'          => $label,
        'target_type'    => 'exact_slug',
        'target_value'   => 'test',
        'mode'           => 'aioseo_plus_custom',
        'replaced_types' => '[]',
        'priority'       => 100,
        'is_active'      => 1,
        'created_at'     => '2024-01-01 00:00:00',
        'updated_at'     => '2024-01-01 00:00:00',
    ];
}

function make_schema_row( int $id, int $rule_id ): array {
    return [
        'id'          => $id,
        'rule_id'     => $rule_id,
        'label'       => 'My Schema',
        'schema_type' => 'FAQPage',
        'schema_json' => '{"@type":"FAQPage"}',
        'priority'    => 10,
        'is_active'   => 1,
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class Test_Export extends TestCase {

    private function make_ie( array $rules_rows, array $schema_map ): SCM_Import_Export {
        $rules   = new Stub_Rules_For_Export( $rules_rows );
        $schemas = new Stub_Schemas_For_Export( $schema_map );
        return new SCM_Import_Export( $rules, $schemas, new SCM_Validator() );
    }

    // ── export_all() ──────────────────────────────────────────────────────────

    public function test_export_all_returns_valid_envelope(): void {
        $ie      = $this->make_ie( [ make_rule_row( 1 ) ], [ 1 => [ make_schema_row( 10, 1 ) ] ] );
        $payload = $ie->export_all();

        $this->assertSame( 'schema-control-pro', $payload['plugin'] );
        $this->assertSame( SCM_VERSION, $payload['version'] );
        $this->assertArrayHasKey( 'exported_at', $payload );
        $this->assertIsArray( $payload['rules'] );
    }

    public function test_export_all_includes_all_rules(): void {
        $rules = [ make_rule_row( 1, 'Rule A' ), make_rule_row( 2, 'Rule B' ) ];
        $ie    = $this->make_ie( $rules, [] );

        $payload = $ie->export_all();

        $this->assertCount( 2, $payload['rules'] );
    }

    public function test_export_all_attaches_schemas_to_each_rule(): void {
        $rules  = [ make_rule_row( 1 ) ];
        $schema = make_schema_row( 10, 1 );
        $ie     = $this->make_ie( $rules, [ 1 => [ $schema ] ] );

        $payload = $ie->export_all();

        $this->assertArrayHasKey( 'schemas', $payload['rules'][0] );
        $this->assertCount( 1, $payload['rules'][0]['schemas'] );
        $this->assertSame( 'FAQPage', $payload['rules'][0]['schemas'][0]['schema_type'] );
    }

    public function test_export_all_decodes_replaced_types(): void {
        $row            = make_rule_row( 1 );
        $row['replaced_types'] = '["WebPage","BreadcrumbList"]';
        $ie             = $this->make_ie( [ $row ], [] );

        $payload = $ie->export_all();

        $this->assertIsArray( $payload['rules'][0]['replaced_types'] );
        $this->assertContains( 'WebPage', $payload['rules'][0]['replaced_types'] );
    }

    public function test_export_all_empty_ruleset_returns_empty_rules_array(): void {
        $ie      = $this->make_ie( [], [] );
        $payload = $ie->export_all();

        $this->assertIsArray( $payload['rules'] );
        $this->assertCount( 0, $payload['rules'] );
    }

    // ── download_export_rule() payload ────────────────────────────────────────

    public function test_export_single_rule_contains_exactly_one_rule(): void {
        $ie      = $this->make_ie( [ make_rule_row( 7 ) ], [ 7 => [ make_schema_row( 20, 7 ) ] ] );
        $payload = $this->capture_export_rule( $ie, 7 );

        $this->assertCount( 1, $payload['rules'] );
        $this->assertSame( 7, (int) $payload['rules'][0]['id'] );
    }

    public function test_export_single_rule_envelope_is_valid(): void {
        $ie      = $this->make_ie( [ make_rule_row( 7 ) ], [] );
        $payload = $this->capture_export_rule( $ie, 7 );

        $this->assertSame( 'schema-control-pro', $payload['plugin'] );
        $this->assertSame( SCM_VERSION, $payload['version'] );
        $this->assertArrayHasKey( 'exported_at', $payload );
    }

    public function test_export_single_rule_attaches_schemas(): void {
        $schema  = make_schema_row( 20, 7 );
        $ie      = $this->make_ie( [ make_rule_row( 7 ) ], [ 7 => [ $schema ] ] );
        $payload = $this->capture_export_rule( $ie, 7 );

        $this->assertCount( 1, $payload['rules'][0]['schemas'] );
    }

    public function test_export_missing_rule_throws(): void {
        $ie = $this->make_ie( [], [] );
        $this->expectException( \RuntimeException::class ); // wp_die stub throws
        $ie->download_export_rule( 999 );
    }

    /**
     * Call download_export_rule() capturing output instead of sending headers.
     */
    private function capture_export_rule( SCM_Import_Export $ie, int $rule_id ): array {
        ob_start();
        try {
            $ie->download_export_rule( $rule_id );
        } catch ( \RuntimeException $e ) {
            // wp_die('') signals stream done (empty message); wp_die('Rule not found.') signals an error.
            if ( '' !== $e->getMessage() ) {
                ob_end_clean();
                throw $e;
            }
        }
        $json = ob_get_clean();
        return json_decode( $json, true ) ?: [];
    }
}
