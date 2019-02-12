<?php

/**
 * Herramienta para la gestión de un TPV Virtual Redsys
 */
class Payment_RedsysTPV extends Payment_Base
{
    const TRANSACTION_PAYMENT = '0';
    const TRANSACTION_PAYMENT_AUTH = '1';
    const TRANSACTION_REFUND = '3';
    const TRANSACTION_SUBSCRIPTION = '5';

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
     * 2 – Confirmación de preautorización
     * 3 – Devolución Automática
     * 5 – Transacción Recurrente
     * 6 – Transacción Sucesiva
     * 7 – Pre-autenticación
     * 8 – Confirmación de pre-autenticación
     * 9 – Anulación de Preautorización
     * O – Autorización en diferido
     * P– Confirmación de autorización en diferido
     * Q - Anulación de autorización en diferido
     * R – Cuota inicial diferido
     * S – Cuota sucesiva diferido
     * @var string
     */
    public $transaction_type = self::TRANSACTION_PAYMENT;

    /**
     * Clave secreta proporcionada por el banco para comprobar la integridad de la firma
     * @var string
     */
    public $secret_key;

    /**
     * % sobre el total que se aplica de comisión para la operación. Se puede indicar un callable que será el encargado de calcular la comisión
     * @var float|callable
     */
    public $fee = 0;

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

        if ($this->currency == 'EUR') { // Para Euros las dos últimas posiciones se consideran decimales
            $amount = str_replace('.', '', $amount);
        }

        // Convertir divisa a código numérico
        if (is_numeric($this->currency)) {
            $currency = $this->currency;
        } else {
            $currencies = $this->_currency_table();
            $currency_name = strtoupper($this->currency);
            if (!isset($currencies[$currency_name])) {
                throw new InvalidArgumentException("Unrecognized currency '{$currency_name}', please use its ISO 4217 numeric code instead");
            }

            $currency = $currencies[$currency_name];
        }

        // Ajustar tamaño del identificador de pedido (minimo 4 caracteres)
        $order = str_pad($this->order, 4, '0', STR_PAD_LEFT);

        // Generar parámetros
        $redsys = new RedsysAPI();
        $redsys->setParameter('DS_MERCHANT_AMOUNT', $amount);
        $redsys->setParameter('DS_MERCHANT_ORDER', $order);
        $redsys->setParameter('DS_MERCHANT_MERCHANTCODE', $this->merchant_id);
        $redsys->setParameter('DS_MERCHANT_CURRENCY', $currency);
        $redsys->setParameter('DS_MERCHANT_TRANSACTIONTYPE', $this->transaction_type);
        $redsys->setParameter('DS_MERCHANT_TERMINAL', $this->terminal);
        $redsys->setParameter('DS_MERCHANT_MERCHANTURL', $this->url_notification);

        if ($this->merchant_name) {
            $redsys->setParameter('DS_MERCHANT_MERCHANTNAME', substr($this->merchant_name, 0, 25));
        }

        if ($this->product_description) {
            $redsys->setParameter('DS_MERCHANT_PRODUCTDESCRIPTION', substr($this->product_description, 0, 125));
        }

        if ($this->buyer_name) {
            $redsys->setParameter('DS_MERCHANT_TITULAR', substr($this->buyer_name, 0, 60));
        }

        if ($this->language) {
            $redsys->setParameter('DS_MERCHANT_CONSUMERLANGUAGE', $this->language);
        }

        $redsys->setParameter('DS_MERCHANT_URLOK', $this->url_success);
        $redsys->setParameter('DS_MERCHANT_URLKO', $this->url_error);

        // Devolver campos
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
    public function validate_notification($post_data = null, &$fee = 0)
    {
        if (!isset($post_data)) {
            $post_data = $_POST;
        }

        $redsys = new RedsysAPI();

        $parameters = $post_data['Ds_MerchantParameters'];
        $redsys->decodeMerchantParameters($parameters);

        // Comprobar firma
        $received_signature = $post_data['Ds_Signature'];
        $signature = $redsys->createMerchantSignatureNotif($this->secret_key, $parameters);

        if ($signature !== $received_signature) {
            throw new Payment_Exception("Invalid signature, received '{$received_signature}', expected '{$signature}'");
        }

        // Comprobar respuesta
        /*
         * ﻿0000 a 0099	Transacción autorizada para pagos y preautorizaciones
         * 0900	Transacción autorizada para devoluciones y confirmaciones
         *
         */
        $response = $redsys->getParameter('Ds_Response');
        if ($response >= 101 && $response != 900) {
            $error_codes = self::error_codes();
            $description = isset($error_codes[$response]) ? $error_codes[$response] : 'Unknown response code';
            throw new Payment_Exception("Invalid Ds_Response '{$response}' ({$description})");
        }

        // Comprobar cantidad y divisa
        $amount = $redsys->getParameter('Ds_Amount');
        $currency_code = $redsys->getParameter('Ds_Currency');

        $currencies = $this->_currency_table();
        $currency = array_search($currency_code, $currencies);

        if ($currency == 'EUR') { // Para Euros las dos últimas posiciones se consideran decimales
            $amount /= 100;
        }

        if ($amount != $this->amount || $currency != $this->currency) {
            throw new Payment_Exception("Invalid amount or currency, received {$amount} {$currency} expected {$this->amount} {$this->currency}");
        }

        // Calcular comisión       
        if (is_numeric($this->fee)) {
            $fee = self::_ceil_precission($amount * $this->fee, 2);
        } else {
            $fee = call_user_func($this->fee, $response);
        }

        // Comprobar si era una devolución
        $transaction_type = $redsys->getParameter('Ds_TransactionType');

        if ($transaction_type == self::TRANSACTION_REFUND) {
            throw new Payment_Exception('Payment refunded', Payment_Exception::REASON_REFUND);
        } elseif ($transaction_type != self::TRANSACTION_PAYMENT) {
            throw new Payment_Exception("Invalid transaction type, received '{$transaction_type}', expected '" . self::TRANSACTION_PAYMENT . "'");
        }

        return true;
    }

    protected function _currency_table()
    {
        return [
            'EUR' => 978,
            'USD' => 840,
            'GBP' => 426,
            'JPY' => 392,
            'CNY' => 156,
        ];
    }

    public static function error_codes()
    {
        return [
            //Códigos de respuesta para terminales basados en sha256
            'SIS0429' => 'Error en la versión enviada por el comercio en el parámetro Ds_SignatureVersion.',
            'SIS0430' => 'Error al decodificar el parámetro Ds_MerchantParameters.',
            'SIS0431' => 'Error del objeto JSON que se envía codificado en el parámetro Ds_MerchantParameters.',
            'SIS0432' => 'Error FUC del comercio erróneo.',
            'SIS0433' => 'Error Terminal del comercio erróneo.',
            'SIS0434' => 'Error ausencia de número de pedido en la operación enviada por el comercio.',
            'SIS0435' => 'Error en el cálculo de la firma.',
            //Códigos de respuesta restantes y de terminales basados en sha1
            '0101'    => 'Tarjeta Caducada.',
            '0102'    => 'Tarjeta en excepción transitoria o bajo sospecha de fraude.',
            '0104'    => 'Operación no permitida para esa tarjeta o terminal.',
            '0106'    => 'Intentos de PIN excedidos.',
            '0116'    => 'Disponible Insuficiente.',
            '0118'    => 'Tarjeta no Registrada.',
            '0125'    => 'Tarjeta no efectiva.',
            '0129'    => 'Código de seguridad (CVV2/CVC2) incorrecto.',
            '0180'    => 'Tarjeta ajena al servicio.',
            '0184'    => 'Error en la autenticación del titular.',
            '0190'    => 'Denegación sin especificar motivo.',
            '0191'    => 'Fecha de caducidad errónea.',
            '0202'    => 'Tarjeta en excepción transitoria o bajo sospecha de fraude con retirada de tarjeta.',
            '0904'    => 'Comercio no registrado en FUC.',
            '0909'    => 'Error de sistema.',
            '0912'    => 'Emisor no disponible.',
            '0913'    => 'Pedido repetido.',
            '0944'    => 'Sesión Incorrecta.',
            '0950'    => 'Operación de devolución no permitida.',
            '9064'    => 'Número de posiciones de la tarjeta incorrecto.',
            '9078'    => 'No existe método de pago válido para esa tarjeta.',
            '9093'    => 'Tarjeta no existente.',
            '9094'    => 'Rechazo servidores internacionales.',
            '9104'    => 'Comercio con “titular seguro” y titular sin clave de compra segura.',
            '9218'    => 'El comercio no permite op. seguras por entrada /operaciones.',
            '9253'    => 'Tarjeta no cumple el check-digit.',
            '9256'    => 'El comercio no puede realizar preautorizaciones.',
            '9257'    => 'Esta tarjeta no permite operativa de preautorizaciones.',
            '9261'    => 'Operación detenida por superar el control de restricciones en la entrada al SIS.',
            '9912'    => 'Emisor no disponible.',
            '9913'    => 'Error en la confirmación que el comercio envía al TPV Virtual (solo aplicable en la opción de sincronización SOAP).',
            '9914'    => 'Confirmación “KO” del comercio (solo aplicable en la opción de sincronización SOAP).',
            '9915'    => 'A petición del usuario se ha cancelado el pago.',
            '9928'    => 'Anulación de autorización en diferido realizada por el SIS (proceso batch).',
            '9929'    => 'Anulación de autorización en diferido realizada por el comercio.',
            '9997'    => 'Se está procesando otra transacción en SIS con la misma tarjeta.',
            '9998'    => 'Operación en proceso de solicitud de datos de tarjeta.',
            '9999'    => 'Operación que ha sido redirigida al emisor a autenticar.',
            'SIS0007' => 'Error al desmontar el XML de entrada.',
            'SIS0008' => 'Error falta Ds_Merchant_MerchantCode.',
            'SIS0009' => 'Error de formato en Ds_Merchant_MerchantCode.',
            'SIS0010' => 'Error falta Ds_Merchant_Terminal.',
            'SIS0011' => 'Error de formato en Ds_Merchant_Terminal.',
            'SIS0014' => 'Error de formato en Ds_Merchant_Order.',
            'SIS0015' => 'Error falta Ds_Merchant_Currency.',
            'SIS0016' => 'Error de formato en Ds_Merchant_Currency.',
            'SIS0017' => 'Error no se admiten operaciones en pesetas.',
            'SIS0018' => 'Error falta Ds_Merchant_Amount.',
            'SIS0019' => 'Error de formato en Ds_Merchant_Amount.',
            'SIS0020' => 'Error falta Ds_Merchant_MerchantSignature.',
            'SIS0021' => 'Error la Ds_Merchant_MerchantSignature viene vacía.',
            'SIS0022' => 'Error de formato en Ds_Merchant_TransactionType.',
            'SIS0023' => 'Error Ds_Merchant_TransactionType desconocido.',
            'SIS0024' => 'Error Ds_Merchant_ConsumerLanguage tiene mas de 3 posiciones.',
            'SIS0025' => 'Error de formato en Ds_Merchant_ConsumerLanguage.',
            'SIS0026' => 'Error No existe el comercio / terminal enviado.',
            'SIS0027' => 'Error Moneda enviada por el comercio es diferente a la que tiene asignada para ese terminal.',
            'SIS0028' => 'Error Comercio / terminal está dado de baja.',
            'SIS0030' => 'Error en un pago con tarjeta ha llegado un tipo de operación no valido.',
            'SIS0031' => 'Método de pago no definido.',
            'SIS0033' => 'Error en un pago con móvil ha llegado un tipo de operación que no es ni pago ni preautorización.',
            'SIS0034' => 'Error de acceso a la Base de Datos.',
            'SIS0037' => 'El nu&#769;mero de teléfono no es válido.',
            'SIS0038' => 'Error en java.',
            'SIS0040' => 'Error el comercio / terminal no tiene ningún método de pago asignado.',
            'SIS0041' => 'Error en el cálculo de la firma de datos del comercio.',
            'SIS0042' => 'La firma enviada no es correcta.',
            'SIS0043' => 'Error al realizar la notificación on-line.',
            'SIS0046' => 'El BIN de la tarjeta no está dado de alta.',
            'SIS0051' => 'Error número de pedido repetido.',
            'SIS0054' => 'Error no existe operación sobre la que realizar la devolución.',
            'SIS0055' => 'Error no existe más de un pago con el mismo número de pedido.',
            'SIS0056' => 'La operación sobre la que se desea devolver no está autorizada.',
            'SIS0057' => 'El importe a devolver supera el permitido.',
            'SIS0058' => 'Inconsistencia de datos, en la validación de una confirmación.',
            'SIS0059' => 'Error no existe operación sobre la que realizar la devolución.',
            'SIS0060' => 'Ya existe una confirmación asociada a la preautorización.',
            'SIS0061' => 'La preautorización sobre la que se desea confirmar no está autorizada.',
            'SIS0062' => 'El importe a confirmar supera el permitido.',
            'SIS0063' => 'Error. Número de tarjeta no disponible.',
            'SIS0064' => 'Error. El número de tarjeta no puede tener más de 19 posiciones.',
            'SIS0065' => 'Error. El número de tarjeta no es numérico.',
            'SIS0066' => 'Error. Mes de caducidad no disponible.',
            'SIS0067' => 'Error. El mes de la caducidad no es numérico.',
            'SIS0068' => 'Error. El mes de la caducidad no es válido.',
            'SIS0069' => 'Error. Año de caducidad no disponible.',
            'SIS0070' => 'Error. El Año de la caducidad no es numérico.',
            'SIS0071' => 'Tarjeta caducada.',
            'SIS0072' => 'Operación no anulable.',
            'SIS0074' => 'Error falta Ds_Merchant_Order.',
            'SIS0075' => 'Error el Ds_Merchant_Order tiene menos de 4 posiciones o más de 12.',
            'SIS0076' => 'Error el Ds_Merchant_Order no tiene las cuatro primeras posiciones numéricas.',
            'SIS0078' => 'Método de pago no disponible.',
            'SIS0079' => 'Error al realizar el pago con tarjeta.',
            'SIS0081' => 'La sesión es nueva, se han perdido los datos almacenados.',
            'SIS0084' => 'El valor de Ds_Merchant_Conciliation es nulo.',
            'SIS0085' => 'El valor de Ds_Merchant_Conciliation no es numérico.',
            'SIS0086' => 'El valor de Ds_Merchant_Conciliation no ocupa 6 posiciones.',
            'SIS0089' => 'El valor de Ds_Merchant_ExpiryDate no ocupa 4 posiciones.',
            'SIS0092' => 'El valor de Ds_Merchant_ExpiryDate es nulo.',
            'SIS0093' => 'Tarjeta no encontrada en la tabla de rangos.',
            'SIS0094' => 'La tarjeta no fue autenticada como 3D Secure.',
            'SIS0097' => 'Valor del campo Ds_Merchant_CComercio no válido.',
            'SIS0098' => 'Valor del campo Ds_Merchant_CVentana no válido.',
            'SIS0112' => 'Error. El tipo de transacción especificado en Ds_Merchant_Transaction_Type no esta permitido.',
            'SIS0113' => 'Excepción producida en el servlet de operaciones.',
            'SIS0114' => 'Error, se ha llamado con un GET en lugar de un POST.',
            'SIS0115' => 'Error no existe operación sobre la que realizar el pago de la cuota.',
            'SIS0116' => 'La operación sobre la que se desea pagar una cuota no es una operación válida.',
            'SIS0117' => 'La operación sobre la que se desea pagar una cuota no está autorizada.',
            'SIS0118' => 'Se ha excedido el importe total de las cuotas.',
            'SIS0119' => 'Valor del campo Ds_Merchant_DateFrecuency no válido.',
            'SIS0120' => 'Valor del campo Ds_Merchant_CargeExpiryDate no válido.',
            'SIS0121' => 'Valor del campo Ds_Merchant_SumTotal no válido.',
            'SIS0122' => 'Valor del campo Ds_merchant_DateFrecuency o Ds_Merchant_SumTotal tiene formato incorrecto.',
            'SIS0123' => 'Se ha excedido la fecha tope para realizar transacciones.',
            'SIS0124' => 'No ha transcurrido la frecuencia mínima en un pago recurrente sucesivo.',
            'SIS0132' => 'La fecha de Confirmación de Autorización no puede superar en más de 7 días a la de Preautorización.',
            'SIS0133' => 'La fecha de Confirmación de Autenticación no puede superar en mas de 45 días a la de Autenticación Previa.',
            'SIS0139' => 'Error el pago recurrente inicial está duplicado.',
            'SIS0142' => 'Tiempo excedido para el pago.',
            'SIS0197' => 'Error al obtener los datos de cesta de la compra en operación tipo pasarela.',
            'SIS0198' => 'Error el importe supera el límite permitido para el comercio.',
            'SIS0199' => 'Error el número de operaciones supera el límite permitido para el comercio.',
            'SIS0200' => 'Error el importe acumulado supera el límite permitido para el comercio.',
            'SIS0214' => 'El comercio no admite devoluciones.',
            'SIS0216' => 'Error Ds_Merchant_CVV2 tiene mas de 3/4 posiciones.',
            'SIS0217' => 'Error de formato en Ds_Merchant_CVV2.',
            'SIS0218' => 'El comercio no permite operaciones seguras por la entrada /operaciones.',
            'SIS0219' => 'Error el nu&#769;mero de operaciones de la tarjeta supera el límite permitido para el comercio.',
            'SIS0220' => 'Error el importe acumulado de la tarjeta supera el límite permitido para el comercio.',
            'SIS0221' => 'Error el CVV2 es obligatorio.',
            'SIS0222' => 'Ya existe una anulación asociada a la preautorización.',
            'SIS0223' => 'La preautorización que se desea anular no está autorizada.',
            'SIS0224' => 'El comercio no permite anulaciones por no tener firma ampliada.',
            'SIS0225' => 'Error no existe operación sobre la que realizar la anulación.',
            'SIS0226' => 'Inconsistencia de datos, en la validación de una anulación.',
            'SIS0227' => 'Valor del campo Ds_Merchan_TransactionDate no válido.',
            'SIS0229' => 'No existe el código de pago aplazado solicitado.',
            'SIS0252' => 'El comercio no permite el envío de tarjeta.',
            'SIS0253' => 'La tarjeta no cumple el check-digit.',
            'SIS0254' => 'El nu&#769;mero de operaciones de la IP supera el límite permitido por el comercio.',
            'SIS0255' => 'El importe acumulado por la IP supera el límite permitido por el comercio.',
            'SIS0256' => 'El comercio no puede realizar preautorizaciones.',
            'SIS0257' => 'Esta tarjeta no permite operativa de preautorizaciones.',
            'SIS0258' => 'Inconsistencia de datos, en la validación de una confirmación.',
            'SIS0261' => 'Operación detenida por superar el control de restricciones en la entrada al SIS.',
            'SIS0270' => 'El comercio no puede realizar autorizaciones en diferido.',
            'SIS0274' => 'Tipo de operación desconocida o no permitida por esta entrada al SIS.',
            'SIS0298' => 'El comercio no permite realizar operaciones de Tarjeta en Archivo.',
            'SIS0319' => 'El comercio no pertenece al grupo especificado en Ds_Merchant_Group.',
            'SIS0321' => 'La referencia indicada en Ds_Merchant_Identifier no está asociada al comercio.',
            'SIS0322' => 'Error de formato en Ds_Merchant_Group.',
            'SIS0325' => 'Se ha pedido no mostrar pantallas pero no se ha enviado ninguna referencia de tarjeta.',
        ];
    }
}
