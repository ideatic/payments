<?php

declare(strict_types=1);

/**
 * Herramienta para la gestión de pagos desde una aplicación
 */
abstract class Payment_Base
{
    /**
     * Cantidad a cobrar
     * @var float
     */
    public $amount;

    /**
     * Código ISO 4217 de la divisa (textual, no numérico)
     * @var string
     */
    public $currency = 'EUR';

    /**
     * Identificador del pedido
     * @var int
     */
    public $order;

    /**
     * Identificador del comercio (código aportado por el banco para módulos TPV, email de la cuenta de usuario para pagos Paypal)
     * @var string
     */
    public $merchantID;

    /**
     * Nombre del comercio
     * @var string
     */
    public $merchantName;

    /**
     * Tipo de transacción. Por defecto, un pago único.
     * @var string
     */
    public $transactionType;

    /**
     * Nombre del comprador (hasta 60 caracteres)
     * @var string
     */
    public $buyerName;

    /**
     * Descripción del producto (hasta 125 caracteres)
     * @var string
     */
    public $productDescription = '';

    /**
     * Idioma mostrado al usuario
     * @var string
     */
    public $language;

    /**
     * Dirección URL remota desde donde se realiza el pago
     * @var string
     */
    public $urlPayment;

    /**
     * Dirección URL cargada de manera transparente donde se recibe la notificación del pago.
     * @var string
     */
    public $urlNotification;


    /**
     * Dirección URL cargada cuando se realiza el pago correctamente
     * @var string
     */
    public $urlSuccess;

    /**
     * Dirección URL cargada cuando se produce un error en el pago
     * @var string
     */
    public $urlError;

    /**
     * Texto mostrado en el botón para enviar el formulario (sólo si auto_submit=false o el navegador no soporta javascript)
     * @var string
     */
    public $defaultSubmitText = 'Pay now';


    public function __construct($appName, $buyer_name = '')
    {
        $this->merchantName = $appName;
        $this->buyerName = $buyer_name;
    }

    /**
     * Renderiza un formulario HTML que muestra la pasarela de pago
     *
     * @param string $target
     * @param bool   $autoSubmit
     * @param array  $attr
     *
     * @return string
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
     * @return string[]
     * @throws InvalidArgumentException
     */
    public abstract function fields(): array;

    /**
     * Comprueba que la notificación de pago recibida es correcta y auténtica
     *
     * @param array|null $postData Datos POST incluidos con la notificación
     * @param float      $fee      Valor completado con la comisión aplicada a la operación
     *
     * @return bool
     */
    public abstract function validateNotification(array $postData = null, float &$fee = 0): bool;


    /**
     * Genera una cadena de texto en código HTML con los atributos indicados en el array asociativo
     *
     * @param array|string $attributes
     */
    private static function _buildAttributes($attributes = '', bool $escape = true): string
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

    protected static function _ceilPrecision($value, $precision)
    {
        $pow = pow(10, $precision);
        return (ceil($pow * $value) + ceil($pow * $value - ceil($pow * $value))) / $pow;
    }
}