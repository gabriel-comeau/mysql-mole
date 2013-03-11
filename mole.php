#!/usr/bin/env php
<?php

	/**
	 * Mysql-mole is a cli-script meant to replicate the "search the entire DB for a string"
	 * functionality of phpMyAdmin.  This is done essentially by brute force so it's important
	 * NOT to run this against a large database.  Done against something smaller, like a typical
	 * CMS package's DB, it works quite well and can be a useful tool when investigating how
	 * data is stored in a package you aren't familiar with.
	 *
	 * @author Gabriel Comeau
	 *
	 * @todo Keep track of how many hits we've gotten - per table and total
	 * @todo Filter out duplicate rows
	 * @todo Ability to detect a suitable index on a given table to it can be selected and filtered
	 * 		 in chunks, instead of all at once as is done now.  This would let things work on much
	 *		 bigger tables (if slowly)
	 * @todo The dividers still look kind of cluttery
	 *
	 */

	// Handle CLI options

	$conf = array();
	$longOpts = array(
	"help",
	"h",
	    "host::",
	    "user::",
	    "password::",
	    "dbname::",
	    "search::",
	    "port::",
	    "no-color::"
	);
	$options = getopt("", $longOpts);


	if (isset($options['help']) || isset($options['h']) || empty($options)) {
		printHelpAndQuit();
	}

	// Make sure we've got all the required info from the user.
	$validFlag = true;
	$errorMessage = "";

	if (isset($options['host'])) {
		$conf['db_host'] = $options['host'];
	} else {
		$errorMessage .= "You must provide a database host via the --host='' parameter!\n";
		$validFlag = false;
	}

	if (isset($options['user'])) {
		$conf['db_user'] = $options['user'];
	} else {
		$errorMessage .= "You must provide a user name for your DB via the --user='' parameter!\n";
		$validFlag = false;
	}

	if (isset($options['password'])) {
		$conf['db_password'] = $options['password'];
	} else {
		$errorMessage .= "You must provide a password for your DB via the --password='' parameter!\n";
		$validFlag = false;
	}

	if (isset($options['dbname'])) {
		$conf['db_name'] = $options['dbname'];
	} else {
		$errorMessage .= "You must provide the database name via the --dbname='' parameter!\n";
		$validFlag = false;
	}

	if (isset($options['search'])) {
		$conf['search'] = $options['search'];
	} else {
		$errorMessage .= "You must provide a search string (what will be looked for) via the --search='' parameter!\n";
		$validFlag = false;
	}

	// Optional parameters

	if (isset($options['port'])) {
		$conf['db_port'] = $options['port'];
	} else {
		// Default port will be fine
		$conf['db_port'] = 3306;
	}

	if (isset($options['no-color'])) {
		$conf['color'] = false;
	} else {
		$conf['color'] = true;
	}

	if ($conf['color']) {
		defineColors();
	} else {
		defineNoColors();
	}

	if (!$validFlag) {
		printHelpAndQuit($errorMessage);
	}


 	/* ---------------- Main querying logic begins here ---------------- */

	// Connect to the DB based on the passed in parameters.
	$dbh = mysqli_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_name'], $conf['db_port']);
	if (!$dbh) {
		printHelpAndQuit("Error occurred connecting to database: (".mysqli_connect_errno().")\n".mysqli_connect_error());
	}

	// Init some containers
	$tables = array();
	$tablesToCols = array();
	$results = array();

	// Get a list of the tables in this DB
	$tablesResult = mysqli_query($dbh, "show tables");
	while ($row = mysqli_fetch_array($tablesResult)) {
			$tables[] = $row[0];
	}

	// Get all the columns for each table
	foreach ($tables as $table) {
		$tablesToCols[$table] = array();
		$query = "DESC `$table`";
		if ($colsRes = mysqli_query($dbh, $query)) {
			while ($row = mysqli_fetch_assoc($colsRes)) {
				$tablesToCols[$table][] = $row['Field'];
			}
		} else {
			echo "Table name which couldn't be described: $table\n";
		}
	}

	/*
	 * This is the part of the script which will choke and die if there is are simply
	 * too many rows in a given table.
	 *
	 * TODO:  add logic to identify a primary key from a given table (or some other suitable id)
	 * and select it in chunks, processing each chunk individually.  Won't make it faster but
	 * will prevent OOM and some tube-clogging.
	 *
	 */
	foreach ($tables as $table) {
		$query = "SELECT * FROM `$table`";
		$allRes = mysqli_query($dbh, $query);
		while ($row = mysqli_fetch_row($allRes)) {
			foreach ($row as $col) {
				/*
				 * Note that this lets the user perform a regex search against
				 * every value in the db.  Obviously use complex or resource-intensive
				 * searches at your own risk.
				 */
				if (preg_match("/".$conf['search']."/", $col)) {
					if (!isset($results[$table]['mole_table_name'])) {
						$results[$table]['mole_table_name'] = $table;
					}
					$results[$table][] = $row;
				}
			}
		}
	}

	/* ----------------Displaying logic begins here ---------------- */

	$termSize = getTermSize();
	foreach ($results as $resultTable) {
		echo "Printing results for ".GREEN.$resultTable['mole_table_name'].WHITE." table\n";
		echo WHITE.buildRowSeparator("=")."\n";

		// Draw the column names as headers

		$colsCount = count($tablesToCols[$resultTable['mole_table_name']]);
		$colCounter = 0;
		$columnRow = "";
		$rowForCount = ""; // Without the color codes which take up space

		foreach ($tablesToCols[$resultTable['mole_table_name']] as $colName) {
			if ($colCounter == 0) {
				$columnRow .= WHITE."|-- ".LIGHTPURPLE."$colName ".WHITE."--|";
				$rowForCount .= "|-- $colName --|";
			} elseif ($colCounter < $colsCount -1) {
				$columnRow .= WHITE."-- ".LIGHTPURPLE."$colName ".WHITE."--|";
				$rowForCount .= "-- $colName --|";
			} else {
				$columnRow .= WHITE."-- ".LIGHTPURPLE."$colName ".WHITE;
				$rowForCount .= "-- $colName ";
			}
			$colCounter++;
		}

		// This mess fills available empty space with -- chars
		// It has to calculate the number of lines and fill the difference
		// of the last one.
		$emptySpace = strlen($rowForCount) - $termSize['width'];

		if ($emptySpace > 0) {
			$lines = 1;
			while (($lines * $termSize['width']) < strlen($rowForCount)) {
				$lines ++;
			}

			$emptySpace = ($termSize['width'] * $lines) - strlen($rowForCount);

			for ($i=0; $i<$emptySpace - 1; $i++) {
				if ($i == 0) {
					$columnRow .= " ";
				} elseif ($i == $emptySpace - 2) {
					$columnRow .= "|";
				} else {
					$columnRow .= "-";
				}
			}
		} elseif ($emptySpace < 0) {
			$emptySpace *= -1;
			for ($i=0; $i<$emptySpace - 1; $i++) {
				if ($i == 0) {
					$columnRow .= " ";
				} elseif ($i == $emptySpace - 2) {
					$columnRow .= "|";
				} else {
					$columnRow .= "-";
				}
			}
		}
		echo $columnRow;

		echo "\n".WHITE.buildRowSeparator("=")."\n";

		// Now draw each row

		foreach ($resultTable as $resultRow) {
			$valCounter = 0;
			$valsRow = "";
			$valsForCount = ""; // Without the color codes which take up space
			if (is_array($resultRow)) {
				foreach ($resultRow as $colVal) {

					if (preg_match("/".$conf['search']."/", $colVal)) {
						$color = RED;
					} else {
						$color = LIGHTCYAN;
					}

					if ($valCounter == 0) {
						$valsRow .= WHITE."|-- ".$color."$colVal ".WHITE."--|";
						$valsForCount .= "|-- $colVal --|";
					} elseif ($valCounter < $colsCount -1) {
						$valsRow .= WHITE."-- ".$color."$colVal ".WHITE."--|";
						$valsForCount .= "-- $colVal --|";
					} else {
						$valsRow .= WHITE."-- ".$color."$colVal ".WHITE;
						$valsForCount .= "-- $colVal ";
					}
					$valCounter++;

				}

				// Same mess as the headers block
				$emptySpace = strlen($valsForCount) - $termSize['width'];

				if ($emptySpace > 0) {
					$lines = 1;
					while (($lines * $termSize['width']) < strlen($valsForCount)) {
						$lines ++;
					}

					$emptySpace = ($termSize['width'] * $lines) - strlen($valsForCount);
					for ($i=0; $i<$emptySpace - 1; $i++) {
						if ($i == 0) {
							$valsRow .= " ";
						} elseif ($i == $emptySpace - 2) {
							$valsRow .= "|";
						} else {
							$valsRow .= "-";
						}
					}
				} elseif ($emptySpace < 0) {
					$emptySpace *= -1;
					for ($i=0; $i<$emptySpace - 1; $i++) {
						if ($i == 0) {
							$valsRow .= " ";
						} elseif ($i == $emptySpace - 2) {
							$valsRow .= "|";
						} else {
							$valsRow .= "-";
						}
					}
				}

				echo $valsRow;
				echo "\n".WHITE.buildRowSeparator()."\n";
			}
		}
		echo "\n";
	}

	/* ---------------- Function definitions ---------------- */

	/**
	 * Builds a separator string to be placed between rows,
	 * based on current terminal height/width.
	 *
	 * @param String The character to be used as a separator
	 * @return String The separator string
	 */
	function buildRowSeparator($sep = "-") {
		$termSize = getTermSize();
		$out = "|";
		for ($i=0; $i<$termSize['width']-3; $i++) {
			$out .= $sep;
		}
		$out .= "|";
		return $out;
	}

	/**
	 * Gets the current size of the terminal in which this script is running
	 *
	 * @return Array.  An array with 'width' and 'height' keys containing the values.
	 */
	function getTermSize() {
		$size['width'] = exec('tput cols');
		$size['height'] = exec('tput lines');
		return $size;
	}

	/**
	 * Prints the standard help spiel to the command line and exits the program.
	 *
	 * @param $customMessage Optional parameter for things like specific error messages.
	 */
	function printHelpAndQuit($customMessage = "") {

		if (!defined("RED")) {
			defineColors();
		}

		$message = "\n";
	    $message .= WHITE."Mysql-mole performs a full text search against an entire mysql database!\n";
	    $message .= RED."Please be cautious when using it against large databases!\n\n";
	    $message .= WHITE."The following options must be provided to the script:\n";
	    $message .= LIGHTCYAN."--host".LIGHTPURPLE." - The mysql hostname or IP address\n";
	    $message .= LIGHTCYAN."--user".LIGHTPURPLE." - The username for the database\n";
	    $message .= LIGHTCYAN."--password".LIGHTPURPLE." - The password for the database\n";
	    $message .= LIGHTCYAN."--dbname".LIGHTPURPLE." - The name of the database\n";
	    $message .= LIGHTCYAN."--search".LIGHTPURPLE." - The literal string or regex to search for\n";
	    $message .= LIGHTCYAN."--port".LIGHTPURPLE." - (OPTIONAL) If your DB doesn't listen on 3306 specify the port here\n";
	    $message .= LIGHTCYAN."--no-colors".LIGHTPURPLE." - (OPTIONAL) Turn off all colorization of output\n";
	    $message .= "\n";

	    if ($customMessage != "") {
	    	$message .= RED.$customMessage."\n";
	    }
	    die($message);
	}

	/**
	 * Defines the color contants with values
	 */
	function defineColors() {
		define("BLACK", "\033[0;30m");
		define("DARKGRAY", "\033[1;30m");
		define("BLUE", "\033[0;34m");
		define("LIGHTBLUE", "\033[1;34m");
		define("GREEN", "\033[0;32m");
		define("LIGHTGREEN", "\033[1;32m");
		define("CYAN", "\033[0;36m");
		define("LIGHTCYAN", "\033[1;36m");
		define("RED", "\033[0;31m");
		define("LIGHTRED", "\033[1;31m");
		define("PURPLE", "\033[0;35m");
		define("LIGHTPURPLE", "\033[1;35m");
		define("BROWN", "\033[0;33m");
		define("YELLOW", "\033[1;33m");
		define("LIGHTGRAY", "\033[0;37m");
		define("WHITE", "\033[1;37m");
	}

	/**
	 * Defines the color contants with no values,
	 * for non-xterms or people who hated my color choices!
	 */
	function defineNoColors() {
		define("BLACK", "");
		define("DARKGRAY", "");
		define("BLUE", "");
		define("LIGHTBLUE", "");
		define("GREEN", "");
		define("LIGHTGREEN", "");
		define("CYAN", "");
		define("LIGHTCYAN", "");
		define("RED", "");
		define("LIGHTRED", "");
		define("PURPLE", "");
		define("LIGHTPURPLE", "");
		define("BROWN", "");
		define("YELLOW", "");
		define("LIGHTGRAY", "");
		define("WHITE", "");
	}
