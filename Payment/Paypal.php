<?php

declare(strict_types=1);

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
    public const TRANSACTION_PAYMENT = '_xclick';
    public const TRANSACTION_SUBSCRIPTION = '_xclick-subscriptions';
    public const TRANSACTION_AUTO_BILLING = '_xclick-auto-billing';
    public const TRANSACTION_DONATION = '_donations';

    /**
     * Dirección URL al logo del vendedor, para ser mostrado en la página de pago
     */
    public string $urlLogo;

    /**
     * Texto mostrado al usuario cuando finaliza el pago
     */
    public string $returnText;

    /**
     * Callback para la búsqueda de un ID de transacción.
     * El delegado recibirá como parámetro el ID a buscar, y devolver true si éste existe o false en caso contrario.
     * Este es un campo opcional, aunque recomendable para aumentar la seguridad de las transacciones.
     * @var callable|null
     */
    public $findTxnIdCallback;

    /**
     * Callback para el almacenado de un ID de transacción.
     * El delegado recibirá como parámetro la ID de transacción al almacenar (de un tamaño de 19 bytes).
     * Este es un campo opcional, aunque recomendable para aumentar la seguridad de las transacciones.
     * @var callable|null
     */
    public $storeTxnIdCallback;

    /**
     * @param bool   $sandbox Valor que indica si la petición será ejecuta en el modo sandbox de Paypal, que permite hacer pruebas del módulo de pago sin utilizar dinero real
     */
    public function __construct(string $appName, bool $sandbox = false)
    {
        parent::__construct($appName);
        $this->urlPayment = $sandbox ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
        $this->transactionType = self::TRANSACTION_PAYMENT;
    }

    /** @inheritDoc */
    public function fields(): array
    {
        $fields = [
            'cmd'           => $this->transactionType,
            'business'      => $this->merchantID,
            'amount'        => $this->amount,
            'currency_code' => $this->currency,
            'custom'        => $this->order,
            'notify_url'    => $this->urlNotification,
            'item_name'     => substr($this->productDescription, 0, 125),
            'no_note'       => 1, // No permitir escribir notas junto al pago,
            'no_shipping'   => 1, // No solicitar dirección de envío,
            'return'        => $this->urlSuccess,
            'cancel_return' => $this->urlError,
            'charset'       => 'utf-8',
        ];

        if (!empty($this->urlLogo)) {
            $fields['cpp_logo_image'] = $this->urlLogo;
            $fields['image_url'] = $this->urlLogo;
        }
        if (!empty($this->returnText)) {
            $fields['cbt'] = $this->returnText;
        }

        return $fields;
    }

    /** @inheritDoc */
    public function validateNotification(array $postData = null, float &$fee = 0): bool
    {
        $postData ??= $_POST;

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
        if (($postData['receiver_email'] ?? '') != $this->merchantID) {
            throw new Payment_Exception("receiver_email != {$this->merchantID}", $postData);
        }

        $refund = false;
        $status = isset($postData['payment_status']) ? $postData['payment_status'] : '';
        if (strcasecmp($status, 'refunded') == 0 || strcasecmp($status, 'reversed') == 0) { // Pago devuelto
            $refund = true;
        } elseif (strcasecmp($status, 'completed') != 0) {
            throw new Payment_Exception("payment_status != 'completed'", $status);
        }

        $expectedGross = $refund ? ($this->amount * -1) : $this->amount;
        if ($postData['mc_gross'] != $expectedGross || strcasecmp($postData['mc_currency'], $this->currency) != 0) {
            throw new Payment_Exception('mc_gross or mc_currency invalid');
        }

        // Realizar validación contra el servidor de Paypal
        $postData['cmd'] = '_notify-validate';

        $response = $this->_postRequest($this->urlPayment, $postData);

        if ($response['status'] != 200 || strcasecmp($response['body'], 'VERIFIED') != 0) {
            throw new Payment_Exception("Invalid Paypal response #{$response['status']}: {$response['body']}");
        }

        $fee = floatval($postData['mc_fee']);

        if ($refund) {
            throw new Payment_Exception('Payment refunded', Payment_Exception::REASON_REFUND);
        }

        // Comprobar unicidad y almacenar el ID de Transacción de Paypal
        if (isset($this->findTxnIdCallback, $this->storeTxnIdCallback)) {
            $txn_id = $postData['txn_id'];
            $found = call_user_func($this->findTxnIdCallback, $txn_id);
            if ($found) {
                throw new Payment_Exception('Duplicated transaction id (txn_id)', $txn_id);
            } else {
                call_user_func($this->storeTxnIdCallback, $txn_id);
            }
        }

        return true;
    }
}