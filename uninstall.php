<?php
/**
 * Uninstall script.
 * Τρέχει μόνο όταν ο χρήστης πατήσει "Delete" στο Plugins admin.
 * ΔΕΝ διαγράφει alt texts — μόνο τις ρυθμίσεις του plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit; // Άμεση έξοδος αν δεν κληθεί από WP
}

// Διαγραφή μόνο των plugin settings — alt texts παραμένουν στη βάση
delete_option( 'aatg_settings' );
delete_option( 'aatg_run_history' );
