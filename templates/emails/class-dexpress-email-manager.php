<?php
defined('ABSPATH') || exit;

class D_Express_Email_Manager
{
    public function __construct()
    {
        // Hook za kreiranje pošiljke
        add_action('dexpress_after_shipment_created', array($this, 'send_shipment_created_email'), 10, 2);

        // Hook za status updates
        add_action('dexpress_shipment_status_updated', array($this, 'handle_status_update'), 10, 4);
    }

    /**
     * Email kada je pošiljka kreirana
     */
    public function send_shipment_created_email($shipment_id, $order)
    {
        if (get_option('dexpress_auto_status_emails', 'yes') !== 'yes') {
            return;
        }

        // Jedan email po narudžbini
        $email_sent = get_post_meta($order->get_id(), '_dexpress_created_email_sent', true);
        if ($email_sent) {
            return;
        }

        $subject = sprintf('Porudžbina #%s je spremna za isporuku', $order->get_order_number());
        $message = $this->get_shipment_created_template($order);

        $result = wp_mail(
            $order->get_billing_email(),
            $subject,
            $message,
            array('Content-Type: text/html; charset=UTF-8')
        );

        if ($result) {
            update_post_meta($order->get_id(), '_dexpress_created_email_sent', 'yes');
            dexpress_log('[EMAIL] Poslat "kreirana pošiljka" email za order #' . $order->get_id(), 'info');
        } else {
            dexpress_log('[EMAIL] GREŠKA pri slanju "kreirana pošiljka" email za order #' . $order->get_id(), 'error');
        }
    }
    private function send_in_transit_email($order)
    {
        $email_sent = get_post_meta($order->get_id(), '_dexpress_in_transit_email_sent', true);
        if ($email_sent) {
            return;
        }

        $subject = sprintf('Porudžbina #%s je preuzeta za transport', $order->get_order_number());
        $message = $this->get_in_transit_template($order);

        $result = wp_mail(
            $order->get_billing_email(),
            $subject,
            $message,
            array('Content-Type: text/html; charset=UTF-8')
        );

        if ($result) {
            update_post_meta($order->get_id(), '_dexpress_in_transit_email_sent', 'yes');
            dexpress_log('[EMAIL] Poslat "u transportu" email za order #' . $order->get_id(), 'info');
        }
    }

    private function get_in_transit_template($order)
    {
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();

        return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            .timeline-progress { width: 100%; height: 6px; background: #f0f0f0; border-radius: 3px; margin: 20px 0; }
            .progress-bar { height: 100%; background: linear-gradient(90deg, #17a2b8, #20c997); border-radius: 3px; width: 50%; }
            .timeline-steps { display: flex; justify-content: space-between; margin-top: 10px; }
            .step { text-align: center; flex: 1; font-size: 12px; }
            .step.active { color: #17a2b8; font-weight: bold; }
            .step.completed { color: #28a745; }
        </style>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #17a2b8;'>Pozdrav {$customer_name}!</h2>
            
            <p>Vaša porudžbina <strong>#{$order_number}</strong> je preuzeta od strane kurira.</p>
            
            <div class='timeline-progress'>
                <div class='progress-bar'></div>
            </div>
            
            <div class='timeline-steps'>
                <div class='step completed'>✓ Kreirana</div>
                <div class='step active'>📦 U transportu</div>
                <div class='step'>🚚 Na putu</div>
                <div class='step'>✅ Isporučena</div>
            </div>
            
            <div style='background: #d1ecf1; padding: 15px; border-left: 4px solid #17a2b8; margin: 20px 0;'>
                <p style='margin: 0;'><strong>Status:</strong> Paket je preuzet i u transportu ka vašoj lokaciji</p>
            </div>
            
            <p>Uskoro će biti na putu za finalnu dostavu do vas.</p>
            
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>Automatska poruka - ne odgovarajte</p>
        </div>
    </body>
    </html>";
    }
    /**
     * Email za status updates
     */
    public function handle_status_update($shipment, $status_id, $status_description, $order)
    {
        // Anti-spam za order notes
        $last_note_meta = '_dexpress_last_note_' . $status_id;
        $last_note = get_post_meta($order->get_id(), $last_note_meta, true);

        if (!$last_note || (time() - strtotime($last_note)) > 1800) { // 30 minuta
            $note = sprintf('D Express status: %s', $status_description);
            $order->add_order_note($note);
            update_post_meta($order->get_id(), $last_note_meta, current_time('mysql'));
        }

        // Email logika
        if (get_option('dexpress_auto_status_emails', 'yes') !== 'yes') {
            return;
        }

        $last_email_meta = '_dexpress_last_email_' . $status_id;
        $last_sent = get_post_meta($order->get_id(), $last_email_meta, true);

        if ($last_sent && (time() - strtotime($last_sent)) < 7200) {
            return;
        }

        $status_group = dexpress_get_status_group($status_id);

        switch ($status_group) {
            case 'delivered':
                $this->send_delivered_email($order);
                update_post_meta($order->get_id(), $last_email_meta, current_time('mysql'));
                break;
            case 'out_for_delivery':
                $this->send_out_for_delivery_email($order);
                update_post_meta($order->get_id(), $last_email_meta, current_time('mysql'));
                break;
            // DODAJ OVO:
            case 'transit':
                if ($status_id === '3') { // Samo za "preuzeta od pošiljaoca"
                    $this->send_in_transit_email($order);
                    update_post_meta($order->get_id(), $last_email_meta, current_time('mysql'));
                }
                break;
        }
    }
    private function send_delivered_email($order)
    {
        // Proveri da li je već poslat
        $email_sent = get_post_meta($order->get_id(), '_dexpress_delivered_email_sent', true);
        if ($email_sent) {
            return;
        }

        $subject = sprintf('Porudžbina #%s je isporučena', $order->get_order_number());
        $message = $this->get_delivered_template($order);

        $result = wp_mail(
            $order->get_billing_email(),
            $subject,
            $message,
            array('Content-Type: text/html; charset=UTF-8')
        );

        if ($result) {
            update_post_meta($order->get_id(), '_dexpress_delivered_email_sent', 'yes');
            dexpress_log('[EMAIL] Poslat "isporučeno" email za order #' . $order->get_id(), 'info');
        }
    }

    private function send_out_for_delivery_email($order)
    {
        // Proveri da li je već poslat
        $email_sent = get_post_meta($order->get_id(), '_dexpress_out_for_delivery_email_sent', true);
        if ($email_sent) {
            return;
        }

        $subject = sprintf('Porudžbina #%s je na putu za dostavu', $order->get_order_number());
        $message = $this->get_out_for_delivery_template($order);

        $result = wp_mail(
            $order->get_billing_email(),
            $subject,
            $message,
            array('Content-Type: text/html; charset=UTF-8')
        );

        if ($result) {
            update_post_meta($order->get_id(), '_dexpress_out_for_delivery_email_sent', 'yes');
            dexpress_log('[EMAIL] Poslat "na putu za dostavu" email za order #' . $order->get_id(), 'info');
        }
    }

    private function get_shipment_created_template($order)
    {
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();

        return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            .timeline-progress { 
                width: 100%; 
                height: 6px; 
                background: #f0f0f0; 
                border-radius: 3px; 
                margin: 20px 0; 
                position: relative;
            }
            .progress-bar { 
                height: 100%; 
                background: linear-gradient(90deg, #2c5aa0, #4a90e2); 
                border-radius: 3px; 
                width: 25%; 
                transition: width 0.3s ease;
            }
            .timeline-steps { 
                display: flex; 
                justify-content: space-between; 
                margin-top: 10px;
            }
            .step { 
                text-align: center; 
                flex: 1; 
                font-size: 12px;
            }
            .step.active { 
                color: #2c5aa0; 
                font-weight: bold;
            }
            .step.completed { 
                color: #28a745; 
            }
        </style>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2c5aa0;'>Pozdrav {$customer_name}!</h2>
            
            <p>Vaša porudžbina <strong>#{$order_number}</strong> je pripremljena za preuzimanje.</p>
            
            <div class='timeline-progress'>
                <div class='progress-bar'></div>
            </div>
            
            <div class='timeline-steps'>
                <div class='step completed'>✓ Kreirana</div>
                <div class='step active'>📦 Čeka preuzimanje</div>
                <div class='step'>🚚 Transport</div>
                <div class='step'>✅ Isporučena</div>
            </div>
            
            <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #2c5aa0; margin: 20px 0;'>
                <p style='margin: 0;'><strong>Trenutni status:</strong> Paket čeka da ga preuzme D Express kurirska služba</p>
            </div>
            
            <p>Očekujte uskoro obaveštenje kada kurir preuzme paket.</p>
            
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>Automatska poruka - ne odgovarajte</p>
        </div>
    </body>
    </html>";
    }

    private function get_delivered_template($order)
    {
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();

        return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            .timeline-progress { 
                width: 100%; 
                height: 6px; 
                background: #f0f0f0; 
                border-radius: 3px; 
                margin: 20px 0;
            }
            .progress-bar { 
                height: 100%; 
                background: linear-gradient(90deg, #28a745, #20c997); 
                border-radius: 3px; 
                width: 100%;
            }
            .timeline-steps { 
                display: flex; 
                justify-content: space-between; 
                margin-top: 10px;
            }
            .step { 
                text-align: center; 
                flex: 1; 
                font-size: 12px; 
                color: #28a745; 
                font-weight: bold;
            }
        </style>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #28a745;'>Pozdrav {$customer_name}!</h2>
            
            <p>Vaša porudžbina <strong>#{$order_number}</strong> je uspešno isporučena.</p>
            
            <div class='timeline-progress'>
                <div class='progress-bar'></div>
            </div>
            
            <div class='timeline-steps'>
                <div class='step'>✓ Kreirana</div>
                <div class='step'>✓ Preuzeta</div>
                <div class='step'>✓ Dostava</div>
                <div class='step'>🎉 ISPORUČENA</div>
            </div>
            
            <div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0;'>
                <p style='margin: 0;'><strong>Status:</strong> Kompletno ✅</p>
            </div>
            
            <p>Hvala što kupujete kod nas!</p>
            
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>Automatska poruka - ne odgovarajte</p>
        </div>
    </body>
    </html>";
    }

    private function get_out_for_delivery_template($order)
    {
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();

        return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            .timeline-progress { 
                width: 100%; 
                height: 6px; 
                background: #f0f0f0; 
                border-radius: 3px; 
                margin: 20px 0;
            }
            .progress-bar { 
                height: 100%; 
                background: linear-gradient(90deg, #fd7e14, #ff9500); 
                border-radius: 3px; 
                width: 75%;
            }
            .timeline-steps { 
                display: flex; 
                justify-content: space-between; 
                margin-top: 10px;
            }
            .step { 
                text-align: center; 
                flex: 1; 
                font-size: 12px;
            }
            .step.active { 
                color: #fd7e14; 
                font-weight: bold;
            }
            .step.completed { 
                color: #28a745; 
            }
        </style>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #fd7e14;'>Pozdrav {$customer_name}!</h2>
            
            <p>Vaša porudžbina <strong>#{$order_number}</strong> je na putu za dostavu.</p>
            
            <div class='timeline-progress'>
                <div class='progress-bar'></div>
            </div>
            
            <div class='timeline-steps'>
                <div class='step completed'>✓ Kreirana</div>
                <div class='step completed'>✓ Preuzeta</div>
                <div class='step active'>🚚 Na putu</div>
                <div class='step'>✅ Isporučena</div>
            </div>
            
            <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #fd7e14; margin: 20px 0;'>
                <p style='margin: 0;'><strong>Status:</strong> Kurir je na putu do vas</p>
            </div>
            
            <p>Pripremite se za preuzimanje. Kurir će vas kontaktirati.</p>
            
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #666;'>Automatska poruka - ne odgovarajte</p>
        </div>
    </body>
    </html>";
    }
}

new D_Express_Email_Manager();
