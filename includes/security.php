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
 * Κρυπτογραφεί το API key (AES-256-CBC) πριν την αποθήκευση.
 * Χρησιμοποιεί το AUTH_KEY του wp-config.php ως master key.
 *
 * @param string $key  Plaintext API key.
 * @return string      Base64-encoded encrypted value, ή κενό string.
 */
function aatg_encrypt_api_key( $key ) {
    if ( empty( $key ) ) return '';
    if ( ! function_exists( 'openssl_encrypt' ) ) return $key; // fallback: no openssl

    $salt    = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
    $enc_key = substr( hash( 'sha256', $salt ), 0, 32 );
    $iv_len  = openssl_cipher_iv_length( 'AES-256-CBC' );
    $iv      = openssl_random_pseudo_bytes( $iv_len );
    $enc     = openssl_encrypt( $key, 'AES-256-CBC', $enc_key, 0, $iv );

    return base64_encode( $iv . '::' . $enc );
}

/**
 * Αποκρυπτογραφεί το API key από τη βάση.
 * Αν το αποθηκευμένο key δεν είναι κρυπτογραφημένο (legacy), το επιστρέφει ως έχει.
 *
 * @param string $stored  Encrypted (ή legacy plaintext) API key.
 * @return string         Plaintext API key.
 */
function aatg_decrypt_api_key( $stored ) {
    if ( empty( $stored ) ) return '';
    if ( ! function_exists( 'openssl_decrypt' ) ) return $stored;

    $data = base64_decode( $stored, true );
    if ( $data === false ) {
        return $stored; // legacy plaintext — επιστρέφεται ως έχει
    }

    $iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );

    // Χρήση fixed-offset αντί explode — αποφεύγει false splits αν το IV περιέχει '::' bytes
    if ( strlen( $data ) <= $iv_len + 2 || substr( $data, $iv_len, 2 ) !== '::' ) {
        return $stored; // legacy plaintext — επιστρέφεται ως έχει
    }

    $salt      = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
    $enc_key   = substr( hash( 'sha256', $salt ), 0, 32 );
    $iv        = substr( $data, 0, $iv_len );
    $enc       = substr( $data, $iv_len + 2 );
    $decrypted = openssl_decrypt( $enc, 'AES-256-CBC', $enc_key, 0, $iv );

    return ( $decrypted !== false ) ? $decrypted : $stored;
}

/**
 * Επιστρέφει καθαρισμένες ρυθμίσεις — ποτέ raw option.
 * Το api_key επιστρέφεται αποκρυπτογραφημένο (για χρήση στα API calls).
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

    $saved  = get_option( AATG_OPTION_KEY, [] );
    $merged = wp_parse_args( $saved, $defaults );

    // Αποκρυπτογράφηση + sanitization
    $merged['api_key']    = sanitize_text_field( aatg_decrypt_api_key( $merged['api_key'] ) );
    $merged['language']   = in_array( $merged['language'], [ 'el', 'en' ], true ) ? $merged['language'] : 'el';
    $merged['model']      = in_array( $merged['model'], [ 'gpt-4o-mini', 'gpt-4o' ], true ) ? $merged['model'] : 'gpt-4o-mini';
    $merged['overwrite']  = (int) $merged['overwrite'];
    $merged['batch_size'] = max( 1, min( 20, (int) $merged['batch_size'] ) );

    return $merged;
}

/**
 * Αποθηκεύει ρυθμίσεις μετά από sanitization.
 * Το API key αποθηκεύεται κρυπτογραφημένο.
 * Αν το key field αφεθεί κενό, το υπάρχον key διατηρείται.
 *
 * @param array $raw  Raw POST data.
 * @return array      Καθαρισμένες ρυθμίσεις που αποθηκεύτηκαν.
 */
function aatg_save_settings( $raw ) {
    $existing = get_option( AATG_OPTION_KEY, [] );
    $new_key  = sanitize_text_field( $raw['aatg_api_key'] ?? '' );

    if ( empty( $new_key ) && ! empty( $existing['api_key'] ) ) {
        // Κενό field = "μη αλλάξεις το key" — κρατάμε το υπάρχον encrypted
        $api_key_stored = $existing['api_key'];
    } else {
        $api_key_stored = aatg_encrypt_api_key( $new_key );
    }

    $clean = [
        'api_key'    => $api_key_stored,
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
