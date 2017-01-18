<?php
function hash_password($password) {
	$salt_str = bin2hex(openssl_random_pseudo_bytes(8));
	return "{SSHA256}".base64_encode(hash('sha256', $password . $salt_str, true) . $salt_str);
}
function hasDomainAccess($username, $role, $domain) {
	global $pdo;
	if (!filter_var($username, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		return false;
	}
	if (!is_valid_domain_name($domain)) {
		return false;
	}
	if ($role != 'admin' && $role != 'domainadmin' && $role != 'user') {
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `domain` WHERE `domain` = :domain1
      AND (`domain` in (
        SELECT `domain` FROM `domain_admins`
          WHERE (
            `username` = :username
            AND `domain` = :domain2
          )
      ) OR 'admin' = :role)");
		$stmt->execute(array(':username' => $username, ':domain1' => $domain, ':domain2' => $domain, ':role' => $role));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
	if (!empty($num_results)) {
		return true;
	}
	return false;
}
function hasMailboxObjectAccess($username, $role, $object) {
	global $pdo;
	if (!filter_var($username, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		return false;
	}
	if ($role != 'admin' && $role != 'domainadmin' && $role != 'user') {
		return false;
	}
	if ($username == $object) {
		return true;
	}
	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `mailbox` WHERE `username` = :object");
		$stmt->execute(array(':object' => $object));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (isset($row['domain']) && hasDomainAccess($username, $role, $row['domain'])) {
      return true;
    }
	}
  catch(PDOException $e) {
		error_log($e);
		return false;
	}
	return false;
}
function init_db_schema() {
	global $pdo;
	try {
		$stmt = $pdo->prepare("SELECT NULL FROM `admin`, `imapsync`");
		$stmt->execute();
	}
	catch (Exception $e) {
		$lines = file('/web/inc/init.sql');
		$data = '';
		foreach ($lines as $line) {
			if (substr($line, 0, 2) == '--' || $line == '') {
				continue;
			}
			$data .= $line;
			if (substr(trim($line), -1, 1) == ';') {
				$pdo->query($data);
				$data = '';
			}
		}
    // Create index if not exists
		$stmt = $pdo->query("SHOW INDEX FROM sogo_acl WHERE KEY_NAME = 'sogo_acl_c_folder_id_idx'");
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		if ($num_results == 0) {
			$pdo->query("CREATE INDEX sogo_acl_c_folder_id_idx ON sogo_acl(c_folder_id)");
		}
		$stmt = $pdo->query("SHOW INDEX FROM sogo_acl WHERE KEY_NAME = 'sogo_acl_c_uid_idx'");
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		if ($num_results == 0) {
			$pdo->query("CREATE INDEX sogo_acl_c_uid_idx ON sogo_acl(c_uid)");
		}
		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => 'Database initialization completed.'
		);
	}
  // Add newly added columns
  $stmt = $pdo->query("SHOW COLUMNS FROM `mailbox` LIKE 'kind'");
  $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
  if ($num_results == 0) {
    $pdo->query("ALTER TABLE `mailbox` ADD `kind` varchar(100) NOT NULL DEFAULT ''");
  }
  $stmt = $pdo->query("SHOW COLUMNS FROM `mailbox` LIKE 'multiple_bookings'");
  $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
  if ($num_results == 0) {
    $pdo->query("ALTER TABLE `mailbox` ADD `multiple_bookings` tinyint(1) NOT NULL DEFAULT '0'");
  }
  $stmt = $pdo->query("SHOW COLUMNS FROM `mailbox` LIKE 'wants_tagged_subject'");
  $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
  if ($num_results == 0) {
    $pdo->query("ALTER TABLE `mailbox` ADD `wants_tagged_subject` tinyint(1) NOT NULL DEFAULT '0'");
  }
}
function verify_ssha256($hash, $password) {
	// Remove tag if any
	$hash = ltrim($hash, '{SSHA256}');
	// Decode hash
	$dhash = base64_decode($hash);
	// Get first 32 bytes of binary which equals a SHA256 hash
	$ohash = substr($dhash, 0, 32);
	// Remove SHA256 hash from decoded hash to get original salt string
	$osalt = str_replace($ohash, '', $dhash);
	// Check single salted SHA256 hash against extracted hash
	if (hash('sha256', $password . $osalt, true) == $ohash) {
		return true;
	}
	else {
		return false;
	}
}
function doveadm_authenticate($hash, $algorithm, $password) {
	$descr = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$pipes = array();
	$process = proc_open("/usr/bin/doveadm pw -s ".$algorithm." -t '".$hash."'", $descr, $pipes);
	if (is_resource($process)) {
		fputs($pipes[0], $password);
		fclose($pipes[0]);
		while ($f = fgets($pipes[1])) {
			if (preg_match('/(verified)/', $f)) {
				proc_close($process);
				return true;
			}
			return false;
		}
		fclose($pipes[1]);
		while ($f = fgets($pipes[2])) {
			proc_close($process);
			return false;
		}
		fclose($pipes[2]);
		proc_close($process);
	}
	return false;
}
function check_login($user, $pass) {
	global $pdo;
	if (!filter_var($user, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $user))) {
		return false;
	}
	$user = strtolower(trim($user));
	$stmt = $pdo->prepare("SELECT `password` FROM `admin`
			WHERE `superadmin` = '1'
			AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass) !== false) {
			unset($_SESSION['ldelay']);
			return "admin";
		}
	}
	$stmt = $pdo->prepare("SELECT `password` FROM `admin`
			WHERE `superadmin` = '0'
			AND `active`='1'
			AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass) !== false) {
			unset($_SESSION['ldelay']);
			return "domainadmin";
		}
	}
	$stmt = $pdo->prepare("SELECT `password` FROM `mailbox`
			WHERE `active`='1'
			AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass) !== false) {
			unset($_SESSION['ldelay']);
			return "user";
		}
	}
	if (!isset($_SESSION['ldelay'])) {
		$_SESSION['ldelay'] = "0";
	}
	elseif (!isset($_SESSION['mailcow_cc_username'])) {
		$_SESSION['ldelay'] = $_SESSION['ldelay']+0.5;
	}
	sleep($_SESSION['ldelay']);
}
function formatBytes($size, $precision = 2) {
	if(!is_numeric($size)) {
		return "0";
	}
	$base = log($size, 1024);
	$suffixes = array(' Byte', ' KiB', ' MiB', ' GiB', ' TiB');
	if ($size == "0") {
		return "0";
	}
	return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
}
function dkim_table($action, $item) {
	global $lang;
	global $pdo;
	switch ($action) {
		case "delete":
    	if ($_SESSION['mailcow_cc_role'] != "admin") {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
			$domain = preg_replace('/[^A-Za-z0-9._\-]/', '_', $item['dkim']['domain']);
			if (!is_valid_domain_name($domain)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
				);
				return false;
			}
			exec('rm ' . escapeshellarg($GLOBALS['MC_DKIM_TXTS'] . '/' . $domain . '.dkim'), $out, $return);
			if ($return != "0") {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['dkim_remove_failed'])
				);
				return false;
			}
			exec('rm ' . escapeshellarg($GLOBALS['MC_DKIM_KEYS'] . '/' . $domain . '.dkim'), $out, $return);
      if ($return != "0") {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['dkim_remove_failed'])
        );
        return false;
      }
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['dkim_removed'])
			);
			break;
		case "add":
    	if ($_SESSION['mailcow_cc_role'] != "admin") {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
			$domain = preg_replace('/[^A-Za-z0-9._\-]/', '_', $item['dkim']['domain']);
			$key_length	= intval($item['dkim']['key_size']);
      if (!is_valid_domain_name($domain) || !is_numeric($key_length)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
        );
        return false;
      }

      if (!empty(glob($GLOBALS['MC_DKIM_TXTS'] . '/' . $domain . '.dkim'))) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
        );
        return false;
      }

			$config = array(
				"digest_alg" => "sha256",
				"private_key_bits" => $key_length,
				"private_key_type" => OPENSSL_KEYTYPE_RSA,
			);
			$keypair_ressource = openssl_pkey_new($config);
			$key_details = openssl_pkey_get_details($keypair_ressource);
			$pubKey = implode(array_slice(
					array_filter(
						explode(PHP_EOL, $key_details['key'])
					), 1, -1)
				);
			// Save public key to file
			file_put_contents($GLOBALS['MC_DKIM_TXTS'] . '/' . $domain . '.dkim', $pubKey);
			// Save private key to file
			openssl_pkey_export_to_file($keypair_ressource, $GLOBALS['MC_DKIM_KEYS'] . '/' . $domain . '.dkim');

			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['dkim_added'])
			);
			break;
		case "get":
      try {
        $stmt = $pdo->prepare("SELECT `target_domain`
          FROM `alias_domain`
            WHERE `alias_domain` = :item");
        $stmt->execute(array(':item' => $item));
        $AdDomainData = $stmt->fetch(PDO::FETCH_ASSOC);
      }
      catch(PDOException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'MySQL: '.$e
        );
        return false;
      }
      (is_valid_domain_name($AdDomainData['target_domain'])) ? $item = $AdDomainData['target_domain'] : null;
    	if (!isset($_SESSION['mailcow_cc_role'])) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
      $dkim_pubkey_file = escapeshellarg($GLOBALS["MC_DKIM_TXTS"]. "/" . $item . "." . "dkim");
      if (file_exists(substr($dkim_pubkey_file, 1, -1))) {
        return 'v=DKIM1;k=rsa;t=s;s=email;p=' . file_get_contents($GLOBALS["MC_DKIM_TXTS"]. "/" . $item . "." . "dkim");
      }
      return false;
			break;
		case "keys-without-domain":
    	if ($_SESSION['mailcow_cc_role'] != "admin") {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
      $dnstxt_folder = scandir($GLOBALS["MC_DKIM_TXTS"]);
      $dnstxt_files = array_diff($dnstxt_folder, array('.', '..'));
      foreach($dnstxt_files as $file) {
        $pubKey = file_get_contents($GLOBALS["MC_DKIM_TXTS"]."/".$file);
        $domains[] = substr($file, 0, -5);
      }
      return array_diff($domains, mailbox_get_domains());
      break;
		case "domains-without-key":
    	if ($_SESSION['mailcow_cc_role'] != "admin") {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
      $dnstxt_folder = scandir($GLOBALS["MC_DKIM_TXTS"]);
      $dnstxt_files = array_diff($dnstxt_folder, array('.', '..'));
      foreach($dnstxt_files as $file) {
        $pubKey = file_get_contents($GLOBALS["MC_DKIM_TXTS"]."/".$file);
        $domains[] = substr($file, 0, -5);
      }
      return array_diff(mailbox_get_domains(), $domains);
      break;
	}
}
function mailbox_add_domain($postarray) {
  // Array elements
  // domain                 string
  // description            string
  // aliases                int
  // mailboxes              int
  // maxquota               int
  // quota                  int
  // active                 int
  // relay_all_recipients   int
  // backupmx               int
	global $pdo;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$domain				= idn_to_ascii(strtolower(trim($postarray['domain'])));
	$description  = $postarray['description'];
	$aliases			= $postarray['aliases'];
	$mailboxes    = $postarray['mailboxes'];
	$maxquota			= $postarray['maxquota'];
	$quota				= $postarray['quota'];

	if ($maxquota > $quota) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_quota_exceeds_domain_quota'])
		);
		return false;
	}

	isset($postarray['active'])               ? $active = '1'                 : $active = '0';
	isset($postarray['relay_all_recipients'])	? $relay_all_recipients = '1'   : $relay_all_recipients = '0';
	isset($postarray['backupmx'])             ? $backupmx = '1'               : $backupmx = '0';
	isset($postarray['relay_all_recipients']) ? $backupmx = '1'               : true;

	if (!is_valid_domain_name($domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}

	foreach (array($quota, $maxquota, $mailboxes, $aliases) as $data) {
		if (!is_numeric($data)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['object_is_not_numeric'], htmlspecialchars($data))
			);
			return false;
		}
	}

	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `domain`
			WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		$stmt = $pdo->prepare("SELECT `alias_domain` FROM `alias_domain`
			WHERE `alias_domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$num_results = $num_results + count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	if ($num_results != 0) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_exists'], htmlspecialchars($domain))
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("INSERT INTO `domain` (`domain`, `description`, `aliases`, `mailboxes`, `maxquota`, `quota`, `transport`, `backupmx`, `created`, `modified`, `active`, `relay_all_recipients`)
			VALUES (:domain, :description, :aliases, :mailboxes, :maxquota, :quota, 'virtual', :backupmx, :created, :modified, :active, :relay_all_recipients)");
		$stmt->execute(array(
			':domain' => $domain,
			':description' => $description,
			':aliases' => $aliases,
			':mailboxes' => $mailboxes,
			':maxquota' => $maxquota,
			':quota' => $quota,
			':backupmx' => $backupmx,
			':active' => $active,
			':created' => date('Y-m-d H:i:s'),
			':modified' => date('Y-m-d H:i:s'),
			':relay_all_recipients' => $relay_all_recipients
		));
		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => sprintf($lang['success']['domain_added'], htmlspecialchars($domain))
		);
	}
	catch (PDOException $e) {
    mailbox_delete_domain(array('domain' => $domain));
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
}
function mailbox_add_alias($postarray) {
  // Array elements
  // address  string  (separated by " ", "," ";" "\n") - email address or domain
  // goto     string  (separated by " ", "," ";" "\n")
  // active   int
	global $lang;
	global $pdo;
	$addresses  = array_map('trim', preg_split( "/( |,|;|\n)/", $postarray['address']));
	$gotos      = array_map('trim', preg_split( "/( |,|;|\n)/", $postarray['goto']));
	isset($postarray['active']) ? $active = '1' : $active = '0';
	if (empty($addresses[0])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['alias_empty'])
		);
		return false;
	}

	if (empty($gotos[0])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['goto_empty'])
		);
		return false;
	}

	foreach ($addresses as $address) {
		if (empty($address)) {
			continue;
		}

		$domain       = idn_to_ascii(substr(strstr($address, '@'), 1));
		$local_part   = strstr($address, '@', true);
		$address      = $local_part.'@'.$domain;

		try {
			$stmt = $pdo->prepare("SELECT `domain` FROM `domain`
				WHERE `domain`= :domain");
			$stmt->execute(array(':domain' => $domain));
			$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
      if ($num_results == 0) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['domain_not_found'], $domain)
        );
        return false;
      }

			$stmt = $pdo->prepare("SELECT `address` FROM `alias`
				WHERE `address`= :address");
			$stmt->execute(array(':address' => $address));
			$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
      if ($num_results != 0) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['is_alias_or_mailbox'], htmlspecialchars($address))
        );
        return false;
      }

			$stmt = $pdo->prepare("SELECT `address` FROM `spamalias`
				WHERE `address`= :address");
			$stmt->execute(array(':address' => $address));
			$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
      if ($num_results != 0) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['is_spam_alias'], htmlspecialchars($address))
        );
        return false;
      }
		}
		catch(PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}

		if ((!filter_var($address, FILTER_VALIDATE_EMAIL) === true) && !empty($local_part)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['alias_invalid'])
			);
			return false;
		}

		if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
		}

		foreach ($gotos as &$goto) {
			if (empty($goto)) {
				continue;
			}

			$goto_domain		= idn_to_ascii(substr(strstr($goto, '@'), 1));
			$goto_local_part	= strstr($goto, '@', true);
			$goto				= $goto_local_part.'@'.$goto_domain;

			if (!filter_var($goto, FILTER_VALIDATE_EMAIL) === true) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['goto_invalid'])
				);
				return false;
			}
			if ($goto == $address) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['alias_goto_identical'])
				);
				return false;
			}
		}

		$gotos = array_filter($gotos);
		$goto = implode(",", $gotos);

		try {
			$stmt = $pdo->prepare("INSERT INTO `alias` (`address`, `goto`, `domain`, `created`, `modified`, `active`)
				VALUES (:address, :goto, :domain, :created, :modified, :active)");

			if (!filter_var($address, FILTER_VALIDATE_EMAIL) === true) {
				$stmt->execute(array(
					':address' => '@'.$domain,
					':goto' => $goto,
					':domain' => $domain,
					':created' => date('Y-m-d H:i:s'),
					':modified' => date('Y-m-d H:i:s'),
					':active' => $active
				));
			}
			else {
				$stmt->execute(array(
					':address' => $address,
					':goto' => $goto,
					':domain' => $domain,
					':created' => date('Y-m-d H:i:s'),
					':modified' => date('Y-m-d H:i:s'),
					':active' => $active
				));
			}
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['alias_added'])
			);
		}
		catch (PDOException $e) {
      mailbox_delete_alias(array('address' => $address));
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['alias_added'])
	);
}
function mailbox_add_alias_domain($postarray) {
  // Array elements
  // active         int
  // alias_domain   string
  // target_domain  string
	global $lang;
	global $pdo;
	isset($postarray['active']) ? $active = '1' : $active = '0';
	$alias_domain     = idn_to_ascii(strtolower(trim($postarray['alias_domain'])));
	$target_domain    = idn_to_ascii(strtolower(trim($postarray['target_domain'])));

	if (!is_valid_domain_name($alias_domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['alias_domain_invalid'])
		);
		return false;
	}

	if (!is_valid_domain_name($target_domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['target_domain_invalid'])
		);
		return false;
	}

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $target_domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	if ($alias_domain == $target_domain) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['aliasd_targetd_identical'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `domain`
			WHERE `domain`= :target_domain");
		$stmt->execute(array(':target_domain' => $target_domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results == 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['targetd_not_found'])
      );
      return false;
    }

		$stmt = $pdo->prepare("SELECT `alias_domain` FROM `alias_domain` WHERE `alias_domain`= :alias_domain
			UNION
			SELECT `alias_domain` FROM `alias_domain` WHERE `alias_domain`= :alias_domain_in_domain");
		$stmt->execute(array(':alias_domain' => $alias_domain, ':alias_domain_in_domain' => $alias_domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['aliasd_exists'])
      );
      return false;
    }
  }
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("INSERT INTO `alias_domain` (`alias_domain`, `target_domain`, `created`, `modified`, `active`)
			VALUES (:alias_domain, :target_domain, :created, :modified, :active)");
		$stmt->execute(array(
			':alias_domain' => $alias_domain,
			':target_domain' => $target_domain,
			':created' => date('Y-m-d H:i:s'),
			':modified' => date('Y-m-d H:i:s'),
			':active' => $active
		));
		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => sprintf($lang['success']['aliasd_added'], htmlspecialchars($alias_domain))
		);
	}
	catch (PDOException $e) {
    mailbox_delete_alias_domain(array('alias_domain' => $alias_domain));
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
}
function mailbox_add_mailbox($postarray) {
  // Array elements
  // active             int
  // local_part         string
  // domain             string
  // name               string    (username if empty)
  // password           string
  // password2          string
  // quota              int       (MiB)
  // active             int

	global $pdo;
	global $lang;
	$local_part   = strtolower(trim($postarray['local_part']));
	$domain       = idn_to_ascii(strtolower(trim($postarray['domain'])));
  $username     = $local_part . '@' . $domain;
	if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_invalid'])
		);
		return false;
	}
	if (empty($postarray['local_part'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_invalid'])
		);
		return false;
	}
	$password     = $postarray['password'];
	$password2    = $postarray['password2'];
	$name         = $postarray['name'];
  $quota_m			= filter_var($postarray['quota'], FILTER_SANITIZE_NUMBER_FLOAT);

	if (empty($name)) {
		$name = $local_part;
	}

	isset($postarray['active']) ? $active = '1' : $active = '0';

	$quota_b		= ($quota_m * 1048576);
	$maildir		= $domain."/".$local_part."/";

	if (!is_valid_domain_name($domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("SELECT `mailboxes`, `maxquota`, `quota` FROM `domain`
			WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$DomainData = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT 
			COUNT(*) as count,
			COALESCE(ROUND(SUM(`quota`)/1048576), 0) as `quota`
				FROM `mailbox`
					WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$MailboxData = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT `local_part` FROM `mailbox` WHERE `local_part` = :local_part and `domain`= :domain");
		$stmt->execute(array(':local_part' => $local_part, ':domain' => $domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['object_exists'], htmlspecialchars($username))
      );
      return false;
    }

		$stmt = $pdo->prepare("SELECT `address` FROM `alias` WHERE address= :username");
		$stmt->execute(array(':username' => $username));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['is_alias'], htmlspecialchars($username))
      );
      return false;
    }

		$stmt = $pdo->prepare("SELECT `address` FROM `spamalias` WHERE `address`= :username");
		$stmt->execute(array(':username' => $username));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['is_spam_alias'], htmlspecialchars($username))
      );
      return false;
    }

		$stmt = $pdo->prepare("SELECT `domain` FROM `domain` WHERE `domain`= :domain");
		$stmt->execute(array(':domain' => $domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results == 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['domain_not_found'], $domain)
      );
      return false;
    }
  }
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	if (!is_numeric($quota_m) || $quota_m == "0") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['quota_not_0_not_numeric'])
		);
		return false;
	}

	if (!empty($password) && !empty($password2)) {
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
	}
	else {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['password_empty'])
		);
		return false;
	}

	if ($MailboxData['count'] >= $DomainData['mailboxes']) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['max_mailbox_exceeded'], $MailboxData['count'], $DomainData['mailboxes'])
		);
		return false;
	}

	if ($quota_m > $DomainData['maxquota']) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_quota_exceeded'], $DomainData['maxquota'])
		);
		return false;
	}

	if (($MailboxData['quota'] + $quota_m) > $DomainData['quota']) {
		$quota_left_m = ($DomainData['quota'] - $MailboxData['quota']);
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_quota_left_exceeded'], $quota_left_m)
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("INSERT INTO `mailbox` (`username`, `password`, `name`, `maildir`, `quota`, `local_part`, `domain`, `created`, `modified`, `active`) 
			VALUES (:username, :password_hashed, :name, :maildir, :quota_b, :local_part, :domain, :created, :modified, :active)");
		$stmt->execute(array(
			':username' => $username,
			':password_hashed' => $password_hashed,
			':name' => $name,
			':maildir' => $maildir,
			':quota_b' => $quota_b,
			':local_part' => $local_part,
			':domain' => $domain,
			':created' => date('Y-m-d H:i:s'),
			':modified' => date('Y-m-d H:i:s'),
			':active' => $active
		));

		$stmt = $pdo->prepare("INSERT INTO `quota2` (`username`, `bytes`, `messages`)
			VALUES (:username, '0', '0')");
		$stmt->execute(array(':username' => $username));

		$stmt = $pdo->prepare("INSERT INTO `alias` (`address`, `goto`, `domain`, `created`, `modified`, `active`)
			VALUES (:username1, :username2, :domain, :created, :modified, :active)");
		$stmt->execute(array(
			':username1' => $username,
			':username2' => $username,
			':domain' => $domain,
			':created' => date('Y-m-d H:i:s'),
			':modified' => date('Y-m-d H:i:s'),
			':active' => $active
		));

		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => sprintf($lang['success']['mailbox_added'], htmlspecialchars($username))
		);
	}
	catch (PDOException $e) {
    mailbox_delete_mailbox(array('address' => $username));
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
}
function mailbox_edit_alias_domain($postarray) {
  // Array elements
  // active             int
  // alias_domain_now   string
  // alias_domain       string
	global $lang;
	global $pdo;
	isset($postarray['active']) ? $active = '1' : $active = '0';
	$alias_domain       = idn_to_ascii(strtolower(trim($postarray['alias_domain'])));
	$alias_domain_now   = strtolower(trim($postarray['alias_domain_now']));
	if (!is_valid_domain_name($alias_domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['alias_domain_invalid'])
		);
		return false;
	}

	if (!is_valid_domain_name($alias_domain_now)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['alias_domain_invalid'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("SELECT `target_domain` FROM `alias_domain`
				WHERE `alias_domain`= :alias_domain_now");
		$stmt->execute(array(':alias_domain_now' => $alias_domain_now));
		$DomainData = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $DomainData['target_domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("SELECT `target_domain` FROM `alias_domain`
		WHERE `target_domain`= :alias_domain");
		$stmt->execute(array(':alias_domain' => $alias_domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	if ($num_results != 0) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['aliasd_targetd_identical'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("UPDATE `alias_domain` SET `alias_domain` = :alias_domain, `active` = :active WHERE `alias_domain` = :alias_domain_now");
		$stmt->execute(array(
			':alias_domain' => $alias_domain,
			':alias_domain_now' => $alias_domain_now,
			':active' => $active
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['aliasd_modified'], htmlspecialchars($alias_domain))
	);
}
function mailbox_edit_alias($postarray) {
  // Array elements
  // address            string
  // goto               string    (separated by " ", "," ";" "\n") - email address or domain
  // active             int
	global $lang;
	global $pdo;
	$address      = $postarray['address'];
	$domain       = idn_to_ascii(substr(strstr($address, '@'), 1));
	$local_part   = strstr($address, '@', true);
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (empty($postarray['goto'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['goto_empty'])
		);
		return false;
	}
	$gotos = array_map('trim', preg_split( "/( |,|;|\n)/", $postarray['goto']));
	foreach ($gotos as &$goto) {
		if (empty($goto)) {
			continue;
		}
		if (!filter_var($goto, FILTER_VALIDATE_EMAIL)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' =>sprintf($lang['danger']['goto_invalid'])
			);
			return false;
		}
		if ($goto == $address) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['alias_goto_identical'])
			);
			return false;
		}
	}
	$gotos = array_filter($gotos);
	$goto = implode(",", $gotos);
	isset($postarray['active']) ? $active = '1' : $active = '0';
	if ((!filter_var($address, FILTER_VALIDATE_EMAIL) === true) && !empty($local_part)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['alias_invalid'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("UPDATE `alias` SET `goto` = :goto, `active`= :active WHERE `address` = :address");
		$stmt->execute(array(
			':goto' => $goto,
			':active' => $active,
			':address' => $address
		));
		$_SESSION['return'] = array(
			'type' => 'success',
		'msg' => sprintf($lang['success']['alias_modified'], htmlspecialchars($address))
		);
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
}
function mailbox_edit_domain($postarray) {
  // Array elements
  // domain                 string
  // description            string
  // active                 int
  // relay_all_recipients   int
  // backupmx               int
  // aliases                float
  // mailboxes              float
  // maxquota               float
  // quota                  float     (Byte)
  // active                 int

	global $lang;
	global $pdo;
  
  $domain       = idn_to_ascii($postarray['domain']);
	if (!is_valid_domain_name($domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}

	if ($_SESSION['mailcow_cc_role'] == "domainadmin" && 	hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
    $description  = $postarray['description'];
    isset($postarray['active']) ? $active = '1' : $active = '0';
    try {
      $stmt = $pdo->prepare("UPDATE `domain` SET 
      `modified`= :modified,
      `description` = :description
        WHERE `domain` = :domain");
      $stmt->execute(array(
        ':modified' => date('Y-m-d H:i:s'),
        ':description' => $description,
        ':domain' => $domain
      ));
      $_SESSION['return'] = array(
        'type' => 'success',
        'msg' => sprintf($lang['success']['domain_modified'], htmlspecialchars($domain))
      );
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
  }
  elseif ($_SESSION['mailcow_cc_role'] == "admin") {
    $description  = $postarray['description'];
    isset($postarray['active']) ? $active = '1' : $active = '0';
    $aliases		= filter_var($postarray['aliases'], FILTER_SANITIZE_NUMBER_FLOAT);
    $mailboxes  = filter_var($postarray['mailboxes'], FILTER_SANITIZE_NUMBER_FLOAT);
    $maxquota		= filter_var($postarray['maxquota'], FILTER_SANITIZE_NUMBER_FLOAT);
    $quota			= filter_var($postarray['quota'], FILTER_SANITIZE_NUMBER_FLOAT);
    isset($postarray['relay_all_recipients']) ? $relay_all_recipients = '1' : $relay_all_recipients = '0';
    isset($postarray['backupmx']) ? $backupmx = '1' : $backupmx = '0';
    isset($postarray['relay_all_recipients']) ? $backupmx = '1' : true;
    try {
      // GET MAILBOX DATA
      $stmt = $pdo->prepare("SELECT 
          COUNT(*) AS count,
          MAX(COALESCE(ROUND(`quota`/1048576), 0)) AS `maxquota`,
          COALESCE(ROUND(SUM(`quota`)/1048576), 0) AS `quota`
            FROM `mailbox`
              WHERE domain= :domain");
      $stmt->execute(array(':domain' => $domain));
      $MailboxData = $stmt->fetch(PDO::FETCH_ASSOC);
      // GET ALIAS DATA
      $stmt = $pdo->prepare("SELECT COUNT(*) AS `count` FROM `alias`
          WHERE domain = :domain
          AND address NOT IN (
            SELECT `username` FROM `mailbox`
          )");
      $stmt->execute(array(':domain' => $domain));
      $AliasData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }

    if ($maxquota > $quota) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['mailbox_quota_exceeds_domain_quota'])
      );
      return false;
    }

    if ($MailboxData['maxquota'] > $maxquota) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['max_quota_in_use'], $MailboxData['maxquota'])
      );
      return false;
    }

    if ($MailboxData['quota'] > $quota) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['domain_quota_m_in_use'], $MailboxData['quota'])
      );
      return false;
    }

    if ($MailboxData['count'] > $mailboxes) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['mailboxes_in_use'], $MailboxData['count'])
      );
      return false;
    }

    if ($AliasData['count'] > $aliases) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['aliases_in_use'], $AliasData['count'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("UPDATE `domain` SET 
      `modified`= :modified,
      `relay_all_recipients` = :relay_all_recipients,
      `backupmx` = :backupmx,
      `active` = :active,
      `quota` = :quota,
      `maxquota` = :maxquota,
      `mailboxes` = :mailboxes,
      `aliases` = :aliases,
      `description` = :description
        WHERE `domain` = :domain");
      $stmt->execute(array(
        ':relay_all_recipients' => $relay_all_recipients,
        ':backupmx' => $backupmx,
        ':active' => $active,
        ':quota' => $quota,
        ':maxquota' => $maxquota,
        ':mailboxes' => $mailboxes,
        ':aliases' => $aliases,
        ':modified' => date('Y-m-d H:i:s'),
        ':description' => $description,
        ':domain' => $domain
      ));
      $_SESSION['return'] = array(
        'type' => 'success',
        'msg' => sprintf($lang['success']['domain_modified'], htmlspecialchars($domain))
      );
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
  }
}
function mailbox_edit_mailbox($postarray) {
	global $lang;
	global $pdo;
	isset($postarray['active']) ? $active = '1' : $active = '0';
	if (!filter_var($postarray['username'], FILTER_VALIDATE_EMAIL)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	$quota_m      = $postarray['quota'];
	$quota_b      = $quota_m*1048576;
	$username     = $postarray['username'];
	$name         = $postarray['name'];
	$password     = $postarray['password'];
	$password2    = $postarray['password2'];

	try {
		$stmt = $pdo->prepare("SELECT `domain`
			FROM `mailbox`
				WHERE username = :username");
		$stmt->execute(array(':username' => $username));
		$MailboxData1 = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT 
			COALESCE(ROUND(SUM(`quota`)/1048576), 0) as `quota_m_now`
				FROM `mailbox`
					WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$MailboxData2 = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT 
			COALESCE(ROUND(SUM(`quota`)/1048576), 0) as `quota_m_in_use`
				FROM `mailbox`
					WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $MailboxData1['domain']));
		$MailboxData3 = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT `quota`, `maxquota`
			FROM `domain`
				WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $MailboxData1['domain']));
		$DomainData = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $MailboxData1['domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (!is_numeric($quota_m) || $quota_m == "0") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['quota_not_0_not_numeric'], htmlspecialchars($quota_m))
		);
		return false;
	}
	if ($quota_m > $DomainData['maxquota']) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_quota_exceeded'], $DomainData['maxquota'])
		);
		return false;
	}
	if (($MailboxData3['quota_m_in_use'] - $MailboxData2['quota_m_now'] + $quota_m) > $DomainData['quota']) {
		$quota_left_m = ($DomainData['quota'] - $MailboxData3['quota_m_in_use'] + $MailboxData2['quota_m_now']);
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['mailbox_quota_left_exceeded'], $quota_left_m)
		);
		return false;
	}

  // Get sender_acl items set by admin
  $sender_acl_admin = array_merge(
    get_sender_acl_handles($username)['sender_acl_domains']['ro'],
    get_sender_acl_handles($username)['sender_acl_addresses']['ro']
  );

  // Get sender_acl items from POST array
  (isset($postarray['sender_acl'])) ? $sender_acl_domain_admin = $postarray['sender_acl'] : $sender_acl_domain_admin = array();

	if (!empty($sender_acl_domain_admin) || !empty($sender_acl_admin)) {
    // Check items in POST array
		foreach ($sender_acl_domain_admin as $sender_acl) {
			if (!filter_var($sender_acl, FILTER_VALIDATE_EMAIL) && !is_valid_domain_name(ltrim($sender_acl, '@'))) {
					$_SESSION['return'] = array(
						'type' => 'danger',
						'msg' => sprintf($lang['danger']['sender_acl_invalid'])
					);
					return false;
			}
      if (is_valid_domain_name(ltrim($sender_acl, '@'))) {
        if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], ltrim($sender_acl, '@'))) {
					$_SESSION['return'] = array(
						'type' => 'danger',
						'msg' => sprintf($lang['danger']['sender_acl_invalid'])
					);
					return false;
        }
      }
			if (filter_var($sender_acl, FILTER_VALIDATE_EMAIL)) {
        if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $sender_acl)) {
					$_SESSION['return'] = array(
						'type' => 'danger',
						'msg' => sprintf($lang['danger']['sender_acl_invalid'])
					);
					return false;
        }
      }
    }

    // Merge both arrays
    $sender_acl_merged = array_merge($sender_acl_domain_admin, $sender_acl_admin);

    try {
      $stmt = $pdo->prepare("DELETE FROM `sender_acl` WHERE `logged_in_as` = :username");
      $stmt->execute(array(
        ':username' => $username
      ));
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }

		foreach ($sender_acl_merged as $sender_acl) {
      $domain = ltrim($sender_acl, '@');
      if (is_valid_domain_name($domain)) {
        $sender_acl = '@' . $domain;
      }
			try {
				$stmt = $pdo->prepare("INSERT INTO `sender_acl` (`send_as`, `logged_in_as`)
					VALUES (:sender_acl, :username)");
				$stmt->execute(array(
					':sender_acl' => $sender_acl,
					':username' => $username
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
		}
	}
  else {
    try {
      $stmt = $pdo->prepare("DELETE FROM `sender_acl` WHERE `logged_in_as` = :username");
      $stmt->execute(array(
        ':username' => $username
      ));
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
  }
	if (!empty($password) && !empty($password2)) {
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
		try {
			$stmt = $pdo->prepare("UPDATE `alias` SET
					`modified` = :modified,
					`active` = :active
						WHERE `address` = :address");
			$stmt->execute(array(
				':address' => $username,
				':modified' => date('Y-m-d H:i:s'),
				':active' => $active
			));
			$stmt = $pdo->prepare("UPDATE `mailbox` SET
					`modified` = :modified,
					`active` = :active,
					`password` = :password_hashed,
					`name`= :name,
					`quota` = :quota_b
						WHERE `username` = :username");
			$stmt->execute(array(
				':modified' => date('Y-m-d H:i:s'),
				':password_hashed' => $password_hashed,
				':active' => $active,
				':name' => $name,
				':quota_b' => $quota_b,
				':username' => $username
			));
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['mailbox_modified'], $username)
			);
			return true;
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	try {
		$stmt = $pdo->prepare("UPDATE `alias` SET
				`modified` = :modified,
				`active` = :active
					WHERE `address` = :address");
		$stmt->execute(array(
			':address' => $username,
			':modified' => date('Y-m-d H:i:s'),
			':active' => $active
		));
		$stmt = $pdo->prepare("UPDATE `mailbox` SET
				`modified` = :modified,
				`active` = :active,
				`name`= :name,
				`quota` = :quota_b
					WHERE `username` = :username");
		$stmt->execute(array(
			':active' => $active,
			':modified' => date('Y-m-d H:i:s'),
			':name' => $name,
			':quota_b' => $quota_b,
			':username' => $username
		));
		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => sprintf($lang['success']['mailbox_modified'], $username)
		);
		return true;
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
}
function mailbox_get_mailboxes($domain) {
	global $lang;
	global $pdo;
  $mailboxes = array();
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  try {
    $stmt = $pdo->prepare("SELECT `username` FROM `mailbox` WHERE `domain` != 'ALL' AND `domain` = :domain");
    $stmt->execute(array(
      ':domain' => $domain,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $mailboxes[] = $row['username'];
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $mailboxes;
}
function mailbox_get_alias_domains($domain) {
	global $lang;
	global $pdo;
  $aliasdomains = array();
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

  try {
    $stmt = $pdo->prepare("SELECT `alias_domain` FROM `alias_domain` WHERE `target_domain` = :domain");
    $stmt->execute(array(
      ':domain' => $domain,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $aliasdomains[] = $row['alias_domain'];
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $aliasdomains;
}
function mailbox_get_aliases($domain) {
	global $lang;
	global $pdo;
  $aliases = array();
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

  try {
    $stmt = $pdo->prepare("SELECT `address` FROM `alias` WHERE `address` != `goto` AND `domain` = :domain");
    $stmt->execute(array(
      ':domain' => $domain,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $aliases[] = $row['address'];
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $aliases;
}
function mailbox_get_alias_details($address) {
	global $lang;
	global $pdo;
  $aliasdata = array();
  try {
    $stmt = $pdo->prepare("SELECT
      `domain`,
      `goto`,
      `address`,
      `active` as `active_int`,
      CASE `active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`,
      `created`,
      `modified`
        FROM `alias`
            WHERE `address` = :address AND `address` != `goto`");
    $stmt->execute(array(
      ':address' => $address,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $aliasdata['domain'] = $row['domain'];
      $aliasdata['goto'] = $row['goto'];
      $aliasdata['address'] = $row['address'];
      (!filter_var($aliasdata['address'], FILTER_VALIDATE_EMAIL)) ? $aliasdata['is_catch_all'] = 1 : $aliasdata['is_catch_all'] = 0;
      $aliasdata['active'] = $row['active'];
      $aliasdata['active_int'] = $row['active_int'];
      $aliasdata['created'] = $row['created'];
      $aliasdata['modified'] = $row['modified'];
      if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $aliasdata['domain'])) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $aliasdata;
}
function mailbox_get_alias_domain_details($aliasdomain) {
	global $lang;
	global $pdo;
  $aliasdomaindata = array();
  try {
    $stmt = $pdo->prepare("SELECT
      `alias_domain`,
      `target_domain`,
      `active` AS `active_int`,
      CASE `active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`,
      `created`,
      `modified`
        FROM `alias_domain`
            WHERE `alias_domain` = :aliasdomain");
    $stmt->execute(array(
      ':aliasdomain' => $aliasdomain,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $aliasdomaindata['alias_domain'] = $row['alias_domain'];
      $aliasdomaindata['target_domain'] = $row['target_domain'];
      $aliasdomaindata['active'] = $row['active'];
      $aliasdomaindata['active_int'] = $row['active_int'];
      $aliasdomaindata['created'] = $row['created'];
      $aliasdomaindata['modified'] = $row['modified'];
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  if (isset($aliasdomaindata['target_domain']) && !hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $aliasdomaindata['target_domain'])) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  return $aliasdomaindata;
}
function mailbox_get_domains() {
	global $lang;
	global $pdo;

  try {
    $domains = array();
    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE (`domain` IN (
        SELECT `domain` from `domain_admins`
          WHERE (`active`='1' AND `username` = :username))
        )
        OR ('admin'= :role)
        AND `domain` != 'ALL'");
    $stmt->execute(array(
      ':username' => $_SESSION['mailcow_cc_username'],
      ':role' => $_SESSION['mailcow_cc_role'],
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domains[] = $row['domain'];
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $domains;
}
function mailbox_get_domain_details($domain) {
	global $lang;
	global $pdo;

  $domaindata = array();
	$domain = idn_to_ascii(strtolower(trim($domain)));

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

  try {
    $stmt = $pdo->prepare("SELECT 
        `domain`,
        `description`,
        `aliases`,
        `mailboxes`, 
        `maxquota`,
        `quota`,
        `relay_all_recipients` as `relay_all_recipients_int`,
        `backupmx` as `backupmx_int`,
        `active` as `active_int`,
        CASE `relay_all_recipients` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `relay_all_recipients`,
        CASE `backupmx` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `backupmx`,
        CASE `active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`
          FROM `domain` WHERE `domain`= :domain");
    $stmt->execute(array(
      ':domain' => $domain,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domaindata['domain_name'] = $row['domain'];
      $domaindata['description'] = $row['description'];
      $domaindata['max_num_aliases_for_domain'] = $row['aliases'];
      $domaindata['max_num_mboxes_for_domain'] = $row['mailboxes'];
      $domaindata['max_quota_for_mbox'] = formatBytes(intval($row['maxquota'] * 1048576), 2);
      $domaindata['max_quota_for_mbox_mib'] = $row['maxquota'];
      $domaindata['max_quota_for_domain'] = formatBytes(intval($row['quota'] * 1048576), 2);
      $domaindata['max_quota_for_domain_mib'] = $row['quota'];
      $domaindata['backupmx'] = $row['backupmx'];
      $domaindata['backupmx_int'] = $row['backupmx_int'];
      $domaindata['active'] = $row['active'];
      $domaindata['active_int'] = $row['active_int'];
      $domaindata['relay_all_recipients'] = $row['relay_all_recipients'];
      $domaindata['relay_all_recipients_int'] = $row['relay_all_recipients_int'];
      $domaindata['relay_all_recipients'] = $row['relay_all_recipients'];

      $stmt = $pdo->prepare("SELECT COUNT(*) AS `alias_count` FROM `alias`
        WHERE `domain`= :domain
          AND `address` NOT IN (
            SELECT `username` FROM `mailbox`
          )");
      $stmt->execute(array(
        ':domain' => $domain,
      ));
      $row = $stmt->fetchAll();
      (isset($row[0]['alias_count'])) ? $domaindata['aliases_in_domain'] = $row[0]['alias_count'] : null;
      $stmt = $pdo->prepare("SELECT COUNT(`username`) AS `mailbox_count`, SUM(`quota`) AS `quota` FROM `mailbox`
          WHERE `domain` = :domain");
      $stmt->execute(array(
        ':domain' => $domain,
      ));
      $row = $stmt->fetchAll();
      $domaindata['mboxes_in_domain'] = $row[0]['mailbox_count'];
      $domaindata['quota_used_in_domain'] = formatBytes(intval($row[0]['quota']), 2);
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }

  return $domaindata;
}
function mailbox_get_mailbox_details($mailbox) {
	global $lang;
	global $pdo;
  $mailboxdata = array();
  try {
    $stmt = $pdo->prepare("SELECT
        `domain`.`backupmx`,
        `mailbox`.`username`,
        `mailbox`.`name`,
        `mailbox`.`active` AS `active_int`,
        CASE `mailbox`.`active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`,
        `mailbox`.`domain`,
        `mailbox`.`quota`,
        `quota2`.`bytes`,
        `quota2`.`messages`
          FROM `mailbox`, `quota2`, `domain`
            WHERE `mailbox`.`username` = `quota2`.`username` AND `domain`.`domain` = `mailbox`.`domain` AND `mailbox`.`username` = :mailbox");
    $stmt->execute(array(
      ':mailbox' => $mailbox,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $mailboxdata['username'] = $row['username'];
      $mailboxdata['is_relayed'] = $row['backupmx'];
      $mailboxdata['name'] = $row['name'];
      $mailboxdata['active'] = $row['active'];
      $mailboxdata['active_int'] = $row['active_int'];
      $mailboxdata['domain'] = $row['domain'];
      $mailboxdata['quota'] = formatBytes(intval($row['quota']), 2);
      $mailboxdata['quota_bytes'] = $row['quota'];
      $mailboxdata['quota_used'] = formatBytes(intval($row['bytes']), 2);
      $mailboxdata['percent_in_use'] = round((intval($row['bytes']) / intval($row['quota'])) * 100);
      $mailboxdata['messages'] = $row['messages'];
      if ($mailboxdata['percent_in_use'] >= 90) {
        $mailboxdata['percent_class'] = "danger";
      }
      elseif ($mailboxdata['percent_in_use'] >= 75) {
        $mailboxdata['percent_class'] = "warning";
      }
      else {
        $mailboxdata['percent_class'] = "success";
      }
    }
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  if (isset($mailboxdata['domain']) && !hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $mailboxdata['domain'])) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  return $mailboxdata;
}
function mailbox_delete_domain($postarray) {
	global $lang;
	global $pdo;
	$domain = $postarray['domain'];
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (!is_valid_domain_name($domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}
	$domain	= strtolower(trim($domain));


	try {
		$stmt = $pdo->prepare("SELECT `username` FROM `mailbox`
			WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	if ($num_results != 0 || !empty($num_results)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_not_empty'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("DELETE FROM `domain` WHERE `domain` = :domain");
		$stmt->execute(array(
			':domain' => $domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `domain_admins` WHERE `domain` = :domain");
		$stmt->execute(array(
			':domain' => $domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `alias` WHERE `domain` = :domain");
		$stmt->execute(array(
			':domain' => $domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `alias_domain` WHERE `target_domain` = :domain");
		$stmt->execute(array(
			':domain' => $domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `mailbox` WHERE `domain` = :domain");
		$stmt->execute(array(
			':domain' => $domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `sender_acl` WHERE `logged_in_as` LIKE :domain");
		$stmt->execute(array(
			':domain' => '%@'.$domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `quota2` WHERE `username` = :domain");
		$stmt->execute(array(
			':domain' => '%@'.$domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `spamalias` WHERE `address` = :domain");
		$stmt->execute(array(
			':domain' => '%@'.$domain,
		));
		$stmt = $pdo->prepare("DELETE FROM `filterconf` WHERE `object` = :domain");
		$stmt->execute(array(
			':domain' => '%@'.$domain,
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_removed'], htmlspecialchars($domain))
	);
	return true;
}
function mailbox_delete_alias($postarray) {
	global $lang;
	global $pdo;
	$address		= $postarray['address'];
	$local_part		= strstr($address, '@', true);
	$domain			= substr(strrchr($address, "@"), 1);
	try {
		$stmt = $pdo->prepare("SELECT `goto` FROM `alias` WHERE `address` = :address");
		$stmt->execute(array(':address' => $address));
		$gotos = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$goto_array = explode(',', $gotos['goto']);

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("DELETE FROM `alias` WHERE `address` = :address AND `address` NOT IN (SELECT `username` FROM `mailbox`)");
		$stmt->execute(array(
			':address' => $address
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['alias_removed'], htmlspecialchars($address))
	);

}
function mailbox_delete_alias_domain($postarray) {
	global $lang;
	global $pdo;
	if (!is_valid_domain_name($postarray['alias_domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}
	$alias_domain = $postarray['alias_domain'];
	try {
		$stmt = $pdo->prepare("SELECT `target_domain` FROM `alias_domain`
				WHERE `alias_domain`= :alias_domain");
		$stmt->execute(array(':alias_domain' => $alias_domain));
		$DomainData = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $DomainData['target_domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("DELETE FROM `alias_domain` WHERE `alias_domain` = :alias_domain");
		$stmt->execute(array(
			':alias_domain' => $alias_domain,
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['alias_domain_removed'], htmlspecialchars($alias_domain))
	);
}
function mailbox_delete_mailbox($postarray) {
	global $lang;
	global $pdo;
	$domain		= substr(strrchr($postarray['username'], "@"), 1);
	$username	= $postarray['username'];
	if (!filter_var($postarray['username'], FILTER_VALIDATE_EMAIL)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("DELETE FROM `alias` WHERE `goto` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `quota2` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `mailbox` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `sender_acl` WHERE `logged_in_as` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `spamalias` WHERE `goto` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `imapsync` WHERE `user2` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("DELETE FROM `filterconf` WHERE `object` = :username");
		$stmt->execute(array(
			':username' => $username
		));
		$stmt = $pdo->prepare("SELECT `address`, `goto` FROM `alias`
				WHERE `goto` LIKE :username");
		$stmt->execute(array(':username' => '%'.$username.'%'));
		$GotoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($GotoData as $gotos) {
			$goto_exploded = explode(',', $gotos['goto']);
			if (($key = array_search($username, $goto_exploded)) !== false) {
				unset($goto_exploded[$key]);
			}
			$gotos_rebuild = implode(',', $goto_exploded);
			$stmt = $pdo->prepare("UPDATE `alias` SET `goto` = :goto WHERE `address` = :address");
			$stmt->execute(array(
				':goto' => $gotos_rebuild,
				':address' => $gotos['address']
			));
		}
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['mailbox_removed'], htmlspecialchars($username))
	);
}
function edit_domain_admin($postarray) {
	global $lang;
	global $pdo;
	$username     = $postarray['username'];
	$password     = $postarray['password'];
	$password2    = $postarray['password2'];
	isset($postarray['active']) ? $active = '1' : $active = '0';

	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	
  if(isset($postarray['domain'])) {
    foreach ($postarray['domain'] as $domain) {
      if (!is_valid_domain_name($domain)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['domain_invalid'])
        );
        return false;
      }
    }
	}

	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("DELETE FROM `domain_admins` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username,
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

  if(isset($postarray['domain'])) {
    foreach ($postarray['domain'] as $domain) {
      try {
        $stmt = $pdo->prepare("INSERT INTO `domain_admins` (`username`, `domain`, `created`, `active`)
          VALUES (:username, :domain, :created, :active)");
        $stmt->execute(array(
          ':username' => $username,
          ':domain' => $domain,
          ':created' => date('Y-m-d H:i:s'),
          ':active' => $active
        ));
      }
      catch (PDOException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'MySQL: '.$e
        );
        return false;
      }
    }
	}

	if (!empty($password) && !empty($password2)) {
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET `modified` = :modified, `active` = :active, `password` = :password_hashed WHERE `username` = :username");
			$stmt->execute(array(
				':password_hashed' => $password_hashed,
				':username' => $username,
				':modified' => date('Y-m-d H:i:s'),
				':active' => $active
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	else {
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET `modified` = :modified, `active` = :active WHERE `username` = :username");
			$stmt->execute(array(
				':username' => $username,
				':modified' => date('Y-m-d H:i:s'),
				':active' => $active
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_admin_modified'], htmlspecialchars($username))
	);
}
function set_admin_account($postarray) {
	global $lang;
	global $pdo;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$name		= $postarray['admin_user'];
	$name_now	= $postarray['admin_user_now'];

	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $name)) || empty ($name)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $name_now)) || empty ($name_now)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	if (!empty($postarray['admin_pass']) && !empty($postarray['admin_pass2'])) {
		if ($postarray['admin_pass'] != $postarray['admin_pass2']) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($postarray['admin_pass']);
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET 
				`modified` = :modified,
				`password` = :password_hashed,
				`username` = :name
					WHERE `username` = :username");
			$stmt->execute(array(
				':password_hashed' => $password_hashed,
				':modified' => date('Y-m-d H:i:s'),
				':name' => $name,
				':username' => $name_now
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	else {
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET 
				`modified` = :modified,
				`username` = :name
					WHERE `username` = :name_now");
			$stmt->execute(array(
				':name' => $name,
				':modified' => date('Y-m-d H:i:s'),
				':name_now' => $name_now
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	try {
		$stmt = $pdo->prepare("UPDATE `domain_admins` SET 
			`domain` = :domain,
			`username` = :name
				WHERE `username` = :name_now");
		$stmt->execute(array(
			':domain' => 'ALL',
			':name' => $name,
			':name_now' => $name_now
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['admin_modified'])
	);
}
function set_time_limited_aliases($postarray) {
	global $lang;
	global $pdo;
  (isset($postarray['username'])) ? $username = $postarray['username'] : $username = $_SESSION['mailcow_cc_username'];

  if ($_SESSION['mailcow_cc_role'] != "user" &&
    $_SESSION['mailcow_cc_role'] != "admin") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
  }
  if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $username)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
  }

	try {
    $stmt = $pdo->prepare("SELECT `domain` FROM `mailbox` WHERE `username` = :username");
    $stmt->execute(array(':username' => $username));
    $domain = $stmt->fetch(PDO::FETCH_ASSOC)['domain'];
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
	if ($_SESSION['mailcow_cc_role'] != "user" || empty($username) || empty($domain)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['access_denied'])
				);
				return false;
	}
	switch ($postarray["trigger_set_time_limited_aliases"]) {
		case "generate":
			if (!is_numeric($postarray["validity"]) || $postarray["validity"] > 672) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['validity_missing'])
				);
				return false;
			}
			$validity = strtotime("+".$postarray["validity"]." hour"); 
			$letters = 'abcefghijklmnopqrstuvwxyz1234567890';
			$random_name = substr(str_shuffle($letters), 0, 24);
			try {
				$stmt = $pdo->prepare("INSERT INTO `spamalias` (`address`, `goto`, `validity`) VALUES
					(:address, :goto, :validity)");
				$stmt->execute(array(
					':address' => $random_name . '@' . $domain,
					':goto' => $username,
					':validity' => $validity
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
			);
		break;
		case "deleteall":
			try {
				$stmt = $pdo->prepare("DELETE FROM `spamalias` WHERE `goto` = :username");
				$stmt->execute(array(
					':username' => $username
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}	
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
			);
		break;
		case "delete":
			if (empty($postarray['item']) || !filter_var($postarray['item'], FILTER_VALIDATE_EMAIL)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['access_denied'])
				);
				return false;
			}
      $item	= $postarray['item'];
			try {
				$stmt = $pdo->prepare("DELETE FROM `spamalias` WHERE `goto` = :username AND `address` = :item");
				$stmt->execute(array(
					':username' => $username,
					':item' => $item
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}	
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
			);
		break;
		case "extend":
			try {
				$stmt = $pdo->prepare("UPDATE `spamalias` SET `validity` = (`validity` + 3600)
					WHERE `goto` = :username 
						AND `validity` >= :validity");
				$stmt->execute(array(
					':username' => $username,
					':validity' => time(),
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
			);
		break;
	}
}
function get_time_limited_aliases($username = null) {
  // 'username' can be be set, if not, default to mailcow_cc_username
  global $lang;
	global $pdo;
  $spamaliasdata = array();
	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "admin") {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
	}
  if (isset($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $username)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
  try {
    $stmt = $pdo->prepare("SELECT `address`,
      `goto`,
      `validity`
        FROM `spamalias`
          WHERE `goto` = :username
            AND `validity` >= :unixnow");
    $stmt->execute(array(':username' => $username, ':unixnow' => time()));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $rows;
}
function set_user_account($postarray) {
	global $lang;
	global $pdo;
	$username			= $_SESSION['mailcow_cc_username'];
	$password_old		= $postarray['user_old_pass'];
	isset($postarray['togglePwNew']) ? $pwnew_active = '1' : $pwnew_active = '0';

	if (isset($pwnew_active) && $pwnew_active == "1") {
		$password_new	= $postarray['user_new_pass'];
		$password_new2	= $postarray['user_new_pass2'];
	}

	if (!check_login($username, $password_old) == "user") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "domainadmin") {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
	}

	if (isset($password_new) && isset($password_new2)) {
		if (!empty($password_new2) && !empty($password_new)) {
			if ($password_new2 != $password_new) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['password_mismatch'])
				);
				return false;
			}
			if (strlen($password_new) < "6" ||
				!preg_match('/[A-Za-z]/', $password_new) ||
				!preg_match('/[0-9]/', $password_new)) {
					$_SESSION['return'] = array(
						'type' => 'danger',
						'msg' => sprintf($lang['danger']['password_complexity'])
					);
					return false;
			}
			$password_hashed = hash_password($password_new);
			try {
				$stmt = $pdo->prepare("UPDATE `mailbox` SET `modified` = :modified, `password` = :password_hashed WHERE `username` = :username");
				$stmt->execute(array(
					':password_hashed' => $password_hashed,
					':modified' => date('Y-m-d H:i:s'),
					':username' => $username
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
		}
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['mailbox_modified'], $username)
	);
}
function add_domain_admin($postarray) {
	global $lang;
	global $pdo;
	$username		= strtolower(trim($postarray['username']));
	$password		= $postarray['password'];
	$password2		= $postarray['password2'];
	isset($postarray['active']) ? $active = '1' : $active = '0';
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (empty($postarray['domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}
	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username)) || empty ($username)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `username` FROM `mailbox`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		
		$stmt = $pdo->prepare("SELECT `username` FROM `admin`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		
		$stmt = $pdo->prepare("SELECT `username` FROM `domain_admins`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	foreach ($num_results as $num_results_each) {
		if ($num_results_each != 0) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['object_exists'], htmlspecialchars($username))
			);
			return false;
		}
	}
	if (!empty($password) && !empty($password2)) {
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
		foreach ($postarray['domain'] as $domain) {
			if (!is_valid_domain_name($domain)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['domain_invalid'])
				);
				return false;
			}
			try {
				$stmt = $pdo->prepare("INSERT INTO `domain_admins` (`username`, `domain`, `created`, `active`)
						VALUES (:username, :domain, :created, :active)");
				$stmt->execute(array(
					':username' => $username,
					':domain' => $domain,
					':created' => date('Y-m-d H:i:s'),
					':active' => $active
				));
			}
			catch (PDOException $e) {
        delete_domain_admin(array('username' => $username));
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `admin` (`username`, `password`, `superadmin`, `created`, `modified`, `active`)
				VALUES (:username, :password_hashed, '0', :created, :modified, :active)");
			$stmt->execute(array(
				':username' => $username,
				':password_hashed' => $password_hashed,
				':created' => date('Y-m-d H:i:s'),
				':modified' => date('Y-m-d H:i:s'),
				':active' => $active
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	else {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['password_empty'])
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_admin_added'], htmlspecialchars($username))
	);
}
function delete_domain_admin($postarray) {
	global $pdo;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$username = $postarray['username'];
	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("DELETE FROM `domain_admins` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username,
		));
		$stmt = $pdo->prepare("DELETE FROM `admin` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username,
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_admin_removed'], htmlspecialchars($username))
	);
}
function get_domain_admins() {
	global $pdo;
	global $lang;
  $domainadmins = array();
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  try {
    $stmt = $pdo->query("SELECT DISTINCT
      `username`
        FROM `domain_admins` 
          WHERE `username` IN (
            SELECT `username` FROM `admin`
              WHERE `superadmin`!='1'
          )");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($rows)) {
      $domainadmins[] = $row['username'];
    }
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $domainadmins;
}
function get_domain_admin_details($domain_admin) {
	global $pdo;
	global $lang;
  $domainadmindata = array();
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $domain_admin))) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
  try {
    $stmt = $pdo->prepare("SELECT
      `created`,
      `active` AS `active_int`,
      CASE `active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`
        FROM `domain_admins`
          WHERE `username`= :domain_admin");
    $stmt->execute(array(
      ':domain_admin' => $domain_admin
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $domainadmindata['active'] = $row['active'];
    $domainadmindata['active_int'] = $row['active_int'];
    $domainadmindata['created'] = $row['created'];
    // GET SELECTED
    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE `domain` IN (
        SELECT `domain` FROM `domain_admins`
          WHERE `username`= :domain_admin)");
    $stmt->execute(array(':domain_admin' => $domain_admin));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domainadmindata['selected_domains'][] = $row['domain'];
    }
    // GET UNSELECTED
    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE `domain` NOT IN (
        SELECT `domain` FROM `domain_admins`
          WHERE `username`= :domain_admin)");
    $stmt->execute(array(':domain_admin' => $domain_admin));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domainadmindata['unselected_domains'][] = $row['domain'];
    }
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $domainadmindata;
}
function get_spam_score($username = null) {
	global $pdo;
	$default = "5, 15";
	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "admin") {
			return false;
	}
  if (isset($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $username)) {
      return false;
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
	try {
		$stmt = $pdo->prepare("SELECT `value` FROM `filterconf` WHERE `object` = :username AND
			(`option` = 'lowspamlevel' OR `option` = 'highspamlevel')");
		$stmt->execute(array(':username' => $username));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	if (empty($num_results)) {
		return $default;
	}
	else {
		try {
			$stmt = $pdo->prepare("SELECT `value` FROM `filterconf` WHERE `option` = 'highspamlevel' AND `object` = :username");
			$stmt->execute(array(':username' => $username));
			$highspamlevel = $stmt->fetch(PDO::FETCH_ASSOC);

			$stmt = $pdo->prepare("SELECT `value` FROM `filterconf` WHERE `option` = 'lowspamlevel' AND `object` = :username");
			$stmt->execute(array(':username' => $username));
			$lowspamlevel = $stmt->fetch(PDO::FETCH_ASSOC);

			return $lowspamlevel['value'].', '.$highspamlevel['value'];
		}
		catch(PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
}
function set_spam_score($postarray) {
	global $lang;
	global $pdo;
	if ($_SESSION['mailcow_cc_role'] != "user") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$username		= $_SESSION['mailcow_cc_username'];
	$lowspamlevel	= explode(',', $postarray['score'])[0];
	$highspamlevel	= explode(',', $postarray['score'])[1];
	if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	if (!is_numeric($lowspamlevel) || !is_numeric($highspamlevel)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("DELETE FROM `filterconf` WHERE `object` = :username
			AND (`option` = 'lowspamlevel' OR `option` = 'highspamlevel')");
		$stmt->execute(array(
			':username' => $username
		));

		$stmt = $pdo->prepare("INSERT INTO `filterconf` (`object`, `option`, `value`)
			VALUES (:username, 'highspamlevel', :highspamlevel)");
		$stmt->execute(array(
			':username' => $username,
			':highspamlevel' => $highspamlevel
		));

		$stmt = $pdo->prepare("INSERT INTO `filterconf` (`object`, `option`, `value`)
			VALUES (:username, 'lowspamlevel', :lowspamlevel)");
		$stmt->execute(array(
			':username' => $username,
			':lowspamlevel' => $lowspamlevel
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['mailbox_modified'], $username)
	);
}
function get_policy_list($object = null) {
  // 'object' can be be set, if not, default to mailcow_cc_username
	global $lang;
	global $pdo;
	if (!isset($_SESSION['mailcow_cc_role'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  if (isset($object)) {
    if (!filter_var($object, FILTER_VALIDATE_EMAIL) && is_valid_domain_name($object)) {
      $object = idn_to_ascii(strtolower(trim($object)));
      if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $object)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
    }
    elseif (filter_var($object, FILTER_VALIDATE_EMAIL)) {
      if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $object)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
    }
  }
  else {
     $object = $_SESSION['mailcow_cc_username'];
  }
  try {
    // WHITELIST
    $stmt = $pdo->prepare("SELECT `object`, `value`, `prefid` FROM `filterconf` WHERE `option`='whitelist_from' AND (`object` = :username OR `object` = SUBSTRING_INDEX(:username_domain, '@' ,-1))");
    $stmt->execute(array(':username' => $object, ':username_domain' => $object));
    $rows['whitelist'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // BLACKLIST
    $stmt = $pdo->prepare("SELECT `object`, `value`, `prefid` FROM `filterconf` WHERE `option`='blacklist_from' AND (`object` = :username OR `object` = SUBSTRING_INDEX(:username_domain, '@' ,-1))");
    $stmt->execute(array(':username' => $object, ':username_domain' => $object));
    $rows['blacklist'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $rows;
}
function set_policy_list($postarray) {
  // Array data
  // Either 'domain' or 'username' can be be set
  // If none of the above is set, default to mailcow_cc_username
  //
  // If 'delete_prefid' then delete item id
	global $lang;
	global $pdo;
  (isset($postarray['username'])) ? $object = $postarray['username'] : null;
  (isset($postarray['domain']))   ? $object = $postarray['domain'] : null;
  (!isset($object))               ? $object = $_SESSION['mailcow_cc_username'] : null;

  // Can be user, domainadmin or admin
	if (!isset($_SESSION['mailcow_cc_role'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}

  if (is_valid_domain_name($object)) {
		if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $object)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
		}
    $object = idn_to_ascii(strtolower(trim($object)));
  }
  else {
		if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $object)) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
		}
  }

  // DELETE
	if (isset($postarray['delete_prefid'])) {
		if (!is_numeric($postarray['delete_prefid'])) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
		}
		try {
			$stmt = $pdo->prepare("DELETE FROM `filterconf` WHERE `object` = :object AND `prefid` = :prefid");
			$stmt->execute(array(
				':object' => $object,
				':prefid' => $postarray['delete_prefid']
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
		$_SESSION['return'] = array(
			'type' => 'success',
			'msg' => sprintf($lang['success']['object_modified'], $object)
		);
		return true;
	}
  
  // ADD WL/BL
	($postarray['object_list'] == "bl") ? $object_list = "blacklist_from" : null;
	($postarray['object_list'] == "wl") ? $object_list = "whitelist_from" : null;
	$object_from = preg_replace('/\.+/', '.', rtrim(preg_replace("/\.\*/", "*", trim(strtolower($postarray['object_from']))), '.'));
  if (!ctype_alnum(str_replace(array('@', '.', '-', '*'), '', $object_from))) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['policy_list_from_invalid'])
		);
		return false;
	}
	if ($object_list != "blacklist_from" && $object_list != "whitelist_from") {
    $_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `object` FROM `filterconf`
			WHERE (`option` = 'whitelist_from'  OR `option` = 'blacklist_from')
				AND `object` = :object
				AND `value` = :object_from");
		$stmt->execute(array(':object' => $object, ':object_from' => $object_from));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['policy_list_from_exists'])
      );
      return false;
    }
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}

	try {
		$stmt = $pdo->prepare("INSERT INTO `filterconf` (`object`, `option` ,`value`)
			VALUES (:object, :object_list, :object_from)");
		$stmt->execute(array(
			':object' => $object,
			':object_list' => $object_list,
			':object_from' => $object_from
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['object_modified'], $object)
	);
}
function get_syncjobs($username = null) {
  // 'username' can be be set, if not, default to mailcow_cc_username
	global $lang;
	global $pdo;
  $syncjobs = array();
	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "admin") {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
	}
  if (isset($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $username)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
  try {
    $stmt = $pdo->prepare("SELECT *, CONCAT(LEFT(`password1`, 3), '…') as `password1_short`
        FROM `imapsync`
          WHERE `user2` = :username");
    $stmt->execute(array(':username' => $username));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $rows;
}
function get_syncjob_details($id) {
	global $lang;
	global $pdo;
  $syncjobdetails = array();
	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "admin") {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
	}
  if (!isset($id) || !is_numeric($id)) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  try {
    $stmt = $pdo->prepare("SELECT * FROM `imapsync` WHERE (`user2` = :username OR 'admin' = :role) AND id = :id");
    $stmt->execute(array(':id' => $id, ':role' => $_SESSION['mailcow_cc_role'], ':username' => $_SESSION['mailcow_cc_username']));
    $syncjobdetails = $stmt->fetch(PDO::FETCH_ASSOC);
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $syncjobdetails;
}
function set_syncjob($postarray, $action) {
	global $lang;
	global $pdo;
  if (isset($postarray['username']) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $postarray['username'])) {
      return false;
    }
    else {
      $username = $postarray['username'];
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
  if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  if ($_SESSION['mailcow_cc_role'] != "user" && $_SESSION['mailcow_cc_role'] != "admin") {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  // DELETE
  if ($action == "delete") {
    $id = $postarray['id'];
    if (!is_numeric($id)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("DELETE FROM `imapsync` WHERE `user2` = :username AND `id`= :id");
      $stmt->execute(array(
        ':username' => $username,
        ':id' => $id,
      ));
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
    );
    return true;
  }
  // ADD
  elseif ($action == "add") {
    isset($postarray['active']) ? $active = '1' : $active = '0';
    isset($postarray['delete2duplicates']) ? $delete2duplicates = '1' : $delete2duplicates = '0';
    $port1            = $postarray['port1'];
    $host1            = $postarray['host1'];
    $password1        = $postarray['password1'];
    $exclude          = $postarray['exclude'];
    $maxage           = $postarray['maxage'];
    $subfolder2       = $postarray['subfolder2'];
    $user1            = $postarray['user1'];
    $mins_interval    = $postarray['mins_interval'];
    $enc1             = $postarray['enc1'];

    if (empty($subfolder2)) {
      $subfolder2 = "";
    }
    if (!isset($maxage) || !filter_var($maxage, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 32767)))) {
      $maxage = "0";
    }
    if (!filter_var($port1, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (!filter_var($mins_interval, FILTER_VALIDATE_INT, array('options' => array('min_range' => 10, 'max_range' => 3600)))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (!is_valid_domain_name($host1)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if ($enc1 != "TLS" && $enc1 != "SSL" && $enc1 != "PLAIN") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (@preg_match("/" . $exclude . "/", null) === false) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("SELECT `user2`, `user1` FROM `imapsync`
        WHERE `user2` = :user2 AND `user1` = :user1");
      $stmt->execute(array(':user1' => $user1, ':user2' => $username));
      $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    if ($num_results != 0) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['object_exists'], htmlspecialchars($host1 . ' / ' . $user1))
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("INSERT INTO `imapsync` (`user2`, `exclude`, `maxage`, `subfolder2`, `host1`, `authmech1`, `user1`, `password1`, `mins_interval`, `port1`, `enc1`, `delete2duplicates`, `active`)
        VALUES (:user2, :exclude, :maxage, :subfolder2, :host1, :authmech1, :user1, :password1, :mins_interval, :port1, :enc1, :delete2duplicates, :active)");
      $stmt->execute(array(
        ':user2' => $username,
        ':exclude' => $exclude,
        ':maxage' => $maxage,
        ':subfolder2' => $subfolder2,
        ':host1' => $host1,
        ':authmech1' => 'PLAIN',
        ':user1' => $user1,
        ':password1' => $password1,
        ':mins_interval' => $mins_interval,
        ':port1' => $port1,
        ':enc1' => $enc1,
        ':delete2duplicates' => $delete2duplicates,
        ':active' => $active,
      ));
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['mailbox_modified'], $username)
    );
    return true;
  }
  // EDIT
  elseif ($action == "edit") {
    isset($postarray['active']) ? $active = '1' : $active = '0';
    isset($postarray['delete2duplicates']) ? $delete2duplicates = '1' : $delete2duplicates = '0';
    $id               = $postarray['id'];
    $port1            = $postarray['port1'];
    $host1            = $postarray['host1'];
    $password1        = $postarray['password1'];
    $exclude          = $postarray['exclude'];
    $maxage           = $postarray['maxage'];
    $subfolder2       = $postarray['subfolder2'];
    $user1            = $postarray['user1'];
    $mins_interval    = $postarray['mins_interval'];
    $enc1             = $postarray['enc1'];

    if (empty($subfolder2)) {
      $subfolder2 = "";
    }
    if (empty($maxage)) {
      $maxage = 0;
    }

    if (!filter_var($maxage, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 32767)))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (!filter_var($port1, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (!filter_var($mins_interval, FILTER_VALIDATE_INT, array('options' => array('min_range' => 10, 'max_range' => 3600)))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (!is_valid_domain_name($host1)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if ($enc1 != "TLS" && $enc1 != "SSL" && $enc1 != "PLAIN") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    if (@preg_match("/" . $exclude . "/", null) === false) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("SELECT `user2` FROM `imapsync`
        WHERE `user2` = :user2 AND `id` = :id");
      $stmt->execute(array(':user2' => $username, ':id' => $id));
      $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    if (empty($num_results)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("UPDATE `imapsync` set `maxage` = :maxage, `subfolder2` = :subfolder2, `exclude` = :exclude, `host1` = :host1, `user1` = :user1, `password1` = :password1, `mins_interval` = :mins_interval, `port1` = :port1, `enc1` = :enc1, `delete2duplicates` = :delete2duplicates, `active` = :active
        WHERE `user2` = :user2 AND `id` = :id");
      $stmt->execute(array(
        ':user2' => $username,
        ':id' => $id,
        ':exclude' => $exclude,
        ':maxage' => $maxage,
        ':subfolder2' => $subfolder2,
        ':host1' => $host1,
        ':user1' => $user1,
        ':password1' => $password1,
        ':mins_interval' => $mins_interval,
        ':port1' => $port1,
        ':enc1' => $enc1,
        ':delete2duplicates' => $delete2duplicates,
        ':active' => $active,
      ));
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['mailbox_modified'], $username)
    );
    return true;
  }
}
function set_tls_policy($postarray) {
	global $lang;
	global $pdo;
	if ($_SESSION['mailcow_cc_role'] != "user") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	isset($postarray['tls_in']) ? $tls_in = '1' : $tls_in = '0';
	isset($postarray['tls_out']) ? $tls_out = '1' : $tls_out = '0';
	$username = $_SESSION['mailcow_cc_username'];
	if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("UPDATE `mailbox` SET `tls_enforce_out` = :tls_out, `tls_enforce_in` = :tls_in WHERE `username` = :username");
		$stmt->execute(array(
			':tls_out' => $tls_out,
			':tls_in' => $tls_in,
			':username' => $username
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['mailbox_modified'], $username)
	);
}
function get_tls_policy($username = null) {
	global $lang;
	global $pdo;
  $TLSData = array();
	if ($_SESSION['mailcow_cc_role'] != "user" &&
		$_SESSION['mailcow_cc_role'] != "admin") {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['access_denied'])
			);
			return false;
	}
  if (isset($username) && filter_var($username, FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $username)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
	try {
		$stmt = $pdo->prepare("SELECT `tls_enforce_out`, `tls_enforce_in` FROM `mailbox` WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$TLSData = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	return $TLSData;
}
function remaining_specs($domain, $object = null, $js = null) {
	// left_m	without object given	= MiB left in domain
	// left_m	with object given		= Max. MiB we can assign to given object
	// limit_m							= Domain limit in MiB
	// left_c							= Mailboxes we can create depending on domain quota
	global $pdo;
	if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `mailboxes`, `maxquota`, `quota` FROM `domain` WHERE `domain` = :domain");
		$stmt->execute(array(':domain' => $domain));
		$DomainData			= $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt = $pdo->prepare("SELECT COUNT(*) AS `count`, COALESCE(ROUND(SUM(`quota`)/1048576), 0) as `in_use_m` FROM `mailbox` WHERE `domain` = :domain AND `username` != :object");
		$stmt->execute(array(':domain' => $domain, ':object' => $object));
		$MailboxDataDomain	= $stmt->fetch(PDO::FETCH_ASSOC);

		$quota_left_m	= $DomainData['quota']		- $MailboxDataDomain['in_use_m'];
		$mboxs_left		= $DomainData['mailboxes']	- $MailboxDataDomain['count'];

		if ($quota_left_m > $DomainData['maxquota']) {
			$quota_left_m = $DomainData['maxquota'];
		}

	}
	catch (PDOException $e) {
		return false;
	}
	if (is_numeric($quota_left_m)) {
		$spec['left_m']		= $quota_left_m;
		$spec['limit_m']	= $DomainData['maxquota'];
	}
	if (is_numeric($mboxs_left)) {
		$spec['left_c']		= $mboxs_left;
	}
	if (!empty($js)) {
		echo $quota_left_m;
		exit;
	}
	return $spec;
}
function get_sender_acl_handles($mailbox) {
	global $pdo;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin" && $_SESSION['mailcow_cc_role'] != "domainadmin") {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
	}

  $data['sender_acl_domains']['ro']               = array();
  $data['sender_acl_domains']['rw']               = array();
  $data['sender_acl_domains']['selectable']       = array();
  $data['sender_acl_addresses']['ro']             = array();
  $data['sender_acl_addresses']['rw']             = array();
  $data['sender_acl_addresses']['selectable']     = array();
  $data['fixed_sender_aliases']                   = array();
  
  try {
    $stmt = $pdo->prepare("SELECT `address` FROM `alias` WHERE `goto` = :goto AND `address` NOT LIKE '@%'");
    $stmt->execute(array(':goto' => $mailbox));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($rows)) {
      $data['fixed_sender_aliases'][] = $row['address'];
    }

    // Return array $data['sender_acl_domains/addresses']['ro'] with read-only objects
    // Return array $data['sender_acl_domains/addresses']['rw'] with read-write objects (can be deleted)
    $stmt = $pdo->prepare("SELECT REPLACE(`send_as`, '@', '') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :logged_in_as AND `send_as` LIKE '@%'");
    $stmt->execute(array(':logged_in_as' => $mailbox));
    $domain_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($domain_row = array_shift($domain_rows)) {
      if (is_valid_domain_name($domain_row['send_as']) && !hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain_row['send_as'])) {
        $data['sender_acl_domains']['ro'][] = $domain_row['send_as'];
        continue;
      }
      if (is_valid_domain_name($domain_row['send_as']) && hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain_row['send_as'])) {
        $data['sender_acl_domains']['rw'][] = $domain_row['send_as'];
        continue;
      }
    }

    $stmt = $pdo->prepare("SELECT `send_as` FROM `sender_acl` WHERE `logged_in_as` = :logged_in_as AND `send_as` NOT LIKE '@%'");
    $stmt->execute(array(':logged_in_as' => $mailbox));
    $address_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($address_row = array_shift($address_rows)) {
      if (filter_var($address_row['send_as'], FILTER_VALIDATE_EMAIL) && !hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $address_row['send_as'])) {
        $data['sender_acl_addresses']['ro'][] = $address_row['send_as'];
        continue;
      }
      if (filter_var($address_row['send_as'], FILTER_VALIDATE_EMAIL) && hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $address_row['send_as'])) {
        $data['sender_acl_addresses']['rw'][] = $address_row['send_as'];
        continue;
      }
    }

    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE `domain` NOT IN (
        SELECT REPLACE(`send_as`, '@', '') FROM `sender_acl` 
          WHERE `logged_in_as` = :logged_in_as
            AND `send_as` LIKE '@%')");
    $stmt->execute(array(
      ':logged_in_as' => $mailbox,
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($rows)) {
      if (is_valid_domain_name($row['domain']) && hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $row['domain'])) {
        $data['sender_acl_domains']['selectable'][] = $row['domain'];
      }
    }

    $stmt = $pdo->prepare("SELECT `address` FROM `alias`
      WHERE `goto` != :goto
        AND `address` NOT IN (
          SELECT `send_as` FROM `sender_acl` 
            WHERE `logged_in_as` = :logged_in_as
              AND `send_as` NOT LIKE '@%')");
    $stmt->execute(array(
      ':logged_in_as' => $mailbox,
      ':goto' => $mailbox
    ));
    while ($row = array_shift($rows)) {
      if (filter_var($row['address'], FILTER_VALIDATE_EMAIL) && hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $row['address'])) {
        $data['sender_acl_addresses']['selectable'][] = $row['address'];
      }
    }
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
  return $data;
}
function tagging_options($action, $data = null) {
	global $lang;
	global $pdo;
  $username	= $_SESSION['mailcow_cc_username'];
  if ($action == "get") {
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
      return false;
    }
    try {
      $stmt = $pdo->prepare("SELECT `wants_tagged_subject` FROM `mailbox` WHERE `username` = :username");
      $stmt->execute(array(':username' => $username));
      $SelectData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    return $SelectData['wants_tagged_subject'];
  }
  elseif ($action == "set") {
    ($data['tagged_mail_handler'] == "subject") ? $wants_tagged_subject = '1' : $wants_tagged_subject = '0';
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['username_invalid'])
      );
      return false;
    }
    try {
      $stmt = $pdo->prepare("UPDATE `mailbox` SET `wants_tagged_subject` = :wants_tagged_subject WHERE `username` = :username");
      $stmt->execute(array(':username' => $username, ':wants_tagged_subject' => $wants_tagged_subject));
      $SelectData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    catch(PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['mailbox_modified'], $username)
    );
  }
  return false;
}
function user_get_alias_details($username) {
	global $lang;
	global $pdo;
  if ($_SESSION['mailcow_cc_role'] == "user") {
    $username	= $_SESSION['mailcow_cc_username'];
  }
  if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    return false;
  }
  try {
    $data['address'] = $username;
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`address` SEPARATOR ', '), '&#10008;') AS `aliases` FROM `alias` WHERE `goto` = :username_goto AND `address` NOT LIKE '@%' AND `address` != :username_address");
    $stmt->execute(array(':username_goto' => $username, ':username_address' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases'] = $row['aliases'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(local_part, '@', alias_domain SEPARATOR ', '), '&#10008;') AS `ad_alias` FROM `mailbox`
      LEFT OUTER JOIN `alias_domain` on `target_domain` = `domain`
        WHERE `username` = :username ;");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['ad_alias'] = $row['ad_alias'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`send_as` SEPARATOR ', '), '&#10008;') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :username AND `send_as` NOT LIKE '@%';");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases_also_send_as'] = $row['send_as'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`send_as` SEPARATOR ', '), '&#10008;') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :username AND `send_as` LIKE '@%';");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases_send_as_all'] = $row['send_as'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`address` SEPARATOR ', '), '&#10008;') as `address` FROM `alias` WHERE `goto` = :username AND `address` LIKE '@%';");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['is_catch_all'] = $row['address'];
    }
    return $data;
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
}
function is_valid_domain_name($domain_name) { 
	if (empty($domain_name)) {
		return false;
	}
	$domain_name = idn_to_ascii($domain_name);
	return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain_name)
		   && preg_match("/^.{1,253}$/", $domain_name)
		   && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name));
}
?>
