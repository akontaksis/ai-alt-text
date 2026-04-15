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

        <?php /* ── SETTINGS FORM ── */ ?>
        <form method="post" style="max-width:700px">
            <?php wp_nonce_field( 'aatg_settings_nonce' ); ?>
            <h2 style="border-bottom:1px solid #ddd;padding-bottom:8px">Ρυθμίσεις</h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="aatg_api_key">OpenAI API Key</label></th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="password"
                                   id="aatg_api_key"
                                   name="aatg_api_key"
                                   value="<?php echo esc_attr( $s['api_key'] ); ?>"
                                   class="regular-text"
                                   autocomplete="off"
                                   placeholder="sk-proj-..."
                                   style="font-family:monospace" />
                            <button type="button" id="aatg-toggle-key" class="button" title="Εμφάνιση/Απόκρυψη">👁</button>
                            <button type="button" id="aatg-test-key" class="button"
                                    <?php echo $has_key ? '' : 'disabled'; ?>>
                                Test Key
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
                Διαγράφεται μόνο η επιλογή <code>aatg_settings</code> (API key, ρυθμίσεις).
            </p>
        </div>

    </div><!-- .wrap -->

    <script>
    (function($){
        var nonce      = '<?php echo esc_js( $bulk_nonce ); ?>';
        var batchSize  = <?php echo (int) $s['batch_size']; ?>;
        var running    = false;
        var stopFlag   = false;
        var totalCount = 0;
        var totalDone  = 0;

        // Toggle key visibility
        $('#aatg-toggle-key').on('click', function(){
            var $f = $('#aatg_api_key');
            $f.attr('type', $f.attr('type') === 'password' ? 'text' : 'password');
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
            });
        });

        // Start bulk
        $('#aatg-start-btn').on('click', function(){
            if(running) return;
            running   = true;
            stopFlag  = false;
            totalDone = 0;

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
                totalDone += (d.processed || 0);

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
        }

        function escHtml(str){
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

    })(jQuery);
    </script>
    <?php
}
