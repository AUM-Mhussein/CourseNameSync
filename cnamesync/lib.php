<?php

/**
 * Database Course Name Sync plugin.
 *
 * This plugin synchronises course name with external database table.
 *
 * Created by Mohammad Hussein 15 Jun 2022
 */

defined('MOODLE_INTERNAL') || die();

class enrol_database_plugin extends enrol_plugin {
 
  
    protected function db_get_sql($table, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key=>$value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key = '$value'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql = "SELECT $distinct $fields
                  FROM $table
                 $where
                  $sort";

        return $sql;
    }

    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    protected function db_init() {
        global $CFG;

        require_once($CFG->libdir.'/adodb/adodb.inc.php');

        // Connect to the external database (forcing new connection).
        $extdb = ADONewConnection($this->get_config('dbtype'));
        if ($this->get_config('debugdb')) {
            $extdb->debug = true;
            ob_start(); // Start output buffer to allow later use of the page headers.
        }

        // The dbtype my contain the new connection URL, so make sure we are not connected yet.
        if (!$extdb->IsConnected()) {
            $result = $extdb->Connect($this->get_config('dbhost'), $this->get_config('dbuser'), $this->get_config('dbpass'), $this->get_config('dbname'), true);
            if (!$result) {
                return null;
            }
        }

        $extdb->SetFetchMode(ADODB_FETCH_ASSOC);
        if ($this->get_config('dbsetupsql')) {
            $extdb->Execute($this->get_config('dbsetupsql'));
        }
        return $extdb;
    }

    protected function db_addslashes($text) {
        // Use custom made function for now - it is better to not rely on adodb or php defaults.
        if ($this->get_config('dbsybasequoting')) {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
        } else {
            $text = str_replace("'", "''", $text);
        }
        return $text;
    }

    protected function db_encode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach($text as $k=>$value) {
                $text[$k] = $this->db_encode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, 'utf-8', $dbenc);
        }
    }

    protected function db_decode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach($text as $k=>$value) {
                $text[$k] = $this->db_decode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, $dbenc, 'utf-8');
        }
    }

	
  public function sync_course_name() {
        global $CFG, $DB;

        // Make sure we sync either enrolments or courses.
        if (!$this->get_config('dbtype') or !$this->get_config('newcoursetable') or !$this->get_config('newcoursefullname') or !$this->get_config('newcourseshortname')) {
            echo 'Course name synchronisation skipped.';
            return 0;
        }

        echo 'Starting course name synchronisation ... created by Mohammad Hussein 15 Jun 2022<br/>';

        // We may need a lot of memory here.
        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        if (!$extdb = $this->db_init()) {
            echo 'Error while communicating with external course database';
            return 1;
        }
        
        $table     = $this->get_config('newcoursetable');
        $fullname  = trim($this->get_config('newcoursefullname'));
        $shortname = trim($this->get_config('newcourseshortname'));
        $idnumber  = trim($this->get_config('newcourseidnumber'));

        // Lowercased versions - necessary because we normalise the resultset with array_change_key_case().
        $fullname_l  = strtolower($fullname);
        $shortname_l = strtolower($shortname);
        $idnumber_l  = strtolower($idnumber);

        $sqlfields = array($fullname, $shortname, $idnumber);
        $sql = $this->db_get_sql($table, array(), $sqlfields, true);
        $externalCourses = array();
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $this->db_decode($fields);
                    
                    $course = new stdClass();
                    $course->fullname  = $fields[$fullname_l];
                    $course->shortname = $fields[$shortname_l];
                    $course->idnumber  = $idnumber ? $fields[$idnumber_l] : '';
                    
                    $externalCourses[] = $course;
                }
            }
            $rs->Close();
        } else {
            $extdb->Close();
            echo 'Error reading data from the external course table';
            return 4;
        }

        echo '<br/><table width="100%" cellspacing="0" cellpadding="5" border="1" style="border-collapse:collapse;">' . 
             '<thead style="background:#eeeeee;"><tr>' . 
             '<th>SR.</th>' . 
             '<th>ID NUMBER</th>' . 
             '<th>BANNER FULL NAME</th>' . 
             '<th>BANNER SHORT NAME</th>' . 
             '<th>IS EXIST</th>' . 
             '<th>COURSE ID</th>' . 
             '<th>MOODLE FULL NAME</th>' . 
             '<th>MOODLE SHORT NAME</th>' . 
             '<th>IS UPDATED</th>' .
             '</tr></thead><tbody>';

        $cnt = 0;
        foreach ($externalCourses as $key => $extCourse) {
            echo '<tr>';
            echo '<td style="text-align:right;">' . ++$cnt . '</td>';
            echo "<td>$extCourse->idnumber</td>" .
                 "<td>$extCourse->fullname</td>" .
                 "<td>$extCourse->shortname</td>";

            if ($updateCourse = $DB->get_record('course', array('idnumber'=>$extCourse->idnumber), 'id, fullname, shortname', IGNORE_MULTIPLE)) {

                echo "<td>TRUE</td>" .
                     "<td>$updateCourse->id</td>" .
                     "<td>$updateCourse->fullname</td>" .
                     "<td>$updateCourse->shortname</td>";

                /* Update this course fullname & shortname, if it's not same */
                if ($updateCourse->fullname != $extCourse->fullname || $updateCourse->shortname != $extCourse->shortname) {
                    echo '<td style="background:#f39c12">TRUE</td>';

                    $updateCourse->fullname = $extCourse->fullname;
                    $updateCourse->shortname = $extCourse->shortname;
            
                    $DB->update_record('course', $updateCourse, true);
                    
                } else {
                    echo "<td>FALSE</td>";
                }
            } else {
                echo '<td colspan="5" style="background:#dd4b39">FALSE</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table><br/>';

        // Close db connection.
	    $extdb->Close();
    
        echo '...course name synchronisation finished.';
    
        return 0;
    }
}
