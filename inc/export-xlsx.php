<?php
/**
 * CFDB7 export to xlsx
 */

if (!defined('ABSPATH')) exit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CFDB7_Export_XLSX {

    /**
     * Download xlsx file
     * @param  String $filename
     * @return file
     */
    public function download_send_headers($filename) {
        // disable caching
        $now = gmdate("D, d M Y H:i:s");
        header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
        header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
        header("Last-Modified: {$now} GMT");
		
        // force download
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment;filename={$filename}");
        header("Cache-Control: max-age=0");
    }

    /**
     * Convert array to xlsx format
     * @param  array  &$array
     * @return file xlsx format
     */
    public function array2xlsx(array &$array) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if (count($array) == 0) {
            return null;
        }

        $array_keys = array_keys($array);
        $heading    = array();
        $unwanted   = array('cfdb7_file', 'cfdb7_', 'your-');

        foreach ($array_keys as $aKeys) {
            if ($aKeys == 'form_date') $aKeys = 'Date';
            if ($aKeys == 'form_id') $aKeys = 'Id';
            $tmp       = str_replace($unwanted, '', $aKeys);
            $tmp       = str_replace(array('-', '_'), ' ', $tmp);
            $heading[] = ucwords($tmp);
        }

        // Set heading row
        $sheet->fromArray($heading, null, 'A1');

        // Set data rows
        $row = 2;
        foreach ($array['form_id'] as $line => $form_id) {
            $line_values = array();
            foreach ($array_keys as $array_key) {
                $val = isset($array[$array_key][$line]) ? $array[$array_key][$line] : '';
                $line_values[] = $val; 
            }
            $sheet->fromArray($line_values, null, "A{$row}");
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Download file
     * @return xlsx file
     */
    public function download_xlsx_file() {

        global $wpdb;
        $cfdb = apply_filters('cfdb7_database', $wpdb);
        $table_name = $cfdb->prefix.'db7_forms';

        if (isset($_REQUEST['xlsx']) && isset($_REQUEST['nonce'])) {

            $nonce = $_REQUEST['nonce'];
            if (!wp_verify_nonce($nonce, 'dnonce')) {
                wp_die('Not Valid.. Download nonce..!!');
            }

            $fid = (int)$_REQUEST['fid'];
            $heading_row = $cfdb->get_results("SELECT form_id, form_value, form_date FROM $table_name
                WHERE form_post_id = '$fid' ORDER BY form_id DESC LIMIT 1", OBJECT);

            $heading_row = reset($heading_row);
            $heading_row = unserialize($heading_row->form_value);
            $heading_key = array_keys($heading_row);
            $rm_underscore = apply_filters('cfdb7_remove_underscore_data', true);

            $total_rows = $cfdb->get_var("SELECT COUNT(*) FROM $table_name WHERE form_post_id = '$fid'");
            $per_query = 1000;
            $total_query = ($total_rows / $per_query);

            $this->download_send_headers("cfdb7-" . date("Y-m-d") . ".xlsx");

            ob_start();

            for ($p = 0; $p <= $total_query; $p++) {

                $offset = $p * $per_query;
                $results = $cfdb->get_results("SELECT form_id, form_value, form_date FROM $table_name
                WHERE form_post_id = '$fid' ORDER BY form_id DESC LIMIT $offset, $per_query", OBJECT);
                
                $data = array();
                $i = 0;
                foreach ($results as $result) {

                    $i++;
                    $data['form_id'][$i] = $result->form_id;
                    $data['form_date'][$i] = $result->form_date;
                    $resultTmp = unserialize($result->form_value);
                    $upload_dir = wp_upload_dir();
                    $cfdb7_dir_url = $upload_dir['baseurl'].'/cfdb7_uploads';

                    foreach ($resultTmp as $key => $value) {
                        $matches = array();

                        if (!in_array($key, $heading_key)) continue;
                        if ($rm_underscore) preg_match('/^_.*$/m', $key, $matches);
                        if (!empty($matches[0])) continue;

                        $value = str_replace(
                            array('&quot;', '&#039;', '&#047;', '&#092;'),
                            array('"', "'", '/', '\\'), $value
                        );

                        if (strpos($key, 'cfdb7_file') !== false) {
                            $data[$key][$i] = empty($value) ? '' : $cfdb7_dir_url.'/'.$value;
                            continue;
                        }
                        if (is_array($value)) {
                            $data[$key][$i] = implode(', ', $value);
                            continue;
                        }
                        $data[$key][$i] = $value;
                        $data[$key][$i] = $this->escape_data($data[$key][$i]);

                    }
                }

                $this->array2xlsx($data);

            }

            echo ob_get_clean();
            die();
        }
    }

    /**
    * Escape a string to be used in a CSV context
    * @param string $data CSV field to escape.
    * @return string    
    */
    public function escape_data($data) {
        $active_content_triggers = array('=', '+', '-', '@', ';');

        if (in_array(mb_substr($data, 0, 1), $active_content_triggers, true)) {
            $data = '"'. $data.'"';
        }

        return $data;
    }
}
