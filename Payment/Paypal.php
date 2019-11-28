<?php

/**
 * Herramienta para la gestión de pagos usando formularios "comprar ya" de Paypal
 *
 * @see https://developer.paypal.com/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/
 *
 * Cuentas sandbox:
 * @see https://developer.paypal.com/webapps/developer/applications/accounts
 */
class Payment_Paypal extends Payment_Base
{
    const TRANSACTION_PAYMENT = '_xclick';
    const TRANSACTION_SUBSCRIPTION = '_xclick-subscriptions';
    const TRANSACTION_AUTO_BILLING = '_xclick-auto-billing';
    const TRANSACTION_DONATION = '_donations';

    /**
     * Dirección URL al logo del vendedor, para ser mostrado en la página de pago
     * @var string
     */
    public $url_logo;

    /**
     * Texto mostrado al usuario cuando finaliza el pago
     * @var string
     */
    public $return_text;

    /**
     * Callback para la búsqueda de un ID de transacción.
     * El delegado recibirá como parámetro el ID a buscar, y devolver true si éste existe o false en caso contrario.
     * Este es un campo opcional, aunque recomendable para aumentar la seguridad de las transacciones.
     * @var callable
     */
    public $find_txnid_callback;


    /**
     * Callback para el almacenado de un ID de transacción.
     * El delegado recibirá como parámetro la ID de transacción al almacenar (de un tamaño de 19 bytes).
     * Este es un campo opcional, aunque recomendable para aumentar la seguridad de las transacciones.
     * @var callable
     */
    public $store_txnid_callback;

    /**
     * @param string $app_name
     * @param bool   $sandbox Valor que indica si la petición será ejecuta en el modo sandbox de Paypal, que permite hacer pruebas del módulo de pago sin utilizar dinero real
     */
    public function __construct($app_name, $sandbox = false)
    {
        parent::__construct($app_name);
        $this->url_payment = $sandbox ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
        $this->transaction_type = self::TRANSACTION_PAYMENT;
    }

    /**
     * Obtiene los campos que deben ser enviados mediante POST a la plataforma de pago
     *
     * @return string[]
     * @throws InvalidArgumentException
     */
    public function fields()
    {
        $fields = [
            'cmd'           => $this->transaction_type,
            'business'      => $this->merchant_id,
            'amount'        => $this->amount,
            'currency_code' => $this->currency,
            'custom'        => $this->order,
            'notify_url'    => $this->url_notification,
            'item_name'     => substr($this->product_description, 0, 125),
            'no_note'       => 1, //No permitir escribir notas junto al pago,
            'no_shipping'   => 1, //No solicitar dirección de envío,
            'return'        => $this->url_success,
            'cancel_return' => $this->url_error,
            'charset'       => 'utf-8',
        ];

        if (!empty($this->url_logo)) {
            $fields['cpp_logo_image'] = $this->url_logo;
            $fields['image_url'] = $this->url_logo;
        }
        if (!empty($this->return_text)) {
            $fields['cbt'] = $this->return_text;
        }

        return $fields;
    }

    /**
     * Comprueba que la notificación de pago recibida es correcta y auténtica
     *
     * @param array $post_data Datos POST incluidos con la notificación
     *
     * @return bool
     * @throws Payment_Exception
     */
    public function validate_notification($post_data = null, &$fee = 0)
    {
        if (!isset($post_data)) {
            $post_data = $_POST;
        }

        /*Validación de notificación

Para garantizar que se ha realizado un pago en su cuenta PayPal, debe verificar que la dirección de correo electrónico utilizada como "receiver_email" se ha registrado y confirmado en su cuenta PayPal.

Una vez que el servidor ha recibido la notificación de pago instantánea, necesitará confirmarla creando un HTTP POST en PayPal. El POST se debe enviar a https://www.paypal.com/cgi-bin/webscr.

cuando las reciba. También necesitará añadir una variable llamada "cmd" con el valor "_notify-validate" (p.ej. cmd=_notify-validate) a la cadena POST.

PayPal responderá al envío con una sola palabra, "VERIFICADO" o "NO VÁLIDO", en el cuerpo de la respuesta. Si recibe la respuesta VERIFICADO, necesitará realizar varias comprobaciones antes de cumplimentar el pedido:

Confirme que el "payment_status" es "Completado", ya que las IPN también se envían para otros resultados como "Pendiente" o "Fallido".
Compruebe que "txn_id" no es un duplicado para impedir que cualquier persona con intenciones fraudulentas utilice una antigua transacción completada.
Valide que "receiver_email" es una dirección de correo electrónico registrada en su cuenta PayPal, con el fin de impedir que el pago se envíe a la cuenta de una persona con intenciones fraudulentas.
Compruebe otros detalles de la transacción como el número de artículo y el precio para confirmar que el precio no ha cambiado.
Una vez que haya completado las comprobaciones anteriores, puede actualizar su base de datos con los datos de la IPN y procesar la compra.

Si recibe la notificación "NO VÁLIDO", debe tratarla como sospechosa e investigarla.*/
        if ($post_data['receiver_email'] != $this->merchant_id) {
            throw new Payment_Exception("receiver_email != $this->merchant_id");
        }

        $refund = false;
        $status = isset($post_data['payment_status']) ? $post_data['payment_status'] : '';
        if (strcasecmp($status, 'refunded') == 0 || strcasecmp($status, 'reversed') == 0) { // Pago devuelto
            $refund = true;
        } elseif (strcasecmp($status, 'completed') != 0) {
            throw new Payment_Exception("payment_status != 'completed'", $status);
        }

        $expected_gross = $refund ? ($this->amount * -1) : $this->amount;
        if ($post_data['mc_gross'] != $expected_gross || strcasecmp($post_data['mc_currency'], $this->currency) != 0) {
            throw new Payment_Exception('mc_gross or mc_currency invalid');
        }

        //Realizar validación contra el servidor de Paypal
        $post_data['cmd'] = '_notify-validate';

        $response = Request::create($this->url_payment)
                           ->post($post_data)
                           ->execute();

        if ($response->status() != 200 || strcasecmp($response->body(), 'VERIFIED') != 0) {
            $status_code = $response->status();
            throw new Payment_Exception("Invalid Paypal response #{$status_code}: {$response->body()}");
        }

        $fee = floatval($post_data['mc_fee']);

        if ($refund) {
            throw new Payment_Exception('Payment refunded', Payment_Exception::REASON_REFUND);
        }

        // Comprobar unicidad y almacenar el ID de Transacción de Paypal
        if (isset($this->find_txnid_callback, $this->store_txnid_callback)) {
            $txn_id = $post_data['txn_id'];
            $found = call_user_func($this->find_txnid_callback, $txn_id);
            if ($found) {
                throw new Payment_Exception('Duplicated transaction id (txn_id)', $txn_id);
            } else {
                call_user_func($this->store_txnid_callback, $txn_id);
            }
        }

        return true;
    }
}