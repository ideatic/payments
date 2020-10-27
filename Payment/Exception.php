<?php
declare(strict_types=1);

class Payment_Exception extends Exception
{
    protected $_data;

    public const REASON_REFUND = 'refund';

    /**
     * @param string $message Mensaje asociado a la excepción (para el desarrollador)
     * @param mixed  $data    Datos asociados a la excepción que permiten comprender sus causas
     */
    public function __construct($message = '', $data = null)
    {
        parent::__construct($message, 0);
        $this->_data = $data;
    }

    public function getData()
    {
        return $this->_data;
    }

}