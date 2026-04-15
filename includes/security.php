<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Επαληθεύει ότι ο τρέχων χρήστης έχει δικαίωμα manage_options.
 * Αν όχι, σκοτώνει το request.
 */
function aatg_check_capability() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Δεν έχεις δικαίωμα να κάνεις αυτή την ενέργεια.', 403 );
    }
}

/**
 * Επαληθεύει nonce για AJAX calls.
 *
 * @param string $action  Το nonce action.
 * @param string $field   Το POST field που περιέχει το nonce.
 */
function aatg_verify_ajax_nonce( $action = 'aatg_bulk_nonce', $field = 'nonce' ) {
    if ( ! check_ajax_referer( $action, $field, false ) ) {
        wp_send_json_error( 'Μη έγκυρο nonce. Ανανέωσε τη σελίδα και δοκίμασε ξανά.' );
        wp_die();
    }
}

/**
 * Επιστρέφει καθαρισμένες ρυθμίσεις — ποτέ raw option.
 *
 * @return array
 */
function aatg_get_settings() {
    $defaults = [
        'api_key'    => '',
        'language'   => 'el',
        'model'      => 'gpt-4o-mini',
        'overwrite'  => 0,
        'batch_size' => 5,
    ];

    $saved = get_option( AATG_OPTION_KEY, [] );
    $merged = wp_parse_args( $saved, $defaults );

    // Sanitize
    $merged['api_key']    = sanitize_text_field( $merged['api_key'] );
    $merged['language']   = in_array( $merged['language'], [ 'el', 'en' ], true ) ? $merged['language'] : 'el';
    $merged['model']      = in_array( $merged['model'], [ 'gpt-4o-mini', 'gpt-4o' ], true ) ? $merged['model'] : 'gpt-4o-mini';
    $merged['overwrite']  = (int) $merged['overwrite'];
    $merged['batch_size'] = max( 1, min( 20, (int) $merged['batch_size'] ) );

    return $merged;
}

/**
 * Αποθηκεύει ρυθμίσεις μετά από sanitization.
 *
 * @param array $raw  Raw POST data.
 * @return array      Καθαρισμένες ρυθμίσεις που αποθηκεύτηκαν.
 */
function aatg_save_settings( $raw ) {
    $clean = [
        'api_key'    => sanitize_text_field( $raw['aatg_api_key'] ?? '' ),
        'language'   => in_array( $raw['aatg_language'] ?? '', [ 'el', 'en' ], true )
                        ? $raw['aatg_language'] : 'el',
        'model'      => in_array( $raw['aatg_model'] ?? '', [ 'gpt-4o-mini', 'gpt-4o' ], true )
                        ? $raw['aatg_model'] : 'gpt-4o-mini',
        'overwrite'  => isset( $raw['aatg_overwrite'] ) ? 1 : 0,
        'batch_size' => max( 1, min( 20, (int) ( $raw['aatg_batch_size'] ?? 5 ) ) ),
    ];

    update_option( AATG_OPTION_KEY, $clean );
    return $clean;
}
