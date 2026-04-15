<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Bulk generate ────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_bulk_generate', 'aatg_ajax_bulk_generate' );

function aatg_ajax_bulk_generate() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $settings   = aatg_get_settings();
    $api_key    = $settings['api_key'];
    $language   = $settings['language'];
    $model      = $settings['model'];
    $overwrite  = (bool) $settings['overwrite'];
    $batch_size = $settings['batch_size'];
    $offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

    if ( empty( $api_key ) ) {
        wp_send_json_error( 'Δεν έχεις ορίσει API key στις ρυθμίσεις.' );
    }

    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ( ! $overwrite ) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
        ];
    }

    $ids = get_posts( $args );

    if ( empty( $ids ) ) {
        wp_send_json_success( [
            'done'        => true,
            'processed'   => 0,
            'errors'      => 0,
            'log'         => [],
            'next_offset' => $offset,
            'message'     => 'Όλες οι εικόνες έχουν ήδη alt text!',
        ] );
    }

    $processed = 0;
    $errors    = 0;
    $log       = [];

    foreach ( $ids as $id ) {
        $url   = wp_get_attachment_url( $id );
        $title = get_the_title( $id );

        if ( ! $url ) {
            $errors++;
            $log[] = [ 'status' => 'error', 'text' => 'ID ' . $id . ': δεν βρέθηκε URL' ];
            continue;
        }

        $result = aatg_generate_alt( $url, $title, $language, $api_key, $model );

        if ( is_wp_error( $result ) ) {
            $errors++;
            $log[] = [
                'status' => 'error',
                'text'   => basename( $url ) . ': ' . $result->get_error_message(),
            ];
        } else {
            update_post_meta( $id, '_wp_attachment_image_alt', $result );
            $processed++;
            $log[] = [
                'status' => 'success',
                'text'   => basename( $url ) . ': ' . $result,
            ];
        }
    }

    wp_send_json_success( [
        'done'        => count( $ids ) < $batch_size,
        'processed'   => $processed,
        'errors'      => $errors,
        'log'         => $log,
        'next_offset' => $offset + count( $ids ),
    ] );
}

// ─── Count images ─────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_count_images', 'aatg_ajax_count_images' );

function aatg_ajax_count_images() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $settings  = aatg_get_settings();
    $overwrite = (bool) $settings['overwrite'];

    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    if ( ! $overwrite ) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
        ];
    }

    $ids = get_posts( $args );
    wp_send_json_success( [ 'count' => count( $ids ) ] );
}

// ─── Delete API key ───────────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_delete_key', 'aatg_ajax_delete_key' );

function aatg_ajax_delete_key() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $existing            = get_option( AATG_OPTION_KEY, [] );
    $existing['api_key'] = '';
    update_option( AATG_OPTION_KEY, $existing );

    wp_send_json_success( 'Το API key διαγράφηκε.' );
}

// ─── Save completed run to history ───────────────────────────────────────────
add_action( 'wp_ajax_aatg_save_run', 'aatg_ajax_save_run' );

function aatg_ajax_save_run() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $processed = max( 0, (int) ( $_POST['processed'] ?? 0 ) );
    $errors    = max( 0, (int) ( $_POST['errors']    ?? 0 ) );
    $stopped   = ! empty( $_POST['stopped'] );

    $settings       = aatg_get_settings();
    $model          = $settings['model'];
    $cost_per_image = ( $model === 'gpt-4o' ) ? 0.01 : 0.001;

    $entry = [
        'date'      => current_time( 'mysql' ),
        'processed' => $processed,
        'errors'    => $errors,
        'model'     => $model,
        'cost_est'  => round( $processed * $cost_per_image, 4 ),
        'stopped'   => $stopped,
    ];

    $history = get_option( 'aatg_run_history', [] );
    array_unshift( $history, $entry );       // νεότερο πρώτο
    $history = array_slice( $history, 0, 20 ); // μέγιστο 20 εγγραφές
    update_option( 'aatg_run_history', $history );

    wp_send_json_success( 'OK' );
}

// ─── Get dashboard stats ──────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_get_stats', 'aatg_ajax_get_stats' );

function aatg_ajax_get_stats() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $base_args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    $total   = count( get_posts( $base_args ) );

    $without = count( get_posts( array_merge( $base_args, [
        'meta_query' => [
            'relation' => 'OR',
            [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
        ],
    ] ) ) );

    $with = $total - $without;
    $pct  = $total > 0 ? round( ( $with / $total ) * 100 ) : 0;

    $history    = get_option( 'aatg_run_history', [] );
    $total_cost = array_sum( array_column( $history, 'cost_est' ) );

    wp_send_json_success( [
        'total'      => $total,
        'with_alt'   => $with,
        'without'    => $without,
        'pct'        => $pct,
        'total_cost' => round( $total_cost, 4 ),
        'history'    => $history,
    ] );
}

// ─── Clear run history ────────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_clear_history', 'aatg_ajax_clear_history' );

function aatg_ajax_clear_history() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    update_option( 'aatg_run_history', [] );
    wp_send_json_success( 'Το ιστορικό διαγράφηκε.' );
}

// ─── Test API key ─────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aatg_test_key', 'aatg_ajax_test_key' );

function aatg_ajax_test_key() {
    aatg_verify_ajax_nonce( 'aatg_bulk_nonce', 'nonce' );
    aatg_check_capability();

    $settings = aatg_get_settings();
    $api_key  = $settings['api_key'];

    if ( empty( $api_key ) ) {
        wp_send_json_error( 'Δεν έχεις ορίσει API key.' );
    }

    // Simple models list call — δεν ξοδεύει credits
    $response = wp_remote_get( 'https://api.openai.com/v1/models', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Σφάλμα σύνδεσης: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );

    if ( $code === 200 ) {
        wp_send_json_success( 'Το API key είναι έγκυρο και λειτουργεί!' );
    } elseif ( $code === 401 ) {
        wp_send_json_error( 'Λάθος API key. Έλεγξε ότι το έχεις αντιγράψει σωστά.' );
    } elseif ( $code === 429 ) {
        wp_send_json_error( 'Rate limit — το key είναι έγκυρο αλλά έχεις ξεπεράσει το όριο.' );
    } else {
        wp_send_json_error( 'HTTP ' . $code . ' — δοκίμασε ξανά.' );
    }
}
