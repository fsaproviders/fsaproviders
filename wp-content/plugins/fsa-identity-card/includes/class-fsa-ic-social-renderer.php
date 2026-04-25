<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Social_Renderer {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    private function get_meta( $listing_id, $key ) {
        if ( empty( $key ) ) return '';
        $val = get_post_meta( $listing_id, '_' . ltrim( $key, '_' ), true );
        if ( '' === $val || null === $val ) {
            $val = get_post_meta( $listing_id, $key, true );
        }
        return $val;
    }

    private function platforms() {
        return [
            'instagram' => [ 'label' => 'Instagram', 'setting' => 'social_instagram_key' ],
            'facebook'  => [ 'label' => 'Facebook',  'setting' => 'social_facebook_key' ],
            'youtube'   => [ 'label' => 'YouTube',   'setting' => 'social_youtube_key' ],
            'tiktok'    => [ 'label' => 'TikTok',    'setting' => 'social_tiktok_key' ],
            'linkedin'  => [ 'label' => 'LinkedIn',  'setting' => 'social_linkedin_key' ],
            'yelp'      => [ 'label' => 'Yelp',      'setting' => 'social_yelp_key' ],
            'google'    => [ 'label' => 'Google',    'setting' => 'social_google_key' ],
        ];
    }

    private function icon( $platform ) {
        // Monochrome SVGs, currentColor for theming
        $icons = [
            'instagram' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63a5.86 5.86 0 0 0-2.13 1.38A5.86 5.86 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.73 1.46 1.38 2.13.67.65 1.34 1.07 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.86 5.86 0 0 0 2.13-1.38 5.86 5.86 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.86 5.86 0 0 0-1.38-2.13A5.86 5.86 0 0 0 19.86.63C19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0z"/><path d="M12 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zm0 10.16a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/><circle cx="18.41" cy="5.59" r="1.44"/></svg>',
            'facebook'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"/></svg>',
            'youtube'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.5 6.19a3 3 0 0 0-2.12-2.13C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.51A3 3 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3 3 0 0 0 2.12 2.13c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3 3 0 0 0 2.12-2.13C24 15.93 24 12 24 12s0-3.93-.5-5.81zM9.55 15.57V8.43L15.82 12l-6.27 3.57z"/></svg>',
            'tiktok'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5.8 20.1a6.34 6.34 0 0 0 10.86-4.43V8.79a8.16 8.16 0 0 0 4.77 1.52V6.86a4.85 4.85 0 0 1-1.84-.17z"/></svg>',
            'linkedin'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.45 20.45h-3.55v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.13 1.45-2.13 2.94v5.66H9.36V9h3.41v1.56h.05a3.74 3.74 0 0 1 3.37-1.85c3.6 0 4.27 2.37 4.27 5.45v6.29zM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.72v20.56C0 23.23.79 24 1.77 24h20.45C23.2 24 24 23.23 24 22.28V1.72C24 .77 23.2 0 22.22 0z"/></svg>',
            'yelp'      => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.16 12.59l-4.4 1.43c-.84.27-1.6-.7-1.07-1.4l2.72-3.74c.36-.5 1.1-.5 1.5.02.93 1.2 1.57 2.6 1.86 4.1.1.55-.32 1.02-.6 1.6zm-2.1 4.4l-3.7-2.7c-.7-.5-.13-1.6.7-1.36l4.4 1.27c.6.18.93.83.7 1.4-.5 1.4-1.36 2.6-2.46 3.6-.4.36-1.04.16-1.34-.3l-1-2.13zm-5.1-3.6v-4.6c0-.85 1.06-1.2 1.55-.5l2.7 3.7c.34.46.13 1.1-.4 1.27l-4.4 1.43c-.45.14-.95-.2-.95-.7l.05-.6h-.55zm-2.7-9c.45-.07.83.27.83.7v9.6c0 .85-1.07 1.2-1.55.5L4.16 8.5c-.34-.46-.13-1.1.4-1.27 1.78-.6 3.7-.83 5.7-.83zm-7.43 9.5l4.4-1.43c.84-.27 1.6.7 1.07 1.4l-2.72 3.74c-.36.5-1.1.5-1.5-.02-.93-1.2-1.57-2.6-1.86-4.1-.1-.55.33-1.02.6-1.6z"/></svg>',
            'google'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12.48 10.92v3.28h7.84c-.24 1.84-.85 3.18-1.73 4.1-1.08 1.07-2.78 2.27-5.74 2.27-4.58 0-8.16-3.69-8.16-8.27S8.27 3.99 12.85 3.99c2.47 0 4.27.97 5.6 2.22l2.31-2.31C18.88 2.13 16.42 1 12.85 1 6.6 1 1.34 6.1 1.34 12.32S6.6 23.65 12.85 23.65c3.36 0 5.9-1.1 7.88-3.18 2.04-2.04 2.68-4.92 2.68-7.24 0-.72-.05-1.39-.16-1.95H12.48z"/></svg>',
        ];
        return isset( $icons[ $platform ] ) ? $icons[ $platform ] : '';
    }

    private function normalize_url( $url ) {
        $url = trim( $url );
        if ( empty( $url ) ) return '';
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . $url;
        }
        return esc_url( $url );
    }

    public function render( $listing_id ) {
        if ( ! $listing_id || get_post_type( $listing_id ) !== 'job_listing' ) return '';

        $links = [];
        foreach ( $this->platforms() as $key => $info ) {
            $meta_key = FSA_IC_Settings::get( $info['setting'] );
            $raw = $this->get_meta( $listing_id, $meta_key );
            $url = $this->normalize_url( (string) $raw );
            if ( ! empty( $url ) ) {
                $links[ $key ] = [ 'label' => $info['label'], 'url' => $url ];
            }
        }

        if ( empty( $links ) ) return '';

        ob_start();
        ?>
        <div class="fsa-ic-card fsa-ic-benefits-card fsa-ic-social-card">
            <div class="fsa-ic-benefits-body">
                <h2 class="fsa-ic-benefits-title">Connect</h2>
                <div class="fsa-ic-social-row">
                    <?php foreach ( $links as $key => $link ) : ?>
                        <a href="<?php echo esc_url( $link['url'] ); ?>"
                           class="fsa-ic-social-btn fsa-ic-social-btn--<?php echo esc_attr( $key ); ?>"
                           target="_blank" rel="noopener nofollow"
                           aria-label="<?php echo esc_attr( $link['label'] ); ?>">
                            <?php echo $this->icon( $key ); // SVG ?>
                            <span class="fsa-ic-social-label"><?php echo esc_html( $link['label'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
