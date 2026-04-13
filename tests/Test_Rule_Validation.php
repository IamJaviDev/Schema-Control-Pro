<?php
/**
 * Tests: SCM_Validator::validate_rule() — enforces taxonomy_term format.
 */

use PHPUnit\Framework\TestCase;

class Test_Rule_Validation extends TestCase {

    private function validator(): SCM_Validator {
        return new SCM_Validator();
    }

    // ── taxonomy_term — valid formats ─────────────────────────────────────────

    public function test_valid_taxonomy_term_passes(): void {
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => 'genre:fiction',
        ) );
        $this->assertTrue( $result );
    }

    public function test_valid_taxonomy_term_with_hyphens_passes(): void {
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => 'product-cat:new-arrivals',
        ) );
        $this->assertTrue( $result );
    }

    public function test_valid_taxonomy_term_with_underscores_passes(): void {
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => 'book_genre:sci_fi',
        ) );
        $this->assertTrue( $result );
    }

    // ── taxonomy_term — invalid formats ───────────────────────────────────────

    public function test_missing_colon_returns_wp_error(): void {
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => 'genrefiction',
        ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_taxonomy_term', $result->get_error_code() );
    }

    public function test_empty_value_returns_wp_error(): void {
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => '',
        ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_taxonomy_term', $result->get_error_code() );
    }

    public function test_only_taxonomy_no_slug_returns_wp_error(): void {
        // Trailing colon — term slug is empty.
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => 'genre:',
        ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_taxonomy_term', $result->get_error_code() );
    }

    public function test_only_colon_no_taxonomy_no_slug_returns_wp_error(): void {
        // Leading colon — taxonomy name is empty.
        $result = $this->validator()->validate_rule( array(
            'target_type'  => 'taxonomy_term',
            'target_value' => ':fiction',
        ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_taxonomy_term', $result->get_error_code() );
    }

    // ── other target types — always pass ─────────────────────────────────────

    /** validate_rule() must not reject other target types regardless of value. */
    public function test_other_target_types_always_pass(): void {
        $v     = $this->validator();
        $types = array( 'home', 'front_page', 'exact_slug', 'exact_url', 'author',
                        'post_type', 'post_type_archive', 'category', 'tag' );

        foreach ( $types as $type ) {
            $result = $v->validate_rule( array(
                'target_type'  => $type,
                'target_value' => 'some-value',
            ) );
            $this->assertTrue( $result, "Expected true for target_type={$type}" );
        }
    }

    public function test_missing_target_type_key_passes(): void {
        // No target_type key at all — defaults to '' which is not taxonomy_term.
        $result = $this->validator()->validate_rule( array() );
        $this->assertTrue( $result );
    }
}
