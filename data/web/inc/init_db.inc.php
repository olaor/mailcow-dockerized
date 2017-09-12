<?php
function init_db_schema() {
  try {
    global $pdo;

    $db_version = "12092017_2254";

    $stmt = $pdo->query("SHOW TABLES LIKE 'versions'"); 
    $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($num_results != 0) {
      $stmt = $pdo->query("SELECT `version` FROM `versions`");
      if ($stmt->fetch(PDO::FETCH_ASSOC)['version'] == $db_version) {
        return true;
      }
    }

    $views = array(
    "grouped_mail_aliases" => "CREATE VIEW grouped_mail_aliases (username, aliases) AS
      SELECT goto, IFNULL(GROUP_CONCAT(address SEPARATOR ' '), '') AS address FROM alias
      WHERE address!=goto
      AND active = '1'
      AND address NOT LIKE '@%'
      GROUP BY goto;",
    "grouped_sender_acl" => "CREATE VIEW grouped_sender_acl (username, send_as) AS
      SELECT logged_in_as, IFNULL(GROUP_CONCAT(send_as SEPARATOR ' '), '') AS send_as FROM sender_acl
      WHERE send_as NOT LIKE '@%'
      GROUP BY logged_in_as;",
    "grouped_domain_alias_address" => "CREATE VIEW grouped_domain_alias_address (username, ad_alias) AS
      SELECT username, IFNULL(GROUP_CONCAT(local_part, '@', alias_domain SEPARATOR ' '), '') AS ad_alias FROM mailbox
      LEFT OUTER JOIN alias_domain on target_domain=domain GROUP BY username;"
    );

    $tables = array(
      "versions" => array(
        "cols" => array(
          "application" => "VARCHAR(255) NOT NULL",
          "version" => "VARCHAR(100) NOT NULL",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
        ),
        "keys" => array(
          "primary" => array(
            "" => array("application")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "admin" => array(
        "cols" => array(
          "username" => "VARCHAR(255) NOT NULL",
          "password" => "VARCHAR(255) NOT NULL",
          "superadmin" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE NOW(0)",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("username")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "relayhosts" => array(
        "cols" => array(
          "id" => "INT NOT NULL AUTO_INCREMENT",
          "hostname" => "VARCHAR(255) NOT NULL",
          "username" => "VARCHAR(255) NOT NULL",
          "password" => "VARCHAR(255) NOT NULL",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("id")
          ),
          "key" => array(
            "hostname" => array("hostname")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "alias" => array(
        "cols" => array(
          "address" => "VARCHAR(255) NOT NULL",
          "goto" => "TEXT NOT NULL",
          "domain" => "VARCHAR(255) NOT NULL",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("address")
          ),
          "key" => array(
            "domain" => array("domain")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sender_acl" => array(
        "cols" => array(
          "logged_in_as" => "VARCHAR(255) NOT NULL",
          "send_as" => "VARCHAR(255) NOT NULL"
        ),
        "keys" => array(),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "domain" => array(
        "cols" => array(
          "domain" => "VARCHAR(255) NOT NULL",
          "description" => "VARCHAR(255)",
          "aliases" => "INT(10) NOT NULL DEFAULT '0'",
          "mailboxes" => "INT(10) NOT NULL DEFAULT '0'",
          "maxquota" => "BIGINT(20) NOT NULL DEFAULT '0'",
          "quota" => "BIGINT(20) NOT NULL DEFAULT '102400'",
          "relayhost" => "VARCHAR(255) NOT NULL DEFAULT '0'",
          "backupmx" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "relay_all_recipients" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("domain")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "mailbox" => array(
        "cols" => array(
          "username" => "VARCHAR(255) NOT NULL",
          "password" => "VARCHAR(255) NOT NULL",
          "name" => "VARCHAR(255)",
          "maildir" => "VARCHAR(255) NOT NULL",
          "quota" => "BIGINT(20) NOT NULL DEFAULT '102400'",
          "local_part" => "VARCHAR(255) NOT NULL",
          "domain" => "VARCHAR(255) NOT NULL",
          "tls_enforce_in" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "tls_enforce_out" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "kind" => "VARCHAR(100) NOT NULL DEFAULT ''",
          "multiple_bookings" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "wants_tagged_subject" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("username")
          ),
          "key" => array(
            "domain" => array("domain")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "user_acl" => array(
        "cols" => array(
          "username" => "VARCHAR(255) NOT NULL",
          "spam_alias" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "tls_policy" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "spam_score" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "spam_policy" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "delimiter_action" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "syncjobs" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "eas_reset" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "eas_autoconfig" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "fkey" => array(
            "fk_username" => array(
              "col" => "username",
              "ref" => "mailbox.username",
              "delete" => "CASCADE",
              "update" => "NO ACTION"
            )
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "alias_domain" => array(
        "cols" => array(
          "alias_domain" => "VARCHAR(255) NOT NULL",
          "target_domain" => "VARCHAR(255) NOT NULL",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("alias_domain")
          ),
          "key" => array(
            "active" => array("active"),
            "target_domain" => array("target_domain")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "spamalias" => array(
        "cols" => array(
          "address" => "VARCHAR(255) NOT NULL",
          "goto" => "TEXT NOT NULL",
          "validity" => "INT(11) NOT NULL"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("address")
          ),
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "filterconf" => array(
        "cols" => array(
          "object" => "VARCHAR(255) NOT NULL DEFAULT ''",
          "option" => "VARCHAR(50) NOT NULL DEFAULT ''",
          "value" => "VARCHAR(100) NOT NULL DEFAULT ''",
          "prefid" => "INT(11) NOT NULL AUTO_INCREMENT"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("prefid")
          ),
          "key" => array(
            "object" => array("object")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "quota2" => array(
        "cols" => array(
          "username" => "VARCHAR(255) NOT NULL",
          "bytes" => "BIGINT(20) NOT NULL DEFAULT '0'",
          "messages" => "BIGINT(20) NOT NULL DEFAULT '0'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("username")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "domain_admins" => array(
        "cols" => array(
          "username" => "VARCHAR(255) NOT NULL",
          "domain" => "VARCHAR(255) NOT NULL",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "active" => "TINYINT(1) NOT NULL DEFAULT '1'"
        ),
        "keys" => array(
          "key" => array(
            "username" => array("username")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "imapsync" => array(
        "cols" => array(
          "id" => "INT NOT NULL AUTO_INCREMENT",
          "user2" => "VARCHAR(255) NOT NULL",
          "host1" => "VARCHAR(255) NOT NULL",
          "authmech1" => "ENUM('PLAIN','LOGIN','CRAM-MD5') DEFAULT 'PLAIN'",
          "regextrans2" => "VARCHAR(255) DEFAULT ''",
          "authmd51" => "TINYINT(1) NOT NULL DEFAULT 0",
          "domain2" => "VARCHAR(255) NOT NULL DEFAULT ''",
          "subfolder2" => "VARCHAR(255) NOT NULL DEFAULT ''",
          "user1" => "VARCHAR(255) NOT NULL",
          "password1" => "VARCHAR(255) NOT NULL",
          "exclude" => "VARCHAR(500) NOT NULL DEFAULT ''",
          "maxage" => "SMALLINT NOT NULL DEFAULT '0'",
          "mins_interval" => "VARCHAR(50) NOT NULL",
          "port1" => "SMALLINT NOT NULL",
          "enc1" => "ENUM('TLS','SSL','PLAIN') DEFAULT 'TLS'",
          "delete2duplicates" => "TINYINT(1) NOT NULL DEFAULT '1'",
          "delete1" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "delete2" => "TINYINT(1) NOT NULL DEFAULT '0'",
          "returned_text" => "TEXT",
          "last_run" => "TIMESTAMP NULL DEFAULT NULL",
          "created" => "DATETIME(0) NOT NULL DEFAULT NOW(0)",
          "modified" => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
          "active" => "TINYINT(1) NOT NULL DEFAULT '0'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("id")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "tfa" => array(
        "cols" => array(
          "id" => "INT NOT NULL AUTO_INCREMENT",
          "key_id" => "VARCHAR(255) NOT NULL",
          "username" => "VARCHAR(255) NOT NULL",
          "authmech" => "ENUM('yubi_otp', 'u2f', 'hotp', 'totp')",
          "secret" => "VARCHAR(255) DEFAULT NULL",
          "keyHandle" => "VARCHAR(255) DEFAULT NULL",
          "publicKey" => "VARCHAR(255) DEFAULT NULL",
          "counter" => "INT NOT NULL DEFAULT '0'",
          "certificate" => "TEXT",
          "active" => "TINYINT(1) NOT NULL DEFAULT '0'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("id")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "forwarding_hosts" => array(
        "cols" => array(
          "host" => "VARCHAR(255) NOT NULL",
          "source" => "VARCHAR(255) NOT NULL",
          "filter_spam" => "TINYINT(1) NOT NULL DEFAULT '0'"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("host")
          ),
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_acl" => array(
        "cols" => array(
          "c_folder_id" => "INT NOT NULL",
          "c_object" => "VARCHAR(255) NOT NULL",
          "c_uid" => "VARCHAR(255) NOT NULL",
          "c_role" => "VARCHAR(80) NOT NULL"
        ),
        "keys" => array(
          "key" => array(
            "sogo_acl_c_folder_id_idx" => array("c_folder_id"),
            "sogo_acl_c_uid_idx" => array("c_uid")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_alarms_folder" => array(
        "cols" => array(
          "c_path" => "VARCHAR(255) NOT NULL",
          "c_name" => "VARCHAR(255) NOT NULL",
          "c_uid" => "VARCHAR(255) NOT NULL",
          "c_recurrence_id" => "INT(11) DEFAULT NULL",
          "c_alarm_number" => "INT(11) NOT NULL",
          "c_alarm_date" => "INT(11) NOT NULL"
        ),
        "keys" => array(),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_cache_folder" => array(
        "cols" => array(
          "c_uid" => "VARCHAR(255) NOT NULL",
          "c_path" => "VARCHAR(255) NOT NULL",
          "c_parent_path" => "VARCHAR(255) DEFAULT NULL",
          "c_type" => "TINYINT(3) unsigned NOT NULL",
          "c_creationdate" => "INT(11) NOT NULL",
          "c_lastmodified" => "INT(11) NOT NULL",
          "c_version" => "INT(11) NOT NULL DEFAULT '0'",
          "c_deleted" => "TINYINT(4) NOT NULL DEFAULT '0'",
          "c_content" => "LONGTEXT"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_uid", "c_path")
          ),
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_folder_info" => array(
        "cols" => array(
          "c_folder_id" => "BIGINT(20) unsigned NOT NULL AUTO_INCREMENT",
          "c_path" => "VARCHAR(255) NOT NULL",
          "c_path1" => "VARCHAR(255) NOT NULL",
          "c_path2" => "VARCHAR(255) DEFAULT NULL",
          "c_path3" => "VARCHAR(255) DEFAULT NULL",
          "c_path4" => "VARCHAR(255) DEFAULT NULL",
          "c_foldername" => "VARCHAR(255) NOT NULL",
          "c_location" => "INT NULL",
          "c_quick_location" => "VARCHAR(2048) DEFAULT NULL",
          "c_acl_location" => "VARCHAR(2048) DEFAULT NULL",
          "c_folder_type" => "VARCHAR(255) NOT NULL"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_path")
          ),
          "unique" => array(
            "c_folder_id" => array("c_folder_id")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_quick_appointment" => array(
        "cols" => array(
          "c_folder_id" => "INT NOT NULL",
          "c_name" => "VARCHAR(255) NOT NULL",
          "c_uid" => "VARCHAR(255) NOT NULL",
          "c_startdate" => "INT",
          "c_enddate" => "INT",
          "c_cycleenddate" => "INT",
          "c_title" => "VARCHAR(1000) NOT NULL",
          "c_participants" => "TEXT",
          "c_isallday" => "INT",
          "c_iscycle" => "INT",
          "c_cycleinfo" => "TEXT",
          "c_classification" => "INT NOT NULL",
          "c_isopaque" => "INT NOT NULL",
          "c_status" => "INT NOT NULL",
          "c_priority" => "INT",
          "c_location" => "VARCHAR(255)",
          "c_orgmail" => "VARCHAR(255)",
          "c_partmails" => "TEXT",
          "c_partstates" => "TEXT",
          "c_category" => "VARCHAR(255)",
          "c_sequence" => "INT",
          "c_component" => "VARCHAR(10) NOT NULL",
          "c_nextalarm" => "INT",
          "c_description" => "TEXT"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_folder_id", "c_name")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_quick_contact" => array(
        "cols" => array(
          "c_folder_id" => "INT NOT NULL",
          "c_name" => "VARCHAR(255) NOT NULL",
          "c_givenname" => "VARCHAR(255)",
          "c_cn" => "VARCHAR(255)",
          "c_sn" => "VARCHAR(255)",
          "c_screenname" => "VARCHAR(255)",
          "c_l" => "VARCHAR(255)",
          "c_mail" => "VARCHAR(255)",
          "c_o" => "VARCHAR(255)",
          "c_ou" => "VARCHAR(255)",
          "c_telephonenumber" => "VARCHAR(255)",
          "c_categories" => "VARCHAR(255)",
          "c_component" => "VARCHAR(10) NOT NULL"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_folder_id", "c_name")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_sessions_folder" => array(
        "cols" => array(
          "c_id" => "VARCHAR(255) NOT NULL",
          "c_value" => "VARCHAR(255) NOT NULL",
          "c_creationdate" => "INT(11) NOT NULL",
          "c_lastseen" => "INT(11) NOT NULL"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_id")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_store" => array(
        "cols" => array(
          "c_folder_id" => "INT NOT NULL",
          "c_name" => "VARCHAR(255) NOT NULL",
          "c_content" => "MEDIUMTEXT NOT NULL",
          "c_creationdate" => "INT NOT NULL",
          "c_lastmodified" => "INT NOT NULL",
          "c_version" => "INT NOT NULL",
          "c_deleted" => "INT"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_folder_id", "c_name")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      ),
      "sogo_user_profile" => array(
        "cols" => array(
          "c_uid" => "VARCHAR(255) NOT NULL",
          "c_defaults" => "TEXT",
          "c_settings" => "TEXT"
        ),
        "keys" => array(
          "primary" => array(
            "" => array("c_uid")
          )
        ),
        "attr" => "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
      )
    );

    foreach ($tables as $table => $properties) {
      $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'"); 
      $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
      if ($num_results != 0) {
        foreach($properties['cols'] as $column => $type) {
          $stmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'"); 
          $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
          if ($num_results == 0) {
            $pdo->query("ALTER TABLE `" . $table . "` ADD `" . $column . "` " . $type);
          }
          else {
            $pdo->query("ALTER TABLE `" . $table . "` MODIFY COLUMN `" . $column . "` " . $type);
          }
        }
        foreach($properties['keys'] as $key_type => $key_content) {
          if (strtolower($key_type) == 'primary') {
            foreach ($key_content as $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name = 'PRIMARY'"); 
              $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
              $is_drop = ($num_results != 0) ? "DROP PRIMARY KEY, " : "";
              $pdo->query("ALTER TABLE `" . $table . "` " . $is_drop . "ADD PRIMARY KEY (" . $fields . ")");
            }
          }
          if (strtolower($key_type) == 'key') {
            foreach ($key_content as $key_name => $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name = '" . $key_name . "'"); 
              $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
              $is_drop = ($num_results != 0) ? "DROP INDEX `" . $key_name . "`, " : "";
              $pdo->query("ALTER TABLE `" . $table . "` " . $is_drop . "ADD KEY `" . $key_name . "` (" . $fields . ")");
            }
          }
          if (strtolower($key_type) == 'unique') {
            foreach ($key_content as $key_name => $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name = '" . $key_name . "'"); 
              $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
              $is_drop = ($num_results != 0) ? "DROP INDEX `" . $key_name . "`, " : "";
              $pdo->query("ALTER TABLE `" . $table . "` " . $is_drop . "ADD UNIQUE KEY `" . $key_name . "` (" . $fields . ")");
            }
          }
          if (strtolower($key_type) == 'fkey') {
            foreach ($key_content as $key_name => $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name = '" . $key_name . "'"); 
              $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
              if ($num_results != 0) {
                $pdo->query("ALTER TABLE `" . $table . "` DROP FOREIGN KEY `" . $key_name . "`");
              }
              @list($table_ref, $field_ref) = explode('.', $key_values['ref']);
              $pdo->query("ALTER TABLE `" . $table . "` ADD FOREIGN KEY `" . $key_name . "` (" . $key_values['col'] . ") REFERENCES `" . $table_ref . "` (`" . $field_ref . "`)
                ON DELETE " . $key_values['delete'] . " ON UPDATE " . $key_values['update']);
            }
          }
        }
        // Drop all vanished columns
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "`"); 
        $cols_in_table = $stmt->fetchAll(PDO::FETCH_ASSOC); 
        while ($row = array_shift($cols_in_table)) {
          if (!array_key_exists($row['Field'], $properties['cols'])) {
            $pdo->query("ALTER TABLE `" . $table . "` DROP COLUMN `" . $row['Field'] . "`;");
          }
        }

        // Step 1: Get all non-primary keys, that currently exist and those that should exist
        $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE `Key_name` != 'PRIMARY'"); 
        $keys_in_table = $stmt->fetchAll(PDO::FETCH_ASSOC); 
        $keys_to_exist = array();
        if (isset($properties['keys']['unique']) && is_array($properties['keys']['unique'])) {
          foreach ($properties['keys']['unique'] as $key_name => $key_values) {
             $keys_to_exist[] = $key_name;
          }
        }
        if (isset($properties['keys']['key']) && is_array($properties['keys']['key'])) {
          foreach ($properties['keys']['key'] as $key_name => $key_values) {
             $keys_to_exist[] = $key_name;
          }
        }
        // Index for foreign key must exist
        if (isset($properties['keys']['fkey']) && is_array($properties['keys']['fkey'])) {
          foreach ($properties['keys']['fkey'] as $key_name => $key_values) {
             $keys_to_exist[] = $key_name;
          }
        }
        // Step 2: Drop all vanished indexes
        while ($row = array_shift($keys_in_table)) {
          if (!in_array($row['Key_name'], $keys_to_exist)) {
            try {
              $pdo->query("ALTER TABLE `" . $table . "` DROP FOREIGN KEY `" . $row['Key_name'] . "`");
            }
            finally {
              $pdo->query("ALTER TABLE `" . $table . "` DROP INDEX `" . $row['Key_name'] . "`");
            }
          }
        }
        // Step 3: Drop all vanished primary keys
        if (!isset($properties['keys']['primary'])) {
          $stmt = $pdo->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name = 'PRIMARY'"); 
          $num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
          if ($num_results != 0) {
            $pdo->query("ALTER TABLE `" . $table . "` DROP PRIMARY KEY");
          }
        }
      }
      else {
        // Create table if it is missing
        $sql = "CREATE TABLE IF NOT EXISTS `" . $table . "` (";
        foreach($properties['cols'] as $column => $type) {
          $sql .= $column . " " . $type . ",";
        }
        foreach($properties['keys'] as $key_type => $key_content) {
          if (strtolower($key_type) == 'primary') {
            foreach ($key_content as $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $sql .= "PRIMARY KEY (" . $fields . ")" . ",";
            }
          }
          elseif (strtolower($key_type) == 'key') {
            foreach ($key_content as $key_name => $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $sql .= "KEY `" . $key_name . "` (" . $fields . ")" . ",";
            }
          }
          elseif (strtolower($key_type) == 'unique') {
            foreach ($key_content as $key_name => $key_values) {
              $fields = "`" . implode("`, `", $key_values) . "`";
              $sql .= "UNIQUE KEY `" . $key_name . "` (" . $fields . ")" . ",";
            }
          }
          elseif (strtolower($key_type) == 'fkey') {
            foreach ($key_content as $key_name => $key_values) {
              @list($table_ref, $field_ref) = explode('.', $key_values['ref']);
              $fields = "`" . implode("`, `", $key_values) . "`";
              $sql .= "FOREIGN KEY `" . $key_name . "` (" . $key_values['col'] . ") REFERENCES `" . $table_ref . "` (`" . $field_ref . "`)
                ON DELETE " . $key_values['delete'] . " ON UPDATE " . $key_values['update'] . ",";
            }
          }
        }
        $sql = rtrim($sql, ",");
        $sql .= ") " . $properties['attr'];
        $pdo->query($sql);
      }
      // Reset table attributes
      $pdo->query("ALTER TABLE `" . $table . "` " . $properties['attr'] . ";");
    }

    // Recreate SQL views
    foreach ($views as $view => $create) {
      $pdo->query("DROP VIEW IF EXISTS `" . $view . "`;");
      $pdo->query($create);
    }

    // Inject admin if not exists
    $stmt = $pdo->query("INSERT INTO `admin` (`username`, `password`, `superadmin`, `created`, `modified`, `active`)
      SELECT 'admin', '{SSHA256}K8eVJ6YsZbQCfuJvSUbaQRLr0HPLz5rC9IAp0PAFl0tmNDBkMDc0NDAyOTAxN2Rk', 1, NOW(), NOW(), 1 FROM `admin`
        WHERE NOT EXISTS (SELECT * FROM `admin`);");
    $stmt = $pdo->query("INSERT INTO `domain_admins` (`username`, `domain`, `created`, `active`)
        SELECT `username`, 'ALL', NOW(), 1 FROM `admin`
          WHERE superadmin='1' AND `username` NOT IN (SELECT `username` FROM `domain_admins`);");
    $stmt = $pdo->query("DELETE FROM `admin` WHERE `username` NOT IN  (SELECT `username` FROM `domain_admins`);");

    // Insert new DB schema version
    $stmt = $pdo->query("REPLACE INTO `versions` (`application`, `version`) VALUES ('db_schema', '" . $db_version . "');"); 

    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => 'Database initialisation completed'
    );

    // Fix user_acl
    $stmt = $pdo->query("INSERT INTO `user_acl` (`username`) SELECT `username` FROM `mailbox` WHERE `kind` = '' AND NOT EXISTS (SELECT `username` FROM `user_acl`);");
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'Database initialisation failed: ' . $e->getMessage()
    );
  }
}
?>