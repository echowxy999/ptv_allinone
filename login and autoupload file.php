<?php

class Admin_JobController extends Zend_Controller_Action
{

    public function login($userName, $password)
    {
        $cookieFileName = date("YmdHis");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_COOKIEJAR, getcwd() . "/auscookie/" . $cookieFileName);
        curl_setopt($ch, CURLOPT_COOKIEFILE, getcwd() . "/auscookie/" . $cookieFileName);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
        curl_setopt($ch, CURLOPT_URL, "https://www.ausxpress.com.au/Account/Login");

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_exec($ch);

        $arrField = array(
            "usernameOrEmailAddress" => $userName,
            "password" => $password,
            "returnUrlHash" => ""
        );
        $postField = "";
        foreach ($arrField as $key => $v) {
            $postField .= $key . "=" . $v . "&";
        }
        $postField = rtrim($postField, "&");


        curl_setopt($ch, CURLOPT_URL, "https://www.ausxpress.com.au/Account/Login?returnUrl=/Application");

        curl_setopt($ch, CURLOPT_COOKIEJAR, getcwd() . "/auscookie/" . $cookieFileName);
        curl_setopt($ch, CURLOPT_COOKIEFILE, getcwd() . "/auscookie/" . $cookieFileName);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
        curl_setopt($ch, CURLOPT_POST, count($arrField));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postField);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $content = curl_exec($ch);

        if ($content === "") {
            echo $output = '<span class="alert alert-info"> ERROR OCCUR<br />' . curl_error($ch) . '</span>';
        }
        curl_close($ch);
        return $cookieFileName;
    }

    public function download($startDate, $endDate,$cookieFileName){

        $url = "https://www.ausxpress.com.au/Shared/ExportCsv?input={%22exportNoSurcharges%22:false,%22exportUninvoicedSurcharges%22:false,%22exportAllSurcharges%22:false,%22markAsInvoiced%22:false,%22startDate%22:%22".$startDate."%22,%22endDate%22:%22".$endDate."%22,%22isLodged%22:false,%22isBooked%22:false}";

        $filename = "$startDate"." to ".$endDate.".csv";
        $fp = fopen(getcwd().$filename, 'w+');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIEFILE, getcwd() . "/auscookie/" . $cookieFileName);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Origin: https://www.ausxpress.com.au'));
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.ausxpress.com.au');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        curl_close($ch);


        $data = preg_split('@(?=Parcel Tube)@', $result);

        foreach ($data as $field){
            fwrite($fp,$field);
        }

        fclose($fp);

        if ($result === "") {
            echo "ERROR OCCUR";
        }

        return $filename;

    }

    public function getTimeRange(){

        date_default_timezone_set('Australia/Melbourne');
        $today = date("Y-m-d");
        $subMonth = Service_Sys_DTHpr::adjustMonths("sub",$today, 1);
        $startDate = Service_Sys_DTHpr::getFirstDayOfTheMonth($subMonth);
        $endDate = Service_Sys_DTHpr::getLastDayOfTheMonth($startDate);

        return array($startDate, $endDate);
    }



    public function importAuxCsvAction(){

        if($_POST){

            $timeRange = $this->getTimeRange();
            $startDate = $timeRange[0];
            $endDate = $timeRange[1];


            $userName = "support@parceltube.com.au";
            $password = "Adgjl!2345";
            $cookieFileName = $this->login($userName,$password);

            $fileName = $this->download($startDate, $endDate,$cookieFileName);

            $chargeSet = new DbTable_Car_Auxchargelog();

            $fileNo = "AUX_" . Service_Sys_DTHpr::dateToday("") . Service_Sys_DTHpr::timeNow("");
            $fName = $fileNo . ".csv";

            if (copy(getcwd().$fileName, getcwd() . '/uploads/' . $fName)) {

                echo $fName." Upload OK";
                $fl = fopen(getcwd() . '/uploads/' . $fName,'r');
                $cot = 0;
                while(($line = fgetcsv($fl))!= false){
                    echo   $cot++;
                    if($cot == 1) continue;

                    echo $serviceType = $line[12];
                    echo $consignmentNo = $line[15];
                    //die();
                    $chargeLine =    $chargeSet->searchConsignment($consignmentNo);

                    echo "ChargeLine RES :".count($chargeLine)."<br />";
                    if(substr($serviceType,0,4) != 'MISC' ){
                        if(!$chargeLine){
                            $chargeSet->addAuxchargelogData($line[0],
                                $line[1],
                                $line[2],
                                $line[3],
                                $line[4],
                                $line[5],
                                $line[6],
                                $line[7],
                                $line[8],
                                $line[9],
                                $line[10],
                                $line[12],
                                $line[13],
                                $line[14],
                                $line[15],
                                $line[16],
                                $line[17],
                                $line[18],
                                $line[19],
                                Service_Sys_DTHpr::dateToday(),
                                Service_Sys_DTHpr::dateToday()." ".Service_Sys_DTHpr::timeNow(),
                                0,
                                null,
                                null,
                                null,
                                null,
                                null,
                                null
                            );

                        }

                    }else{
                        // update charge line with Extra Price
                        if($chargeLine){
                            var_dump($chargeLine,$chargeLine->_idCharge);
                            //die();

                            $idCharge = $chargeLine->_idCharge;

                            if(!empty($idCharge)){
                                $chargeSet->updateExtraCharge($idCharge,$line[14]);
                            }else{

                                var_dump("KKKKK",$chargeLine,$chargeLine->getIdCharge(),$chargeLine->_idCharge);
                                die();
                            }


                        }
                    }



                }
            }else{

                echo "Upload File Fail";
            }
        }
        //setp 1 read csv
        // check consignment
        // Service Type , if service type

        $this->renderScript("v3/products/cd-add-mapping.phtml");

    }


}