#!/opt/local/bin/php5
<?php
/*
  $Id: dbgp-mapper.php 7 2007-11-25 22:35:19Z drslump $

  DBGp Path Mapper

  An intercepting proxy for DBGp connections to apply custom path mappings
  to the filenames transmitted. It can be useful to improve debugging sessions
  initiated from a remote server.

  License:

  The GNU General Public License version 3 (GPLv3)

  This file is part of DBGp Path Mapper.

  DBGp Path Mapper is free software; you can redistribute it and/or modify it
  under the terms of the GNU General Public License as published by the Free
  Software Foundation; either version 2 of the License, or (at your option)
  any later version.

  DBGp Path Mapper is distributed in the hope that it will be useful, but
  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
  or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
  for more details.

  You should have received a copy of the GNU General Public License along
  with DBGp Path Mapper; if not, write to the Free Software Foundation, Inc.,
  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA

  See bundled license.txt or check <http://www.gnu.org/copyleft/gpl.html>

  Copyright:

  copyright (c) 2007 Ivan Montes <http://blog.netxus.es>
  adjustments 2012 by Sebastian Kurfürst <http://sandstorm-media.de>
 */

class DBGp_Mapper {

	static $asDaemon = false;
	static $listenSocket = null;
	static $dbgSocket = null;
	static $ideSocket = null;
	static $mappings = array();

	static public $additionalContexts = '';

	static function addMapping($idePath, $dbgPath) {
		self::$mappings[$idePath] = $dbgPath;
	}

	static function destroySockets($sockets) {
		foreach ($sockets as $sock) {
			@socket_shutdown($sock, 2);
			@socket_close($sock);
		}
	}

	static function output($msg) {
		if (!self::$asDaemon)
			echo $msg;
	}

	static function shutdown($msg = '') {
		// make sure all sockets are closed
		self::destroySockets(array(self::$dbgSocket, self::$ideSocket, self::$listenSocket));

		if ($msg) {
			die($msg);
		} else {
			exit();
		}
	}

	static function parseCommandArguments($args) {
		//preg_match_all( '/-([A-Za-z]+)\s+("[^"]*"|[^\s]*)|--\s+([A-Za-z0-9]*)$/', $args, $m, PREG_SET_ORDER);
		preg_match_all('/-([A-Za-z-]+)\s+("[^"]*"|[^\s]*)/', $args, $m, PREG_SET_ORDER);
		$args = array();
		foreach ($m as $arg) {
			$args[$arg[1]] = $arg[2];
		}
		return $args;
	}

	static function buildCommandArguments($args) {
		$out = array();
		foreach ($args as $arg => $value) {
			$out[] = trim('-' . $arg . ' ' . $value);
		}
		return implode(' ', $out);
	}

	static protected function constructClassNameFromPath($path) {
		$matches = array();
		preg_match('#(.*?)/Packages/(.*?)/(.*).php#', $path, $matches);
		$flow3BaseUri = $matches[1];
		$classPath = str_replace('Classes/', '', $matches[3]);
		$className = str_replace(array('.', '/'), '\\', $classPath);
		return array($flow3BaseUri, $className);
	}

	static function map($path) {
		if (strpos($path, '/Packages/') !== FALSE) {
			// We assume it's a FLOW3 class where a breakpoint was set
			list ($flow3BaseUri, $className) = self::constructClassNameFromPath($path);

			$setBreakpointsInFiles = array($path);

			$contexts = array('Development', 'Testing', 'Production');
			if (strlen(self::$additionalContexts) > 0) {
				foreach (explode(',', self::$additionalContexts) as $ctx) {
					$contexts[] = $ctx;
				}
			}

			foreach ($contexts as $context) {
				$codeCacheFileName = $flow3BaseUri . '/Data/Temporary/' . $context . '/Cache/Code/FLOW3_Object_Classes/' . str_replace('\\', '_', $className) . '_Original.php';
				$setBreakpointsInFiles[] = $codeCacheFileName;
				self::$mappings[$codeCacheFileName] = $path;
			}

			return $setBreakpointsInFiles;
		} else {
			return array($path);
		}
	}

	static function unmap($path) {
		foreach (self::$mappings as $k => $v) {
			$path = str_ireplace($k, $v, $path);
		}

		return $path;
	}

	static function run($ideHost, $idePort, $bindIp, $bindPort) {
		# Initialize the listenning socket
		self::$listenSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)
				or self::shutdown('Unable to create listenning socket: ' . socket_strerror(socket_last_error()));
		socket_set_option(self::$listenSocket, SOL_SOCKET, SO_REUSEADDR, 1)
				or self::shutdown('Failed setting options on listenning socket: ' . socket_strerror(socket_last_error()));
		socket_bind(self::$listenSocket, $bindIp, $bindPort)
				or self::shutdown("Failed binding listenning socket ($bindIp:$bindPort): " . socket_strerror(socket_last_error()));
		socket_listen(self::$listenSocket)
				or self::shutdown('Failed listenning on socket: ' . socket_strerror(socket_last_error()));

		self::output("Running DBGp Path Mapper\n");

		$ideBuffer = $dbgBuffer = '';
		$dbgLengthSize = 0;

		$sockets = array(self::$listenSocket);
		while (true) {
			# create a copy of the sockets list since it'll be modified
			$toRead = $sockets;

			# get a list of all clients which have data to be read from
			if (socket_select($toRead, $write = null, $except = null, 0, 10) < 1) {
				continue;
			}

			# check for new connections
			if (in_array(self::$listenSocket, $toRead)) {

				# check if there are connections opened
				if (count($sockets) > 1) {
					self::output("Resetting debug session\n");

					$sockets = array(self::$listenSocket);
					self::destroySockets(array(self::$dbgSocket, self::$ideSocket));
				}

				# accept the debugger connection
				self::$dbgSocket = socket_accept(self::$listenSocket);

				# create a new connection to the IDE
				self::$ideSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or
						self::shutdown('Fatal: Unable to create a new socket');
				if (!@socket_connect(self::$ideSocket, $ideHost, $idePort)) {
					self::output("Error: Unable to contact the IDE at $ideHost:$idePort\n");
					# destroy the connection with the debugger
					self::destroySockets(array(self::$dbgSocket));
				} else {
					# add the debugger and the IDE to the socket to the list
					$sockets[] = self::$dbgSocket;
					$sockets[] = self::$ideSocket;

					self::output("New debug session\n");
				}

				# remove listenning socket from the clients-with-data array
				$key = array_search($listener, $toRead);
				unset($toRead[$key]);
			}

			# process the sockets with data
			foreach ($toRead as $sock) {

				# read data
				$data = @socket_read($sock, 1024);

				# check if the ide or the debugger has disconnected
				if ($data === '' || $data === false) {
					# reset the sockets list
					$sockets = array(self::$listenSocket);
					self::destroySockets(array(self::$dbgSocket, self::$ideSocket));

					self::output("Debug session closed\n");

					# nothing else to do
					continue;
				}

				$pos = strpos($data, "\0");

				# check if the data comes from the debugger or the IDE
				if ($sock === self::$ideSocket) {
					# the command is not complete so just store in buffer
					if ($pos === false) {
						$ideBuffer .= $data;
					} else {
						# end of command found, store it in the buffer
						$ideBuffer .= substr($data, 0, $pos + 1);

						$buf = '';
						$commands = explode("\0", $ideBuffer);

						foreach ($commands as $cmd) {
							if (strlen($cmd)) {
								$parts = explode(" ", $cmd);
								$command = array_shift($parts);
								if ($command === 'breakpoint_set') {
									$args = self::parseCommandArguments(implode(' ', $parts));
									$files = self::map($args['f']);
									foreach ($files as $file) {
										$args['f'] = $file;
										$cmd = $command . ' ' . self::buildCommandArguments($args);
										$buf .= $cmd . "\0";
									}

								} else {
									$buf .= $cmd . "\0";
								}
							}
						}

						//echo "\n\n\n TO SERVER:\n";
						//echo $buf;
						//echo "\n\n";

						socket_write(self::$dbgSocket, $buf);

						# set the buffer with the start of a new command if any
						$ideBuffer = substr($data, $pos + 1);
					}
				} else {

					if ($pos === false) {

						$dbgBuffer .= $data;
						continue;
					}

					# check if we found the null byte after the packet length
					if (!$dbgLengthSize) {

						$dbgLengthSize = $pos;
						$pos = strpos($data, "\0", $pos + 1);
						if ($pos === false) {
							$dbgBuffer .= $data;
							continue;
						}
					}

					# add the remaining data for a packet to the buffer
					$dbgBuffer .= substr($data, 0, $pos + 1);

					$sxe = simplexml_load_string(trim(substr($dbgBuffer, $dbgLengthSize)));

					# reset the buffer with the data left over
					$dbgBuffer = substr($data, $pos + 1);
					$dbgLengthSize = 0;

					if ($sxe->children('http://xdebug.org/dbgp/xdebug')->message) {
						foreach($sxe->children('http://xdebug.org/dbgp/xdebug')->message as $msg) {
							if ($msg->attributes()->filename) {
								$msg->addAttribute('filename', self::unmap((string)$msg->attributes()->filename));
							}
						}
					}
					if (!empty($sxe['fileuri'])) {
						$sxe['fileuri'] = self::unmap($sxe['fileuri']);
					} elseif ($sxe->stack['filename']) {
						foreach ($sxe->stack as $stack) {
							$stack['filename'] = self::unmap($stack['filename']);
						}
					}

					# prepare the processed xml to send a packet message
					$xml = trim($sxe->asXML(), " \t\n\r");
					$xml = (strlen($xml)) . "\0" . $xml . "\0";

					//echo "\n\n\n SENDING TO IDE: ";
					//echo $xml;
					//echo "\n\n\n";

					socket_write(self::$ideSocket, $xml);
				}
			}
		}

		# close listenner socket
		socket_close($listener);
	}

	static function help() {
		global $argv;

		$help = array(
			$argv[0] . " - DBGp Path Mapper <http://blog.netxus.es>, adjusted by Sebastian Kurfürst for FLOW3 (http://sandstorm-media.de)",
			"",
			"Usage:",
			"\t" . $argv[0],
			"",
			"With the default configuration, xdebug needs to connect to port 9000,",
			"and your IDE should listen on port 9001.",
			"",
			"Options:",
			"\t-h           Show this help and exit",
			"\t-V           Show version and exit",
			"\t-i hostname  Client/IDE ip or host address (default: 127.0.0.1)",
			"\t-p port      Client/IDE port number (default: 9001)",
			"\t-I ip        Bind to this ip address (default: all interfaces)",
			"\t-P port      Bind to this port number (default: 9000)",
			"\t-f           Run in foreground (default: disabled)",
			"\t-c Development/Foo,Production/Bar",
			"\t             comma-separated list of additional contexts to support.",
			"\t             Note: There is NO SPACE ALLOWED between the additional contexts.",
			"",
			""
		);

		echo implode(PHP_EOL, $help);
	}

	static function processArguments() {
		if (function_exists('getopt')) {
			$r = getopt('hVfi:p:I:P:c:');
		} else {
			$args = implode(' ', $GLOBALS['argv']);
			$r = self::parseCommandArguments($args);
		}

		if (isset($r['V'])) {
			echo "DBGp Path Mapper v2.0 - FLOW3 version of 09.08.2012\n";
			exit();
		} else if (isset($r['h'])) {
			self::help();
			exit();
		}

		return array(
			'i' => isset($r['i']) ? $r['i'] : '127.0.0.1',
			'p' => isset($r['p']) ? $r['p'] : '9001',
			'I' => isset($r['I']) ? $r['I'] : '0.0.0.0',
			'P' => isset($r['P']) ? $r['P'] : '9000',
			'f' => isset($r['f']) ? true : false,
			'c' => isset($r['c']) ? $r['c'] : ''
		);
	}

	static function daemonize() {
		if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
			echo "Warning: Unable to run as daemon, falling back to foreground\n";
			return;
		}

		$pid = pcntl_fork();
		if ($pid === -1) {
			die('Could not fork');
		} else if ($pid) {
			//die("Forked child ($pid)");
		}

		if (!posix_setsid()) {
			die('Could not detach from terminal');
		}

		self::$asDaemon = true;

		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
	}

}

# set up some stuff to run as a daemon
set_time_limit(0);
error_reporting(E_ERROR);
ini_set('output_handler', '');
@ob_end_flush();

# parse arguments
$args = DBGp_Mapper::processArguments();

# makes sure we exit gracefully
register_shutdown_function('DBGp_Mapper::shutdown');


if (!$args['f']) {
	echo "Initializing daemon...\n";
	DBGp_Mapper::daemonize();
}

DBGp_Mapper::$additionalContexts = $args['c'];
# run the process to listen for connections
DBGp_Mapper::run($args['i'], $args['p'], $args['I'], $args['P']);
