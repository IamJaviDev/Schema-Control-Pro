<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Snapshot of all WordPress conditional data relevant to rule matching.
 *
 * Built once per request via from_wp(), or injected from a plain array
 * via from_array() for unit tests. All matching logic reads from this
 * object instead of calling WordPress functions directly.
 */
class SCM_Request_Context {

    // ── Boolean flags ─────────────────────────────────────────────────────────

    /** @var bool True when the static front page is displayed. */
    public $is_front_page = false;

    /** @var bool True when the posts/blog-index page is displayed. */
    public $is_home = false;

    /** @var bool True when a single post/page/CPT is displayed. */
    public $is_singular = false;

    /** @var bool True when a CPT archive is displayed. */
    public $is_post_type_archive = false;

    /** @var bool True when a category archive is displayed. */
    public $is_category = false;

    /** @var bool True when a tag archive is displayed. */
    public $is_tag = false;

    /** @var bool True when a custom taxonomy term archive is displayed. */
    public $is_tax = false;

    /** @var bool True when an author archive is displayed. */
    public $is_author = false;

    // ── Scalars ───────────────────────────────────────────────────────────────

    /** @var string Post type of the queried singular post. */
    public $post_type = '';

    /** @var int ID of the queried singular post. */
    public $post_id = 0;

    /** @var string Post type for the current CPT archive. */
    public $archive_post_type = '';

    /** @var string Slug of the current category term. */
    public $category_slug = '';

    /** @var string Slug of the current tag term. */
    public $tag_slug = '';

    /** @var string Taxonomy name for the current custom-taxonomy term page. */
    public $taxonomy = '';

    /** @var string Term slug for the current custom-taxonomy term page. */
    public $term_slug = '';

    /** @var string user_nicename for the current author archive. */
    public $author_nicename = '';

    /** @var string Normalised current URL (no trailing slash, no query string). */
    public $current_url = '';

    /** @var string post_name of the queried object (exact_slug primary check). */
    public $queried_slug = '';

    /** @var string Trimmed $wp->request path (exact_slug fallback). */
    public $request_path = '';

    // ── Constructors ──────────────────────────────────────────────────────────

    private function __construct() {}

    /**
     * Build a context from the live WordPress request.
     *
     * Must only be called after the main query has run (e.g. inside wp_head).
     *
     * @return self
     */
    public static function from_wp(): self {
        $ctx = new self();

        $ctx->is_front_page = (bool) is_front_page();
        $ctx->is_home       = (bool) is_home();
        $ctx->is_singular   = (bool) is_singular();

        $queried = get_queried_object();

        if ( $ctx->is_singular && is_object( $queried ) && isset( $queried->post_type ) ) {
            $ctx->post_type   = (string) $queried->post_type;
            $ctx->post_id     = (int) ( $queried->ID ?? 0 );
            $ctx->queried_slug = (string) ( $queried->post_name ?? '' );
        } elseif ( is_object( $queried ) && isset( $queried->post_name ) ) {
            $ctx->queried_slug = (string) $queried->post_name;
        }

        $ctx->request_path = trim( $GLOBALS['wp']->request ?? '', '/' );

        $raw               = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
        $ctx->current_url  = untrailingslashit( strtok( (string) $raw, '?' ) );

        $ctx->is_post_type_archive = (bool) is_post_type_archive();
        if ( $ctx->is_post_type_archive ) {
            $ctx->archive_post_type = (string) get_query_var( 'post_type', '' );
        }

        $ctx->is_category = (bool) is_category();
        if ( $ctx->is_category && is_object( $queried ) && isset( $queried->slug ) ) {
            $ctx->category_slug = (string) $queried->slug;
            // Populate taxonomy/term_slug so taxonomy_term rules can match category pages.
            $ctx->taxonomy  = 'category';
            $ctx->term_slug = (string) $queried->slug;
        }

        $ctx->is_tag = (bool) is_tag();
        if ( $ctx->is_tag && is_object( $queried ) && isset( $queried->slug ) ) {
            $ctx->tag_slug = (string) $queried->slug;
            // Populate taxonomy/term_slug so taxonomy_term rules can match tag pages.
            $ctx->taxonomy  = 'post_tag';
            $ctx->term_slug = (string) $queried->slug;
        }

        $ctx->is_tax = (bool) is_tax();
        if ( $ctx->is_tax && is_object( $queried ) && isset( $queried->taxonomy, $queried->slug ) ) {
            $ctx->taxonomy  = (string) $queried->taxonomy;
            $ctx->term_slug = (string) $queried->slug;
        }

        $ctx->is_author = (bool) is_author();
        if ( $ctx->is_author && is_object( $queried ) && isset( $queried->user_nicename ) ) {
            $ctx->author_nicename = (string) $queried->user_nicename;
        }

        return $ctx;
    }

    /**
     * Build a context from a plain array — for unit tests only.
     *
     * Keys must match the public property names. Unknown keys are silently
     * ignored so tests only need to specify the fields they care about.
     *
     * @param array $data
     * @return self
     */
    public static function from_array( array $data ): self {
        $ctx = new self();
        foreach ( $data as $key => $value ) {
            if ( property_exists( $ctx, $key ) ) {
                $ctx->$key = $value;
            }
        }
        return $ctx;
    }
}
