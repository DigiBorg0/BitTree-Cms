<?php
	// Set version
	include "core/version.php";
	
	// Turn off errors
	ini_set("log_errors", false);
	error_reporting(0);
	
	// Allow for passing in $_POST via command line for automatic installs.
	if (isset($argv) && count($argv) > 1) {
		// Cut off the first argument.
		$vars = array_slice($argv, 1);
		
		// Loop through the variables passed in.
		foreach ($vars as $v) {
			list($key, $val) = explode("=", $v);
			$_POST[$key] = $val;
		}
	}
	
	// Issues that are game enders first.
	$fails = [];
	if (version_compare(PHP_VERSION, "5.3.0", "<")) {
		$fails[] = "PHP 5.3 or higher is required.";
	}
	
	if (!extension_loaded('json')) {
		$fails[] = "PHP does not have the JSON extension installed.";
	}
	
	if (!extension_loaded("mysql") && !extension_loaded("mysqli")) {
		$fails[] = "PHP does not have the MySQL extension installed.";
	}
	
	if (!extension_loaded('gd')) {
		$fails[] = "PHP does not have the GD extension installed.";
	}
	
	if (!extension_loaded('curl')) {
		$fails[] = "PHP does not have the cURL extension installed.";
	}
	
	if (!extension_loaded('ctype')) {
		$fails[] = "PHP does not have the ctype extension installed.";
	}
	
	if (!ini_get('file_uploads')) {
		$fails[] = "PHP does not have file uploads enabled.";
	}
	
	if (!is_writable(".")) {
		$fails[] = "Please make the current directory writable.";
	}
	
	// Issues that could cause problems next.
	$warnings = [];
	if (get_magic_quotes_gpc()) {
		$fails[] = "magic_quotes_gpc is on. This is a deprecated setting that will break BigTree. Please disable it in php.ini.";
	}
	
	if (intval(ini_get('upload_max_filesize')) < 4) {
		$warnings[] = "Max upload filesize (upload_max_filesize in php.ini) is currently less than 4MB. 8MB or higher is recommended.";
	}
	
	if (intval(ini_get('post_max_size')) < 4) {
		$warnings[] = "Max POST size (post_max_size in php.ini) is currently less than 4MB. 8MB or higher is recommended.";
	}
	
	if (intval(ini_get("memory_limit")) < 32) {
		$warnings[] = "PHP's memory limit is currently under 32MB. BigTree recommends at least 32MB of memory be available to PHP.";
	}
	
	// Determine if we're on Apache or IIS
	if (strpos($_SERVER["SERVER_SOFTWARE"], "IIS") !== false) {
		$iis = $iis_rewrite = true;
		$warnings[] = "You are running Microsoft IIS. BigTree is only tested on Apache; proceed with caution in production environments.";
		
		// See if we have the equivalent of rewrite installed.
		if (!isset($_SERVER["IIS_UrlRewriteModule"])) {
			$warnings[] = "You do not seem to have the IIS rewrite module installed; only basic routing is available.";
			$iis_rewrite = false;
		}
	} else {
		$iis = false;
	}
	
	// mod_rewrite check
	$rewrite_enabled = true;
	
	if (function_exists("apache_get_modules")) {
		$apache_modules = apache_get_modules();
		
		if (in_array('mod_rewrite', $apache_modules) === false) {
			$warnings[] = "Apache's mod_rewrite is not installed. Only basic routing is available without mod_rewrite.";
			$rewrite_enabled = false;
		}
	}
	
	$success = false;
	$installed = false;
	$error = false;
	
	if (count($_POST) && !($_POST["db"] && $_POST["host"] && $_POST["user"] && $_POST["cms_user"] && $_POST["cms_pass"])) {
		$error = "Errors found! Please fix the highlighted fields and submit the form again.";
	} elseif (count($_POST)) {
		if ($_POST["write_host"] && $_POST["write_user"] && $_POST["write_password"]) {
			$sql_connection = @mysqli_connect($_POST["write_host"], $_POST["write_user"], $_POST["write_password"],
											  $_POST["write_port"] ?: 3306, $_POST["write_socket"]);
		} else {
			$sql_connection = @mysqli_connect($_POST["host"], $_POST["user"], $_POST["password"],
											  $_POST["port"] ?: 3306, $_POST["socket"]);
		}
		
		if (!$sql_connection) {
			$error = "Could not connect to MySQL server.";
		} else {
			// Try to create the database
			$sql_connection->query("CREATE DATABASE IF NOT EXISTS `".$_POST["db"]."`");
			// Try to select it
			$select = $sql_connection->select_db($_POST["db"]);
			
			if (!$select) {
				$error = "Error accessing/creating database &ldquo;".htmlspecialchars($_POST["db"])."&rdquo;.";
			}
		}
	}
	
	if (!$error && count($_POST)) {
		
		// Let domain/www_root/static_root be set by post for command line installs
		if (!isset($domain)) {
			$domain = "http://".$_SERVER["HTTP_HOST"];
			
			if ($_POST["routing"] == "basic") {
				$static_root = $domain.str_replace("install.php", "", $_SERVER["REQUEST_URI"])."site/";
				$www_root = $static_root."index.php/";
			} elseif ($_POST["routing"] == "iis") {
				$www_root = $static_root = $domain.str_replace("install.php", "", $_SERVER["REQUEST_URI"])."site/";
			} else {
				$www_root = $static_root = $domain.str_replace("install.php", "", $_SERVER["REQUEST_URI"]);
			}
		}
		
		$find = [
			"[host]",
			"[db]",
			"[user]",
			"[password]",
			"[port]",
			"[socket]",
			"[write_host]",
			"[write_db]",
			"[write_user]",
			"[write_password]",
			"[write_port]",
			"[write_socket]",
			"[domain]",
			"[wwwroot]",
			"[staticroot]",
			"[email]",
			"[settings_key]",
			"[force_secure_login]",
			"[routing]",
			"[slash_behavior]"
		];
		
		$replace = [
			$_POST["host"],
			$_POST["db"],
			$_POST["user"],
			$_POST["password"],
			$_POST["port"],
			$_POST["socket"],
			(isset($_POST["loadbalanced"])) ? $_POST["write_host"] : "",
			(isset($_POST["loadbalanced"])) ? $_POST["write_db"] : "",
			(isset($_POST["loadbalanced"])) ? $_POST["write_user"] : "",
			(isset($_POST["loadbalanced"])) ? $_POST["write_password"] : "",
			(isset($_POST["loadbalanced"])) ? $_POST["write_port"] : "",
			(isset($_POST["loadbalanced"])) ? $_POST["write_socket"] : "",
			$domain,
			$www_root,
			$static_root,
			$_POST["cms_user"],
			uniqid("", true),
			(!empty($_POST["force_secure_login"])) ? "true" : "false",
			($_POST["routing"] == "basic") ? "basic" : "htaccess",
			$_POST["slash_behavior"]
		];
		
		// Make sure we're not running in a special mode that forces values for textareas that aren't allowing null.
		$sql_connection->query("SET SESSION sql_mode = ''");
		
		// Grab the base SQL - we're replacing some text if we support utf8mb4
		$charset_test_query = $sql_connection->query("SHOW CHARACTER SET WHERE Charset LIKE 'utf8mb4'");
		$base_sql = file_get_contents("bigtree.sql");
		
		if ($charset_test_query->num_rows) {
			$base_sql = str_replace("CHARSET=utf8 COLLATE=utf8_general_ci", "CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $base_sql);
		}
		
		$sql_queries = explode("\n", $base_sql);
		
		foreach ($sql_queries as $query) {
			$query = trim($query);
			
			if ($query != "") {
				$q = $sql_connection->query($query);
			}
		}
		
		include "core/inc/lib/PasswordHash.php";
		$phpass = new PasswordHash(8, true);
		$cms_user = $sql_connection->real_escape_string($_POST["cms_user"]);
		$enc_pass = $sql_connection->real_escape_string($phpass->HashPassword($_POST["cms_pass"]));
		$sql_connection->query("INSERT INTO bigtree_users (`email`, `password`, `name`, `level`)
								VALUES ('$cms_user', '$enc_pass', 'Developer', '2')");
		
		// Determine whether Apache is running as the owner of the BigTree files -- only works if we have posix_getuid
		// We do this to determine whether we need to make the files the script writes 777
		if (function_exists("posix_getuid")) {
			if (posix_getuid() == getmyuid()) {
				define("BT_SU_EXEC", true);
			} else {
				define("BT_SU_EXEC", false);
			}
		} else {
			define("BT_SU_EXEC", false);
		}
		
		function bt_mkdir_writable($dir) {
			global $root;
			
			mkdir($root.$dir);
			
			if (!BT_SU_EXEC) {
				chmod($root.$dir, 0777);
			}
		}
		
		function bt_touch_writable($file, $contents = "") {
			file_put_contents($file, $contents);
			
			if (!BT_SU_EXEC) {
				chmod($file, 0777);
			}
		}
		
		function bt_copy_dir($from, $to) {
			global $root;
			$d = opendir($root.$from);
			
			if (!file_exists($root.$to)) {
				@mkdir($root.$to);
				
				if (!BT_SU_EXEC) {
					@chmod($root.$to, 0777);
				}
			}
			
			while ($f = readdir($d)) {
				if ($f != "." && $f != "..") {
					if (is_dir($root.$from.$f)) {
						bt_copy_dir($from.$f."/", $to.$f."/");
					} else {
						@copy($from.$f, $to.$f);
						
						if (!BT_SU_EXEC) {
							@chmod($to.$f, 0777);
						}
					}
				}
			}
		}
		
		$root = "";
		
		bt_mkdir_writable("cache/");
		bt_mkdir_writable("custom/");
		bt_mkdir_writable("custom/admin/");
		bt_mkdir_writable("custom/admin/ajax/");
		bt_mkdir_writable("custom/admin/css/");
		bt_mkdir_writable("custom/admin/images/");
		bt_mkdir_writable("custom/admin/modules/");
		bt_mkdir_writable("custom/admin/pages/");
		bt_mkdir_writable("custom/admin/form-field-types/");
		bt_mkdir_writable("custom/admin/form-field-types/draw/");
		bt_mkdir_writable("custom/admin/form-field-types/process/");
		bt_mkdir_writable("custom/inc/");
		bt_mkdir_writable("custom/inc/modules/");
		bt_mkdir_writable("custom/inc/required/");
		bt_mkdir_writable("extensions/");
		bt_mkdir_writable("site");
		bt_mkdir_writable("site/css/");
		bt_mkdir_writable("site/extensions/");
		bt_mkdir_writable("site/files/");
		bt_mkdir_writable("site/files/pages/");
		bt_mkdir_writable("site/files/resources/");
		bt_mkdir_writable("site/images/");
		bt_mkdir_writable("site/js/");
		bt_mkdir_writable("templates");
		bt_mkdir_writable("templates/ajax/");
		bt_mkdir_writable("templates/layouts/");
		bt_touch_writable("templates/layouts/_header.php");
		bt_touch_writable("templates/layouts/default.php",'<?php include "_header.php" ?>
<?=$bigtree["content"]?>
<?php include "_footer.php" ?>');
		bt_touch_writable("templates/layouts/_footer.php");
		bt_mkdir_writable("templates/routed/");
		bt_mkdir_writable("templates/basic/");
		bt_touch_writable("templates/basic/_404.php","<h1>404 - Page Not Found</h1>");
		bt_touch_writable("templates/basic/_maintenance.php","<h1>Under Construction</h1><p>Maintenance mode has been enabled.</p>");
		bt_touch_writable("templates/basic/_sitemap.php","<h1>Sitemap</h1>");
		bt_touch_writable("templates/basic/home.php");
		bt_touch_writable("templates/basic/content.php",'<h1><?=$page_header?></h1>
<?=$page_content?>');
		bt_mkdir_writable("templates/callouts/");
		
		bt_touch_writable("custom/environment.php",str_replace($find,$replace,file_get_contents("core/config.environment.php")));
		
		// Install the example site if they asked for it.
		if (!empty($_POST["install_example_site"])) {
			bt_copy_dir("core/example-site/", "");
			$sql_queries = explode("\n", file_get_contents("example-site.sql"));
			
			foreach ($sql_queries as $query) {
				$query = trim($query);
				
				if ($query != "") {
					$q = $sql_connection->query($query);
				}
			}
			
			bt_touch_writable("custom/settings.php", str_replace($find, $replace, file_get_contents("core/example-site/custom/settings.php")));
		} else {
			bt_touch_writable("custom/settings.php", str_replace($find, $replace, file_get_contents("core/config.settings.php")));
		}
		
		// Create site/index.php, site/.htaccess, and .htaccess (masks the 'site' directory)
		bt_touch_writable("site/index.php",'<?php
	$server_root = str_replace("site/index.php","",strtr(__FILE__, "\\\\", "/"));
	include "../core/launch.php";');
		
		if ($_POST["routing"] == "advanced") {
			bt_touch_writable("site/.htaccess",'<IfModule mod_deflate.c>
	<IfModule mod_setenvif.c>
		<IfModule mod_headers.c>
			SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding
			RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding
		</IfModule>
	</IfModule>
	<IfModule mod_mime.c>
		AddEncoding gzip svgz
	</IfModule>
	<IfModule mod_filter.c>
		AddOutputFilterByType DEFLATE "application/atom+xml" \
			"application/javascript" \
			"application/json" \
			"application/ld+json" \
			"application/manifest+json" \
			"application/rss+xml" \
			"application/vnd.geo+json" \
			"application/vnd.ms-fontobject" \
			"application/x-font-ttf" \
			"application/x-web-app-manifest+json" \
			"application/xhtml+xml" \
			"application/xml" \
			"font/opentype" \
			"image/svg+xml" \
			"image/x-icon" \
			"text/cache-manifest" \
			"text/css" \
			"text/html" \
			"text/plain" \
			"text/vtt" \
			"text/x-component" \
			"text/xml" \
			"text/javascript"
	</IfModule>
</IfModule>

<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresByType image/gif "access plus 1 month"
	ExpiresByType image/png "access plus 1 month"
	ExpiresByType image/jpeg "access plus 1 month"
	ExpiresByType text/css "access plus 1 month"
	ExpiresByType text/javascript "access plus 1 month"
	ExpiresByType application/x-javascript "access plus 1 month"
	ExpiresByType application/x-shockwave-flash "access plus 1 month"
	
	ExpiresByType application/vnd.ms-fontobject "access plus 1 month"
	ExpiresByType font/ttf "access plus 1 month"
	ExpiresByType font/otf "access plus 1 month"
	ExpiresByType font/x-woff "access plus 1 month"
	ExpiresByType image/svg+xml "access plus 1 month"
</IfModule>

<IfModule mod_headers.c>
	<FilesMatch "\.(ttf|otf|eot|woff)$">
		Header set Access-Control-Allow-Origin "*"
	</FilesMatch>

	Header set X-UA-Compatible "IE=edge"
	<FilesMatch "\.(appcache|atom|crx|css|cur|eot|f4[abpv]|flv|geojson|gif|htc|ico|jpe?g|js|json(ld)?|m4[av]|manifest|map|mp4|oex|og[agv]|opus|otf|pdf|png|rdf|rss|safariextz|svgz?|swf|topojson|tt[cf]|txt|vcf|vtt|webapp|web[mp]|woff2?|xml|xpi)$">
		Header unset X-UA-Compatible
	</FilesMatch>
	
	Header set X-Content-Type-Options "nosniff"
	Header set X-XSS-Protection "1; mode=block"
	Header set X-Permitted-Cross-Domain-Policies "master-only"
</IfModule>

AddType image/svg+xml svg
AddType video/ogg .ogv
AddType video/mp4 .mp4
AddType video/webm .webm

IndexIgnore */*

Options -MultiViews

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php?bigtree_htaccess_url=$1 [QSA,L]

RewriteRule .* - [E=HTTP_IF_MODIFIED_SINCE:%{HTTP:If-Modified-Since}]
RewriteRule .* - [E=HTTP_BIGTREE_PARTIAL:%{HTTP:BigTree-Partial}]');
		
		} elseif ($_POST["routing"] == "simple") {
			bt_touch_writable("site/.htaccess", 'IndexIgnore */*

Options -MultiViews

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php?bigtree_htaccess_url=$1 [QSA,L]

RewriteRule .* - [E=HTTP_IF_MODIFIED_SINCE:%{HTTP:If-Modified-Since}]
RewriteRule .* - [E=HTTP_BIGTREE_PARTIAL:%{HTTP:BigTree-Partial}]');
		} else {
			bt_touch_writable("index.php", '<?php header("Location: site/index.php/"); ?>');
		}
		
		if ($_POST["routing"] != "basic" && $_POST["routing"] != "iis") {
			bt_touch_writable(".htaccess", 'RewriteEngine On
RewriteRule ^$ site/ [L]
RewriteRule (.*) site/$1 [L]');
		}

		$installed = true;
	}
	
	if ($installed) {
		@unlink("install.php");
		@unlink("bigtree.sql");
		@unlink("example-site.sql");
		@unlink("README.md");
	}
?>
<!doctype html> 
<!--[if lt IE 7 ]> <html lang="en" class="no-js ie6"> <![endif]-->
<!--[if IE 7 ]>	<html lang="en" class="no-js ie7"> <![endif]-->
<!--[if IE 8 ]>	<html lang="en" class="no-js ie8"> <![endif]-->
<!--[if IE 9 ]>	<html lang="en" class="no-js ie9"> <![endif]-->
<!--[if (gt IE 9)|!(IE)]><!--> <html lang="en" class="no-js"> <!--<![endif]-->
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
		<title>Install BigTree <?=BIGTREE_VERSION?></title>
		<?php if ($installed && $_POST["routing"] != "iis") { ?>
		<link rel="stylesheet" href="<?php echo $www_root ?>admin/css/main.css" type="text/css" media="all" />
		<?php } else { ?>
		<link rel="stylesheet" href="core/admin/css/main.css" type="text/css" media="all" />
		<script src="core/admin/js/lib.js"></script>
		<script src="core/admin/js/main.js"></script>
		<?php } ?>
	</head>
	<body class="install">
		<div class="install_wrapper">
			<?php if ($installed) { ?>
			<h1>BigTree <?=BIGTREE_VERSION?> Installed</h1>
			<form method="post" action="" class="module">
				<h2 class="getting_started"><span></span>Installation Complete</h2>
				<fieldset class="clear">
					<p>Your new BigTree site is ready to go! Login to the CMS using the email/password you entered on the previous page.</p>
					<?php if ($_POST["routing"] == "basic" && file_exists("install.php")) { ?>
					<p class="delete_message">Remember to delete install.php from your root folder as it is publicly accessible in Basic Routing mode.</p>
					<?php } elseif ($_POST["routing"] == "iis") { ?>
					<p class="error_message iis_message">To setup proper rewrite routing for IIS you must import the following .htaccess rules to the /site/ directory:</p>
					<code>
						RewriteCond %{REQUEST_FILENAME} !-d<br />
						RewriteCond %{REQUEST_FILENAME} !-f<br />
						RewriteRule ^(.*)$ index.php?bigtree_htaccess_url=$1 [QSA,L]
					</code>
					<p class="delete_message">To remove the /site/ path from your BigTree install you will need to setup a separate IIS Site for your BigTree install and set its document root to the /site/ folder (as well as moving the rewrite rules to apply the the main Site instead of the /site/ directory). After doing so, edit your /templates/config.php file to adjust your domain, www_root, static_root, and admin_root variables.</p>
					<?php } ?>
				</fieldset>
				
				<hr />
				
				<h2>Public Site</h2>
				<fieldset class="clear">
					<p><small>URL</small><a href="<?php echo $www_root; ?>"><?php echo $www_root; ?></a></p>
				</fieldset>
				<br /><br />
				<h2>Administration Area</h2>
				<fieldset class="clear">
					<p>
						<small>URL</small><a href="<?php echo $www_root."admin/"; ?>"><?php echo $www_root."admin/"; ?></a><br />
						<small>EMAIL</small><?php echo htmlspecialchars($_POST["cms_user"]); ?><br />
						<small>PASSWORD</small><?php for ($i = 0, $count = strlen($_POST["cms_pass"]); $i < $count; $i++) { echo "*"; } ?><br />
					</p>
				</fieldset>
				
				<br class="clear" /><br />
			</form>
			<?php } else { ?>
			<h1>Install BigTree <?=BIGTREE_VERSION?></h1>
			<form method="post" action="" class="module">
				<h2 class="getting_started"><span></span>Getting Started</h2>
				<fieldset class="clear">
					<p>Welcome to the BigTree installer. If you need help with installation, <a href="http://www.bigtreecms.org/docs/dev-guide/installation/" target="_blank">check out the developer docs</a>.</p>
					<br />
				</fieldset>
				<?php
					if (count($warnings)) {
						echo '<br />';
						foreach ($warnings as $warning) {
				?>
				<p class="warning_message clear"><?php echo $warning?></p>
				<?php
						}
					}
					if (count($fails)) {
						echo '<br />';
						foreach ($fails as $fail) {
				?>
				<p class="error_message clear"><?php echo $fail?></p>
				<?php
						}
						echo '<br /><fieldset class="clear"><p><strong>Please resolve all the errors marked in red above to install BigTree.</strong></p></fieldset><br /><br />';
					} else {
						if ($error) {
							echo '<br />';
				?>
				<p class="error_message clear"><?php echo $error?></p>
				<?php
						}
				?>
				<hr />
				
				<h2 class="database"><span></span>Database Properties</h2>
				<fieldset class="clear">
					<p>Enter your MySQL database information below.</p>
				</fieldset>
				<hr />
				<fieldset class="left<?php if (count($_POST) && !$_POST["host"]) { ?> form_error<?php } ?>">
					<label for="db_host">Hostname</label>
					<input class="text" type="text" id="db_host" name="host" value="<?php echo htmlspecialchars($_POST["host"] ?: "localhost") ?>" tabindex="1" />
				</fieldset>
				<fieldset class="right<?php if (count($_POST) && !$_POST["db"]) { ?> form_error<?php } ?>">
					<label for="db_name">Database</label>
					<input class="text" type="text" id="db_name" name="db" value="<?php echo htmlspecialchars($_POST["db"]) ?>" tabindex="2" />
				</fieldset>
				<br class="clear" /><br />
				<fieldset class="left<?php if (count($_POST) && !$_POST["user"]) { ?> form_error<?php } ?>">
					<label for="db_user">Username</label>
					<input class="text" type="text" id="db_user" name="user" value="<?php echo htmlspecialchars($_POST["user"]) ?>" tabindex="3" autocomplete="off" />
				</fieldset>
				<fieldset class="right">
					<label for="db_pass">Password</label>
					<input class="text" type="password" id="db_pass" name="password" value="<?php echo htmlspecialchars($_POST["password"]) ?>" tabindex="4" autocomplete="off" />
				</fieldset>
				<div class="db_port_or_socket_settings"<?php if (!$_POST["db_port_or_socket"]) { ?> style="display: none;"<?php } ?>>
					<br class="clear" /><br />
					<fieldset class="left">
						<label for="db_port">Port <small>(defaults to 3306)</small></label>
						<input class="text" type="text" id="db_port" name="port" value="<?php echo htmlspecialchars($_POST["port"]) ?>" tabindex="7" />
					</fieldset>
					<fieldset class="right">
						<label for="db_socket">Socket</label>
						<input class="text" type="text" id="db_socket" name="socket" value="<?php echo htmlspecialchars($_POST["socket"]) ?>" tabindex="8" />
					</fieldset>
				</div>
				<fieldset>
					<br /><br />
					<input type="checkbox" class="checkbox" name="db_port_or_socket" id="db_port_or_socket"<?php if ($_POST["db_port_or_socket"]) { ?> checked="checked"<?php } ?> tabindex="5" />
					<label class="for_checkbox" for="db_port_or_socket">Connect via Socket or Alternate Port</label>
					<input type="checkbox" class="checkbox" name="loadbalanced" id="loadbalanced"<?php if ($_POST["loadbalanced"]) { ?> checked="checked"<?php } ?> tabindex="6" />
					<label class="for_checkbox" for="loadbalanced">Load Balanced MySQL</label>
				</fieldset>
				
				<div id="loadbalanced_settings"<?php if (!$_POST["loadbalanced"]) { ?> style="display: none;"<?php } ?>>
					<hr />
					
					<h2 class="database"><span></span>Write Database Properties</h2>
					<fieldset class="clear">
						<p>If you are hosting a load balanced setup with multiple MySQL servers, enter the master write server information below.</p>
					</fieldset>
					<hr />
					<fieldset class="left<?php if (count($_POST) && !$_POST["write_host"]) { ?> form_error<?php } ?>">
						<label for="db_write_host">Hostname</label>
						<input class="text" type="text" id="db_write_host" name="write_host" value="<?php echo htmlspecialchars($_POST["write_host"]) ?>" tabindex="6" />
					</fieldset>
					<fieldset class="right<?php if (count($_POST) && !$_POST["write_db"]) { ?> form_error<?php } ?>">
						<label for="db_write_name">Database</label>
						<input class="text" type="text" id="db_write_name" name="write_db" value="<?php echo htmlspecialchars($_POST["write_db"]) ?>" tabindex="7" />
					</fieldset>
					<br class="clear" /><br />
					<fieldset class="left<?php if (count($_POST) && !$_POST["write_user"]) { ?> form_error<?php } ?>">
						<label for="db_write_user">Username</label>
						<input class="text" type="text" id="db_write_user" name="write_user" value="<?php echo htmlspecialchars($_POST["write_user"]) ?>" tabindex="8" autocomplete="off" />
					</fieldset>
					<fieldset class="right<?php if (count($_POST) && !$_POST["write_password"]) { ?> form_error<?php } ?>">
						<label for="db_write_pass">Password</label>
						<input class="text" type="password" id="db_write_pass" name="write_password" value="<?php echo htmlspecialchars($_POST["write_password"]) ?>" tabindex="9" autocomplete="off" />
					</fieldset>
					<div class="db_port_or_socket_settings"<?php if (!$_POST["db_port_or_socket"]) { ?> style="display: none;"<?php } ?>>
						<br class="clear" /><br />
						<fieldset class="left">
							<label for="db_write_port">Port <small>(defaults to 3306)</small></label>
							<input class="text" type="text" id="db_write_port" name="write_port" value="<?php echo htmlspecialchars($_POST["write_port"]) ?>" tabindex="7" />
						</fieldset>
						<fieldset class="right">
							<label for="db_write_socket">Socket</label>
							<input class="text" type="text" id="db_write_socket" name="write_socket" value="<?php echo htmlspecialchars($_POST["write_socket"]) ?>" tabindex="8" />
						</fieldset>
					</div>
					<br class="clear" /><br />
				</div>
				
				<hr />
				
				<h2 class="account"><span></span>Administrator Account</h2>
				<fieldset class="clear">
					<p>Please enter the desired email address and password for your site's developer account.</p>
				</fieldset>
				<hr />
				<fieldset class="left<?php if (count($_POST) && !$_POST["cms_user"]) { ?> form_error<?php } ?>">
					<label for="cms_user">Email Address</label>
					<input class="text" type="text" id="cms_user" name="cms_user" value="<?php echo htmlspecialchars($_POST["cms_user"]) ?>" tabindex="10" autocomplete="off" />
				</fieldset>
				<fieldset class="right<?php if (count($_POST) && !$_POST["cms_pass"]) { ?> form_error<?php } ?>">
					<label for="cms_pass">Password</label>
					<input class="text" type="password" id="cms_pass" name="cms_pass" value="<?php echo htmlspecialchars($_POST["cms_pass"]) ?>" tabindex="11" autocomplete="off" />
				</fieldset>
				<fieldset class="clear">
					<br /><br />
					<input type="checkbox" class="checkbox" name="force_secure_login" id="force_secure_login"<?php if ($_POST["force_secure_login"]) { ?> checked="checked"<?php } ?> tabindex="12" />
					<label class="for_checkbox" for="force_secure_login">Force HTTPS Logins</label>
				</fieldset>
				
				<hr />
				
				<?php if (!$iis || $_POST["iis_rewrite"]) { ?>
				<h2 class="routing"><span></span>Site Routing</h2>
				<fieldset class="clear">
					<?php if ($iis) { ?>
					<p>BigTree makes your URLs pretty but URL Rewrite support can make them even more pretty. By choosing "Rewrite Routing" you can remove /index.php/ from your URLs.</p>
					<?php } else { ?>
					<p>BigTree makes your URLs pretty but mod_rewrite support can make them even more pretty. If your server supports .htaccess overrides and mod_rewrite support you can remove /index.php/ from your URLs.</p>
					<?php } ?>
					<ul>
						<?php if ($iis) { ?>
						<li>Choose <strong>"Basic Routing"</strong> if you are not familiar with importing .htaccess rules into IIS.</li>
						<li>Choose <strong>"Rewrite Routing"</strong> if you want cleaner looking URLs and can import .htaccess rules.</li>
						<?php } else { ?>
						<li>Choose <strong>"Basic Routing"</strong> if you are unsure your server supports .htaccess overrides and mod_rewrite.</li>
						<li>Choose <strong>"Simple Rewrite Routing"</strong> if your server supports .htaccess and mod_rewrite but does not allow for content compression.</li>
						<li>Choose <strong>"Advanced Routing"</strong> to install an .htaccess that enables caching, compression, and routing.</li>
						<?php } ?>
					</ul>
				</fieldset>
				<hr />
				<div class="contain">
					<fieldset class="left">
						<label for="routing_field">Routing</label>
						<select name="routing" id="routing_field" tabindex="13">
							<?php
								if ($iis) {
							?>
							<option value="basic"<?php if (!$_POST["routing"] || $_POST["routing"] == "basic") { ?> selected="selected"<?php } ?>>Basic Routing</option>
							<option value="iis"<?php if ($_POST["routing"] == "iis") { ?> selected="selected"<?php } ?>>Rewrite Routing</option>
							<?php
								} else {
							?>
							<option value="basic"<?php if (!$_POST["routing"] || $_POST["routing"] == "basic") { ?> selected="selected"<?php } ?>>Basic Routing</option>
							<?php
									if ($rewrite_enabled) {
							?>
							<option value="simple"<?php if ($_POST["routing"] == "simple") { ?> selected="selected"<?php } ?>>Simple Rewrite Routing</option>
							<option value="advanced"<?php if ($_POST["routing"] == "advanced") { ?> selected="selected"<?php } ?>>Advanced Routing</option>
							<?php
									}
								}
							?>
						</select>
					</fieldset>
					<fieldset class="left">
						<label for="url_behavior_field">URL Behavior</label>
						<select name="slash_behavior" id="url_behavior_field">
							<option value="append">URLs End With /</option>
							<option value="remove"<?php if ($_POST["slash_behavior"]== "remove") { ?> selected="selected"<?php } ?>>URLs End With Page Slug</option>
							<option value="none"<?php if ($_POST["slash_behavior"] == "none") { ?> selected="selected"<?php } ?>>Allow Either</option>
						</select>
					</fieldset>
				</div>
				
				<hr />
				<?php } else { ?>
				<input type="hidden" name="routing" value="basic" />
				<?php } ?>
				
				<h2 class="example"><span></span>Example Site</h2>
				<fieldset class="clear">
					<p>If you would also like to install the BigTree example site, check the box below. These optional demo files include example templates and modules to help get you started learning BigTree.</p>
				</fieldset>
				<br />
				<fieldset class="clear">
					<input type="checkbox" class="checkbox" name="install_example_site" id="install_example_site"<?php if ($_POST["install_example_site"]) { ?> checked="checked"<?php } ?> tabindex="14" />
					<label class="for_checkbox" for="install_example_site">Install Example Site</label>
				</fieldset>
								
				<fieldset class="lower">
					<input type="submit" class="button blue" value="Install Now" tabindex="15" />
				</fieldset>
				<?php
					}
				?>
			</form>
		    <script>
		        $(document).ready(function() {
		        	$("#loadbalanced").on("change", function() {
		        		if ($(this).prop("checked")) {
		        			$("#loadbalanced_settings").css({ display: "block" });
		        		} else {
		        			$("#loadbalanced_settings").css({ display: "none" });
		        		}
		        	});
		        	$("#db_port_or_socket").on("change", function() {
		        		if ($(this).prop("checked")) {
		        			$(".db_port_or_socket_settings").css({ display: "block" });
		        		} else {
		        			$(".db_port_or_socket_settings").css({ display: "none" });
		        		}
		        	});
		        });
		    </script>
			<?php } ?>
			<a href="http://www.bigtreecms.com" class="install_logo" target="_blank">BigTree</a>
			<a href="http://www.fastspot.com" class="install_copyright" target="_blank">&copy; <?php echo date("Y") ?> Fastspot</a>
		</div>
	</body>
</html>