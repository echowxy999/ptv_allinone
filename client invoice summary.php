<?php


class Admin_JobController extends Zend_Controller_Action
{


    /**
     * Download Customer Invoice Excel according Date Range and Custoemr List
     * @param $startDate
     * @param $endDate
     * @return array
     */
    public function downloadCustInvoiceFile($startDate, $endDate,$arrClient){
        $fileList = array();
        foreach($arrClient as $clientKey =>  $line){
            $baseUrl = $line[0];
            if('' == trim($line[0])){
                throw new Exception("Client CSV File Format Error ");
            }
            $exportUrl = $baseUrl.'v3/shipments/export-manifest/nocookie/allowNoCookie';
            $post_field = 'date_begin='.$startDate.'&date_end='.$endDate.'&id_choice%5B%5D='.$line[1].'&id_choice%5B%5D='.$line[2].'&all=all&btn_export=Export';


            $curl_connection = curl_init();
            curl_setopt($curl_connection, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_URL, $exportUrl);
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 0);
            curl_setopt($curl_connection, CURLOPT_POST, 1);
            curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_field);
            curl_setopt($curl_connection, CURLOPT_HEADER, false);
            curl_setopt($curl_connection, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, 0);

            $result = curl_exec($curl_connection);
            curl_close($curl_connection);


            $doc = new \DOMDocument();
            $doc->loadHTML($result);

            // all links in document
            $links = [];
            $arr = $doc->getElementsByTagName("a"); // DOMNodeList Object
            foreach($arr as $item) { // DOMElement Object
                $href =  $item->getAttribute("href");
                $text = trim(preg_replace("/[\r\n]+/", " ", $item->nodeValue));
                $links[] = $href;
            }

            $searchword = 'Manifest_Export';
            foreach($links as $link){
                if (strpos($link, $searchword)){
                    $text = $link;
                    $manifestFile = str_replace( '/export/', '', $text);
                }
            }


            $filePath = $baseUrl.'export/'.$manifestFile;
            $fileName = substr($filePath, 0, strpos($filePath, ".parceltube"));
            $clientName = str_replace( 'http://', '', $fileName);
            $fileName = $clientName.'_'.$startDate .'_'.$endDate.'_'.rand(0,20). ".xls";
            $fileList[$clientKey] = $fileName;

            $filePath = str_replace(" ","%20",$filePath);
            $content= file_get_contents($filePath);


            file_put_contents(getcwd().'/'.$fileName, $content);

        }
        return ($fileList);

    }

    public function matchCustomerInvoice($arrClientFileList,$arrInvoiceData,$arrClient ){

        $arrExcep = array();

        //read cp invoice file
        foreach ($arrClientFileList as $clientKey => $file){

            $clientName = str_replace(".xls","",$file);


            $fl = new Service_Fil_ExcelFileHelper();
            $arrClientInvoiceLines = $fl->readExcel(getcwd() . '/' . $file, 0, 2);


            $arrAttach = array();


            $totalCharge = 0;
            $totalInGst = 0;
            $totalMargin = 0;

            foreach ($arrInvoiceData as $key => $cpInvLine){
                foreach($arrClientInvoiceLines as $clientInvLine){
                    if($cpInvLine[2] == $clientInvLine['C']){

                        //get fuel surcharge rate by month and year
                        $manifestDate = $cpInvLine[3];
                        $fuelRate = $this->getFuelSurchargeRate($manifestDate);

                        unset($arrInvoiceData[$key]); // Why Unset at the Beginin

                        $id = $clientInvLine['A'];
                        $orderId = $clientInvLine['B'];
                        $actWeight = $clientInvLine['N'];
                        $cubWeight = $clientInvLine['O'];
                        $incGst = $cpInvLine[22]+ $cpInvLine[23] + $cpInvLine[26];
                        $fuleEx = $cpInvLine[23];
                        $estWeigh = ($actWeight > $cubWeight)?$actWeight:$cubWeight;


//                      Calculate act charge price
                        $serviceCode = $cpInvLine[7];
                        $zoneCode = $cpInvLine[28];
                        $chargeWeight = $cpInvLine[21];
                        $estCharge = ($clientInvLine['G'] >= 999999)?0:$clientInvLine['G'];


                        $actCharge = 0;

                        if(preg_match("/PC/i", $serviceCode)) {

                            $rateTable = $arrClient[$clientKey][3];
                            $actCharge = $this->matchPCPrice($serviceCode,$zoneCode,$rateTable);
                        }
                        else{
                            if (preg_match("/I77/i", $serviceCode)){

                                $rateTable = $arrClient[$clientKey][4];
                                $actCharge = $this->matchI77Price($chargeWeight,$zoneCode,$rateTable);

                                //fuel charge for i77: price inc Gst * fuel charge rate
                                if($zoneCode == 'MEL'){
                                    $actCharge = round($actCharge, 2);
                                }
                                else{
                                    $actCharge = $actCharge + $fuleEx * 1.1;
                                    $actCharge = round($actCharge, 2);
                                }

                            }
                            if(preg_match("/I10/i", $serviceCode)){

                                $rateTable = 'car_isacp_price_i10_ex_mel';
                                $actCharge = $this->matchI77Price($chargeWeight,$zoneCode,$rateTable);

                                //fuel charge for i10: act Charge * fuel charge rate
                                if($zoneCode == 'MEL'){
                                    $actCharge = round($actCharge, 2);
                                }
                                else{
                                    $actCharge = $actCharge + $actCharge * $fuelRate * 1.1;
                                    $actCharge = round($actCharge, 2);
                                }
                            }

                        }

//                      Calculate adjusted charge
                        $adjCharge = '';


                        if ($estCharge < $actCharge){
                            $adjCharge = $actCharge - $estCharge;
                        }
                        else {
                            $actCharge = $estCharge;
                        }

//                      Calculate percent and margin

                        $margin = $actCharge-$incGst;
                        $percent = round($margin/$incGst*100,2);
                        $percent.="%";

//                      Calculate total
                        $totalCharge += $actCharge;
                        $totalInGst += $incGst;
                        $totalMargin += $margin;



                        $arrTemp = array(
                            $id,
                            $orderId,
                            $cpInvLine[2],
                            $cpInvLine[1],
                            $cpInvLine[3],
                            $cpInvLine[13],
                            $cpInvLine[12],
                            $cpInvLine[28],
                            $actWeight,
                            $cubWeight,
                            $estWeigh,
                            $cpInvLine[8],
                            $cpInvLine[7],
                            $cpInvLine[21],
                            $actCharge,
                            $adjCharge,
                            $cpInvLine[22],
                            $incGst,
                            $margin,
                            $percent
                        );



                        // Generate Exception records file
                        if ($actCharge == 0 or $actCharge < $incGst){
                            array_unshift($arrTemp,$clientName);
                            $arrExcep[] = $arrTemp;
                        }
                        else{
                            $arrAttach[] = $arrTemp;
                        }



                    }
                }

            }

            if (count($arrAttach) > 1){
                $arrTotal = array(
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    "$".$totalCharge,
                    '',
                    '',
                    "$".$totalInGst,
                    "$".$totalMargin,
                    ''
                );

                $arrAttach[] = $arrTotal;

                $attachFileName = $this->invoiceGenerate($arrAttach,$clientName);


                $arrFile[] = array($attachFileName,$totalCharge,$totalInGst);

            }
        }




        if (count($arrFile) == 0){
            echo "no match records!!";
        }
        else{
            if(count($arrInvoiceData) > 3){
                $missingFileName = $this->missingRecordsGenerate($arrInvoiceData);
            }
            if (count($arrExcep) > 0){
                $excepFileName = $this->invoiceGenerate($arrExcep);
            }
            $arrFile = $this->clientInvoiceGenerate($arrFile);
        }

        return array($arrFile,$missingFileName,$excepFileName);

    }




    public function sendClientInvoiceAction(){
        $uploadFolder = "/upload/";

        if (isset ($_POST ['btn_download_cp'])) {
            $cpUrl = $_POST['cp_url'];
            $cpInvoiceFileName = "Couriers_Please" . Service_Sys_DTHpr::dateToday('') . Service_Sys_DTHpr::timeNow('') . ".csv";

            if (file_put_contents(getcwd().$uploadFolder.$cpInvoiceFileName, fopen($cpUrl, 'r'))){

                // Step 1 Download CP invoice
                $arrInvoiceData = $this->transferCsvToArray(getcwd().$uploadFolder.$cpInvoiceFileName);



                $arrDateRange = $this->getCpInvoiceDateRange($arrInvoiceData);

                $dateBegin = $arrDateRange[0];
                $dateEnd = $arrDateRange[1];

                //Step2: download manifest file of all client with date range
                $clientWebisteFileName = getcwd() . '/client_website_list.csv';

                $arrClient = $this->transferCsvToArray($clientWebisteFileName);
//                $fuelSurchargeRateFileName = getcwd() . '/fuel_surcharge_rate.csv';
//                $arrFuel = $this->transferCsvToArray($fuelSurchargeRateFileName);

//                var_dump($arrDateRange,$arrClient);


                $arrClientFileList = $this->downloadCustInvoiceFile($dateBegin,$dateEnd,$arrClient);




                //Step3: match manifest file with cp invoice
//                var_dump($arrClientFileList);


                $array = $this->matchCustomerInvoice($arrClientFileList,$arrInvoiceData,$arrClient);

                $arrFile = $array[0];
                $missingFileName = $array[1];
                $excepFileName = $array[2];



                //Step4: zip all file
                $zipFile = $this->zipFile($dateBegin,$dateEnd,$arrFile,$missingFileName,$excepFileName);

                //Step5: Calculate profit and sub-total
                $totalCharge = 0;
                $totalPrice = 0;
                $totalProfit = 0;
                foreach ($arrFile as $key => $data){
                    $profit = $data[1] - $data[2];
                    $totalCharge += $data[1];
                    $totalPrice += $data[2];
                    $totalProfit += $profit;
                    $arrFile[$key][]=$profit;
                }


                $this->view->assign('dateRange', "Invoice Date Range: ".$dateBegin . " to ".$dateEnd);
                $this->view->exportFileNames = $arrFile;
                $this->view->zipFile = $zipFile;
                $this->view->missingFileName = $missingFileName;
                $this->view->excepFileName = $excepFileName;
                $this->view->totalCharge = $totalCharge;
                $this->view->totalPrice = $totalPrice;
                $this->view->totalProfit = $totalProfit;
            }
            else
            {
                $this->view->assign('downloadStatus', "File downloaded failed!!");

            }

        }



        $this->renderScript("admin/job/send-client-invoice.phtml");


    }

    /**
     * Read CSV file and return Array
     * @param $fileParth
     */
    public function transferCsvToArray($fileParth){
        $arrRes = array();
        $fl = fopen($fileParth,"r");
       while(($lineData = fgetcsv($fl)) !== false){
           $arrRes[] = $lineData;
       }
       fclose($fl);
       //remove Title unset($arrRes[0]);
        return $arrRes;
    }

    /**
     * get Date Range direct from array
     * @param $arrInvoiceData
     */
    public function getCpInvoiceDateRange($arrInvoiceData){
        $dateRange = array();
        foreach ($arrInvoiceData as $line) {
            $date = $line[3];
            if(!empty($date)){
                $dateInt = Service_Sys_DTHpr::transferToInt($date);
                $dateString = "Y" . "-" . "m" . "-" . "d";
                $dateRange[]=date($dateString,$dateInt);
            }
            unset($dateRange[0]); // remove Header
        }
        return array( min($dateRange),max($dateRange));
    }

    /**
     * get Fuel Surcharge Rate by Date
     * @param $date
     */

    public function getFuelSurchargeRate($date){


        //get fuel surcharge rate by month and year
        $fuelSurchargeRateFileName = getcwd() . '/fuel_surcharge_rate.csv';
        $arrFuel = $this->transferCsvToArray($fuelSurchargeRateFileName);

        $dateInt = Service_Sys_DTHpr::transferToInt($date);
        $manifestYear = date("Y",$dateInt);
        $manifestMonth = date("m",$dateInt);

        foreach ($arrFuel as $rate){
            if($rate[0] == $manifestYear && $rate[1] == $manifestMonth){
                $fuelRate = $rate[2];
            }
        }

        return $fuelRate;
    }

    /**
     * Match pc price by zone code, services code, table name
     * @param $serviceCode
     * @param $zoneCode
     * @param $tableName
     */

    public function matchPCPrice($serviceCode,$zoneCode,$tableName){

            $this->priceSet = new DbTable_Car_AuxcpPrice($tableName);

            $whereStr = "`code_zone` LIKE '".trim($zoneCode)."'";
            $priceLine = $this->priceSet->fetchRow($whereStr);

            $codeWeight = str_replace("PC","",$serviceCode);

            if($codeWeight >0){
                $columnSelect = 'kg'.$codeWeight;
            }else{
                $columnSelect = 'kg05';
            }


            $actCharge = $priceLine[$columnSelect];

            return $actCharge;

    }

    /**
     * Match i77/i10 price by weight,zone code, table name
     * @param $weight
     * @param $zoneCode
     * @param $tableName
     */


    public function matchI77Price($weight,$zoneCode,$tableName){

        $this->priceSet = new DbTable_Car_AuxctnPrice($tableName);

        $whereStr = "`code_zone` LIKE '".trim($zoneCode)."'";
        $priceLine = $this->priceSet->fetchRow($whereStr);

        //if flat_rate only use flat rate
        if($priceLine['flat_rate'] > 0){
            $actCharge = $priceLine['flat_rate'];
        }
        else{
            //calculate charge
            $minimum = $priceLine['minimum'];
            $exprice = $priceLine['base'] + $priceLine['per_kilo']*$weight;
            $actCharge = ($minimum>$exprice)?$minimum:$exprice;

        }

        return $actCharge;
    }

    public function invoiceGenerate($arrAttach,$clientName = null){

        if (is_null($clientName)){
            $arrTitle = array(
                "A" => "Client Name",
                "B" => "id",
                "C" => "Order ID",
                "D" => "Consignment No",
                "E" => "Invoice Date",
                "F" => "Consignment Date",
                "G" => "Suburb",
                "H" => "Postcode",
                "I" => "Destination Zone",
                "J" => "Est Act Wt",
                "K" => "Est Cub Wt",
                "L" => "Est Charge Wt",
                "M" => "Service Name",
                "N" => "Service Code",
                "O" => "Act Chargeable Wt",
                "P" => "Est Charge Inc GST",
                "Q" => "Adjusted Charge Inc GST",
                "R" => "Cost Ex GST",
                "S" => "Cost Inc GST",
                "T" => "Margin",
                "U" => "Percent"
            );

            $attachFileName = "anomaly_records.xls";


        }
        else{
            $arrTitle = array(
                "A" => "id",
                "B" => "Order ID",
                "C" => "Consignment No",
                "D" => "Invoice Date",
                "E" => "Consignment Date",
                "F" => "Suburb",
                "G" => "Postcode",
                "H" => "Destination Zone",
                "I" => "Est Act Wt",
                "J" => "Est Cub Wt",
                "K" => "Est Charge Wt",
                "L" => "Service Name",
                "M" => "Service Code",
                "N" => "Act Chargeable Wt",
                "O" => "Est Charge Inc GST",
                "P" => "Adjusted Charge Inc GST",
                "Q" => "Cost Ex GST",
                "R" => "Cost Inc GST",
                "S" => "Margin",
                "T" => "Percent"
            );

            $attachFileName = "Invoice_".$clientName.".xls";
        }




        $fl = new Service_Fil_ExcelFileHelper();
        $fl->exportGeneralExcel($arrTitle,array(),$arrAttach,$attachFileName,"Invoice");

//        $arrFile[] = array($attachFileName,$totalCharge,$totalInGst);

        return $attachFileName;
    }

    public function clientInvoiceGenerate($arrFile){

        //Generate invoice for customer based on previous
        foreach ($arrFile as $key => $file){

            $arrAttach = array();
            $fName = getcwd().'/export/'.$file[0];

            $fl = new Service_Fil_ExcelFileHelper();
            $data = $fl->readExcel( $fName, 0, 2);


            foreach($data as $clientInvLine){
                $arrTemp = array(
                    $clientInvLine['A'],
                    $clientInvLine['B'],
                    $clientInvLine['C'],
                    $clientInvLine['D'],
                    $clientInvLine['E'],
                    $clientInvLine['F'],
                    $clientInvLine['G'],
                    $clientInvLine['H'],
                    $clientInvLine['I'],
                    $clientInvLine['J'],
                    $clientInvLine['K'],
                    $clientInvLine['L'],
                    $clientInvLine['M'],
                    $clientInvLine['N'],
                    $clientInvLine['O']
                );
                $arrAttach[] = $arrTemp;
            }


            $arrTitle = array(
                "A" => "id",
                "B" => "Order ID",
                "C" => "Consignment No",
                "D" => "Invoice Date",
                "E" => "Consignment Date",
                "F" => "Suburb",
                "G" => "Postcode",
                "H" => "Destination Zone",
                "I" => "Est Act Wt",
                "J" => "Est Cub Wt",
                "K" => "Est Charge Wt",
                "L" => "Service Name",
                "M" => "Service Code",
                "N" => "Act Chargeable Wt",
                "O" => "Est Charge Inc GST",
            );

            $file[0] = str_replace(".xls","",$file[0]);

            $attachFileName = "Client_".$file[0].".xls";

            $fl->exportGeneralExcel($arrTitle,array(),$arrAttach,$attachFileName,"Client_Invoice");
            $arrFile[$key][] = $attachFileName;

        }

        return $arrFile;

    }

    public function missingRecordsGenerate($arrMissing){

        //unset title
        unset($arrMissing[0]);


        $arrTitle = array(
            "A" => "Inv. No.",
            "B" => "Inv. Date",
            "C" => "Consignment Ref.",
            "D" => "Consignment Date",
            "E" => "Manifest Ref.",
            "F" => "Customer",
            "G" => "Name",
            "H" => "Service Code",
            "I" => "Service Name",
            "J" => "Manifest Date",
            "K" => "Origin Postcode",
            "L" => "Origin Locality",
            "M" => "Destination Postcode",
            "N" => "Destination Locality",
            "O" => "Receiver Name",
            "P" => "Customer Ref.",
            "Q" => "Total Items",
            "R" => "Total Weight",
            "S" => "Total Volume",
            "T" => "Insurance Cat.",
            "U" => "Declared Value",
            "V" => "Chargeable Weight",
            "W" => "Ex GST",
            "X" => "Fuel Surcharge",
            "Y" => "Insurance",
            "Z" => "Other",
            "AA" => "GST",
            "AB" => "Origin Zone",
            "AC" => "Destination Zone",
            "AD" => "Declared Weight",
            "AE" => "Measured Weight",
            "AF" => "Declared Volume",
            "AG" => "Measured Volume"
        );


        $missingFileName = "Missing_Records" . Service_Sys_DTHpr::dateToday('') . Service_Sys_DTHpr::timeNow('') . ".xls";

        $fl = new Service_Fil_ExcelFileHelper();
        $fl->exportGeneralExcel($arrTitle,array(),$arrMissing,$missingFileName,"Missing_Records");

        return $missingFileName;
    }


    public function zipFile($dateBegin,$dateEnd,$arrFile,$missingFileName = null,$excepFileName = null){

        $zip = new ZipArchive();
        $filename =  'Zip_File_'. $dateBegin .'_'. $dateEnd .'.zip';
        $zipPath =  getcwd().'/export/'. $filename;

        if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
            exit("cannot open <$filename>\n");
        }


        foreach ($arrFile as $file){

            $filePath = getcwd().'/export/'.$file[0];
            $filePathClient = getcwd().'/export/'.$file[3];

            $zip->addFile($filePath,$file[0]);
            $zip->addFile($filePathClient,$file[3]);
        }
        if (!is_null($missingFileName)){
            $zip->addFile(getcwd().'/export/'.$missingFileName,$missingFileName);
        }
        if (!is_null($excepFileName)){
            $zip->addFile(getcwd().'/export/'.$excepFileName,$excepFileName);
        }

        $zip->close();

        return $filename;
    }

    public function updateFuelSurchargeAction(){
        $surChargeFile  =  getcwd().'/fuel_surcharge_rate.csv';

        $doc = new DOMDocument();
        $html = file_get_contents('https://www.couriersplease.com.au/tools/shipping-tools/fuel-surcharge');
        $data = $doc->loadHTML($html);

//        $arr = $doc->getElementsByTagName("td"); // DOMNodeList Object

        var_dump($data);
        die();
        foreach($arr as $item) { // DOMElement Object
            $href =  $item->getAttribute("href");
            $text = trim(preg_replace("/[\r\n]+/", " ", $item->nodeValue));
            $links[] = $href;
        }

    }


    public function qbLoginAction(){
        $qb = new Service_Qb_Quickbooks;
        $session = $qb->callback();

        $token = null;

        $token = $session['sessionAccessToken'];



        $this->view->assign('token', $token);


        $this->renderScript("v3/send-client-invoice.phtml");


    }




}