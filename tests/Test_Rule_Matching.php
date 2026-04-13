<?php
/**
 * Tests: get_matching_rule_for_request() respects is_active filter.
 *
 * Uses a stub subclass of SCM_Rules that overrides get_all() and
 * matches_current_request() so no database or WordPress query functions
 * are required.
 */

use PHPUnit\Framework\TestCase;

// ── Stub ──────────────────────────────────────────────────────────────────────

/**
 * Subclass of SCM_Rules with injectable rows and a predictable matcher.
 *
 * - get_all() honours the is_active filter exactly as the production query does.
 * - build_request_context() returns an empty context so no WP functions are needed.
 * - matches_context() always returns true so every active rule matches — these
 *   tests verify priority/filtering logic, not target matching.
 */
class Stub_SCM_Rules_Matching extends SCM_Rules {
    /** @var array[] */
    private $rows = array();

    public function set_rows( array $rows ): void {
        $this->rows = $rows;
    }

    public function get_all( $args = array() ): array {
        $rows = $this->rows;

        if ( isset( $args['is_active'] ) && '' !== $args['is_active'] ) {
            $active = (int) $args['is_active'];
            $rows   = array_values(
                array_filter( $rows, static function ( $r ) use ( $active ) {
                    return (int) $r['is_active'] === $active;
                } )
            );
        }

        return $rows;
    }

    protected function build_request_context(): SCM_Request_Context {
        // Return an empty context so no WordPress functions are invoked.
        return SCM_Request_Context::from_array( array() );
    }

    public function matches_context( $rule, SCM_Request_Context $ctx ): bool {
        return true; // every rule matches in priority/filter isolation tests
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class Test_Rule_Matching extends TestCase {

    private function make_stub(): Stub_SCM_Rules_Matching {
        return new Stub_SCM_Rules_Matching( new SCM_DB() );
    }

    private function make_row( int $id, int $priority, int $is_active ): array {
        return array(
            'id'             => $id,
            'label'          => 'Rule ' . $id,
            'target_type'    => 'exact_slug',
            'target_value'   => 'test',
            'mode'           => 'aioseo_plus_custom',
            'replaced_types' => '[]',
            'priority'       => $priority,
            'is_active'      => $is_active,
            'updated_at'     => '2024-01-01 00:00:00',
        );
    }

    private function make_custom_only_row( int $id, int $priority ): array {
        return array(
            'id'             => $id,
            'label'          => 'Rule ' . $id,
            'target_type'    => 'exact_slug',
            'target_value'   => 'same-slug',
            'mode'           => 'custom_only',
            'replaced_types' => '[]',
            'priority'       => $priority,
            'is_active'      => 1,
            'updated_at'     => '2024-01-01 00:00:00',
        );
    }

    /**
     * An inactive rule with higher priority must NOT win over a lower-priority
     * active rule. get_matching_rule_for_request() filters is_active=1 first,
     * then sorts; the inactive rule is never considered.
     */
    public function test_inactive_rule_ignored(): void {
        $stub = $this->make_stub();
        $stub->set_rows( array(
            $this->make_row( 1, 200, 0 ), // high priority but inactive
            $this->make_row( 2, 50,  1 ), // lower priority but active
        ) );

        $matched = $stub->get_matching_rule_for_request();

        $this->assertNotNull( $matched, 'Expected an active rule to be matched.' );
        $this->assertSame( 2, (int) $matched['id'], 'Inactive high-priority rule must not win.' );
    }

    /**
     * When all rules are inactive, no match is returned.
     */
    public function test_all_inactive_returns_null(): void {
        $stub = $this->make_stub();
        $stub->set_rows( array(
            $this->make_row( 1, 100, 0 ),
            $this->make_row( 2, 200, 0 ),
        ) );

        $this->assertNull( $stub->get_matching_rule_for_request() );
    }

    /**
     * Among multiple active rules the highest-priority one wins.
     */
    public function test_highest_priority_active_rule_wins(): void {
        $stub = $this->make_stub();
        $stub->set_rows( array(
            $this->make_row( 1, 50,  1 ),
            $this->make_row( 2, 200, 1 ),
            $this->make_row( 3, 100, 1 ),
        ) );

        $matched = $stub->get_matching_rule_for_request();

        $this->assertSame( 2, (int) $matched['id'] );
    }

    // ── Bug regression: double-render of custom_only schemas ─────────────────

    /**
     * Two active custom_only rules matching the same request: the higher-priority
     * rule's id is returned. Both the injector and the AIOSEO filter receive this
     * id and call get_custom_nodes_for_rule(winner_id), so rule B's schemas are
     * never fetched or rendered.
     */
    public function test_two_custom_only_rules_winner_is_highest_priority(): void {
        $stub = $this->make_stub();
        $stub->set_rows( array(
            $this->make_custom_only_row( 1, 200 ), // winner
            $this->make_custom_only_row( 2, 100 ), // loser
        ) );

        $matched = $stub->get_matching_rule_for_request();

        $this->assertNotNull( $matched );
        $this->assertSame( 1,   (int) $matched['id'],       'Winner must be the higher-priority rule.' );
        $this->assertSame( 200, (int) $matched['priority'], 'Winner priority must be 200.' );
        $this->assertSame( 'custom_only', $matched['mode'] );
    }

    /**
     * The losing rule must never be the matched result.
     * Confirms that get_custom_nodes_for_rule would never be called with rule B's id.
     */
    public function test_lower_priority_custom_only_rule_never_matched(): void {
        $stub = $this->make_stub();
        $stub->set_rows( array(
            $this->make_custom_only_row( 10, 200 ), // winner
            $this->make_custom_only_row( 20, 100 ), // loser
        ) );

        $matched = $stub->get_matching_rule_for_request();

        $this->assertNotNull( $matched );
        $this->assertNotSame( 20, (int) $matched['id'], 'Lower-priority rule id must not be returned.' );
    }

    // ── matches_context() — new target types ─────────────────────────────────

    /** Helper: real SCM_Rules instance (no DB needed for matches_context). */
    private function make_rules(): SCM_Rules {
        return new SCM_Rules( new SCM_DB() );
    }

    /** Helper: build a minimal rule array for matching tests. */
    private function make_match_rule( string $type, string $value = '' ): array {
        return array(
            'target_type'  => $type,
            'target_value' => $value,
        );
    }

    // ── front_page vs home ────────────────────────────────────────────────────

    public function test_front_page_matches_when_is_front_page(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_front_page' => true ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'front_page' ), $ctx ) );
    }

    public function test_front_page_does_not_match_blog_index(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_home' => true, 'is_front_page' => false ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'front_page' ), $ctx ) );
    }

    public function test_home_matches_front_page_for_backward_compat(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_front_page' => true, 'is_home' => false ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'home' ), $ctx ) );
    }

    public function test_home_matches_blog_index(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_home' => true, 'is_front_page' => false ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'home' ), $ctx ) );
    }

    // ── post_type ─────────────────────────────────────────────────────────────

    public function test_post_type_matches_singular_of_correct_type(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_singular' => true, 'post_type' => 'post' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'post_type', 'post' ), $ctx ) );
    }

    public function test_post_type_does_not_match_different_type(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_singular' => true, 'post_type' => 'page' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'post_type', 'post' ), $ctx ) );
    }

    public function test_post_type_does_not_match_non_singular(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_singular' => false, 'post_type' => 'post' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'post_type', 'post' ), $ctx ) );
    }

    // ── post_type_archive ─────────────────────────────────────────────────────

    public function test_post_type_archive_matches_correct_type(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_post_type_archive' => true, 'archive_post_type' => 'movie' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'post_type_archive', 'movie' ), $ctx ) );
    }

    public function test_post_type_archive_does_not_match_wrong_type(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_post_type_archive' => true, 'archive_post_type' => 'book' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'post_type_archive', 'movie' ), $ctx ) );
    }

    // ── category ──────────────────────────────────────────────────────────────

    public function test_category_matches_correct_slug(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_category' => true, 'category_slug' => 'news' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'category', 'news' ), $ctx ) );
    }

    public function test_category_does_not_match_wrong_slug(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_category' => true, 'category_slug' => 'sport' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'category', 'news' ), $ctx ) );
    }

    // ── tag ───────────────────────────────────────────────────────────────────

    public function test_tag_matches_correct_slug(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tag' => true, 'tag_slug' => 'php' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'tag', 'php' ), $ctx ) );
    }

    public function test_tag_does_not_match_wrong_slug(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tag' => true, 'tag_slug' => 'javascript' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'tag', 'php' ), $ctx ) );
    }

    // ── taxonomy_term ─────────────────────────────────────────────────────────

    public function test_taxonomy_term_matches_correct_taxonomy_and_slug(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tax' => true, 'taxonomy' => 'genre', 'term_slug' => 'fiction' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'genre:fiction' ), $ctx ) );
    }

    public function test_taxonomy_term_does_not_match_wrong_term(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tax' => true, 'taxonomy' => 'genre', 'term_slug' => 'sci-fi' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'genre:fiction' ), $ctx ) );
    }

    public function test_taxonomy_term_does_not_match_wrong_taxonomy(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tax' => true, 'taxonomy' => 'topic', 'term_slug' => 'fiction' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'genre:fiction' ), $ctx ) );
    }

    public function test_taxonomy_term_does_not_match_without_is_tax(): void {
        // All three flags false → no taxonomy context at all → must not match.
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tax' => false, 'is_category' => false, 'is_tag' => false, 'taxonomy' => 'genre', 'term_slug' => 'fiction' ) );
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'genre:fiction' ), $ctx ) );
    }

    /** Regression: taxonomy_term must match built-in category pages (is_tax is false for categories). */
    public function test_taxonomy_term_matches_builtin_category_via_is_category(): void {
        $rules = $this->make_rules();
        // WP category pages: is_category=true, is_tax=false; context sets taxonomy='category', term_slug=slug.
        $ctx   = SCM_Request_Context::from_array( array( 'is_category' => true, 'is_tax' => false, 'taxonomy' => 'category', 'term_slug' => 'noticias' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'category:noticias' ), $ctx ) );
    }

    /** Regression: taxonomy_term must match built-in tag pages (is_tax is false for tags). */
    public function test_taxonomy_term_matches_builtin_tag_via_is_tag(): void {
        $rules = $this->make_rules();
        // WP tag pages: is_tag=true, is_tax=false; context sets taxonomy='post_tag', term_slug=slug.
        $ctx   = SCM_Request_Context::from_array( array( 'is_tag' => true, 'is_tax' => false, 'taxonomy' => 'post_tag', 'term_slug' => 'php' ) );
        $this->assertTrue( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'post_tag:php' ), $ctx ) );
    }

    public function test_taxonomy_term_malformed_value_never_matches(): void {
        $rules = $this->make_rules();
        $ctx   = SCM_Request_Context::from_array( array( 'is_tax' => true, 'taxonomy' => 'genre', 'term_slug' => 'fiction' ) );
        // No colon → malformed, should always return false.
        $this->assertFalse( $rules->matches_context( $this->make_match_rule( 'taxonomy_term', 'genrefiction' ), $ctx ) );
    }
}
