<?php
/**
 * Tests: rule priority ordering and specificity ordering.
 *
 * These tests exercise SCM_Rules::target_specificity() and the usort() logic
 * inside get_matching_rule_for_request() without touching the database.
 */

use PHPUnit\Framework\TestCase;

class Test_Rule_Resolution extends TestCase {

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal rule stub.
     *
     * @param array $overrides
     * @return array
     */
    private function make_rule( array $overrides = [] ): array {
        return array_merge(
            [
                'id'          => 1,
                'target_type' => 'exact_slug',
                'target_value'=> 'test',
                'priority'    => 100,
                'updated_at'  => '2024-01-01 12:00:00',
                'is_active'   => 1,
            ],
            $overrides
        );
    }

    /**
     * Run the same deterministic sort that get_matching_rule_for_request() uses.
     *
     * @param array $rules
     * @return array sorted rules
     */
    private function sorted( array $rules ): array {
        usort(
            $rules,
            static function ( $a, $b ) {
                $pa = (int) ( $a['priority'] ?? 100 );
                $pb = (int) ( $b['priority'] ?? 100 );
                if ( $pa !== $pb ) {
                    return $pb - $pa;
                }

                $sa = SCM_Rules::target_specificity( $a['target_type'] );
                $sb = SCM_Rules::target_specificity( $b['target_type'] );
                if ( $sa !== $sb ) {
                    return $sb - $sa;
                }

                $ua = strtotime( $a['updated_at'] );
                $ub = strtotime( $b['updated_at'] );
                if ( $ua !== $ub ) {
                    return $ub - $ua;
                }

                return (int) $b['id'] - (int) $a['id'];
            }
        );
        return $rules;
    }

    // ── priority ordering ─────────────────────────────────────────────────────

    /** Higher priority value sorts first. */
    public function test_priority_desc_ordering(): void {
        $low  = $this->make_rule( [ 'id' => 1, 'priority' => 50 ] );
        $high = $this->make_rule( [ 'id' => 2, 'priority' => 200 ] );

        $result = $this->sorted( [ $low, $high ] );

        $this->assertSame( 200, (int) $result[0]['priority'] );
        $this->assertSame( 50,  (int) $result[1]['priority'] );
    }

    /** Rules with equal priority fall back to specificity. */
    public function test_priority_tie_falls_back_to_specificity(): void {
        $slug = $this->make_rule( [ 'id' => 1, 'priority' => 100, 'target_type' => 'exact_slug' ] );
        $url  = $this->make_rule( [ 'id' => 2, 'priority' => 100, 'target_type' => 'exact_url' ] );

        $result = $this->sorted( [ $slug, $url ] );

        $this->assertSame( 'exact_url', $result[0]['target_type'] );
    }

    /** Rules missing priority key default to 100. */
    public function test_missing_priority_defaults_to_100(): void {
        $no_prio = [ 'id' => 1, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ];
        $prio_50 = $this->make_rule( [ 'id' => 2, 'priority' => 50 ] );

        $result = $this->sorted( [ $prio_50, $no_prio ] );

        // no_prio defaults to 100, which beats 50.
        $this->assertSame( 1, (int) $result[0]['id'] );
    }

    // ── specificity ordering ──────────────────────────────────────────────────

    public function test_specificity_map_values(): void {
        $this->assertSame( 0,  SCM_Rules::target_specificity( 'home' ) );
        $this->assertSame( 5,  SCM_Rules::target_specificity( 'front_page' ) );
        $this->assertSame( 8,  SCM_Rules::target_specificity( 'category' ) );
        $this->assertSame( 8,  SCM_Rules::target_specificity( 'tag' ) );
        $this->assertSame( 10, SCM_Rules::target_specificity( 'post_type' ) );
        $this->assertSame( 10, SCM_Rules::target_specificity( 'author' ) );
        $this->assertSame( 12, SCM_Rules::target_specificity( 'taxonomy_term' ) );
        $this->assertSame( 12, SCM_Rules::target_specificity( 'post_type_archive' ) );
        $this->assertSame( 20, SCM_Rules::target_specificity( 'exact_slug' ) );
        $this->assertSame( 30, SCM_Rules::target_specificity( 'exact_url' ) );
    }

    public function test_unknown_target_type_specificity_is_zero(): void {
        $this->assertSame( 0, SCM_Rules::target_specificity( 'nonexistent_type' ) );
        $this->assertSame( 0, SCM_Rules::target_specificity( '' ) );
    }

    /** front_page (5) beats home (0) on specificity when priority is equal. */
    public function test_front_page_beats_home_on_specificity(): void {
        $home = $this->make_rule( [ 'id' => 1, 'priority' => 100, 'target_type' => 'home' ] );
        $fp   = $this->make_rule( [ 'id' => 2, 'priority' => 100, 'target_type' => 'front_page' ] );

        $result = $this->sorted( [ $home, $fp ] );

        $this->assertSame( 'front_page', $result[0]['target_type'] );
        $this->assertSame( 'home',       $result[1]['target_type'] );
    }

    /** taxonomy_term (12) beats category (8) on specificity when priority is equal. */
    public function test_taxonomy_term_beats_category_on_specificity(): void {
        $cat = $this->make_rule( [ 'id' => 1, 'priority' => 100, 'target_type' => 'category' ] );
        $tax = $this->make_rule( [ 'id' => 2, 'priority' => 100, 'target_type' => 'taxonomy_term' ] );

        $result = $this->sorted( [ $cat, $tax ] );

        $this->assertSame( 'taxonomy_term', $result[0]['target_type'] );
    }

    /** Specificity tie-break: updated_at DESC. */
    public function test_specificity_tie_falls_back_to_updated_at(): void {
        $older = $this->make_rule( [ 'id' => 1, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] );
        $newer = $this->make_rule( [ 'id' => 2, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-06-01 00:00:00' ] );

        $result = $this->sorted( [ $older, $newer ] );

        $this->assertSame( 2, (int) $result[0]['id'] );
    }

    /** Final tie-break: id DESC. */
    public function test_identical_rules_sorted_by_id_desc(): void {
        $a = $this->make_rule( [ 'id' => 5, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] );
        $b = $this->make_rule( [ 'id' => 9, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] );

        $result = $this->sorted( [ $a, $b ] );

        $this->assertSame( 9, (int) $result[0]['id'] );
    }

    /**
     * Full tie-break: all four criteria equal → highest id wins across 3 rules.
     * Verifies the id DESC comparator is stable when priority, specificity,
     * and updated_at are all identical.
     */
    public function test_full_tiebreak_highest_id_wins(): void {
        $rules = [
            $this->make_rule( [ 'id' => 3, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] ),
            $this->make_rule( [ 'id' => 7, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] ),
            $this->make_rule( [ 'id' => 5, 'priority' => 100, 'target_type' => 'exact_slug', 'updated_at' => '2024-01-01 00:00:00' ] ),
        ];

        $result = $this->sorted( $rules );
        $ids    = array_map( 'intval', array_column( $result, 'id' ) );

        $this->assertSame( [ 7, 5, 3 ], $ids );
    }

    /** Full ordering scenario: priority > specificity > updated_at > id. */
    public function test_full_ordering_scenario(): void {
        $rules = [
            $this->make_rule( [ 'id' => 1, 'priority' => 50,  'target_type' => 'exact_url',  'updated_at' => '2024-06-01 00:00:00' ] ),
            $this->make_rule( [ 'id' => 2, 'priority' => 200, 'target_type' => 'home',        'updated_at' => '2024-01-01 00:00:00' ] ),
            $this->make_rule( [ 'id' => 3, 'priority' => 100, 'target_type' => 'exact_slug',  'updated_at' => '2024-03-01 00:00:00' ] ),
            $this->make_rule( [ 'id' => 4, 'priority' => 100, 'target_type' => 'exact_url',   'updated_at' => '2024-03-01 00:00:00' ] ),
        ];

        $result = $this->sorted( $rules );

        $ids = array_column( $result, 'id' );
        // Expected: id=2 (priority 200), id=4 (p100, exact_url), id=3 (p100, exact_slug), id=1 (p50)
        $this->assertSame( [ 2, 4, 3, 1 ], array_map( 'intval', $ids ) );
    }
}
