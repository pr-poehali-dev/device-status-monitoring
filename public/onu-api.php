<?php
/**
 * ONU API — опрос CData OLT через SNMP
 * Положить в корень сайта: /onu-api.php
 * Доступ: https://arttele.ru/onu-api.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// ─── Настройки ───────────────────────────────────────────────────────────────
$OLT_HOST      = '192.168.1.1';   // ← замени на IP твоего CData OLT в локальной сети
$SNMP_COMMUNITY = 'public';
$SNMP_PORT     = 161;
$SNMP_TIMEOUT  = 1000000; // микросекунды (1 сек)
$SNMP_RETRIES  = 2;

// ─── CData GPON ONU OIDs ─────────────────────────────────────────────────────
$OID_STATE = '1.3.6.1.4.1.34592.1.3.5.1.1.5'; // 1=online, 2=offline
$OID_MAC   = '1.3.6.1.4.1.34592.1.3.5.1.1.3';
$OID_SN    = '1.3.6.1.4.1.34592.1.3.5.1.1.2';
$OID_RX    = '1.3.6.1.4.1.34592.1.3.5.1.1.8'; // Rx в 0.001 dBm
$OID_TX    = '1.3.6.1.4.1.34592.1.3.5.1.1.9'; // Tx в 0.001 dBm
$OID_DESC  = '1.3.6.1.4.1.34592.1.3.5.1.1.1'; // Описание/имя ONU

// Стандартные OID (fallback)
$OID_IF_STATUS = '1.3.6.1.2.1.2.2.1.8';
$OID_IF_DESCR  = '1.3.6.1.2.1.2.2.1.2';

// ─── Проверка SNMP ────────────────────────────────────────────────────────────
if (!function_exists('snmp2_walk')) {
    echo json_encode([
        'error'    => 'PHP расширение SNMP не установлено. Выполни: apt install php-snmp',
        'onu_list' => [],
        'total'    => 0,
    ]);
    exit;
}

// ─── Функция опроса ───────────────────────────────────────────────────────────
function snmp_table($host, $community, $port, $oid, $timeout, $retries) {
    $result = [];
    $host_port = "$host:$port";
    $rows = @snmp2_walk($host_port, $community, $oid, $timeout, $retries);
    if (!$rows) return $result;
    foreach ($rows as $key => $val) {
        // Убираем тип (INTEGER: 1 → 1)
        $clean = preg_replace('/^[A-Za-z0-9\-]+:\s*/', '', $val);
        // Извлекаем индекс из OID (последнее число)
        $result[$key] = trim($clean);
    }
    return $result;
}

function snmp_table_indexed($host, $community, $port, $oid, $timeout, $retries) {
    $result = [];
    $host_port = "$host:$port";
    $raw = @snmp2_walk($host_port, $community, $oid, $timeout, $retries, true);
    if (!$raw) return $result;
    foreach ($raw as $full_oid => $val) {
        $parts = explode('.', $full_oid);
        $idx = end($parts);
        $clean = preg_replace('/^[A-Za-z0-9\-]+:\s*/', '', $val);
        $result[$idx] = trim($clean);
    }
    return $result;
}

function format_mac($raw) {
    $clean = preg_replace('/[^a-fA-F0-9]/', '', $raw);
    if (strlen($clean) === 12) {
        return strtoupper(implode(':', str_split($clean, 2)));
    }
    return $raw;
}

// ─── Опрос OLT ───────────────────────────────────────────────────────────────
$errors = [];

$states = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_STATE, $SNMP_TIMEOUT, $SNMP_RETRIES);
$macs   = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_MAC,   $SNMP_TIMEOUT, $SNMP_RETRIES);
$rxs    = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_RX,    $SNMP_TIMEOUT, $SNMP_RETRIES);
$txs    = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_TX,    $SNMP_TIMEOUT, $SNMP_RETRIES);
$sns    = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_SN,    $SNMP_TIMEOUT, $SNMP_RETRIES);
$descs  = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_DESC,  $SNMP_TIMEOUT, $SNMP_RETRIES);

$onu_list = [];

if (!empty($states)) {
    foreach ($states as $idx => $state_val) {
        $state_int = intval($state_val);
        if ($state_int === 1)      $status = 'online';
        elseif ($state_int === 2)  $status = 'offline';
        else                       $status = 'unknown';

        $rx_raw = isset($rxs[$idx]) ? intval($rxs[$idx]) : 0;
        $tx_raw = isset($txs[$idx]) ? intval($txs[$idx]) : 0;

        $rx = $rx_raw !== 0 ? round($rx_raw / 1000.0, 2) : null;
        $tx = $tx_raw !== 0 ? round($tx_raw / 1000.0, 2) : null;

        if ($status === 'online' && $rx !== null && $rx < -28) {
            $status = 'warning';
        }

        $mac = isset($macs[$idx]) ? format_mac($macs[$idx]) : '';
        $sn  = isset($sns[$idx])  ? $sns[$idx] : '';
        $desc = isset($descs[$idx]) ? $descs[$idx] : '';

        $onu_list[] = [
            'id'     => 'ONU-' . str_pad($idx, 3, '0', STR_PAD_LEFT),
            'index'  => $idx,
            'mac'    => $mac,
            'sn'     => $sn,
            'desc'   => $desc,
            'status' => $status,
            'signal' => $rx,
            'tx'     => $tx,
            'olt'    => 'OLT-01',
            'port'   => "1/1/$idx",
            'ip'     => '',
            'uptime' => '',
            'model'  => 'CData OLT',
        ];
    }
} else {
    $errors[] = 'CData OID не ответил, пробуем стандартный ifOperStatus';

    // Fallback: стандартный ifOperStatus
    $if_status = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_IF_STATUS, $SNMP_TIMEOUT, $SNMP_RETRIES);
    $if_descr  = snmp_table_indexed($OLT_HOST, $SNMP_COMMUNITY, $SNMP_PORT, $OID_IF_DESCR,  $SNMP_TIMEOUT, $SNMP_RETRIES);

    if (empty($if_status)) {
        $errors[] = 'OLT не отвечает на SNMP. Проверь IP и community string.';
    }

    foreach ($if_status as $idx => $val) {
        $descr = isset($if_descr[$idx]) ? strtolower($if_descr[$idx]) : '';
        if (strpos($descr, 'onu') === false && strpos($descr, 'gpon') === false && strpos($descr, 'epon') === false) {
            continue;
        }
        $status = (intval($val) === 1) ? 'online' : 'offline';
        $onu_list[] = [
            'id'     => 'ONU-' . str_pad($idx, 3, '0', STR_PAD_LEFT),
            'index'  => $idx,
            'mac'    => '',
            'sn'     => '',
            'desc'   => $if_descr[$idx] ?? '',
            'status' => $status,
            'signal' => null,
            'tx'     => null,
            'olt'    => 'OLT-01',
            'port'   => $if_descr[$idx] ?? $idx,
            'ip'     => '',
            'uptime' => '',
            'model'  => 'CData OLT',
        ];
    }
}

// ─── Ответ ────────────────────────────────────────────────────────────────────
echo json_encode([
    'onu_list' => $onu_list,
    'total'    => count($onu_list),
    'online'   => count(array_filter($onu_list, fn($o) => $o['status'] === 'online')),
    'offline'  => count(array_filter($onu_list, fn($o) => $o['status'] === 'offline')),
    'warning'  => count(array_filter($onu_list, fn($o) => $o['status'] === 'warning')),
    'host'     => $OLT_HOST,
    'errors'   => $errors,
    'updated'  => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
