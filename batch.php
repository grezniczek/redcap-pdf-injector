<?php

    /**
    * Injects multiple PDFs at once an serves them as a .zip download
    * 
    * To Do: Serve Download as merged PDF. pdftk as server dependency is required
    */

    global $Proj;

    #  Retrieve and sanitize parameters
    $params = array(
        "document_id" => htmlspecialchars($_GET["did"]),
        "report_id" => htmlspecialchars($_GET["rid"]),
        "project_id" => htmlspecialchars($_GET["pid"]),
        "dl_format" => htmlspecialchars($_GET["dlf"])
    );

    #   Validate request parameters

    //  Retrieve reports and check if report exists
    $report = \DataExport::getReports($params["report_id"]);
    $reportExists = isset($report) && !empty($report);

    //  Retrieve List of reports that are enabled and check if retrieved report is enabled
    $str = $module->getProjectSetting("reports-enabled");
    $reportsEnabled = array_map('trim', explode(',', $str));
    $isReportEnabled = in_array($params["report_id"], $reportsEnabled);

    //  Retrieve Injections and check if retrieved injection exists
    $injections = $module->getProjectSetting("pdf-injections");    
    $injectionExists = array_key_exists($params["document_id"], $injections);

    //  Die if checks do not pass
    if(!$reportExists || !$isReportEnabled || !$injectionExists) {
        die("Invalid Request");
    }

    #   Prepare injection data

    // Build sort array of sort fields and their attribute (ASC, DESC)
    $sortArray = array();
    if ($report['orderby_field1'] != '') $sortArray[$report['orderby_field1']] = $report['orderby_sort1'];
    if ($report['orderby_field2'] != '') $sortArray[$report['orderby_field2']] = $report['orderby_sort2'];
    if ($report['orderby_field3'] != '') $sortArray[$report['orderby_field3']] = $report['orderby_sort3'];
    // If the only sort field is record ID field, then remove it (because it will sort by record ID and event on its own)
    if (count($sortArray) == 1 && isset($sortArray[$Proj->table_pk]) && $sortArray[$Proj->table_pk] == 'ASC') {
        unset($sortArray[$Proj->table_pk]);
    }

    //  Check syntax of logic string: If there is an issue in the logic, then return false and stop processing
    if ($report['limiter_logic'] != '' && !LogicTester::isValid($report['limiter_logic'])) {
        throw new Exception('Invalid Report Logic.');
    }

    //  Retrieve data with filter logic and sorting
    //  Records::getData() using $report['limiter_logic']

    $pid = $params["project_id"];
    $returnFormat = 'array';
    $fields = array("record_id");
    $filterLogic = $report['limiter_logic'];

    $data = Records::getdata(
        $pid, $returnFormat, null, $fields, null, null, null, null, null, $filterLogic, null, null, null, null, null, $sortArray,false, false, false, true, false, false, $report['filter_type'], false, false, false, false, false, null, 0, false, null, null, false, 0, array(), false, array("record_id")
    );

    //  Flatten $data array into $records
    $records = [];
    foreach ($data as $key => $record) {
        $records[] = $record["record_id"];
    }
    
    #   To Do: Create a new instance from PDF Merger class
    #   Limitation: If pdftk is installed and merging is enabled and PDF download is requested'
    #   Possible Libraries to use: 
    #   - http://www.fpdf.org/en/script/script94.php
    #   - https://github.com/clegginabox/pdf-merger
    
    //$pdf = new \Clegginabox\PDFMerger\PDFMerger;

    //require('classes/fpdf_merge.php');
    //$merge = new FPDF_Merge();

    //  Generate variables used for filenames
    $injection = $injections[$params["document_id"]];
    
    $lbl_ids = "P".$params["project_id"] . "R".$params["report_id"] . "I".$params["document_id"];
    $lbl_names = strtolower(str_replace(" ", "_", $injection["title"]) . "_" . str_replace(" ", "_", $report["title"]));

    //  Create temporary files by looping through records
    $files = [];
    foreach ($records as $key => $record) {
        
        //  Create directory if not exists
        mkdir(__DIR__ . "/tmp");
        //  Write pdf content into temporary file that is going to be deleted after the ZIP has been created
        $filename = __DIR__ . "/tmp". "/" . $lbl_ids . "-" . $lbl_names . "_" . $record . ".pdf";
        $fp = fopen($filename, 'x');

        //  Write to temp file
        $content = $module->renderInjection($params["document_id"], $record, $params["project_id"]);
        fwrite($fp, $content);
        fclose($fp);
        $files[] = $filename;

        #   To do: add files to merger instance
        //$pdf->addPDF($filename, 'all');            
        //$merge->add($filename);

    }

    #   To do: Merge multiple PDFs into one
    //$pdf->merge('browser', 'newTest.pdf', 'P');
    //$merge->output();

    //  Put all files into a zip archive and stream download
    $zipname = $lbl_ids . "_" . $lbl_names .".zip";
    $zip = new ZipArchive;
    $zip->open($zipname, ZipArchive::CREATE);
    foreach ($files as $file) {
        $zip->addFile($file, basename($file));
    }

    //  Add information file
    $zip->addFromString(
        "README.txt", 
        "===================================================" . "\n" .
        "\n" .
        "Project:\t\t\t" . $Proj->project["app_title"] . "\n" .
        "Report:\t\t\t" . $report["title"] . "\n" .
        "Injection:\t\t\t" . $injection["title"]  . "\n" .
        "Records Total:\t\t\t" . count($records) . "\n" .
        "Date Created:\t\t\t" . date("Y-m-d H:i:s") . "\n" .
        "\n" .
        "===================================================" . "\n" .
        "\n" .
        "PDF Batch Export created with PDF Injector" . "\n" . 
        "Documentation: https://tertek.github.io/redcap-pdf-injector/" . "\n" . 
        "\n" .
        "===================================================" . "\n"
    );
    $zip->close();

    if(true) {
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename='.$zipname);
        header('Content-Length: ' . filesize($zipname));
        readfile($zipname);
    }


    # To Do: Serve PDF File as Download
    //  header(..);

    //  Cleanup
    foreach ($records as $key => $record) {
        $filename = __DIR__ . "/tmp". "/".$lbl_ids."-".$lbl_names."_".$record.".pdf";
        unlink($filename);
    }