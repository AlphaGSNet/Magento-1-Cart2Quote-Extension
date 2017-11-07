<?php

class Pro_Cart2quote_Model_Status extends Ophirah_Qquoteadv_Model_Status
{
    public function getStatusConfirmed()
    {
        return self::STATUS_CONFIRMED;
    }
    
    public function getStatusOrdered()
    {
        return self::STATUS_ORDERED;
    }
}
