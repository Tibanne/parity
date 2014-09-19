<?php

function raidSplit($string, $parts = 3, $min_req = 2) {
	// default setting is for raid5 with 3 disks (requires 2 to decode)
	$parts = (int)round($parts);
	$min_req = (int)round($min_req);
	if ($min_req > $parts) throw new \Exception('Requires more parts than existing');
	if ($parts > 255) throw new \Exception('Requiring too many parts');
	if ($parts > strlen($string)) throw new \Exception('Cannot build this many parts');

	// handle some easy cases now
	if ($min_req == 1) {
		// just pass result as is to each receipient
		return array_fill(0, $parts, pack('CCC', $parts, $min_req, 0).$string);
	}

	// need to split
	$step = (int)ceil(strlen($string)/$parts);

	if ($min_req == $parts) {
		// need all parts to rebuild!
		$res = array();
		for($i = 0; $i < $parts; $i++) {
			if ($i == $parts-1) {
				// last
				$tmp = substr($string, $step*$i);
			} else {
				$tmp = substr($string, $step*$i, $step);
			}
			if (strlen($tmp) < $step) $tmp .= openssl_random_pseudo_bytes($step - strlen($tmp));
			$res[] = pack('CCC', $parts, $min_req, $i).$tmp;
		}
		return $res;
	}

	// need to build XOR tables
	$xor_table_count = $parts - $min_req; // number of table per block
	// each table contain xor of $min_req entries, from 1 to $parts except current part

	$res = array();
	$split = array();
	for($i = 0; $i < $parts; $i++) {
		if ($i == $parts-1) {
			// last
			$tmp = substr($string, $step*$i);
		} else {
			$tmp = substr($string, $step*$i, $step);
		}
		if (strlen($tmp) < $step) $tmp .= openssl_random_pseudo_bytes($step - strlen($tmp));
		$split[$i] = $tmp;
	}

	for($i = 0; $i < $parts; $i++) {
		$block = $split[$i]; // good data
		for($j = 0; $j < $xor_table_count; $j++) {
			$bl = $j;
			$data = str_repeat("\x00", $step);
			for($k = 0; $k < $min_req; $k++) {
				if ($bl == $i) $bl++;
				$data ^= $split[$bl];
				$bl++;
			}
			$block .= $data;
		}
		$res[] = pack('CCC', $parts, $min_req, $i).$block;
	}

	return $res;
}

function raidRepair(array $data, $length) {
	if (!$data) throw new \Exception('Empty array passed, cannot restore anything');
	$data = array_values($data); // drop keys
	// we got an array of strings in $data, attempt to repair raid. First we need to check that first 2 bytes of all blocks are the same
	$head = substr($data[0], 0, 2);
	foreach($data as $tmp) if (substr($tmp, 0, 2) != $head) throw new \Exception('Not all data blocks came from the same encoding!');

	// ok now we need to decode the header
	list(, $parts, $min_req) = unpack('C2', $head);
	if (count($data) < $min_req) throw new \Exception('Not enough parts to recover data');
	if ($min_req == 1) {
		// only need one block, take block 0 and return the data
		return substr($data[0], 3, $length);
	}

	$step = (int)ceil($length/$parts);

	// rebuild an (ordered) array of blocks
	$blocks = array();
	$trail = array();
	foreach($data as $tmp) {
		if (strlen($tmp) < ($step+3)) continue;
		$blocks[ord($tmp[2])] = substr($tmp, 3, $step);
		$trail[ord($tmp[2])] = substr($tmp, $step+3);
	}
	if (count($data) < $min_req) throw new \Exception('Not enough valid parts to recover data, some parts are corrupted');
	ksort($blocks);

	if (count($blocks) == $parts) {
		// much more simple than expected! No missing block => we can return the data directly!
		$tmp = '';
		foreach($blocks as $tmp2) $tmp .= $tmp2;
		return substr($tmp, 0, $length);
	}

	$xor_table_count = $parts - $min_req;

	// search for missing blocks and try to rebuild them
	do {
		$change = false;
		for($b = 0; $b < $parts; $b++) {
			if (isset($blocks[$b])) continue; // already got this one
			// check other blocks for data that can be used to rebuild this one
			for($i = 0; $i < $parts; $i++) {
				if (!isset($trail[$i])) continue; // don't have recovery data for that block :(
				for($j = 0; $j < $xor_table_count; $j++) {
					// fetch that xor table
					$table = substr($trail[$i], $j*$step, $step);
					if (strlen($table) != $step) continue; // table missing or incomplete

					$data = $table;
					$bl = $j;
					$good = false;
					for($k = 0; $k < $min_req; $k++) {
						if ($bl == $i) $bl++;
						if ($bl == $b) {
							$good = true;
							$bl++;
							continue; // skip
						}
						if (!isset($blocks[$bl])) {
							$data = NULL;
							break; // can't use that table to restore, too much is missing
						}
						$data ^= $blocks[$bl];
						$bl++;
					}
					if (!$good) continue;
					if (is_null($data)) continue;
					// one block restored!
					$blocks[$b] = $data;
					$change = true;
					break;
				}
				if (isset($blocks[$b])) break;
			}
		}
	} while($change);

	if (count($blocks) != $parts) throw new \Exception('Failed to rebuild original data, some blocks are probably corrupted or incomplete');
	ksort($blocks);

	$tmp = '';
	foreach($blocks as $tmp2) $tmp .= $tmp2;
	return substr($tmp, 0, $length);
}

