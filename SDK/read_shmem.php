<?php

// $key should be result of ftok()
function &read_shmem($key, $strategy) {
    $shmid = shmop_open($key, "a", 0, 0);
    if($shmid === false) {
        return false;
    }
    $data = shmop_read($shmid, 0, 0);
    switch($strategy) {
        case 'json':
            return json_decode($data, true);
        case 'php':
            return unserialize($data);
        case 'raw':
            return $data;
        case 'simple':
            return decode_simple($data);
    }
    shmop_close($shmid);
    return $result;
}

function &decode_simple(&$data) {
    $len = unpack('Plen', substr($data, 0, 8));
    $len = $len['len'];
    $offset = 8;
    $result = [];
    echo "Data length: ", strlen($data), "\n";
    echo "Number of elements: $len\n";
    while($len--) {
        $keylen = unpack('Ckeylen', substr($data, $offset, 1));
        $keylen = $keylen['keylen'];
        $offset++;
        $key = substr($data, $offset, $keylen);
        $offset += $keylen;
        $valuelen = unpack('Pvaluelen', substr($data, $offset, 8));
        $valuelen = $valuelen['valuelen'];
        $offset += 8;
        $value = substr($data, $offset, $valuelen);
        //echo "data: $data\n";
        $offset += $valuelen;
        $result[$key] = $value;
    }
    return $result;
}

$mem = read_shmem(ftok(realpath('shmshim.php'), "d"), 'simple');
var_dump($mem);
var_dump(realpath('shmshim.php'));

