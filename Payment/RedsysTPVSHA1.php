<?php

/**
 * Herramienta para la gestión de un TPV Virtual Redsys basado en el estándar SHA1
 */
class Payment_RedsysTPVSHA1 extends Payment_Base
{
    /**
     * Identificador del terminal TPV utilizado
     * @var string
     */
    public $terminal = '001';

    /**
     * Campo opcional para el comercio para
     * indicar qué tipo de transacción es. Los
     * posibles valores son:
     * 0 – Autorización
     * 1 – Preautorización
     * 2 – Confirmación
     * 3 – Devolución Automática
     * 4 – Pago Referencia
     * 5 – Transacción Recurrente
     * 6 – Transacción Sucesiva
     * 7 – Autenticación
     * 8 – Confirmación de Autenticación
     * @var string
     */
    public $transaction_type = '0';

    /**
     * Clave secreta proporcionada por el banco para comprobar la integridad de la firma
     * @var string
     */
    public $secret_key;


    public function __construct($app_name, $test_mode = false)
    {
        parent::__construct($app_name);
        $this->language = '000'; //Autodetectar
        $this->url_payment = $test_mode ? 'https://sis-t.redsys.es:25443/sis/realizarPago' : 'https://sis.redsys.es/sis/realizarPago';
    }

    /**
     * Obtiene los campos que deben ser enviados mediante POST al TPV
     *
     * @return array|string
     * @throws InvalidArgumentException
     * @return string[]|string
     */
    public function fields()
    {
        $amount = number_format($this->amount, 2);

        if ($this->currency == 'EUR') { //Para Euros las dos últimas posiciones se consideran decimales
            $amount = str_replace('.', '', $amount);
        }

        //Convertir divisa a código numérico
        $currency = is_numeric($this->currency) ? $this->currency : $this->_currency_name_to_code($this->currency);

        //Ajustar tamaño del identificador de pedido (minimo 4 caracteres)
        $order = str_pad($this->order, 4, '0', STR_PAD_LEFT);

        //Calcular firma
        $message = $amount . $order . $this->merchant_id . $currency . $this->transaction_type . $this->url_notification . $this->secret_key;
        $signature = strtoupper(sha1($message));

        //Devolver campos
        return [
            'Ds_Merchant_Amount'             => $amount,
            'Ds_Merchant_Currency'           => $currency,
            'Ds_Merchant_Order'              => $order,
            'Ds_Merchant_MerchantCode'       => $this->merchant_id,
            'Ds_Merchant_Terminal'           => $this->terminal,
            'Ds_Merchant_TransactionType'    => $this->transaction_type,
            'Ds_Merchant_Titular'            => substr($this->buyer_name, 0, 60),
            'Ds_Merchant_MerchantName'       => substr($this->merchant_name, 0, 25),
            'Ds_Merchant_ProductDescription' => substr($this->product_description, 0, 125),
            'Ds_Merchant_ConsumerLanguage'   => $this->language,
            'Ds_Merchant_MerchantURL'        => $this->url_notification,
            'Ds_Merchant_UrlOK'              => $this->url_success,
            'Ds_Merchant_UrlKO'              => $this->url_error,
            'Ds_Merchant_MerchantSignature'  => $signature,
        ];
    }

    protected function _currency_name_to_code($currency_name)
    {
        switch ($currency_name) {
            case 'EUR':
                return 978;

            case 'USD':
                return 840;

            case 'GBP':
                return 426;

            case 'JPY':
                return 392;

            case 'CNY':
                return 156;

            default:
                throw new InvalidArgumentException("Unrecognized currency '$currency_name', please use its ISO 4217 numeric code instead");
        }

    }

    /**
     * Comprueba que la notificación de pago recibida es correcta y auténtica
     *
     * @param array $post_data Datos POST incluidos con la notificación
     *
     * @throws Payment_Exception
     * @return bool
     */
    public function validate_notification($post_data = null)
    {
        if (!isset($post_data)) {
            $post_data = $_POST;
        }

        //Comprobar respuesta
        /*
         * 0000 a 0099 Transacción autorizada para pagos y preautorizaciones
         * 0900 Transacción autorizada para devoluciones y confirmaciones
         * */

        $response = isset($post_data['Ds_Response']) ? $post_data['Ds_Response'] : -1;
        if ($response < 0 || $response > 99) {
            if ($response != 900) {
                throw new Payment_Exception("Invalid response, received Ds_Response '$response'");
            }
        }

        //Comprobar firma
        $fields = $this->fields();
        $message = $fields['Ds_Merchant_Amount'] . $fields['Ds_Merchant_Order'] . $fields['Ds_Merchant_MerchantCode'] . $fields['Ds_Merchant_Currency'] .
                   $response . $this->secret_key;
        $received_signature = isset($post_data['Ds_Signature']) ? $post_data['Ds_Signature'] : false;
        $signature = strtoupper(sha1($message));
        if ($received_signature != $signature) {
            throw new Payment_Exception("Invalid signature, received '$received_signature', expected '$signature'");
        }

        return true;
    }
}
