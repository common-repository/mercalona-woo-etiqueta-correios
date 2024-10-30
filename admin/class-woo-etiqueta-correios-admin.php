<?php

class Woo_Etiqueta_Correios_Admin
{

    private $plugin_name;
    private $version;
    private $api_url;
    private $app_url;
    private $open_order_list_url;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->api_url = 'https://api.mercalona.com/';
//        $this->api_url = 'http://host.docker.internal:8000/';
        $this->app_url = 'https://app.mercalona.com/';
        $this->open_order_list_url = $this->app_url . 'public/order-list/';

    }

    public function enqueue_styles()
    {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/woo-etiqueta-correios-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/woo-etiqueta-correios-admin.js', array('jquery'), $this->version, false);
    }

    public function general()
    {
        echo "<pre>";
        var_dump(get_current_screen());
        echo "</pre>";
        exit();
    }

    public function addActionItemBulk($bulk_actions)
    {
        $bulk_actions['woo_etiqueta_correios'] = __('Gerar etiquetas correios', 'woo_etiqueta_correios');
        return $bulk_actions;
    }

    public function handleActionsBulk($redirect_to, $action_name, $post_ids)
    {
        if ('woo_etiqueta_correios' === $action_name) {
            $orderAddresses = $this->getOrdersData($post_ids);
            $response = $this->sendOrdersToMercalona($orderAddresses);
            if ($response->success) {
                $link_id = $response->public_id;
                $message = $response->message;
                $redirect_to = add_query_arg('mercalona_status', 'success', $redirect_to);
                $redirect_to = add_query_arg('mercalona_label_id', $link_id, $redirect_to);
                $redirect_to = add_query_arg('mercalona_message', $message, $redirect_to);
            } else {
                $message = $response->message;
                if ($response->errors)
                    $this->errorsFormatter($message, $response->errors);
                $redirect_to = add_query_arg('mercalona_status', 'error', $redirect_to);
                $redirect_to = add_query_arg('mercalona_message', $message, $redirect_to);
            }
        }

        return $redirect_to;
    }

    private function errorMessageToObject($message)
    {
        $response_array = ['success' => false, 'message' => $message];
        $response_object = json_decode(json_encode($response_array), FALSE);
        return $response_object;
    }

    private function sendOrdersToMercalona($addresses)
    {
        $auth = base64_encode(get_option('mercalona_id') . ':' . get_option('mercalona_token'));
        $url = $this->api_url . 'api/order/bulk-create';
        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => "Bearer $auth"
            ],
            'body' => ['orders' => $addresses],
        ];
        $response = wp_remote_post($url, $args);
//        $this->vDump($response, true);

        if (!is_array($response) && count($response->errors) > 0) {
            $error_message = 'Erro ao se comunicar com servidor. Envie esta mensagem para o suporte do Mercalona. Mensagem: ' . json_encode($response->errors);
            return $this->errorMessageToObject($error_message);
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_decoded = json_decode($response_body);
        if ($response_decoded == NULL) {
            $error_message = 'Não foi possível processar a resposta do servidor.';
            return $this->errorMessageToObject($error_message);
        }
        return $response_decoded;

    }

    private function errorsFormatter(&$message, $errors)
    {
        $errors = (array)$errors;
        if (count($errors) === 0) return;
        foreach ($errors as $order_id => $field_errors) {
            $message .= " | Pedido com ID: $order_id " . $this->errorsFormatter_ObjectToString($field_errors);
        }
    }

    private function errorsFormatter_ObjectToString($error)
    {
        $error = (array)$error;
        $error_string = '';
        foreach ($error as $field => $errors) {
            $error_string .= "Campo: $field, Erro: " . implode('|', $errors);
        }
        return $error_string;
    }

    private function getOrdersData($post_ids)
    {
        $addresses = [];
        foreach ($post_ids as $post_id) {
            $addresses[] = $this->getOrderData($post_id);
        }
        return $addresses;
    }

    private function getOrderData($order_id)
    {
        $order_data = get_post_meta($order_id);
        $order_products = $this->getOrderProductsV3($order_id);
        return [
            'public_id' => $order_id,
            'url' => @get_site_url(),
            'name' => @$order_data['_billing_first_name'][0] . ' ' . @$order_data['_billing_last_name'][0],
            'email' => @$order_data['_billing_email'][0],
            'phone' => @$order_data['_billing_phone'][0],
            'cellphone' => @$order_data['_billing_cellphone'][0],
            'cpf' => @$order_data['_billing_cpf'][0],
            'cnpj' => @$order_data['_billing_cnpj'][0],
            'street' => @$order_data['_shipping_address_1'][0],
            'complement' => @$order_data['_shipping_address_2'][0],
            'neighborhood' => @$order_data['_shipping_neighborhood'][0],
            'number' => @$order_data['_shipping_number'][0],
            'city' => @$order_data['_shipping_city'][0],
            'state' => @$order_data['_shipping_state'][0],
            'postcode' => @$order_data['_shipping_postcode'][0],
            'country' => @$order_data['_shipping_country'][0],
            'products' => $order_products
        ];
    }

    private function getOrderProductsV3($order_id)
    {
        $order = wc_get_order($order_id);
        $products = [];
        foreach ($order->get_items() as $item_id => $item_data) {
            $product = $item_data->get_product();
            $products[] = [
                'public_id' => $item_id,
                'quantity' => $item_data->get_quantity(),
                'subtotal' => $item_data->get_subtotal(),
                'total' => $item_data->get_total(),
                'product' => [
                    'public_id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'url' => $product->get_slug(),
                    'description' => $product->get_description(),
                    'short_description' => $product->get_short_description(),
                    'code' => $product->get_sku(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'total_sale' => $product->get_total_sales(),
                    'quantity' => $product->get_stock_quantity(),
                    'weight' => $product->get_weight(),
                    'length' => $product->get_length(),
                    'width' => $product->get_width(),
                    'height' => $product->get_height(),
                ]
            ];
        }
        return $products;
    }

    public function vDump($thing, $stop = false)
    {
        echo "<pre>";
        var_dump($thing);
        echo "</pre>";
        if ($stop) exit();
    }

    public function renderAlert()
    {
        if (!empty($_REQUEST['mercalona_status'])) {
            $message = __($_REQUEST['mercalona_message']);
            if ($_REQUEST['mercalona_status'] == 'success') {
                $link = $this->open_order_list_url . strval($_REQUEST['mercalona_label_id']);
                $class = 'notice notice-success';
                echo "<script> 
                window.open(
                '$link',
                '_blank'
            );</script>";
                printf('<div class="%1$s"><p>%2$s <a target="_blank" href="%3$s">%3$s</a></p></div>', esc_attr($class), esc_html($message), esc_url($link));
            } else {
                $class = 'notice notice-error';
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
            }
        }
    }

    public function registerMenu()
    {
        add_submenu_page('woocommerce', 'Etiqueta Correios', 'Etiqueta Correios', 'manage_options', 'woo-etiqueta-correios', [$this, 'renderEtiquetaCorreiosConfigPage']);
    }

    public function renderEtiquetaCorreiosConfigPage()
    {
        ?>
        <div>
            <h1>Configurações | Etiqueta Correios | Mercalona</h1>
            <form method="post" action="options.php">
                <?php settings_fields('woo-etiqueta-correios'); ?>
                <hr>
                <p><h4>Configuração de API</h4>Preencha os campos a baixo com os dados de sua conta. <a target="_blank"
                                                                                                        href="https://app.mercalona.com/system/settings">Clique
                    aqui para pegar sua credencial</a></p>
                <hr>
                <table>
                    <tr valign="top">
                        <th class="text-right" scope="row"><label for="mercalona_id">Mercalona ID</label></th>
                        <td>
                            <input class="input-style" type="text" id="mercalona_id" name="mercalona_id"
                                   value="<?php echo esc_attr(get_option('mercalona_id')); ?>"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th class="text-right" scope="row"><label for="mercalona_token">Mercalona Token</label></th>
                        <td>
                            <input class="input-style" type="text" id="mercalona_token" name="mercalona_token"
                                   value="<?php echo esc_attr(get_option('mercalona_token')); ?>"/>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <br>
            Funcionando apenas para WooCommerce 3 ou superior neste momento.
            <br>
            <iframe width="560" height="315" src="https://www.youtube.com/embed/J3X43hxsLFc" frameborder="0"
                    allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
        </div>
        <style>
            .text-right {
                text-align: right;
            }

            .input-style {
                width: 250px;
            }
        </style>
        <?php
    }

    public function registerOptionFields()
    {
        register_setting('woo-etiqueta-correios', 'mercalona_id');
        register_setting('woo-etiqueta-correios', 'mercalona_token');
    }

    public function managePluginLinks($links)
    {
        $merca_links = ['<a href="' . get_admin_url() . 'admin.php?page=woo-etiqueta-correios">Configurações</a>'];
        return array_merge($merca_links, $links);
    }


}
