<?php

class V3_ApiController extends Zend_Controller_Action
{
    public function cpDateCountAction(){

        $date = $this->getParam('date');


        $carrierManifestSet = new DbTable_Ord_CarrierManifestDetail();

        $id_carrier = array(34,36);
        $id_choices = $this->carCustListSet->getCarrierChoice($id_carrier);

        foreach ($id_choices as $id){
            $cpTotal += count($carrierManifestSet->listByDateAll($date,$date,$id));
        }

        echo "Total Courier Please for date ".$date." is: ".$cpTotal;


        $this->render("empty");
    }



}

?>