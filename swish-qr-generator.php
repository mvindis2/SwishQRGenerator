<?php
/**
 * Plugin Name: Swish QR Generator
 * Description: Genererar Swish QR-koder från ett formulär och skickar notifiering till admin.
 * Version: 1.0
 * Author: Martin Vindis
 */

if (!defined('ABSPATH')) exit; // Säkerhetskontroll för att förhindra direkt åtkomst

// Registrera shortcode för formuläret som kan användas på sidor
add_shortcode('swish_qr_form', 'swish_qr_form_shortcode');

/**
 * Funktion som genererar formuläret via shortcode
 * Returnerar HTML för formuläret och QR-kod modalfönster
 */
function swish_qr_form_shortcode() {
    ob_start();
    $show_magazine = get_option('swish_qr_show_magazine', '1');
    $default_amount_options = ['300', '500', '1000'];
    $amount_options = get_option('swish_qr_amount_options', $default_amount_options);
    
    // Säkerställ att options är en array
    if (!is_array($amount_options) || empty($amount_options)) {
        $amount_options = $default_amount_options;
    }

    // Enqueue JavaScript file
    wp_enqueue_script('swish-qr-generator', plugins_url('swish-qr-generator.js', __FILE__), array(), '1.0', true);
    
    // Add necessary data for JavaScript
    wp_localize_script('swish-qr-generator', 'swishQRData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'swishNumber' => get_option('swish_qr_payee_number', '0123456789')
    ));
    ?>
    <div style="display: flex; justify-content: center; align-items: flex-start;">
        <form id="swish-form" style="width: 420px; margin: 0 auto;">
          <?php foreach ($amount_options as $index => $amount): 
              $checked = ($index === 0) ? 'checked' : '';
          ?>
          <label><input type="radio" name="amount" value="<?php echo esc_attr($amount); ?>" <?php echo $checked; ?> required> <?php echo esc_html($amount); ?> kr</label>
          <?php endforeach; ?>
          <br>
          <label><input type="radio" name="amount" value="custom"> Valfritt belopp</label><br>
          <input type="number" name="custom_amount" placeholder="Ange belopp" style="width: 100%;" min="1" readonly>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
                const amountRadios = document.querySelectorAll('input[name="amount"]');
                const customAmountField = document.querySelector('input[name="custom_amount"]');
                
                amountRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        if (this.value === 'custom') {
                            customAmountField.required = true;
                            customAmountField.readOnly = true;
                        } else {
                            customAmountField.required = false;
                            customAmountField.readOnly = false;
                        }
                    });
                });

                customAmountField.addEventListener('input', function() {
                    if (this.value) {
                        document.querySelector('input[value="custom"]').checked = true;
                    }
                });
            });
          </script>
          <br>
          <br>
          <?php if ($show_magazine == '1'): ?>
          <input type="checkbox" name="magazine"> Jag/vi vill ha tidningen<br><br>
          <?php endif; ?>

        <input type="text" name="firstname" placeholder="Förnamn" required style="width: 49%;">
        <input type="text" name="lastname" placeholder="Efternamn" required style="width: 49%; float: right;"><br>
        <input type="text" name="address" placeholder="Adress" required style="width: 100%;"><br>
        <input type="text" name="postal_code" placeholder="Postnummer" required style="width: 49%;">
        <input type="text" name="city" placeholder="Stad" required style="width: 49%; float: right;"><br>
        <input type="tel" name="mobile" placeholder="Mobilnummer" required style="width: 100%;"><br>
        <input type="email" name="email" placeholder="E-post" required style="width: 100%;"><br>
        <br>
        <button type="submit" style="width: 100%; height: 45px;">Gå vidare till betalning</button>
        </form>
    </div>

    <!-- QR-kod modalfönster som visas efter formulärsubmit -->
    <div id="qr-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(168, 14, 68, 1); padding:20px; border-radius:16px; z-index:999; text-align:center; width: 685px; height: 654px;">
        <div style="background:#fff; padding:20px; border-radius:8px; max-width:100%; max-height:100%; text-align:center; overflow: hidden;">
            <h2>Skanna QR-koden med Swish för att betala</h2>
            <img id="qr-image" alt="Genererar QR-kod..." style="max-width:100%; max-height: 450px; height:auto;" />
            <br>
            <button onclick="document.getElementById('qr-modal').style.display='none'">Stäng</button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Registrera AJAX-hanterare för både inloggade och icke-inloggade användare
add_action('wp_ajax_generate_swish_qr', 'handle_swish_qr_request');
add_action('wp_ajax_nopriv_generate_swish_qr', 'handle_swish_qr_request');
add_action('wp_ajax_send_admin_notification', 'handle_admin_notification');
add_action('wp_ajax_nopriv_send_admin_notification', 'handle_admin_notification');

// Funktion för att formatera mobilnummer
function format_mobile_number($mobile) {
    // Ta bort alla icke-numeriska tecken
    $mobile = preg_replace('/\D/', '', $mobile);
    // Kontrollera längden och formatera
    if (strlen($mobile) == 10) {
        return substr($mobile, 0, 4) . '-' . substr($mobile, 4, 2) . ' ' . substr($mobile, 6, 2) . ' ' . substr($mobile, 8, 2);
    }
    return $mobile; // Returnera oformaterat om det inte är 10 siffror
}

// Funktion för att formatera postnummer
function format_postal_code($postal_code) {
    // Ta bort alla icke-numeriska tecken
    $postal_code = preg_replace('/\D/', '', $postal_code);
    // Kontrollera längden och formatera
    if (strlen($postal_code) == 5) {
        return substr($postal_code, 0, 3) . ' ' . substr($postal_code, 3, 2);
    }
    return $postal_code; // Returnera oformaterat om det inte är 5 siffror
}

// Funktion för att skicka e-postnotifiering till admin
function send_admin_notification($data) {
    $admin_email = get_option('swish_qr_admin_email', 'admin@epost.se');
    $full_name = $data['firstname'] . " " . $data['lastname'];
    $subject = "Ny Swish-betalning initierad";
    
    $message = "En ny Swish-betalning har initierats med följande information:\n\n";
    $message .= "Namn: " . $full_name . "\n";
    $message .= "Adress: " . $data['address'] . "\n";
    $message .= "Postnummer: " . format_postal_code($data['postal_code']) . "\n";
    $message .= "Stad: " . $data['city'] . "\n";
    $message .= "Mobilnummer: " . format_mobile_number($data['mobile']) . "\n";
    $message .= "E-post: " . $data['email'] . "\n";
    $message .= "Tidning: " . $data['magazine'] . "\n";
    $message .= "Belopp: " . $data['amount'] . " kr\n";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    
    return wp_mail($admin_email, $subject, $message, $headers);
}

/**
 * Hanterar AJAX-förfrågningar för QR-kod generering
 * Tar emot data från formuläret, genererar QR-kod via Swish API och skickar e-postnotifiering
 */
function handle_swish_qr_request() {
    $data = json_decode(file_get_contents("php://input"), true);

    // Validera och rensa inkommande data
    $name = sanitize_text_field($data['firstname']) . ' ' . sanitize_text_field($data['lastname']);
    $address = sanitize_text_field($data['address']);
    $city = sanitize_text_field($data['city']);
    $postal_code = sanitize_text_field($data['postal_code']);
    $mobile = sanitize_text_field($data['mobile']);
    $email = sanitize_text_field($data['email']);
    $magazine = sanitize_text_field($data['magazine']);
    $amount = (int) $data['amount'];

    if (!$name || !$amount) {
        http_response_code(400);
        echo "Ogiltig data";
        wp_die();
    }

    // Hämta inställningar
    $admin_email = get_option('swish_qr_admin_email', 'admin@epost.se');
    $swish_number = get_option('swish_qr_payee_number', '0123456789');

    // Förbereder data för Swish API
    $payload = [
        "format" => "png",
        "payee" => ["value" => $swish_number, "editable" => false],
        "amount" => ["value" => $amount, "editable" => false],
        "message" => ["value" => $name, "editable" => false],
        "size" => 450,
        "transparent" => true
    ];

    // Anropa Swish API för att generera QR-kod
    $ch = curl_init("https://mpc.getswish.net/qrg-swish/api/v1/prefilled");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Returnera QR-koden till klienten
    header("Content-Type: $contentType");
    echo $response;
    wp_die();
}

// Funktion för att hantera admin notifiering via AJAX
function handle_admin_notification() {
    $data = json_decode(file_get_contents("php://input"), true);
    $result = send_admin_notification($data);
    wp_send_json(['success' => $result]);
}

// Inställningar i adminpanelen
add_action('admin_menu', 'swish_qr_add_admin_menu');
add_action('admin_init', 'swish_qr_settings_init');
add_action('admin_enqueue_scripts', 'swish_qr_admin_scripts');

/**
 * Läser in jQuery på inställningssidan
 */
function swish_qr_admin_scripts($hook) {
    if ($hook != 'settings_page_swish_qr_settings') {
        return;
    }
    wp_enqueue_script('jquery');
}

/**
 * Lägger till en sida i adminmenyn under Inställningar
 */
function swish_qr_add_admin_menu() {
    add_options_page('Swish QR Inställningar', 'Swish QR Generator', 'manage_options', 'swish_qr_settings', 'swish_qr_settings_page');
}

/**
 * Registrerar inställningar, sektioner och fält
 */
function swish_qr_settings_init() {
    // Registrera inställningar som ska sparas i databasen
    register_setting('swish_qr', 'swish_qr_admin_email');
    register_setting('swish_qr', 'swish_qr_payee_number');
    register_setting('swish_qr', 'swish_qr_show_magazine');
    register_setting('swish_qr', 'swish_qr_amount_options');

    // Skapa sektioner för inställningarna
    add_settings_section('swish_qr_section', 'Grund-inställningar', null, 'swish_qr_settings');
    add_settings_section('swish_qr_amount_section', 'Belopps-inställningar', null, 'swish_qr_settings');

    // Lägg till fält i sektionerna
    add_settings_field('swish_qr_admin_email', 'Admin e-postadress', 'swish_qr_admin_email_render', 'swish_qr_settings', 'swish_qr_section');
    add_settings_field('swish_qr_payee_number', 'Swish-nummer', 'swish_qr_payee_number_render', 'swish_qr_settings', 'swish_qr_section');
    add_settings_field('swish_qr_show_magazine', 'Erbjud tidning som tillval', 'swish_qr_show_magazine_render', 'swish_qr_settings', 'swish_qr_section');
    add_settings_field('swish_qr_amount_options', 'Valbara belopp', 'swish_qr_amount_options_render', 'swish_qr_settings', 'swish_qr_amount_section');
}

/**
 * Renderar fältet för admin e-postadress
 */
function swish_qr_admin_email_render() {
    $value = get_option('swish_qr_admin_email', '');
    echo "<input type='email' name='swish_qr_admin_email' value='" . esc_attr($value) . "' style='width: 400px;' required />";
}

/**
 * Renderar fältet för Swish-nummer
 */
function swish_qr_payee_number_render() {
    $value = get_option('swish_qr_payee_number', '');
    echo "<input type='text' name='swish_qr_payee_number' value='" . esc_attr($value) . "' required />";
}

/**
 * Renderar checkbox för att visa tidningsalternativ
 */
function swish_qr_show_magazine_render() {
    $value = get_option('swish_qr_show_magazine', '1');
    echo "<input type='checkbox' name='swish_qr_show_magazine' value='1' " . checked('1', $value, false) . " />";
}

/**
 * Renderar fält för beloppsalternativ
 * Låter administratören lägga till och ta bort beloppsalternativ dynamiskt
 */
function swish_qr_amount_options_render() {
    $default_amount_options = ['300', '500', '1000'];
    $options = get_option('swish_qr_amount_options', $default_amount_options);
    
    // Säkerställ att options är en array
    if (!is_array($options)) {
        $options = $default_amount_options;
        update_option('swish_qr_amount_options', $options);
    }
    
    echo '<div id="amount-options-container">';
    
    foreach ($options as $index => $amount) {
        echo '<div class="amount-option">';
        echo '<input type="text" name="swish_qr_amount_options[]" value="' . esc_attr($amount) . '" placeholder="Belopp" style="width: 90px;" required /> ';
        echo '<button type="button" class="button button-small remove-option" style="width: 54px; height: 30px;">Ta bort</button><br><br>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<button type="button" id="add-amount-option" class="button" style="width: 150px; height: 30px;">Lägg till alternativ</button>';
    
    // JavaScript för att hantera tillägg/borttagning av alternativ
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var container = $('#amount-options-container');
        
        // Lägg till ett nytt beloppsalternativ
        $('#add-amount-option').on('click', function() {
            var newOption = $('<div class="amount-option"></div>');
            newOption.append('<input type="text" name="swish_qr_amount_options[]" placeholder="Belopp" style="width: 90px;" required /> ');
            newOption.append('<button type="button" class="button button-small remove-option" style="width: 54px; height: 30px;">Ta bort</button><br><br>');
            container.append(newOption);
        });
        
        // Ta bort ett beloppsalternativ
        $(document).on('click', '.remove-option', function() {
            $(this).closest('.amount-option').remove();
        });
    });
    </script>
    <?php
}

/**
 * Renderar inställningssidan i admin
 */
function swish_qr_settings_page() {
    ?>
    <div class="wrap">
    <h1>Swish QR Generator-inställningar</h1>
    <p>För att lägga till Swish QR-formuläret på en sida, använd följande kortkod:</p>
    <code>[swish_qr_form]</code>
    <style>
    .swish-qr-settings h2 {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #ccc;
    }
    .swish-qr-settings h2:first-child {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
    }
    </style>
    <form action="options.php" method="post" class="swish-qr-settings">
    <?php
    settings_fields('swish_qr');
    do_settings_sections('swish_qr_settings');
    submit_button();
    ?>
    </form>
    </div>
    <?php
}

// Lägg till inställningslänk under "Tillägg"
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'swish_qr_plugin_action_links');

/**
 * Lägger till en länk till inställningssidan från tilläggslistan
 */
function swish_qr_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=swish_qr_settings') . '">Inställningar</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Aktivering av plugin
register_activation_hook(__FILE__, 'swish_qr_activate');

/**
 * Körs när pluginet aktiveras
 * Sätter standardvärden för inställningar
 */
function swish_qr_activate() {
    // Standardvärden när plugin aktiveras
    if (get_option('swish_qr_amount_options') === false) {
        update_option('swish_qr_amount_options', ['300', '500', '1000']);
    }
    if (get_option('swish_qr_show_magazine') === false) {
        update_option('swish_qr_show_magazine', '1');
    }
}