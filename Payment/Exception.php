<?php

declare(strict_types=1);

class Payment_Exception extends Exception
{
    protected mixed $_data;

    public const string REASON_REFUND = 'refund';

    /**
     * @param string $message Mensaje asociado a la excepción (para el desarrollador)
     * @param mixed  $data    Datos asociados a la excepción que permiten comprender sus causas
     */
    public function __construct(string $message = '', mixed $data = null)
    {
        parent::__construct($message, 0);
        $this->_data = $data;
    }

    public function getData(): mixed
    {
        return $this->_data;
    }

}
