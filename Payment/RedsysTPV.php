<?php

/**
 * Herramienta para la gestión de un TPV Virtual Redsys
 */
class Payment_RedsysTPV extends Payment_Base
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

        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'libs/apiRedsys.php';
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

        //Generar parámetros
        $redsys = new RedsysAPI();
        $redsys->setParameter("DS_MERCHANT_AMOUNT", $amount);
        $redsys->setParameter("DS_MERCHANT_ORDER", $order);
        $redsys->setParameter("DS_MERCHANT_MERCHANTCODE", $this->merchant_id);
        $redsys->setParameter("DS_MERCHANT_CURRENCY", $currency);
        $redsys->setParameter("DS_MERCHANT_TRANSACTIONTYPE", $this->transaction_type);
        $redsys->setParameter("DS_MERCHANT_TERMINAL", $this->terminal);
        $redsys->setParameter("DS_MERCHANT_MERCHANTURL", $this->url_notification);

        if ($this->merchant_name) {
            $redsys->setParameter("DS_MERCHANT_MERCHANTNAME", substr($this->merchant_name, 0, 25));
        }

        if ($this->product_description) {
            $redsys->setParameter("DS_MERCHANT_PRODUCTDESCRIPTION", substr($this->product_description, 0, 125));
        }

        if ($this->buyer_name) {
            $redsys->setParameter("DS_MERCHANT_TITULAR", substr($this->buyer_name, 0, 60));
        }

        if ($this->language) {
            $redsys->setParameter("DS_MERCHANT_CONSUMERLANGUAGE", $this->language);
        }

        $redsys->setParameter("DS_MERCHANT_URLOK", $this->url_success);
        $redsys->setParameter("DS_MERCHANT_URLKO", $this->url_error);

        //Devolver campos
        return [
            'Ds_SignatureVersion'   => 'HMAC_SHA256_V1',
            'Ds_MerchantParameters' => $redsys->createMerchantParameters(),
            'Ds_Signature'          => $redsys->createMerchantSignature($this->secret_key),
        ];
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

        $redsys = new RedsysAPI();

        $parameters = $post_data['Ds_MerchantParameters'];
        $redsys->decodeMerchantParameters($parameters);

        //Comprobar firma
        $received_signature = $post_data['Ds_Signature'];
        $signature = $redsys->createMerchantSignatureNotif($this->secret_key, $parameters);

        if ($signature !== $received_signature) {
            throw new Payment_Exception("Invalid signature, received '$received_signature', expected '$signature'");
        }

        //Comprobar respuesta
        /*
         * ﻿0000 a 0099	Transacción autorizada para pagos y preautorizaciones
         * 0900	Transacción autorizada para devoluciones y confirmaciones
         *
         */
        $response = $redsys->getParameter('Ds_Response');
        if ($response >= 101 && $response != 900) {
            throw new Payment_Exception("Invalid response, received Ds_Response '$response'");
        }

        return true;
    }

    protected function _currency_name_to_code($currency_name)
    {
        $currencies = [
            'EUR' => 978,
            'USD' => 840,
            'GBP' => 426,
            'JPY' => 392,
            'CNY' => 156,
        ];

        $currency_name = strtoupper($currency_name);
        if (!isset($currencies[$currency_name])) {
            throw new InvalidArgumentException("Unrecognized currency '$currency_name', please use its ISO 4217 numeric code instead");
        }

        return $currencies[$currency_name];
    }
}
