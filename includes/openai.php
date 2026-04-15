<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Στέλνει εικόνα + τίτλο στο OpenAI και επιστρέφει alt text.
 *
 * @param string $image_url    Δημόσιο URL εικόνας.
 * @param string $image_title  Τίτλος attachment (για context).
 * @param string $language     'el' ή 'en'.
 * @param string $api_key      OpenAI API key.
 * @param string $model        'gpt-4o-mini' ή 'gpt-4o'.
 *
 * @return string|WP_Error     Alt text ή WP_Error.
 */
function aatg_generate_alt( $image_url, $image_title, $language, $api_key, $model = 'gpt-4o-mini' ) {

    // Validate API key format (sk-... ή sk-proj-...)
    if ( empty( $api_key ) || ! preg_match( '/^sk-/', $api_key ) ) {
        return new WP_Error( 'invalid_key', 'Μη έγκυρο API key format.' );
    }

    // Validate model whitelist — ποτέ user input απευθείας στο API
    $allowed_models = [ 'gpt-4o-mini', 'gpt-4o' ];
    if ( ! in_array( $model, $allowed_models, true ) ) {
        $model = 'gpt-4o-mini';
    }

    // Build prompt
    if ( $language === 'el' ) {
        $prompt = 'Γράψε ένα σύντομο alt text για αυτή την εικόνα στα Ελληνικά (max 125 χαρακτήρες). Να είναι περιγραφικό και χρήσιμο για screen readers και SEO. Μόνο το alt text, χωρίς εισαγωγικά ή επεξηγήσεις.';
    } else {
        $prompt = 'Write a short alt text for this image in English (max 125 characters). Be descriptive and useful for screen readers and SEO. Return only the alt text, no quotes or explanation.';
    }

    if ( ! empty( $image_title ) ) {
        $prompt .= $language === 'el'
            ? ' Τίτλος αρχείου: ' . sanitize_text_field( $image_title )
            : ' File title: ' . sanitize_text_field( $image_title );
    }

    $body = wp_json_encode( [
        'model'      => $model,
        'max_tokens' => 150,
        'messages'   => [
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url'    => esc_url_raw( $image_url ),
                            'detail' => 'low', // φτηνότερο — αρκετό για alt text
                        ],
                    ],
                ],
            ],
        ],
    ] );

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_response', 'Μη αναμενόμενη απάντηση από OpenAI (HTTP ' . $code . ').' );
    }

    if ( $code === 401 ) {
        return new WP_Error( 'auth_error', 'Λάθος API key. Έλεγξε τις ρυθμίσεις.' );
    }

    if ( $code === 429 ) {
        return new WP_Error( 'rate_limit', 'Rate limit — περίμενε λίγο και δοκίμασε ξανά.' );
    }

    if ( $code === 402 ) {
        return new WP_Error( 'billing', 'Δεν υπάρχουν credits στον OpenAI λογαριασμό σου.' );
    }

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? 'HTTP ' . $code;
        return new WP_Error( 'openai_error', sanitize_text_field( $msg ) );
    }

    $text = trim( $data['choices'][0]['message']['content'] ?? '' );

    if ( empty( $text ) ) {
        return new WP_Error( 'empty_response', 'Κενή απάντηση από OpenAI.' );
    }

    // Strip surrounding quotes if model added them despite instructions
    $text = trim( $text, '"\'«»' );

    return sanitize_text_field( $text );
}

// ─── SVG support ──────────────────────────────────────────────────────────────

/**
 * Εξάγει context (title, desc, text nodes) από SVG αρχείο.
 *
 * @param string $file_path  Απόλυτο path στο SVG αρχείο.
 * @return array             Associative array με κλειδιά 'title', 'desc', 'text'.
 */
function aatg_extract_svg_context( $file_path ) {
    $content = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    if ( empty( $content ) ) return [];

    libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $dom->loadXML( $content, LIBXML_NOERROR | LIBXML_NOWARNING );
    libxml_clear_errors();

    $info = [];

    // <title> — πρώτο στοιχείο = τίτλος του SVG document
    $titles = $dom->getElementsByTagName( 'title' );
    if ( $titles->length > 0 ) {
        $t = trim( $titles->item(0)->textContent );
        if ( $t !== '' ) $info['title'] = $t;
    }

    // <desc> — περιγραφή SVG
    $descs = $dom->getElementsByTagName( 'desc' );
    if ( $descs->length > 0 ) {
        $d = trim( $descs->item(0)->textContent );
        if ( $d !== '' ) $info['desc'] = $d;
    }

    // <text> — ορατό κείμενο (textContent συμπεριλαμβάνει <tspan> παιδιά)
    $texts = [];
    foreach ( $dom->getElementsByTagName( 'text' ) as $node ) {
        $t = trim( $node->textContent );
        if ( $t !== '' ) $texts[] = $t;
    }
    $texts = array_unique( $texts );
    if ( ! empty( $texts ) ) {
        $info['text'] = implode( ' ', $texts );
    }

    return $info;
}

/**
 * Παράγει alt text για SVG χρησιμοποιώντας text-only OpenAI call.
 * Εξάγει title/desc/text από το SVG XML και τα στέλνει ως prompt.
 *
 * @param int    $attachment_id  WordPress attachment ID.
 * @param string $image_title    Τίτλος attachment.
 * @param string $language       'el' ή 'en'.
 * @param string $api_key        OpenAI API key.
 * @param string $model          'gpt-4o-mini' ή 'gpt-4o'.
 *
 * @return string|WP_Error       Alt text ή WP_Error.
 */
function aatg_generate_alt_svg( $attachment_id, $image_title, $language, $api_key, $model = 'gpt-4o-mini' ) {
    if ( empty( $api_key ) || ! preg_match( '/^sk-/', $api_key ) ) {
        return new WP_Error( 'invalid_key', 'Μη έγκυρο API key format.' );
    }

    $allowed_models = [ 'gpt-4o-mini', 'gpt-4o' ];
    if ( ! in_array( $model, $allowed_models, true ) ) {
        $model = 'gpt-4o-mini';
    }

    // Εξαγωγή context από SVG
    $file_path = get_attached_file( $attachment_id );
    $svg_info  = ( $file_path && file_exists( $file_path ) )
        ? aatg_extract_svg_context( $file_path )
        : [];

    // Συλλογή context lines
    $context_parts = [];
    if ( ! empty( $image_title ) ) {
        $context_parts[] = ( $language === 'el' ? 'Όνομα αρχείου: ' : 'File name: ' )
            . sanitize_text_field( $image_title );
    }
    if ( ! empty( $svg_info['title'] ) ) {
        $context_parts[] = ( $language === 'el' ? 'Τίτλος SVG: ' : 'SVG title: ' )
            . sanitize_text_field( $svg_info['title'] );
    }
    if ( ! empty( $svg_info['desc'] ) ) {
        $context_parts[] = ( $language === 'el' ? 'Περιγραφή SVG: ' : 'SVG description: ' )
            . sanitize_text_field( $svg_info['desc'] );
    }
    if ( ! empty( $svg_info['text'] ) ) {
        $context_parts[] = ( $language === 'el' ? 'Κείμενο στο SVG: ' : 'Text inside SVG: ' )
            . sanitize_text_field( $svg_info['text'] );
    }

    if ( empty( $context_parts ) ) {
        return new WP_Error( 'no_svg_context', 'Δεν βρέθηκε περιεχόμενο στο SVG για ανάλυση.' );
    }

    $context = implode( "\n", $context_parts );

    if ( $language === 'el' ) {
        $prompt = 'Βάσει των παρακάτω πληροφοριών για ένα SVG αρχείο, γράψε ένα σύντομο alt text στα Ελληνικά (max 125 χαρακτήρες). Να είναι περιγραφικό και χρήσιμο για screen readers και SEO. Μόνο το alt text, χωρίς εισαγωγικά ή επεξηγήσεις.' . "\n\n" . $context;
    } else {
        $prompt = 'Based on the following information about an SVG file, write a short alt text in English (max 125 characters). Be descriptive and useful for screen readers and SEO. Return only the alt text, no quotes or explanation.' . "\n\n" . $context;
    }

    $body = wp_json_encode( [
        'model'      => $model,
        'max_tokens' => 150,
        'messages'   => [
            [ 'role' => 'user', 'content' => $prompt ],
        ],
    ] );

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_response', 'Μη αναμενόμενη απάντηση από OpenAI (HTTP ' . $code . ').' );
    }

    if ( $code === 401 ) return new WP_Error( 'auth_error', 'Λάθος API key.' );
    if ( $code === 429 ) return new WP_Error( 'rate_limit', 'Rate limit — περίμενε λίγο.' );
    if ( $code === 402 ) return new WP_Error( 'billing', 'Δεν υπάρχουν credits.' );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? 'HTTP ' . $code;
        return new WP_Error( 'openai_error', sanitize_text_field( $msg ) );
    }

    $text = trim( $data['choices'][0]['message']['content'] ?? '' );
    if ( empty( $text ) ) {
        return new WP_Error( 'empty_response', 'Κενή απάντηση από OpenAI.' );
    }

    $text = trim( $text, '"\'«»' );
    return sanitize_text_field( $text );
}
