#!/usr/bin/env php
<?php

/**
 * shmshim.php - Shared memory shim for 
 * Licensed under the MIT License.  See LICENSE for details.
 * See README.md for basic overview.
 * Or make this file executable and run:
 * 
 *  ./shmshim.php --help
 *  
 * for usage details.
 */


// Basic definitions
define('VERSION', '0.1.0');
define_filter_strategies(); // Set up filtering constants

pcntl_async_signals(true); // needed for pcntl_signal()
set_time_limit(0);  // needed to run forever

// GLOBALS
// There are three globals used throughout: $cli_options, $options, $shmid
// These represent command-line options, config file options, and the shared
// memory resource ID resepectively.
// Their definitions appear alongside their global declarations for clarity.
// NOTE ON GLOBALS: Some people take avoidance of globals to an extreme.
// Using globals is wholly appropriate for a handful of variables that need to
// be accessed widely throughout the codebase.

global $cli_options;
$cli_options = getopt('XxCc:hvI', [
    'conf:',
    'help',
    'verbose',
    'ignore-missing-files',
]);

// Display help and exit if help option is set
if(isset($cli_options['h']) || isset($cli_options['help'])) {
    print_help();
    exit(0);
}

// Load the config file
global $options;
$this_dir = dirname(__FILE__);
$default_config_file = $this_dir . '/shmshim.ini';
$dist_config_file = $this_dir . '/shmshim.ini.dist';
$config_file = $cli_options['conf'] ?? $cli_options['c'] ?? $default_config_file;
if($config_file === $default_config_file && !file_exists($default_config_file) && file_exists($dist_config_file)) {
    verbose("Config file not found.  Attempting to copy dist file to default shmshim.ini location...\n");
    if(!copy($dist_config_file, $default_config_file)) {
        exit(
            "Config file not found: $default_config_file\n"
            . "Additionally, dist config file not found: $dist_config_file\n"
            . "Aborting.\n"
        );
    }
    verbose("Default config file created at: $default_config_file\n");
}
$options = load_config($config_file);
if(isset($cli_options['C'])) {
    echo "Configuration settings loaded from file `$config_file`:\n";
    print_r($options);
    exit(0);
}

// Load user-specified files and data into memory
$serialized_data = load_assets();
verbose("Data loaded.\n");


global $shmid;
$shmid = shmop_open(ftok(__FILE__, $options['project_identifier']), "c", 0600, strlen($serialized_data));
verbose('Obtained shared memory token for ', __FILE__, ': ', $shmid);
install_pcntl_handlers();
if($shmid === false) {
    verbose("Failed to open shared memory.\n");
    exit(1);
}
shmop_write($shmid, $serialized_data, 0);
verbose("Data loaded.  Shared memory size is ", shmop_size($shmid));

// Wait for signals...
while(1) {
    sleep(1);
    pcntl_signal_dispatch();
}

// Fin.


// Functions
function define_filter_strategies() {
    define('FILTER_STRAT_NONE', 0);
    define('FILTER_STRAT_WHITELIST', 1);
    define('FILTER_STRAT_BLACKLIST', 2);
    define('FILTER_STRATS', [
        'none' => FILTER_STRAT_NONE,
        'whitelist' => FILTER_STRAT_WHITELIST,
        'blacklist' => FILTER_STRAT_BLACKLIST,
    ]);
}

function verbose() {
    global $cli_options;
    if(isset($cli_options['v']) || isset($cli_options['verbose'])) {
        $args = func_get_args();
        foreach($args as $arg) {
            echo $arg;
        }
        echo "\n";
    }
}

function load_config($file) {
    // Load CLI options
    global $cli_options;
    
    // Verify config file exists and load it
    if(!file_exists($file)) {
        echo "Configuration file could not be found: $file\n";
        // If verbose mode is on, display CWD in case a relative path has been specified
        verbose("Current working directory is: ", getcwd(), "\n");
        exit(1);
    }

    return parse_ini_file($file, true);
}

function load_files() {
    global $options;
    $filter_files = $options['settings']['filter_files'] ?? 'none';
    $file_types = [];
    if($filter_files !== 'none') {
        $file_types = $options['settings']['file_types'];
    }

    $load_paths = $options['data_load']['load_paths'] ?? [];
    $files = [];
    foreach($load_paths as $path) {
        $files = array_merge($files, walk_dir($path));
    }
    return read_files($files);
}

function load_php() {
    global $options;
    $scripts = $options['data_load']['load_php'] ?? [];
    $loaded_data = [];
    foreach($scripts as $k => $v) {
        $loaded_data[$k] = require $v;
    }
    return $loaded_data;
}


function read_files($files, $filtering_strategy = 0, $file_types = []) {
    global $cli_options;
    if($filtering_strategy === 'none') {
        $filtering_strategy = false;
    }
    $filtering_strategy = FILTER_STRATS[$filtering_strategy] ?? FILTER_STRAT_NONE;
    $data = [];
    foreach($files as $file) {
        // Skip recording this file if it appears in the blacklist or doesn't appear
        // in the whitelist.
        if($filtering_strategy !== FILTER_STRAT_NONE) {
            $mime_type = mime_content_type($file);
            $in_list = in_array($mime_type, $file_types);
            if($filtering_strategy === FILTER_STRAT_WHITELIST) {
                $in_list = !$in_list;
            }
            if($in_list) {
                continue;
            }
        }
        if(!file_exists($file)) {
            if(isset($cli_options['I']) || isset($cli_options['ignore-missing-files'])) {
                verbose("Could not find file: $file\n");
                continue;
            } else {
                exit("Could not find file: $file\n");
            }
        }
        $data[$file] = file_get_contents($file);
    }
    return $data;
}

function walk_dir($path) {
    if($path[-1] == '/') {
        $path = substr($path, 0, -1);
    }
    $files = [$path];
    $ignore = [$path, '.', '..'];
    $i = 0;
    do {
        $target = $files[$i];
        if(is_dir($files[$i])) {
            $new_files = scandir($target);
            foreach($new_files as $new_file) {
                if(!in_array($new_file, ['.', '..'])) {
                    $files[] = $target . '/' . $new_file;
                }
            }
        }
        $i++;
    } while($i < count($files));
    
    return array_diff($files, array_filter($files, 'is_dir'));;
}

function &serialize_data($strategy, &$unserialized_data) {
    $return = new stdClass;
    switch($strategy) {
        case 'php': 
            return serialize($unserialized_data);
        case 'simple':
            return simple_pack($unserialized_data);
        case 'raw':
            return implode('', $unserialized_data);
        case 'json':
        default:
            return json_encode($unserialized_data, JSON_INVALID_UTF8_IGNORE);
    }
}

function &simple_pack($data) {
    $len = count($data);
    $output = pack('P', $len);
    foreach($data as $key => $val) {
        if(strlen($key) > 255) {
            exit("Error: Keys / paths longer than 255 characters are not supported.\n");
        }
        $output .= pack('C', strlen($key));
        $output .= $key;
        $output .= pack('P', strlen($val));
        $output .= $val;
    }
    return $output;
}

function simple_unpack($data) {
    $len = unpack('Q', $data);
    $result = [];
    $offset = 8;
    while($len--) {
        // Get length of key and adjust offset
        $key_len = unpack('C', $data, $offset);
        $offset++;
        // Get length of data and adjust offset
        $data_len = unpack('Q', $data, $offset);
        $offset += 8;
        // Load key and data into result map and move offset beyond end of data
        $result[substr($data, $offset, $offset + $key_len)] = substr($data, $offset + $key_len, $offset + $key_len + $data_len);
        $offset += $key_len + $data_len;
    }
    return $result;
}

// Load all data
// TODO: Use references to avoid copying data all over the place.
function &load_assets() {
    global $options;
    $loaded_files = load_files();
    $loaded_scripts = load_php();
    $loaded_programs = [];
    $executable_enabled = $options['settings']['exec_enabled'];
    if(isset($cli_options['x'])) {
        $executable_enabled = true;
    }
    if($executable_enabled) {
        $loaded_programs = load_commands();
    }

    $unserialized_data = array_merge($loaded_files, $loaded_scripts, $loaded_programs);
    $strategy = $options['settings']['serialization_strategy'] ?? 'json';
    verbose("Serializing data with strategy: ", $strategy, "\n");
    return serialize_data($strategy, $unserialized_data);
}

function &load_commands() {
    global $options;
    $commands = $options['data_load']['load_exec'] ?? [];
    $retval = [];
    foreach($commands as $k => $v) {
        $output = [];
        exec($v, $output);
        $retval[$k] = implode('', $output);
    }
    return $retval;
}

function install_pcntl_handlers() {
    pcntl_signal(SIGINT, 'graceful_shutdown');
    pcntl_signal(SIGTERM, 'graceful_shutdown');
    pcntl_signal(SIGHUP, 'graceful_restart');
}

function graceful_shutdown() {
    global $shmid;
    verbose("Destroying shared memory in ", __FILE__, "...");
    shmop_delete($shmid);
    shmop_close($shmid);
    exit;
}

// TODO: Complete reload logic below
function graceful_restart() {
    global $shmid;
    verbose("SIGHUP received.  Reloading...");
    shmop_delete($shmid);
    shmop_close($shmid);
    // restart...
    
    // Reload config
    global $options;
    $config_file = $cli_options['conf'] ?? $cli_options['c'] ?? dirname(__FILE__) . '/shmshim.ini';
    $options = load_config($config_file);
    $serialized_data = load_assets();
    verbose("Config reloaded.\n");
    $shmid = shmop_open(ftok(__FILE__, $options['project_identifier']), "c", 0600, strlen($serialized_data));
    if($shmid === false) {
        verbose("Failed to open shared memory.\n");
        exit(1);
    }
    //install_pcntl_handlers();
    shmop_write($shmid, $serialized_data, 0);
    verbose("Data reloaded.  Shared memory size is ", shmop_size($shmid));
    //while(1) sleep(1);
}

function print_help() {
    global $argv;
    $help = <<<EOT
shmshim.php %s

Usage: %s [OPTIONS]
    -h, --help      Output this help and exit.
    --conf=<file>   Specify the location of the shmshim.ini configuration file.
                    Defaults to './shmshim.ini'
    -c=<file>       Same as --conf=<file>
    -C              Print loaded config file settings and exit.
    -v, --verbose   Turn on verbose output.
    -x              Enable loading of command-line programs
    -X              Disable loading of command-line programs
    -I, --ignore-missing-files  Do not abort if a file cannot be loaded


EOT;
    echo sprintf($help, VERSION, $argv[0]);
}
