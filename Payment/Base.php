<?php

declare(strict_types=1);

/**
 * Herramienta para la gestión de pagos desde una aplicación
 */
abstract class Payment_Base
{
    /** Cantidad a cobrar */
    public float|int|string $amount;

    /** Código ISO 4217 de la divisa (textual, no numérico)*/
    public string $currency = 'EUR';

    /** Identificador del pedido */
    public int $order;

    /** Identificador del comercio (código aportado por el banco para módulos TPV, email de la cuenta de usuario para pagos Paypal) */
    public string $merchantID;

    /** Nombre del comercio */
    public string $merchantName;

    /** Tipo de transacción. Por defecto, un pago único. */
    public string $transactionType;

    /** Nombre del comprador (hasta 60 caracteres) */
    public string $buyerName;

    /** Descripción del producto (hasta 125 caracteres) */
    public string $productDescription = '';

    /** Idioma mostrado al usuario */
    public string $language;

    /** Dirección URL remota desde donde se realiza el pago */
    public string $urlPayment;

    /** Dirección URL cargada de manera transparente donde se recibe la notificación del pago.*/
    public string $urlNotification;


    /** Dirección URL cargada cuando se realiza el pago correctamente */
    public string $urlSuccess;

    /** Dirección URL cargada cuando se produce un error en el pago */
    public string $urlError;

    /** Texto mostrado en el botón para enviar el formulario (sólo si auto_submit=false o el navegador no soporta javascript) */
    public string $defaultSubmitText = 'Pay now';


    public function __construct(string $appName, string $buyerName = '')
    {
        $this->merchantName = $appName;
        $this->buyerName = $buyerName;
    }

    /**
     * Renderiza un formulario HTML que muestra la pasarela de pago
     *
     * @param array<string, mixed> $attr Atributos HTML del formulario
     */
    public function renderForm(string $target = '_top', bool $autoSubmit = true, array $attr = []): string
    {
        // Generar campos del formulario
        $fields = [];
        foreach ($this->fields() as $name => $value) {
            $fields[] = '<input type="hidden" name="' . htmlspecialchars((string)$name) . '" value="' . htmlspecialchars((string)$value) . '"/>';
        }

        // Botón para enviar el formulario si no hay javascript
        $fields[] = '<noscript><input type="submit" value="' . htmlspecialchars($this->defaultSubmitText) . '"/></noscript>';

        // Generar cabecera y formulario
        if ($autoSubmit && !isset($attr['id'])) {
            $attr['id'] = 'payment_form_' . mt_rand();
        }

        $html = '<form ' . self::_buildAttributes(
                $attr + [
                    'target' => $target,
                    'action' => $this->urlPayment,
                    'method' => 'post'
                ]
            ) . '>' . implode('', $fields) . '</form>';

        // Código para autoenvío
        if ($autoSubmit) {
            $html .= '<script>document.getElementById(' . json_encode($attr['id']) . ').submit();</script>';
        }

        return $html;
    }

    /**
     * Obtiene los campos que deben ser enviados mediante POST a la plataforma de pago
     *
     * @return array<string, string>
     * @throws InvalidArgumentException
     */
    public abstract function fields(): array;

    /**
     * Comprueba que la notificación de pago recibida es correcta y auténtica
     *
     * @param array<string|mixed>|null $postData Datos POST incluidos con la notificación
     * @param float                    $fee      Valor completado con la comisión aplicada a la operación
     */
    public abstract function validateNotification(array $postData = null, float &$fee = 0): bool;

    /**
     * Realiza una petición POST a una URL
     * @return array{status: int, body: string}
     */
    protected function _postRequest(string $url, array $postData): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return ['status' => $statusCode, 'body' => $body];
    }

    /**
     * Genera una cadena de texto en código HTML con los atributos indicados en el array asociativo
     *
     * @param array<string, mixed>|string $attributes
     */
    private static function _buildAttributes(array|string $attributes = '', bool $escape = true): string
    {
        if (is_array($attributes)) {
            $atts = '';
            foreach ($attributes as $key => $val) {
                if ($key == 'class' && is_array($val)) {
                    $val = implode(' ', $val);
                } elseif ($key == 'style' && is_array($val)) {
                    $val = implode(';', $val);
                } elseif (is_bool($val)) {
                    //Compatibilidad con XHTML
                    //Establece el valor de atributos booleanos con el valor de la clave (p.ej.: required="required", checked="checked", etc.)
                    if ($val) {
                        $val = $key;
                    } else { //Si el valor es FALSE, el atributo se ignora
                        continue;
                    }
                }

                //$atts .= ' ' . $key . '="' . $val . '"';
                if ($escape) {
                    $val = htmlspecialchars($val);
                }
                $atts .= " $key=\"$val\"";
            }
            return $atts;
        }
        return $attributes;
    }

    protected static function _ceilPrecision(int|float $value, int $precision): int|float
    {
        $pow = pow(10, $precision);
        return (ceil($pow * $value) + ceil($pow * $value - ceil($pow * $value))) / $pow;
    }
}
