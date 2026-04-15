<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Admin menu ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    add_media_page(
        'AI Alt Text Generator',
        'AI Alt Text',
        'manage_options',
        'ai-alt-text-generator',
        'aatg_admin_page'
    );
} );

// ─── Settings save ────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
    if (
        isset( $_POST['aatg_save'] ) &&
        check_admin_referer( 'aatg_settings_nonce' )
    ) {
        aatg_check_capability();
        aatg_save_settings( $_POST );
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>✓ Οι ρυθμίσεις αποθηκεύτηκαν.</p></div>';
        } );
    }
} );

// ─── Admin page ───────────────────────────────────────────────────────────────
function aatg_admin_page() {
    aatg_check_capability();

    $s          = aatg_get_settings();
    $bulk_nonce = wp_create_nonce( 'aatg_bulk_nonce' );
    $has_key    = ! empty( $s['api_key'] );
    ?>
    <div class="wrap" id="aatg-wrap">
        <h1 style="display:flex;align-items:center;gap:10px">
            AI Alt Text Generator
            <span style="font-size:12px;background:#e8f5e9;color:#2e7d32;padding:3px 10px;border-radius:20px;font-weight:400">v<?php echo esc_html( AATG_VERSION ); ?></span>
        </h1>
        <p style="color:#666;margin-top:0">Παράγει alt text για εικόνες μέσω <strong>OpenAI GPT-4o mini</strong> ή <strong>GPT-4o</strong>.</p>

        <?php /* ── DASHBOARD ── */ ?>
        <div style="max-width:700px;margin-bottom:2rem">
            <div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:1.2rem">
                <h2 style="margin:0;padding:0;border:none">Dashboard</h2>
                <button id="aatg-refresh-stats" class="button" style="font-size:12px">↻ Ανανέωση</button>
            </div>

            <div id="aatg-stats-loading" style="color:#666;font-size:13px;padding:4px 0">
                Φόρτωση στατιστικών…
            </div>

            <?php /* Stat cards */ ?>
            <div id="aatg-stats-cards"
                 style="display:none;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:1.4rem">
            </div>

            <?php /* Coverage bar */ ?>
            <div id="aatg-coverage-wrap" style="display:none;margin-bottom:1.4rem">
                <div style="display:flex;justify-content:space-between;font-size:13px;color:#666;margin-bottom:5px">
                    <span>Κάλυψη alt text</span>
                    <strong id="aatg-coverage-pct">0%</strong>
                </div>
                <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:14px">
                    <div id="aatg-coverage-bar"
                         style="height:100%;background:#00a32a;width:0%;transition:width .6s ease;border-radius:4px">
                    </div>
                </div>
            </div>

            <?php /* Total API cost */ ?>
            <div id="aatg-cost-wrap"
                 style="display:none;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;
                        padding:10px 16px;margin-bottom:1.4rem;gap:8px;align-items:center">
                <span style="font-size:13px;color:#666">Εκτιμώμενο κόστος API (από ιστορικό):</span>
                <strong id="aatg-total-cost" style="font-size:15px;color:#1d2327">$0.0000</strong>
            </div>

            <?php /* Run history */ ?>
            <div id="aatg-history-wrap" style="display:none">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <strong style="font-size:13px">Ιστορικό εκτελέσεων</strong>
                    <button id="aatg-clear-history" class="button button-small"
                            style="color:#d63638;border-color:#d63638;font-size:12px">
                        Διαγραφή ιστορικού
                    </button>
                </div>
                <table class="wp-list-table widefat fixed striped" style="font-size:12px">
                    <thead>
                        <tr>
                            <th style="width:32%">Ημερομηνία</th>
                            <th style="width:15%">Εικόνες</th>
                            <th style="width:13%">Σφάλματα</th>
                            <th style="width:18%">Μοντέλο</th>
                            <th style="width:12%">Κόστος</th>
                            <th style="width:10%">Κατάσταση</th>
                        </tr>
                    </thead>
                    <tbody id="aatg-history-tbody"></tbody>
                </table>
            </div>
        </div>

        <hr style="max-width:700px;margin:0 0 2rem">

        <?php /* ── SETTINGS FORM ── */ ?>
        <form method="post" style="max-width:700px">
            <?php wp_nonce_field( 'aatg_settings_nonce' ); ?>
            <h2 style="border-bottom:1px solid #ddd;padding-bottom:8px">Ρυθμίσεις</h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="aatg_api_key">OpenAI API Key</label></th>
                    <td>
                        <p id="aatg-key-status" style="margin:0 0 6px;font-size:13px;font-weight:500;color:#2e7d32<?php echo $has_key ? '' : ';display:none'; ?>">
                            ✓ API key είναι ρυθμισμένο
                        </p>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input type="password"
                                   id="aatg_api_key"
                                   name="aatg_api_key"
                                   value=""
                                   class="regular-text"
                                   autocomplete="new-password"
                                   placeholder="<?php echo $has_key ? 'Άφησε κενό για να μη αλλάξει' : 'sk-proj-...'; ?>"
                                   style="font-family:monospace" />
                            <button type="button" id="aatg-test-key" class="button"
                                    <?php echo $has_key ? '' : 'disabled'; ?>>
                                Test Key
                            </button>
                            <button type="button" id="aatg-delete-key" class="button button-link-delete"
                                    style="<?php echo $has_key ? '' : 'display:none'; ?>">
                                Διαγραφή Key
                            </button>
                        </div>
                        <span id="aatg-test-result" style="display:block;margin-top:6px;font-size:13px"></span>
                        <p class="description">
                            Βρες το key στο <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys ↗</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="aatg_model">Μοντέλο OpenAI</label></th>
                    <td>
                        <select id="aatg_model" name="aatg_model">
                            <option value="gpt-4o-mini" <?php selected( $s['model'], 'gpt-4o-mini' ); ?>>
                                GPT-4o mini — Φτηνό (~$0.001/εικόνα) · Συνιστάται
                            </option>
                            <option value="gpt-4o" <?php selected( $s['model'], 'gpt-4o' ); ?>>
                                GPT-4o — Πιο έξυπνο (~$0.01/εικόνα) · Για σύνθετες εικόνες
                            </option>
                        </select>
                        <p class="description">
                            Για alt text το <strong>gpt-4o-mini</strong> είναι αρκετό στο 99% των περιπτώσεων.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="aatg_language">Γλώσσα alt text</label></th>
                    <td>
                        <select id="aatg_language" name="aatg_language">
                            <option value="el" <?php selected( $s['language'], 'el' ); ?>>Ελληνικά</option>
                            <option value="en" <?php selected( $s['language'], 'en' ); ?>>English</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="aatg_batch_size">Εικόνες ανά batch</label></th>
                    <td>
                        <input type="number"
                               id="aatg_batch_size"
                               name="aatg_batch_size"
                               value="<?php echo esc_attr( $s['batch_size'] ); ?>"
                               min="1" max="20"
                               style="width:70px" />
                        <p class="description">Πόσες εικόνες να επεξεργάζεται κάθε φορά (1–20). Default: 5.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Αντικατάσταση</th>
                    <td>
                        <label>
                            <input type="checkbox" name="aatg_overwrite" value="1"
                                   <?php checked( $s['overwrite'], 1 ); ?> />
                            Αντικατάσταση υπαρχόντων alt texts
                        </label>
                        <p class="description" style="color:#d63638">
                            ⚠️ Αν είναι ενεργό, θα αντικαταστήσει alt texts που έχεις βάλει manually.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="aatg_save" class="button button-primary">Αποθήκευση ρυθμίσεων</button>
            </p>
        </form>

        <hr style="max-width:700px;margin:1.5rem 0">

        <?php /* ── BULK SECTION ── */ ?>
        <div style="max-width:700px">
            <h2>Bulk Generation</h2>

            <?php if ( ! $has_key ) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 1rem">
                    <p>Πρέπει να ορίσεις API key πριν τρέξεις το bulk generation.</p>
                </div>
            <?php endif; ?>

            <div style="display:flex;gap:12px;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
                <button id="aatg-count-btn" class="button">
                    Μέτρησε εικόνες χωρίς alt text
                </button>
                <span id="aatg-count-result" style="color:#666;font-size:13px"></span>
            </div>

            <div style="display:flex;gap:8px;align-items:center">
                <button id="aatg-start-btn" class="button button-primary"
                        <?php echo $has_key ? '' : 'disabled'; ?>>
                    ▶ Έναρξη Bulk Generation
                </button>
                <button id="aatg-stop-btn" class="button button-link-delete" style="display:none">
                    ■ Διακοπή
                </button>
            </div>

            <?php /* Progress */ ?>
            <div id="aatg-progress-wrap" style="display:none;margin-top:1.5rem">
                <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:18px;margin-bottom:6px">
                    <div id="aatg-progress-bar"
                         style="height:100%;background:#2271b1;width:0%;transition:width .4s;border-radius:4px"></div>
                </div>
                <p id="aatg-progress-text" style="color:#666;font-size:13px;margin:0 0 1rem"></p>

                <div id="aatg-log"
                     style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;
                            padding:12px 14px;border-radius:4px;max-height:280px;overflow-y:auto;
                            line-height:1.7;white-space:pre-wrap;word-break:break-word">
                </div>
            </div>

            <div id="aatg-done-msg" style="display:none;margin-top:1rem"></div>
        </div>

        <?php /* ── UNINSTALL INFO ── */ ?>
        <hr style="max-width:700px;margin:2rem 0">
        <div style="max-width:700px">
            <h2>Απεγκατάσταση</h2>
            <p style="font-size:13px;color:#666">
                Αν απενεργοποιήσεις ή διαγράψεις το plugin, τα <strong>alt texts που έχουν παραχθεί παραμένουν</strong> στη βάση δεδομένων —
                αποθηκεύονται ως post meta στα attachments και δεν εξαρτώνται από το plugin.
                Διαγράφονται μόνο οι επιλογές <code>aatg_settings</code> (API key, ρυθμίσεις) και <code>aatg_run_history</code> (ιστορικό εκτελέσεων).
            </p>
        </div>

    </div><!-- .wrap -->

    <script>
    (function($){
        var nonce      = '<?php echo esc_js( $bulk_nonce ); ?>';
        var batchSize  = <?php echo (int) $s['batch_size']; ?>;
        var running      = false;
        var stopFlag     = false;
        var totalCount   = 0;
        var totalDone    = 0;
        var totalErrors  = 0;

        // Delete API key
        $('#aatg-delete-key').on('click', function(){
            if ( ! confirm('Είσαι σίγουρος; Το API key θα διαγραφεί οριστικά.') ) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Διαγράφω...');

            $.post(ajaxurl, { action: 'aatg_delete_key', nonce: nonce }, function(res){
                if ( res.success ) {
                    $('#aatg-key-status').hide();
                    $('#aatg_api_key').attr('placeholder', 'sk-proj-...');
                    $('#aatg-test-key').prop('disabled', true);
                    $('#aatg-start-btn').prop('disabled', true);
                    $btn.hide();
                    $('#aatg-test-result').text('✓ Το API key διαγράφηκε.').css('color','#2e7d32');
                } else {
                    $btn.prop('disabled', false).text('Διαγραφή Key');
                    $('#aatg-test-result').text('✗ ' + res.data).css('color','#d63638');
                }
            }).fail(function(){
                $btn.prop('disabled', false).text('Διαγραφή Key');
                $('#aatg-test-result').text('✗ Σφάλμα σύνδεσης').css('color','#d63638');
            });
        });

        // Test API key
        $('#aatg-test-key').on('click', function(){
            var $btn = $(this);
            var $res = $('#aatg-test-result');
            $btn.prop('disabled', true).text('Ελέγχω...');
            $res.text('').css('color','#666');

            $.post(ajaxurl, { action: 'aatg_test_key', nonce: nonce }, function(res){
                if(res.success){
                    $res.text('✓ ' + res.data).css('color','#2e7d32');
                } else {
                    $res.text('✗ ' + res.data).css('color','#d63638');
                }
                $btn.prop('disabled', false).text('Test Key');
            }).fail(function(){
                $res.text('✗ Σφάλμα σύνδεσης').css('color','#d63638');
                $btn.prop('disabled', false).text('Test Key');
            });
        });

        // Count images
        $('#aatg-count-btn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true).text('Μετράω...');
            $.post(ajaxurl, { action: 'aatg_count_images', nonce: nonce }, function(res){
                if(res.success){
                    totalCount = res.data.count;
                    var msg = totalCount === 0
                        ? '✓ Όλες οι εικόνες έχουν alt text!'
                        : totalCount + ' εικόνες χωρίς alt text';
                    $('#aatg-count-result').text(msg);
                }
                $btn.prop('disabled', false).text('Μέτρησε εικόνες χωρίς alt text');
            }).fail(function(){
                $('#aatg-count-result').text('✗ Σφάλμα σύνδεσης. Δοκίμασε ξανά.').css('color','#d63638');
                $btn.prop('disabled', false).text('Μέτρησε εικόνες χωρίς alt text');
            });
        });

        // Start bulk
        $('#aatg-start-btn').on('click', function(){
            if(running) return;
            running      = true;
            stopFlag     = false;
            totalDone    = 0;
            totalErrors  = 0;

            $('#aatg-progress-wrap').show();
            $('#aatg-done-msg').hide().html('');
            $('#aatg-log').html('');
            $('#aatg-progress-bar').css({ width: '0%', background: '#2271b1' });
            $('#aatg-start-btn').prop('disabled', true);
            $('#aatg-stop-btn').show();

            runBatch(0);
        });

        // Stop
        $('#aatg-stop-btn').on('click', function(){
            stopFlag = true;
            $(this).prop('disabled', true).text('■ Διακοπή...');
        });

        function runBatch(offset){
            if(stopFlag){
                finish('Διακόπηκε. Επεξεργάστηκαν ' + totalDone + ' εικόνες.', false, true);
                return;
            }

            var from = offset + 1;
            var to   = offset + batchSize;
            setProgress('Επεξεργασία εικόνων ' + from + '–' + to + '...');

            $.post(ajaxurl, {
                action : 'aatg_bulk_generate',
                nonce  : nonce,
                offset : offset
            }, function(res){
                if(!res.success){
                    finish('Σφάλμα: ' + res.data, true);
                    return;
                }

                var d = res.data;
                totalDone   += (d.processed || 0);
                totalErrors += (d.errors    || 0);

                // Log entries
                if(d.log && d.log.length){
                    var $log = $('#aatg-log');
                    d.log.forEach(function(entry){
                        var color  = entry.status === 'success' ? '#4caf50' : '#f44336';
                        var symbol = entry.status === 'success' ? '✓' : '✗';
                        $log.append(
                            '<span style="color:' + color + '">' + symbol + ' </span>' +
                            escHtml(entry.text) + '\n'
                        );
                    });
                    $log.scrollTop($log[0].scrollHeight);
                }

                // Progress bar
                if(totalCount > 0){
                    var pct = Math.min(100, Math.round((totalDone / totalCount) * 100));
                    $('#aatg-progress-bar').css('width', pct + '%');
                    setProgress('Πρόοδος: ' + totalDone + ' / ' + totalCount + ' εικόνες (' + pct + '%)');
                }

                if(d.done){
                    finish('✓ Ολοκληρώθηκε! Επεξεργάστηκαν ' + totalDone + ' εικόνες.');
                } else {
                    setTimeout(function(){ runBatch(d.next_offset); }, 300);
                }

            }).fail(function(){
                finish('✗ Σφάλμα δικτύου. Δοκίμασε ξανά.', true);
            });
        }

        function setProgress(msg){
            $('#aatg-progress-text').text(msg);
        }

        function finish(msg, isError, isStopped){
            running = false;
            $('#aatg-start-btn').prop('disabled', false);
            $('#aatg-stop-btn').hide().prop('disabled', false).text('■ Διακοπή');
            setProgress('');

            var barColor = isError ? '#d63638' : (isStopped ? '#f0b429' : '#00a32a');
            $('#aatg-progress-bar').css({ width: '100%', background: barColor });

            var noticeType = isError ? 'error' : (isStopped ? 'warning' : 'success');
            $('#aatg-done-msg').show().html(
                '<div class="notice notice-' + noticeType + ' inline"><p>' + escHtml(msg) + '</p></div>'
            );

            // Αποθήκευση run στο ιστορικό και ανανέωση dashboard
            if ( totalDone > 0 ) {
                $.post(ajaxurl, {
                    action    : 'aatg_save_run',
                    nonce     : nonce,
                    processed : totalDone,
                    errors    : totalErrors,
                    stopped   : isStopped ? 1 : 0,
                }, function(){ setTimeout(loadStats, 400); });
            }
        }

        function escHtml(str){
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Dashboard stats ──────────────────────────────────────────────────

        function statCard(label, value, color) {
            return '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;' +
                   'padding:18px 12px;text-align:center;border-top:3px solid ' + color + '">' +
                   '<div style="font-size:30px;font-weight:700;color:' + color + '">' + value + '</div>' +
                   '<div style="font-size:12px;color:#666;margin-top:5px">' + escHtml(label) + '</div>' +
                   '</div>';
        }

        function loadStats() {
            $('#aatg-stats-loading').show().text('Φόρτωση στατιστικών…');
            $('#aatg-stats-cards').hide();
            $('#aatg-coverage-wrap').hide();
            $('#aatg-cost-wrap').hide();
            $('#aatg-history-wrap').hide();

            $.post(ajaxurl, { action: 'aatg_get_stats', nonce: nonce }, function(res) {
                $('#aatg-stats-loading').hide();
                if ( ! res.success ) return;

                var d = res.data;

                // Cards
                var noAltColor = d.without > 0 ? '#d63638' : '#00a32a';
                $('#aatg-stats-cards').html(
                    statCard('Σύνολο εικόνων',    d.total,    '#2271b1') +
                    statCard('Με alt text',        d.with_alt, '#00a32a') +
                    statCard('Χωρίς alt text',     d.without,  noAltColor)
                ).css('display', 'grid');

                // Coverage bar
                $('#aatg-coverage-bar').css('width', d.pct + '%');
                $('#aatg-coverage-pct').text(d.pct + '%');
                $('#aatg-coverage-wrap').show();

                // Cost
                $('#aatg-total-cost').text('$' + parseFloat(d.total_cost).toFixed(4));
                $('#aatg-cost-wrap').css('display', 'flex');

                // History table
                if ( d.history && d.history.length ) {
                    var rows = '';
                    d.history.forEach(function(h) {
                        var errCell = h.errors > 0
                            ? '<span style="color:#d63638">' + h.errors + '</span>'
                            : '0';
                        var status = h.stopped
                            ? '<span style="color:#f0b429">■ Στάση</span>'
                            : '<span style="color:#00a32a">✓ OK</span>';
                        rows += '<tr>' +
                            '<td>' + escHtml(h.date) + '</td>' +
                            '<td>' + h.processed + '</td>' +
                            '<td>' + errCell + '</td>' +
                            '<td style="font-family:monospace">' + escHtml(h.model) + '</td>' +
                            '<td>$' + parseFloat(h.cost_est).toFixed(4) + '</td>' +
                            '<td>' + status + '</td>' +
                            '</tr>';
                    });
                    $('#aatg-history-tbody').html(rows);
                    $('#aatg-history-wrap').show();
                }
            }).fail(function(){
                $('#aatg-stats-loading').text('✗ Σφάλμα φόρτωσης. Δοκίμασε ξανά με ↻ Ανανέωση.').css('color','#d63638').show();
            });
        }

        // Φόρτωση stats κατά την είσοδο στη σελίδα
        loadStats();

        $('#aatg-refresh-stats').on('click', loadStats);

        $('#aatg-clear-history').on('click', function() {
            if ( ! confirm('Είσαι σίγουρος; Το ιστορικό θα διαγραφεί οριστικά.') ) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(ajaxurl, { action: 'aatg_clear_history', nonce: nonce }, function(res) {
                $btn.prop('disabled', false);
                if ( res.success ) loadStats();
            }).fail(function(){
                $btn.prop('disabled', false);
            });
        });

    })(jQuery);
    </script>
    <?php
}
