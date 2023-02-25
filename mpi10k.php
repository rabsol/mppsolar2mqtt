#!/usr/bin/php
<?php

// General settings
// V1.1 (25.02.2023)
//---------------------------------------------------------------------------------
$moxa_ip      = "192.168.178.9";         // ETH-RS232 converter in TCP_Server mode
$moxa_port    = 8234;                    // ETH-RS232 converter port
$moxa_timeout = 15;                      // fsock() connect timeout
$looptime     = 10;                      // time to sleep for next loop run
$tmp_dir      = "/tmp/inv1/";            // act. Values -> need a trailing  "/" !!!
$bud_dir      = "/home/pi/inv1";         // Folder for daily backup
$script_name  = "mpi10k.php";            // script name
$logfilename  = "/tmp/mpi10k_";          // Debugging Logfile
$mqtt_send    = "/home/pi/mqtt_send.sh"; // MQTT send script
//---------------------------------------------------------------------------------
// Logging/Debugging settings:
$debug  = 1;        // advanced debugging
$debug2 = 0;        // advanced debugging CLI only
$debug3 = 0;        // advanced debugging show send(cmd) data
$debug4 = 0;        // advanced debugging show summary data

error_reporting(E_ERROR | E_PARSE);

// Inverter Data Arrays --
$eminfo = [0,0];


// init variables
$loopcounter  = 0;
$storage_stat = 1;
$log2console  = 0;
$fp_log       = 0;
$totalcounter = 0;
$daybase      = 0;
$daybase_yday = 0;
$daypower_old = 0;
$totalpwr_old = 0;
$ac_wh_day    = 0;
$error        = [];
$delta        = 99;     // power decimals init val
$kwh          = 0;      // power with decimals
$resp         = "";
$is_error_write = false;

// ======================= INIT ==================================================
if (!file_exists($tmp_dir)) {    // temp files
  mkdir("$tmp_dir", 0777);
}
if (!file_exists($bud_dir)) {    // Folder for daily backup
  mkdir("$bud_dir", 0777);
}

// open Syslog
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
// open debug logfile
if ($debug) $fp_log = @fopen($logfilename.date("Y-m-d").".log", "a");

// open connection to ETH-RS232 converter
if (!$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout)) {
  echo "FSOCK OPEN ($moxa_ip : $moxa_port: $errstr) failed!!!\n";
  exit(1);
}
stream_set_timeout($fp, $moxa_timeout);   // shorten TCP timer for answers from 60 sec to 10

if ($resp = send_cmd("^P003PI",1)) {;     // Query protocol ID
  $protid = intval($resp[0]);
  if ($debug) echo "ProtocolID: " . $protid . "\n";
  if ($protid != 17) {
    echo "This script works with protocol 17 only! Found version $resp[0] instead\n";
    exit(2);
  }
}
else {
  echo "No response on ProtocolID\n";
  exit(1);
}

//$resp   = send_cmd("^P003ET",0);    // Query total generated energy
//$resp   = send_cmd("^P003DM",0);    // Query machine model
//$resp   = send_cmd("^P003WS",0);    // Query warning status [A=Solar input 1 loss]
//$resp   = send_cmd("^P004CFS",0);   // Query current fault status
//$resp   = send_cmd("^P004FET",0);   // Query first generated energy saved time (2022061521)
//$resp   = send_cmd("^P004PS2",0);   // Query Generator and secondary output information
//$resp   = send_cmd("^P003PS",0);    // Query power status
//$resp   = send_cmd("^P004MOD",0);   // Query working mode [5=Hybrid mode(Line mode, Grid mode)]
//$resp   = send_cmd("^P005BATS",0);  // Query battery setting
//$resp   = send_cmd("^P005ACCT",0);  // Query AC charge time bucket
//$resp   = send_cmd("^P005INGS",0);  // Query internal general status
//$resp   = send_cmd("^P006FPADJ",0); // Query feeding grid power calibration
if ($debug) echo "----------------------------------------------------------------------------------\n";

// Query series number
if ($resp   = send_cmd("^P003ID",1)) $serial = $resp[0];
if ($debug) echo "Serial       : ".$serial."\n";

// CPU FW timestamp
if ($resp = send_cmd("^P005VFWT",6)) {
  $cpu1_date = $resp[0];
  $cpu2_date = $resp[1];
}
// Query CPU version
if ($resp    = send_cmd("^P004VFW",1)) $version1 = $resp[0];
if ($debug) echo "CPU-1 Version: $version1 ($cpu1_date)\n";

// Query secondary CPU version
if ($resp =  send_cmd("^P005VFW2",1)) $version2 = $resp[0];
if ($debug) echo "CPU-2 Version: $version2 ($cpu2_date)\n";

// Query Inverter Modell
if ($resp = send_cmd("^P003MD",9)) {
  $modelcode    = $resp[0];
  $modelva      = $resp[1];
  $modelpf      = $resp[2];
  $modelbattpcs = $resp[7];
  $modelbattv   = $resp[8] / 10;
}
if ($modelcode="000") $model="MPI Hybrid 10KW/3P";
if ($debug) {
  echo "Modell       : $model\n";
  echo "Max-VA       : $modelva W\n";
  echo "PowerFactor  : $modelpf\n";
  echo "BattVoltage  : " . $modelbattv * $modelbattpcs . " V\n";
}

// get date+time and set current time from server if minutes differ -----------------------
$resp = send_cmd("^P002T",1); // P002T<cr>: Query current time
if (strlen($resp[0])==14) {   // Datestring received from inverter
  $invzeit = $resp[0];        // date-time from inverter
  $syszeit = date("YmdHis");
  if ($debug) {
    echo "Server Time  : $syszeit \n";
    echo "Invert Time  : $invzeit \n";
  }
  if (substr($invzeit,0,11)!=substr($syszeit,0,11)) {   // don't check the seconds
    if ($debug) echo "**** Inverter clock needs adjustment *****";
    $datum=date('ymdHis');
    echo "DATUM im Server:".$datum."\n";
    fwrite($fp, "^S016DAT".$datum.chr(0x0d));
    $resp = parse_resp();
    echo "RESP:".$resp[0]."\n";
  }
}
echo "----------------------------------------------------------------------------------\n";
echo " Start-Time: ". date("Y-m-d H:i:s") . " / $model \n";
echo " $version1 ($cpu1_date) / $version2 ($cpu2_date) \n";
echo " ----\n";

$timer1 = microtime(true);     // init loop timer for Wh calculation once
$loopcounter=100;              // trigger: get_alarms() & "inverter working mode" queries

//---------------------------------------------------------------------------------------------------------
// MAIN LOOP
//---------------------------------------------------------------------------------------------------------
while (true) { 
  if ($debug) echo "---------------------------------------------------------------------------------------\n";

  if ($loopcounter++ == 100) { // 100 = Query alarms about every 6 minutes 
    get_alarms();              // check inverter alarms/warnings
    $modus = get_wmode();      // get inverter working mode
    $loopcounter=0;
  }

  if ($resp=send_cmd("^P003GS",26)) {                      // Query general status
    store_general_status();
    $battpower = round($battvolt*$battamps);
  }
  if ($resp = send_cmd("^P003PS",22)) {                    // Query power status
    store_power_status(); 
    $dc_power  = $pv1power + $pv2power;
  }
  if ($resp = send_cmd("^P005INGS",11)) {                  // Query internal general status
    store_internal_general_status();
  }
  if ($resp = send_cmd("^P007EMINFO",6)) {                 // EMINFO query zero feed-in status
    store_eminfo();
  }
  $daypwr = get_daypower();                                // kWh generated today
  if ($daypwr >= 0.0) {
     shell_exec("$mqtt_send KWh_today $daypwr");           // MQTT -> HA
  }
  if ($resp = send_cmd("^P003ET",1)) {                     // get total kWh
     $totalpwr  = intval($resp[0]);                        // total generated kWh
     $pout      = $dc_power - $battpower;
     $eigen     = $pout - $eminfo["feed"];
 
     if ($ac_wh_day == 0) {                                // startup script
        $ac_wh_day = read_file("/run/ac_wh_day");
        if ($debug4) echo "INIT: ac_wh_day = $ac_wh_day \n";
     }
     $ltime      = (microtime(true) - $timer1);           // loop time in seconds
     $ac_wh_day += ($acouttotal  / (3600 / $ltime)) * -1;
     $wh         = round($ac_wh_day,0);
     $timer1     = microtime(true);                       // loop timer for Wh calculation
     if (date("Hi") == "0000") {                          // that is a new day
        $wh = 0;                                          // reset AV-Wh-today
        $ac_wh_day = 0;
     }
     write2file("/run/ac_wh_day", $wh);                   // write to RAM disk

     shell_exec("$mqtt_send AC_wh     $wh");              // MQTT -> HA
     shell_exec("$mqtt_send INV_mode  $modus");           // MQTT -> HA
     shell_exec("$mqtt_send PV1_power $pv1power");
     shell_exec("$mqtt_send PV2_power $pv2power");
     shell_exec("$mqtt_send PV_total  $dc_power");
     shell_exec("$mqtt_send BAT_power $battpower");
     shell_exec("$mqtt_send BAT_amps  $battamps");
     shell_exec("$mqtt_send AC_feed " . $eminfo["feed"]);
     shell_exec("$mqtt_send INV_temp  $intemp");
     $total_energy = calc_total_energy();                 // total kWh energy with decimals
     shell_exec("$mqtt_send KWh_total $total_energy");

     if ($debug4) printf("## PV-PWR: %4d W, BAT-PWR: %5d W, ACT: %4d W, FEED: %4d W (%3d), RES: %4d W, Total: %5d kWh, Day: %2.3f kWh\n",
        $dc_power,$battpower,$eminfo["pvpow"],$eminfo["feed"],$acouttotal,$eminfo["resrv"],$totalpwr,$daypwr );
  }
  $ts = time();   //akt. Timestamp 
  sleep($looptime);
}
//---------------------------------------------------------------------------------------------------------
// END OF MAIN LOOP 
//---------------------------------------------------------------------------------------------------------

function cal_crc_half($pin) //-------------------------------------------------------------------------------------------
{
  $sum = 0;
  for ($i = 0; $i < strlen($pin); $i++) {
     $sum += ord($pin[$i]);
  }
  $sum = $sum % 256;
  if (strlen($sum)==2) $sum="0".$sum;
  if (strlen($sum)==1) $sum="00".$sum;
  return $sum;
}

function read_file($filename) //-------------------------------------------------------------------------------------------
{
  global $debug;

  $fp2 = fopen($filename,"r");
  if ($fp2) {                         // file exists
    if (! $ret=fread($fp2, 16)) {     // read file content
      $ret = 0;
      if ($debug) echo "Can't read from file: $filename\n";
    }
    fclose($fp2);
  }
  else {                              // return 0 if file doesn't exist
    $ret = 0;
    if ($debug) echo "File $filename doesn't exist!\n";
  }
  return($ret);
}

function write2file($filename, $value) //-------------------------------------------------------------------------------------
{
  global $debug;

  $fp2 = fopen($filename,"w");
  if ($fp2) {
    if (!fwrite($fp2, (float) $value)) {
      if ($debug) echo "Can't write to file $filename\n";
    }
    fclose($fp2);
  }
  else if ($debug) echo "Can't open file: $filename\n";
}

function logging($txt, $write2syslog=false)
{
        global $fp_log, $log2console, $debug, $ts;
        if ($log2console) echo date("Y-m-d H:i:s").": $txt<br />\n";
        if ($debug)
        {
                list($ts) = explode(".",microtime(true));
                $dt = new DateTime(date("Y-m-d H:i:s.",$ts));
                $logdate = $dt->format("Y-m-d H:i:s.u");
                echo date("Y-m-d H:i:s").": $txt\n";
                fwrite($fp_log, date("Y-m-d H:i:s").": $txt<br />\n");
        }
}

function get_alarms() // --------------------------------------------------------------------------------
{
        global $debug, $debug2, $tmp_dir, $moxa_ip, $moxa_port, $moxa_timeout;

        $resp = send_cmd("^P003WS",25);
	$warnings = array(
		"Solar input 1 loss",
		"Solar input 2 loss",
		"Solar input 1 voltage too high",
		"Solar input 2 voltage too high",
		"Battery under",
		"Battery low",
		"Battery open",
		"Battery voltage too higher",
		"Battery low in hybrid mode",
		"Grid voltage high loss",
		"Grid voltage low loss",
		"Grid frequency high loss",
		"Grid frequency low loss",
		"AC input long-time average voltage over",
		"AC input voltage loss",
		"AC input frequency loss",
		"AC input island",
		"AC input phase dislocation",
		"Over temperature",
		"Over load",
		"EPO active",
		"AC input wave loss",
	);
	$fpA = fopen($tmp_dir.'ALARM.txt',"a");
	if ($fpA)
	{
	for($w=0; $w < count($resp); $w++)
		{
		if (substr($resp[$w],-1)=="1"){
			if ($debug) logging("WARNING: $warnings[$w]");
			fwrite($fpA, date("Y-m-d H:i:s"));
			fwrite($fpA, ": ".$warnings[$w]."\n");
			}
		}
	}
	fclose($fpA);
}

function get_wmode() { //------------------------------------------------------------------------------------
   global $debug, $resp, $modusT;

   //Query inverter working mode -- "^P004MOD"
   if ($resp  = send_cmd("^P004MOD",1)) {
     $modus = $resp[0];
     switch ($modus) {
       case "00":
         $modusT="PowerOn";
         break;
       case "01":
         $modusT="Standby without charging";
         break;
       case "02":
         $modusT="Bypass without charging";
         break;
       case "03":
         $modusT="Inverter";
         break;
       case "04":
         $modusT="Fault";
         break;
       case "05":
         $modusT="Hybrid (Line mode, Grid mode)";
         break;
       case "06":
         $modusT="Charge";
         break;
       default:
         $modusT="unknown";
     }
  }
  if ($debug) logging("DEBUG: Modus: ".$modusT);
  return(intval($modus));
}

function store_general_status() { //---------------------------------------------------------------------------
  global $pv1volt,$pv2volt,$pv1amps,$pv2amps,$battvolt,$battcap,$battamps,$gridvolt1,$gridvolt2,$gridvolt3;
  global $gridfreq,$gridamps1,$gridamps2,$gridamps3,$outvolt1,$outvolt2,$outvolt3,$outfreq,$outamps1,$outamps2;
  global $outamps3,$intemp,$maxtemp,$batttemp;
  global $debug2,$resp;

  $pv1volt   = $resp[0]/10;
  $pv2volt   = $resp[1]/10;
  $pv1amps   = $resp[2]/100;
  $pv2amps   = $resp[3]/100;
  $battvolt  = $resp[4]/10;
  $battcap   = intval($resp[5]);
  $battamps  = $resp[6]/10;
  $gridvolt1 = $resp[7]/10;
  $gridvolt2 = $resp[8]/10;
  $gridvolt3 = $resp[9]/10;
  $gridfreq  = $resp[10]/100;
  $gridamps1 = $resp[11]/10;
  $gridamps2 = $resp[12]/10;
  $gridamps3 = $resp[13]/10;
  $outvolt1  = $resp[14]/10;
  $outvolt2  = $resp[15]/10;
  $outvolt3  = $resp[16]/10;
  $outfreq   = $resp[17]/100;
  $outamps1  = intval($resp[18]);
  $outamps2  = intval($resp[19]);
  $outamps3  = intval($resp[20]);
  $intemp    = intval($resp[21]);
  $maxtemp   = intval($resp[22]);
  $batttemp  = intval($resp[23]);
  if ($debug2) {
    echo "SolarInput1: ".$pv1volt."V\n";
    echo "SolarInput2: ".$pv2volt."V\n";
    echo "SolarInput1: ".$pv1amps."A\n";
    echo "SolarInput2: ".$pv2amps."A\n";
    echo "BattVoltage: ".$battvolt."V\n";
    echo "BattCap    : ".$battcap."%\n";
    echo "BattAmp    : ".$battamps."A\n";
    echo "GridVolt1  : ".$gridvolt1."V\n";
    echo "GridVolt2  : ".$gridvolt2."V\n";
    echo "GridVolt3  : ".$gridvolt3."V\n";
    echo "GridFreq   : ".$gridfreq."Hz\n";
    echo "GridAmps1  : ".$gridamps1."A\n";
    echo "GridAmps2  : ".$gridamps2."A\n";
    echo "GridAmps3  : ".$gridamps3."A\n";
    echo "OutVolt1   : ".$outvolt1."V\n";
    echo "OutVolt2   : ".$outvolt2."V\n";
    echo "OutVolt3   : ".$outvolt3."V\n";
    echo "OutFreq    : ".$outfreq."Hz\n";
    echo "OutAmps1   : ".$outamps1."A\n";
    echo "OutAmps2   : ".$outamps2."A\n";
    echo "OutAmps3   : ".$outamps3."A\n";
    echo "InnerTemp  : ".$intemp."°\n";
    echo "CompMaxTemp: ".$maxtemp."°\n";
    echo "BattTemp   : ".$batttemp."°\n";
  }
}

function store_power_status() {  //-----------------------------------------------------------------------------
  global $pv1power,$pv2power,$acouttotal,$gridpower1,$gridpower2,$gridpower3,$gridpower,$powerperc,$acoutact;
  global $pvinput1status,$pvinput2status,$dcaccode_code,$powerdir_code;
  global $debug2,$resp;
 
  $pv1power   = $resp[0];
  $pv2power   = $resp[1];
  $acouttotal = $resp[6];
  $gridpower1 = $resp[7];
  $gridpower2 = $resp[8];
  $gridpower3 = $resp[9];
  $gridpower  = $resp[10];
  $apppower1  = $resp[11];
  $apppower2  = $resp[12];
  $apppower3  = $resp[13];
  $apppower   = $resp[14];
  $powerperc  = $resp[15];
  $acoutact   = $resp[16];
  if ($acoutact=="0") $acoutactT="disconnected";
  if ($acoutact=="1") $acoutactT="connected";
  $pvinput1status = $resp[17];
  $pvinput2status = $resp[18];
  $battcode_code  = $resp[19];
  if ($battcode_code=="0") $battstat="Leerlauf";
  if ($battcode_code=="1") $battstat="Laden";
  if ($battcode_code=="2") $battstat="Entladen";
  $dcaccode_code = $resp[20];
  if ($dcaccode_code=="0") $dcaccode="donothing";
  if ($dcaccode_code=="1") $dcaccode="AC-DC";
  if ($dcaccode_code=="2") $dcaccode="DC-AC";
  $powerdir_code = $resp[21];
  if ($powerdir_code=="0") $powerdir="donothing";
  if ($powerdir_code=="1") $powerdir="input";
  if ($powerdir_code=="2") $powerdir="output";
  if ($debug2) {
    echo "ACTual values:\n";
    echo " PV1_Power : ".$pv1power."W\n";
    echo " PV2_Power : ".$pv2power."W\n";
    echo " GridPower1: ".$gridpower1."W\n";
    echo " GridPower2: ".$gridpower2."W\n";
    echo " GridPower3: ".$gridpower3."W\n";
    echo " GridPower : ".$gridpower."W\n";
    echo " AC-Out    : ".$acoutactT."\n";
    echo " ApperentPower1: ".$apppower1."W\n";
    echo " ApperentPower2: ".$apppower2."W\n";
    echo " ApperentPower3: ".$apppower3."W\n";
    echo " ApperentPower : ".$apppower."W\n";
    echo " BatteryStatus : ".$battstat."\n";
    echo " DC-AC Power direction: ".$dcaccode."\n";
    echo " PowerOutputPercentage: ".$powerperc."%\n";
  }
}

function store_internal_general_status() { //------------------------------------------------------------------------
  global $ings_InvCurrR,$ings_InvCurrS,$ings_InvCurrT,$ings_OutCurrR,$ings_OutCurrS,$ings_OutCurrT,$ings_PBusVolt;
  global $ings_NBusVolt,$ings_PBusAvgV,$ings_NBusAvgV,$ings_NLintCur;
  global $debug2,$resp;

  $ings_InvCurrR = $resp[0];
  $ings_InvCurrS = $resp[1];
  $ings_InvCurrT = $resp[2];
  $ings_OutCurrR = $resp[3];
  $ings_OutCurrS = $resp[4];
  $ings_OutCurrT = $resp[5];
  $ings_PBusVolt = $resp[6];
  $ings_NBusVolt = $resp[7];
  $ings_PBusAvgV = $resp[8];
  $ings_NBusAvgV = $resp[9];
  $ings_NLintCur = $resp[10];
  if ($debug2) { //Print INGS Command output
    echo "INGS Commando:\n";
    echo " R_Inv_Curr $ings_InvCurrR\n";
    echo " S_Inv_Curr $ings_InvCurrS\n";
    echo " T_Inv_Curr $ings_InvCurrT\n";
    echo " R_Out_Curr $ings_OutCurrR\n";
    echo " S_Out_Curr $ings_OutCurrS\n";
    echo " T_Out_Curr $ings_OutCurrT\n";
    echo " PBus_Volt  $ings_PBusVolt\n";
    echo " NBus_Volt  $ings_NBusVolt\n";
    echo " PBusAvg_V  $ings_PBusAvgV\n";
    echo " NBusAvg_V  $ings_PBusAvgV\n";
    echo " NLine_Cur  $ings_NLintCur\n";
    echo "\n";
  }
}

function get_daypower() // Get today's generated power -------------------------------------------------------------
{
  global $debug;

  $month = date("m");
  $year  = date("Y");
  $day   = date("d");
  $check = cal_crc_half("^P014ED".$year.$month.$day);             // cecksum

  if ($resp = send_cmd("^P014ED".$year.$month.$day.$check,1)) {   // Wh today
     return($resp[0] / 1000.0)  ;                                 // compute kWh
  }
  return -1;
}

function store_eminfo() { //----------------------------------------------------------------------------------------
  global $resp,$debug,$eminfo;

  $eminfo["gpmp"]  = intval($resp[1]);  // the maximum output power for feeding grid
  $eminfo["pvpow"] = intval($resp[2]);  // current used PV power output
  $eminfo["feed"]  = intval($resp[3]);  // current AC power output (to grid)
  $eminfo["resrv"] = intval($resp[4]);  // reserve AC power (max_ac_power - feed)

  if ($debug) { 
    echo " -- EMINFO Command --\n";
    echo " MaxGridPow: " . $eminfo['gpmp']  . " W\n";
    echo " PV-Power  : " . $eminfo['pvpow'] . " W\n";
    echo " AC-Power  : " . $eminfo['feed']  . " W\n";
    echo " ReservPow : " . $eminfo['resrv'] . " W\n";
    echo "\n";
  }
  // experiment with setting feedin ...
  // fwrite($fp, "^S026EMINFO00000,00050,0,00050".chr(0x0d));
  // echo " S026EMINFO 00000,00050,0,00050\n";
  // $resp  = parse_resp();
  // $ecount= count($resp);
  // echo "   RESP: $ecount, $resp[0]\n";
}

function calc_total_energy()  //------------------------------------------------------------------------------------
{
  global $debug,$daypwr,$totalpwr,$totalpwr_old,$delta,$kwh;
 
  if ($daypwr > 0.0) {
    if ($delta == 99) {                                        // 99 = 1st run after prg start
      $delta = read_file("/run/dc_wh_frac");
    }
    $frac1 = round($daypwr - floor($daypwr),3);

    if ($frac1 < $delta)  $frac2 = $frac1 - $delta + 1.0;      // adjust decimals
    else                  $frac2 = $frac1 - $delta;
    if ($delta == 0) $kwh = $totalpwr;                         // when running after reboot
    else             $kwh = $totalpwr + $frac2;                // add decimals

    if ($totalpwr_old != 0 && ($totalpwr_old != $totalpwr)) {  // totalpwr changed
      $delta = round($daypwr - floor($daypwr),3);              // compute diff to daypwr decimals
      write2file("/run/dc_wh_frac", $delta);                   // write to RAM disk
    }
    $totalpwr_old = $totalpwr;                                 // store old val
    if ($debug) {
      echo " kWh-total : $totalpwr\n";
      echo " kWh-today : $daypwr\n";
      echo " kWh+decim : $kwh (frac1=$frac1)(delta=$delta)\n";
    }
  }
  return($kwh);
}

function send_cmd($cmd, $num) {  //----------------------------------------------------------------------------------
  global $fp, $debug, $moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout;

  if ($fp) {
     if (fwrite($fp, $cmd . chr(0x0d))) {                          // send $cmd via serial
       if (($buf = stream_get_line($fp, 4096, "\r")) !== false) {  // get line up to <cr>
         $endstr = substr($buf,2,3);                               // get content length
         if (is_numeric($endstr)) {                                // check valid result
           $buffer = substr($buf,5,$endstr-3);                     // remove header bytes
           $retarr = explode(",",$buffer);                         // result array
           $arranz = count($retarr);                               // array member count
           if ($debug3) { 
             echo "| $cmd | $endstr ($arranz=$num) | $buffer |\n"; 
           }
           if ($num == $arranz) {                                  // expected array members?
             return($retarr);                                      // return content array
           }
           else echo "| $cmd | $endstr ($arranz=$num) | $buffer |\n";
         }
       } else {                                                    // comm. problem?
         if ($debug) echo "Read failed: | $cmd | \n";
         sleep(10);                                                // reconnect after 10s
         if (!$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout)) { 
           if ($debug) echo "FSOCK OPEN ($moxa_ip : $moxa_port: $errstr) failed!!!\n";
           sleep(60);                                              // reconnect after 60s (next round)
         } else {
           stream_set_timeout($fp, $moxa_timeout);   // shorten TCP timer for answers from 60 sec to 15
         }
       }
    } 
  }
  return NULL;                                                     // NULL -> error
}

?>
