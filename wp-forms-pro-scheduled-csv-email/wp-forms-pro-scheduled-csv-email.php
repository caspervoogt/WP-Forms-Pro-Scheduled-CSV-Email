<?php 
/*
Plugin Name: WP Forms Pro Scheduled CSV Export
Description: This is a copy of code I use on a production site. I have modified this for general consumption, and have not tested this version, but it should be usable, or very close to it. Note this will requires manually setting up two cron jobs e.g.:

0 7 * * SUN wget -O - -q -t 1 https://www.yoursite.com/?exportformentries
15 7 * * SUN wget -O - -q -t 1 https://www.yoursite.com/?emailcsv

That may limit some people who are on cheap / shared hosting.

Note that the second cron job (the 'emailcsv' one) is set to run a bit after the 'exportformentries' one, to allow the CSVs to get exported to a CSV on the server first. Then the 'emailcsv' cron job will find the latest CSV file on the server, and email it.


Author: Casper Voogt
Author URI: https://www.plethoradesign.com
*/

function wpfEmailCSVexports_mail_form_csv($form_id){
    if(eit_checkwpformlastsubmission($form_id) > 0){//only send mail if needed
        global $wpdb;
        $markreadquery = "UPDATE `wp_wpforms_entries` SET viewed=1 WHERE form_id=".$form_id.";";
        $markread = $wpdb->query( $markreadquery );
        print "emailing service form csv..";//I can't remember why I have this printing here. I think maybe the cron job needed something printed as a response, in order to succeed. Leaving as is for now.
        //find most recent file in /home/master/applications/eit20prod/private_html/form-csv-exports/service-form;
        $files = scandir('/var/www/csv-export-folder/'.$form_id, SCANDIR_SORT_DESCENDING);
        $newest_file = $files[0];
        //clunky IF statements to deal with different email recipients and subject lines for different forms, for sites where you need CSV export for multple forms. Ultimately this should be made a UI option in WP Forms Pro;
        $dir = '/var/www/csv-export-folder/'.$form_id;
        if($form_id == 2233){//sample form ID 2233
            wp_mail("someone@yoursite.com", "Custom Subject Line for form 2233", "Attached is the most recent CSV export", "", $dir."/".$newest_file);//attaches most recent file
        }
        if($form_id == 2225){//sample form ID 2225
            wp_mail("someoneelse@yoursite.com", "Custom Subject Line for form 2225", "Attached is the most recent CSV export", "", $dir."/".$newest_file);//attaches most recent file
        }
    }
}
function wpfEmailCSVexports_init(){

    if(isset($_GET["emailcsv"])){
        //Clunky way to send a separate email per form. Should really be set up in WP Forms Pro UI;
        eit_mail_form_csv(2233);//email Service Request Form CSV
        eit_mail_form_csv(2225);//email EIT2.0 Radiometer Registration Card CSV
    }
}

add_action('init', 'wpfEmailCSVexports_init');


function wpfEmailCSVexports_checkwpformlastsubmission($form_id){
    global $wpdb;
    $query = "SELECT * FROM `wp_wpforms_entries` WHERE form_id=".$form_id." AND viewed=0;";
    $entries = $wpdb->get_results( $query );
    $count = count($entries);
    return $count;
}

function wpfEmailCSVexports_export_wpform_csv($form_id){

        global $wpdb;

        header('Content-Type: text/html; charset=UTF-8');
        
        $query = "SELECT entry_id,date,fields FROM wp_wpforms_entries WHERE form_id=".$form_id." AND date BETWEEN DATE_SUB(NOW(), INTERVAL 7 DAY) AND NOW() ORDER BY entry_id DESC";//select only past 7 days

        $entries = $wpdb->get_results( $query );
        $entriesarray = [];

        // Open a file in write mode ('w')
        date_default_timezone_set('America/New_York');
        $date = date("Y-m-d--H-i-A");

        $today = date("Y-m-d");
        $weekago = date('Y-m-d', strtotime('-1 week'));
        $thisweek = $weekago."-through-".$today;

        $dir = '/var/www/csv-export-folder/'.$form_id;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true))
            {
                throw new Exception("Error creating the directory.");
            }

            //echo "Directory created successfully.";
        }
        else
        {
            //echo "Directory already exists.";
        }
        $fp = fopen($dir.'/serviceform-submissions-'.$thisweek.'.csv', 'w');

        fputs($fp, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

        //get csv header columns
        $i = 0;
        foreach($entries as $entry){
            if($i === 0){//only do this once, for the header;
                $header_entryfields = json_decode( $entry->fields, true );
                $header_entryfieldsarray = array("submission ID", "date", "time");
                foreach($header_entryfields as $header_entryfield){
                    if(isset($header_entryfield['name'])){
                        array_push($header_entryfieldsarray,$header_entryfield['name']);
                    }
                }
                fputcsv($fp, $header_entryfieldsarray);
            }
            $i++;


            $entryfields = json_decode( $entry->fields, true );
            $entryfieldsarray = array();
            //add $entry->entry_id to beginning of $entryfieldsarray
            array_push($entryfieldsarray,$entry->entry_id);


            $dateinitial = new DateTime($entry->date);
            $dateinitial = date($entry->date,strtotime($date.' America/New_York')); 

            $dt = new DateTime($entry->date, new DateTimeZone('UTC'));

            // change the timezone of the object without changing its time
            $dt->setTimezone(new DateTimeZone('America/New_York'));
            
            $date = $dt->format('Y/m/d');
            $time = $dt->format('H:i');

            array_push($entryfieldsarray,$date);
            array_push($entryfieldsarray,$time);

            foreach($entryfields as $entryfield){
                if(($form_id == 1234) && isset($entryfield['value'])){//run this part only for sample form 1234, which has some complex formatting needs. I left this in here as an example of how to override WP entry field data at the time it is exported to CSV. 
                    $field = trim($entryfield['value']);
                    $field = str_replace("____________________", "\r\n", $field);
                    $field = str_replace("Family:", "____________________\r\nFamily:", $field);
                    $field = str_replace("\r\n____________________", "____________________", $field);
                    $field = str_replace("\r____________________", "____________________", $field);
                    $field = str_replace("\n____________________", "____________________", $field);
                    $field = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $field);//remove double empty lines
                }
                else{
                    $field = $entryfield;
                }
                if(isset($entryfield['value'])){
                    if($entryfield['name'] != "Model/Type of Instrument(s), Lamp System(s), and Bulb Type(s)"){
                        $field = str_replace(array("\r", "\n"), ' ', $field);
                        
                    }
                }
                array_push($entryfieldsarray,$field);

            }

            fputcsv($fp, $entryfieldsarray);
        
            array_push($entriesarray,$entryfieldsarray);
        }
          
        fclose($fp);
        $dir = '/var/www/csv-export-folder/'.$form_id;
        $di = new RecursiveDirectoryIterator($dir);
        foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
            if(str_ends_with($filename,".csv") && ($filename != $dir."/serviceform-submissions-".$thisweek.".csv")){
                $filenamenofolder = str_replace($dir."/","",$filename);
                rename($filename, $dir."/"."archived/".$filenamenofolder);
            }
        }
}


if(isset($_GET["exportformentries"])){   //runs on cron

        ?>
        <style type="text/css">
            body{
                display: block!important;
            }
        </style>

        <?php

        if(eit_checkwpformlastsubmission(2233) > 0){//only export new 'Service Request Form' CSV file if needed
            eit_export_wpform_csv(2233);
        }
        if(eit_checkwpformlastsubmission(2225) > 0){//only export new 'EIT2.0 Radiometer Registration Card' CSV file if needed
            eit_export_wpform_csv(2225);
        }
        

}


?>