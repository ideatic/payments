<?php

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
    public $merchant_id;

    /**
     * Nombre del comercio
     * @var string
     */
    public $merchant_name;

    /**
     * Tipo de transacción. Por defecto, un pago único.
     * @var string
     */
    public $transaction_type;

    /**
     * Nombre del comprador (hasta 60 caracteres)
     * @var string
     */
    public $buyer_name;

    /**
     * Descripción del producto (hasta 125 caracteres)
     * @var string
     */
    public $product_description = '';

    /**
     * Idioma mostrado al usuario
     * @var string
     */
    public $language;

    /**
     * Dirección URL remota desde donde se realiza el pago
     * @var string
     */
    public $url_payment;
    
    /**
     * Dirección URL cargada de manera transparente donde se recibe la notificación del pago.
     * @var string
     */
    public $url_notification;


    /**
     * Dirección URL cargada cuando se realiza el pago correctamente
     * @var string
     */
    public $url_success;

    /**
     * Dirección URL cargada cuando se produce un error en el pago
     * @var string
     */
    public $url_error;

    /**
     * Texto mostrado en el botón para enviar el formulario (sólo si auto_submit=false o el navegador no soporta javascript)
     * @var string
     */
    public $default_submit_text = 'Pay now';


    public function __construct($app_name, $buyer_name = '')
    {
        $this->merchant_name = $app_name;
        $this->buyer_name = $buyer_name;
    }

    /**
     * Renderiza un formulario HTML que muestra la pasarela de pago
     *
     * @param string $target
     * @param bool   $auto_submit
     * @param array  $attr
     *
     * @return string
     */
    public function render_form($target = '_top', $auto_submit = true, $attr = [])
    {
        //Generar campos del formulario
        $fields = [];
        foreach ($this->fields() as $name => $value) {
            $fields[] = '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '"/>';
        }

        //Botón para enviar el formulario si no hay javascript
        $fields[] = '<noscript><input type="submit" value="' . htmlspecialchars($this->default_submit_text) . '"/></noscript>';

        //Generar cabecera y formulario
        if ($auto_submit && !isset($attr['id'])) {
            $attr['id'] = 'payment_form_' . mt_rand();
        }

        $html = '<form ' . self::_build_attributes(
                $attr + [
                    'target' => $target,
                    'action' => $this->url_payment,
                    'method' => 'post'
                ]
            ) . '>' . implode('', $fields) . '</form>';

        //Código para autoenvío
        if ($auto_submit) {
            $html .= '<script>document.getElementById(' . json_encode($attr['id']) . ').submit();</script>';
        }

        return $html;
    }

    /**
     * Obtiene los campos que deben ser enviados mediante POST a la plataforma de pago
     *
     * @throws InvalidArgumentException
     * @return string[]
     */
    public abstract function fields();

    /**
     * Comprueba que la notificación de pago recibida es correcta y auténtica
     *
     * @param array $post_data Datos POST incluidos con la notificación
     * @param float $fee Valor completado con la comisión aplicada a la operación
     *
     * @return bool
     */
    public abstract function validate_notification($post_data = null, &$fee=0);


    /**
     * Genera una cadena de texto en código HTML con los atributos indicados en el array asociativo
     * @access private
     *
     * @param array|string $attributes
     *
     * @param bool         $escape
     *
     * @return string
     */
    private static function _build_attributes($attributes = '', $escape = true)
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
}