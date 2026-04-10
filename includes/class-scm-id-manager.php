<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Id_Manager {
    private $classifier;

    public function __construct( SCM_Structural_Classifier $classifier ) {
        $this->classifier = $classifier;
    }

    public function ensure_node_ids( $nodes, $context = array() ) {
        if ( ! is_array( $nodes ) ) {
            return array();
        }

        $used = array();
        foreach ( $nodes as $node ) {
            if ( is_array( $node ) && ! empty( $node['@id'] ) ) {
                $used[ strtolower( trim( (string) $node['@id'] ) ) ] = true;
            }
        }

        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) || ! $this->classifier->node_has_structural_type( $node ) ) {
                continue;
            }

            if ( ! empty( $node['@id'] ) ) {
                continue;
            }

            $base_url  = $this->get_context_url( $context );
            $base_url  = $this->sanitize_base_url( $base_url );
            $fragment  = $this->get_fragment_for_node( $node );
            $candidate = untrailingslashit( $base_url ) . '/#' . $fragment;
            $suffix    = 2;

            while ( isset( $used[ strtolower( $candidate ) ] ) ) {
                $candidate = untrailingslashit( $base_url ) . '/#' . $fragment . '-' . $suffix;
                $suffix++;
            }

            $nodes[ $index ]['@id']           = $candidate;
            $used[ strtolower( $candidate ) ] = true;
        }

        return $nodes;
    }

    public function get_context_url( $context = array() ) {
        if ( ! empty( $context['target_url'] ) ) {
            return (string) $context['target_url'];
        }

        if ( function_exists( 'is_author' ) && is_author() ) {
            $author = get_queried_object();
            if ( isset( $author->ID ) ) {
                return get_author_posts_url( (int) $author->ID, $author->user_nicename ?? '' );
            }
        }

        if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
            return home_url( '/' );
        }

        if ( function_exists( 'get_permalink' ) ) {
            $queried = get_queried_object_id();
            if ( $queried ) {
                $link = get_permalink( $queried );
                if ( $link ) {
                    return $link;
                }
            }
        }

        $request = $GLOBALS['wp']->request ?? '';

        // Guard: $GLOBALS['wp']->request must be a relative path, not an absolute URL.
        // Passing an absolute URL to home_url() produces double-scheme strings like
        // "https://site.com/http://...". Fall back to the site root if it looks absolute.
        if ( preg_match( '#^https?://#i', (string) $request ) ) {
            $request = '';
        }

        return home_url( '/' . ltrim( (string) $request, '/' ) );
    }

    /**
     * Ensure $url is a valid absolute URL for use as an @id base.
     *
     * Detects and repairs double-scheme patterns such as "https://http://..."
     * (produced when an absolute URL is accidentally passed to home_url()).
     * Falls back to home_url('/') for any value that cannot be resolved to a
     * single-scheme absolute URL.
     */
    private function sanitize_base_url( $url ) {
        // Detect "scheme://scheme://..." – take everything from the second "scheme://"
        if ( preg_match( '#^https?://.+?(https?://.+)$#i', $url, $m ) ) {
            return rtrim( $m[1], '/' );
        }

        // Must start with a single http(s) scheme.
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return home_url( '/' );
        }

        return $url;
    }

    private function get_fragment_for_node( $node ) {
        $type = $this->classifier->get_primary_type( $node );
        switch ( $type ) {
            case 'breadcrumblist':
                return 'breadcrumb';
            case 'person':
                return 'author';
            case 'organization':
                return 'organization';
            case 'webpage':
                return 'webpage';
            case 'website':
                return 'website';
            case 'profilepage':
                return 'profilepage';
            case 'collectionpage':
                return 'collectionpage';
            default:
                return sanitize_title( $type ?: 'node' );
        }
    }
}
