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
