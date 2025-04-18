<?php
/* ==========================================================================
   TR‑069 ACS entrypoint – PART 1/2
   (send “continue” for the remainder)
   ======================================================================= */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors',    1);

$GLOBALS['session_id']   = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;

$GLOBALS['device_log'] = __DIR__ . '/device.log';
if (!file_exists($GLOBALS['device_log'])) {
    touch($GLOBALS['device_log']);
    chmod($GLOBALS['device_log'], 0666);
}

function tr069_log($msg, $level='INFO')
{
    $ts  = date('Y-m-d H:i:s');
    $out = "[TR-069][$level][{$GLOBALS['session_id']}] $msg";
    error_log($out, 0);

    if (is_writable($GLOBALS['device_log'])) {
        file_put_contents($GLOBALS['device_log'], "[$ts] $out\n", FILE_APPEND);
    }

    $logDir = __DIR__ . '/logs';
    if (is_dir($logDir)) {
        $file = $logDir . '/tr069_' . date('Y-m-d') . '.log';
        file_put_contents($file, "[$ts] $out\n", FILE_APPEND);
    }
}

/* --------------------------------------------------------------------- */

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/tr069/auth/AuthenticationHandler.php';
require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
require_once __DIR__ . '/backend/tr069/tasks/TaskHandler.php';
require_once __DIR__ . '/backend/tr069/utils/ParameterSaver.php';

try {
    /* ---------------- database + authentication ------------------- */
    $db = (new Database())->getConnection();
    $auth = new AuthenticationHandler();
    if (!$auth->authenticate()) {
        tr069_log('Authentication failed','ERROR');
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="TR-069 ACS"');
        exit;
    }

    /* ---------------- initialize helpers -------------------------- */
    $raw_post   = file_get_contents('php://input');
    $taskH      = new TaskHandler();
    $respGen    = new InformResponseGenerator();
    tr069_log('Received: '.substr($raw_post,0,200).'...','DEBUG');

    /* =================================================================
       A) Handle <cwmp:Inform>                                         */
    if (stripos($raw_post,'<cwmp:Inform>') !== false) {

        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post,$m);
        $soapId = $m[1] ?? '1';

        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s',$raw_post,$m);
        $serialNumber = $m[1] ?? null;

        if ($serialNumber) {
            tr069_log("Inform from $serialNumber",'INFO');

            /* --- upsert device record ------------------------------ */
            try {
                $db->prepare(
                    'INSERT INTO devices(serial_number,status,last_contact)
                     VALUES(:s,"online",NOW())
                     ON DUPLICATE KEY UPDATE status="online",last_contact=NOW()'
                )->execute([':s'=>$serialNumber]);
            } catch(PDOException $e){
                tr069_log('DB error: '.$e->getMessage(),'ERROR');
            }

            /* --- manufacturer / model ------------------------------ */
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s',$raw_post,$mf);
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s',$raw_post,$pc);
            $upd = [];
            $par = [':s'=>$serialNumber];
            if (!empty($mf[1])) { $upd[]='manufacturer=:mf'; $par[':mf']=trim($mf[1]); }
            if (!empty($pc[1])) { $upd[]='model_name=:md';  $par[':md']=trim($pc[1]); }
            if ($upd) {
                $sql='UPDATE devices SET '.implode(',',$upd).' WHERE serial_number=:s';
                $db->prepare($sql)->execute($par);
            }

            /* --- auto‑queue info task ------------------------------ */
            try{
                $d=$db->prepare('SELECT * FROM devices WHERE serial_number=:s');
                $d->execute([':s'=>$serialNumber]);
                $row=$d->fetch(PDO::FETCH_ASSOC);

                if(empty($row['ip_address'])||empty($row['software_version'])||empty($row['ssid'])){
                    $db->prepare(
                        'INSERT INTO device_tasks(device_id,task_type,task_data,status,created_at,updated_at)
                         VALUES(:d,"info","{}", "pending", NOW(),NOW())
                         ON DUPLICATE KEY UPDATE id=id'
                    )->execute([':d'=>$row['id']]);
                }
            }catch(PDOException $e){}

            /* --- gather pending tasks ------------------------------ */
            $tasks = $taskH->getPendingTasks($serialNumber);
            if($tasks){
                $GLOBALS['current_task']=$tasks[0];
                session_start();
                $_SESSION['current_task']=$tasks[0];
                $_SESSION['device_serial']=$serialNumber;
                session_write_close();
            }
        }

        header('Content-Type:text/xml');
        echo $respGen->createResponse($soapId);
        exit;
    }

    /* =================================================================
       B) Handle SetParameterValuesResponse (status)                   */
    if (stripos($raw_post,'SetParameterValuesResponse')!==false ||
        stripos($raw_post,'<Status>')!==false) {

        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/',$raw_post,$m);
        $soapId=$m[1]??'1';

        preg_match('/<Status>(.*?)<\/Status>/',$raw_post,$m);
        $status=trim($m[1]??'0');

        $task=null;
        if($GLOBALS['current_task']){ $task=$GLOBALS['current_task']; }
        else{
            session_start();
            $task=$_SESSION['current_task']??$_SESSION['in_progress_task']??null;
            unset($_SESSION['current_task'],$_SESSION['in_progress_task']);
            session_write_close();
        }

        if($task){
            $taskH->updateTaskStatus(
                $task['id'],
                $status==='0'?'completed':'failed',
                $status==='0'?
                  'Successfully applied '.$task['task_type']:
                  'Device returned error '.$status
            );
        }

        header('Content-Type:text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
  <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
        exit;
    }

    /* =================================================================
       C) Device poll (empty POST) – send next RPC                     */
    if (trim($raw_post)==='' ||
        stripos($raw_post,'<cwmp:GetParameterValuesResponse>')!==false) {

        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/',$raw_post,$m);
        $soapId=$m[1]??'1';

        $task=$GLOBALS['current_task']??null;
        if(!$task){
            session_start();
            $task=$_SESSION['current_task']??null;
            session_write_close();
        }

        if(!$task){
            header('Content-Type:text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
  <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
            exit;
        }

        $pr=$taskH->generateParameterValues($task['task_type'],$task['task_data']);
        if(!$pr){
            $taskH->updateTaskStatus($task['id'],'failed','generator null');
            exit;
        }

        /* --- build proper SOAP RPC (see part 2) -------------------- */
        /* ########################################################### */
/* ==========================================================================
   TR‑069 ACS entrypoint – PART 2/2
   (continues directly from “…build proper SOAP RPC” comment)
   ======================================================================= */

        /* --------------- SetParameterValues -------------------------- */
        if ($pr['method']==='SetParameterValues') {

            $paramXml   = '';
            $count      = count($pr['parameters']);
            foreach ($pr['parameters'] as $p) {
                $paramXml .= "        <ParameterValueStruct>\n";
                $paramXml .= "          <Name>".htmlspecialchars($p['name'])."</Name>\n";
                $paramXml .= "          <Value xsi:type=\"".$p['type']."\">"
                           .htmlspecialchars($p['value'])."</Value>\n";
                $paramXml .= "        </ParameterValueStruct>\n";
            }

            $body = "<cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType=\"cwmp:ParameterValueStruct[$count]\">
$paramXml      </ParameterList>
      <ParameterKey>Task-{$task['id']}-".substr(md5(time()),0,8)."</ParameterKey>
    </cwmp:SetParameterValues>";

        /* --------------- Reboot -------------------------------------- */
        } elseif ($pr['method']==='Reboot') {

            $body = "<cwmp:Reboot><CommandKey>{$pr['commandKey']}</CommandKey></cwmp:Reboot>";

        /* --------------- Huawei delay reboot ------------------------- */
        } elseif ($pr['method']==='X_HW_DelayReboot') {

            $body = "<cwmp:X_HW_DelayReboot>
      <CommandKey>{$pr['commandKey']}</CommandKey>
      <DelaySeconds>{$pr['delay']}</DelaySeconds>
    </cwmp:X_HW_DelayReboot>";

        /* --------------- GetParameterValues -------------------------- */
        } elseif ($pr['method']==='GetParameterValues') {

            $n   = count($pr['parameterNames']);
            $xml = '';
            foreach ($pr['parameterNames'] as $nm) {
                $xml .= "        <string>$nm</string>\n";
            }
            $body = "<cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType=\"xsd:string[$n]\">
$xml      </ParameterNames>
    </cwmp:GetParameterValues>";

        } else {
            /* should not happen */
            tr069_log('Unknown method '.$pr['method'],'ERROR');
            exit;
        }

        /* --------------- emit envelope ------------------------------- */
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    '.$body.'
  </soapenv:Body>
</soapenv:Envelope>';

        header('Content-Type:text/xml');
        echo $envelope;

        $taskH->updateTaskStatus($task['id'],'in_progress',
            'Sent '.$pr['method']);
        tr069_log('Sent '.$pr['method'].' (task '.$task['id'].')','INFO');
        exit;
    }

    /* =================================================================
       D) RebootResponse & X_HW_DelayRebootResponse                     */
    if (stripos($raw_post,'RebootResponse')!==false ||
        stripos($raw_post,'X_HW_DelayRebootResponse')!==false) {

        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/',$raw_post,$m);
        $soapId=$m[1]??'1';

        $task=$GLOBALS['current_task']??null;
        if(!$task){
            session_start();
            $task=$_SESSION['current_task']??null;
            session_write_close();
        }
        if($task){
            $taskH->updateTaskStatus($task['id'],'completed','Device rebooting');
        }

        header('Content-Type:text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
  <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
        exit;
    }

    /* =================================================================
       E) Raw GetParameterValuesResponse – save parameters              */
    if (stripos($raw_post,'GetParameterValuesResponse')!==false) {

        session_start();
        $serial = $_SESSION['device_serial'] ?? null;
        session_write_close();

        if ($serial) {
            (new ParameterSaver($db,new class {
                function logToFile($m){ tr069_log($m,'INFO'); }
            }))->save($serial,$raw_post);
            tr069_log("Parameter values saved for $serial",'INFO');
        } else {
            tr069_log('GPV response but serial missing','WARNING');
        }
    }

    /* =================================================================
       F) Generic catch‑all response                                   */
    header('Content-Type:text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
  <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
    exit;

/* --------------------------------------------------------------------- */
} catch (Throwable $e) {
    tr069_log('Fatal: '.$e->getMessage(),'ERROR');
    header('Content-Type:text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
  <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
}
?>
